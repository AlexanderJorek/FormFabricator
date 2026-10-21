<?php

namespace FabricatorForms\Tests\Unit\Tools;

use FabricatorForms\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The review gate's own sniffs (tools/phpcs/FormFabricatorReview), run by phpcs itself on small files: each shape
 * WordPress.org's reviewers flag is reported, and the shape the plugin uses instead passes.
 */
final class ReviewSniffsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function cases(): iterable
    {
        // LateEscape: prints.
        yield 'a variable printed'            => ['echo $x;', ['LateEscape.OutputNotEscaped']];
        yield 'a number cast, not escaped'    => ['echo (int) $id;', ['LateEscape.OutputNotEscaped']];
        yield 'a helper\'s markup printed'    => ['echo self::cardHtml();', ['LateEscape.OutputNotEscaped']];
        yield 'escaped at the print'          => ['echo esc_html($x);', []];
        yield 'markup through wp_kses()'      => ['echo wp_kses($html, $allowed);', []];
        yield 'a literal ternary'             => ['echo $on ? \' hidden\' : \'\';', []];
        yield 'an interpolated string'        => ['echo "<p>$x</p>";', ['LateEscape.InterpolatedString']];
        yield 'esc_attr() in a URL attribute' => ['?><a href="<?php echo esc_attr($url); ?>">', ['LateEscape.WrongContext']];
        yield 'esc_url() in a URL attribute'  => ['?><a href="<?php echo esc_url($url); ?>">', []];
        yield 'a URL part after its start'    => ['?><a href="?page=x&id=<?php echo esc_attr($id); ?>">', []];
        yield 'esc_attr() in text'            => ['?><p><?php echo esc_attr($t); ?></p>', ['LateEscape.WrongContext']];
        yield 'esc_html() in an attribute'    => ['?><input value="<?php echo esc_html($v); ?>">', ['LateEscape.WrongContext']];
        yield 'an attribute fragment'         => ['?><div<?php echo esc_attr($attrs); ?>>', ['LateEscape.AttributeFragment']];
        yield 'an unquoted attribute'         => ['?><div id=<?php echo esc_attr($id); ?>>', ['LateEscape.UnquotedAttribute']];
        yield 'esc_textarea() in a textarea'  => ['?><textarea><?php echo esc_textarea($t); ?></textarea>', []];
        yield 'esc_html() in a textarea'      => ['?><textarea><?php echo esc_html($t); ?></textarea>', ['LateEscape.WrongContext']];
        yield 'esc_js() in an event handler'  => ['?><a onclick="<?php echo esc_js($js); ?>">', []];
        yield '_e()'                          => ['_e(\'Text\', \'formfabricator\');', ['LateEscape.TranslationNotEscaped']];
        yield 'printf() with a raw argument'  => ['printf(esc_html__(\'%s\', \'d\'), $x);', ['LateEscape.OutputNotEscaped']];
        yield 'wp_die() with a raw message'   => ['wp_die($message);', ['LateEscape.OutputNotEscaped']];
        yield 'inline CSS from a variable'    => ['wp_add_inline_style(\'h\', $css);', ['LateEscape.InlineAssetNotEscaped']];
        yield 'inline CSS stripped of tags'   => ['wp_add_inline_style(\'h\', wp_strip_all_tags($css));', []];
        yield 'inline JS as JSON'             => ['wp_add_inline_script(\'h\', \'var a = \' . wp_json_encode($a) . \';\');', []];

        // LateEscape: built markup.
        yield 'a raw value built into markup' => ['$html = \'<p>\' . $t . \'</p>\';', ['LateEscape.BuiltOutputNotEscaped']];
        yield 'a value escaped where built'   => ['$html = \'<p>\' . esc_html($t) . \'</p>\';', []];
        yield 'markup joined by its name'     => ['$html = \'<div>\' . $inner_html . self::rowHtml() . $this->render() . \'</div>\';', []];
        yield 'an early-escaped value'        => ['$t = esc_html($t); $html = \'<p>\' . $t . \'</p>\';', ['LateEscape.BuiltOutputNotEscaped']];
        yield 'an attribute fragment built'   => ['$x_attr = \' data-x="\' . $v . \'"\';', ['LateEscape.BuiltOutputNotEscaped']];
        yield 'an attribute fragment escaped' => ['$x_attr = \' data-x="\' . esc_attr($v) . \'"\';', []];
        yield 'flags and attributes in a tag' => ['$h = \'<input\' . $x_attrs . ($r ? \' required\' : \'\') . checked($a, $b, false) . \'>\';', []];
        yield 'a raw fragment in a tag'       => ['$h = \'<input\' . $req . \'>\';', ['LateEscape.BuiltOutputNotEscaped']];
        yield 'a number in CSS'               => ['$h = \'<div style="width:\' . absint($w) . \'px">\';', []];
        yield 'an append to markup, raw'      => ['$html .= \'• \' . $t;', ['LateEscape.BuiltOutputNotEscaped']];
        yield 'an append to markup, escaped'  => ['$html .= \'• \' . esc_html($t);', []];
        yield 'a buffered template joined'    => ['$html .= (string) ob_get_clean();', []];
        yield 'markup closures and constants' => ['$html .= $row_html(1) . $layout[\'field_html\']() . self::ICON_HTML;', []];
        yield 'a closure not named as markup' => ['$html .= $layout[\'field\']();', ['LateEscape.BuiltOutputNotEscaped']];
        yield 'markup built with sprintf()'   => ['return sprintf(\'<b>%s</b>\', $x);', ['LateEscape.MarkupSprintf']];
        yield 'a format split over literals'  => ['return sprintf(\'<b>\' . \'%s</b>\', $x);', ['LateEscape.MarkupSprintf']];
        yield 'a returned built value'        => ['return \'<b>\' . $x . \'</b>\';', ['LateEscape.BuiltOutputNotEscaped']];
        yield 'no markup, no check'           => ['$header = \'From: \' . $name . \' <\' . $email . \'>\';', []];
        yield 'a parameter default'           => ['function f($a = \'<b>\') {}', []];

        // RequestInput.
        yield 'a ?? inside wp_unslash()'      => ['$a = sanitize_text_field(wp_unslash($_POST[\'a\'] ?? \'\'));', ['RequestInput.NotSanitizedInPlace']];
        yield 'no wp_unslash()'               => ['$a = isset($_POST[\'a\']) ? sanitize_key($_POST[\'a\']) : \'\';', ['RequestInput.NotSanitizedInPlace']];
        yield 'the shape the plugin reads'    => ['$a = isset($_POST[\'a\']) ? sanitize_text_field(wp_unslash($_POST[\'a\'])) : \'\';', []];
        yield 'an array, mapped'              => ['$a = isset($_POST[\'a\']) ? map_deep(wp_unslash($_POST[\'a\']), \'sanitize_key\') : [];', []];
        yield 'a type guard'                  => ['$ok = is_string($_POST[\'a\'] ?? null);', []];
        yield 'the keys, sanitized'           => ['$k = array_map(\'sanitize_text_field\', array_keys($_FILES));', []];
        yield 'a file part, sanitized'        => ['$n = map_deep($_FILES[\'f\'][\'name\'], \'sanitize_file_name\');', []];
        yield 'a raw comparison'              => ['$get = $_SERVER[\'REQUEST_METHOD\'] === \'GET\';', ['RequestInput.NotSanitizedInPlace']];

        // NonceShape.
        yield 'a nonce result kept'           => ['$ok = wp_verify_nonce($n, \'a\');', ['NonceShape.NonceResultStored']];
        yield 'a nonce joined with &&'        => ['function h() { if ($a && !wp_verify_nonce($n, \'a\')) { wp_die(); } }', ['NonceShape.NonceConditional']];
        yield 'a nonce in a branch'           => ['function h() { if ($a) { check_ajax_referer(\'a\', \'n\'); } }', ['NonceShape.NonceConditional']];
        yield 'a check that never stops'      => ['function h() { check_ajax_referer(\'a\', \'n\', false); }', ['NonceShape.NonceNotFailEarly']];
        yield 'a check that lets it through'  => ['function h() { if (wp_verify_nonce($n, \'a\')) { work(); } }', ['NonceShape.NonceNotFailEarly']];
        yield 'check_ajax_referer() alone'    => ['function h() { check_ajax_referer(\'a\', \'n\'); }', []];
        yield 'an if that fails early'        => [
            'function h() { if (!isset($_POST[\'n\']) || !wp_verify_nonce(sanitize_key(wp_unslash($_POST[\'n\'])), \'a\')) { wp_die(); } }',
            [],
        ];
        yield 'a per-item check after it'     => [
            'function h($items) { check_ajax_referer(\'a\', \'n\'); foreach ($items as $i) { if (!wp_verify_nonce($i, \'b\')) { continue; } } }',
            [],
        ];
        yield 'a handler without a nonce'     => [
            'class C { public static function init() { add_action(\'wp_ajax_x\', [self::class, \'h\']); } public static function h() {} }',
            ['NonceShape.HandlerWithoutNonce'],
        ];
        yield 'a handler with its nonce'      => [
            'class C { public static function init() { add_action(\'wp_ajax_x\', [self::class, \'h\']); } '
                . 'public static function h() { check_ajax_referer(\'a\', \'n\'); } }',
            [],
        ];
    }

    /**
     * @param list<string> $expected Sniff codes, without the standard's prefix, in any order.
     */
    #[DataProvider('cases')]
    public function testReportsWhatReviewersFlag(string $code, array $expected): void
    {
        $found = self::sniff("<?php\n" . $code . "\n");
        sort($found);
        sort($expected);
        $this->assertSame($expected, $found, $code);
    }

    /**
     * The codes the FormFabricatorReview standard reports for a file, without the 'FormFabricatorReview.Security.'
     * prefix, one per finding.
     *
     * @return list<string>
     */
    private static function sniff(string $source): array
    {
        $root    = dirname(__DIR__, 3);
        $command = [
            PHP_BINARY,
            $root . '/vendor/squizlabs/php_codesniffer/bin/phpcs',
            '-q',
            '--report=json',
            '--standard=' . $root . '/tools/phpcs/FormFabricatorReview',
            '--stdin-path=case.php',
            '-',
        ];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        self::assertIsResource($process);
        fwrite($pipes[0], $source);
        fclose($pipes[0]);
        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $report = json_decode(substr($output, (int) strpos($output, '{')), true);
        self::assertIsArray($report, 'phpcs gave no report: ' . $output . $errors);
        $codes = [];
        foreach ($report['files'] as $file) {
            foreach ($file['messages'] as $message) {
                self::assertStringStartsWith('FormFabricatorReview.Security.', $message['source'], $message['message']);
                $codes[] = substr($message['source'], strlen('FormFabricatorReview.Security.'));
            }
        }
        return $codes;
    }
}
