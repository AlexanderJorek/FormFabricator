<?php

namespace FabricatorForms\Tests\Integration\Admin;

use FabricatorForms\Admin\FormSettings;
use FabricatorForms\PDF\HashSeal;
use FabricatorForms\Plugin;
use FabricatorForms\Tests\Integration\AjaxTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The one-time PDF seal key setup and what a broken key does afterwards (TESTING.md §1): every FormFabricator screen
 * leads to Settings until the key exists, the new key is offered for backup, and a missing or damaged key warns the
 * admin and refuses sealed submissions until "Rotate PDF key" replaces it.
 */
#[Group('package')]
final class FirstRunTest extends AjaxTestCase
{
    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- overrides WP_UnitTestCase's snake_case fixture method.
    public function set_up(): void
    {
        parent::set_up();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        // The plugin loads the admin classes only when is_admin(), which the test bootstrap is not; hook them as
        // admin-ajax.php would.
        FormSettings::init();
        delete_option('fabricator_forms_seal_setup_done');
        delete_option('fabricator_forms_seal_encryption');
        delete_option('fabricator_forms_seal_key');
        delete_option('fabricator_forms_seal_key_history');
        delete_transient('fabricator_forms_seal_key_pending_download');
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- overrides WP_UnitTestCase's snake_case fixture method.
    public function tear_down(): void
    {
        $_GET = [];
        unset($_SERVER['REQUEST_METHOD']);
        parent::tear_down();
    }

    public function testEveryFormFabricatorScreenLeadsToSettingsUntilTheKeyIsSetUp(): void
    {
        $settings = admin_url('admin.php?page=fabricator-forms-settings');
        // The verifier included: its slug does not start with "fabricator-forms", which the redirect once required.
        foreach (['fabricator-forms', 'fabricator-forms-editor', 'fabricator-forms-select', 'fabricator-forms-pdf-layout', 'fabricator-pdf-verification'] as $page) {
            self::assertSame($settings, $this->setupRedirectFor($page), "$page before setup: form creation stays blocked");
        }
        self::assertNull($this->setupRedirectFor('fabricator-forms-settings'), 'Settings itself opens, with the setup dialog');
        self::assertNull($this->setupRedirectFor('another-plugin'), 'other plugins are left alone');

        $this->ajax('fabricator_setup_keep_default', ['nonce' => wp_create_nonce('fabricator_seal_setup')]);

        self::assertNull($this->setupRedirectFor('fabricator-forms-editor'), 'after setup the editor opens');
    }

    public function testTheSettingsPageShowsTheSetupDialogOnlyBeforeSetup(): void
    {
        self::assertStringContainsString('id="fabricator-setup-blocker"', $this->settingsPage());

        $this->ajax('fabricator_setup_keep_default', ['nonce' => wp_create_nonce('fabricator_seal_setup')]);

        self::assertStringNotContainsString('id="fabricator-setup-blocker"', $this->settingsPage());
    }

    public function testTheNewKeyIsOfferedForBackupUntilItsDownloadIsConfirmed(): void
    {
        $setup = $this->ajax('fabricator_setup_keep_default', ['nonce' => wp_create_nonce('fabricator_seal_setup')]);
        self::assertTrue($setup['success'], wp_json_encode($setup));
        $key = $setup['data'];
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $key['key']);
        self::assertSame(HashSeal::keyFingerprint($key['key']), $key['fingerprint'], 'the backup carries the key\'s fingerprint');

        // Reloading the page must not use up the one chance to save the key: peeking twice returns it twice.
        $nonce = wp_create_nonce('fabricator_forms_admin_nonce');
        self::assertSame($key['key'], $this->ajax('fabricator_peek_key_download', ['nonce' => $nonce])['data']['key']);
        self::assertSame($key['key'], $this->ajax('fabricator_peek_key_download', ['nonce' => $nonce])['data']['key']);

        self::assertTrue($this->ajax('fabricator_confirm_key_download', ['nonce' => $nonce])['success']);
        self::assertFalse($this->ajax('fabricator_peek_key_download', ['nonce' => $nonce])['success'], 'gone once saved');
    }

    /**
     * @return iterable<string, array{0: callable}>
     */
    public static function brokenKeys(): iterable
    {
        yield 'deleted' => [static fn() => delete_option('fabricator_forms_seal_key')];
        yield 'not JSON' => [static fn() => update_option('fabricator_forms_seal_key', 'garbage', false)];
        yield 'no key in it' => [static fn() => update_option('fabricator_forms_seal_key', wp_json_encode(['uuid' => wp_generate_uuid4()]), false)];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('brokenKeys')]
    public function testABrokenKeyWarnsTheAdminAndRefusesSealedSubmissionsUntilRotated(callable $break): void
    {
        $this->ajax('fabricator_setup_keep_default', ['nonce' => wp_create_nonce('fabricator_seal_setup')]);
        $admin = get_current_user_id();
        $form  = $this->createForm(
            [['id' => 'name', 'type' => 'text', 'label' => 'Name']],
            [self::notification(['attach_pdf' => true])]
        );
        self::assertSame('', $this->sealKeyNotice(), 'no notice while the key is fine');
        $break();

        self::assertStringContainsString('forms that attach a sealed PDF cannot be submitted', $this->sealKeyNotice());
        $refused = $this->submit($form, ['name' => 'Ada']);
        self::assertFalse($refused['success'], 'no unsealed or unsigned PDF goes out');
        self::assertStringContainsString('Please try again later', $refused['data']['message']);
        self::assertSame([], self::sentMail());

        wp_set_current_user($admin);
        $rotated = $this->ajax('fabricator_forms_rotate_key', ['nonce' => wp_create_nonce('fabricator_rotate_key')]);
        self::assertTrue($rotated['success'], wp_json_encode($rotated));

        self::assertSame('', $this->sealKeyNotice(), 'the notice is gone after the rotation');
        self::assertTrue($this->submit($form, ['name' => 'Ada'])['success'], 'sealed submissions work again');
        self::assertCount(1, self::sentMail());
    }

    public function testNoNoticeBeforeSetupWhenThereIsNoKeyYetByDesign(): void
    {
        self::assertSame('', $this->sealKeyNotice());
    }

    /**
     * Where maybeSealSetupRedirect() sends an admin who opens $page with a GET request, or null when it lets them stay.
     */
    private function setupRedirectFor(string $page): ?string
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = ['page' => $page];
        $trap = static function (string $location): string {
            throw new \RuntimeException($location);
        };
        add_filter('wp_redirect', $trap);
        // A page load, not the AJAX request WP_Ajax_UnitTestCase pretends every test is.
        add_filter('wp_doing_ajax', '__return_false', 99);
        try {
            Plugin::maybeSealSetupRedirect();
            return null;
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        } finally {
            remove_filter('wp_redirect', $trap);
            remove_filter('wp_doing_ajax', '__return_false', 99);
        }
    }

    private function sealKeyNotice(): string
    {
        wp_set_current_user(get_current_user_id() ?: self::factory()->user->create(['role' => 'administrator']));
        ob_start();
        Plugin::maybeWarnSealKeyUnusable();
        return (string) ob_get_clean();
    }

    private function settingsPage(): string
    {
        ob_start();
        FormSettings::renderSettingsPage();
        return (string) ob_get_clean();
    }
}
