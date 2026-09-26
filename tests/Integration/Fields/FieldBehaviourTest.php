<?php

namespace FabricatorForms\Tests\Integration\Fields;

use FabricatorForms\Tests\Integration\TestCase;

// phpcs:disable Generic.Files.LineLength -- ported verbatim from the former WP_DEBUG field test page; see class docblock.

/**
 * Every field type's schema, render, validate, map and sanitize behaviour, in real WordPress.
 *
 * Ported from the former WP_DEBUG admin page (includes/Admin/FieldTestPage.php) without changing a check: each
 * $this->check() closure returns true or a failure message, exactly as it did there. A test method runs all of its
 * checks and then fails once, listing every check that failed — the page showed them all side by side, and stopping
 * at the first would hide the rest.
 */
final class FieldBehaviourTest extends TestCase
{
    /** Side-channel the helpers fill for a failure message: the input a check used and what it got back. */
    private static string $lastIn  = '';
    private static string $lastOut = '';

    private string $currentSection = '';

    /** @var string[] */
    private array $failures = [];

    private function section(string $label): void
    {
        $this->currentSection = $label;
    }

    /**
     * Runs one check: $fn returns true, or a string (or other value) describing the failure.
     */
    private function check(string $name, callable $fn): void
    {
        self::$lastIn  = '';
        self::$lastOut = '';
        try {
            $result = $fn();
        } catch (\Throwable $e) {
            $result = get_class($e) . ': ' . $e->getMessage();
        }
        $this->addToAssertionCount(1);
        if ($result !== true) {
            $this->failures[] = '[' . $this->currentSection . '] ' . $name . ' — '
                . (is_string($result) ? $result : var_export($result, true))
                . (self::$lastIn !== '' ? ' (input: ' . self::$lastIn . ', output: ' . self::$lastOut . ')' : '');
        }
    }

    /**
     * Runs $fn with the site's number format set to these separators, then restores it.
     */
    private static function withNumberFormat(string $decimal, string $thousands, callable $fn): mixed
    {
        global $wp_locale;
        $saved                     = $wp_locale->number_format;
        $wp_locale->number_format = ['decimal_point' => $decimal, 'thousands_sep' => $thousands];
        try {
            return $fn();
        } finally {
            $wp_locale->number_format = $saved;
        }
    }

    protected function assertPostConditions(): void
    {
        parent::assertPostConditions();
        self::assertSame([], $this->failures, count($this->failures) . ' check(s) failed');
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
        self::$lastIn  = is_array($value) ? wp_json_encode($value, JSON_UNESCAPED_UNICODE) : (string)$value;
        self::$lastOut = is_string($r) ? $r : var_export($r, true);
        return (is_string($r) && $r !== '') ? true : 'expected error string, got ' . var_export($r, true);
    }

    private static function expectOk(mixed $value, array $config, \FabricatorForms\Fields\BaseField $h): bool|string
    {
        $r = $h->validate($value, $config);
        self::$lastIn  = is_array($value) ? wp_json_encode($value, JSON_UNESCAPED_UNICODE) : (string)$value;
        self::$lastOut = $r === true ? 'valid' : var_export($r, true);
        return $r === true ? true : 'expected true, got ' . var_export($r, true);
    }

    private static function expectMap(mixed $value, array $config, \FabricatorForms\Fields\BaseField $h, string $expected): bool|string
    {
        $r = $h->map($value, $config);
        self::$lastIn  = is_array($value) ? wp_json_encode($value, JSON_UNESCAPED_UNICODE) : var_export($value, true);
        self::$lastOut = $r;
        return $r === $expected ? true : 'expected ' . var_export($expected, true) . ', got ' . var_export($r, true);
    }

    /**
     * Asserts an explicit UTC consent timestamp (GDPR Art. 7(1)); matches pattern+date, not current_time('mysql') (site-local, and second-boundary flaky).
     *
     * @param mixed  $value  Submitted value.
     * @param array  $config Field configuration.
     * @param object $h      Field handler under test.
     * @return bool|string True, or a failure description.
     */
    private static function expectUtcStamp(mixed $value, array $config, \FabricatorForms\Fields\BaseField $h): bool|string
    {
        $r = $h->map($value, $config);
        self::$lastIn  = var_export($value, true);
        self::$lastOut = $r;
        if (!preg_match('/(\d{4}-\d{2}-\d{2}) \d{2}:\d{2}:\d{2} UTC/', $r, $m)) {
            return 'no explicit UTC timestamp in: ' . $r;
        }
        // Tolerate a run that straddles UTC midnight.
        if (!in_array($m[1], [gmdate('Y-m-d'), gmdate('Y-m-d', time() - 60)], true)) {
            return 'timestamp date ' . $m[1] . ' is not today (UTC): ' . $r;
        }
        return true;
    }

    private static function expectMapContains(mixed $value, array $config, \FabricatorForms\Fields\BaseField $h, string ...$needles): bool|string
    {
        $r = $h->map($value, $config);
        self::$lastIn  = is_array($value) ? wp_json_encode($value, JSON_UNESCAPED_UNICODE) : var_export($value, true);
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

    public function testText(): void
    {
        $this->section('text');
        $h   = new \FabricatorForms\Fields\TextField();
        $cfg = array_merge($h->getDefaultConfig(), ['type'=>'text','label'=>'Name']);

        $this->check('schema integrity', fn() => self::schemaIntegrity($h));
        $this->check('render basic', fn() => self::renderBasic($h, $cfg));
        $this->check('render <input', fn() => self::contains($h->render($cfg, 'f1'), '<input'));
        $this->check('render required attr', fn() => self::contains($h->render(array_merge($cfg, ['required'=>true]), 'f1'), 'required'));
        $this->check('render hide_label', function () use ($h, $cfg) {
            return !str_contains($h->render(array_merge($cfg, ['hide_label'=>true]), 'f1'), '<label') ? true : 'label still present';
        });
        $this->check('render placeholder', fn() => self::contains($h->render(array_merge($cfg, ['placeholder'=>'Enter']), 'f1'), 'Enter'));
        $this->check('render description', fn() => self::contains($h->render(array_merge($cfg, ['description'=>'Help']), 'f1'), 'Help'));
        $this->check('render char limit → maxlength', function () use ($h, $cfg) {
            return self::contains($h->render(array_merge($cfg, ['limit_type'=>'chars','limit_max'=>'10']), 'f1'), 'maxlength');
        });
        $this->check('render word limit → data-word-limit', function () use ($h, $cfg) {
            return self::contains($h->render(array_merge($cfg, ['limit_type'=>'words','limit_max'=>'5']), 'f1'), 'data-word-limit');
        });
        $this->check('validate required empty', fn() => self::expectError('', array_merge($cfg, ['required'=>true]), $h));
        $this->check('validate optional empty', fn() => self::expectOk('', $cfg, $h));
        $this->check('validate with value', fn() => self::expectOk('Hello', $cfg, $h));
        $this->check('validate word limit exceeded', function () use ($h, $cfg) {
            return self::expectError('one two three four five six', array_merge($cfg, ['limit_type'=>'words','limit_max'=>'3']), $h);
        });
        $this->check('validate word limit within', function () use ($h, $cfg) {
            return self::expectOk('one two three', array_merge($cfg, ['limit_type'=>'words','limit_max'=>'3']), $h);
        });
        $this->check('validate word limit exact at boundary (count===max)', function () use ($h, $cfg) {
            return self::expectOk('alpha beta gamma delta', array_merge($cfg, ['limit_type'=>'words','limit_max'=>'4']), $h);
        });
        $this->check('validate char limit exceeded (server-side)', function () use ($h, $cfg) {
            return self::expectError('this value is far too long', array_merge($cfg, ['limit_type'=>'chars','limit_max'=>'5']), $h);
        });
        $this->check('validate char limit within', function () use ($h, $cfg) {
            return self::expectOk('short', array_merge($cfg, ['limit_type'=>'chars','limit_max'=>'10']), $h);
        });
        $this->check('validate char limit exact at boundary (len===max)', function () use ($h, $cfg) {
            return self::expectOk('abcde', array_merge($cfg, ['limit_type'=>'chars','limit_max'=>'5']), $h);
        });
        $this->check('map non-empty', fn() => self::expectMap('Hello', $cfg, $h, 'Hello'));
        $this->check('map empty → Kein Eintrag', fn() => self::expectMapContains('', $cfg, $h, __('[No entry]', 'formfabricator')));
        $this->check('sanitize strips <script>', function () use ($h) {
            return !str_contains($h->sanitizeConfigValue('label', '<script>x</script>'), '<script') ? true : 'script not stripped';
        });
    }

    public function testTextarea(): void
    {
        $this->section('textarea');
        $h   = new \FabricatorForms\Fields\TextareaField();
        $cfg = array_merge($h->getDefaultConfig(), ['type'=>'textarea','label'=>'Nachricht']);

        $this->check('schema integrity', fn() => self::schemaIntegrity($h));
        $this->check('render basic', fn() => self::renderBasic($h, $cfg));
        $this->check('render <textarea', fn() => self::contains($h->render($cfg, 'f1'), '<textarea'));
        $this->check('render default rows=5 (no override)', fn() => self::contains($h->render($cfg, 'f1'), 'rows="5"'));
        $this->check('render rows attr', fn() => self::contains($h->render(array_merge($cfg, ['rows'=>6]), 'f1'), 'rows="6"'));
        $this->check('render char limit → maxlength', fn() => self::contains($h->render(array_merge($cfg, ['limit_type'=>'chars','limit_max'=>'200']), 'f1'), 'maxlength'));
        $this->check('render word limit → data-word-limit', function () use ($h, $cfg) {
            return self::contains($h->render(array_merge($cfg, ['limit_type'=>'words','limit_max'=>'10']), 'f1'), 'data-word-limit');
        });
        $this->check('validate required empty', fn() => self::expectError('', array_merge($cfg, ['required'=>true]), $h));
        $this->check('validate optional empty', fn() => self::expectOk('', $cfg, $h));
        $this->check('validate multiline', fn() => self::expectOk("line1\nline2", $cfg, $h));
        $this->check('validate word limit exceeded', function () use ($h, $cfg) {
            return self::expectError('a b c d e', array_merge($cfg, ['limit_type'=>'words','limit_max'=>'3']), $h);
        });
        $this->check('validate word limit within', function () use ($h, $cfg) {
            return self::expectOk('a b c', array_merge($cfg, ['limit_type'=>'words','limit_max'=>'3']), $h);
        });
        $this->check('map non-empty', fn() => self::expectMap('Hello', $cfg, $h, 'Hello'));
        $this->check('map empty → Kein Eintrag', fn() => self::expectMapContains('', $cfg, $h, __('[No entry]', 'formfabricator')));
        $this->check('sanitize strips <script> (inherited from BaseField)', function () use ($h) {
            return !str_contains($h->sanitizeConfigValue('description', '<p>ok</p><script>x</script>'), '<script') ? true : 'script not stripped';
        });
    }

    public function testEmail(): void
    {
        $this->section('email');
        $h   = new \FabricatorForms\Fields\EmailField();
        $cfg = array_merge($h->getDefaultConfig(), ['type'=>'email','label'=>'E-Mail']);

        $this->check('schema integrity', fn() => self::schemaIntegrity($h));
        $this->check('render basic', fn() => self::renderBasic($h, $cfg));
        $this->check('render type="email"', fn() => self::contains($h->render($cfg, 'f1'), 'type="email"'));
        $this->check('validate required empty', fn() => self::expectError('', array_merge($cfg, ['required'=>true]), $h));
        $this->check('validate optional empty', fn() => self::expectOk('', $cfg, $h));
        $this->check('validate valid email', fn() => self::expectOk('user@example.com', $cfg, $h));
        $this->check('validate invalid format', function () use ($h, $cfg) {
            return self::expectError('notanemail', array_merge($cfg, ['validate_format'=>true]), $h);
        });
        $this->check('validate blocked domain', function () use ($h, $cfg) {
            // patterns match the full address; use wildcard *@domain
            $c = array_merge($cfg, ['filter_mode'=>'block','filter_patterns'=>'*@spam.com']);
            return self::expectError('user@spam.com', $c, $h);
        });
        $this->check('validate allowed domain pass', function () use ($h, $cfg) {
            $c = array_merge($cfg, ['filter_mode'=>'allow','filter_patterns'=>'*@example.com']);
            return self::expectOk('user@example.com', $c, $h);
        });
        $this->check('validate allowed domain fail', function () use ($h, $cfg) {
            $c = array_merge($cfg, ['filter_mode'=>'allow','filter_patterns'=>'*@example.com']);
            return self::expectError('user@other.com', $c, $h);
        });
        $this->check('validate_format=false allows malformed string', function () use ($h, $cfg) {
            return self::expectOk('notanemail', array_merge($cfg, ['validate_format'=>false]), $h);
        });
        $this->check('validate multi-pattern allow list (; separated)', function () use ($h, $cfg) {
            $c = array_merge($cfg, ['filter_mode'=>'allow','filter_patterns'=>'*@example.com;*@test.com']);
            return self::expectOk('user@test.com', $c, $h);
        });
    }

    public function testName(): void
    {
        $this->section('name');
        $h   = new \FabricatorForms\Fields\NameField();
        $cfg = array_merge($h->getDefaultConfig(), ['type'=>'name','label'=>'Name','expanded'=>false]);
        $exp = array_merge($cfg, [
            'expanded'=>true,'fname_enabled'=>true,'fname_label'=>'Vorname','fname_required'=>true,
            'lname_enabled'=>true,'lname_label'=>'Nachname','lname_required'=>true,
            'mname_enabled'=>false,'prefix_enabled'=>false,
        ]);

        $this->check('schema integrity', fn() => self::schemaIntegrity($h));
        $this->check('render simple <input>', fn() => self::renderBasic($h, $cfg));
        $this->check('render expanded subfields', fn() => self::contains($h->render($exp, 'f1'), 'Vorname'));
        $this->check('render expanded prefix select', function () use ($h, $cfg) {
            $c = array_merge($cfg, ['expanded'=>true,'prefix_enabled'=>true,'prefix_label'=>'Anrede','fname_enabled'=>false,'lname_enabled'=>false,'mname_enabled'=>false]);
            return self::contains($h->render($c, 'f1'), '<select');
        });
        $this->check('validate required empty simple', fn() => self::expectError('', array_merge($cfg, ['required'=>true]), $h));
        $this->check('validate optional empty simple', fn() => self::expectOk('', $cfg, $h));
        $this->check('validate filled simple', fn() => self::expectOk('Max Mustermann', $cfg, $h));
        $this->check('validate required empty expanded', fn() => self::expectError(['fname'=>'','lname'=>''], $exp, $h));
        $this->check('validate required filled expanded', fn() => self::expectOk(['fname'=>'Hans','lname'=>'Müller'], $exp, $h));
        $this->check('validate partial expanded → error', function () use ($h, $exp) {
            return self::expectError(['fname'=>'Hans','lname'=>''], $exp, $h);
        });
        $this->check('map simple string', fn() => self::expectMap('Max Mustermann', $cfg, $h, 'Max Mustermann'));
        $this->check('map simple empty → Kein Eintrag', fn() => self::expectMapContains('', $cfg, $h, __('[No entry]', 'formfabricator')));
        $this->check('map expanded has Vorname', fn() => self::expectMapContains(['fname'=>'Hans','lname'=>'Müller'], $exp, $h, 'Hans'));
        $this->check('map expanded has Nachname', fn() => self::expectMapContains(['fname'=>'Hans','lname'=>'Müller'], $exp, $h, 'Müller'));
        $this->check('map expanded empty → Kein Eintrag', fn() => self::expectMapContains(['fname'=>'','lname'=>''], $exp, $h, __('[No entry]', 'formfabricator')));
        $this->check('render middle name subfield', function () use ($h, $exp) {
            $c = array_merge($exp, ['mname_enabled'=>true,'mname_label'=>'Zweiter Vorname']);
            return self::contains($h->render($c, 'f1'), 'Zweiter Vorname');
        });
        $this->check('validate middle name filled passes', function () use ($h, $exp) {
            $c = array_merge($exp, ['mname_enabled'=>true,'mname_required'=>true]);
            return self::expectOk(['fname'=>'Hans','lname'=>'Müller','mname'=>'Peter'], $c, $h);
        });
        $this->check('map includes middle name', function () use ($h, $exp) {
            $c = array_merge($exp, ['mname_enabled'=>true]);
            return self::expectMapContains(['fname'=>'Hans','lname'=>'Müller','mname'=>'Peter'], $c, $h, 'Peter');
        });
        $this->check('validate prefix_required=true is skipped (select subfield always has a value)', function () use ($h, $cfg) {
            $c = array_merge($cfg, ['expanded'=>true,'prefix_enabled'=>true,'prefix_required'=>true,'fname_enabled'=>false,'lname_enabled'=>false,'mname_enabled'=>false]);
            return self::expectOk([], $c, $h);
        });
        // Salutations post a stable key, so validation doesn't depend on the locale the request runs in.
        $prefix_only = array_merge($cfg, ['expanded'=>true,'prefix_enabled'=>true,'fname_enabled'=>false,'lname_enabled'=>false,'mname_enabled'=>false]);
        $this->check('validate prefix key accepted', fn() => self::expectOk(['prefix'=>'ms'], $prefix_only, $h));
        $this->check('validate unknown prefix rejected', fn() => self::expectError(['prefix'=>'Sir'], $prefix_only, $h));
        $this->check('map prefix key shows its label', fn() => self::expectMapContains(['prefix'=>'mr'], $prefix_only, $h, __('Mr.', 'formfabricator')));
    }

    public function testPhone(): void
    {
        $this->section('phone');
        $h   = new \FabricatorForms\Fields\PhoneField();
        $cfg = array_merge($h->getDefaultConfig(), ['type'=>'phone','label'=>'Telefon']);

        $this->check('schema integrity', fn() => self::schemaIntegrity($h));
        $this->check('render basic', fn() => self::renderBasic($h, $cfg));
        $this->check('render type="tel"', fn() => self::contains($h->render($cfg, 'f1'), 'type="tel"'));
        $this->check('render phone_mode data-attr', function () use ($h, $cfg) {
            return self::contains($h->render(array_merge($cfg, ['phone_mode'=>'any']), 'f1'), 'data-phone-mode');
        });
        $this->check('render countries data attrs', function () use ($h, $cfg) {
            $c = array_merge($cfg, ['phone_mode'=>'countries','phone_country_mode'=>'allow','phone_country_list'=>['+49','+43']]);
            return self::contains($h->render($c, 'f1'), 'data-phone-country');
        });
        $this->check('validate required empty', fn() => self::expectError('', array_merge($cfg, ['required'=>true]), $h));
        $this->check('validate optional empty', fn() => self::expectOk('', $cfg, $h));
        $this->check('validate no-mode any value passes', fn() => self::expectOk('+4915123456789', $cfg, $h));
        $this->check('validate mode=any valid', fn() => self::expectOk('+4915123456789', array_merge($cfg, ['phone_mode'=>'any']), $h));
        $this->check('validate mode=any invalid', function () use ($h, $cfg) {
            return self::expectError('123', array_merge($cfg, ['phone_mode'=>'any']), $h);
        });
        $this->check('validate mode=countries missing +', function () use ($h, $cfg) {
            $c = array_merge($cfg, ['phone_mode'=>'countries','phone_country_mode'=>'allow','phone_country_list'=>['+49']]);
            return self::expectError('04915123456789', $c, $h);
        });
        $this->check('validate mode=countries allowed pass', function () use ($h, $cfg) {
            $c = array_merge($cfg, ['phone_mode'=>'countries','phone_country_mode'=>'allow','phone_country_list'=>['+49']]);
            return self::expectOk('+4915123456789', $c, $h);
        });
        $this->check('validate mode=countries allowed fail', function () use ($h, $cfg) {
            $c = array_merge($cfg, ['phone_mode'=>'countries','phone_country_mode'=>'allow','phone_country_list'=>['+44']]);
            return self::expectError('+4915123456789', $c, $h);
        });
        $this->check('validate mode=countries disallow match', function () use ($h, $cfg) {
            $c = array_merge($cfg, ['phone_mode'=>'countries','phone_country_mode'=>'disallow','phone_country_list'=>['+49']]);
            return self::expectError('+4915123456789', $c, $h);
        });
        $this->check('validate mode=countries disallow non-matching passes', function () use ($h, $cfg) {
            $c = array_merge($cfg, ['phone_mode'=>'countries','phone_country_mode'=>'disallow','phone_country_list'=>['+49']]);
            return self::expectOk('+33123456789', $c, $h);
        });
        $this->check('validate overlapping country prefix codes both match', function () use ($h, $cfg) {
            // '+3' is a prefix of '+358' — matching should still succeed for a +358 number
            $c = array_merge($cfg, ['phone_mode'=>'countries','phone_country_mode'=>'allow','phone_country_list'=>['+3','+358']]);
            return self::expectOk('+358123456789', $c, $h);
        });
        $this->check('map non-empty', fn() => self::expectMap('+4915123456789', $cfg, $h, '+4915123456789'));
        $this->check('map empty → Kein Eintrag', fn() => self::expectMapContains('', $cfg, $h, __('[No entry]', 'formfabricator')));
    }

    public function testNumber(): void
    {
        $this->section('number');
        $h   = new \FabricatorForms\Fields\NumberField();
        $cfg = array_merge($h->getDefaultConfig(), ['type'=>'number','label'=>'Anzahl']);

        $this->check('schema integrity', fn() => self::schemaIntegrity($h));
        $this->check('render basic', fn() => self::renderBasic($h, $cfg));
        $this->check('render type="number"', fn() => self::contains($h->render($cfg, 'f1'), 'type="number"'));
        $this->check('render min/max/step', function () use ($h, $cfg) {
            $html = $h->render(array_merge($cfg, ['min'=>'1','max'=>'100','step'=>'5']), 'f1');
            return str_contains($html, 'min') ? true : 'min attr missing';
        });
        $this->check('validate required empty', fn() => self::expectError('', array_merge($cfg, ['required'=>true]), $h));
        $this->check('validate optional empty', fn() => self::expectOk('', $cfg, $h));
        $this->check('validate valid number', fn() => self::expectOk('42', $cfg, $h));
        $this->check('validate non-numeric', function () use ($h, $cfg) {
            return self::expectError('abc', array_merge($cfg, ['required'=>true]), $h);
        });
        $this->check('validate below min', function () use ($h, $cfg) {
            return self::expectError('5', array_merge($cfg, ['min'=>'10']), $h);
        });
        $this->check('validate above max', function () use ($h, $cfg) {
            return self::expectError('150', array_merge($cfg, ['max'=>'100']), $h);
        });
        $this->check('validate exact at min passes', fn() => self::expectOk('10', array_merge($cfg, ['min'=>'10']), $h));
        $this->check('validate exact at max passes', fn() => self::expectOk('100', array_merge($cfg, ['max'=>'100']), $h));
    }

    public function testAddress(): void
    {
        $this->section('address');
        $h   = new \FabricatorForms\Fields\AddressField();
        $cfg = array_merge($h->getDefaultConfig(), ['type'=>'address','label'=>'Adresse','expanded'=>false]);
        $exp = array_merge($cfg, [
            'expanded'=>true,
            'street_enabled'=>true,'street_label'=>'Straße','street_required'=>true,
            'city_enabled'=>true,'city_label'=>'Stadt','city_required'=>true,
            'zip_enabled'=>true,'zip_label'=>'PLZ','zip_required'=>true,
            'street2_enabled'=>false,'state_enabled'=>false,'country_enabled'=>false,
        ]);

        $this->check('schema integrity', fn() => self::schemaIntegrity($h));
        $this->check('render simple <input>', fn() => self::renderBasic($h, $cfg));
        $this->check('render expanded subfields', fn() => self::contains($h->render($exp, 'f1'), 'Straße'));
        $this->check('validate required empty simple', fn() => self::expectError('', array_merge($cfg, ['required'=>true]), $h));
        $this->check('validate optional empty simple', fn() => self::expectOk('', $cfg, $h));
        $this->check('validate filled simple', fn() => self::expectOk('Hauptstr. 1, Berlin', $cfg, $h));
        $this->check('validate required fields empty exp', fn() => self::expectError(['street'=>'','city'=>'','zip'=>''], $exp, $h));
        $this->check('validate required fields filled exp', fn() => self::expectOk(['street'=>'Hauptstr. 1','city'=>'Berlin','zip'=>'10115'], $exp, $h));
        $this->check('validate partial required exp err', function () use ($h, $exp) {
            return self::expectError(['street'=>'Hauptstr. 1','city'=>'','zip'=>'10115'], $exp, $h);
        });
        $this->check('map simple keeps the typed address', fn() => self::expectMapContains('Hauptstr. 1, Berlin', $cfg, $h, 'Hauptstr. 1, Berlin'));
        $this->check('map empty → Kein Eintrag', fn() => self::expectMapContains('', $cfg, $h, __('[No entry]', 'formfabricator')));
        $this->check('map array has Straße', fn() => self::expectMapContains(['street'=>'Hauptstr. 1','city'=>'Berlin','zip'=>'10115'], $exp, $h, 'Hauptstr'));
        $this->check('map array has Stadt', fn() => self::expectMapContains(['street'=>'Hauptstr. 1','city'=>'Berlin','zip'=>'10115'], $exp, $h, 'Berlin'));
        $expFull = array_merge($exp, [
            'street2_enabled'=>true,'street2_label'=>'Adresszusatz',
            'state_enabled'=>true,'state_label'=>'Bundesland',
            'country_enabled'=>true,'country_label'=>'Land',
        ]);
        $this->check('render street2/state/country subfields', function () use ($h, $expFull) {
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
        $this->check('map includes street2/state/country', function () use ($h, $expFull) {
            return self::expectMapContains(
                ['street'=>'Hauptstr. 1','street2'=>'3.OG','city'=>'Berlin','zip'=>'10115','state'=>'Bayern','country'=>'Deutschland'],
                $expFull,
                $h,
                '3.OG',
                'Bayern',
                'Deutschland'
            );
        });
        $this->check('validate multiple required fields empty simultaneously', function () use ($h, $exp) {
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

    public function testDate(): void
    {
        $this->section('date');
        $h   = new \FabricatorForms\Fields\DateField();
        // Pinned: the default date_format follows this site's date setting, and the cases below are written as DD.MM.YYYY.
        $cfg = array_merge($h->getDefaultConfig(), ['type'=>'date','label'=>'Datum','date_format'=>'dmy']);

        $this->check('schema integrity', fn() => self::schemaIntegrity($h));
        $this->check('render basic', fn() => self::renderBasic($h, $cfg));
        $this->check('render show_picker=true → cal btn', function () use ($h, $cfg) {
            return self::contains($h->render(array_merge($cfg, ['show_picker'=>true]), 'f1'), 'fabricator-date-cal-btn');
        });
        $this->check('render prefill_today attr', function () use ($h, $cfg) {
            return self::contains($h->render(array_merge($cfg, ['prefill_today'=>true]), 'f1'), 'data-prefill-today');
        });
        $this->check('render show_picker=false → no cal btn', function () use ($h, $cfg) {
            return !str_contains($h->render(array_merge($cfg, ['show_picker'=>false]), 'f1'), 'fabricator-date-cal-btn')
                ? true : 'calendar button present when show_picker=false';
        });
        $this->check('validate required empty', fn() => self::expectError('', array_merge($cfg, ['required'=>true]), $h));
        $this->check('validate optional empty', fn() => self::expectOk('', $cfg, $h));
        $this->check('validate valid DD.MM.YYYY', fn() => self::expectOk('10.07.2026', $cfg, $h));
        $this->check('validate wrong format (ISO)', fn() => self::expectError('2026-07-10', array_merge($cfg, ['required'=>true]), $h));
        $this->check('validate wrong format (text)', fn() => self::expectError('not-a-date', array_merge($cfg, ['required'=>true]), $h));
        $this->check('validate invalid calendar date', fn() => self::expectError('31.02.2026', $cfg, $h));
        $this->check('validate MM/DD/YYYY format', fn() => self::expectOk('07/10/2026', array_merge($cfg, ['date_format'=>'mdy']), $h));
        $this->check('validate YYYY-MM-DD format', fn() => self::expectOk('2026-07-10', array_merge($cfg, ['date_format'=>'ymd']), $h));
        $this->check('validate DD.MM.YYYY rejected in YYYY-MM-DD field', fn() => self::expectError('10.07.2026', array_merge($cfg, ['date_format'=>'ymd']), $h));
        $this->check('validate config without date_format keeps DD.MM.YYYY', function () use ($h, $cfg) {
            $legacy = $cfg;
            unset($legacy['date_format']);
            return self::expectOk('10.07.2026', $legacy, $h);
        });
        $this->check('map non-empty returns value', fn() => self::expectMap('10.07.2026', $cfg, $h, '10.07.2026'));
        $this->check('map empty → Kein Eintrag', fn() => self::expectMapContains('', $cfg, $h, __('[No entry]', 'formfabricator')));
        $this->check('validate before min_date → error', function () use ($h, $cfg) {
            return self::expectError('10.07.2020', array_merge($cfg, ['min_date'=>'01.01.2025','max_date'=>'31.12.2025']), $h);
        });
        $this->check('validate after max_date → error', function () use ($h, $cfg) {
            return self::expectError('10.07.2030', array_merge($cfg, ['min_date'=>'01.01.2025','max_date'=>'31.12.2025']), $h);
        });
        $this->check('validate within min_date/max_date range → ok', function () use ($h, $cfg) {
            return self::expectOk('15.06.2025', array_merge($cfg, ['min_date'=>'01.01.2025','max_date'=>'31.12.2025']), $h);
        });
        $this->check('validate exactly at min_date → ok', function () use ($h, $cfg) {
            return self::expectOk('01.01.2025', array_merge($cfg, ['min_date'=>'01.01.2025']), $h);
        });
        $this->check('validate exactly at max_date → ok', function () use ($h, $cfg) {
            return self::expectOk('31.12.2025', array_merge($cfg, ['max_date'=>'31.12.2025']), $h);
        });
    }

    public function testTime(): void
    {
        $this->section('time');
        $h   = new \FabricatorForms\Fields\TimeField();
        $cfg = array_merge($h->getDefaultConfig(), ['type'=>'time','label'=>'Uhrzeit']);

        $this->check('schema integrity', fn() => self::schemaIntegrity($h));
        $this->check('render basic', fn() => self::renderBasic($h, $cfg));
        $this->check('render type="time"', fn() => self::contains($h->render($cfg, 'f1'), 'type="time"'));
        $this->check('render 12h data-attr', function () use ($h, $cfg) {
            return self::contains($h->render(array_merge($cfg, ['time_format'=>true]), 'f1'), 'data-time-format');
        });
        $this->check('render prefill_now attr', function () use ($h, $cfg) {
            return self::contains($h->render(array_merge($cfg, ['prefill_now'=>true]), 'f1'), 'data-prefill-now');
        });
        $this->check('render default has no data-time-format attr', function () use ($h, $cfg) {
            return !str_contains($h->render($cfg, 'f1'), 'data-time-format') ? true : 'attr present by default';
        });
        $this->check('render default has no data-prefill-now attr', function () use ($h, $cfg) {
            return !str_contains($h->render($cfg, 'f1'), 'data-prefill-now') ? true : 'attr present by default';
        });
        $this->check('validate required empty', fn() => self::expectError('', array_merge($cfg, ['required'=>true]), $h));
        $this->check('validate optional empty', fn() => self::expectOk('', $cfg, $h));
        // TimeField DOES validate format: a direct POST can bypass the <input type="time"> constraint, and an unchecked value flows into the email and sealed PDF.
        $this->check('validate HH:MM', fn() => self::expectOk('14:30', $cfg, $h));
        $this->check('validate HH:MM:SS', fn() => self::expectOk('14:30:45', $cfg, $h));
        $this->check('validate rejects non-time', fn() => self::expectError('not-a-time', $cfg, $h));
        $this->check('validate rejects out-of-range hour', fn() => self::expectError('24:00', $cfg, $h));
        $this->check('validate rejects out-of-range minute', fn() => self::expectError('12:60', $cfg, $h));
        $this->check('map non-empty', fn() => self::expectMap('14:30', $cfg, $h, '14:30'));
        $this->check('map empty → Kein Eintrag', fn() => self::expectMapContains('', $cfg, $h, __('[No entry]', 'formfabricator')));
    }

    public function testCurrency(): void
    {
        $this->section('currency');
        $h   = new \FabricatorForms\Fields\CurrencyField();
        $cfg = array_merge($h->getDefaultConfig(), ['type'=>'currency','label'=>'Betrag','currency'=>'EUR']);

        $this->check('schema integrity', fn() => self::schemaIntegrity($h));
        $this->check('render basic', fn() => self::renderBasic($h, $cfg));
        $this->check('render EUR symbol', fn() => self::contains($h->render($cfg, 'f1'), '€'));
        $this->check('render USD symbol', fn() => self::contains($h->render(array_merge($cfg, ['currency'=>'USD']), 'f1'), '$'));
        $this->check('render GBP symbol', fn() => self::contains($h->render(array_merge($cfg, ['currency'=>'GBP']), 'f1'), '£'));
        $this->check('render CHF symbol', fn() => self::contains($h->render(array_merge($cfg, ['currency'=>'CHF']), 'f1'), 'Fr.'));
        $this->check('render JPY symbol', fn() => self::contains($h->render(array_merge($cfg, ['currency'=>'JPY']), 'f1'), '¥'));
        $this->check('render CAD symbol', fn() => self::contains($h->render(array_merge($cfg, ['currency'=>'CAD']), 'f1'), 'CA$'));
        $this->check('render min/max attrs', function () use ($h, $cfg) {
            $html = $h->render(array_merge($cfg, ['min_value'=>'5','max_value'=>'1000']), 'f1');
            return str_contains($html, 'min') && str_contains($html, 'max') ? true : 'attrs missing';
        });
        $this->check('validate required empty', fn() => self::expectError('', array_merge($cfg, ['required'=>true]), $h));
        $this->check('validate optional empty', fn() => self::expectOk('', $cfg, $h));
        $this->check('validate valid amount', fn() => self::expectOk('12.50', $cfg, $h));
        $this->check('validate non-numeric', fn() => self::expectError('abc', array_merge($cfg, ['required'=>true]), $h));
        $this->check('validate below min_value', fn() => self::expectError('5', array_merge($cfg, ['min_value'=>'10']), $h));
        $this->check('validate above max_value', fn() => self::expectError('200', array_merge($cfg, ['max_value'=>'100']), $h));
        $this->check('validate exact at min_value passes', fn() => self::expectOk('10', array_merge($cfg, ['min_value'=>'10']), $h));
        $this->check('validate exact at max_value passes', fn() => self::expectOk('100', array_merge($cfg, ['max_value'=>'100']), $h));
        $this->check('map has numeric value', fn() => self::expectMapContains('12.5', $cfg, $h, '12'));
        $this->check('map has currency symbol', fn() => self::expectMapContains('12.5', $cfg, $h, '€'));
        // The separators follow the site's locale (CurrencyField::map()). The WP_DEBUG page asserted a comma because it
        // ran on a German site; the locale is set explicitly here instead.
        $this->check('map uses the locale\'s separators: de_DE', fn() => self::withNumberFormat(',', '.', fn() => self::expectMap('1234.5', $cfg, $h, '1.234,50 €')));
        $this->check('map uses the locale\'s separators: en_US', fn() => self::withNumberFormat('.', ',', fn() => self::expectMap('1234.5', $cfg, $h, '1,234.50 €')));
        $this->check('map non-numeric string passes through unformatted', fn() => self::expectMap('N/A', $cfg, $h, 'N/A €'));
        $this->check('map empty → Kein Eintrag', fn() => self::expectMapContains('', $cfg, $h, __('[No entry]', 'formfabricator')));
    }

    public function testSelect(): void
    {
        $this->section('select');
        $opts = [['value'=>'a','label'=>'Alpha','default'=>false],['value'=>'b','label'=>'Beta','default'=>true]];
        $h    = new \FabricatorForms\Fields\SelectField();
        $cfg  = array_merge($h->getDefaultConfig(), ['type'=>'select','label'=>'Auswahl','options'=>$opts]);

        $this->check('schema integrity', fn() => self::schemaIntegrity($h));
        $this->check('render basic', fn() => self::renderBasic($h, $cfg));
        $this->check('render <select', fn() => self::contains($h->render($cfg, 'f1'), '<select'));
        $this->check('render option labels', fn() => self::contains($h->render($cfg, 'f1'), 'Alpha'));
        $this->check('render default selected', fn() => self::contains($h->render($cfg, 'f1'), 'selected'));
        $this->check('render explicit value overrides default option', function () use ($h, $cfg) {
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
        $this->check('render other_option', function () use ($h, $cfg) {
            $html = $h->render(array_merge($cfg, ['other_option'=>true]), 'f1');
            return self::contains($html, '__other__');
        });
        $this->check('validate required empty', fn() => self::expectError('', array_merge($cfg, ['required'=>true]), $h));
        $this->check('validate optional empty', fn() => self::expectOk('', $cfg, $h));
        // SelectField::validate() checks the value against the configured options.
        $this->check('validate listed option passes', fn() => self::expectOk('a', $cfg, $h));
        $this->check('validate unlisted option rejected', fn() => self::expectError('zzz', $cfg, $h));
        $this->check('render other_max_length chars → maxlength attr', function () use ($h, $cfg) {
            $c = array_merge($cfg, ['other_option'=>true,'other_max_type'=>'chars','other_max_length'=>'20']);
            return self::contains($h->render($c, 'f1'), 'maxlength="20"');
        });
        $this->check('render other_max_length words → data-word-limit attr', function () use ($h, $cfg) {
            $c = array_merge($cfg, ['other_option'=>true,'other_max_type'=>'words','other_max_length'=>'5']);
            return self::contains($h->render($c, 'f1'), 'data-word-limit="5"');
        });
        $this->check('validate __other__ text within char limit → ok', function () use ($h, $cfg) {
            $c = array_merge($cfg, ['other_option'=>true,'other_max_type'=>'chars','other_max_length'=>'20']);
            return self::expectOk(['value'=>'__other__','__other_text__'=>'short text'], $c, $h);
        });
        $this->check('validate __other__ text exceeding char limit → error', function () use ($h, $cfg) {
            $c = array_merge($cfg, ['other_option'=>true,'other_max_type'=>'chars','other_max_length'=>'5']);
            return self::expectError(['value'=>'__other__','__other_text__'=>'this is way too long'], $c, $h);
        });
        $this->check('validate __other__ text exceeding word limit → error', function () use ($h, $cfg) {
            $c = array_merge($cfg, ['other_option'=>true,'other_max_type'=>'words','other_max_length'=>'2']);
            return self::expectError(['value'=>'__other__','__other_text__'=>'one two three'], $c, $h);
        });
        $this->check('map known value → label', fn() => self::expectMap('a', $cfg, $h, 'Alpha'));
        $this->check('map __other__ → [Other]', fn() => self::expectMapContains('__other__', $cfg, $h, __('[Other]', 'formfabricator')));
        $this->check('map unknown value → raw', fn() => self::expectMap('unknown', $cfg, $h, 'unknown'));
        $this->check('map empty → Kein Eintrag', fn() => self::expectMapContains('', $cfg, $h, __('[No entry]', 'formfabricator')));
    }

    public function testRadio(): void
    {
        $this->section('radio');
        $opts = [['value'=>'x','label'=>'X-Ray','default'=>false],['value'=>'y','label'=>'Yankee','default'=>false]];
        $h    = new \FabricatorForms\Fields\RadioField();
        $cfg  = array_merge($h->getDefaultConfig(), ['type'=>'radio','label'=>'Radio','options'=>$opts]);

        $this->check('schema integrity', fn() => self::schemaIntegrity($h));
        $this->check('render basic', fn() => self::renderBasic($h, $cfg));
        $this->check('render type="radio"', fn() => self::contains($h->render($cfg, 'f1'), 'type="radio"'));
        $this->check('render option labels', fn() => self::contains($h->render($cfg, 'f1'), 'X-Ray'));
        // RadioField render() has no other_option PHP output; the JS init wires __other__ client-side
        $this->check('render other_option does not crash', function () use ($h, $cfg) {
            return is_string($h->render(array_merge($cfg, ['other_option'=>true]), 'f1')) ? true : 'render threw';
        });
        $this->check('render layout=false → no horizontal class', function () use ($h, $cfg) {
            return !str_contains($h->render(array_merge($cfg, ['layout'=>false]), 'f1'), 'fabricator-radio-group--horizontal')
                ? true : 'horizontal class present when layout=false';
        });
        $this->check('render layout=true → horizontal class', function () use ($h, $cfg) {
            return self::contains($h->render(array_merge($cfg, ['layout'=>true]), 'f1'), 'fabricator-radio-group--horizontal');
        });
        $this->check('render default-selected fallback (no value)', function () use ($h, $cfg) {
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
        $this->check('validate required empty', fn() => self::expectError('', array_merge($cfg, ['required'=>true]), $h));
        // RadioField::validate() checks the value against the configured options.
        $this->check('validate listed option passes', fn() => self::expectOk('x', $cfg, $h));
        $this->check('validate unlisted option rejected', fn() => self::expectError('zzz', $cfg, $h));
        $this->check('render other_max_length chars → maxlength attr', function () use ($h, $cfg) {
            $c = array_merge($cfg, ['other_option'=>true,'other_max_type'=>'chars','other_max_length'=>'20']);
            return self::contains($h->render($c, 'f1'), 'maxlength="20"');
        });
        $this->check('render other_max_length words → data-word-limit attr', function () use ($h, $cfg) {
            $c = array_merge($cfg, ['other_option'=>true,'other_max_type'=>'words','other_max_length'=>'5']);
            return self::contains($h->render($c, 'f1'), 'data-word-limit="5"');
        });
        $this->check('validate __other__ text within char limit → ok', function () use ($h, $cfg) {
            $c = array_merge($cfg, ['other_option'=>true,'other_max_type'=>'chars','other_max_length'=>'20']);
            return self::expectOk(['value'=>'__other__','__other_text__'=>'short text'], $c, $h);
        });
        $this->check('validate __other__ text exceeding char limit → error', function () use ($h, $cfg) {
            $c = array_merge($cfg, ['other_option'=>true,'other_max_type'=>'chars','other_max_length'=>'5']);
            return self::expectError(['value'=>'__other__','__other_text__'=>'this is way too long'], $c, $h);
        });
        $this->check('validate __other__ text exceeding word limit → error', function () use ($h, $cfg) {
            $c = array_merge($cfg, ['other_option'=>true,'other_max_type'=>'words','other_max_length'=>'2']);
            return self::expectError(['value'=>'__other__','__other_text__'=>'one two three'], $c, $h);
        });
        $this->check('getClientValidation includes other-text-word-limit rule', function () use ($h) {
            $rules = array_column($h->getClientValidation(), 'rule');
            return in_array('other-text-word-limit', $rules, true) ? true : 'rule missing: '.implode(',', $rules);
        });
        $this->check('map known value → label', fn() => self::expectMap('x', $cfg, $h, 'X-Ray'));
        $this->check('map unknown value → raw', fn() => self::expectMap('unknown', $cfg, $h, 'unknown'));
        $this->check('map empty → Kein Eintrag', fn() => self::expectMapContains('', $cfg, $h, __('[No entry]', 'formfabricator')));
    }

    public function testCheckbox(): void
    {
        $this->section('checkbox');
        $opts = [['value'=>'one','label'=>'Eins','default'=>true],['value'=>'two','label'=>'Zwei','default'=>false],['value'=>'three','label'=>'Drei','default'=>false]];
        $h    = new \FabricatorForms\Fields\CheckboxField();
        $cfg  = array_merge($h->getDefaultConfig(), ['type'=>'checkbox','label'=>'Checkboxen','options'=>$opts]);

        $this->check('schema integrity', fn() => self::schemaIntegrity($h));
        $this->check('render basic', fn() => self::renderBasic($h, $cfg));
        $this->check('render type="checkbox"', fn() => self::contains($h->render($cfg, 'f1'), 'type="checkbox"'));
        $this->check('render option labels', fn() => self::contains($h->render($cfg, 'f1'), 'Eins'));
        $this->check('render min/max_selections', function () use ($h, $cfg) {
            $html = $h->render(array_merge($cfg, ['min_selections'=>1,'max_selections'=>2]), 'f1');
            return self::contains($html, 'data-min-selections');
        });
        $this->check('render other_option', function () use ($h, $cfg) {
            return self::contains($h->render(array_merge($cfg, ['other_option'=>true]), 'f1'), '__other__');
        });
        $this->check('render layout=false → no horizontal class', function () use ($h, $cfg) {
            return !str_contains($h->render(array_merge($cfg, ['layout'=>false]), 'f1'), 'fabricator-checkbox-group--horizontal')
                ? true : 'horizontal class present when layout=false';
        });
        $this->check('render default pre-checked options (no value)', function () use ($h, $cfg) {
            $html = $h->render($cfg, 'f1');
            self::$lastIn  = 'no value, "one" has default:true';
            self::$lastOut = $html;
            return str_contains($html, 'value="one" autocomplete="off" checked') ? true : 'default option "one" not checked';
        });
        $this->check('validate required empty []', fn() => self::expectError([], array_merge($cfg, ['required'=>true]), $h));
        $this->check('validate optional empty []', fn() => self::expectOk([], $cfg, $h));
        $this->check('validate valid selection', fn() => self::expectOk(['one'], $cfg, $h));
        $this->check('validate below min_selections', fn() => self::expectError(['one'], array_merge($cfg, ['min_selections'=>2]), $h));
        $this->check('validate above max_selections', fn() => self::expectError(['one','two'], array_merge($cfg, ['max_selections'=>1]), $h));
        $this->check('validate exact at min_selections passes', fn() => self::expectOk(['one','two'], array_merge($cfg, ['min_selections'=>2]), $h));
        $this->check('validate exact at max_selections passes', fn() => self::expectOk(['one','two'], array_merge($cfg, ['max_selections'=>2]), $h));
        $this->check('map known values has Eins', fn() => self::expectMapContains(['one','two'], $cfg, $h, 'Eins'));
        $this->check('map known values has Zwei', fn() => self::expectMapContains(['one','two'], $cfg, $h, 'Zwei'));
        $this->check('map __other__ → [Other]', fn() => self::expectMapContains(['__other__'], $cfg, $h, __('[Other]', 'formfabricator')));
        $this->check('map empty → Kein Eintrag', fn() => self::expectMapContains([], $cfg, $h, __('[No entry]', 'formfabricator')));
        $this->check('render other_max_length chars → maxlength attr', function () use ($h, $cfg) {
            $c = array_merge($cfg, ['other_option'=>true,'other_max_type'=>'chars','other_max_length'=>'20']);
            return self::contains($h->render($c, 'f1'), 'maxlength="20"');
        });
        $this->check('render other_max_length words → data-word-limit attr', function () use ($h, $cfg) {
            $c = array_merge($cfg, ['other_option'=>true,'other_max_type'=>'words','other_max_length'=>'5']);
            return self::contains($h->render($c, 'f1'), 'data-word-limit="5"');
        });
        $this->check('validate __other__ text within char limit → ok', function () use ($h, $cfg) {
            $c = array_merge($cfg, ['other_option'=>true,'other_max_type'=>'chars','other_max_length'=>'20']);
            return self::expectOk(['__other__','__other_text__'=>'short text'], $c, $h);
        });
        $this->check('validate __other__ text exceeding char limit → error', function () use ($h, $cfg) {
            $c = array_merge($cfg, ['other_option'=>true,'other_max_type'=>'chars','other_max_length'=>'5']);
            return self::expectError(['__other__','__other_text__'=>'this is way too long'], $c, $h);
        });
        $this->check('validate __other__ text exceeding word limit → error', function () use ($h, $cfg) {
            $c = array_merge($cfg, ['other_option'=>true,'other_max_type'=>'words','other_max_length'=>'2']);
            return self::expectError(['__other__','__other_text__'=>'one two three'], $c, $h);
        });
        $this->check('getClientValidation includes other-text-word-limit rule', function () use ($h) {
            $rules = array_column($h->getClientValidation(), 'rule');
            return in_array('other-text-word-limit', $rules, true) ? true : 'rule missing: '.implode(',', $rules);
        });
    }

    public function testUpload(): void
    {
        $this->section('upload');
        $h   = new \FabricatorForms\Fields\UploadField();
        $cfg = array_merge($h->getDefaultConfig(), ['type'=>'upload','label'=>'Datei']);

        $this->check('schema integrity', fn() => self::schemaIntegrity($h));
        $this->check('render basic', fn() => self::renderBasic($h, $cfg));
        $this->check('render multiple attr', fn() => self::contains($h->render(array_merge($cfg, ['multiple'=>true]), 'f1'), 'multiple'));
        $this->check('render max_size hint', fn() => self::contains($h->render(array_merge($cfg, ['max_size_mb'=>5]), 'f1'), '5 MB'));
        $this->check('render custom allowed_types', fn() => self::contains($h->render(array_merge($cfg, ['allowed_types'=>'pdf,docx']), 'f1'), 'pdf'));
        $this->check('render allow_images=true includes image extensions', function () use ($h, $cfg) {
            return self::contains($h->render(array_merge($cfg, ['allow_images'=>true,'allow_documents'=>false]), 'f1'), '.jpg');
        });
        $this->check('render allow_images=false excludes image extensions', function () use ($h, $cfg) {
            $html = $h->render(array_merge($cfg, ['allow_images'=>false,'allow_documents'=>false]), 'f1');
            self::$lastIn  = 'allow_images=false, allow_documents=false';
            self::$lastOut = $html;
            return !str_contains($html, '.jpg') ? true : '.jpg present despite allow_images=false';
        });
        $this->check('render blocked extension excluded even when in custom allowed_types', function () use ($h, $cfg) {
            $html = $h->render(array_merge($cfg, ['allow_images'=>false,'allow_documents'=>false,'allowed_types'=>'php,pdf']), 'f1');
            self::$lastIn  = 'allowed_types=php,pdf (.php is blocked)';
            self::$lastOut = $html;
            if (str_contains($html, '.php')) {
                return '.php should be excluded from accept list (blocked type)';
            }
            return str_contains($html, '.pdf') ? true : '.pdf missing from accept list';
        });
        $this->check('needsMultipartEncoding=true', fn() => $h->needsMultipartEncoding() ? true : 'expected true');
        // validate: optional with no file → true
        $this->check('validate optional no file', fn() => self::expectOk(null, $cfg, $h));
        // validate: required with no file → error
        $this->check('validate required no file', fn() => self::expectError(null, array_merge($cfg, ['required'=>true]), $h));
        $this->check('validate required empty name', fn() => self::expectError(['name'=>'','tmp_name'=>'','error'=>0,'size'=>0,'type'=>''], array_merge($cfg, ['required'=>true]), $h));
        // validate: blocked extension regardless of required
        $this->check('validate blocked ext php', fn() => self::expectError(['name'=>'evil.php','tmp_name'=>'/tmp/x','error'=>0,'size'=>100,'type'=>'text/plain'], $cfg, $h));
        $this->check('validate blocked ext js', fn() => self::expectError(['name'=>'evil.js','tmp_name'=>'/tmp/x','error'=>0,'size'=>100,'type'=>'text/plain'], $cfg, $h));
        $this->check('validate blocked ext exe', fn() => self::expectError(['name'=>'evil.exe','tmp_name'=>'/tmp/x','error'=>0,'size'=>100,'type'=>'application/octet-stream'], $cfg, $h));
        // An allowed extension gets past the type checks. The stub temp path can't be read, so the file is refused by name
        // for the visitor to retry; it used to pass silently and the form went out without it.
        $this->check('validate allowed ext pdf, unreadable temp file refused by name', function () use ($h, $cfg) {
            $r = $h->validate(['name'=>'doc.pdf','tmp_name'=>'/tmp/x','error'=>0,'size'=>100,'type'=>'application/pdf'], $cfg);
            self::$lastIn  = 'doc.pdf, tmp_name=/tmp/x (unreadable)';
            self::$lastOut = is_string($r) ? $r : var_export($r, true);
            // translators: %s: uploaded file name.
            $expected = sprintf(__('"%s" could not be uploaded. Please try again.', 'formfabricator'), 'doc.pdf');
            return $r === $expected ? true : 'expected the retry message naming doc.pdf';
        });
        $this->check('validate empty temp path refused', fn() => self::expectError(['name'=>'doc.pdf','tmp_name'=>'','error'=>0,'size'=>100,'type'=>'application/pdf'], $cfg, $h));
        $this->check('map string value', fn() => self::expectMap('file.pdf', $cfg, $h, 'file.pdf'));
        $this->check('map empty → Kein Eintrag', fn() => self::expectMapContains('', $cfg, $h, __('[No entry]', 'formfabricator')));
    }

    public function testSignature(): void
    {
        $this->section('signature');
        $h   = new \FabricatorForms\Fields\SignatureField();
        $cfg = array_merge($h->getDefaultConfig(), ['type'=>'signature','label'=>'Unterschrift','export_format'=>'png']);

        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- carries raw binary across a JSON/array boundary between the field handler and the PDF/mail layer. Not obfuscation.
        $validPng = 'data:image/png;base64,'.base64_encode("\x89PNG\r\n\x1a\n".str_repeat("\x00", 100));
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- carries raw binary across a JSON/array boundary between the field handler and the PDF/mail layer. Not obfuscation.
        $validJpg = 'data:image/jpeg;base64,'.base64_encode("\xff\xd8\xff".str_repeat("\x00", 100));

        $this->check('schema integrity', fn() => self::schemaIntegrity($h));
        $this->check('render basic', fn() => self::renderBasic($h, $cfg));
        $this->check('render <canvas', fn() => self::contains($h->render($cfg, 'f1'), '<canvas'));
        $this->check('render data-format=png', fn() => self::contains($h->render($cfg, 'f1'), 'data-format="png"'));
        $this->check('render data-format=jpeg', fn() => self::contains($h->render(array_merge($cfg, ['export_format'=>'jpeg']), 'f1'), 'data-format="jpeg"'));
        $this->check('render data-required when req', fn() => self::contains($h->render(array_merge($cfg, ['required'=>true]), 'f1'), 'data-required="true"'));
        $this->check('render canvas_height attr', fn() => self::contains($h->render(array_merge($cfg, ['canvas_height'=>300]), 'f1'), '300'));
        $this->check('render data-stroke attr', function () use ($h, $cfg) {
            return self::contains($h->render(array_merge($cfg, ['stroke_width'=>4]), 'f1'), 'data-stroke="4"');
        });
        $this->check('validate required empty', fn() => self::expectError('', array_merge($cfg, ['required'=>true]), $h));
        $this->check('validate optional empty', fn() => self::expectOk('', $cfg, $h));
        $this->check('validate required valid png', fn() => self::expectOk($validPng, array_merge($cfg, ['required'=>true]), $h));
        $this->check('validate required jpeg format', function () use ($h, $cfg, $validJpg) {
            return self::expectOk($validJpg, array_merge($cfg, ['required'=>true,'export_format'=>'jpeg']), $h);
        });
        $this->check('validate png rejected for jpeg config', function () use ($h, $cfg, $validPng) {
            return self::expectError($validPng, array_merge($cfg, ['required'=>true,'export_format'=>'jpeg']), $h);
        });
        $this->check('map non-empty → empty string', fn() => self::expectMap($validPng, $cfg, $h, ''));
        $this->check('map empty → Kein Eintrag', fn() => self::expectMapContains('', $cfg, $h, __('[No entry]', 'formfabricator')));
        $this->check('includeValueInSeal=false', fn() => !$h->includeValueInSeal() ? true : 'expected false');
    }

    public function testRating(): void
    {
        $this->section('rating');
        $h   = new \FabricatorForms\Fields\RatingField();
        $cfg = array_merge($h->getDefaultConfig(), ['type'=>'rating','label'=>'Bewertung','max'=>5,'icon_type'=>'star']);

        $this->check('schema integrity', fn() => self::schemaIntegrity($h));
        $this->check('render basic', fn() => self::renderBasic($h, $cfg));
        $this->check('render 5 radio inputs', function () use ($h, $cfg) {
            $count = substr_count($h->render($cfg, 'f1'), 'type="radio"');
            return $count === 5 ? true : "expected 5 radio inputs, got $count";
        });
        $this->check('render max=3 → 3 radios', function () use ($h, $cfg) {
            $count = substr_count($h->render(array_merge($cfg, ['max'=>3]), 'f1'), 'type="radio"');
            return $count === 3 ? true : "expected 3, got $count";
        });
        $this->check('render allow_half doubles inputs', function () use ($h, $cfg) {
            $count = substr_count($h->render(array_merge($cfg, ['allow_half'=>true]), 'f1'), 'type="radio"');
            return $count === 10 ? true : "expected 10 (5*2), got $count";
        });
        foreach (['star','heart','circle','diamond'] as $icon) {
            $this->check("render icon_type=$icon", function () use ($h, $cfg, $icon) {
                return is_string($h->render(array_merge($cfg, ['icon_type'=>$icon]), 'f1')) ? true : "failed for $icon";
            });
        }
        $this->check('render custom icon_source uses image url', function () use ($h, $cfg) {
            $c = array_merge($cfg, ['icon_source'=>true,'custom_icon_url'=>'https://example.com/star.png']);
            return self::contains($h->render($c, 'f1'), 'star.png');
        });
        $this->check('validate required empty', fn() => self::expectError('', array_merge($cfg, ['required'=>true]), $h));
        $this->check('validate optional empty', fn() => self::expectOk('', $cfg, $h));
        $this->check('validate valid rating', fn() => self::expectOk('3', $cfg, $h));
        $this->check('validate above max → error', fn() => self::expectError('99', $cfg, $h));
        $this->check('validate negative → error', fn() => self::expectError('-1', $cfg, $h));
        $this->check('validate non-numeric → error', fn() => self::expectError('abc', $cfg, $h));
        $this->check('validate half-step rejected without allow_half', fn() => self::expectError('2.5', $cfg, $h));
        $this->check('validate half-step accepted with allow_half', function () use ($h, $cfg) {
            return self::expectOk('2.5', array_merge($cfg, ['allow_half'=>true]), $h);
        });
        $this->check('validate exactly at max → ok', fn() => self::expectOk('5', $cfg, $h));
        $this->check('map value/max format', fn() => self::expectMap('3', $cfg, $h, '3 / 5'));
        $this->check('map half value format', fn() => self::expectMap('2.5', $cfg, $h, '2.5 / 5'));
        $this->check('map empty → Kein Eintrag', fn() => self::expectMapContains('', $cfg, $h, __('[No entry]', 'formfabricator')));
    }

    public function testSlider(): void
    {
        $this->section('slider');
        $h   = new \FabricatorForms\Fields\SliderField();
        $cfg = array_merge($h->getDefaultConfig(), ['type'=>'slider','label'=>'Slider','min'=>0,'max'=>100,'step'=>1,'ranged'=>false]);

        $this->check('schema integrity', fn() => self::schemaIntegrity($h));
        $this->check('render basic', fn() => self::renderBasic($h, $cfg));
        $this->check('render data-min/max/step', fn() => self::contains($h->render($cfg, 'f1'), 'data-min'));
        $this->check('render ranged two hidden inputs', function () use ($h, $cfg) {
            $html = $h->render(array_merge($cfg, ['ranged'=>true]), 'f1');
            $count = substr_count($html, 'type="hidden"');
            return $count === 2 ? true : "expected 2 hidden inputs, got $count";
        });
        $this->check('validate required empty', fn() => self::expectError('', array_merge($cfg, ['required'=>true]), $h));
        $this->check('validate optional empty', fn() => self::expectOk('', $cfg, $h));
        $this->check('validate valid in range', fn() => self::expectOk('50', $cfg, $h));
        $this->check('validate below min', fn() => self::expectError('-5', $cfg, $h));
        $this->check('validate above max', fn() => self::expectError('150', $cfg, $h));
        $this->check('validate exact at min passes', fn() => self::expectOk('0', $cfg, $h));
        $this->check('validate exact at max passes', fn() => self::expectOk('100', $cfg, $h));
        $this->check('validate non-numeric', fn() => self::expectError('abc', array_merge($cfg, ['required'=>true]), $h));
        $this->check('validate ranged valid', fn() => self::expectOk(['from'=>'20','to'=>'80'], array_merge($cfg, ['ranged'=>true]), $h));
        $this->check('validate ranged below min', fn() => self::expectError(['from'=>'-5','to'=>'50'], array_merge($cfg, ['ranged'=>true]), $h));
        $this->check('validate ranged above max', fn() => self::expectError(['from'=>'50','to'=>'150'], array_merge($cfg, ['ranged'=>true]), $h));
        $this->check('validate ranged exact at min/max passes', function () use ($h, $cfg) {
            return self::expectOk(['from'=>'0','to'=>'100'], array_merge($cfg, ['ranged'=>true]), $h);
        });
        $this->check('map scalar value', fn() => self::expectMap('50', $cfg, $h, '50'));
        $this->check('map empty → Kein Eintrag', fn() => self::expectMapContains('', $cfg, $h, __('[No entry]', 'formfabricator')));
        $this->check('map ranged has from', fn() => self::expectMapContains(['from'=>'20','to'=>'80'], array_merge($cfg, ['ranged'=>true]), $h, '20'));
        $this->check('map ranged has to', fn() => self::expectMapContains(['from'=>'20','to'=>'80'], array_merge($cfg, ['ranged'=>true]), $h, '80'));
    }

    public function testCaptcha(): void
    {
        $this->section('captcha');
        $h   = new \FabricatorForms\Fields\CaptchaField();
        $cfg = $h->getDefaultConfig();

        $this->check('schema integrity', fn() => self::schemaIntegrity($h));
        $this->check('render returns string', fn() => is_string($h->render($cfg, 'f1')) ? true : 'render failed');
        // skipValidation=false — captcha runs its own server-side verify, not skipped
        $this->check('skipValidation=false', fn() => $h->skipValidation() === false ? true : 'expected false');
        // validate: empty token always errors (no secret key configured in test env)
        $this->check('validate empty token → error', fn() => self::expectError('', $cfg, $h));
        // map always returns confirmation string regardless of value
        $this->check('map always confirmed string', fn() => self::expectMapContains('anytoken', $cfg, $h, 'CAPTCHA'));
    }

    public function testConsent(): void
    {
        $this->section('consent');
        $h   = new \FabricatorForms\Fields\ConsentField();
        $cfg = array_merge($h->getDefaultConfig(), ['type'=>'consent','label'=>'Einwilligung','consent_text'=>'Ich stimme zu.']);

        $this->check('schema integrity', fn() => self::schemaIntegrity($h));
        $this->check('render basic', fn() => self::renderBasic($h, $cfg));
        $this->check('render consent text', fn() => self::contains($h->render($cfg, 'f1'), 'Ich stimme zu'));
        $this->check('render type="checkbox"', fn() => self::contains($h->render($cfg, 'f1'), 'type="checkbox"'));
        $this->check('render checked attr for truthy value', fn() => self::contains($h->render($cfg, 'f1', '1'), 'checked'));
        $this->check('render default consent_text fallback when key omitted', function () use ($h) {
            $c = $h->getDefaultConfig();
            unset($c['consent_text']);
            $c['type'] = 'consent';
            return self::contains($h->render($c, 'f1'), __('I agree.', 'formfabricator'));
        });
        $this->check('validate required empty', fn() => self::expectError('', array_merge($cfg, ['required'=>true]), $h));
        $this->check('validate required checked', fn() => self::expectOk('1', array_merge($cfg, ['required'=>true]), $h));
        $this->check('validate optional empty', fn() => self::expectOk('', $cfg, $h));
        $this->check('map checked → includes consent text', fn() => self::expectMapContains('1', $cfg, $h, 'Ich stimme zu'));
        $this->check('map checked → includes timestamp (demonstrable per Art. 7(1))', function () use ($h, $cfg) {
            return self::expectUtcStamp('1', $cfg, $h);
        });
        $this->check('map unchecked → Not agreed', fn() => self::expectMap('', $cfg, $h, __('Not agreed', 'formfabricator')));
        $this->check('map 0 → Not agreed', fn() => self::expectMap('0', $cfg, $h, __('Not agreed', 'formfabricator')));
        $this->check('sanitize keeps <a> in text', function () use ($h) {
            $out = $h->sanitizeConfigValue('consent_text', '<a href="https://x.com">Link</a>');
            return str_contains($out, '<a') ? true : 'link stripped: '.$out;
        });
    }

    public function testGdpr(): void
    {
        $this->section('gdpr');
        $h   = new \FabricatorForms\Fields\GdprField();
        $cfg = array_merge($h->getDefaultConfig(), [
            'type'=>'gdpr','label'=>'DSGVO',
            'privacy_policy_url'=>'https://example.com/privacy',
            'privacy_policy_text'=>'Datenschutz',
        ]);

        $this->check('schema integrity', fn() => self::schemaIntegrity($h));
        $this->check('render basic', fn() => self::renderBasic($h, $cfg));
        $this->check('render policy URL in link', fn() => self::contains($h->render($cfg, 'f1'), 'example.com/privacy'));
        $this->check('render policy text', fn() => self::contains($h->render($cfg, 'f1'), 'Datenschutz'));
        $this->check('render always required attr', fn() => self::contains($h->render($cfg, 'f1'), 'required'));
        $this->check('render always carries fabricator-required-field (client required check)', function () use ($h, $cfg) {
            return self::contains($h->render(array_merge($cfg, ['required'=>false]), 'f1'), 'fabricator-required-field');
        });
        $this->check('client empty check: unchecked box counts as empty', function () use ($h) {
            $fn = (string) ($h->getClientEmptyCheck()['fn'] ?? '');
            return self::contains($fn, ':checked', 'checkbox :checked empty check');
        });
        $this->check('render checked attr for truthy value', fn() => self::contains($h->render($cfg, 'f1', '1'), 'checked'));
        $this->check('render default privacy_policy_url falls back to get_privacy_policy_url()', function () use ($h) {
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
        $this->check('validate checked → ok', fn() => self::expectOk('1', $cfg, $h));
        // GDPR always errors when unchecked, regardless of required config flag
        $this->check('validate unchecked required=true → error', fn() => self::expectError('', array_merge($cfg, ['required'=>true]), $h));
        $this->check('validate unchecked required=false → error', fn() => self::expectError('', array_merge($cfg, ['required'=>false]), $h));
        $this->check('map checked → includes policy text', fn() => self::expectMapContains('1', $cfg, $h, 'Datenschutz'));
        $this->check('map checked → includes timestamp (demonstrable per Art. 7(1))', function () use ($h, $cfg) {
            return self::expectUtcStamp('1', $cfg, $h);
        });
        $this->check('map unchecked → Privacy notice not acknowledged', fn() => self::expectMap('', $cfg, $h, __('Privacy notice not acknowledged', 'formfabricator')));
    }

    public function testHtml(): void
    {
        $this->section('html');
        $h   = new \FabricatorForms\Fields\HtmlField();
        $cfg = array_merge($h->getDefaultConfig(), ['type'=>'html','label'=>'','html_content'=>'<p>Hello <strong>World</strong></p>']);

        $this->check('schema integrity', fn() => self::schemaIntegrity($h));
        $this->check('render basic', fn() => self::renderBasic($h, $cfg));
        $this->check('render preserves <strong>', fn() => self::contains($h->render($cfg, 'f1'), '<strong>World</strong>'));
        $this->check('hasRequired=false', fn() => !$h->hasRequired() ? true : 'expected false');
        $this->check('skipValidation=true', fn() => $h->skipValidation() ? true : 'expected true');
        $this->check('includeInEmailSummary=true', fn() => $h->includeInEmailSummary() ? true : 'expected true');
        $this->check('rawEmailHtml=true', fn() => $h->rawEmailHtml() ? true : 'expected true');
        $this->check('map strips all tags', function () use ($h, $cfg) {
            $m = $h->map('', $cfg);
            self::$lastIn  = '(html_content from config)';
            self::$lastOut = $m;
            return !str_contains($m, '<') ? true : 'tags remain: '.$m;
        });
        $this->check('sanitize strips <script>', function () use ($h) {
            return !str_contains($h->sanitizeConfigValue('html_content', '<p>OK</p><script>evil()</script>'), '<script') ? true : 'script not stripped';
        });
        // Form controls are removed from every rich text: nothing reads them, so their only use there is a fake form.
        $this->check('sanitize strips <input>', function () use ($h) {
            return !str_contains($h->sanitizeConfigValue('html_content', '<input type="password" name="x">'), '<input') ? true : 'input kept';
        });
        $this->check('sanitize strips <form>', function () use ($h) {
            return !str_contains($h->sanitizeConfigValue('html_content', '<form action="https://example.com/"><p>x</p></form>'), '<form') ? true : 'form kept';
        });
        $this->check('sanitize preserves <canvas>', function () use ($h) {
            return str_contains($h->sanitizeConfigValue('html_content', '<canvas id="c"></canvas>'), '<canvas') ? true : 'canvas stripped';
        });
        $this->check('sanitize preserves <svg>', function () use ($h) {
            return str_contains($h->sanitizeConfigValue('html_content', '<svg><circle cx="10" cy="10" r="5"/></svg>'), '<svg') ? true : 'svg stripped';
        });
        $this->check('sanitize strips <select>', function () use ($h) {
            return !str_contains($h->sanitizeConfigValue('html_content', '<select><option value="a">A</option></select>'), '<select') ? true : 'select kept';
        });
        $this->check('sanitize preserves <source>', function () use ($h) {
            return str_contains($h->sanitizeConfigValue('html_content', '<source src="a.mp4" type="video/mp4">'), '<source') ? true : 'source stripped';
        });
        $this->check('sanitize <use href="#frag"> kept, external href stripped', function () use ($h) {
            $out = $h->sanitizeConfigValue('html_content', '<svg><use href="#frag"></use><use href="http://evil.com/x"></use></svg>');
            self::$lastIn  = '<use href="#frag"> + <use href="http://evil.com/x">';
            self::$lastOut = $out;
            if (!str_contains($out, 'href="#frag"')) {
                return 'in-document fragment href was stripped unexpectedly';
            }
            return !str_contains($out, 'evil.com') ? true : 'external href was not stripped';
        });
        $this->check('sanitize plain-text key (label) strips <script>', function () use ($h) {
            return !str_contains($h->sanitizeConfigValue('label', '<p>ok</p><script>evil()</script>'), '<script')
                ? true : 'script not stripped for non-html_content key';
        });
        $this->check('mapNormalized empty html_content → []', function () use ($h) {
            $c = array_merge($h->getDefaultConfig(), ['type'=>'html','label'=>'','html_content'=>'']);
            $r = $h->mapNormalized('f1', '', '', $c, []);
            self::$lastIn  = 'html_content=""';
            self::$lastOut = var_export($r, true);
            return $r === [] ? true : 'expected [], got: ' . var_export($r, true);
        });
        $this->check('mapNormalized non-empty html_content → labeled entry', function () use ($h) {
            $c = array_merge($h->getDefaultConfig(), ['type'=>'html','label'=>'Block','html_content'=>'<p>Hi</p>']);
            $r = $h->mapNormalized('f1', 'Block', '', $c, []);
            self::$lastIn  = 'html_content=<p>Hi</p>';
            self::$lastOut = var_export($r, true);
            return (isset($r['f1']) && $r['f1']['label'] === 'Block' && str_contains($r['f1']['value'], 'Hi'))
                ? true : 'unexpected result: ' . var_export($r, true);
        });
        $this->check('mapNormalized show_in_output=false → []', function () use ($h) {
            $c = array_merge($h->getDefaultConfig(), ['type'=>'html','label'=>'Block','html_content'=>'<p>Hi</p>','show_in_output'=>false]);
            $r = $h->mapNormalized('f1', 'Block', '', $c, []);
            self::$lastIn  = 'show_in_output=false';
            self::$lastOut = var_export($r, true);
            return $r === [] ? true : 'expected [], got: ' . var_export($r, true);
        });
    }

    public function testGroup(): void
    {
        $this->section('group');
        $h   = new \FabricatorForms\Fields\GroupField();
        $cfg = array_merge($h->getDefaultConfig(), ['type'=>'group','label'=>'Gruppe','children'=>[]]);

        $this->check('schema integrity', fn() => self::schemaIntegrity($h));
        $this->check('isGroupContainer=true', fn() => $h->isGroupContainer() ? true : 'expected true');
        $this->check('hasRequired=false', fn() => !$h->hasRequired() ? true : 'expected false');
        $this->check('includeInEmailSummary=false', fn() => !$h->includeInEmailSummary() ? true : 'expected false');
        $this->check('render returns string', fn() => is_string($h->render($cfg, 'f1')) ? true : 'render failed');
        $this->check('render opens fabricator-field-group', fn() => self::contains($h->render($cfg, 'f1'), 'fabricator-field-group'));
        $this->check('map always empty string', fn() => self::expectMap(null, $cfg, $h, ''));
        $this->check('mapNormalized empty → []', function () use ($h, $cfg) {
            $r = $h->mapNormalized('f1', 'Group', [], $cfg, []);
            return $r === [] ? true : 'expected [], got: '.var_export($r, true);
        });
        $this->check('mapNormalized populated', function () use ($h) {
            $children = [['id'=>'child_text','type'=>'text','label'=>'Kind']];
            $c        = array_merge($h->getDefaultConfig(), ['type'=>'group','label'=>'Gruppe','children'=>$children]);
            $value    = ['child_text' => 'Hallo'];
            $r        = $h->mapNormalized('grp1', 'Gruppe', $value, $c, []);
            self::$lastIn  = wp_json_encode($value);
            self::$lastOut = wp_json_encode($r);
            if (!isset($r['child_text'])) {
                return 'expected key "child_text" (single copy, no suffix), got: ' . var_export($r, true);
            }
            return $r['child_text']['value'] === 'Hallo' ? true : 'unexpected value: ' . var_export($r['child_text'], true);
        });
    }

    public function testPageBreak(): void
    {
        $this->section('pagebreak');
        $h   = new \FabricatorForms\Fields\PageBreakField();
        $cfg = array_merge($h->getDefaultConfig(), ['type'=>'pagebreak','label'=>'','prev_btn'=>'Zurück','next_btn'=>'Weiter']);

        $this->check('schema integrity', fn() => self::schemaIntegrity($h));
        $this->check('isPageBreak=true', fn() => $h->isPageBreak() ? true : 'expected true');
        $this->check('hasSettingsPanel=true', fn() => $h->hasSettingsPanel() ? true : 'expected true');
        $this->check('button labels are editable', function () use ($h) {
            $keys = array_column($h->getGeneralSchema(), 'key');
            return in_array('prev_btn', $keys, true) && in_array('next_btn', $keys, true)
                ? true : 'prev_btn/next_btn missing from the schema the panel builds from';
        });
        $this->check('hasRequired=false', fn() => !$h->hasRequired() ? true : 'expected false');
        $this->check('skipValidation=true', fn() => $h->skipValidation() ? true : 'expected true');
        $this->check('includeInEmailSummary=false', fn() => !$h->includeInEmailSummary() ? true : 'expected false');
        $this->check('render returns empty string', fn() => $h->render($cfg, 'f1') === '' ? true : 'expected empty string');
        $this->check('map returns empty string', fn() => self::expectMap(null, $cfg, $h, ''));
        $this->check('mapNormalized returns []', function () use ($h, $cfg) {
            $r = $h->mapNormalized('f1', '', null, $cfg, []);
            return $r === [] ? true : 'expected []';
        });
        $this->check('renderBreak page 2 has nav', function () use ($h, $cfg) {
            $html = $h->renderBreak($cfg, 2);
            return str_contains($html, 'fabricator-btn-next') && str_contains($html, 'fabricator-btn-prev') ? true : 'nav missing';
        });
        // page 1: bottom nav uses <span></span> instead of prev button
        $this->check('renderBreak page 1 bottom has span', function () use ($h, $cfg) {
            $html = $h->renderBreak($cfg, 1);
            return str_contains($html, '<span></span>') ? true : 'expected <span></span> on page 1 bottom nav';
        });
        $this->check('renderBreak custom prev/next labels appear', function () use ($h, $cfg) {
            $html = $h->renderBreak($cfg, 2);
            self::$lastIn  = 'prev_btn=Zurück, next_btn=Weiter';
            self::$lastOut = $html;
            return (str_contains($html, 'Zurück') && str_contains($html, 'Weiter'))
                ? true : 'custom button labels missing from renderBreak output';
        });
    }

    public function testPageHeader(): void
    {
        $this->section('page-header');
        $h   = new \FabricatorForms\Fields\PageHeaderField();
        $cfg = array_merge($h->getDefaultConfig(), ['type'=>'page-header','label'=>'']);

        $this->check('schema integrity', fn() => self::schemaIntegrity($h));
        $this->check('isPageBreak=false', fn() => !$h->isPageBreak() ? true : 'expected false');
        $this->check('hasRequired=false', fn() => !$h->hasRequired() ? true : 'expected false');
        $this->check('skipValidation=true', fn() => $h->skipValidation() ? true : 'expected true');
        $this->check('includeInEmailSummary=false', fn() => !$h->includeInEmailSummary() ? true : 'expected false');
        $this->check('map returns empty string', fn() => self::expectMap(null, $cfg, $h, ''));
        $this->check('mapNormalized returns []', function () use ($h, $cfg) {
            $r = $h->mapNormalized('f1', '', null, $cfg, []);
            return $r === [] ? true : 'expected []';
        });
        $this->check('render contains container class', function () use ($h, $cfg) {
            $html = $h->render($cfg, 'f1');
            self::$lastIn  = wp_json_encode($cfg);
            self::$lastOut = $html;
            return str_contains($html, 'fabricator-page-header') ? true : 'container class missing';
        });
        $this->check('render show_names=false → data-show-names="0"', function () use ($h, $cfg) {
            $html = $h->render(array_merge($cfg, ['show_names'=>false]), 'f1');
            return str_contains($html, 'data-show-names="0"') ? true : 'expected data-show-names="0"';
        });
        $this->check('render show_names=true → data-show-names="1"', function () use ($h, $cfg) {
            $html = $h->render(array_merge($cfg, ['show_names'=>true]), 'f1');
            return str_contains($html, 'data-show-names="1"') ? true : 'expected data-show-names="1"';
        });
        $this->check('render page_names serialized only when show_names=true', function () use ($h, $cfg) {
            $c    = array_merge($cfg, ['show_names'=>true,'page_names'=>['Kontakt','Adresse']]);
            $html = $h->render($c, 'f1');
            self::$lastIn  = wp_json_encode($c);
            self::$lastOut = $html;
            return str_contains($html, 'Kontakt') && str_contains($html, 'Adresse')
                ? true : 'page names missing from data-names';
        });
        $this->check('render page_names omitted when show_names=false', function () use ($h, $cfg) {
            $c    = array_merge($cfg, ['show_names'=>false,'page_names'=>['Kontakt','Adresse']]);
            $html = $h->render($c, 'f1');
            return !str_contains($html, 'Kontakt') ? true : 'page names leaked while show_names=false';
        });
        $this->check('render escapes page name HTML', function () use ($h, $cfg) {
            $c    = array_merge($cfg, ['show_names'=>true,'page_names'=>['<script>alert(1)</script>']]);
            $html = $h->render($c, 'f1');
            self::$lastIn  = wp_json_encode($c);
            self::$lastOut = $html;
            return !str_contains($html, '<script>') ? true : 'unescaped script tag in output';
        });
        $this->check('getClientInit returns non-empty script', fn() => trim($h->getClientInit()) !== '' ? true : 'expected non-empty JS');
    }

    public function testPostData(): void
    {
        $this->section('postdata');
        $h   = new \FabricatorForms\Fields\PostDataField();
        $cfg = array_merge($h->getDefaultConfig(), ['type'=>'postdata','label'=>'Beitragsinfo','post_field'=>['post_title']]);

        $this->check('schema integrity', fn() => self::schemaIntegrity($h));
        $this->check('render returns string', fn() => is_string($h->render($cfg, 'f1')) ? true : 'render failed');
        $this->check('hasRequired=false', fn() => !$h->hasRequired() ? true : 'expected false');
        $this->check('render produces hidden input with correct name', function () use ($h, $cfg) {
            $html = $h->render($cfg, 'f1');
            self::$lastIn  = 'post_field=[post_title]';
            self::$lastOut = $html;
            return str_contains($html, '<input type="hidden" name="f1[post_title]"')
                ? true : 'expected hidden input name f1[post_title], got: ' . $html;
        });
        $this->check('map array → imploded values', fn() => self::expectMapContains(['post_title'=>'My Page','post_id'=>'42'], $cfg, $h, 'My Page'));
        $this->check('map excludes fields not selected in post_field', function () use ($h, $cfg) {
            $value = ['post_title'=>'My Page','post_id'=>'42','post_url'=>'https://example.com','post_author'=>'Admin'];
            $r     = $h->map($value, $cfg);
            self::$lastIn  = wp_json_encode($value);
            self::$lastOut = $r;
            if (str_contains($r, '42') || str_contains($r, 'example.com') || str_contains($r, 'Admin')) {
                return 'unselected post_field values leaked into output: ' . $r;
            }
            return str_contains($r, 'My Page') ? true : 'expected "My Page" in output';
        });
        $this->check('map empty array → Kein Eintrag', fn() => self::expectMapContains([], $cfg, $h, __('[No entry]', 'formfabricator')));
        $this->check('map non-array → Kein Eintrag', fn() => self::expectMapContains('', $cfg, $h, __('[No entry]', 'formfabricator')));
    }

    public function testWebsite(): void
    {
        $this->section('website');
        $h   = new \FabricatorForms\Fields\WebsiteField();
        $cfg = array_merge($h->getDefaultConfig(), ['type'=>'website','label'=>'Webseite']);

        $this->check('schema integrity', fn() => self::schemaIntegrity($h));
        $this->check('render basic', fn() => self::renderBasic($h, $cfg));
        $this->check('render type="url"', fn() => self::contains($h->render($cfg, 'f1'), 'type="url"'));
        $this->check('render validate_url data-attr', fn() => self::contains($h->render(array_merge($cfg, ['validate_url'=>true]), 'f1'), 'data-validate-url'));
        $this->check('validate required empty', fn() => self::expectError('', array_merge($cfg, ['required'=>true]), $h));
        $this->check('validate optional empty', fn() => self::expectOk('', $cfg, $h));
        $this->check('validate valid URL', fn() => self::expectOk('https://example.com', $cfg, $h));
        $this->check('validate invalid URL when flag', fn() => self::expectError('not a url', array_merge($cfg, ['validate_url'=>true]), $h));
        // validate_url=false: invalid URLs pass (no format check)
        $this->check('validate invalid URL no flag', fn() => self::expectOk('not a url', array_merge($cfg, ['validate_url'=>false]), $h));
        $this->check('map value', fn() => self::expectMap('https://example.com', $cfg, $h, 'https://example.com'));
        $this->check('map empty → Kein Eintrag', fn() => self::expectMapContains('', $cfg, $h, __('[No entry]', 'formfabricator')));
    }

    public function testSepa(): void
    {
        $this->section('sepa');
        $h   = new \FabricatorForms\Fields\SepaField();
        $cfg = array_merge($h->getDefaultConfig(), [
            'type'=>'sepa','label'=>'SEPA-Mandat',
            'mandate_title'=>'SEPA Lastschriftmandat',
            'creditor_id'=>'DE98ZZZ09999999999','mandate_ref'=>'MANDAT-001',
        ]);

        // validate() only checks the data-URI prefix, not real image content.
        $dummySig  = 'data:image/png;base64,iVBORw0KGgo=';
        $validData = ['iban'=>'DE89370400440532013000','bic'=>'COBADEFFXXX','holder'=>'Max Mustermann','sig'=>$dummySig];

        $this->check('schema integrity', fn() => self::schemaIntegrity($h));
        $this->check('render basic', fn() => self::renderBasic($h, $cfg));
        $this->check('render mandate title', fn() => self::contains($h->render($cfg, 'f1'), 'SEPA Lastschriftmandat'));
        $this->check('render IBAN input class', fn() => self::contains($h->render($cfg, 'f1'), 'fabricator-sepa-iban'));
        $this->check('render BIC input class', fn() => self::contains($h->render($cfg, 'f1'), 'fabricator-sepa-bic'));
        $this->check('render holder input class', fn() => self::contains($h->render($cfg, 'f1'), 'fabricator-sepa-holder'));
        $this->check('render <canvas for sig', fn() => self::contains($h->render($cfg, 'f1'), '<canvas'));
        $this->check('render creditor_id in output', fn() => self::contains($h->render($cfg, 'f1'), 'DE98ZZZ09999999999'));
        $this->check('render country_filter → data attr present', function () use ($h, $cfg) {
            $c = array_merge($cfg, ['country_filter_mode'=>'allow','country_filter_list'=>['DE','AT']]);
            return self::contains($h->render($c, 'f1'), 'data-country-filter');
        });
        $this->check('render country_filter → list attr present', function () use ($h, $cfg) {
            $c = array_merge($cfg, ['country_filter_mode'=>'allow','country_filter_list'=>['DE','AT']]);
            return self::contains($h->render($c, 'f1'), 'data-country-list');
        });
        $this->check('render country_filter off → no attr', function () use ($h, $cfg) {
            $html = $h->render(array_merge($cfg, ['country_filter_mode'=>'off']), 'f1');
            self::$lastIn  = 'country_filter_mode=off';
            self::$lastOut = str_contains($html, 'data-country-filter') ? 'attr present' : 'attr absent';
            return !str_contains($html, 'data-country-filter') ? true : 'attr should be absent when mode=off';
        });
        // Server-side country filter validation
        $this->check('validate allow-list blocks foreign IBAN', function () use ($h, $cfg, $dummySig) {
            $c = array_merge($cfg, ['required'=>true,'country_filter_mode'=>'allow','country_filter_list'=>['DE']]);
            return self::expectError(['iban'=>'AT611904300234573201','bic'=>'COBADEFFXXX','holder'=>'Max','sig'=>$dummySig], $c, $h);
        });
        $this->check('validate allow-list passes matching IBAN', function () use ($h, $cfg, $dummySig) {
            $c = array_merge($cfg, ['required'=>true,'country_filter_mode'=>'allow','country_filter_list'=>['DE']]);
            return self::expectOk(['iban'=>'DE89370400440532013000','bic'=>'COBADEFFXXX','holder'=>'Max','sig'=>$dummySig], $c, $h);
        });
        $this->check('validate disallow-list blocks listed IBAN', function () use ($h, $cfg, $dummySig) {
            $c = array_merge($cfg, ['required'=>true,'country_filter_mode'=>'disallow','country_filter_list'=>['AT']]);
            return self::expectError(['iban'=>'AT611904300234573201','bic'=>'COBADEFFXXX','holder'=>'Max','sig'=>$dummySig], $c, $h);
        });
        $this->check('validate disallow-list passes unlisted IBAN', function () use ($h, $cfg, $dummySig) {
            $c = array_merge($cfg, ['required'=>true,'country_filter_mode'=>'disallow','country_filter_list'=>['AT']]);
            return self::expectOk(['iban'=>'DE89370400440532013000','bic'=>'COBADEFFXXX','holder'=>'Max','sig'=>$dummySig], $c, $h);
        });
        $this->check('validate country filter off passes any country', function () use ($h, $cfg, $dummySig) {
            $c = array_merge($cfg, ['required'=>true,'country_filter_mode'=>'off','country_filter_list'=>['DE']]);
            return self::expectOk(['iban'=>'AT611904300234573201','bic'=>'COBADEFFXXX','holder'=>'Max','sig'=>$dummySig], $c, $h);
        });
        // optional=false skips all validation immediately
        $this->check('validate optional → true', fn() => self::expectOk([], array_merge($cfg, ['required'=>false]), $h));
        $this->check('validate req non-array → error', fn() => self::expectError(null, array_merge($cfg, ['required'=>true]), $h));
        $this->check('validate req empty IBAN → error', fn() => self::expectError(['iban'=>'','bic'=>'COBADEFFXXX','holder'=>'Max','sig'=>$dummySig], array_merge($cfg, ['required'=>true]), $h));
        $this->check('validate req invalid IBAN', fn() => self::expectError(['iban'=>'INVALID','bic'=>'COBADEFFXXX','holder'=>'Max','sig'=>$dummySig], array_merge($cfg, ['required'=>true]), $h));
        $this->check('validate req empty BIC → error', fn() => self::expectError(['iban'=>'DE89370400440532013000','bic'=>'','holder'=>'Max','sig'=>$dummySig], array_merge($cfg, ['required'=>true]), $h));
        // 'TOO' is only 3 chars — fails [A-Z]{6} minimum
        $this->check('validate req invalid BIC', fn() => self::expectError(['iban'=>'DE89370400440532013000','bic'=>'TOO','holder'=>'Max','sig'=>$dummySig], array_merge($cfg, ['required'=>true]), $h));
        $this->check('validate req empty holder', fn() => self::expectError(['iban'=>'DE89370400440532013000','bic'=>'COBADEFFXXX','holder'=>'','sig'=>$dummySig], array_merge($cfg, ['required'=>true]), $h));
        $this->check('validate req all valid → true', fn() => self::expectOk($validData, array_merge($cfg, ['required'=>true]), $h));
        $this->check('validate lowercase BIC passes (case-insensitive regex)', function () use ($h, $cfg, $dummySig) {
            return self::expectOk(['iban'=>'DE89370400440532013000','bic'=>'cobadeffxxx','holder'=>'Max','sig'=>$dummySig], array_merge($cfg, ['required'=>true]), $h);
        });
        $this->check('validate lowercase country_filter_list entry matches (strtoupper normalized)', function () use ($h, $cfg, $dummySig) {
            $c = array_merge($cfg, ['required'=>true,'country_filter_mode'=>'allow','country_filter_list'=>['de']]);
            return self::expectOk(['iban'=>'DE89370400440532013000','bic'=>'COBADEFFXXX','holder'=>'Max','sig'=>$dummySig], $c, $h);
        });
        $this->check('validate req empty signature → error', fn() => self::expectError(['iban'=>'DE89370400440532013000','bic'=>'COBADEFFXXX','holder'=>'Max','sig'=>''], array_merge($cfg, ['required'=>true]), $h));
        $this->check('validate req non-image signature → error', fn() => self::expectError(['iban'=>'DE89370400440532013000','bic'=>'COBADEFFXXX','holder'=>'Max','sig'=>'not-a-data-uri'], array_merge($cfg, ['required'=>true]), $h));
        $this->check('map non-array → No entry', fn() => str_contains($h->map(null, $cfg), __('[No entry]', 'formfabricator')) ? true : 'wrong map');
        $this->check('map valid data contains IBAN', fn() => self::expectMapContains($validData, $cfg, $h, 'IBAN'));
        $this->check('map valid data contains BIC', fn() => self::expectMapContains($validData, $cfg, $h, 'BIC'));
        $this->check('map valid data contains holder', fn() => self::expectMapContains($validData, $cfg, $h, 'Mustermann'));
    }

    // ── conditional logic ────────────────────────────────────────────────────

    /**
     * Calls a private static FormProcessor/FormRenderer method, so the condition handling of the real submit and render paths is tested directly.
     *
     * @param string $class   Fully qualified class name.
     * @param string $method  Method name.
     * @param mixed  ...$args Method arguments.
     * @return mixed Whatever the method returns.
     */
    private static function callPrivate(string $class, string $method, mixed ...$args): mixed
    {
        return (new \ReflectionMethod($class, $method))->invoke(null, ...$args);
    }

    /**
     * For an unmet (country=FR) and a met (country=DE) condition, asserts the server hides and drops the field exactly
     * while unmet, and that the browser receives the rule.
     *
     * @param array  $fields  Top-level form fields containing the field under test.
     * @param array  $ruleCfg Config carrying the condition: the field itself, or its group.
     * @param string $leafId  Id of the field under test.
     * @param string $attr    data-conditions attribute rendered for $ruleCfg.
     * @return bool|string True, or a failure description.
     */
    private static function expectHiddenOnlyWhileUnmet(array $fields, array $ruleCfg, string $leafId, string $attr): bool|string
    {
        $proc = \FabricatorForms\Form\FormProcessor::class;
        $seen = [];
        foreach (['FR' => true, 'DE' => false] as $country => $expectHidden) {
            $flat    = ['country' => $country];
            $hidden  = self::callPrivate($proc, 'isHiddenByConditions', $ruleCfg, $flat);
            $dropped = in_array($leafId, self::callPrivate($proc, 'collectHiddenIds', $fields, $flat), true);
            $seen[]  = 'country=' . $country . ': ' . ($hidden ? 'hidden' : 'visible') . ($dropped ? ', dropped' : '');
            if ($hidden !== $expectHidden || $dropped !== $expectHidden) {
                self::$lastIn  = $leafId;
                self::$lastOut = implode(' | ', $seen);
                return 'expected ' . ($expectHidden ? 'hidden and dropped (not validated, not required-checked)' : 'visible and kept (validated)')
                    . ' for country=' . $country;
            }
        }
        self::$lastIn  = $leafId . ' (shown when country=DE)';
        self::$lastOut = implode(' | ', $seen) . ($attr !== '' ? ' | rule sent to browser' : '');
        return $attr !== '' ? true : 'no data-conditions rendered: the browser would validate the field while it is hidden';
    }

    public function testConditionalLogic(): void
    {
        $this->section('conditional logic — hidden = never validated or required-checked');
        $cond = ['action'=>'show','match'=>'all','rules'=>[['field_id'=>'country','operator'=>'equals','value'=>'DE']]];
        $rend = \FabricatorForms\Form\FormRenderer::class;
        $grp  = new \FabricatorForms\Fields\GroupField();

        $this->check('validation-less types are exactly html, page-header, pagebreak', function () {
            $skip = [];
            foreach (\FabricatorForms\Fields\FieldRegistry::all() as $type => $class) {
                if ((new $class())->skipValidation()) {
                    $skip[] = $type;
                }
            }
            sort($skip);
            self::$lastIn  = 'skipValidation() across FieldRegistry';
            self::$lastOut = implode(', ', $skip);
            return $skip === ['html', 'page-header', 'pagebreak'] ? true : 'unexpected set: ' . self::$lastOut;
        });

        // Every type, CAPTCHA/Consent/GDPR included: no type may opt out of its conditions.
        foreach (\FabricatorForms\Fields\FieldRegistry::all() as $type => $class) {
            $h = new $class();
            if ($h->isGroupContainer() || $h->isPageBreak()) {
                continue;
            }
            $field = ['id'=>'f_' . $type,'type'=>$type,'label'=>$type,'required'=>true];
            $own   = $field + ['conditions'=>$cond];

            $this->check($type . ': own condition', function () use ($own, $rend) {
                return self::expectHiddenOnlyWhileUnmet([$own], $own, $own['id'], self::callPrivate($rend, 'conditionAttr', $own));
            });
            $this->check($type . ': inside a conditional group', function () use ($field, $cond, $grp) {
                $group = ['id'=>'g_' . $field['type'],'type'=>'group','conditions'=>$cond,'children'=>[$field]];
                return self::expectHiddenOnlyWhileUnmet([$group], $group, $field['id'], $grp->rowCondAttr($group));
            });
            $this->check($type . ': own condition inside a visible group', function () use ($own, $rend) {
                $group = ['id'=>'g_' . $own['type'],'type'=>'group','children'=>[$own]];
                return self::expectHiddenOnlyWhileUnmet([$group], $own, $own['id'], self::callPrivate($rend, 'conditionAttr', $own));
            });
        }

        $this->check('hidden group → every child dropped', function () use ($cond) {
            $fields = [[
                'id'=>'g1','type'=>'group','conditions'=>$cond,
                'children'=>[['id'=>'c_consent','type'=>'consent','required'=>true], ['id'=>'c_gdpr','type'=>'gdpr']],
            ]];
            $ids      = self::callPrivate(\FabricatorForms\Form\FormProcessor::class, 'collectHiddenIds', $fields, ['country'=>'FR']);
            $expected = ['g1', 'c_consent', 'c_gdpr'];
            self::$lastIn  = 'country=FR';
            self::$lastOut = implode(', ', $ids);
            return $ids === $expected ? true : 'expected ' . implode(', ', $expected);
        });

        // Once the condition is met the field is visible, so validate() and its required check run as for any other field.
        $captcha = new \FabricatorForms\Fields\CaptchaField();
        $consent = new \FabricatorForms\Fields\ConsentField();
        $gdpr    = new \FabricatorForms\Fields\GdprField();
        $this->check('visible captcha, empty token → error', fn() => self::expectError('', $captcha->getDefaultConfig(), $captcha));
        $this->check('visible required consent, unchecked → error', fn() => self::expectError('', array_merge($consent->getDefaultConfig(), ['required'=>true]), $consent));
        $this->check('visible gdpr, unchecked → error', fn() => self::expectError('', $gdpr->getDefaultConfig(), $gdpr));
    }

    public function testRegistryCoverage(): void
    {
        $this->section('FieldRegistry coverage');
        $registry    = \FabricatorForms\Fields\FieldRegistry::all();
        $testedTypes = [
            'text', 'textarea', 'email', 'name', 'phone', 'number', 'address',
            'date', 'time', 'currency', 'select', 'radio', 'checkbox', 'upload',
            'signature', 'rating', 'slider', 'captcha', 'consent', 'gdpr', 'html',
            'group', 'pagebreak', 'page-header', 'postdata', 'website', 'sepa',
        ];

        $this->check('all registered types are tested', function () use ($registry, $testedTypes) {
            $missing = array_diff(array_keys($registry), $testedTypes);
            return empty($missing) ? true : 'untested: '.implode(', ', $missing);
        });
        $this->check('no tested type is unregistered', function () use ($registry, $testedTypes) {
            $unknown = array_diff($testedTypes, array_keys($registry));
            return empty($unknown) ? true : 'not registered: '.implode(', ', $unknown);
        });
    }
}
