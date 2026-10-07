<?php

namespace FabricatorForms\Tests\Integration;

use FabricatorForms\Plugin;
use PHPUnit\Framework\Attributes\Group;

/**
 * Activation and deactivation, single site and network (TESTING.md §0): activation schedules the sweeps (network
 * sites on their first admin page); deactivation clears every event on every site and sweeps once more.
 */
#[Group('package')]
final class ActivationTest extends TestCase
{
    private const ALL_HOOKS = [...Plugin::CRON_HOOKS, ...Plugin::ONE_OFF_CRON_HOOKS];

    public function testActivationSchedulesEveryRecurringSweep(): void
    {
        self::clearAll();

        do_action('activate_' . self::basename(), false);

        foreach (Plugin::CRON_HOOKS as $hook) {
            self::assertNotFalse(wp_next_scheduled($hook), "$hook scheduled");
        }
    }

    public function testDeactivationClearsEverySweepAndOneOffEvent(): void
    {
        Plugin::scheduleSweeps();
        self::scheduleOneOffs();

        do_action('deactivate_' . self::basename(), false);

        foreach (self::ALL_HOOKS as $hook) {
            self::assertFalse(wp_next_scheduled($hook), "$hook cleared");
        }
    }

    public function testDeactivationRemovesTheAttachmentCopiesAKilledRequestLeftButNotOneStillInUse(): void
    {
        $left  = self::attachmentCopies(time() - 20 * MINUTE_IN_SECONDS);
        $fresh = self::attachmentCopies(time());

        do_action('deactivate_' . self::basename(), false);

        self::assertDirectoryDoesNotExist($left, 'left by a killed request');
        self::assertFileExists($fresh . '/0/form.pdf', 'a submission may still be sending it');
        \FabricatorForms\Form\MailSender::removeTempTree($fresh);
    }

    /**
     * A mail-attachment folder in the system temp dir as MailSender makes one (a file in a numbered subfolder), last
     * changed at $mtime.
     */
    private static function attachmentCopies(int $mtime): string
    {
        $dir = untrailingslashit(get_temp_dir()) . '/fabricator_test' . wp_generate_password(8, false, false);
        mkdir($dir . '/0', 0700, true);
        file_put_contents($dir . '/0/form.pdf', '%PDF-1.4');
        touch($dir, $mtime);
        clearstatcache();
        return $dir;
    }

    public function testNetworkActivationReachesEverySiteByItsFirstAdminPage(): void
    {
        if (!is_multisite()) {
            self::markTestSkipped('Multisite only: run with WP_MULTISITE=1.');
        }
        $other = self::factory()->blog->create();
        switch_to_blog($other);
        self::clearAll();
        restore_current_blog();

        do_action('activate_' . self::basename(), true);

        // Activation ran on the main site only; the other site schedules on its first admin page (admin_init).
        switch_to_blog($other);
        Plugin::scheduleSweeps();
        foreach (Plugin::CRON_HOOKS as $hook) {
            self::assertNotFalse(wp_next_scheduled($hook), "$hook scheduled on site $other");
        }
        restore_current_blog();
    }

    public function testNetworkDeactivationClearsEventsOnEverySite(): void
    {
        if (!is_multisite()) {
            self::markTestSkipped('Multisite only: run with WP_MULTISITE=1.');
        }
        $sites = [get_current_blog_id(), self::factory()->blog->create(), self::factory()->blog->create()];
        foreach ($sites as $site) {
            switch_to_blog($site);
            Plugin::scheduleSweeps();
            self::scheduleOneOffs();
            restore_current_blog();
        }

        do_action('deactivate_' . self::basename(), true);

        foreach ($sites as $site) {
            switch_to_blog($site);
            foreach (self::ALL_HOOKS as $hook) {
                self::assertFalse(wp_next_scheduled($hook), "$hook cleared on site $site");
            }
            restore_current_blog();
        }
    }

    public function testASingleSiteDeactivationOnANetworkLeavesTheOtherSitesAlone(): void
    {
        if (!is_multisite()) {
            self::markTestSkipped('Multisite only: run with WP_MULTISITE=1.');
        }
        $other = self::factory()->blog->create();
        switch_to_blog($other);
        Plugin::scheduleSweeps();
        restore_current_blog();
        Plugin::scheduleSweeps();

        do_action('deactivate_' . self::basename(), false);

        self::assertFalse(wp_next_scheduled(Plugin::CRON_HOOKS[0]), 'cleared here');
        switch_to_blog($other);
        self::assertNotFalse(wp_next_scheduled(Plugin::CRON_HOOKS[0]), 'still scheduled where the plugin stays active');
        restore_current_blog();
    }

    private static function basename(): string
    {
        return plugin_basename(FABRICATOR_FORMS_PATH . 'formfabricator.php');
    }

    private static function clearAll(): void
    {
        foreach (self::ALL_HOOKS as $hook) {
            wp_clear_scheduled_hook($hook);
        }
    }

    private static function scheduleOneOffs(): void
    {
        foreach (Plugin::ONE_OFF_CRON_HOOKS as $hook) {
            wp_schedule_single_event(time() + 600, $hook);
        }
    }
}
