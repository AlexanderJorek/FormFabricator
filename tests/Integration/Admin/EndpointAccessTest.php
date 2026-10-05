<?php

namespace FabricatorForms\Tests\Integration\Admin;

use FabricatorForms\Admin;
use FabricatorForms\Tests\Integration\AjaxTestCase;

/**
 * Every AJAX action, screen and form-post handler answers each permission as the access settings say (TESTING.md §7):
 * a user granted one permission reaches exactly its endpoints, a Subscriber none.
 *
 * Calls carry no valid nonce, so nothing changes: a refused user stops at the capability check ("Forbidden"), an
 * allowed one at the nonce check, in Utils\AjaxGuard's order.
 *
 * A new wp_ajax_fabricator_* action fails testEveryActionIsListed() until it is added to ACTIONS with its permission.
 */
final class EndpointAccessTest extends AjaxTestCase
{
    /** The permission each action requires; 'admin' = the settings permission plus manage_options (a real WP admin). */
    private const ACTIONS = [
        'fabricator_forms_save_form'          => 'edit_forms',
        'fabricator_forms_preview'            => 'edit_forms',
        'fabricator_forms_unlock_form'        => 'edit_forms',
        'fabricator_forms_delete'             => 'edit_forms',
        'fabricator_forms_duplicate'          => 'edit_forms',
        'fabricator_forms_bulk_delete'        => 'edit_forms',
        'fabricator_forms_bulk_duplicate'     => 'edit_forms',
        'fabricator_forms_export'             => 'edit_forms',
        'fabricator_forms_import'             => 'edit_forms',
        'fabricator_fsel_save'                => 'edit_forms',
        'fabricator_fsel_delete'              => 'edit_forms',
        'fabricator_save_general_settings'    => 'settings',
        'fabricator_forms_unlock_settings'    => 'settings',
        'fabricator_forms_factory_reset'      => 'admin',
        'fabricator_forms_rotate_key'         => 'admin',
        'fabricator_setup_keep_default'       => 'admin',
        'fabricator_setup_get_master_key'     => 'admin',
        'fabricator_upgrade_get_master_key'   => 'admin',
        'fabricator_setup_confirm_secure'     => 'admin',
        'fabricator_setup_reset_choice'       => 'admin',
        'fabricator_add_legacy_key'           => 'admin',
        'fabricator_peek_key_download'        => 'admin',
        'fabricator_confirm_key_download'     => 'admin',
        'fabricator_save_access_settings'     => 'admin',
        'fabricator_access_user_search'       => 'admin',
        'fabricator_forms_pdf_preview'        => 'edit_pdf_layout',
        'fabricator_save_pdf_layout'          => 'edit_pdf_layout',
        'fabricator_forms_unlock_pdf_layout'  => 'edit_pdf_layout',
        'fabricator_verify_push_lines'        => 'use_verifier',
        'fabricator_verify_progress'          => 'use_verifier',
        'fabricator_serve_pdf'                => 'use_verifier',
    ];

    /** Actions a logged-out visitor uses on purpose: the form submission, its token and an ALTCHA field's challenge. */
    private const PUBLIC_ACTIONS = ['fabricator_forms_submit', 'fabricator_forms_get_token', 'fabricator_altcha_challenge'];

    private const PERMISSIONS = ['view_forms', 'edit_forms', 'use_verifier', 'edit_pdf_layout', 'settings'];

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- overrides WP_UnitTestCase's snake_case fixture method.
    public function set_up(): void
    {
        parent::set_up();
        // The plugin loads the admin classes only when is_admin(), which the test bootstrap is not; hook them as
        // admin-ajax.php would.
        Admin\FormList::init();
        Admin\FormEditor::init();
        Admin\FormSelectList::init();
        Admin\FormSettings::init();
        Admin\PDFLayoutEditor::init();
        Admin\Verificationpage::register();
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- overrides WP_UnitTestCase's snake_case fixture method.
    public function tear_down(): void
    {
        $_POST = [];
        $_GET  = [];
        unset($_SERVER['REQUEST_METHOD']);
        parent::tear_down();
    }

    public function testEveryActionIsListed(): void
    {
        $registered = [];
        foreach (array_keys($GLOBALS['wp_filter']) as $hook) {
            if (preg_match('/^wp_ajax_(fabricator_\w+)$/', (string) $hook, $m)) {
                $registered[] = $m[1];
            }
        }
        $registered = array_values(array_diff($registered, self::PUBLIC_ACTIONS));
        sort($registered);
        $listed = array_keys(self::ACTIONS);
        sort($listed);

        self::assertSame($listed, $registered, 'every admin AJAX action has a permission in ACTIONS, and nothing listed is gone');
    }

    public function testNoAdminActionIsOpenToLoggedOutVisitors(): void
    {
        foreach (array_keys($GLOBALS['wp_filter']) as $hook) {
            if (preg_match('/^wp_ajax_nopriv_(fabricator_\w+)$/', (string) $hook, $m)) {
                self::assertContains($m[1], self::PUBLIC_ACTIONS, "$m[1] is reachable without logging in");
            }
        }
    }

    public function testEachPermissionReachesExactlyItsOwnActions(): void
    {
        foreach ($this->profiles() as $profile => [$user, $holds]) {
            foreach (self::ACTIONS as $action => $needs) {
                wp_set_current_user($user);
                $refused = $this->isRefused($action);
                self::assertSame(!in_array($needs, $holds, true), $refused, sprintf(
                    '%s %s %s (needs %s)',
                    $profile,
                    $refused ? 'was refused' : 'got past the capability check of',
                    $action,
                    $needs
                ));
            }
        }
    }

    public function testEachScreenOpensExactlyForItsPermission(): void
    {
        $screens = [
            'form list'       => [[Admin\FormList::class, 'render'], 'view_forms'],
            'form editor'     => [[Admin\FormEditor::class, 'render'], 'edit_forms'],
            'form selections' => [[Admin\FormSelectList::class, 'render'], 'edit_forms'],
            'settings'        => [[Admin\FormSettings::class, 'renderSettingsPage'], 'settings'],
            'PDF layout'      => [[Admin\PDFLayoutEditor::class, 'render'], 'edit_pdf_layout'],
            'PDF verifier'    => [[Admin\Verificationpage::class, 'render'], 'use_verifier'],
        ];
        foreach ($this->profiles() as $profile => [$user, $holds]) {
            foreach ($screens as $screen => [$callback, $needs]) {
                wp_set_current_user($user);
                $died = self::callPage($callback);
                if (in_array($needs, $holds, true)) {
                    self::assertSame('', $died, "$profile opens the $screen screen");
                } else {
                    self::assertNotSame('', $died, "$profile is refused the $screen screen");
                }
            }
        }
    }

    public function testFormPostHandlersCheckThePermissionFirst(): void
    {
        // Settings and PDF layout also accept a classic form post, and the verifier its uploads, each on the page's load-
        // hook. With a valid nonce, an allowed user's post is handled and redirected (Post/Redirect/Get); a refused user's
        // is not.
        $handlers = [
            'settings post'   => [[Admin\FormSettings::class, 'handleSettingsPost'], 'settings', 'fabricator_settings_nonce', 'fabricator_forms_settings'],
            'PDF layout post' => [[Admin\PDFLayoutEditor::class, 'handleLayoutPost'], 'edit_pdf_layout', 'fabricator_pdf_layout_nonce', 'fabricator_pdf_layout'],
            'verifier upload' => [[Admin\Verificationpage::class, 'handleUploadPost'], 'use_verifier', 'fabricator_verifier_nonce', 'fabricator_verifier_upload'],
        ];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $trap = static function (string $location): string {
            throw new \RuntimeException('redirected to ' . $location);
        };
        add_filter('wp_redirect', $trap);
        foreach ($this->profiles() as $profile => [$user, $holds]) {
            foreach ($handlers as $name => [$callback, $needs, $field, $action]) {
                wp_set_current_user($user);
                $_POST = $_REQUEST = [$field => wp_create_nonce($action), '_wp_http_referer' => '/wp-admin/'];
                $outcome = self::callPage($callback);
                if (in_array($needs, $holds, true)) {
                    self::assertStringStartsWith('redirected to', $outcome, "$profile has the $name handled");
                } else {
                    self::assertStringStartsNotWith('redirected to', $outcome, "$profile is refused the $name");
                }
            }
        }
        remove_filter('wp_redirect', $trap);
    }

    /**
     * Runs a page callback with its output discarded; returns what ended it early (a wp_die() message, a trapped
     * redirect), or '' when it returned normally.
     */
    private static function callPage(callable $callback): string
    {
        $level = ob_get_level();
        ob_start();
        try {
            $callback();
            return '';
        } catch (\WPDieException | \RuntimeException $e) {
            return $e->getMessage();
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }
    }

    /**
     * One user per permission (an Editor granted only that one), a Subscriber, and an administrator.
     *
     * @return array<string, array{0: int, 1: string[]}> Name => [user id, permissions held].
     */
    private function profiles(): array
    {
        $profiles = [];
        $grants   = [];
        foreach (self::PERMISSIONS as $permission) {
            $user = self::factory()->user->create(['role' => 'editor']);
            $grants[$user] = [$permission => true];
            $profiles["an editor granted only $permission"] = [$user, [$permission]];
        }
        update_option('fabricator_forms_access', ['users' => $grants]);
        $profiles['a subscriber'] = [self::factory()->user->create(['role' => 'subscriber']), []];
        $profiles['an administrator'] = [self::factory()->user->create(['role' => 'administrator']), array_merge(self::PERMISSIONS, ['admin'])];
        return $profiles;
    }

    /**
     * Calls $action without a valid nonce and tells whether the capability check stopped it.
     */
    private function isRefused(string $action): bool
    {
        $_POST = ['nonce' => 'invalid', 'form_id' => '1', 'id' => '1', 'token' => 'x'];
        $_GET  = [];
        $this->_last_response = '';
        $died = '';
        try {
            $this->_handleAjax($action);
        } catch (\WPAjaxDieContinueException | \WPAjaxDieStopException $e) {
            $died = $e->getMessage();
        }
        if ($died === 'Forbidden') {
            return true; // wp_die() in the file-serving endpoint
        }
        $response = json_decode($this->_last_response, true);
        if (!is_array($response) || ($response['success'] ?? true) !== false) {
            return false;
        }
        $data = $response['data'] ?? null;
        return $data === [] || (is_array($data) && ($data['message'] ?? '') === 'Forbidden');
    }
}
