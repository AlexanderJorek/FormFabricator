<?php

/**
 * Late escaping, as WordPress.org's reviewers ask for it: every printed value is a literal or a direct escaping call
 * written at the print, fitting the HTML context the literal before it shows. Stricter than
 * WordPress.Security.EscapeOutput, which trusts helpers, casts and interpolation. The full rule, including inline
 * CSS/JS and markup built in strings, is in docs/CONTRIBUTING.md ("Linters", "Request input and output").
 */

namespace FormFabricatorReview\Sniffs\Security;

require_once __DIR__ . '/../../Expr.php';

use FormFabricatorReview\Expr;
use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;
use PHP_CodeSniffer\Util\Tokens;

final class LateEscapeSniff implements Sniff
{
    private const TEXT = ['esc_html', 'esc_html__', 'esc_html_x', 'wp_kses', 'wp_kses_post', 'wp_kses_data', 'esc_xml'];
    private const ATTR = ['esc_attr', 'esc_attr__', 'esc_attr_x', 'esc_url'];
    private const URL  = ['esc_url'];
    private const JS   = ['esc_js', 'wp_json_encode'];

    /** What may stand in CSS, in a style attribute or a <style> element of built markup. */
    private const CSS = ['absint', 'intval', 'floatval', 'sanitize_hex_color', 'esc_attr'];

    /** Core functions that return a fixed attribute (checked='checked') or nothing, for inside a tag. */
    private const FLAGS = ['checked', 'selected', 'disabled', 'wp_readonly'];

    /** Every function whose result may be printed, in some context. */
    private const ESCAPES = [
        'esc_html', 'esc_html__', 'esc_html_x', 'esc_attr', 'esc_attr__', 'esc_attr_x', 'esc_url', 'esc_js',
        'esc_textarea', 'esc_xml', 'wp_kses', 'wp_kses_post', 'wp_kses_data', 'wp_json_encode',
    ];

    /** What the inline CSS and JS a page adds may be made of, besides literals. */
    private const INLINE = [
        'wp_add_inline_style'  => ['wp_strip_all_tags', 'sanitize_hex_color', 'safecss_filter_attr', 'esc_url', 'esc_attr', 'absint', 'intval', 'floatval'],
        'wp_add_inline_script' => ['wp_json_encode', 'esc_js', 'absint', 'intval'],
    ];

    /** Attributes that hold a URL. */
    private const URL_ATTRS = ['href', 'src', 'action', 'formaction', 'poster', 'cite', 'srcset', 'xlink:href', 'background'];

    /** Names that say a value is markup: [variable or property, function or method, class constant]. */
    private const MARKUP_NAME = ['/(?:^\$?|_)html$|Html$/', '/Html$|^render/', '/(?:^|_)HTML$/'];

    /** Names that say it is a tag's attributes, each escaped where it was built (' data-x="…"'). */
    private const ATTRS_NAME = ['/(?:^\$?|_)attrs?$|Attrs?$/', '/Attrs?$|Attributes$/', '/(?:^|_)ATTRS?$/'];

    /** A literal that opens or closes a tag, or a comment. */
    private const TAG_START = '/<(?:[a-zA-Z]|\/[a-zA-Z]|!--)/';

    /**
     * Whether markup that assignments, returns and array values build is checked too. On in .phpcs-review.xml; off,
     * the sniff checks prints only.
     *
     * @var bool|string
     */
    public $builders = true;

    /** Whether the expression being checked is built markup, not a print. */
    private bool $building = false;

    /**
     * @return list<int|string>
     */
    public function register(): array
    {
        return [T_ECHO, T_PRINT, T_OPEN_TAG_WITH_ECHO, T_EXIT, T_STRING, T_EQUAL, T_CONCAT_EQUAL, T_RETURN, T_DOUBLE_ARROW];
    }

    public function process(File $phpcsFile, $stackPtr): void
    {
        $tokens = $phpcsFile->getTokens();
        $code   = $tokens[$stackPtr]['code'];

        if ($code === T_STRING) {
            $this->processCall($phpcsFile, $stackPtr);
            return;
        }
        if (in_array($code, [T_EQUAL, T_CONCAT_EQUAL, T_RETURN, T_DOUBLE_ARROW], true)) {
            if ($this->builders === true || $this->builders === 'true') {
                $this->processBuilder($phpcsFile, $stackPtr);
            }
            return;
        }
        if ($code === T_EXIT) {
            $open = $phpcsFile->findNext(Tokens::$emptyTokens, $stackPtr + 1, null, true);
            if ($open === false || $tokens[$open]['code'] !== T_OPEN_PARENTHESIS) {
                return;
            }
            [$s, $e] = Expr::trim($phpcsFile, $open + 1, $tokens[$open]['parenthesis_closer']);
            if ($s < $e && !($e - $s === 1 && $tokens[$s]['code'] === T_LNUMBER)) {
                $this->check($phpcsFile, $s, $e, null);
            }
            return;
        }

        $end = $this->statementEnd($phpcsFile, $stackPtr + 1);
        [$s, $e] = Expr::trim($phpcsFile, $stackPtr + 1, $end);
        $this->check($phpcsFile, $s, $e, $this->inlineContext($phpcsFile, $stackPtr));
    }

    /**
     * An assignment to a variable or property, a return or an array value that builds markup: a literal at its top
     * level (not inside a call's arguments) opens a tag, or, for `.=`, sets an attribute.
     */
    private function processBuilder(File $file, int $ptr): void
    {
        $tokens = $file->getTokens();
        $code   = $tokens[$ptr]['code'];
        if ($code === T_EQUAL || $code === T_CONCAT_EQUAL) {
            // A parameter's default value, a constant or a property declaration is no markup being built.
            $owner = array_key_last($tokens[$ptr]['nested_parenthesis'] ?? []);
            if ($owner !== null && isset($tokens[$owner]['parenthesis_owner']) && in_array($tokens[$tokens[$owner]['parenthesis_owner']]['code'], [T_FUNCTION, T_CLOSURE, T_FN], true)) {
                return;
            }
            $prev = $file->findPrevious(Tokens::$emptyTokens, $ptr - 1, null, true);
            if ($prev === false || !in_array($tokens[$prev]['code'], [T_VARIABLE, T_CLOSE_SQUARE_BRACKET, T_STRING], true)) {
                return;
            }
            if ($tokens[$prev]['code'] === T_STRING) {
                $before = $file->findPrevious(Tokens::$emptyTokens, $prev - 1, null, true);
                if ($before === false || !in_array($tokens[$before]['code'], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
                    return;
                }
            }
        }

        $end = $this->valueEnd($file, $ptr + 1);
        [$s, $e] = Expr::trim($file, $ptr + 1, $end);
        if ($s >= $e) {
            return;
        }

        $sprintf = Expr::call($file, $s, $e);
        if ($sprintf !== null && in_array($sprintf[0], ['sprintf', 'vsprintf'], true)) {
            $args = Expr::args($file, $sprintf[1]);
            $format = isset($args[0]) ? implode('', $this->topLiterals($file, $args[0][0], $args[0][1])) : '';
            if (preg_match(self::TAG_START, $format) === 1) {
                $file->addError(
                    'Markup is built with %s(); concatenate it, each value through its escaping function, so the escaping stands at each value',
                    $s,
                    'MarkupSprintf',
                    [$sprintf[0]]
                );
            }
            return;
        }

        $literals = $this->topLiterals($file, $s, $e);
        $joined   = implode('', $literals);
        $context  = '';
        if (preg_match(self::TAG_START, $joined) !== 1) {
            if (preg_grep('/^\s+[\w:-]+\s*=\s*["\']/', $literals) !== []) {
                // An attribute fragment (`$x_attr = ' data-x="' . esc_attr($v) . '"'`), or the rest of a tag an earlier
                // statement opened, is built inside a tag.
                $context = '<x';
            } elseif ($code !== T_CONCAT_EQUAL || !$this->appendsToMarkup($file, $ptr)) {
                return;
            }
        }
        $this->building = true;
        $this->check($file, $s, $e, $context);
        $this->building = false;
    }

    /**
     * Whether the `.=` at $ptr appends to markup: a variable or property named as markup, or the verifier's report.
     * What it appends is text in it, tag or no tag.
     */
    private function appendsToMarkup(File $file, int $ptr): bool
    {
        $tokens = $file->getTokens();
        $target = $file->findPrevious(Tokens::$emptyTokens, $ptr - 1, null, true);
        if ($target === false || !in_array($tokens[$target]['code'], [T_VARIABLE, T_STRING], true)) {
            return false;
        }
        return preg_match(self::MARKUP_NAME[0], $tokens[$target]['content']) === 1 || $tokens[$target]['content'] === '$report';
    }

    /**
     * The string literals of a value outside any call's arguments (inside ternaries and parentheses too), in order.
     *
     * @return list<string>
     */
    private function topLiterals(File $file, int $start, int $end): array
    {
        $tokens   = $file->getTokens();
        $literals = [];
        for ($i = $start; $i < $end;) {
            $token = $tokens[$i];
            if ($token['code'] === T_OPEN_PARENTHESIS && !isset($token['parenthesis_owner'])) {
                $prev = $file->findPrevious(Tokens::$emptyTokens, $i - 1, null, true);
                if ($prev === false || !in_array($tokens[$prev]['code'], [T_STRING, T_VARIABLE, T_CLOSE_PARENTHESIS, T_CLOSE_SQUARE_BRACKET], true)) {
                    $i++;
                    continue;
                }
            }
            if ($token['code'] === T_CONSTANT_ENCAPSED_STRING) {
                $literals[] = Expr::literalText($token['content']);
            } elseif ($token['code'] === T_DOUBLE_QUOTED_STRING) {
                $literals[] = $token['content'];
            }
            $i = Expr::after($file, $i);
        }
        return $literals;
    }

    /**
     * printf()/vprintf(), _e()/_ex(), esc_html_e()/esc_attr_e() (their context), wp_die(), wp_add_inline_*().
     */
    private function processCall(File $file, int $ptr): void
    {
        $tokens = $file->getTokens();
        $name   = strtolower($tokens[$ptr]['content']);
        $known  = ['printf', 'vprintf', '_e', '_ex', 'esc_html_e', 'esc_attr_e', 'wp_die', 'wp_add_inline_style', 'wp_add_inline_script'];
        if (!in_array($name, $known, true) || !Expr::isFunctionCall($file, $ptr)) {
            return;
        }
        $open = $file->findNext(Tokens::$emptyTokens, $ptr + 1, null, true);
        $args = Expr::args($file, (int) $open);

        switch ($name) {
            case '_e':
            case '_ex':
                $file->addError(
                    '%s() prints a translation unescaped; use esc_html_e()/esc_attr_e() or echo esc_html_x()/esc_attr_x()',
                    $ptr,
                    'TranslationNotEscaped',
                    [$name]
                );
                return;
            case 'esc_html_e':
            case 'esc_attr_e':
                $this->checkContext($file, $ptr, $name === 'esc_html_e' ? 'esc_html' : 'esc_attr', $this->inlineContext($file, $ptr));
                return;
            case 'printf':
            case 'vprintf':
                foreach ($args as [$s, $e]) {
                    $this->check($file, $s, $e, null);
                }
                return;
            case 'wp_die':
                foreach (array_slice($args, 0, 2) as [$s, $e]) {
                    $this->check($file, $s, $e, null);
                }
                return;
            default:
                if (isset($args[1])) {
                    $this->checkInline($file, $args[1][0], $args[1][1], self::INLINE[$name], $name);
                }
        }
    }

    /**
     * Checks one printed expression, given what is known of the HTML printed just before it, and returns that context
     * extended by what the expression prints ('X' standing in for a value).
     */
    private function check(File $file, int $start, int $end, ?string $context): ?string
    {
        $tokens = $file->getTokens();
        [$start, $end] = Expr::trim($file, $start, $end);
        if ($start >= $end) {
            return $context;
        }

        $then = Expr::findTop($file, $start, $end, [T_INLINE_THEN]);
        if ($then !== null) {
            $else = Expr::inlineElse($file, $then, $end);
            if ($else !== null) {
                [$ts, $te] = Expr::trim($file, $then + 1, $else);
                // The short ternary `a ?: b` prints its condition.
                $after = $ts < $te ? $this->check($file, $ts, $te, $context) : $this->check($file, $start, $then, $context);
                $this->check($file, $else + 1, $end, $context);
                return $after;
            }
        }
        $coalesce = Expr::findTop($file, $start, $end, [T_COALESCE]);
        if ($coalesce !== null) {
            $after = $this->check($file, $start, $coalesce, $context);
            $this->check($file, $coalesce + 1, $end, $context);
            return $after;
        }

        $parts = Expr::split($file, $start, $end, [T_STRING_CONCAT, T_COMMA]);
        if (count($parts) > 1) {
            foreach ($parts as [$s, $e]) {
                $context = $this->check($file, $s, $e, $context);
            }
            return $context;
        }

        $first = $tokens[$start];
        $text  = Expr::literal($file, $start, $end);
        if ($text !== null) {
            return $context === null ? null : $context . $text;
        }
        if ($end - $start === 1) {
            if ($first['code'] === T_LNUMBER || $first['code'] === T_DNUMBER) {
                return $context === null ? null : $context . 'X';
            }
            if ($first['code'] === T_STRING && $first['content'] === 'PHP_EOL') {
                return $context === null ? null : $context . "\n";
            }
        }
        if (in_array($first['code'], [T_DOUBLE_QUOTED_STRING, T_START_HEREDOC, T_START_NOWDOC], true)) {
            $this->report(
                $file,
                'A string with variables in it is printed unescaped; concatenate each value through its escaping function',
                $start,
                'InterpolatedString'
            );
            return $context === null ? null : $context . 'X';
        }

        $kind = $context === null ? null : $this->classify($context)[0];
        $call = Expr::call($file, $start, $end);
        if ($call !== null && in_array($call[0], self::ESCAPES, true)) {
            $this->checkContext($file, $start, $call[0], $context);
            return $context === null ? null : $context . 'X';
        }
        if ($this->building && $this->builtPart($file, $start, $end, $call, $kind, $context)) {
            return $context === null ? null : $context . 'X';
        }

        $this->report(
            $file,
            ($this->building ? 'Built into markup' : 'Printed') . ' without escaping where it stands: %s. Wrap it in the escaping '
                . 'function for its context (esc_html(), esc_attr(), esc_url(), esc_textarea(), wp_kses() with an allowlist) there',
            $start,
            'OutputNotEscaped',
            [Expr::snippet($file, $start, $end)]
        );
        return $context === null ? null : $context . 'X';
    }

    /**
     * In built markup: a piece that is markup by name where text goes, a core flag function inside a tag, a CSS-safe
     * function in CSS.
     *
     * @param array{0: string, 1: int}|null $call
     */
    private function builtPart(File $file, int $start, int $end, ?array $call, ?string $kind, ?string $context): bool
    {
        $tokens = $file->getTokens();
        if ($tokens[$start]['code'] === T_STRING_CAST) {
            $start = (int) $file->findNext(Tokens::$emptyTokens, $start + 1, $end, true);
            $call  = Expr::call($file, $start, $end);
        }
        if ($kind === 'text' && $this->isMarkupByName($file, $start, $end, self::MARKUP_NAME)) {
            return true;
        }
        // What a template printed into an output buffer, each value escaped as it was printed.
        if ($kind === 'text' && $call !== null && in_array($call[0], ['ob_get_clean', 'ob_get_contents'], true)) {
            return true;
        }
        if ($kind === 'tag' && $this->isMarkupByName($file, $start, $end, self::ATTRS_NAME)) {
            return true;
        }
        if ($call === null) {
            return false;
        }
        if ($kind === 'tag') {
            return in_array($call[0], self::FLAGS, true);
        }
        $inCss = $kind === 'style' || ($kind === 'attr' && $context !== null && $this->classify($context)[1] === 'style');
        return $inCss && in_array($call[0], self::CSS, true);
    }

    /**
     * Whether the range is a variable or property named as markup ($html, $…_html, $…Html, self::$html), optionally
     * indexed, a class constant named …_HTML, a call of a function or method named …Html() or render…(), or
     * implode()/join() of such a variable; for $names = ATTRS_NAME, the same for attributes.
     *
     * @param array{0: string, 1: string, 2: string} $names Patterns for variables, functions and constants.
     */
    private function isMarkupByName(File $file, int $start, int $end, array $names): bool
    {
        $tokens = $file->getTokens();
        $last   = $end - 1;
        while ($last > $start && $tokens[$last]['code'] === T_CLOSE_SQUARE_BRACKET && isset($tokens[$last]['bracket_opener'])) {
            $last = $tokens[$last]['bracket_opener'] - 1;
        }
        $after = $last > $start ? $tokens[$last - 1]['code'] : null;
        if ($tokens[$last]['code'] === T_VARIABLE || ($tokens[$last]['code'] === T_STRING && in_array($after, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true))) {
            return $this->onlyAccessTokens($file, $start, $last) && preg_match($names[0], $tokens[$last]['content']) === 1;
        }
        if ($tokens[$last]['code'] === T_STRING && $after === T_DOUBLE_COLON && $last === $end - 1) {
            return $this->onlyAccessTokens($file, $start, $last) && preg_match($names[2], $tokens[$last]['content']) === 1;
        }
        if ($tokens[$end - 1]['code'] !== T_CLOSE_PARENTHESIS || !isset($tokens[$end - 1]['parenthesis_opener'])) {
            return false;
        }
        $open = $tokens[$end - 1]['parenthesis_opener'];
        $name = $file->findPrevious(Tokens::$emptyTokens, $open - 1, null, true);
        if ($name === false || $name < $start) {
            return false;
        }
        // A closure called through an array key named as markup ($layout['field_html'](...)).
        if ($tokens[$name]['code'] === T_CLOSE_SQUARE_BRACKET && isset($tokens[$name]['bracket_opener'])) {
            $opener = $tokens[$name]['bracket_opener'];
            $key    = Expr::literal($file, $opener + 1, $name);
            return $key !== null && $this->onlyAccessTokens($file, $start, $opener) && preg_match($names[0], $key) === 1;
        }
        if (!$this->onlyAccessTokens($file, $start, $name)) {
            return false;
        }
        // A closure called through a variable named as markup ($row_html(...)).
        if ($tokens[$name]['code'] === T_VARIABLE) {
            return preg_match($names[0], $tokens[$name]['content']) === 1;
        }
        if ($tokens[$name]['code'] !== T_STRING) {
            return false;
        }
        $function = $tokens[$name]['content'];
        if (preg_match($names[1], $function) === 1) {
            return true;
        }
        if (in_array(strtolower($function), ['implode', 'join'], true)) {
            $args  = Expr::args($file, $open);
            $array = end($args);
            return $array !== false && $this->isMarkupByName($file, $array[0], $array[1], $names);
        }
        return false;
    }

    /**
     * Whether [start, end) holds only what leads to a variable, property or method: $this->, self::, Class::, \Ns\.
     */
    private function onlyAccessTokens(File $file, int $start, int $end): bool
    {
        $tokens  = $file->getTokens();
        $allowed = [T_VARIABLE, T_STRING, T_SELF, T_STATIC, T_PARENT, T_NS_SEPARATOR, T_DOUBLE_COLON, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR];
        for ($i = $start; $i < $end; $i++) {
            if (!in_array($tokens[$i]['code'], $allowed, true) && !isset(Tokens::$emptyTokens[$tokens[$i]['code']])) {
                return false;
            }
        }
        return true;
    }

    /**
     * Checks that the escaping function fits the HTML context the literal before it opened.
     */
    private function checkContext(File $file, int $ptr, string $function, ?string $context): void
    {
        if ($context === null) {
            return;
        }
        [$kind, $attr] = $this->classify($context);
        $allowed = match ($kind) {
            'text'     => self::TEXT,
            'textarea' => ['esc_textarea'],
            'script'   => self::JS,
            'style'    => $this->building ? self::CSS : [],
            'attr'     => $this->attrFunctions($attr, $context),
            default    => [],
        };
        if (in_array($function, $allowed, true)) {
            return;
        }
        if ($kind === 'tag') {
            $this->report(
                $file,
                '%s() stands inside a tag but outside an attribute value; write the attribute name in the literal and escape only its value',
                $ptr,
                'AttributeFragment',
                [$function]
            );
            return;
        }
        if ($kind === 'unquoted') {
            $this->report($file, '%s() stands in an unquoted attribute value; quote it in the literal', $ptr, 'UnquotedAttribute', [$function]);
            return;
        }
        if ($kind === 'style' && !$this->building) {
            $this->report($file, '%s() prints inside a <style> element; inline CSS goes through wp_add_inline_style()', $ptr, 'WrongContext', [$function]);
            return;
        }
        $where = $kind === 'attr' ? 'the ' . $attr . ' attribute' : ($kind === 'text' ? 'HTML text' : 'a <' . $kind . '> element');
        $this->report(
            $file,
            '%s() does not fit %s; use %s',
            $ptr,
            'WrongContext',
            [$function, $where, implode('() or ', $allowed) . '()']
        );
    }

    /**
     * An error; in built markup with the code prefixed 'Built', so a review exception can tell them apart.
     *
     * @param list<string> $data
     */
    private function report(File $file, string $message, int $ptr, string $code, array $data = []): void
    {
        $file->addError($message, $ptr, ($this->building ? 'Built' : '') . $code, $data);
    }

    /**
     * @return list<string>
     */
    private function attrFunctions(string $attr, string $context): array
    {
        if (str_starts_with($attr, 'on')) {
            return ['esc_js'];
        }
        if (in_array($attr, self::URL_ATTRS, true)) {
            // A value that starts the URL is escaped as one; a later part of a URL already begun ("?page=x&id=") is an
            // attribute value like any other.
            return preg_match('/=\s*["\']\s*$/', $context) === 1 ? self::URL : self::ATTR;
        }
        if ($attr === 'style' && $this->building) {
            return array_merge(self::ATTR, self::CSS);
        }
        return self::ATTR;
    }

    /**
     * Where the end of $html leaves a printed value: ['text'|'tag'|'unquoted'|'script'|'style'|'textarea', ''] or
     * ['attr', name].
     *
     * @return array{0: string, 1: string}
     */
    private function classify(string $html): array
    {
        $lower = strtolower($html);
        foreach (['script', 'style', 'textarea'] as $element) {
            $opened = strrpos($lower, '<' . $element);
            $closed = strrpos($lower, '</' . $element);
            if ($opened !== false && strpos($lower, '>', $opened) !== false && ($closed === false || $closed < $opened)) {
                return [$element, ''];
            }
        }
        $lt = strrpos($html, '<');
        $gt = strrpos($html, '>');
        if ($lt === false || ($gt !== false && $gt > $lt)) {
            return ['text', ''];
        }

        // Inside a tag: walk it to see whether a quoted value is open. $pending is the name of an attribute whose `=`
        // came and whose value hasn't started yet.
        $tag     = substr($html, $lt + 1);
        $quote   = null;
        $name    = '';
        $word    = '';
        $ended   = false;
        $pending = null;
        $len     = strlen($tag);
        for ($i = 0; $i < $len; $i++) {
            $ch = $tag[$i];
            if ($quote !== null) {
                if ($ch === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($ch === '"' || $ch === "'") {
                [$quote, $name, $pending, $word, $ended] = [$ch, $pending ?? '', null, '', false];
            } elseif ($ch === '=') {
                [$pending, $word, $ended] = [$word, '', false];
            } elseif (preg_match('/[\w:.-]/', $ch) === 1) {
                if ($pending !== null || $ended) {
                    [$pending, $word, $ended] = [null, '', false];
                }
                $word .= $ch;
            } elseif (ctype_space($ch)) {
                $ended = $word !== '';
            } else {
                [$pending, $word, $ended] = [null, '', false];
            }
        }
        if ($quote !== null) {
            return ['attr', strtolower($name)];
        }
        return [$pending !== null ? 'unquoted' : 'tag', ''];
    }

    /**
     * Inline CSS or JS: each part a literal or one of the functions that fit that language.
     *
     * @param list<string> $functions
     */
    private function checkInline(File $file, int $start, int $end, array $functions, string $sink): void
    {
        $tokens = $file->getTokens();
        foreach (Expr::split($file, $start, $end, [T_STRING_CONCAT]) as [$s, $e]) {
            if ($s >= $e) {
                continue;
            }
            // A ternary prints one of its branches; its condition is never added.
            $then = Expr::findTop($file, $s, $e, [T_INLINE_THEN]);
            $else = $then === null ? null : Expr::inlineElse($file, $then, $e);
            if ($then !== null && $else !== null) {
                $this->checkInline($file, $then + 1, $else, $functions, $sink);
                $this->checkInline($file, $else + 1, $e, $functions, $sink);
                continue;
            }
            if (Expr::literal($file, $s, $e) !== null || ($e - $s === 1 && in_array($tokens[$s]['code'], [T_LNUMBER, T_DNUMBER], true))) {
                continue;
            }
            $call = Expr::call($file, $s, $e);
            if ($call !== null && in_array($call[0], $functions, true)) {
                continue;
            }
            $file->addError(
                '%s() adds %s unescaped; pass each part through %s()',
                $s,
                'InlineAssetNotEscaped',
                [$sink, Expr::snippet($file, $s, $e), implode('(), ', $functions)]
            );
        }
    }

    /**
     * The token ending the statement that starts at $from: its `;` or `?>`.
     */
    private function statementEnd(File $file, int $from): int
    {
        $tokens = $file->getTokens();
        $count  = count($tokens);
        for ($i = $from; $i < $count; $i = Expr::after($file, $i)) {
            if ($tokens[$i]['code'] === T_SEMICOLON || $tokens[$i]['code'] === T_CLOSE_TAG) {
                return $i;
            }
        }
        return $count;
    }

    /**
     * The token ending the value that starts at $from: the statement's `;` or `?>`, the `,` after an array value or
     * argument, or the bracket that closes the list it stands in.
     */
    private function valueEnd(File $file, int $from): int
    {
        $tokens  = $file->getTokens();
        $count   = count($tokens);
        $closers = [T_SEMICOLON, T_CLOSE_TAG, T_COMMA, T_CLOSE_PARENTHESIS, T_CLOSE_SHORT_ARRAY, T_CLOSE_SQUARE_BRACKET, T_CLOSE_CURLY_BRACKET];
        for ($i = $from; $i < $count; $i = Expr::after($file, $i)) {
            if (in_array($tokens[$i]['code'], $closers, true)) {
                return $i;
            }
        }
        return $count;
    }

    /**
     * The HTML a template printed right before the statement at $ptr, when it follows `?>...<?php` directly (PHP
     * blocks in between stand as 'X'); null when the statement is inside PHP code and the context is unknown.
     */
    private function inlineContext(File $file, int $ptr): ?string
    {
        $tokens = $file->getTokens();
        $i      = $tokens[$ptr]['code'] === T_OPEN_TAG_WITH_ECHO ? $ptr : $file->findPrevious(Tokens::$emptyTokens, $ptr - 1, null, true);
        if ($i === false || !in_array($tokens[$i]['code'], [T_OPEN_TAG, T_OPEN_TAG_WITH_ECHO], true)) {
            return null;
        }
        $html = '';
        for ($i--, $blocks = 0; $i >= 0 && $blocks < 40; $i--) {
            $code = $tokens[$i]['code'];
            if ($code === T_INLINE_HTML) {
                $html = $tokens[$i]['content'] . $html;
                if (strpos($tokens[$i]['content'], '<') !== false) {
                    return $html;
                }
                continue;
            }
            if ($code === T_CLOSE_TAG) {
                $open = $file->findPrevious([T_OPEN_TAG, T_OPEN_TAG_WITH_ECHO], $i - 1);
                if ($open === false) {
                    break;
                }
                $html = 'X' . $html;
                $i    = $open;
                $blocks++;
                continue;
            }
            break;
        }
        return $html === '' ? null : $html;
    }
}
