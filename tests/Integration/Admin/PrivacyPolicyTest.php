<?php

namespace FabricatorForms\Tests\Integration\Admin;

use FabricatorForms\Admin\FormSettings;
use FabricatorForms\Admin\PrivacyPolicy;
use FabricatorForms\Tests\Integration\TestCase;

/**
 * The suggested privacy-policy text: offered in each language WordPress loads a translation of the plugin for (a
 * language pack in WP_LANG_DIR/plugins, with the core locale installed), and introduced on the Settings page by what it
 * covers: the reCAPTCHA section only while a reCAPTCHA site key is set.
 *
 * The language packs go in a folder of this run's content directory, which the registry is pointed at through its
 * lang_dir_for_domain filter: WP_LANG_DIR is the test library's own folder, shared by the runs the release build starts
 * at once, where a pack one run removes would vanish under another run loading it.
 */
final class PrivacyPolicyTest extends TestCase
{
    /** @var string[] Files this test placed in $dir, removed afterwards. */
    private array $placed = [];

    /** The plugin's language packs for this test, as WP_LANG_DIR/plugins would hold them. */
    private string $dir = '';

    private mixed $registry = null;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- overrides WP_UnitTestCase's snake_case fixture method.
    public function set_up(): void
    {
        parent::set_up();
        $this->registry = $GLOBALS['wp_textdomain_registry'] ?? null;
        // A registry of its own, so no lookup cached before this test hides the files it places.
        $GLOBALS['wp_textdomain_registry'] = new \WP_Textdomain_Registry();
        $this->dir = WP_CONTENT_DIR . '/fabricator-test-languages';
        wp_mkdir_p($this->dir);
        // A locale with a pack here is found here, as WordPress finds one in WP_LANG_DIR/plugins.
        add_filter('lang_dir_for_domain', function ($path, $domain, $locale) {
            if ($domain !== 'formfabricator') {
                return $path;
            }
            $base = $this->dir . '/formfabricator-' . $locale;
            return is_file($base . '.mo') || is_file($base . '.l10n.php') ? $this->dir . '/' : $path;
        }, 10, 3);
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- overrides WP_UnitTestCase's snake_case fixture method.
    public function tear_down(): void
    {
        foreach ($this->placed as $file) {
            wp_delete_file($file);
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
        $GLOBALS['wp_textdomain_registry'] = $this->registry;
        unload_textdomain('formfabricator');
        parent::tear_down();
    }

    public function testAnInstalledLanguagePackIsOfferedAndItsTextIsInThatLanguage(): void
    {
        // The test library's WordPress has the core locales de_DE and es_ES installed, and no translation of this plugin.
        self::assertSame(['en' => 'English'], $this->names(), 'without a language pack, English only');

        $this->place($this->dir . '/formfabricator-de_DE.mo', $this->germanMo());

        $languages = PrivacyPolicy::languages();
        self::assertSame(['en' => 'English', 'de_DE' => $languages['de_DE']['name']], $this->names());
        self::assertStringContainsString('Kontaktformulare', $languages['de_DE']['text']);
        self::assertStringContainsString('Contact forms', $languages['en']['text'], 'English is still English');
    }

    public function testALanguagePackOfPhpTranslationFilesOnlyIsOfferedToo(): void
    {
        // Language packs carry a .l10n.php beside the .mo, and WordPress loads either.
        $this->place(
            $this->dir . '/formfabricator-es_ES.l10n.php',
            '<?php return ' . var_export(['messages' => ['Contact forms' => 'Formularios de contacto']], true) . ';'
        );

        self::assertStringContainsString('Formularios de contacto', PrivacyPolicy::languages()['es_ES']['text'] ?? '');
    }

    public function testAPluginTranslationWithoutTheCoreLocaleIsNotOffered(): void
    {
        // switch_to_locale() refuses a locale WordPress itself isn't installed in, so the text could not be French.
        self::assertNotContains('fr_FR', get_available_languages());
        $this->place($this->dir . '/formfabricator-fr_FR.mo', $this->germanMo());

        self::assertSame(['en' => 'English'], $this->names());
    }

    public function testATranslationThatReadsLikeEnglishIsNotOfferedAgain(): void
    {
        // An English locale (en_GB is installed here) whose translation changes nothing in this text: a second
        // "English" would offer the same words twice.
        $this->place(
            $this->dir . '/formfabricator-en_GB.l10n.php',
            '<?php return ' . var_export(['messages' => ['Contact forms' => 'Contact forms']], true) . ';'
        );

        self::assertSame(['en' => 'English'], $this->names());
    }

    public function testAnEnglishVariantThatReadsDifferentlyIsNamedWithItsRegion(): void
    {
        $this->place(
            $this->dir . '/formfabricator-en_GB.l10n.php',
            '<?php return ' . var_export(['messages' => ['Contact forms' => 'Contact forms (UK)']], true) . ';'
        );

        $names = $this->names();
        self::assertArrayHasKey('en_GB', $names);
        self::assertNotSame('English', $names['en_GB'], 'two entries never read alike');
        self::assertSame(count($names), count(array_unique($names)));
    }

    public function testTheSettingsPageSaysWhatTheTextCovers(): void
    {
        delete_option('fabricator_forms_recaptcha_site_key');
        $page = $this->settingsPage();
        self::assertStringContainsString('A section on Google reCAPTCHA is added once a reCAPTCHA site key is set.', $page);
        self::assertStringNotContainsString('To prevent spam and fraudulent form submissions, we use Google reCAPTCHA', $page);

        update_option('fabricator_forms_recaptcha_site_key', '6Le-test-site-key');
        $page = $this->settingsPage();
        self::assertStringContainsString('what happens to a form submission, and what Google reCAPTCHA receives.', $page);
        self::assertStringContainsString('To prevent spam and fraudulent form submissions, we use Google reCAPTCHA', $page);
    }

    /**
     * The dropdown's entries: locale => name.
     *
     * @return array<string, string>
     */
    private function names(): array
    {
        $names = [];
        foreach (PrivacyPolicy::languages() as $locale => $lang) {
            $names[$locale] = $lang['name'];
        }
        return $names;
    }

    /**
     * The plugin's German translation, compiled.
     */
    private function germanMo(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/languages/formfabricator-de_DE.mo');
    }

    private function place(string $file, string $bytes): void
    {
        wp_mkdir_p(dirname($file));
        self::assertFileDoesNotExist($file, 'the test places its own files only');
        self::assertNotFalse(file_put_contents($file, $bytes));
        $this->placed[] = $file;
        // As the next request would see it: the registry remembers a locale it found nothing for.
        wp_cache_flush();
        $GLOBALS['wp_textdomain_registry'] = new \WP_Textdomain_Registry();
    }

    private function settingsPage(): string
    {
        ob_start();
        FormSettings::renderSettingsPage();
        return (string) ob_get_clean();
    }
}
