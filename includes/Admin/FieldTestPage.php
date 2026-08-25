<?php

/**
 * Admin page that runs field-type unit tests in the browser.
 * Only registered when WP_DEBUG is true.
 *
 * PHP Version 8.1
 *
 * @category  FormFabricator
 * @package   FormFabricator
 * @author    Alexander Jorek
 * @copyright 2026 Alexander Jorek
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 * @version   1.0.4
 * @link      https://github.com/AlexanderJorek/FormFabricator
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 3
 * of the License, or (at your option) any later version.
 */

namespace FabricatorForms\Admin;

defined('ABSPATH') || exit;

// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_var_export -- this entire file only loads when WP_DEBUG is true (see Plugin::load()); var_export() is the intended human-readable diff output for this dev-only test harness, never shipped active in production.

/**
 * Runs PHP and JS field tests on a WP_DEBUG-only admin submenu page.
 */
class FieldTestPage
{
    /**
     * Registers the admin menu entry when WP_DEBUG is active.
     *
     * @return void
     */
    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'addMenu'], 20);
        add_filter('admin_body_class', [self::class, 'bodyClass']);
    }

    /**
     * Appends fabricator-list-page body class on the test page.
     *
     * @param string $classes Current body classes.
     */
    public static function bodyClass(string $classes): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin body-class check, no data written.
        if (isset($_GET['page']) && $_GET['page'] === 'fabricator-field-tests') {
            $classes .= ' fabricator-list-page';
        }
        return $classes;
    }

    /**
     * Adds the submenu page under the FormFabricator menu.
     *
     * @return void
     */
    public static function addMenu(): void
    {
        if (!(defined('WP_DEBUG') && WP_DEBUG)) {
            return;
        }
        add_submenu_page(
            'fabricator-forms',
            'Field Tests',
            'Field Tests',
            'manage_options',
            'fabricator-field-tests',
            [self::class, 'render']
        );
    }

    // ── test harness ────────────────────────────────────────────────────────

    private static int $pass = 0;
    private static int $fail = 0;
    /** @var string[] */
    private static array $log = [];
    /** @var string[] plain-text lines for the copy box */
    private static array $failLines = [];
    private static string $currentSection = '';
    /** Side-channel: helpers write input/output here; run() reads after the lambda. */
    private static string $lastIn  = '';
    private static string $lastOut = '';

    private static function run(string $name, callable $fn): void
    {
        self::$lastIn  = '';
        self::$lastOut = '';
        try {
            $result = $fn();
            $in  = esc_html(self::$lastIn);
            $out = esc_html(self::$lastOut);
            if ($result === true) {
                self::$pass++;
                self::$log[] = '<tr class="ff-pass"><td><i class="fa-solid fa-check" aria-hidden="true"></i></td><td>' . esc_html($name) . '</td>'
                    . '<td class="ff-io">' . $in . '</td>'
                    . '<td class="ff-io ff-out">' . $out . '</td>'
                    . '<td></td></tr>';
            } else {
                self::$fail++;
                $msg = is_string($result) ? $result : var_export($result, true);
                self::$log[] = '<tr class="ff-fail"><td><i class="fa-solid fa-xmark" aria-hidden="true"></i></td><td>' . esc_html($name) . '</td>'
                    . '<td class="ff-io">' . $in . '</td>'
                    . '<td class="ff-io ff-out">' . $out . '</td>'
                    . '<td>' . esc_html($msg) . '</td></tr>';
                self::$failLines[] = '[' . self::$currentSection . '] ' . $name
                    . "\tinput: " . self::$lastIn
                    . "\t" . $msg;
            }
        } catch (\Throwable $e) {
            self::$fail++;
            $in  = esc_html(self::$lastIn);
            $out = esc_html(self::$lastOut);
            self::$log[] = '<tr class="ff-fail"><td><i class="fa-solid fa-xmark" aria-hidden="true"></i></td><td>' . esc_html($name) . '</td>'
                . '<td class="ff-io">' . $in . '</td>'
                . '<td class="ff-io ff-out">' . $out . '</td>'
                . '<td>Exception: ' . esc_html($e->getMessage()) . '</td></tr>';
            self::$failLines[] = '[' . self::$currentSection . '] ' . $name
                . "\tinput: " . self::$lastIn
                . "\tException: " . $e->getMessage();
        }
    }

    private static function section(string $label): void
    {
        self::$currentSection = $label;
        self::$log[] = '<tr class="ff-section"><td colspan="5"><strong>' . esc_html($label) . '</strong></td></tr>';
    }

    // ── assertion helpers ────────────────────────────────────────────────────

    private static function contains(string $html, string $needle, string $what = ''): bool|string
    {
        $found = str_contains($html, $needle);
        self::$lastIn  = $needle;
        self::$lastOut = $found ? 'found' : 'not found';
        return $found ? true : (($what ?: $needle) . ' not found in output');
    }

    private static function expectError(mixed $value, array $config, \FabricatorForms\Fields\BaseField $h): bool|string
    {
        $r = $h->validate($value, $config);
        self::$lastIn  = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (string)$value;
        self::$lastOut = is_string($r) ? $r : var_export($r, true);
        return (is_string($r) && $r !== '') ? true : 'expected error string, got ' . var_export($r, true);
    }

    private static function expectOk(mixed $value, array $config, \FabricatorForms\Fields\BaseField $h): bool|string
    {
        $r = $h->validate($value, $config);
        self::$lastIn  = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (string)$value;
        self::$lastOut = $r === true ? 'valid' : var_export($r, true);
        return $r === true ? true : 'expected true, got ' . var_export($r, true);
    }

    private static function expectMap(mixed $value, array $config, \FabricatorForms\Fields\BaseField $h, string $expected): bool|string
    {
        $r = $h->map($value, $config);
        self::$lastIn  = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : var_export($value, true);
        self::$lastOut = $r;
        return $r === $expected ? true : 'expected ' . var_export($expected, true) . ', got ' . var_export($r, true);
    }

    private static function expectMapContains(mixed $value, array $config, \FabricatorForms\Fields\BaseField $h, string ...$needles): bool|string
    {
        $r = $h->map($value, $config);
        self::$lastIn  = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : var_export($value, true);
        self::$lastOut = $r;
        foreach ($needles as $needle) {
            if (!str_contains($r, $needle)) {
                return var_export($needle, true) . ' not found in: ' . $r;
            }
        }
        return true;
    }

    private static function schemaIntegrity(\FabricatorForms\Fields\BaseField $h): bool|string
    {
        // These schema types are composite UI widgets with no single config key of their own
        $noKeyTypes = ['subfields', 'rating_preview', 'notice'];
        $schemaKeys = [];
        foreach (array_merge($h->getGeneralSchema(), $h->getAdvancedSchema()) as $entry) {
            if (isset($entry['type']) && in_array($entry['type'], $noKeyTypes, true)) {
                continue;
            }
            if (isset($entry['key'])) {
                $schemaKeys[] = $entry['key'];
            }
            foreach (['count_key','half_key','format_key','prefill_key'] as $k) {
                if (isset($entry[$k])) {
                    $schemaKeys[] = $entry[$k];
                }
            }
        }
        $missing = array_diff(array_unique($schemaKeys), array_keys($h->getDefaultConfig()));
        self::$lastIn  = count($schemaKeys) . ' schema keys';
        self::$lastOut = empty($missing) ? 'all in getDefaultConfig' : 'missing: ' . implode(', ', $missing);
        return empty($missing) ? true : 'schema keys missing from getDefaultConfig(): ' . implode(', ', $missing);
    }

    private static function renderBasic(\FabricatorForms\Fields\BaseField $h, array $cfg, string $fid = 'field-test-1'): bool|string
    {
        $html = $h->render($cfg, $fid);
        self::$lastIn  = 'field_id=' . $fid;
        self::$lastOut = is_string($html)
            ? '[' . strlen($html) . 'B] ' . substr(wp_strip_all_tags($html), 0, 55)
            : '(not string)';
        if (!is_string($html) || $html === '') {
            return 'render() returned empty string';
        }
        if (!str_contains($html, $fid)) {
            return 'render() output does not contain field_id';
        }
        if (!str_contains($html, 'fabricator-field')) {
            return 'render() output missing fabricator-field class';
        }
        return true;
    }

    // ── test suites — every assertion reflects the actual field implementation ──

    private static function testText(): void
    {
        self::section('text');
        $h   = new \FabricatorForms\Fields\TextField();
        $cfg = array_merge($h->getDefaultConfig(), ['type'=>'text','label'=>'Name']);

        self::run('schema integrity', fn() => self::schemaIntegrity($h));
        self::run('render basic', fn() => self::renderBasic($h, $cfg));
        self::run('render <input', fn() => self::contains($h->render($cfg, 'f1'), '<input'));
        self::run('render required attr', fn() => self::contains($h->render(array_merge($cfg, ['required'=>true]), 'f1'), 'required'));
        self::run('render hide_label', function () use ($h, $cfg) {
            return !str_contains($h->render(array_merge($cfg, ['hide_label'=>true]), 'f1'), '<label') ? true : 'label still present';
        });
        self::run('render placeholder', fn() => self::contains($h->render(array_merge($cfg, ['placeholder'=>'Enter']), 'f1'), 'Enter'));
        self::run('render description', fn() => self::contains($h->render(array_merge($cfg, ['description'=>'Help']), 'f1'), 'Help'));
        self::run('render char limit → maxlength', function () use ($h, $cfg) {
            return self::contains($h->render(array_merge($cfg, ['limit_type'=>'chars','limit_max'=>'10']), 'f1'), 'maxlength');
        });
        self::run('render word limit → data-word-limit', function () use ($h, $cfg) {
            return self::contains($h->render(array_merge($cfg, ['limit_type'=>'words','limit_max'=>'5']), 'f1'), 'data-word-limit');
        });
        self::run('validate required empty', fn() => self::expectError('', array_merge($cfg, ['required'=>true]), $h));
        self::run('validate optional empty', fn() => self::expectOk('', $cfg, $h));
        self::run('validate with value', fn() => self::expectOk('Hello', $cfg, $h));
        self::run('validate word limit exceeded', function () use ($h, $cfg) {
            return self::expectError('one two three four five six', array_merge($cfg, ['limit_type'=>'words','limit_max'=>'3']), $h);
        });
        self::run('validate word limit within', function () use ($h, $cfg) {
            return self::expectOk('one two three', array_merge($cfg, ['limit_type'=>'words','limit_max'=>'3']), $h);
        });
        self::run('validate word limit exact at boundary (count===max)', function () use ($h, $cfg) {
            return self::expectOk('alpha beta gamma delta', array_merge($cfg, ['limit_type'=>'words','limit_max'=>'4']), $h);
        });
        self::run('validate char limit exceeded (server-side)', function () use ($h, $cfg) {
            return self::expectError('this value is far too long', array_merge($cfg, ['limit_type'=>'chars','limit_max'=>'5']), $h);
        });
        self::run('validate char limit within', function () use ($h, $cfg) {
            return self::expectOk('short', array_merge($cfg, ['limit_type'=>'chars','limit_max'=>'10']), $h);
        });
        self::run('validate char limit exact at boundary (len===max)', function () use ($h, $cfg) {
            return self::expectOk('abcde', array_merge($cfg, ['limit_type'=>'chars','limit_max'=>'5']), $h);
        });
        self::run('map non-empty', fn() => self::expectMap('Hello', $cfg, $h, 'Hello'));
        self::run('map empty → Kein Eintrag', fn() => self::expectMapContains('', $cfg, $h, __('[No entry]', 'formfabricator')));
        self::run('sanitize strips <script>', function () use ($h) {
            return !str_contains($h->sanitizeConfigValue('label', '<script>x</script>'), '<script') ? true : 'script not stripped';
        });
    }

    private static function testTextarea(): void
    {
        self::section('textarea');
        $h   = new \FabricatorForms\Fields\TextareaField();
        $cfg = array_merge($h->getDefaultConfig(), ['type'=>'textarea','label'=>'Nachricht']);

        self::run('schema integrity', fn() => self::schemaIntegrity($h));
        self::run('render basic', fn() => self::renderBasic($h, $cfg));
        self::run('render <textarea', fn() => self::contains($h->render($cfg, 'f1'), '<textarea'));
        self::run('render default rows=5 (no override)', fn() => self::contains($h->render($cfg, 'f1'), 'rows="5"'));
        self::run('render rows attr', fn() => self::contains($h->render(array_merge($cfg, ['rows'=>6]), 'f1'), 'rows="6"'));
        self::run('render char limit → maxlength', fn() => self::contains($h->render(array_merge($cfg, ['limit_type'=>'chars','limit_max'=>'200']), 'f1'), 'maxlength'));
        self::run('render word limit → data-word-limit', function () use ($h, $cfg) {
            return self::contains($h->render(array_merge($cfg, ['limit_type'=>'words','limit_max'=>'10']), 'f1'), 'data-word-limit');
        });
        self::run('validate required empty', fn() => self::expectError('', array_merge($cfg, ['required'=>true]), $h));
        self::run('validate optional empty', fn() => self::expectOk('', $cfg, $h));
        self::run('validate multiline', fn() => self::expectOk("line1\nline2", $cfg, $h));
        self::run('validate word limit exceeded', function () use ($h, $cfg) {
            return self::expectError('a b c d e', array_merge($cfg, ['limit_type'=>'words','limit_max'=>'3']), $h);
        });
        self::run('validate word limit within', function () use ($h, $cfg) {
            return self::expectOk('a b c', array_merge($cfg, ['limit_type'=>'words','limit_max'=>'3']), $h);
        });
        self::run('map non-empty', fn() => self::expectMap('Hello', $cfg, $h, 'Hello'));
        self::run('map empty → Kein Eintrag', fn() => self::expectMapContains('', $cfg, $h, __('[No entry]', 'formfabricator')));
        self::run('sanitize strips <script> (inherited from BaseField)', function () use ($h) {
            return !str_contains($h->sanitizeConfigValue('description', '<p>ok</p><script>x</script>'), '<script') ? true : 'script not stripped';
        });
    }

    private static function testEmail(): void
    {
        self::section('email');
        $h   = new \FabricatorForms\Fields\EmailField();
        $cfg = array_merge($h->getDefaultConfig(), ['type'=>'email','label'=>'E-Mail']);

        self::run('schema integrity', fn() => self::schemaIntegrity($h));
        self::run('render basic', fn() => self::renderBasic($h, $cfg));
        self::run('render type="email"', fn() => self::contains($h->render($cfg, 'f1'), 'type="email"'));
        self::run('validate required empty', fn() => self::expectError('', array_merge($cfg, ['required'=>true]), $h));
        self::run('validate optional empty', fn() => self::expectOk('', $cfg, $h));
        self::run('validate valid email', fn() => self::expectOk('user@example.com', $cfg, $h));
        self::run('validate invalid format', function () use ($h, $cfg) {
            return self::expectError('notanemail', array_merge($cfg, ['validate_format'=>true]), $h);
        });
        self::run('validate blocked domain', function () use ($h, $cfg) {
            // patterns match the full address; use wildcard *@domain
            $c = array_merge($cfg, ['filter_mode'=>'block','filter_patterns'=>'*@spam.com']);
            return self::expectError('user@spam.com', $c, $h);
        });
        self::run('validate allowed domain pass', function () use ($h, $cfg) {
            $c = array_merge($cfg, ['filter_mode'=>'allow','filter_patterns'=>'*@example.com']);
            return self::expectOk('user@example.com', $c, $h);
        });
        self::run('validate allowed domain fail', function () use ($h, $cfg) {
            $c = array_merge($cfg, ['filter_mode'=>'allow','filter_patterns'=>'*@example.com']);
            return self::expectError('user@other.com', $c, $h);
        });
        self::run('validate_format=false allows malformed string', function () use ($h, $cfg) {
            return self::expectOk('notanemail', array_merge($cfg, ['validate_format'=>false]), $h);
        });
        self::run('validate multi-pattern allow list (; separated)', function () use ($h, $cfg) {
            $c = array_merge($cfg, ['filter_mode'=>'allow','filter_patterns'=>'*@example.com;*@test.com']);
            return self::expectOk('user@test.com', $c, $h);
        });
    }

    private static function testName(): void
    {
        self::section('name');
        $h   = new \FabricatorForms\Fields\NameField();
        $cfg = array_merge($h->getDefaultConfig(), ['type'=>'name','label'=>'Name','expanded'=>false]);
        $exp = array_merge($cfg, [
            'expanded'=>true,'fname_enabled'=>true,'fname_label'=>'Vorname','fname_required'=>true,
            'lname_enabled'=>true,'lname_label'=>'Nachname','lname_required'=>true,
            'mname_enabled'=>false,'prefix_enabled'=>false,
        ]);

        self::run('schema integrity', fn() => self::schemaIntegrity($h));
        self::run('render simple <input>', fn() => self::renderBasic($h, $cfg));
        self::run('render expanded subfields', fn() => self::contains($h->render($exp, 'f1'), 'Vorname'));
        self::run('render expanded prefix select', function () use ($h, $cfg) {
            $c = array_merge($cfg, ['expanded'=>true,'prefix_enabled'=>true,'prefix_label'=>'Anrede','fname_enabled'=>false,'lname_enabled'=>false,'mname_enabled'=>false]);
            return self::contains($h->render($c, 'f1'), '<select');
        });
        self::run('validate required empty simple', fn() => self::expectError('', array_merge($cfg, ['required'=>true]), $h));
        self::run('validate optional empty simple', fn() => self::expectOk('', $cfg, $h));
        self::run('validate filled simple', fn() => self::expectOk('Max Mustermann', $cfg, $h));
        self::run('validate required empty expanded', fn() => self::expectError(['fname'=>'','lname'=>''], $exp, $h));
        self::run('validate required filled expanded', fn() => self::expectOk(['fname'=>'Hans','lname'=>'Müller'], $exp, $h));
        self::run('validate partial expanded → error', function () use ($h, $exp) {
            return self::expectError(['fname'=>'Hans','lname'=>''], $exp, $h);
        });
        self::run('map simple string', fn() => self::expectMap('Max Mustermann', $cfg, $h, 'Max Mustermann'));
        self::run('map simple empty → Kein Eintrag', fn() => self::expectMapContains('', $cfg, $h, __('[No entry]', 'formfabricator')));
        self::run('map expanded has Vorname', fn() => self::expectMapContains(['fname'=>'Hans','lname'=>'Müller'], $exp, $h, 'Hans'));
        self::run('map expanded has Nachname', fn() => self::expectMapContains(['fname'=>'Hans','lname'=>'Müller'], $exp, $h, 'Müller'));
        self::run('map expanded empty → Kein Eintrag', fn() => self::expectMapContains(['fname'=>'','lname'=>''], $exp, $h, __('[No entry]', 'formfabricator')));
        self::run('render middle name subfield', function () use ($h, $exp) {
            $c = array_merge($exp, ['mname_enabled'=>true,'mname_label'=>'Zweiter Vorname']);
            return self::contains($h->render($c, 'f1'), 'Zweiter Vorname');
        });
        self::run('validate middle name filled passes', function () use ($h, $exp) {
            $c = array_merge($exp, ['mname_enabled'=>true,'mname_required'=>true]);
            return self::expectOk(['fname'=>'Hans','lname'=>'Müller','mname'=>'Peter'], $c, $h);
        });
        self::run('map includes middle name', function () use ($h, $exp) {
            $c = array_merge($exp, ['mname_enabled'=>true]);
            return self::expectMapContains(['fname'=>'Hans','lname'=>'Müller','mname'=>'Peter'], $c, $h, 'Peter');
        });
        self::run('validate prefix_required=true is skipped (select subfield always has a value)', function () use ($h, $cfg) {
            $c = array_merge($cfg, ['expanded'=>true,'prefix_enabled'=>true,'prefix_required'=>true,'fname_enabled'=>false,'lname_enabled'=>false,'mname_enabled'=>false]);
            return self::expectOk([], $c, $h);
        });
    }

    private static function testPhone(): void
    {
        self::section('phone');
        $h   = new \FabricatorForms\Fields\PhoneField();
        $cfg = array_merge($h->getDefaultConfig(), ['type'=>'phone','label'=>'Telefon']);

        self::run('schema integrity', fn() => self::schemaIntegrity($h));
        self::run('render basic', fn() => self::renderBasic($h, $cfg));
        self::run('render type="tel"', fn() => self::contains($h->render($cfg, 'f1'), 'type="tel"'));
        self::run('render phone_mode data-attr', function () use ($h, $cfg) {
            return self::contains($h->render(array_merge($cfg, ['phone_mode'=>'any']), 'f1'), 'data-phone-mode');
        });
        self::run('render countries data attrs', function () use ($h, $cfg) {
            $c = array_merge($cfg, ['phone_mode'=>'countries','phone_country_mode'=>'allow','phone_country_list'=>['+49','+43']]);
            return self::contains($h->render($c, 'f1'), 'data-phone-country');
        });
        self::run('validate required empty', fn() => self::expectError('', array_merge($cfg, ['required'=>true]), $h));
        self::run('validate optional empty', fn() => self::expectOk('', $cfg, $h));
        self::run('validate no-mode any value passes', fn() => self::expectOk('+4915123456789', $cfg, $h));
        self::run('validate mode=any valid', fn() => self::expectOk('+4915123456789', array_merge($cfg, ['phone_mode'=>'any']), $h));
        self::run('validate mode=any invalid', function () use ($h, $cfg) {
            return self::expectError('123', array_merge($cfg, ['phone_mode'=>'any']), $h);
        });
        self::run('validate mode=countries missing +', function () use ($h, $cfg) {
            $c = array_merge($cfg, ['phone_mode'=>'countries','phone_country_mode'=>'allow','phone_country_list'=>['+49']]);
            return self::expectError('04915123456789', $c, $h);
        });
        self::run('validate mode=countries allowed pass', function () use ($h, $cfg) {
            $c = array_merge($cfg, ['phone_mode'=>'countries','phone_country_mode'=>'allow','phone_country_list'=>['+49']]);
            return self::expectOk('+4915123456789', $c, $h);
        });
        self::run('validate mode=countries allowed fail', function () use ($h, $cfg) {
            $c = array_merge($cfg, ['phone_mode'=>'countries','phone_country_mode'=>'allow','phone_country_list'=>['+44']]);
            return self::expectError('+4915123456789', $c, $h);
        });
        self::run('validate mode=countries disallow match', function () use ($h, $cfg) {
            $c = array_merge($cfg, ['phone_mode'=>'countries','phone_country_mode'=>'disallow','phone_country_list'=>['+49']]);
            return self::expectError('+4915123456789', $c, $h);
        });
        self::run('validate mode=countries disallow non-matching passes', function () use ($h, $cfg) {
            $c = array_merge($cfg, ['phone_mode'=>'countries','phone_country_mode'=>'disallow','phone_country_list'=>['+49']]);
            return self::expectOk('+33123456789', $c, $h);
        });
        self::run('validate overlapping country prefix codes both match', function () use ($h, $cfg) {
            // '+3' is a prefix of '+358' — matching should still succeed for a +358 number
            $c = array_merge($cfg, ['phone_mode'=>'countries','phone_country_mode'=>'allow','phone_country_list'=>['+3','+358']]);
            return self::expectOk('+358123456789', $c, $h);
        });
        self::run('map non-empty', fn() => self::expectMap('+4915123456789', $cfg, $h, '+4915123456789'));
        self::run('map empty → Kein Eintrag', fn() => self::expectMapContains('', $cfg, $h, __('[No entry]', 'formfabricator')));
    }

    private static function testNumber(): void
    {
        self::section('number');
        $h   = new \FabricatorForms\Fields\NumberField();
        $cfg = array_merge($h->getDefaultConfig(), ['type'=>'number','label'=>'Anzahl']);

        self::run('schema integrity', fn() => self::schemaIntegrity($h));
        self::run('render basic', fn() => self::renderBasic($h, $cfg));
        self::run('render type="number"', fn() => self::contains($h->render($cfg, 'f1'), 'type="number"'));
        self::run('render min/max/step', function () use ($h, $cfg) {
            $html = $h->render(array_merge($cfg, ['min'=>'1','max'=>'100','step'=>'5']), 'f1');
            return str_contains($html, 'min') ? true : 'min attr missing';
        });
        self::run('validate required empty', fn() => self::expectError('', array_merge($cfg, ['required'=>true]), $h));
        self::run('validate optional empty', fn() => self::expectOk('', $cfg, $h));
        self::run('validate valid number', fn() => self::expectOk('42', $cfg, $h));
        self::run('validate non-numeric', function () use ($h, $cfg) {
            return self::expectError('abc', array_merge($cfg, ['required'=>true]), $h);
        });
        self::run('validate below min', function () use ($h, $cfg) {
            return self::expectError('5', array_merge($cfg, ['min'=>'10']), $h);
        });
        self::run('validate above max', function () use ($h, $cfg) {
            return self::expectError('150', array_merge($cfg, ['max'=>'100']), $h);
        });
        self::run('validate exact at min passes', fn() => self::expectOk('10', array_merge($cfg, ['min'=>'10']), $h));
        self::run('validate exact at max passes', fn() => self::expectOk('100', array_merge($cfg, ['max'=>'100']), $h));
    }

    private static function testAddress(): void
    {
        self::section('address');
        $h   = new \FabricatorForms\Fields\AddressField();
        $cfg = array_merge($h->getDefaultConfig(), ['type'=>'address','label'=>'Adresse','expanded'=>false]);
        $exp = array_merge($cfg, [
            'expanded'=>true,
            'street_enabled'=>true,'street_label'=>'Straße','street_required'=>true,
            'city_enabled'=>true,'city_label'=>'Stadt','city_required'=>true,
            'zip_enabled'=>true,'zip_label'=>'PLZ','zip_required'=>true,
            'street2_enabled'=>false,'state_enabled'=>false,'country_enabled'=>false,
        ]);

        self::run('schema integrity', fn() => self::schemaIntegrity($h));
        self::run('render simple <input>', fn() => self::renderBasic($h, $cfg));
        self::run('render expanded subfields', fn() => self::contains($h->render($exp, 'f1'), 'Straße'));
        self::run('validate required empty simple', fn() => self::expectError('', array_merge($cfg, ['required'=>true]), $h));
        self::run('validate optional empty simple', fn() => self::expectOk('', $cfg, $h));
        self::run('validate filled simple', fn() => self::expectOk('Hauptstr. 1, Berlin', $cfg, $h));
        self::run('validate required fields empty exp', fn() => self::expectError(['street'=>'','city'=>'','zip'=>''], $exp, $h));
        self::run('validate required fields filled exp', fn() => self::expectOk(['street'=>'Hauptstr. 1','city'=>'Berlin','zip'=>'10115'], $exp, $h));
        self::run('validate partial required exp err', function () use ($h, $exp) {
            return self::expectError(['street'=>'Hauptstr. 1','city'=>'','zip'=>'10115'], $exp, $h);
        });
        self::run('map non-array → Kein Eintrag', fn() => self::expectMapContains('not-array', $cfg, $h, __('[No entry]', 'formfabricator')));
        self::run('map array has Straße', fn() => self::expectMapContains(['street'=>'Hauptstr. 1','city'=>'Berlin','zip'=>'10115'], $exp, $h, 'Hauptstr'));
        self::run('map array has Stadt', fn() => self::expectMapContains(['street'=>'Hauptstr. 1','city'=>'Berlin','zip'=>'10115'], $exp, $h, 'Berlin'));
        $expFull = array_merge($exp, [
            'street2_enabled'=>true,'street2_label'=>'Adresszusatz',
            'state_enabled'=>true,'state_label'=>'Bundesland',
            'country_enabled'=>true,'country_label'=>'Land',
        ]);
        self::run('render street2/state/country subfields', function () use ($h, $expFull) {
            $html = $h->render($expFull, 'f1');
            self::$lastIn  = 'expanded with street2/state/country enabled';
            self::$lastOut = $html;
            foreach (['Adresszusatz', 'Bundesland', 'Land'] as $needle) {
                if (!str_contains($html, $needle)) {
                    return $needle . ' not found in output';
                }
            }
            return true;
        });
        self::run('map includes street2/state/country', function () use ($h, $expFull) {
            return self::expectMapContains(
                ['street'=>'Hauptstr. 1','street2'=>'3.OG','city'=>'Berlin','zip'=>'10115','state'=>'Bayern','country'=>'Deutschland'],
                $expFull,
                $h,
                '3.OG',
                'Bayern',
                'Deutschland'
            );
        });
        self::run('validate multiple required fields empty simultaneously', function () use ($h, $exp) {
            $r = $h->validate(['street'=>'','city'=>'','zip'=>'10115'], $exp);
            self::$lastIn  = 'street empty, city empty, zip filled';
            self::$lastOut = is_string($r) ? $r : var_export($r, true);
            if (!is_string($r) || $r === '') {
                return 'expected error string, got ' . var_export($r, true);
            }
            return (str_contains($r, $exp['street_label']) && str_contains($r, $exp['city_label']))
                ? true
                : 'expected both missing labels in message: ' . $r;
        });
    }

    private static function testDate(): void
    {
        self::section('date');
        $h   = new \FabricatorForms\Fields\DateField();
        $cfg = array_merge($h->getDefaultConfig(), ['type'=>'date','label'=>'Datum']);

        self::run('schema integrity', fn() => self::schemaIntegrity($h));
        self::run('render basic', fn() => self::renderBasic($h, $cfg));
        self::run('render show_picker=true → cal btn', function () use ($h, $cfg) {
            return self::contains($h->render(array_merge($cfg, ['show_picker'=>true]), 'f1'), 'fabricator-date-cal-btn');
        });
        self::run('render prefill_today attr', function () use ($h, $cfg) {
            return self::contains($h->render(array_merge($cfg, ['prefill_today'=>true]), 'f1'), 'data-prefill-today');
        });
        self::run('render show_picker=false → no cal btn', function () use ($h, $cfg) {
            return !str_contains($h->render(array_merge($cfg, ['show_picker'=>false]), 'f1'), 'fabricator-date-cal-btn')
                ? true : 'calendar button present when show_picker=false';
        });
        self::run('validate required empty', fn() => self::expectError('', array_merge($cfg, ['required'=>true]), $h));
        self::run('validate optional empty', fn() => self::expectOk('', $cfg, $h));
        self::run('validate valid DD.MM.YYYY', fn() => self::expectOk('10.07.2026', $cfg, $h));
        self::run('validate wrong format (ISO)', fn() => self::expectError('2026-07-10', array_merge($cfg, ['required'=>true]), $h));
        self::run('validate wrong format (text)', fn() => self::expectError('not-a-date', array_merge($cfg, ['required'=>true]), $h));
        self::run('validate invalid calendar date', fn() => self::expectError('31.02.2026', $cfg, $h));
        self::run('map non-empty returns value', fn() => self::expectMap('10.07.2026', $cfg, $h, '10.07.2026'));
        self::run('map empty → Kein Eintrag', fn() => self::expectMapContains('', $cfg, $h, __('[No entry]', 'formfabricator')));
        self::run('validate before min_date → error', function () use ($h, $cfg) {
            return self::expectError('10.07.2020', array_merge($cfg, ['min_date'=>'01.01.2025','max_date'=>'31.12.2025']), $h);
        });
        self::run('validate after max_date → error', function () use ($h, $cfg) {
            return self::expectError('10.07.2030', array_merge($cfg, ['min_date'=>'01.01.2025','max_date'=>'31.12.2025']), $h);
        });
        self::run('validate within min_date/max_date range → ok', function () use ($h, $cfg) {
            return self::expectOk('15.06.2025', array_merge($cfg, ['min_date'=>'01.01.2025','max_date'=>'31.12.2025']), $h);
        });
        self::run('validate exactly at min_date → ok', function () use ($h, $cfg) {
            return self::expectOk('01.01.2025', array_merge($cfg, ['min_date'=>'01.01.2025']), $h);
        });
        self::run('validate exactly at max_date → ok', function () use ($h, $cfg) {
            return self::expectOk('31.12.2025', array_merge($cfg, ['max_date'=>'31.12.2025']), $h);
        });
    }

    private static function testTime(): void
    {
        self::section('time');
        $h   = new \FabricatorForms\Fields\TimeField();
        $cfg = array_merge($h->getDefaultConfig(), ['type'=>'time','label'=>'Uhrzeit']);

        self::run('schema integrity', fn() => self::schemaIntegrity($h));
        self::run('render basic', fn() => self::renderBasic($h, $cfg));
        self::run('render type="time"', fn() => self::contains($h->render($cfg, 'f1'), 'type="time"'));
        self::run('render 12h data-attr', function () use ($h, $cfg) {
            return self::contains($h->render(array_merge($cfg, ['time_format'=>true]), 'f1'), 'data-time-format');
        });
        self::run('render prefill_now attr', function () use ($h, $cfg) {
            return self::contains($h->render(array_merge($cfg, ['prefill_now'=>true]), 'f1'), 'data-prefill-now');
        });
        self::run('render default has no data-time-format attr', function () use ($h, $cfg) {
            return !str_contains($h->render($cfg, 'f1'), 'data-time-format') ? true : 'attr present by default';
        });
        self::run('render default has no data-prefill-now attr', function () use ($h, $cfg) {
            return !str_contains($h->render($cfg, 'f1'), 'data-prefill-now') ? true : 'attr present by default';
        });
        self::run('validate required empty', fn() => self::expectError('', array_merge($cfg, ['required'=>true]), $h));
        self::run('validate optional empty', fn() => self::expectOk('', $cfg, $h));
        // TimeField has no format validation — any non-empty value passes
        self::run('validate any non-empty', fn() => self::expectOk('14:30', $cfg, $h));
        self::run('validate string passes', fn() => self::expectOk('not-a-time', $cfg, $h));
        self::run('map non-empty', fn() => self::expectMap('14:30', $cfg, $h, '14:30'));
        self::run('map empty → Kein Eintrag', fn() => self::expectMapContains('', $cfg, $h, __('[No entry]', 'formfabricator')));
    }

    private static function testCurrency(): void
    {
        self::section('currency');
        $h   = new \FabricatorForms\Fields\CurrencyField();
        $cfg = array_merge($h->getDefaultConfig(), ['type'=>'currency','label'=>'Betrag','currency'=>'EUR']);

        self::run('schema integrity', fn() => self::schemaIntegrity($h));
        self::run('render basic', fn() => self::renderBasic($h, $cfg));
        self::run('render EUR symbol', fn() => self::contains($h->render($cfg, 'f1'), '€'));
        self::run('render USD symbol', fn() => self::contains($h->render(array_merge($cfg, ['currency'=>'USD']), 'f1'), '$'));
        self::run('render GBP symbol', fn() => self::contains($h->render(array_merge($cfg, ['currency'=>'GBP']), 'f1'), '£'));
        self::run('render CHF symbol', fn() => self::contains($h->render(array_merge($cfg, ['currency'=>'CHF']), 'f1'), 'Fr.'));
        self::run('render JPY symbol', fn() => self::contains($h->render(array_merge($cfg, ['currency'=>'JPY']), 'f1'), '¥'));
        self::run('render CAD symbol', fn() => self::contains($h->render(array_merge($cfg, ['currency'=>'CAD']), 'f1'), 'CA$'));
        self::run('render min/max attrs', function () use ($h, $cfg) {
            $html = $h->render(array_merge($cfg, ['min_value'=>'5','max_value'=>'1000']), 'f1');
            return str_contains($html, 'min') && str_contains($html, 'max') ? true : 'attrs missing';
        });
        self::run('validate required empty', fn() => self::expectError('', array_merge($cfg, ['required'=>true]), $h));
        self::run('validate optional empty', fn() => self::expectOk('', $cfg, $h));
        self::run('validate valid amount', fn() => self::expectOk('12.50', $cfg, $h));
        self::run('validate non-numeric', fn() => self::expectError('abc', array_merge($cfg, ['required'=>true]), $h));
        self::run('validate below min_value', fn() => self::expectError('5', array_merge($cfg, ['min_value'=>'10']), $h));
        self::run('validate above max_value', fn() => self::expectError('200', array_merge($cfg, ['max_value'=>'100']), $h));
        self::run('validate exact at min_value passes', fn() => self::expectOk('10', array_merge($cfg, ['min_value'=>'10']), $h));
        self::run('validate exact at max_value passes', fn() => self::expectOk('100', array_merge($cfg, ['max_value'=>'100']), $h));
        self::run('map has numeric value', fn() => self::expectMapContains('12.5', $cfg, $h, '12'));
        self::run('map has currency symbol', fn() => self::expectMapContains('12.5', $cfg, $h, '€'));
        self::run('map uses comma decimal', fn() => self::expectMapContains('12.5', $cfg, $h, ','));
        self::run('map non-numeric string passes through unformatted', fn() => self::expectMap('N/A', $cfg, $h, 'N/A €'));
        self::run('map empty → Kein Eintrag', fn() => self::expectMapContains('', $cfg, $h, __('[No entry]', 'formfabricator')));
    }

    private static function testSelect(): void
    {
        self::section('select');
        $opts = [['value'=>'a','label'=>'Alpha','default'=>false],['value'=>'b','label'=>'Beta','default'=>true]];
        $h    = new \FabricatorForms\Fields\SelectField();
        $cfg  = array_merge($h->getDefaultConfig(), ['type'=>'select','label'=>'Auswahl','options'=>$opts]);

        self::run('schema integrity', fn() => self::schemaIntegrity($h));
        self::run('render basic', fn() => self::renderBasic($h, $cfg));
        self::run('render <select', fn() => self::contains($h->render($cfg, 'f1'), '<select'));
        self::run('render option labels', fn() => self::contains($h->render($cfg, 'f1'), 'Alpha'));
        self::run('render default selected', fn() => self::contains($h->render($cfg, 'f1'), 'selected'));
        self::run('render explicit value overrides default option', function () use ($h, $cfg) {
            $html = $h->render($cfg, 'f1', 'a');
            self::$lastIn  = 'explicit value=a (option b has default:true)';
            self::$lastOut = $html;
            if (!str_contains($html, 'value="a" selected')) {
                return 'explicit value "a" not selected';
            }
            if (str_contains($html, 'value="b" selected')) {
                return 'default option "b" should not be selected when an explicit value is given';
            }
            return true;
        });
        self::run('render other_option', function () use ($h, $cfg) {
            $html = $h->render(array_merge($cfg, ['other_option'=>true]), 'f1');
            return self::contains($html, '__other__');
        });
        self::run('validate required empty', fn() => self::expectError('', array_merge($cfg, ['required'=>true]), $h));
        self::run('validate optional empty', fn() => self::expectOk('', $cfg, $h));
        // SelectField has no server-side option-list validation — any value passes
        self::run('validate any value passes', fn() => self::expectOk('a', $cfg, $h));
        self::run('render other_max_length chars → maxlength attr', function () use ($h, $cfg) {
            $c = array_merge($cfg, ['other_option'=>true,'other_max_type'=>'chars','other_max_length'=>'20']);
            return self::contains($h->render($c, 'f1'), 'maxlength="20"');
        });
        self::run('render other_max_length words → data-word-limit attr', function () use ($h, $cfg) {
            $c = array_merge($cfg, ['other_option'=>true,'other_max_type'=>'words','other_max_length'=>'5']);
            return self::contains($h->render($c, 'f1'), 'data-word-limit="5"');
        });
        self::run('validate __other__ text within char limit → ok', function () use ($h, $cfg) {
            $c = array_merge($cfg, ['other_option'=>true,'other_max_type'=>'chars','other_max_length'=>'20']);
            return self::expectOk(['value'=>'__other__','__other_text__'=>'short text'], $c, $h);
        });
        self::run('validate __other__ text exceeding char limit → error', function () use ($h, $cfg) {
            $c = array_merge($cfg, ['other_option'=>true,'other_max_type'=>'chars','other_max_length'=>'5']);
            return self::expectError(['value'=>'__other__','__other_text__'=>'this is way too long'], $c, $h);
        });
        self::run('validate __other__ text exceeding word limit → error', function () use ($h, $cfg) {
            $c = array_merge($cfg, ['other_option'=>true,'other_max_type'=>'words','other_max_length'=>'2']);
            return self::expectError(['value'=>'__other__','__other_text__'=>'one two three'], $c, $h);
        });
        self::run('map known value → label', fn() => self::expectMap('a', $cfg, $h, 'Alpha'));
        self::run('map __other__ → [Other]', fn() => self::expectMapContains('__other__', $cfg, $h, __('[Other]', 'formfabricator')));
        self::run('map unknown value → raw', fn() => self::expectMap('unknown', $cfg, $h, 'unknown'));
        self::run('map empty → Kein Eintrag', fn() => self::expectMapContains('', $cfg, $h, __('[No entry]', 'formfabricator')));
    }

    private static function testRadio(): void
    {
        self::section('radio');
        $opts = [['value'=>'x','label'=>'X-Ray','default'=>false],['value'=>'y','label'=>'Yankee','default'=>false]];
        $h    = new \FabricatorForms\Fields\RadioField();
        $cfg  = array_merge($h->getDefaultConfig(), ['type'=>'radio','label'=>'Radio','options'=>$opts]);

        self::run('schema integrity', fn() => self::schemaIntegrity($h));
        self::run('render basic', fn() => self::renderBasic($h, $cfg));
        self::run('render type="radio"', fn() => self::contains($h->render($cfg, 'f1'), 'type="radio"'));
        self::run('render option labels', fn() => self::contains($h->render($cfg, 'f1'), 'X-Ray'));
        // RadioField render() has no other_option PHP output; the JS init wires __other__ client-side
        self::run('render other_option does not crash', function () use ($h, $cfg) {
            return is_string($h->render(array_merge($cfg, ['other_option'=>true]), 'f1')) ? true : 'render threw';
        });
        self::run('render layout=false → no horizontal class', function () use ($h, $cfg) {
            return !str_contains($h->render(array_merge($cfg, ['layout'=>false]), 'f1'), 'fabricator-radio-group--horizontal')
                ? true : 'horizontal class present when layout=false';
        });
        self::run('render layout=true → horizontal class', function () use ($h, $cfg) {
            return self::contains($h->render(array_merge($cfg, ['layout'=>true]), 'f1'), 'fabricator-radio-group--horizontal');
        });
        self::run('render default-selected fallback (no value)', function () use ($h, $cfg) {
            $optsD = [['value'=>'x','label'=>'X-Ray','default'=>false],['value'=>'y','label'=>'Yankee','default'=>true]];
            $c     = array_merge($cfg, ['options'=>$optsD]);
            $html  = $h->render($c, 'f1');
            self::$lastIn  = 'no value, "y" has default:true';
            self::$lastOut = $html;
            if (!preg_match('/value="y"[^>]*>/', $html, $m)) {
                return 'option "y" input not found';
            }
            return str_contains($m[0], "checked='checked'") ? true : 'default option "y" not checked';
        });
        self::run('validate required empty', fn() => self::expectError('', array_merge($cfg, ['required'=>true]), $h));
        // RadioField has no server-side option-list validation — any value passes
        self::run('validate any value passes', fn() => self::expectOk('x', $cfg, $h));
        self::run('render other_max_length chars → maxlength attr', function () use ($h, $cfg) {
            $c = array_merge($cfg, ['other_option'=>true,'other_max_type'=>'chars','other_max_length'=>'20']);
            return self::contains($h->render($c, 'f1'), 'maxlength="20"');
        });
        self::run('render other_max_length words → data-word-limit attr', function () use ($h, $cfg) {
            $c = array_merge($cfg, ['other_option'=>true,'other_max_type'=>'words','other_max_length'=>'5']);
            return self::contains($h->render($c, 'f1'), 'data-word-limit="5"');
        });
        self::run('validate __other__ text within char limit → ok', function () use ($h, $cfg) {
            $c = array_merge($cfg, ['other_option'=>true,'other_max_type'=>'chars','other_max_length'=>'20']);
            return self::expectOk(['value'=>'__other__','__other_text__'=>'short text'], $c, $h);
        });
        self::run('validate __other__ text exceeding char limit → error', function () use ($h, $cfg) {
            $c = array_merge($cfg, ['other_option'=>true,'other_max_type'=>'chars','other_max_length'=>'5']);
            return self::expectError(['value'=>'__other__','__other_text__'=>'this is way too long'], $c, $h);
        });
        self::run('validate __other__ text exceeding word limit → error', function () use ($h, $cfg) {
            $c = array_merge($cfg, ['other_option'=>true,'other_max_type'=>'words','other_max_length'=>'2']);
            return self::expectError(['value'=>'__other__','__other_text__'=>'one two three'], $c, $h);
        });
        self::run('getClientValidation includes other-text-word-limit rule', function () use ($h) {
            $rules = array_column($h->getClientValidation(), 'rule');
            return in_array('other-text-word-limit', $rules, true) ? true : 'rule missing: '.implode(',', $rules);
        });
        self::run('map known value → label', fn() => self::expectMap('x', $cfg, $h, 'X-Ray'));
        self::run('map unknown value → raw', fn() => self::expectMap('unknown', $cfg, $h, 'unknown'));
        self::run('map empty → Kein Eintrag', fn() => self::expectMapContains('', $cfg, $h, __('[No entry]', 'formfabricator')));
    }

    private static function testCheckbox(): void
    {
        self::section('checkbox');
        $opts = [['value'=>'one','label'=>'Eins','default'=>true],['value'=>'two','label'=>'Zwei','default'=>false],['value'=>'three','label'=>'Drei','default'=>false]];
        $h    = new \FabricatorForms\Fields\CheckboxField();
        $cfg  = array_merge($h->getDefaultConfig(), ['type'=>'checkbox','label'=>'Checkboxen','options'=>$opts]);

        self::run('schema integrity', fn() => self::schemaIntegrity($h));
        self::run('render basic', fn() => self::renderBasic($h, $cfg));
        self::run('render type="checkbox"', fn() => self::contains($h->render($cfg, 'f1'), 'type="checkbox"'));
        self::run('render option labels', fn() => self::contains($h->render($cfg, 'f1'), 'Eins'));
        self::run('render min/max_selections', function () use ($h, $cfg) {
            $html = $h->render(array_merge($cfg, ['min_selections'=>1,'max_selections'=>2]), 'f1');
            return self::contains($html, 'data-min-selections');
        });
        self::run('render other_option', function () use ($h, $cfg) {
            return self::contains($h->render(array_merge($cfg, ['other_option'=>true]), 'f1'), '__other__');
        });
        self::run('render layout=false → no horizontal class', function () use ($h, $cfg) {
            return !str_contains($h->render(array_merge($cfg, ['layout'=>false]), 'f1'), 'fabricator-checkbox-group--horizontal')
                ? true : 'horizontal class present when layout=false';
        });
        self::run('render default pre-checked options (no value)', function () use ($h, $cfg) {
            $html = $h->render($cfg, 'f1');
            self::$lastIn  = 'no value, "one" has default:true';
            self::$lastOut = $html;
            return str_contains($html, 'value="one" autocomplete="off" checked') ? true : 'default option "one" not checked';
        });
        self::run('validate required empty []', fn() => self::expectError([], array_merge($cfg, ['required'=>true]), $h));
        self::run('validate optional empty []', fn() => self::expectOk([], $cfg, $h));
        self::run('validate valid selection', fn() => self::expectOk(['one'], $cfg, $h));
        self::run('validate below min_selections', fn() => self::expectError(['one'], array_merge($cfg, ['min_selections'=>2]), $h));
        self::run('validate above max_selections', fn() => self::expectError(['one','two'], array_merge($cfg, ['max_selections'=>1]), $h));
        self::run('validate exact at min_selections passes', fn() => self::expectOk(['one','two'], array_merge($cfg, ['min_selections'=>2]), $h));
        self::run('validate exact at max_selections passes', fn() => self::expectOk(['one','two'], array_merge($cfg, ['max_selections'=>2]), $h));
        self::run('map known values has Eins', fn() => self::expectMapContains(['one','two'], $cfg, $h, 'Eins'));
        self::run('map known values has Zwei', fn() => self::expectMapContains(['one','two'], $cfg, $h, 'Zwei'));
        self::run('map __other__ → [Other]', fn() => self::expectMapContains(['__other__'], $cfg, $h, __('[Other]', 'formfabricator')));
        self::run('map empty → Kein Eintrag', fn() => self::expectMapContains([], $cfg, $h, __('[No entry]', 'formfabricator')));
        self::run('render other_max_length chars → maxlength attr', function () use ($h, $cfg) {
            $c = array_merge($cfg, ['other_option'=>true,'other_max_type'=>'chars','other_max_length'=>'20']);
            return self::contains($h->render($c, 'f1'), 'maxlength="20"');
        });
        self::run('render other_max_length words → data-word-limit attr', function () use ($h, $cfg) {
            $c = array_merge($cfg, ['other_option'=>true,'other_max_type'=>'words','other_max_length'=>'5']);
            return self::contains($h->render($c, 'f1'), 'data-word-limit="5"');
        });
        self::run('validate __other__ text within char limit → ok', function () use ($h, $cfg) {
            $c = array_merge($cfg, ['other_option'=>true,'other_max_type'=>'chars','other_max_length'=>'20']);
            return self::expectOk(['__other__','__other_text__'=>'short text'], $c, $h);
        });
        self::run('validate __other__ text exceeding char limit → error', function () use ($h, $cfg) {
            $c = array_merge($cfg, ['other_option'=>true,'other_max_type'=>'chars','other_max_length'=>'5']);
            return self::expectError(['__other__','__other_text__'=>'this is way too long'], $c, $h);
        });
        self::run('validate __other__ text exceeding word limit → error', function () use ($h, $cfg) {
            $c = array_merge($cfg, ['other_option'=>true,'other_max_type'=>'words','other_max_length'=>'2']);
            return self::expectError(['__other__','__other_text__'=>'one two three'], $c, $h);
        });
        self::run('getClientValidation includes other-text-word-limit rule', function () use ($h) {
            $rules = array_column($h->getClientValidation(), 'rule');
            return in_array('other-text-word-limit', $rules, true) ? true : 'rule missing: '.implode(',', $rules);
        });
    }

    private static function testUpload(): void
    {
        self::section('upload');
        $h   = new \FabricatorForms\Fields\UploadField();
        $cfg = array_merge($h->getDefaultConfig(), ['type'=>'upload','label'=>'Datei']);

        self::run('schema integrity', fn() => self::schemaIntegrity($h));
        self::run('render basic', fn() => self::renderBasic($h, $cfg));
        self::run('render multiple attr', fn() => self::contains($h->render(array_merge($cfg, ['multiple'=>true]), 'f1'), 'multiple'));
        self::run('render max_size hint', fn() => self::contains($h->render(array_merge($cfg, ['max_size_mb'=>5]), 'f1'), '5 MB'));
        self::run('render custom allowed_types', fn() => self::contains($h->render(array_merge($cfg, ['allowed_types'=>'pdf,docx']), 'f1'), 'pdf'));
        self::run('render allow_images=true includes image extensions', function () use ($h, $cfg) {
            return self::contains($h->render(array_merge($cfg, ['allow_images'=>true,'allow_documents'=>false]), 'f1'), '.jpg');
        });
        self::run('render allow_images=false excludes image extensions', function () use ($h, $cfg) {
            $html = $h->render(array_merge($cfg, ['allow_images'=>false,'allow_documents'=>false]), 'f1');
            self::$lastIn  = 'allow_images=false, allow_documents=false';
            self::$lastOut = $html;
            return !str_contains($html, '.jpg') ? true : '.jpg present despite allow_images=false';
        });
        self::run('render blocked extension excluded even when in custom allowed_types', function () use ($h, $cfg) {
            $html = $h->render(array_merge($cfg, ['allow_images'=>false,'allow_documents'=>false,'allowed_types'=>'php,pdf']), 'f1');
            self::$lastIn  = 'allowed_types=php,pdf (.php is blocked)';
            self::$lastOut = $html;
            if (str_contains($html, '.php')) {
                return '.php should be excluded from accept list (blocked type)';
            }
            return str_contains($html, '.pdf') ? true : '.pdf missing from accept list';
        });
        self::run('needsMultipartEncoding=true', fn() => $h->needsMultipartEncoding() ? true : 'expected true');
        // validate: optional with no file → true
        self::run('validate optional no file', fn() => self::expectOk(null, $cfg, $h));
        // validate: required with no file → error
        self::run('validate required no file', fn() => self::expectError(null, array_merge($cfg, ['required'=>true]), $h));
        self::run('validate required empty name', fn() => self::expectError(['name'=>'','tmp_name'=>'','error'=>0,'size'=>0,'type'=>''], array_merge($cfg, ['required'=>true]), $h));
        // validate: blocked extension regardless of required
        self::run('validate blocked ext php', fn() => self::expectError(['name'=>'evil.php','tmp_name'=>'/tmp/x','error'=>0,'size'=>100,'type'=>'text/plain'], $cfg, $h));
        self::run('validate blocked ext js', fn() => self::expectError(['name'=>'evil.js','tmp_name'=>'/tmp/x','error'=>0,'size'=>100,'type'=>'text/plain'], $cfg, $h));
        self::run('validate blocked ext exe', fn() => self::expectError(['name'=>'evil.exe','tmp_name'=>'/tmp/x','error'=>0,'size'=>100,'type'=>'application/octet-stream'], $cfg, $h));
        self::run('validate allowed ext pdf', fn() => self::expectOk(['name'=>'doc.pdf','tmp_name'=>'/tmp/x','error'=>0,'size'=>100,'type'=>'application/pdf'], $cfg, $h));
        self::run('map string value', fn() => self::expectMap('file.pdf', $cfg, $h, 'file.pdf'));
        self::run('map empty → Kein Eintrag', fn() => self::expectMapContains('', $cfg, $h, __('[No entry]', 'formfabricator')));
    }

    private static function testSignature(): void
    {
        self::section('signature');
        $h   = new \FabricatorForms\Fields\SignatureField();
        $cfg = array_merge($h->getDefaultConfig(), ['type'=>'signature','label'=>'Unterschrift','export_format'=>'png']);

        $validPng = 'data:image/png;base64,'.base64_encode("\x89PNG\r\n\x1a\n".str_repeat("\x00", 100));
        $validJpg = 'data:image/jpeg;base64,'.base64_encode("\xff\xd8\xff".str_repeat("\x00", 100));

        self::run('schema integrity', fn() => self::schemaIntegrity($h));
        self::run('render basic', fn() => self::renderBasic($h, $cfg));
        self::run('render <canvas', fn() => self::contains($h->render($cfg, 'f1'), '<canvas'));
        self::run('render data-format=png', fn() => self::contains($h->render($cfg, 'f1'), 'data-format="png"'));
        self::run('render data-format=jpeg', fn() => self::contains($h->render(array_merge($cfg, ['export_format'=>'jpeg']), 'f1'), 'data-format="jpeg"'));
        self::run('render data-required when req', fn() => self::contains($h->render(array_merge($cfg, ['required'=>true]), 'f1'), 'data-required="true"'));
        self::run('render canvas_height attr', fn() => self::contains($h->render(array_merge($cfg, ['canvas_height'=>300]), 'f1'), '300'));
        self::run('render data-stroke attr', function () use ($h, $cfg) {
            return self::contains($h->render(array_merge($cfg, ['stroke_width'=>4]), 'f1'), 'data-stroke="4"');
        });
        self::run('validate required empty', fn() => self::expectError('', array_merge($cfg, ['required'=>true]), $h));
        self::run('validate optional empty', fn() => self::expectOk('', $cfg, $h));
        self::run('validate required valid png', fn() => self::expectOk($validPng, array_merge($cfg, ['required'=>true]), $h));
        self::run('validate required jpeg format', function () use ($h, $cfg, $validJpg) {
            return self::expectOk($validJpg, array_merge($cfg, ['required'=>true,'export_format'=>'jpeg']), $h);
        });
        self::run('validate png rejected for jpeg config', function () use ($h, $cfg, $validPng) {
            return self::expectError($validPng, array_merge($cfg, ['required'=>true,'export_format'=>'jpeg']), $h);
        });
        self::run('map non-empty → empty string', fn() => self::expectMap($validPng, $cfg, $h, ''));
        self::run('map empty → Kein Eintrag', fn() => self::expectMapContains('', $cfg, $h, __('[No entry]', 'formfabricator')));
        self::run('includeValueInSeal=false', fn() => !$h->includeValueInSeal() ? true : 'expected false');
    }

    private static function testRating(): void
    {
        self::section('rating');
        $h   = new \FabricatorForms\Fields\RatingField();
        $cfg = array_merge($h->getDefaultConfig(), ['type'=>'rating','label'=>'Bewertung','max'=>5,'icon_type'=>'star']);

        self::run('schema integrity', fn() => self::schemaIntegrity($h));
        self::run('render basic', fn() => self::renderBasic($h, $cfg));
        self::run('render 5 radio inputs', function () use ($h, $cfg) {
            $count = substr_count($h->render($cfg, 'f1'), 'type="radio"');
            return $count === 5 ? true : "expected 5 radio inputs, got $count";
        });
        self::run('render max=3 → 3 radios', function () use ($h, $cfg) {
            $count = substr_count($h->render(array_merge($cfg, ['max'=>3]), 'f1'), 'type="radio"');
            return $count === 3 ? true : "expected 3, got $count";
        });
        self::run('render allow_half doubles inputs', function () use ($h, $cfg) {
            $count = substr_count($h->render(array_merge($cfg, ['allow_half'=>true]), 'f1'), 'type="radio"');
            return $count === 10 ? true : "expected 10 (5*2), got $count";
        });
        foreach (['star','heart','circle','diamond'] as $icon) {
            self::run("render icon_type=$icon", function () use ($h, $cfg, $icon) {
                return is_string($h->render(array_merge($cfg, ['icon_type'=>$icon]), 'f1')) ? true : "failed for $icon";
            });
        }
        self::run('render custom icon_source uses image url', function () use ($h, $cfg) {
            $c = array_merge($cfg, ['icon_source'=>true,'custom_icon_url'=>'https://example.com/star.png']);
            return self::contains($h->render($c, 'f1'), 'star.png');
        });
        self::run('validate required empty', fn() => self::expectError('', array_merge($cfg, ['required'=>true]), $h));
        self::run('validate optional empty', fn() => self::expectOk('', $cfg, $h));
        self::run('validate valid rating', fn() => self::expectOk('3', $cfg, $h));
        self::run('validate above max → error', fn() => self::expectError('99', $cfg, $h));
        self::run('validate negative → error', fn() => self::expectError('-1', $cfg, $h));
        self::run('validate non-numeric → error', fn() => self::expectError('abc', $cfg, $h));
        self::run('validate half-step rejected without allow_half', fn() => self::expectError('2.5', $cfg, $h));
        self::run('validate half-step accepted with allow_half', function () use ($h, $cfg) {
            return self::expectOk('2.5', array_merge($cfg, ['allow_half'=>true]), $h);
        });
        self::run('validate exactly at max → ok', fn() => self::expectOk('5', $cfg, $h));
        self::run('map value/max format', fn() => self::expectMap('3', $cfg, $h, '3 / 5'));
        self::run('map half value format', fn() => self::expectMap('2.5', $cfg, $h, '2.5 / 5'));
        self::run('map empty → Kein Eintrag', fn() => self::expectMapContains('', $cfg, $h, __('[No entry]', 'formfabricator')));
    }

    private static function testSlider(): void
    {
        self::section('slider');
        $h   = new \FabricatorForms\Fields\SliderField();
        $cfg = array_merge($h->getDefaultConfig(), ['type'=>'slider','label'=>'Slider','min'=>0,'max'=>100,'step'=>1,'ranged'=>false]);

        self::run('schema integrity', fn() => self::schemaIntegrity($h));
        self::run('render basic', fn() => self::renderBasic($h, $cfg));
        self::run('render data-min/max/step', fn() => self::contains($h->render($cfg, 'f1'), 'data-min'));
        self::run('render ranged two hidden inputs', function () use ($h, $cfg) {
            $html = $h->render(array_merge($cfg, ['ranged'=>true]), 'f1');
            $count = substr_count($html, 'type="hidden"');
            return $count === 2 ? true : "expected 2 hidden inputs, got $count";
        });
        self::run('validate required empty', fn() => self::expectError('', array_merge($cfg, ['required'=>true]), $h));
        self::run('validate optional empty', fn() => self::expectOk('', $cfg, $h));
        self::run('validate valid in range', fn() => self::expectOk('50', $cfg, $h));
        self::run('validate below min', fn() => self::expectError('-5', $cfg, $h));
        self::run('validate above max', fn() => self::expectError('150', $cfg, $h));
        self::run('validate exact at min passes', fn() => self::expectOk('0', $cfg, $h));
        self::run('validate exact at max passes', fn() => self::expectOk('100', $cfg, $h));
        self::run('validate non-numeric', fn() => self::expectError('abc', array_merge($cfg, ['required'=>true]), $h));
        self::run('validate ranged valid', fn() => self::expectOk(['from'=>'20','to'=>'80'], array_merge($cfg, ['ranged'=>true]), $h));
        self::run('validate ranged below min', fn() => self::expectError(['from'=>'-5','to'=>'50'], array_merge($cfg, ['ranged'=>true]), $h));
        self::run('validate ranged above max', fn() => self::expectError(['from'=>'50','to'=>'150'], array_merge($cfg, ['ranged'=>true]), $h));
        self::run('validate ranged exact at min/max passes', function () use ($h, $cfg) {
            return self::expectOk(['from'=>'0','to'=>'100'], array_merge($cfg, ['ranged'=>true]), $h);
        });
        self::run('map scalar value', fn() => self::expectMap('50', $cfg, $h, '50'));
        self::run('map empty → Kein Eintrag', fn() => self::expectMapContains('', $cfg, $h, __('[No entry]', 'formfabricator')));
        self::run('map ranged has from', fn() => self::expectMapContains(['from'=>'20','to'=>'80'], array_merge($cfg, ['ranged'=>true]), $h, '20'));
        self::run('map ranged has to', fn() => self::expectMapContains(['from'=>'20','to'=>'80'], array_merge($cfg, ['ranged'=>true]), $h, '80'));
    }

    private static function testCaptcha(): void
    {
        self::section('captcha');
        $h   = new \FabricatorForms\Fields\CaptchaField();
        $cfg = $h->getDefaultConfig();

        self::run('schema integrity', fn() => self::schemaIntegrity($h));
        self::run('render returns string', fn() => is_string($h->render($cfg, 'f1')) ? true : 'render failed');
        // skipValidation=false — captcha runs its own server-side verify, not skipped
        self::run('skipValidation=false', fn() => $h->skipValidation() === false ? true : 'expected false');
        // validate: empty token always errors (no secret key configured in test env)
        self::run('validate empty token → error', fn() => self::expectError('', $cfg, $h));
        // map always returns confirmation string regardless of value
        self::run('map always confirmed string', fn() => self::expectMapContains('anytoken', $cfg, $h, 'CAPTCHA'));
    }

    private static function testConsent(): void
    {
        self::section('consent');
        $h   = new \FabricatorForms\Fields\ConsentField();
        $cfg = array_merge($h->getDefaultConfig(), ['type'=>'consent','label'=>'Einwilligung','consent_text'=>'Ich stimme zu.']);

        self::run('schema integrity', fn() => self::schemaIntegrity($h));
        self::run('render basic', fn() => self::renderBasic($h, $cfg));
        self::run('render consent text', fn() => self::contains($h->render($cfg, 'f1'), 'Ich stimme zu'));
        self::run('render type="checkbox"', fn() => self::contains($h->render($cfg, 'f1'), 'type="checkbox"'));
        self::run('render checked attr for truthy value', fn() => self::contains($h->render($cfg, 'f1', '1'), 'checked'));
        self::run('render default consent_text fallback when key omitted', function () use ($h) {
            $c = $h->getDefaultConfig();
            unset($c['consent_text']);
            $c['type'] = 'consent';
            return self::contains($h->render($c, 'f1'), __('I agree.', 'formfabricator'));
        });
        self::run('validate required empty', fn() => self::expectError('', array_merge($cfg, ['required'=>true]), $h));
        self::run('validate required checked', fn() => self::expectOk('1', array_merge($cfg, ['required'=>true]), $h));
        self::run('validate optional empty', fn() => self::expectOk('', $cfg, $h));
        self::run('map checked → includes consent text', fn() => self::expectMapContains('1', $cfg, $h, 'Ich stimme zu'));
        self::run('map checked → includes timestamp (demonstrable per Art. 7(1))', function () use ($h, $cfg) {
            return self::expectMapContains('1', $cfg, $h, current_time('mysql'));
        });
        self::run('map unchecked → Not agreed', fn() => self::expectMap('', $cfg, $h, __('Not agreed', 'formfabricator')));
        self::run('map 0 → Not agreed', fn() => self::expectMap('0', $cfg, $h, __('Not agreed', 'formfabricator')));
        self::run('sanitize keeps <a> in text', function () use ($h) {
            $out = $h->sanitizeConfigValue('consent_text', '<a href="https://x.com">Link</a>');
            return str_contains($out, '<a') ? true : 'link stripped: '.$out;
        });
    }

    private static function testGdpr(): void
    {
        self::section('gdpr');
        $h   = new \FabricatorForms\Fields\GdprField();
        $cfg = array_merge($h->getDefaultConfig(), [
            'type'=>'gdpr','label'=>'DSGVO',
            'privacy_policy_url'=>'https://example.com/privacy',
            'privacy_policy_text'=>'Datenschutz',
        ]);

        self::run('schema integrity', fn() => self::schemaIntegrity($h));
        self::run('render basic', fn() => self::renderBasic($h, $cfg));
        self::run('render policy URL in link', fn() => self::contains($h->render($cfg, 'f1'), 'example.com/privacy'));
        self::run('render policy text', fn() => self::contains($h->render($cfg, 'f1'), 'Datenschutz'));
        self::run('render always required attr', fn() => self::contains($h->render($cfg, 'f1'), 'required'));
        self::run('render checked attr for truthy value', fn() => self::contains($h->render($cfg, 'f1', '1'), 'checked'));
        self::run('render default privacy_policy_url falls back to get_privacy_policy_url()', function () use ($h) {
            $c = $h->getDefaultConfig();
            unset($c['privacy_policy_url']); // key merely defaults to '' otherwise, which ?? would NOT fall through on
            $c['type']  = 'gdpr';
            $c['label'] = 'DSGVO';
            $html = $h->render($c, 'f1');
            self::$lastIn  = 'privacy_policy_url omitted (default "")';
            self::$lastOut = $html;
            return !str_contains($html, 'example.com/privacy')
                ? true : 'should not carry over the explicitly-configured URL when key is left at its default';
        });
        self::run('validate checked → ok', fn() => self::expectOk('1', $cfg, $h));
        // GDPR always errors when unchecked, regardless of required config flag
        self::run('validate unchecked required=true → error', fn() => self::expectError('', array_merge($cfg, ['required'=>true]), $h));
        self::run('validate unchecked required=false → error', fn() => self::expectError('', array_merge($cfg, ['required'=>false]), $h));
        self::run('map checked → includes policy text', fn() => self::expectMapContains('1', $cfg, $h, 'Datenschutz'));
        self::run('map checked → includes timestamp (demonstrable per Art. 7(1))', function () use ($h, $cfg) {
            return self::expectMapContains('1', $cfg, $h, current_time('mysql'));
        });
        self::run('map unchecked → Privacy notice not acknowledged', fn() => self::expectMap('', $cfg, $h, __('Privacy notice not acknowledged', 'formfabricator')));
    }

    private static function testHtml(): void
    {
        self::section('html');
        $h   = new \FabricatorForms\Fields\HtmlField();
        $cfg = array_merge($h->getDefaultConfig(), ['type'=>'html','label'=>'','html_content'=>'<p>Hello <strong>World</strong></p>']);

        self::run('schema integrity', fn() => self::schemaIntegrity($h));
        self::run('render basic', fn() => self::renderBasic($h, $cfg));
        self::run('render preserves <strong>', fn() => self::contains($h->render($cfg, 'f1'), '<strong>World</strong>'));
        self::run('hasRequired=false', fn() => !$h->hasRequired() ? true : 'expected false');
        self::run('skipValidation=true', fn() => $h->skipValidation() ? true : 'expected true');
        self::run('includeInEmailSummary=true', fn() => $h->includeInEmailSummary() ? true : 'expected true');
        self::run('rawEmailHtml=true', fn() => $h->rawEmailHtml() ? true : 'expected true');
        self::run('map strips all tags', function () use ($h, $cfg) {
            $m = $h->map('', $cfg);
            self::$lastIn  = '(html_content from config)';
            self::$lastOut = $m;
            return !str_contains($m, '<') ? true : 'tags remain: '.$m;
        });
        self::run('sanitize strips <script>', function () use ($h) {
            return !str_contains($h->sanitizeConfigValue('html_content', '<p>OK</p><script>evil()</script>'), '<script') ? true : 'script not stripped';
        });
        self::run('sanitize preserves <input>', function () use ($h) {
            return str_contains($h->sanitizeConfigValue('html_content', '<input type="text" name="x">'), '<input') ? true : 'input stripped';
        });
        self::run('sanitize preserves <canvas>', function () use ($h) {
            return str_contains($h->sanitizeConfigValue('html_content', '<canvas id="c"></canvas>'), '<canvas') ? true : 'canvas stripped';
        });
        self::run('sanitize preserves <svg>', function () use ($h) {
            return str_contains($h->sanitizeConfigValue('html_content', '<svg><circle cx="10" cy="10" r="5"/></svg>'), '<svg') ? true : 'svg stripped';
        });
        self::run('sanitize preserves <select>', function () use ($h) {
            return str_contains($h->sanitizeConfigValue('html_content', '<select><option value="a">A</option></select>'), '<select') ? true : 'select stripped';
        });
        self::run('sanitize preserves <source>', function () use ($h) {
            return str_contains($h->sanitizeConfigValue('html_content', '<source src="a.mp4" type="video/mp4">'), '<source') ? true : 'source stripped';
        });
        self::run('sanitize <use href="#frag"> kept, external href stripped', function () use ($h) {
            $out = $h->sanitizeConfigValue('html_content', '<svg><use href="#frag"></use><use href="http://evil.com/x"></use></svg>');
            self::$lastIn  = '<use href="#frag"> + <use href="http://evil.com/x">';
            self::$lastOut = $out;
            if (!str_contains($out, 'href="#frag"')) {
                return 'in-document fragment href was stripped unexpectedly';
            }
            return !str_contains($out, 'evil.com') ? true : 'external href was not stripped';
        });
        self::run('sanitize other key uses plain wp_kses_post (strips <script>)', function () use ($h) {
            return !str_contains($h->sanitizeConfigValue('label', '<p>ok</p><script>evil()</script>'), '<script')
                ? true : 'script not stripped for non-html_content key';
        });
        self::run('mapNormalized empty html_content → []', function () use ($h) {
            $c = array_merge($h->getDefaultConfig(), ['type'=>'html','label'=>'','html_content'=>'']);
            $r = $h->mapNormalized('f1', '', '', $c, []);
            self::$lastIn  = 'html_content=""';
            self::$lastOut = var_export($r, true);
            return $r === [] ? true : 'expected [], got: ' . var_export($r, true);
        });
        self::run('mapNormalized non-empty html_content → labeled entry', function () use ($h) {
            $c = array_merge($h->getDefaultConfig(), ['type'=>'html','label'=>'Block','html_content'=>'<p>Hi</p>']);
            $r = $h->mapNormalized('f1', 'Block', '', $c, []);
            self::$lastIn  = 'html_content=<p>Hi</p>';
            self::$lastOut = var_export($r, true);
            return (isset($r['f1']) && $r['f1']['label'] === 'Block' && str_contains($r['f1']['value'], 'Hi'))
                ? true : 'unexpected result: ' . var_export($r, true);
        });
        self::run('mapNormalized show_in_output omitted (legacy saved config) → still included', function () use ($h) {
            // Legacy configs lack this key entirely; mapNormalized()'s `?? true` must default to enabled.
            $c = array_merge($h->getDefaultConfig(), ['type'=>'html','label'=>'Block','html_content'=>'<p>Hi</p>']);
            unset($c['show_in_output']);
            $r = $h->mapNormalized('f1', 'Block', '', $c, []);
            self::$lastIn  = 'show_in_output key absent';
            self::$lastOut = var_export($r, true);
            return isset($r['f1']) ? true : 'expected the entry to still be included by default';
        });
        self::run('mapNormalized show_in_output=false → []', function () use ($h) {
            $c = array_merge($h->getDefaultConfig(), ['type'=>'html','label'=>'Block','html_content'=>'<p>Hi</p>','show_in_output'=>false]);
            $r = $h->mapNormalized('f1', 'Block', '', $c, []);
            self::$lastIn  = 'show_in_output=false';
            self::$lastOut = var_export($r, true);
            return $r === [] ? true : 'expected [], got: ' . var_export($r, true);
        });
    }

    private static function testGroup(): void
    {
        self::section('group');
        $h   = new \FabricatorForms\Fields\GroupField();
        $cfg = array_merge($h->getDefaultConfig(), ['type'=>'group','label'=>'Gruppe','children'=>[]]);

        self::run('schema integrity', fn() => self::schemaIntegrity($h));
        self::run('isGroupContainer=true', fn() => $h->isGroupContainer() ? true : 'expected true');
        self::run('hasRequired=false', fn() => !$h->hasRequired() ? true : 'expected false');
        self::run('includeInEmailSummary=false', fn() => !$h->includeInEmailSummary() ? true : 'expected false');
        self::run('render returns string', fn() => is_string($h->render($cfg, 'f1')) ? true : 'render failed');
        self::run('render opens fabricator-field-group', fn() => self::contains($h->render($cfg, 'f1'), 'fabricator-field-group'));
        self::run('map always empty string', fn() => self::expectMap(null, $cfg, $h, ''));
        self::run('mapNormalized empty → []', function () use ($h, $cfg) {
            $r = $h->mapNormalized('f1', 'Group', [], $cfg, []);
            return $r === [] ? true : 'expected [], got: '.var_export($r, true);
        });
        self::run('mapNormalized populated single copy', function () use ($h) {
            $children = [['id'=>'child_text','type'=>'text','label'=>'Kind']];
            $c        = array_merge($h->getDefaultConfig(), ['type'=>'group','label'=>'Gruppe','children'=>$children]);
            $value    = [0 => ['child_text' => 'Hallo']];
            $r        = $h->mapNormalized('grp1', 'Gruppe', $value, $c, []);
            self::$lastIn  = json_encode($value);
            self::$lastOut = json_encode($r);
            if (!isset($r['child_text'])) {
                return 'expected key "child_text" (single copy, no suffix), got: ' . var_export($r, true);
            }
            return $r['child_text']['value'] === 'Hallo' ? true : 'unexpected value: ' . var_export($r['child_text'], true);
        });
        self::run('mapNormalized populated multiple copies suffixes keys', function () use ($h) {
            $children = [['id'=>'child_text','type'=>'text','label'=>'Kind']];
            $c        = array_merge($h->getDefaultConfig(), ['type'=>'group','label'=>'Gruppe','children'=>$children]);
            $value    = [0 => ['child_text' => 'Erste'], 1 => ['child_text' => 'Zweite']];
            $r        = $h->mapNormalized('grp1', 'Gruppe', $value, $c, []);
            self::$lastIn  = json_encode($value);
            self::$lastOut = json_encode($r);
            if (!isset($r['child_text_copy_0']) || !isset($r['child_text_copy_1'])) {
                return 'expected suffixed keys child_text_copy_0/1, got: ' . var_export($r, true);
            }
            return ($r['child_text_copy_0']['value'] === 'Erste' && $r['child_text_copy_1']['value'] === 'Zweite')
                ? true : 'unexpected values: ' . var_export($r, true);
        });
    }

    private static function testPageBreak(): void
    {
        self::section('pagebreak');
        $h   = new \FabricatorForms\Fields\PageBreakField();
        $cfg = array_merge($h->getDefaultConfig(), ['type'=>'pagebreak','label'=>'','prev_btn'=>'Zurück','next_btn'=>'Weiter']);

        self::run('schema integrity', fn() => self::schemaIntegrity($h));
        self::run('isPageBreak=true', fn() => $h->isPageBreak() ? true : 'expected true');
        self::run('hasSettingsPanel=false', fn() => !$h->hasSettingsPanel() ? true : 'expected false');
        self::run('hasRequired=false', fn() => !$h->hasRequired() ? true : 'expected false');
        self::run('skipValidation=true', fn() => $h->skipValidation() ? true : 'expected true');
        self::run('includeInEmailSummary=false', fn() => !$h->includeInEmailSummary() ? true : 'expected false');
        self::run('render returns empty string', fn() => $h->render($cfg, 'f1') === '' ? true : 'expected empty string');
        self::run('map returns empty string', fn() => self::expectMap(null, $cfg, $h, ''));
        self::run('mapNormalized returns []', function () use ($h, $cfg) {
            $r = $h->mapNormalized('f1', '', null, $cfg, []);
            return $r === [] ? true : 'expected []';
        });
        self::run('renderBreak page 2 has nav', function () use ($h, $cfg) {
            $html = $h->renderBreak($cfg, 2);
            return str_contains($html, 'fabricator-btn-next') && str_contains($html, 'fabricator-btn-prev') ? true : 'nav missing';
        });
        // page 1: bottom nav uses <span></span> instead of prev button
        self::run('renderBreak page 1 bottom has span', function () use ($h, $cfg) {
            $html = $h->renderBreak($cfg, 1);
            return str_contains($html, '<span></span>') ? true : 'expected <span></span> on page 1 bottom nav';
        });
        self::run('renderBreak custom prev/next labels appear', function () use ($h, $cfg) {
            $html = $h->renderBreak($cfg, 2);
            self::$lastIn  = 'prev_btn=Zurück, next_btn=Weiter';
            self::$lastOut = $html;
            return (str_contains($html, 'Zurück') && str_contains($html, 'Weiter'))
                ? true : 'custom button labels missing from renderBreak output';
        });
    }

    private static function testPageHeader(): void
    {
        self::section('page-header');
        $h   = new \FabricatorForms\Fields\PageHeaderField();
        $cfg = array_merge($h->getDefaultConfig(), ['type'=>'page-header','label'=>'']);

        self::run('schema integrity', fn() => self::schemaIntegrity($h));
        self::run('isPageBreak=false', fn() => !$h->isPageBreak() ? true : 'expected false');
        self::run('hasRequired=false', fn() => !$h->hasRequired() ? true : 'expected false');
        self::run('skipValidation=true', fn() => $h->skipValidation() ? true : 'expected true');
        self::run('includeInEmailSummary=false', fn() => !$h->includeInEmailSummary() ? true : 'expected false');
        self::run('map returns empty string', fn() => self::expectMap(null, $cfg, $h, ''));
        self::run('mapNormalized returns []', function () use ($h, $cfg) {
            $r = $h->mapNormalized('f1', '', null, $cfg, []);
            return $r === [] ? true : 'expected []';
        });
        self::run('render contains container class', function () use ($h, $cfg) {
            $html = $h->render($cfg, 'f1');
            self::$lastIn  = json_encode($cfg);
            self::$lastOut = $html;
            return str_contains($html, 'fabricator-page-header') ? true : 'container class missing';
        });
        self::run('render show_names=false → data-show-names="0"', function () use ($h, $cfg) {
            $html = $h->render(array_merge($cfg, ['show_names'=>false]), 'f1');
            return str_contains($html, 'data-show-names="0"') ? true : 'expected data-show-names="0"';
        });
        self::run('render show_names=true → data-show-names="1"', function () use ($h, $cfg) {
            $html = $h->render(array_merge($cfg, ['show_names'=>true]), 'f1');
            return str_contains($html, 'data-show-names="1"') ? true : 'expected data-show-names="1"';
        });
        self::run('render page_names serialized only when show_names=true', function () use ($h, $cfg) {
            $c    = array_merge($cfg, ['show_names'=>true,'page_names'=>['Kontakt','Adresse']]);
            $html = $h->render($c, 'f1');
            self::$lastIn  = json_encode($c);
            self::$lastOut = $html;
            return str_contains($html, 'Kontakt') && str_contains($html, 'Adresse')
                ? true : 'page names missing from data-names';
        });
        self::run('render page_names omitted when show_names=false', function () use ($h, $cfg) {
            $c    = array_merge($cfg, ['show_names'=>false,'page_names'=>['Kontakt','Adresse']]);
            $html = $h->render($c, 'f1');
            return !str_contains($html, 'Kontakt') ? true : 'page names leaked while show_names=false';
        });
        self::run('render escapes page name HTML', function () use ($h, $cfg) {
            $c    = array_merge($cfg, ['show_names'=>true,'page_names'=>['<script>alert(1)</script>']]);
            $html = $h->render($c, 'f1');
            self::$lastIn  = json_encode($c);
            self::$lastOut = $html;
            return !str_contains($html, '<script>') ? true : 'unescaped script tag in output';
        });
        self::run('getClientInit returns non-empty script', fn() => trim($h->getClientInit()) !== '' ? true : 'expected non-empty JS');
    }

    private static function testPostData(): void
    {
        self::section('postdata');
        $h   = new \FabricatorForms\Fields\PostDataField();
        $cfg = array_merge($h->getDefaultConfig(), ['type'=>'postdata','label'=>'Beitragsinfo','post_field'=>['post_title']]);

        self::run('schema integrity', fn() => self::schemaIntegrity($h));
        self::run('render returns string', fn() => is_string($h->render($cfg, 'f1')) ? true : 'render failed');
        self::run('hasRequired=false', fn() => !$h->hasRequired() ? true : 'expected false');
        self::run('render produces hidden input with correct name', function () use ($h, $cfg) {
            $html = $h->render($cfg, 'f1');
            self::$lastIn  = 'post_field=[post_title]';
            self::$lastOut = $html;
            return str_contains($html, '<input type="hidden" name="f1[post_title]"')
                ? true : 'expected hidden input name f1[post_title], got: ' . $html;
        });
        self::run('map array → imploded values', fn() => self::expectMapContains(['post_title'=>'My Page','post_id'=>'42'], $cfg, $h, 'My Page'));
        self::run('map excludes fields not selected in post_field', function () use ($h, $cfg) {
            $value = ['post_title'=>'My Page','post_id'=>'42','post_url'=>'https://example.com','post_author'=>'Admin'];
            $r     = $h->map($value, $cfg);
            self::$lastIn  = json_encode($value);
            self::$lastOut = $r;
            if (str_contains($r, '42') || str_contains($r, 'example.com') || str_contains($r, 'Admin')) {
                return 'unselected post_field values leaked into output: ' . $r;
            }
            return str_contains($r, 'My Page') ? true : 'expected "My Page" in output';
        });
        self::run('map empty array → Kein Eintrag', fn() => self::expectMapContains([], $cfg, $h, __('[No entry]', 'formfabricator')));
        self::run('map non-array → Kein Eintrag', fn() => self::expectMapContains('', $cfg, $h, __('[No entry]', 'formfabricator')));
    }

    private static function testWebsite(): void
    {
        self::section('website');
        $h   = new \FabricatorForms\Fields\WebsiteField();
        $cfg = array_merge($h->getDefaultConfig(), ['type'=>'website','label'=>'Webseite']);

        self::run('schema integrity', fn() => self::schemaIntegrity($h));
        self::run('render basic', fn() => self::renderBasic($h, $cfg));
        self::run('render type="url"', fn() => self::contains($h->render($cfg, 'f1'), 'type="url"'));
        self::run('render validate_url data-attr', fn() => self::contains($h->render(array_merge($cfg, ['validate_url'=>true]), 'f1'), 'data-validate-url'));
        self::run('validate required empty', fn() => self::expectError('', array_merge($cfg, ['required'=>true]), $h));
        self::run('validate optional empty', fn() => self::expectOk('', $cfg, $h));
        self::run('validate valid URL', fn() => self::expectOk('https://example.com', $cfg, $h));
        self::run('validate invalid URL when flag', fn() => self::expectError('not a url', array_merge($cfg, ['validate_url'=>true]), $h));
        // validate_url=false: invalid URLs pass (no format check)
        self::run('validate invalid URL no flag', fn() => self::expectOk('not a url', array_merge($cfg, ['validate_url'=>false]), $h));
        self::run('map value', fn() => self::expectMap('https://example.com', $cfg, $h, 'https://example.com'));
        self::run('map empty → Kein Eintrag', fn() => self::expectMapContains('', $cfg, $h, __('[No entry]', 'formfabricator')));
    }

    private static function testSepa(): void
    {
        self::section('sepa');
        $h   = new \FabricatorForms\Fields\SepaField();
        $cfg = array_merge($h->getDefaultConfig(), [
            'type'=>'sepa','label'=>'SEPA-Mandat',
            'mandate_title'=>'SEPA Lastschriftmandat',
            'creditor_id'=>'DE98ZZZ09999999999','mandate_ref'=>'MANDAT-001',
        ]);

        // A minimal but well-formed data: URI — validate() only checks the prefix,
        // not that it decodes to a real image, so this is enough to satisfy the
        // "signature present" check without needing an actual PNG payload.
        $dummySig  = 'data:image/png;base64,iVBORw0KGgo=';
        $validData = ['iban'=>'DE89370400440532013000','bic'=>'COBADEFFXXX','holder'=>'Max Mustermann','sig'=>$dummySig];

        self::run('schema integrity', fn() => self::schemaIntegrity($h));
        self::run('render basic', fn() => self::renderBasic($h, $cfg));
        self::run('render mandate title', fn() => self::contains($h->render($cfg, 'f1'), 'SEPA Lastschriftmandat'));
        self::run('render IBAN input class', fn() => self::contains($h->render($cfg, 'f1'), 'fabricator-sepa-iban'));
        self::run('render BIC input class', fn() => self::contains($h->render($cfg, 'f1'), 'fabricator-sepa-bic'));
        self::run('render holder input class', fn() => self::contains($h->render($cfg, 'f1'), 'fabricator-sepa-holder'));
        self::run('render <canvas for sig', fn() => self::contains($h->render($cfg, 'f1'), '<canvas'));
        self::run('render creditor_id in output', fn() => self::contains($h->render($cfg, 'f1'), 'DE98ZZZ09999999999'));
        self::run('render country_filter → data attr present', function () use ($h, $cfg) {
            $c = array_merge($cfg, ['country_filter_mode'=>'allow','country_filter_list'=>['DE','AT']]);
            return self::contains($h->render($c, 'f1'), 'data-country-filter');
        });
        self::run('render country_filter → list attr present', function () use ($h, $cfg) {
            $c = array_merge($cfg, ['country_filter_mode'=>'allow','country_filter_list'=>['DE','AT']]);
            return self::contains($h->render($c, 'f1'), 'data-country-list');
        });
        self::run('render country_filter off → no attr', function () use ($h, $cfg) {
            $html = $h->render(array_merge($cfg, ['country_filter_mode'=>'off']), 'f1');
            self::$lastIn  = 'country_filter_mode=off';
            self::$lastOut = str_contains($html, 'data-country-filter') ? 'attr present' : 'attr absent';
            return !str_contains($html, 'data-country-filter') ? true : 'attr should be absent when mode=off';
        });
        // Server-side country filter validation
        self::run('validate allow-list blocks foreign IBAN', function () use ($h, $cfg, $dummySig) {
            $c = array_merge($cfg, ['required'=>true,'country_filter_mode'=>'allow','country_filter_list'=>['DE']]);
            return self::expectError(['iban'=>'AT611904300234573201','bic'=>'COBADEFFXXX','holder'=>'Max','sig'=>$dummySig], $c, $h);
        });
        self::run('validate allow-list passes matching IBAN', function () use ($h, $cfg, $dummySig) {
            $c = array_merge($cfg, ['required'=>true,'country_filter_mode'=>'allow','country_filter_list'=>['DE']]);
            return self::expectOk(['iban'=>'DE89370400440532013000','bic'=>'COBADEFFXXX','holder'=>'Max','sig'=>$dummySig], $c, $h);
        });
        self::run('validate disallow-list blocks listed IBAN', function () use ($h, $cfg, $dummySig) {
            $c = array_merge($cfg, ['required'=>true,'country_filter_mode'=>'disallow','country_filter_list'=>['AT']]);
            return self::expectError(['iban'=>'AT611904300234573201','bic'=>'COBADEFFXXX','holder'=>'Max','sig'=>$dummySig], $c, $h);
        });
        self::run('validate disallow-list passes unlisted IBAN', function () use ($h, $cfg, $dummySig) {
            $c = array_merge($cfg, ['required'=>true,'country_filter_mode'=>'disallow','country_filter_list'=>['AT']]);
            return self::expectOk(['iban'=>'DE89370400440532013000','bic'=>'COBADEFFXXX','holder'=>'Max','sig'=>$dummySig], $c, $h);
        });
        self::run('validate country filter off passes any country', function () use ($h, $cfg, $dummySig) {
            $c = array_merge($cfg, ['required'=>true,'country_filter_mode'=>'off','country_filter_list'=>['DE']]);
            return self::expectOk(['iban'=>'AT611904300234573201','bic'=>'COBADEFFXXX','holder'=>'Max','sig'=>$dummySig], $c, $h);
        });
        // optional=false skips all validation immediately
        self::run('validate optional → true', fn() => self::expectOk([], array_merge($cfg, ['required'=>false]), $h));
        self::run('validate req non-array → error', fn() => self::expectError(null, array_merge($cfg, ['required'=>true]), $h));
        self::run('validate req empty IBAN → error', fn() => self::expectError(['iban'=>'','bic'=>'COBADEFFXXX','holder'=>'Max','sig'=>$dummySig], array_merge($cfg, ['required'=>true]), $h));
        self::run('validate req invalid IBAN', fn() => self::expectError(['iban'=>'INVALID','bic'=>'COBADEFFXXX','holder'=>'Max','sig'=>$dummySig], array_merge($cfg, ['required'=>true]), $h));
        self::run('validate req empty BIC → error', fn() => self::expectError(['iban'=>'DE89370400440532013000','bic'=>'','holder'=>'Max','sig'=>$dummySig], array_merge($cfg, ['required'=>true]), $h));
        // 'TOO' is only 3 chars — fails [A-Z]{6} minimum
        self::run('validate req invalid BIC', fn() => self::expectError(['iban'=>'DE89370400440532013000','bic'=>'TOO','holder'=>'Max','sig'=>$dummySig], array_merge($cfg, ['required'=>true]), $h));
        self::run('validate req empty holder', fn() => self::expectError(['iban'=>'DE89370400440532013000','bic'=>'COBADEFFXXX','holder'=>'','sig'=>$dummySig], array_merge($cfg, ['required'=>true]), $h));
        self::run('validate req all valid → true', fn() => self::expectOk($validData, array_merge($cfg, ['required'=>true]), $h));
        self::run('validate lowercase BIC passes (case-insensitive regex)', function () use ($h, $cfg, $dummySig) {
            return self::expectOk(['iban'=>'DE89370400440532013000','bic'=>'cobadeffxxx','holder'=>'Max','sig'=>$dummySig], array_merge($cfg, ['required'=>true]), $h);
        });
        self::run('validate lowercase country_filter_list entry matches (strtoupper normalized)', function () use ($h, $cfg, $dummySig) {
            $c = array_merge($cfg, ['required'=>true,'country_filter_mode'=>'allow','country_filter_list'=>['de']]);
            return self::expectOk(['iban'=>'DE89370400440532013000','bic'=>'COBADEFFXXX','holder'=>'Max','sig'=>$dummySig], $c, $h);
        });
        self::run('validate req empty signature → error', fn() => self::expectError(['iban'=>'DE89370400440532013000','bic'=>'COBADEFFXXX','holder'=>'Max','sig'=>''], array_merge($cfg, ['required'=>true]), $h));
        self::run('validate req non-image signature → error', fn() => self::expectError(['iban'=>'DE89370400440532013000','bic'=>'COBADEFFXXX','holder'=>'Max','sig'=>'not-a-data-uri'], array_merge($cfg, ['required'=>true]), $h));
        self::run('map non-array → No entry', fn() => str_contains($h->map(null, $cfg), __('[No entry]', 'formfabricator')) ? true : 'wrong map');
        self::run('map valid data contains IBAN', fn() => self::expectMapContains($validData, $cfg, $h, 'IBAN'));
        self::run('map valid data contains BIC', fn() => self::expectMapContains($validData, $cfg, $h, 'BIC'));
        self::run('map valid data contains holder', fn() => self::expectMapContains($validData, $cfg, $h, 'Mustermann'));
    }

    private static function testRegistryCoverage(): void
    {
        self::section('FieldRegistry coverage');
        $registry    = \FabricatorForms\Fields\FieldRegistry::all();
        $testedTypes = [
            'text', 'textarea', 'email', 'name', 'phone', 'number', 'address',
            'date', 'time', 'currency', 'select', 'radio', 'checkbox', 'upload',
            'signature', 'rating', 'slider', 'captcha', 'consent', 'gdpr', 'html',
            'group', 'pagebreak', 'page-header', 'postdata', 'website', 'sepa',
        ];

        self::run('all registered types are tested', function () use ($registry, $testedTypes) {
            $missing = array_diff(array_keys($registry), $testedTypes);
            return empty($missing) ? true : 'untested: '.implode(', ', $missing);
        });
        self::run('no tested type is unregistered', function () use ($registry, $testedTypes) {
            $unknown = array_diff($testedTypes, array_keys($registry));
            return empty($unknown) ? true : 'not registered: '.implode(', ', $unknown);
        });
    }

    // ── JS test helpers ──────────────────────────────────────────────────────

    /**
     * Generates the same window.FabricatorValidators / FabricatorEmptyChecks / FabricatorFieldInits /
     * FabricatorSkipValidation globals that Assets::enqueueFront() emits, so the JS test
     * harness has the real field implementations available on the admin test page.
     */
    private static function generateFrontGlobals(): string
    {
        $emptyChecks = [];
        $pairs = [];
        $seenRules = [];
        $inits = [];
        $skip = [];

        foreach (\FabricatorForms\Fields\FieldRegistry::all() as $type => $class) {
            $handler = new $class();

            $entry = $handler->getClientEmptyCheck();
            if (!empty($entry['fn'])) {
                $emptyChecks[] = json_encode($type) . ':' . trim($entry['fn']);
            }

            foreach ($handler->getClientValidation() as $vEntry) {
                $rule = $vEntry['rule'] ?? '';
                $fn = $vEntry['fn'] ?? '';
                if ($rule !== '' && $fn !== '' && !isset($seenRules[$rule])) {
                    $seenRules[$rule] = true;
                    $pairs[] = json_encode($rule) . ':' . trim($fn);
                }
            }

            $fn = $handler->getClientInit();
            if ($fn !== '') {
                $inits[] = json_encode($type) . ':' . trim($fn);
            }

            if ($handler->skipValidation()) {
                $skip[] = json_encode($type);
            }
        }

        $js = "window.__FABRICATOR_TEST__=true;\n";
        $js .= "window.FabricatorForms={ajaxUrl:''};\n";
        $js .= 'window.FabricatorValidators=' . (!empty($pairs)
            ? '{' . implode(',', $pairs) . '}'
            : '{}') . ";\n";
        $js .= 'window.FabricatorEmptyChecks=' . (!empty($emptyChecks)
            ? '{' . implode(',', $emptyChecks) . '}'
            : '{}') . ";\n";
        $js .= 'window.FabricatorFieldInits=' . (!empty($inits)
            ? '{' . implode(',', $inits) . '}'
            : '{}') . ";\n";
        $js .= 'window.FabricatorSkipValidation=' . (!empty($skip)
            ? '[' . implode(',', $skip) . ']'
            : '[]') . ";\n";

        return $js;
    }

    private static function renderJsTests(): void
    {
        echo '<div id="fabricator-js-tests" style="color:#555;font-style:italic;">Running JS tests…</div>';

        // Dependency chain (globals -> front.js -> harness) enforces execution order via WP's
        // own script-dependency system, rather than relying on raw echo/output order.
        wp_register_script('fabricator-fieldtest-globals', false, [], FABRICATOR_FORMS_VERSION, true);
        wp_enqueue_script('fabricator-fieldtest-globals');
        wp_add_inline_script('fabricator-fieldtest-globals', self::generateFrontGlobals());

        wp_enqueue_script(
            'fabricator-fieldtest-front',
            FABRICATOR_FORMS_URL . 'assets/js/front.js',
            ['fabricator-fieldtest-globals'],
            FABRICATOR_FORMS_VERSION,
            true
        );
        wp_enqueue_script(
            'fabricator-fieldtest-harness',
            FABRICATOR_FORMS_URL . 'assets/js/admin-fieldtest.js',
            ['fabricator-fieldtest-front'],
            FABRICATOR_FORMS_VERSION,
            true
        );
    }

    // ── page output ──────────────────────────────────────────────────────────

    /**
     * Runs every PHP test suite, then outputs the tabbed PHP/JS results page.
     * The JS panel embeds the same field-handler validators/inits used on the
     * live front end (see generateFrontGlobals()) and runs its own suite client-side.
     *
     * @return void
     */
    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized', '', ['response' => 403]);
        }

        // reset
        self::$pass           = 0;
        self::$fail           = 0;
        self::$log            = [];
        self::$failLines      = [];
        self::$currentSection = '';
        self::$lastIn         = '';
        self::$lastOut        = '';

        // run all suites
        self::testText();
        self::testTextarea();
        self::testEmail();
        self::testName();
        self::testPhone();
        self::testNumber();
        self::testAddress();
        self::testDate();
        self::testTime();
        self::testCurrency();
        self::testSelect();
        self::testRadio();
        self::testCheckbox();
        self::testUpload();
        self::testSignature();
        self::testRating();
        self::testSlider();
        self::testCaptcha();
        self::testConsent();
        self::testGdpr();
        self::testHtml();
        self::testGroup();
        self::testPageBreak();
        self::testPageHeader();
        self::testPostData();
        self::testWebsite();
        self::testSepa();
        self::testRegistryCoverage();

        $total    = self::$pass + self::$fail;
        $allOk    = self::$fail === 0;


        echo '<div class="wrap fabricator-list-wrap">';
        echo '<hr class="wp-header-end" style="display:none">';

        $phpBadge = '<span class="fabricator-tab-badge ' . ($allOk ? 'fabricator-tab-badge--pass' : 'fabricator-tab-badge--fail') . '">'
            . ($allOk
                ? '<i class="fa-solid fa-check" aria-hidden="true"></i> ' . $total
                : '<i class="fa-solid fa-xmark" aria-hidden="true"></i> ' . self::$fail . '/' . $total) . '</span>';

        echo '<div class="fabricator-test-topbar">';
        echo '<h1 class="fabricator-test-title">Field Tests</h1>';
        echo '<div class="fabricator-test-tabs">';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $phpBadge is built from a boolean and internal integer counts only, no user input.
        echo '<div class="fabricator-test-tab active" data-panel="fabricator-panel-php">PHP' . $phpBadge . '</div>';
        echo '<div class="fabricator-test-tab" data-panel="fabricator-panel-js">JS <span id="fabricator-js-tab-badge" class="fabricator-tab-badge fabricator-tab-badge--loading">…</span></div>';
        echo '</div>';
        echo '</div>';

        // PHP panel
        echo '<div id="fabricator-panel-php" class="fabricator-test-panel active">';
        if (!$allOk) {
            $copyText = esc_js(implode("\n", self::$failLines));
            echo '<div style="margin-bottom:10px;">';
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $copyText is already esc_js()'d above; this is a JS string literal context, not HTML.
            echo '<button onclick="navigator.clipboard.writeText(\'' . $copyText . '\')'
                . '.then(function(){this.textContent=\'Copied!\';}.bind(this))"'
                . ' style="cursor:pointer;padding:6px 14px;font-size:13px;">'
                . '<i class="fa-regular fa-clipboard" aria-hidden="true"></i> Copy failures</button>';
            echo '</div>';
        }
        echo '<table id="fabricator-php-tests" class="fabricator-test-table">';
        echo '<colgroup>'
            . '<col style="width:24px">'
            . '<col style="width:26%">'
            . '<col style="width:20%">'
            . '<col style="width:28%">'
            . '<col>'
            . '</colgroup>';
        echo '<thead><tr>'
            . '<th></th>'
            . '<th>Test</th>'
            . '<th>Input</th>'
            . '<th>Output</th>'
            . '<th>Note</th>'
            . '</tr></thead>';
        echo '<tbody>';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each self::$log entry already escapes its dynamic parts with esc_html() when appended.
        echo implode('', self::$log);
        echo '</tbody></table>';
        echo '</div>';

        // JS panel
        echo '<div id="fabricator-panel-js" class="fabricator-test-panel">';
        self::renderJsTests();
        echo '</div>';

        wp_enqueue_script(
            'fabricator-fieldtest-tabs',
            FABRICATOR_FORMS_URL . 'assets/js/admin-fieldtest-tabs.js',
            [],
            FABRICATOR_FORMS_VERSION,
            true
        );

        echo '</div>';
    }
}
// phpcs:enable WordPress.PHP.DevelopmentFunctions.error_log_var_export
