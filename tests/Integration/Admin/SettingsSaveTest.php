<?php

namespace FabricatorForms\Tests\Integration\Admin;

use FabricatorForms\Admin\FormSettings;
use FabricatorForms\PDF\HashSeal;
use FabricatorForms\Tests\Integration\AjaxTestCase;
use FabricatorForms\Tests\Support\Reflect;
use FabricatorForms\Utils\Assets;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * FormFabricator → Settings saves (TESTING.md §3): saving twice without a reload, a raw POST that leaves fields out, the
 * trusted proxy list, the access matrix from two tabs, and a seal key rotation while another one runs. Each save carries
 * the snapshot its page loaded with; a save from a page that is out of date is refused rather than applied.
 */
final class SettingsSaveTest extends AjaxTestCase
{
    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- overrides WP_UnitTestCase's snake_case fixture method.
    public function set_up(): void
    {
        parent::set_up();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        // The plugin loads the admin classes only when is_admin(), which the test bootstrap is not; hook them as
        // admin-ajax.php would.
        FormSettings::init();
        update_option('fabricator_forms_seal_setup_done', true, false);
    }

    public function testSavingTwiceWithoutReloadingWorksWithTheSnapshotTheFirstSaveReturned(): void
    {
        $loaded = self::settingsSnapshot();

        $first = $this->saveSettings(['accent_color' => '#112233'], $loaded);
        self::assertTrue($first['success'], wp_json_encode($first));

        $second = $this->saveSettings(['accent_color' => '#445566'], $first['data']['snapshot']);
        self::assertTrue($second['success'], 'the page took the new snapshot as its baseline: ' . wp_json_encode($second));
        self::assertSame('#445566', get_option('fabricator_forms_accent_color'));

        $stale = $this->saveSettings(['accent_color' => '#778899'], $loaded);
        self::assertFalse($stale['success'], 'a page still holding the first snapshot is refused');
        self::assertStringContainsString('changed elsewhere', $stale['data']['message']);
        self::assertSame('#445566', get_option('fabricator_forms_accent_color'));
    }

    public function testARawPostWithoutTheColourLayoutAndSiteKeyFieldsKeepsThem(): void
    {
        update_option('fabricator_forms_accent_color', '#123456', false);
        update_option('fabricator_forms_border_color', '#abcdef', false);
        update_option('fabricator_forms_hover_color', '#010203', false);
        update_option('fabricator_forms_admin_accent', '#0a0b0c', false);
        update_option('fabricator_forms_field_layout', 'inline', false);
        update_option('fabricator_forms_particles', 'static', false);
        update_option('fabricator_forms_recaptcha_site_key', 'site-key-kept', false);
        update_option('fabricator_forms_recaptcha_secret_key', 'secret-kept', false);

        $r = $this->saveSettings(['fabricator_cfg_b' => 'Shop'], '');

        self::assertTrue($r['success'], wp_json_encode($r));
        self::assertSame('Shop', get_option('fabricator_forms_from_name'));
        self::assertSame('#123456', get_option('fabricator_forms_accent_color'));
        self::assertSame('#abcdef', get_option('fabricator_forms_border_color'));
        self::assertSame('#010203', get_option('fabricator_forms_hover_color'));
        self::assertSame('#0a0b0c', get_option('fabricator_forms_admin_accent'));
        self::assertSame('inline', get_option('fabricator_forms_field_layout'));
        self::assertSame('static', get_option('fabricator_forms_particles'));
        self::assertSame('site-key-kept', get_option('fabricator_forms_recaptcha_site_key'));
        self::assertSame('secret-kept', get_option('fabricator_forms_recaptcha_secret_key'), 'an empty secret field keeps the secret');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTheParticleBackgroundCanBeSetToStaticAndThePagesAreTold(): void
    {
        // Its own process: the admin CSS variables are written once per request.
        $r = $this->saveSettings(['particles' => 'static'], self::settingsSnapshot());
        self::assertTrue($r['success'], wp_json_encode($r));
        self::assertSame('static', get_option('fabricator_forms_particles'));

        ob_start();
        FormSettings::renderSettingsPage();
        self::assertMatchesRegularExpression('/name="particles" value="static"\s+checked/', (string) ob_get_clean());

        wp_register_style('fabricator-forms-admin', false, [], '1');
        Reflect::call(Assets::class, 'addAdminCssVars');
        self::assertStringContainsString('--fabricator-particles: static', implode('', (array) wp_styles()->get_data('fabricator-forms-admin', 'after')));

        $animated = $this->saveSettings(['particles' => 'anything else'], $r['data']['snapshot']);
        self::assertTrue($animated['success'], wp_json_encode($animated));
        self::assertSame('animated', get_option('fabricator_forms_particles'), 'anything but "static" is animated');
    }

    public function testAnInvalidTrustedProxyEntryRefusesTheWholeSaveNamingIt(): void
    {
        update_option('fabricator_forms_trusted_proxies', '203.0.113.10', false);

        $r = $this->saveSettings(['trusted_proxies' => "198.51.100.0/24\nnot-an-ip\n10.0.0.300", 'fabricator_cfg_b' => 'Changed'], '');

        self::assertFalse($r['success']);
        self::assertStringContainsString('not-an-ip', $r['data']['message']);
        self::assertStringContainsString('10.0.0.300', $r['data']['message']);
        self::assertSame('203.0.113.10', get_option('fabricator_forms_trusted_proxies'), 'nothing written');
        self::assertNotSame('Changed', get_option('fabricator_forms_from_name'), 'not half done either');
    }

    public function testAProxyRangeTrustingTheWholeInternetShowsTheWarningUnderTheField(): void
    {
        self::assertTrue($this->saveSettings(['trusted_proxies' => '0.0.0.0/0'], '')['success']);
        self::assertStringContainsString('0.0.0.0/0 trusts a large part of the internet', $this->proxyCard());

        self::assertTrue($this->saveSettings(['trusted_proxies' => '203.0.113.10'], '')['success']);
        self::assertStringNotContainsString('trusts a large part of the internet', $this->proxyCard(), 'gone once removed');
    }

    public function testTrustedProxiesAreForAdministratorsOnly(): void
    {
        $editor = self::factory()->user->create(['role' => 'editor']);
        update_option('fabricator_forms_access', ['users' => [$editor => ['settings' => true]]]);
        wp_set_current_user($editor);

        self::assertTrue($this->saveSettings(['trusted_proxies' => '0.0.0.0/0'], '')['success']);
        self::assertSame('', (string) get_option('fabricator_forms_trusted_proxies', ''));
    }

    public function testTheAccessMatrixSavedInOneTabRefusesTheOtherTabsStaleCopy(): void
    {
        $loaded = hash('sha256', (string) wp_json_encode(get_option('fabricator_forms_access', ['roles' => [], 'users' => []])));
        $save   = fn(array $roles, string $snapshot): array => $this->ajax('fabricator_save_access_settings', [
            'nonce'    => wp_create_nonce('fabricator_access_settings'),
            'roles'    => $roles,
            'snapshot' => $snapshot,
        ]);

        $first = $save(['editor' => ['view_forms' => '1']], $loaded);
        self::assertTrue($first['success'], wp_json_encode($first));

        $other = $save(['author' => ['use_verifier' => '1']], $loaded);
        self::assertFalse($other['success'], 'the second tab loaded before the first saved');
        self::assertStringContainsString('changed elsewhere', $other['data']['message']);
        self::assertArrayNotHasKey('author', get_option('fabricator_forms_access')['roles'], 'the first tab\'s grants were not overwritten');

        $again = $save(['editor' => ['view_forms' => '1', 'edit_forms' => '1']], $first['data']['snapshot']);
        self::assertTrue($again['success'], 'saving twice in one tab works');
    }

    public function testOnANetworkPerUserGrantsAreForThisSitesMembersOnly(): void
    {
        if (!is_multisite()) {
            self::markTestSkipped('Multisite only: run with WP_MULTISITE=1.');
        }
        $member   = self::factory()->user->create(['role' => 'editor', 'display_name' => 'Member Mia']);
        $outsider = self::factory()->user->create(['role' => 'editor', 'display_name' => 'Outsider Otto']);
        remove_user_from_blog($outsider, get_current_blog_id());

        $r = $this->ajax('fabricator_save_access_settings', [
            'nonce'    => wp_create_nonce('fabricator_access_settings'),
            'users'    => [$member => ['edit_forms' => '1'], $outsider => ['edit_forms' => '1']],
            'snapshot' => hash('sha256', (string) wp_json_encode(get_option('fabricator_forms_access', ['roles' => [], 'users' => []]))),
        ]);
        self::assertTrue($r['success'], (string) wp_json_encode($r));
        self::assertSame([$member], array_keys(get_option('fabricator_forms_access')['users']), 'only this site\'s member is saved');

        // Removing the member from the site ends the grant, though the stored list still names them.
        self::assertTrue(\FabricatorForms\Plugin::userCan('edit_forms', $member));
        remove_user_from_blog($member, get_current_blog_id());
        self::assertFalse(\FabricatorForms\Plugin::userCan('edit_forms', $member));

        // The list reaches the page as the settings script's localized data.
        wp_register_script('fabricator-forms-admin-settings', false, [], '1');
        ob_start();
        FormSettings::renderSettingsPage();
        ob_end_clean();
        $data = (string) wp_scripts()->get_data('fabricator-forms-admin-settings', 'data');
        self::assertStringContainsString('Unknown user (#' . $member . ')', $data, 'no longer named to this site\'s admin');
        self::assertStringNotContainsString('Member Mia', $data);
    }

    public function testARotationWhileAnotherRunsIsRefusedWithoutChangingTheKey(): void
    {
        HashSeal::createInitialKey();
        $before = get_option('fabricator_forms_seal_key');
        // A rotation running in another request holds the key history's lock (OptionMutex's row).
        global $wpdb;
        $wpdb->insert($wpdb->options, ['option_name' => 'fabricator_lock_opt_fabricator_forms_seal_key_history', 'option_value' => (time() + 60) . ':other', 'autoload' => 'no']);

        $r = $this->ajax('fabricator_forms_rotate_key', ['nonce' => wp_create_nonce('fabricator_rotate_key')]);

        self::assertFalse($r['success']);
        self::assertStringContainsString('Another change to the seal key is in progress', $r['data']['message']);
        self::assertSame($before, get_option('fabricator_forms_seal_key'));

        $wpdb->delete($wpdb->options, ['option_name' => 'fabricator_lock_opt_fabricator_forms_seal_key_history']);
        self::assertTrue($this->ajax('fabricator_forms_rotate_key', ['nonce' => wp_create_nonce('fabricator_rotate_key')])['success'], 'and works once it has finished');
    }

    /**
     * @param array<string, string> $fields
     * @return array{success: bool, data: mixed}
     */
    private function saveSettings(array $fields, string $snapshot): array
    {
        return $this->ajax('fabricator_save_general_settings', $fields + [
            'fabricator_settings_nonce'    => wp_create_nonce('fabricator_forms_settings'),
            'fabricator_settings_snapshot' => $snapshot,
        ]);
    }

    private static function settingsSnapshot(): string
    {
        return (string) Reflect::call(FormSettings::class, 'settingsSnapshot');
    }

    /**
     * The Trusted proxies card of the rendered settings page.
     */
    private function proxyCard(): string
    {
        ob_start();
        FormSettings::renderSettingsPage();
        $page = (string) ob_get_clean();
        $at   = (int) strpos($page, 'id="trusted_proxies"');
        return substr($page, $at, (int) strpos($page, 'fabricator-settings-card', $at) - $at);
    }
}
