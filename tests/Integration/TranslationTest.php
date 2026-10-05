<?php

namespace FabricatorForms\Tests\Integration;

use FabricatorForms\Fields\FieldRegistry;
use FabricatorForms\Form\FormModel;
use FabricatorForms\Form\FormRenderer;
use FabricatorForms\Utils\Assets;

/**
 * The German translation in languages/ (TESTING.md §9), loaded as WordPress loads a published one: builder, sub-field
 * labels and front-end messages are German, every source string has an entry, and the .mo matches the .po.
 */
final class TranslationTest extends TestCase
{
    private const LANGUAGES = __DIR__ . '/../../languages/';

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- overrides WP_UnitTestCase's snake_case fixture method.
    public function set_up(): void
    {
        parent::set_up();
        switch_to_locale('de_DE');
        self::assertTrue(load_textdomain('formfabricator', self::LANGUAGES . 'formfabricator-de_DE.mo', 'de_DE'));
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- overrides WP_UnitTestCase's snake_case fixture method.
    public function tear_down(): void
    {
        restore_current_locale();
        unload_textdomain('formfabricator');
        parent::tear_down();
    }

    public function testThePaletteGroupsAreGerman(): void
    {
        $labels = array_column(FieldRegistry::paletteGroups(), 'label');

        self::assertSame(['Eingabe', 'Auswahl', 'Persönlich', 'Erweitert', 'Anordnung', 'System'], $labels);
    }

    public function testNameAndAddressSubFieldLabelsAreGerman(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        // Sub-fields whose label was never changed: the field carries no label of its own, so the site's language decides.
        // (A label the admin typed is kept as typed, in any language.)
        $unlabelled = static fn(string $type): array => array_filter(
            FieldRegistry::get($type)->getDefaultConfig(),
            static fn(string $key): bool => !str_ends_with($key, '_label'),
            ARRAY_FILTER_USE_KEY
        );
        $form = FormModel::save(['title' => 'Kontakt', 'fields' => [
            ['id' => 'who', 'type' => 'name', 'label' => 'Name', 'expanded' => true] + $unlabelled('name'),
            ['id' => 'where', 'type' => 'address', 'label' => 'Adresse', 'expanded' => true] + $unlabelled('address'),
        ], 'notifications' => [], 'settings' => []], 0, true);
        self::assertIsInt($form);

        $html = FormRenderer::render($form);

        foreach (['Vorname', 'Nachname', 'Straße und Hausnummer', 'Postleitzahl'] as $german) {
            self::assertStringContainsString('>' . $german, $html);
        }
        foreach (['First name', 'Last name', 'Street and house number', 'Postal code'] as $english) {
            self::assertStringNotContainsString('>' . $english, $html);
        }

        // And the builder: a new field's defaults and its settings panel.
        self::assertSame('Vorname', FieldRegistry::get('name')->getDefaultConfig()['fname_label']);
        self::assertStringContainsString('Straße und Hausnummer', (string) wp_json_encode(FieldRegistry::get('address')->getGeneralSchema(), JSON_UNESCAPED_UNICODE));
    }

    public function testTheFrontEndMessagesAreGerman(): void
    {
        $strings = Assets::frontLocalization();

        self::assertSame('Dieses Feld ist ein Pflichtfeld.', $strings['i18n']['field_required'] ?? $strings['field_required'] ?? null);
    }

    public function testEverySourceStringHasAGermanTranslation(): void
    {
        $pot = self::entries(self::LANGUAGES . 'formfabricator.pot');
        $po  = self::entries(self::LANGUAGES . 'formfabricator-de_DE.po');
        // The plugin header's author and links stay as they are in every language.
        $untranslatable = ['Alexander Jorek', 'https://github.com/AlexanderJorek', 'https://github.com/AlexanderJorek/FormFabricator'];

        $missing = [];
        foreach (array_keys($pot) as $key) {
            if (in_array($key, $untranslatable, true)) {
                continue;
            }
            if (!isset($po[$key]) || in_array('', $po[$key], true)) {
                $missing[] = $key;
            }
        }
        self::assertSame([], $missing, count($missing) . ' source string(s) without a German translation');
        self::assertSame([], array_values(array_diff(array_keys($po), array_keys($pot))), 'German entries for strings the plugin no longer has');
    }

    public function testTheCompiledMoIsThePo(): void
    {
        // The .mo is committed next to its .po and compiled by hand (CONTRIBUTING.md, "Translations"): compile the .po again
        // and compare, so an edited .po without a recompiled .mo fails here instead of on a German site.
        $dir = get_temp_dir() . 'ff-mo-' . wp_generate_password(6, false) . '/';
        wp_mkdir_p($dir);
        copy(self::LANGUAGES . 'formfabricator-de_DE.po', $dir . 'formfabricator-de_DE.po');
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(self::LANGUAGES . 'compile-mo.php') . ' ' . escapeshellarg($dir . 'formfabricator-de_DE.po'), $out, $code);
        $compiled = (string) file_get_contents($dir . 'formfabricator-de_DE.mo');
        unlink($dir . 'formfabricator-de_DE.po');
        unlink($dir . 'formfabricator-de_DE.mo');
        rmdir($dir);

        self::assertSame(0, $code, implode("\n", $out));
        self::assertTrue(
            $compiled === (string) file_get_contents(self::LANGUAGES . 'formfabricator-de_DE.mo'),
            'languages/formfabricator-de_DE.mo is out of date: php languages/compile-mo.php languages/formfabricator-de_DE.po'
        );
    }

    /**
     * The entries of a .po/.pot file (the header excluded), keyed "context\x04msgid" or "msgid", with their msgstr forms.
     *
     * @return array<string, string[]>
     */
    private static function entries(string $file): array
    {
        $entries = [];
        $entry   = [];
        $field   = null;
        $flush   = static function () use (&$entries, &$entry): void {
            if (($entry['msgid'] ?? '') !== '' && empty($entry['fuzzy'])) {
                $key           = (isset($entry['msgctxt']) ? $entry['msgctxt'] . "\x04" : '') . $entry['msgid'];
                $entries[$key] = $entry['msgstr'] ?? [''];
            }
            $entry = [];
        };
        foreach ((array) file($file, FILE_IGNORE_NEW_LINES) as $line) {
            if (trim($line) === '') {
                $flush();
                $field = null;
            } elseif (str_starts_with($line, '#,') && str_contains($line, 'fuzzy')) {
                $entry['fuzzy'] = true;
            } elseif (preg_match('/^(msgctxt|msgid|msgid_plural|msgstr(?:\[(\d+)\])?) "(.*)"$/', $line, $m)) {
                $field = $m[1] === 'msgstr' || str_starts_with($m[1], 'msgstr[') ? ['msgstr', (int) ($m[2] ?? 0)] : [$m[1], null];
                self::append($entry, $field, stripcslashes($m[3]));
            } elseif ($field !== null && preg_match('/^"(.*)"$/', $line, $m)) {
                self::append($entry, $field, stripcslashes($m[1]));
            }
        }
        $flush();
        return $entries;
    }

    /**
     * @param array<string, mixed>     $entry
     * @param array{0: string, 1: ?int} $field
     */
    private static function append(array &$entry, array $field, string $text): void
    {
        [$name, $index] = $field;
        if ($name === 'msgstr') {
            $entry['msgstr'][$index] = ($entry['msgstr'][$index] ?? '') . $text;
        } else {
            $entry[$name] = ($entry[$name] ?? '') . $text;
        }
    }
}
