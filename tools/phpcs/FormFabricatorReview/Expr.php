<?php

/**
 * Token helpers the FormFabricatorReview sniffs share: trimming, top-level splitting and call recognition over a token
 * range. A range is [start, end): start inclusive, end exclusive. Dev tooling, never shipped.
 */

namespace FormFabricatorReview;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Util\Tokens;

final class Expr
{
    /**
     * The range without leading and trailing whitespace and comments, and without parentheses that wrap all of it.
     *
     * @return array{0: int, 1: int}
     */
    public static function trim(File $file, int $start, int $end): array
    {
        $tokens = $file->getTokens();
        while (true) {
            while ($start < $end && isset(Tokens::$emptyTokens[$tokens[$start]['code']])) {
                $start++;
            }
            while ($end > $start && isset(Tokens::$emptyTokens[$tokens[$end - 1]['code']])) {
                $end--;
            }
            $wrapped = $start < $end
                && $tokens[$start]['code'] === T_OPEN_PARENTHESIS
                && !isset($tokens[$start]['parenthesis_owner'])
                && ($tokens[$start]['parenthesis_closer'] ?? -1) === $end - 1;
            if ($wrapped) {
                $start++;
                $end--;
                continue;
            }
            return [$start, $end];
        }
    }

    /**
     * The token after the group that opens at $i (parentheses, brackets, braces), or $i + 1 when nothing opens there.
     */
    public static function after(File $file, int $i): int
    {
        $token = $file->getTokens()[$i];
        if ($token['code'] === T_OPEN_PARENTHESIS && isset($token['parenthesis_closer'])) {
            return $token['parenthesis_closer'] + 1;
        }
        if (in_array($token['code'], [T_OPEN_SQUARE_BRACKET, T_OPEN_SHORT_ARRAY, T_OPEN_CURLY_BRACKET], true) && isset($token['bracket_closer'])) {
            return $token['bracket_closer'] + 1;
        }
        return $i + 1;
    }

    /**
     * The first token of one of $codes outside any group in the range, or null.
     *
     * @param array<int|string> $codes
     */
    public static function findTop(File $file, int $start, int $end, array $codes): ?int
    {
        $tokens = $file->getTokens();
        for ($i = $start; $i < $end; $i = self::after($file, $i)) {
            if (in_array($tokens[$i]['code'], $codes, true)) {
                return $i;
            }
        }
        return null;
    }

    /**
     * The range split at every top-level token of $codes, each part trimmed.
     *
     * @param array<int|string> $codes
     * @return list<array{0: int, 1: int}>
     */
    public static function split(File $file, int $start, int $end, array $codes): array
    {
        $tokens = $file->getTokens();
        $parts  = [];
        $from   = $start;
        for ($i = $start; $i < $end; $i = self::after($file, $i)) {
            if (in_array($tokens[$i]['code'], $codes, true)) {
                $parts[] = self::trim($file, $from, $i);
                $from    = $i + 1;
            }
        }
        $parts[] = self::trim($file, $from, $end);
        return $parts;
    }

    /**
     * The T_INLINE_ELSE that closes the ternary whose T_INLINE_THEN is at $then, or null.
     */
    public static function inlineElse(File $file, int $then, int $end): ?int
    {
        $tokens = $file->getTokens();
        $depth  = 0;
        for ($i = $then + 1; $i < $end; $i = self::after($file, $i)) {
            if ($tokens[$i]['code'] === T_INLINE_THEN) {
                $depth++;
            } elseif ($tokens[$i]['code'] === T_INLINE_ELSE) {
                if ($depth === 0) {
                    return $i;
                }
                $depth--;
            }
        }
        return null;
    }

    /**
     * When the range is exactly one call of a plain function (`name(...)` or `\name(...)`), its lowercase name and the
     * pointer of its opening parenthesis; otherwise null.
     *
     * @return array{0: string, 1: int}|null
     */
    public static function call(File $file, int $start, int $end): ?array
    {
        $tokens = $file->getTokens();
        $i      = $start;
        if ($i < $end && $tokens[$i]['code'] === T_NS_SEPARATOR) {
            $i++;
        }
        if ($i >= $end || $tokens[$i]['code'] !== T_STRING) {
            return null;
        }
        $open = $file->findNext(Tokens::$emptyTokens, $i + 1, $end, true);
        if ($open === false || $tokens[$open]['code'] !== T_OPEN_PARENTHESIS || ($tokens[$open]['parenthesis_closer'] ?? -1) !== $end - 1) {
            return null;
        }
        return [strtolower($tokens[$i]['content']), $open];
    }

    /**
     * The arguments of the call whose opening parenthesis is at $open, each trimmed; empty for `name()`.
     *
     * @return list<array{0: int, 1: int}>
     */
    public static function args(File $file, int $open): array
    {
        $close = $file->getTokens()[$open]['parenthesis_closer'];
        [$s, $e] = self::trim($file, $open + 1, $close);
        if ($s >= $e) {
            return [];
        }
        return self::split($file, $open + 1, $close, [T_COMMA]);
    }

    /**
     * Whether $ptr is the name of a plain function call: followed by `(`, not a method, not a declaration.
     */
    public static function isFunctionCall(File $file, int $ptr): bool
    {
        $tokens = $file->getTokens();
        $next   = $file->findNext(Tokens::$emptyTokens, $ptr + 1, null, true);
        if ($next === false || $tokens[$next]['code'] !== T_OPEN_PARENTHESIS) {
            return false;
        }
        $prev = $file->findPrevious(Tokens::$emptyTokens, $ptr - 1, null, true);
        if ($prev !== false && $tokens[$prev]['code'] === T_NS_SEPARATOR) {
            $prev = $file->findPrevious(Tokens::$emptyTokens, $prev - 1, null, true);
        }
        $notCalls = [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_STRING, T_CONST];
        return $prev === false || !in_array($tokens[$prev]['code'], $notCalls, true);
    }

    /**
     * The text of the range when it is one string literal without variables, or null. PHPCS splits a literal that
     * spans lines into a token per line.
     */
    public static function literal(File $file, int $start, int $end): ?string
    {
        $tokens = $file->getTokens();
        $source = '';
        for ($i = $start; $i < $end; $i++) {
            if ($tokens[$i]['code'] !== T_CONSTANT_ENCAPSED_STRING) {
                return null;
            }
            $source .= $tokens[$i]['content'];
        }
        return $source === '' ? null : self::literalText($source);
    }

    /**
     * The text of a string literal's source as PHP reads it.
     */
    public static function literalText(string $content): string
    {
        $body = substr($content, 1, -1);
        if ($content[0] === "'") {
            return str_replace(["\\'", '\\\\'], ["'", '\\'], $body);
        }
        return stripcslashes($body);
    }

    /**
     * The code of a range on one line, shortened, for a message.
     */
    public static function snippet(File $file, int $start, int $end): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', $file->getTokensAsString($start, max(0, $end - $start))));
        return strlen($text) > 80 ? substr($text, 0, 77) . '...' : $text;
    }
}
