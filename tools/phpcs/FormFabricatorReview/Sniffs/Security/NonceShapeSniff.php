<?php

/**
 * A nonce check no path can skip, as WordPress.org's reviewers ask for it: check_ajax_referer()/check_admin_referer()
 * on its own, or a top-level `if` holding only the check that ends the request. Every wp_ajax_/admin_post_ handler
 * whose callback is in the same file checks one. See docs/CONTRIBUTING.md ("Linters").
 */

namespace FormFabricatorReview\Sniffs\Security;

require_once __DIR__ . '/../../Expr.php';

use FormFabricatorReview\Expr;
use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;
use PHP_CodeSniffer\Util\Tokens;

final class NonceShapeSniff implements Sniff
{
    private const CHECKS = ['wp_verify_nonce', 'check_ajax_referer', 'check_admin_referer'];

    /** Scopes a nonce check may sit in: the handler itself, its class, a try block. */
    private const TOP_LEVEL = [T_CLASS, T_TRAIT, T_ENUM, T_ANON_CLASS, T_FUNCTION, T_CLOSURE, T_TRY, T_NAMESPACE];

    /** Loops, in which a per-item check may follow the handler's own. */
    private const LOOPS = [T_FOREACH, T_FOR, T_WHILE, T_DO];

    /** What may stand beside the nonce call in its `if` condition. */
    private const CONDITION_TOKENS = [T_BOOLEAN_NOT, T_BOOLEAN_OR, T_LOGICAL_OR, T_FALSE, T_IS_IDENTICAL, T_IS_EQUAL];

    /**
     * @return list<int|string>
     */
    public function register(): array
    {
        return [T_STRING];
    }

    public function process(File $phpcsFile, $stackPtr): void
    {
        $name = strtolower($phpcsFile->getTokens()[$stackPtr]['content']);
        if (!Expr::isFunctionCall($phpcsFile, $stackPtr)) {
            return;
        }
        if (in_array($name, self::CHECKS, true)) {
            $this->checkShape($phpcsFile, $stackPtr, $name);
        } elseif ($name === 'add_action') {
            $this->checkHandler($phpcsFile, $stackPtr);
        }
    }

    private function checkShape(File $file, int $ptr, string $name): void
    {
        $tokens = $file->getTokens();
        $nested = $tokens[$ptr]['nested_parenthesis'] ?? [];

        if ($nested === []) {
            $prev = $file->findPrevious(Tokens::$emptyTokens, $ptr - 1, null, true);
            if ($prev !== false && $tokens[$prev]['code'] === T_NS_SEPARATOR) {
                $prev = $file->findPrevious(Tokens::$emptyTokens, $prev - 1, null, true);
            }
            $prevCode = $prev === false ? null : $tokens[$prev]['code'];
            if ($prevCode === T_EQUAL || $prevCode === T_RETURN || $prevCode === T_FN_ARROW) {
                $file->addError(
                    '%s()\'s result is stored or returned for later; check the nonce where the handler starts and stop there when it fails',
                    $ptr,
                    'NonceResultStored',
                    [$name]
                );
                return;
            }
            if ($name === 'wp_verify_nonce') {
                $file->addError('wp_verify_nonce()\'s result is not acted on; use it in an if that ends the request', $ptr, 'NonceNotFailEarly');
                return;
            }
            $open = (int) $file->findNext(Tokens::$emptyTokens, $ptr + 1, null, true);
            $args = Expr::args($file, $open);
            if ($name === 'check_ajax_referer' && isset($args[2]) && strtolower($file->getTokensAsString($args[2][0], $args[2][1] - $args[2][0])) === 'false') {
                $file->addError('check_ajax_referer(..., false) on its own checks nothing; let it die, or use it in an if that ends the request', $ptr, 'NonceNotFailEarly');
                return;
            }
            $this->checkTopLevel($file, $ptr, $name);
            return;
        }

        // Inside parentheses: those of an if condition, wholly, and nothing else.
        $outer = (int) array_key_first($nested);
        $owner = $tokens[$outer]['parenthesis_owner'] ?? null;
        if ($owner === null || $tokens[$owner]['code'] !== T_IF) {
            $file->addError(
                '%s() is used inside an expression; check the nonce in its own if that ends the request, or call check_ajax_referer() on its own',
                $ptr,
                'NonceNotFailEarly',
                [$name]
            );
            return;
        }
        $closer = $tokens[$outer]['parenthesis_closer'];
        if (!$this->conditionIsNonceOnly($file, $outer + 1, $closer)) {
            $file->addError(
                'The nonce check shares its condition with other values; a condition like that can skip it. Use '
                    . 'if (!isset($_POST[\'nonce\']) || !wp_verify_nonce(...)) on its own',
                $ptr,
                'NonceConditional'
            );
            return;
        }
        $negative = $file->findNext([T_BOOLEAN_NOT, T_FALSE], $outer + 1, $closer) !== false;
        $perItem = $this->inLoopAfterCheck($file, $owner);
        if (!$negative || !$this->endsRequest($file, $owner, $perItem)) {
            $file->addError(
                'The nonce check must fail early: if (!wp_verify_nonce(...)) { wp_die()/wp_send_json_error()/return; }, with the handler\'s work after it',
                $ptr,
                'NonceNotFailEarly'
            );
            return;
        }
        if (!$perItem) {
            $this->checkTopLevel($file, $owner, $name);
        }
    }

    /**
     * Whether the condition holds nothing but nonce calls, isset()/empty() and the operators that negate or OR them.
     */
    private function conditionIsNonceOnly(File $file, int $start, int $end): bool
    {
        $tokens = $file->getTokens();
        for ($i = $start; $i < $end;) {
            $code = $tokens[$i]['code'];
            if (isset(Tokens::$emptyTokens[$code]) || in_array($code, self::CONDITION_TOKENS, true) || $code === T_NS_SEPARATOR) {
                $i++;
                continue;
            }
            if ($code === T_ISSET || $code === T_EMPTY) {
                $open = (int) $file->findNext(Tokens::$emptyTokens, $i + 1, null, true);
                $i    = $tokens[$open]['parenthesis_closer'] + 1;
                continue;
            }
            if ($code === T_OPEN_PARENTHESIS && !isset($tokens[$i]['parenthesis_owner'])) {
                if (!$this->conditionIsNonceOnly($file, $i + 1, $tokens[$i]['parenthesis_closer'])) {
                    return false;
                }
                $i = $tokens[$i]['parenthesis_closer'] + 1;
                continue;
            }
            if ($code === T_STRING && in_array(strtolower($tokens[$i]['content']), self::CHECKS, true)) {
                $open = (int) $file->findNext(Tokens::$emptyTokens, $i + 1, null, true);
                $i    = $tokens[$open]['parenthesis_closer'] + 1;
                continue;
            }
            return false;
        }
        return true;
    }

    /**
     * Whether the body of the if at $if ends the request: exit, return, throw, wp_die(), wp_send_json*(); or, for a
     * per-item check, skips the item.
     */
    private function endsRequest(File $file, int $if, bool $perItem): bool
    {
        $tokens = $file->getTokens();
        if (!isset($tokens[$if]['scope_opener'], $tokens[$if]['scope_closer'])) {
            return false;
        }
        for ($i = $tokens[$if]['scope_opener'] + 1; $i < $tokens[$if]['scope_closer']; $i++) {
            $code = $tokens[$i]['code'];
            if ($code === T_EXIT || $code === T_RETURN || $code === T_THROW || ($perItem && $code === T_CONTINUE)) {
                return true;
            }
            if ($code === T_STRING && in_array(strtolower($tokens[$i]['content']), ['wp_die', 'wp_send_json_error', 'wp_send_json'], true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether the token at $ptr is in a loop that a top-level nonce check of the same function comes before.
     */
    private function inLoopAfterCheck(File $file, int $ptr): bool
    {
        $tokens     = $file->getTokens();
        $conditions = $tokens[$ptr]['conditions'];
        if (array_intersect($conditions, self::LOOPS) === []) {
            return false;
        }
        $function = null;
        foreach ($conditions as $at => $code) {
            if ($code === T_FUNCTION || $code === T_CLOSURE) {
                $function = $at;
            }
        }
        if ($function === null) {
            return false;
        }
        for ($i = $tokens[$function]['scope_opener']; $i < $ptr; $i++) {
            $isCheck = $tokens[$i]['code'] === T_STRING && in_array(strtolower($tokens[$i]['content']), self::CHECKS, true);
            if ($isCheck && array_intersect($tokens[$i]['conditions'], self::LOOPS) === []) {
                return true;
            }
        }
        return false;
    }

    /**
     * The nonce check (the call, or the if around it) sits directly in its function, not in another branch or loop.
     */
    private function checkTopLevel(File $file, int $ptr, string $name): void
    {
        $tokens = $file->getTokens();
        foreach ($tokens[$ptr]['conditions'] as $code) {
            if (!in_array($code, self::TOP_LEVEL, true)) {
                $file->addError(
                    '%s() runs only on some paths through the handler; check the nonce unconditionally where the handler starts',
                    $ptr,
                    'NonceConditional',
                    [$name]
                );
                return;
            }
        }
        $prev = $file->findPrevious(Tokens::$emptyTokens, $ptr - 1, null, true);
        if ($prev !== false && $tokens[$prev]['code'] === T_ELSE) {
            $file->addError('%s() is checked in an elseif; give it an if of its own', $ptr, 'NonceConditional', [$name]);
        }
    }

    /**
     * add_action('wp_ajax_…' | 'admin_post_…', callback) with a callback in this file: the callback checks a nonce.
     */
    private function checkHandler(File $file, int $ptr): void
    {
        $tokens = $file->getTokens();
        $open   = (int) $file->findNext(Tokens::$emptyTokens, $ptr + 1, null, true);
        $args   = Expr::args($file, $open);
        if (count($args) < 2 || $args[0][1] - $args[0][0] !== 1 || $tokens[$args[0][0]]['code'] !== T_CONSTANT_ENCAPSED_STRING) {
            return;
        }
        $hook = Expr::literalText($tokens[$args[0][0]]['content']);
        if (!str_starts_with($hook, 'wp_ajax_') && !str_starts_with($hook, 'admin_post_')) {
            return;
        }

        $function = $this->callbackName($file, $args[1]);
        if ($function === null) {
            return;
        }
        $declared = $this->findFunction($file, $function);
        if ($declared === null || !isset($tokens[$declared]['scope_opener'], $tokens[$declared]['scope_closer'])) {
            return;
        }
        for ($i = $tokens[$declared]['scope_opener']; $i < $tokens[$declared]['scope_closer']; $i++) {
            if ($tokens[$i]['code'] === T_STRING && in_array(strtolower($tokens[$i]['content']), self::CHECKS, true)) {
                return;
            }
        }
        $file->addError(
            'The %s handler %s() checks no nonce; check it in the handler itself, before it reads input or acts',
            $ptr,
            'HandlerWithoutNonce',
            [$hook, $function]
        );
    }

    /**
     * The function name of a callback this file defines: [self::class|static::class|__CLASS__, 'method'], 'function' or
     * __NAMESPACE__ . '\\function'; null for any other.
     *
     * @param array{0: int, 1: int} $range
     */
    private function callbackName(File $file, array $range): ?string
    {
        $tokens = $file->getTokens();
        [$s, $e] = $range;
        if ($tokens[$s]['code'] === T_OPEN_SHORT_ARRAY && ($tokens[$s]['bracket_closer'] ?? -1) === $e - 1) {
            $items = Expr::split($file, $s + 1, $e - 1, [T_COMMA]);
            if (count($items) !== 2) {
                return null;
            }
            $class = strtolower($file->getTokensAsString($items[0][0], $items[0][1] - $items[0][0]));
            $class = (string) preg_replace('/\s+/', '', $class);
            $m     = $items[1];
            if (!in_array($class, ['self::class', 'static::class', '__class__'], true) || $m[1] - $m[0] !== 1 || $tokens[$m[0]]['code'] !== T_CONSTANT_ENCAPSED_STRING) {
                return null;
            }
            return Expr::literalText($tokens[$m[0]]['content']);
        }
        $last = $e - 1;
        if ($tokens[$last]['code'] !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }
        $text = Expr::literalText($tokens[$last]['content']);
        $pos  = strrpos($text, '\\');
        return $pos === false ? $text : substr($text, $pos + 1);
    }

    /**
     * The T_FUNCTION token declaring $name in this file, or null.
     */
    private function findFunction(File $file, string $name): ?int
    {
        $tokens = $file->getTokens();
        $i      = 0;
        while (($i = $file->findNext(T_FUNCTION, $i + 1)) !== false) {
            $declared = $file->getDeclarationName($i);
            if ($declared !== null && strcasecmp($declared, $name) === 0) {
                return $i;
            }
        }
        return null;
    }
}
