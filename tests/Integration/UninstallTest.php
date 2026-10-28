<?php

namespace FabricatorForms\Tests\Integration;

use FabricatorForms\Form\FormModel;
use PHPUnit\Framework\Attributes\Group;

/**
 * Deleting the plugin leaves nothing behind (TESTING.md §8), on every site of a network with WP_MULTISITE=1.
 *
 * uninstall.php declares a global function, so this must stay the only test that includes it.
 */
#[Group('package')]
final class UninstallTest extends TestCase
{
    private const OPTIONS = [
        'fabricator_forms_access',
        'fabricator_forms_pdf_layout',
        'fabricator_forms_seal_key',
        'fabricator_forms_seal_key_history',
        'fabricator_forms_trusted_proxies',
        'fabricator_verifier_sweep_due',
        'fabricator_forms_mail_failures',
    ];

    private const ROW_PREFIXES = ['fabricator_rl_', 'fabricator_su_', 'fabricator_cs_', 'fabricator_lock_'];

    public function testUninstallRemovesEveryTraceOnEverySite(): void
    {
        $admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin);
        update_user_meta($admin, 'fabricator_uploads_notice_dismissed', '1');

        $sites = [get_current_blog_id()];
        if (is_multisite()) {
            // Roles are per site on a network; a network admin is who deletes a network-activated plugin.
            grant_super_admin($admin);
            $sites[] = self::factory()->blog->create();
        }
        foreach ($sites as $site) {
            $this->onSite($site, fn() => $this->plantData());
        }
        if (!defined('WP_UNINSTALL_PLUGIN')) {
            define('WP_UNINSTALL_PLUGIN', 'formfabricator/formfabricator.php');
        }
        // On a server where WordPress writes files over FTP, which needs credentials an uninstall can't ask for: the
        // plugin's folder holds copies with submission data and must go all the same.
        $ftp = static fn(): string => 'ftpext';
        add_filter('filesystem_method', $ftp);
        try {
            // The loaded plugin's own: the repository's, or the release zip's (Support\Package).
            require FABRICATOR_FORMS_PATH . 'uninstall.php';
        } finally {
            remove_filter('filesystem_method', $ftp);
        }

        foreach ($sites as $site) {
            $this->onSite($site, fn() => $this->assertNothingLeft('site ' . $site));
        }
        self::assertSame('', get_user_meta($admin, 'fabricator_uploads_notice_dismissed', true));
    }

    /**
     * Runs $fn on $site: switched to it on a network, directly on a single site (where switch_to_blog() does not exist).
     */
    private function onSite(int $site, callable $fn): void
    {
        if (!is_multisite()) {
            $fn();
            return;
        }
        switch_to_blog($site);
        try {
            $fn();
        } finally {
            restore_current_blog();
        }
    }

    private function plantData(): void
    {
        global $wpdb;
        $form = FormModel::save(['title' => 'To be removed', 'fields' => [], 'notifications' => [], 'settings' => []], 0);
        self::assertIsInt($form);
        foreach (self::OPTIONS as $option) {
            update_option($option, 'x');
        }
        foreach (self::ROW_PREFIXES as $prefix) {
            $wpdb->insert($wpdb->options, ['option_name' => $prefix . 'planted', 'option_value' => '1|1', 'autoload' => 'no']);
        }
        // A form editor's dismissal of the delivery-failure notice, stored per site.
        update_user_option(get_current_user_id(), 'fabricator_mail_failures_seen', time());
        set_transient('fabricator_uploads_probe', 'x');
        set_transient('fabricator_pdf_randomtoken', 'x');
        set_transient('fabricator_vpending_1', ['tokens' => []]);
        wp_schedule_event(time() + 3600, 'hourly', 'fabricator_rl_sweep_expired');
        wp_schedule_single_event(time() + 600, 'fabricator_verifier_sweep_expired');
        // Attachment copies a killed request left in the site's folder, and its ensure() transient.
        $left = \FabricatorForms\Utils\Storage\PrivateDir::ensure() . '/mail/' . bin2hex(random_bytes(16));
        mkdir($left . '/f1', 0700, true);
        file_put_contents($left . '/f1/passport.jpg', 'x');
    }

    private function assertNothingLeft(string $where): void
    {
        global $wpdb;
        $forms = get_posts(['post_type' => 'fabricator_form', 'post_status' => 'any', 'fields' => 'ids', 'numberposts' => -1]);
        self::assertSame([], $forms, "$where: forms deleted");
        wp_cache_delete('alloptions', 'options');
        foreach (self::OPTIONS as $option) {
            self::assertFalse(get_option($option), "$where: option $option");
        }
        $left = $wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", '%' . $wpdb->esc_like('fabricator') . '%'));
        self::assertSame([], $left, "$where: no fabricator rows left in the options table");
        self::assertFalse(get_user_option('fabricator_mail_failures_seen', get_current_user_id()), "$where: the notice dismissal");
        self::assertFalse(wp_next_scheduled('fabricator_rl_sweep_expired'), "$where: hourly sweep unscheduled");
        self::assertFalse(wp_next_scheduled('fabricator_verifier_sweep_expired'), "$where: one-off event unscheduled");
        clearstatcache();
        self::assertDirectoryDoesNotExist(\FabricatorForms\Utils\Storage\PrivateDir::base(), "$where: the plugin's folder in the uploads directory");
    }
}
