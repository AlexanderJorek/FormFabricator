<?php

/**
 * Request input is read in one shape only, as WordPress.org's reviewers ask for it:
 * `sanitize_*(wp_unslash($_POST['k']))`, nothing in between. Beside that a superglobal appears only in isset()/empty()
 * and a type guard; $_FILES parts go directly into their sanitizer. See docs/CONTRIBUTING.md ("Linters").
 */

namespace FormFabricatorReview\Sniffs\Security;

require_once __DIR__ . '/../../Expr.php';

use FormFabricatorReview\Expr;
use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;
use PHP_CodeSniffer\Util\Tokens;

final class RequestInputSniff implements Sniff
{
    private const SUPERGLOBALS = ['$_POST', '$_GET', '$_REQUEST', '$_COOKIE', '$_SERVER', '$_FILES', '$_ENV'];

    private const GUARDS = ['is_string', 'is_array', 'is_scalar', 'is_numeric', 'array_key_exists', 'key_exists'];

    private const SANITIZERS = [
        'sanitize_text_field', 'sanitize_textarea_field', 'sanitize_key', 'sanitize_email', 'sanitize_file_name',
        'sanitize_title', 'sanitize_hex_color', 'sanitize_user', 'sanitize_url', 'sanitize_mime_type', 'esc_url_raw',
        'absint', 'intval', 'floatval', 'rest_sanitize_boolean', 'wp_kses_post',
    ];

    /** Sanitizers that take the value as their second argument after a callback (map_deep() takes it first). */
    private const MAPPERS = ['map_deep'];

    /**
     * @return list<int|string>
     */
    public function register(): array
    {
        return [T_VARIABLE];
    }

    public function process(File $phpcsFile, $stackPtr): void
    {
        $tokens = $phpcsFile->getTokens();
        $name   = $tokens[$stackPtr]['content'];
        if (!in_array($name, self::SUPERGLOBALS, true)) {
            return;
        }

        // The entry: the superglobal and its [..] keys.
        $end = $stackPtr + 1;
        while (isset($tokens[$end]) && $tokens[$end]['code'] === T_OPEN_SQUARE_BRACKET && isset($tokens[$end]['bracket_closer'])) {
            $end = $tokens[$end]['bracket_closer'] + 1;
        }

        $open = $this->innermostParenthesis($phpcsFile, $stackPtr);
        if ($open !== null) {
            $owner = $this->parenthesisOwner($phpcsFile, $open);
            if ($owner === 'isset' || $owner === 'empty') {
                return;
            }
            [$s, $e] = Expr::trim($phpcsFile, $open + 1, $tokens[$open]['parenthesis_closer']);
            $args = Expr::split($phpcsFile, $open + 1, $tokens[$open]['parenthesis_closer'], [T_COMMA]);
            if (in_array($owner, self::GUARDS, true)) {
                // A type guard reads no value; `?? null` keeps a missing key from warning.
                return;
            }
            $arg = $owner === 'array_map' ? ($args[1] ?? [0, 0]) : $args[0];
            if ($name === '$_FILES' && $arg[0] === $stackPtr && $arg[1] === $end && $this->sanitizes($phpcsFile, $owner, $open, $stackPtr)) {
                return;
            }
            // wp_unslash($_POST['k']), or the keys, array_keys($_FILES), each directly inside its sanitizer.
            $wrapped = in_array($owner, ['wp_unslash', 'array_keys'], true) && $s === $stackPtr && $e === $end && count($args) === 1;
            if ($wrapped && $this->sanitizedCall($phpcsFile, $open)) {
                return;
            }
        }

        $phpcsFile->addError(
            'Read %s only as sanitize_*(wp_unslash(%s[...])), the sanitizer directly around wp_unslash() and no ?? or helper '
                . 'between them (guard with isset() first); $_FILES parts directly in their sanitizer',
            $stackPtr,
            'NotSanitizedInPlace',
            [$name, $name]
        );
    }

    /**
     * Whether the call whose parenthesis opens at $open is, whole, the value argument of a sanitizing call.
     */
    private function sanitizedCall(File $file, int $open): bool
    {
        $tokens = $file->getTokens();
        $outer  = $this->innermostParenthesis($file, $open - 1);
        if ($outer === null) {
            return false;
        }
        $first = $this->firstToken($file, $open);
        $owner = $this->parenthesisOwner($file, $outer);
        $args  = Expr::split($file, $outer + 1, $tokens[$outer]['parenthesis_closer'], [T_COMMA]);
        $arg   = $args[$owner === 'array_map' ? 1 : 0] ?? null;
        return $arg !== null
            && $arg[0] === $first
            && $arg[1] === $tokens[$open]['parenthesis_closer'] + 1
            && $this->sanitizes($file, $owner, $outer, $first);
    }

    /**
     * Whether the call that owns the parenthesis at $open sanitizes its argument starting at $first: a sanitizer, or
     * map_deep()/array_map() with a sanitizer named as a literal callback.
     */
    private function sanitizes(File $file, ?string $owner, int $open, int $first): bool
    {
        if ($owner === null) {
            return false;
        }
        $tokens = $file->getTokens();
        $args   = Expr::split($file, $open + 1, $tokens[$open]['parenthesis_closer'], [T_COMMA]);
        if (in_array($owner, self::SANITIZERS, true)) {
            return $args[0][0] === $first;
        }
        if (in_array($owner, self::MAPPERS, true) && count($args) === 2 && $args[0][0] === $first) {
            return $this->isSanitizerName($file, $args[1]);
        }
        if ($owner === 'array_map' && count($args) === 2 && $args[1][0] === $first) {
            return $this->isSanitizerName($file, $args[0]);
        }
        return false;
    }

    /**
     * @param array{0: int, 1: int} $range
     */
    private function isSanitizerName(File $file, array $range): bool
    {
        $tokens = $file->getTokens();
        return $range[1] - $range[0] === 1
            && $tokens[$range[0]]['code'] === T_CONSTANT_ENCAPSED_STRING
            && in_array(Expr::literalText($tokens[$range[0]]['content']), self::SANITIZERS, true);
    }

    /**
     * The opening parenthesis of the innermost parentheses around $ptr, or null.
     */
    private function innermostParenthesis(File $file, int $ptr): ?int
    {
        $nested = $file->getTokens()[$ptr]['nested_parenthesis'] ?? [];
        if ($nested === []) {
            return null;
        }
        $keys = array_keys($nested);
        return (int) end($keys);
    }

    /**
     * The lowercase name of the function or construct that owns the parenthesis at $open, or null.
     */
    private function parenthesisOwner(File $file, int $open): ?string
    {
        $tokens = $file->getTokens();
        if (isset($tokens[$open]['parenthesis_owner'])) {
            return strtolower($tokens[$tokens[$open]['parenthesis_owner']]['content']);
        }
        // isset() and empty() don't own their parentheses in PHPCS's token stream.
        $prev = $file->findPrevious(Tokens::$emptyTokens, $open - 1, null, true);
        if ($prev === false || !in_array($tokens[$prev]['code'], [T_STRING, T_ISSET, T_EMPTY], true)) {
            return null;
        }
        return strtolower($tokens[$prev]['content']);
    }

    /**
     * The first token of the call whose opening parenthesis is at $open: its name, or the namespace separator before it.
     */
    private function firstToken(File $file, int $open): int
    {
        $tokens = $file->getTokens();
        $name   = (int) $file->findPrevious(Tokens::$emptyTokens, $open - 1, null, true);
        return ($name > 0 && $tokens[$name - 1]['code'] === T_NS_SEPARATOR) ? $name - 1 : $name;
    }
}
