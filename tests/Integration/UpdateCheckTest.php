<?php

namespace FabricatorForms\Tests\Integration;

/**
 * The test site never reaches wordpress.org: WordPress's update checks get an empty answer from the bootstrap, so a run
 * doesn't fail or pass by the network (an unreachable wordpress.org made WordPress warn, which fails the run).
 */
final class UpdateCheckTest extends AjaxTestCase
{
    public function testWordPresssUpdateChecksAreAnsweredLocallyAndQuietly(): void
    {
        $answered = [];
        // After the bootstrap's answer: a request that would go out arrives here with $pre still false.
        add_filter('pre_http_request', static function ($pre, $args, $url) use (&$answered) {
            $answered[] = $pre !== false;
            return $pre !== false ? $pre : new \WP_Error('http_request_failed', 'unreachable');
        }, 99, 3);
        delete_site_transient('update_core');
        delete_site_transient('update_plugins');
        delete_site_transient('update_themes');

        wp_version_check();
        wp_update_plugins();
        wp_update_themes();

        self::assertNotEmpty($answered, 'WordPress asked');
        self::assertNotContains(false, $answered, 'and every request was answered before it went out');
    }
}
