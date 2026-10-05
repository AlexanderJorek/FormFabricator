<?php

namespace FabricatorForms\Tests\Integration\Admin;

use FabricatorForms\Admin;
use FabricatorForms\Plugin;
use FabricatorForms\Tests\Integration\TestCase;

/**
 * Who may reach which FormFabricator screen (TESTING.md §7). The admin pages are registered with
 * "fabricator_access_<cap>" capabilities that Plugin::grantAccessCaps() derives from the access settings, so WordPress
 * itself refuses a Subscriber who opens a page URL directly — the page callbacks' own checks are a second line.
 */
final class AccessControlTest extends TestCase
{
    public function tear_down(): void // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WP_UnitTestCase fixture method.
    {
        unset($GLOBALS['menu'], $GLOBALS['submenu'], $GLOBALS['_registered_pages'], $GLOBALS['_parent_pages']);
        parent::tear_down();
    }

    public function testEveryFormFabricatorScreenRequiresAnAccessCapability(): void
    {
        $GLOBALS['menu']    = [];
        $GLOBALS['submenu'] = [];
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        set_current_screen('dashboard');

        Admin\FormList::menu();
        Admin\FormEditor::menu();
        Admin\FormSelectList::menu();
        Admin\FormSettings::addSettingsPage();
        Admin\PDFLayoutEditor::addPage();
        Admin\Verificationpage::menu();

        $pages = [];
        foreach ($GLOBALS['menu'] as $item) {
            if (str_starts_with((string) ($item[2] ?? ''), 'fabricator')) {
                $pages[$item[2]] = $item[1];
            }
        }
        foreach ($GLOBALS['submenu'] as $items) {
            foreach ($items as $item) {
                if (str_starts_with((string) ($item[2] ?? ''), 'fabricator')) {
                    $pages[$item[2]] = $item[1];
                }
            }
        }

        self::assertGreaterThanOrEqual(5, count($pages), 'screens registered: ' . implode(', ', array_keys($pages)));
        $known = array_map(static fn($c) => Plugin::ACCESS_CAP_PREFIX . $c, ['view_forms', 'edit_forms', 'use_verifier', 'edit_pdf_layout', 'settings']);
        foreach ($pages as $slug => $capability) {
            self::assertContains($capability, $known, "$slug is registered with '$capability'");
        }
    }

    public function testAUserAllowedOnlyTheVerifierStillGetsTheMenu(): void
    {
        // The top-level menu was registered for view_forms only, so this user's verifier page had no menu entry at all.
        // Registered, WordPress points the menu at the first page the user may open (wp-admin/includes/menu.php).
        $GLOBALS['menu']    = [];
        $GLOBALS['submenu'] = [];
        $editor = self::factory()->user->create(['role' => 'editor']);
        update_option('fabricator_forms_access', ['roles' => ['editor' => ['use_verifier' => true]]]);
        wp_set_current_user($editor);
        set_current_screen('dashboard');

        Admin\FormList::menu();
        Admin\Verificationpage::menu();

        self::assertContains('fabricator-forms', array_column($GLOBALS['menu'], 2), 'the top-level menu is registered');
        $subs = array_column($GLOBALS['submenu']['fabricator-forms'] ?? [], 1);
        self::assertContains(Plugin::ACCESS_CAP_PREFIX . 'use_verifier', $subs, 'with the verifier page this user may open');
    }

    public function testCreatingFormsIsAnsweredForTheUserAskedAbout(): void
    {
        // map_meta_cap passes the user being checked; the filter used the current user instead.
        $editor = self::factory()->user->create(['role' => 'editor']);
        update_option('fabricator_forms_access', ['roles' => ['editor' => ['edit_forms' => true]]]);
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));

        self::assertTrue(user_can($editor, 'create_fabricator_forms'));
        self::assertFalse(current_user_can('create_fabricator_forms'));
    }

    public function testASubscriberHoldsNoAccessCapability(): void
    {
        $subscriber = self::factory()->user->create(['role' => 'subscriber']);
        foreach (['view_forms', 'edit_forms', 'use_verifier', 'edit_pdf_layout', 'settings'] as $cap) {
            self::assertFalse(user_can($subscriber, Plugin::ACCESS_CAP_PREFIX . $cap), $cap);
            self::assertFalse(Plugin::userCan($cap, $subscriber), $cap);
        }
    }

    public function testAnAdministratorHoldsEveryAccessCapability(): void
    {
        $admin = self::factory()->user->create(['role' => 'administrator']);
        self::assertTrue(user_can($admin, Plugin::ACCESS_CAP_PREFIX . 'use_verifier'));
        self::assertTrue(user_can($admin, Plugin::ACCESS_CAP_PREFIX . 'edit_forms'));
    }

    public function testARoleGrantedInTheAccessSettingsGetsExactlyThatCapability(): void
    {
        $editor = self::factory()->user->create(['role' => 'editor']);
        update_option('fabricator_forms_access', ['roles' => ['editor' => ['use_verifier' => true]]]);

        self::assertTrue(user_can($editor, Plugin::ACCESS_CAP_PREFIX . 'use_verifier'));
        self::assertFalse(user_can($editor, Plugin::ACCESS_CAP_PREFIX . 'edit_forms'));
    }

    public function testAPerUserGrantAddsToTheRoleAndDoesNotReplaceIt(): void
    {
        // "A per-user entry GRANTS on top of the role, it does not replace it" (Plugin::userCan()).
        $author = self::factory()->user->create(['role' => 'author']);
        update_option('fabricator_forms_access', [
            'roles' => ['author' => ['edit_forms' => true]],
            'users' => [$author => ['use_verifier' => true]],
        ]);

        self::assertTrue(user_can($author, Plugin::ACCESS_CAP_PREFIX . 'edit_forms'), 'kept from the role');
        self::assertTrue(user_can($author, Plugin::ACCESS_CAP_PREFIX . 'use_verifier'), 'added for the user');
    }

    public function testNobodyLoggedOutHoldsAnAccessCapability(): void
    {
        update_option('fabricator_forms_access', ['roles' => ['subscriber' => ['use_verifier' => true]]]);
        wp_set_current_user(0);

        self::assertFalse(current_user_can(Plugin::ACCESS_CAP_PREFIX . 'use_verifier'));
        self::assertFalse(Plugin::userCan('use_verifier'));
    }

    public function testASubscriberCannotSaveAForm(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        $result = \FabricatorForms\Form\FormModel::save(['title' => 'x', 'fields' => [], 'notifications' => [], 'settings' => []], 0, true);

        self::assertWPError($result);
        self::assertSame('forbidden', $result->get_error_code());
    }
}
