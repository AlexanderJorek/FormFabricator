<?php

namespace FabricatorForms\Tests\Integration\Admin;

use FabricatorForms\Plugin;
use FabricatorForms\Tests\Integration\TestCase;

/**
 * The notice with the server rule for the protected PDF folder (TESTING.md §1). The loopback probe answers through
 * pre_http_request: only a 403 is protected, the file's content is exposed, anything else proves nothing.
 */
final class UploadsProbeTest extends TestCase
{
    private const TRANSIENT = 'fabricator_uploads_probe';

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- overrides WP_UnitTestCase's snake_case fixture method.
    public function set_up(): void
    {
        parent::set_up();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        delete_transient(self::TRANSIENT);
        wp_clear_scheduled_hook(Plugin::UPLOADS_PROBE_HOOK);
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- overrides WP_UnitTestCase's snake_case fixture method.
    public function tear_down(): void
    {
        unset($_SERVER['SERVER_SOFTWARE']);
        remove_all_filters('pre_http_request');
        parent::tear_down();
    }

    public function testAFirstLoadQueuesTheProbeAndFallsBackToTheServerName(): void
    {
        $_SERVER['SERVER_SOFTWARE'] = 'nginx/1.25.3';
        self::assertStringContainsString('reads neither .htaccess nor web.config', $this->notice());
        self::assertNotFalse(wp_next_scheduled(Plugin::UPLOADS_PROBE_HOOK), 'the probe is queued, not run inline');

        $_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.58';
        self::assertSame('', $this->notice(), 'plain Apache honours .htaccess');
    }

    public function testTheFilesOwnContentComingBackMeansExposedForADay(): void
    {
        // Nginx serving static files in front of Apache (Plesk, cPanel): SERVER_SOFTWARE says Apache.
        $_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.58';
        $this->answer(static fn(string $url): array => self::response(200, self::probeFileContent($url)));

        Plugin::runUploadsProbe();

        self::assertSame('exposed', get_transient(self::TRANSIENT));
        self::assertEqualsWithDelta(DAY_IN_SECONDS, $this->transientLifetime(), 5);
        $notice = $this->notice();
        self::assertStringContainsString('could be downloaded from outside', $notice);
        self::assertStringContainsString('location ^~ ', $notice, 'with the Nginx rule');
        self::assertStringContainsString('/fabricator-secure-pdf/', $notice);
    }

    public function testA403MeansProtectedForADay(): void
    {
        $_SERVER['SERVER_SOFTWARE'] = 'nginx/1.25.3';
        $this->answer(static fn(): array => self::response(403, 'Forbidden'));

        Plugin::runUploadsProbe();

        self::assertSame('protected', get_transient(self::TRANSIENT));
        self::assertEqualsWithDelta(DAY_IN_SECONDS, $this->transientLifetime(), 5);
        self::assertSame('', $this->notice(), 'a real 403 outranks the server name');
    }

    /**
     * @return iterable<string, array{0: callable}>
     */
    public static function inconclusiveAnswers(): iterable
    {
        yield 'timeout' => [static fn() => new \WP_Error('http_request_failed', 'cURL error 28: Operation timed out')];
        yield '401 basic auth' => [static fn() => self::response(401, 'Unauthorized')];
        yield '404 from another vhost' => [static fn() => self::response(404, 'Not Found')];
        yield '500' => [static fn() => self::response(500, 'Internal Server Error')];
        yield '503' => [static fn() => self::response(503, 'Service Unavailable')];
        yield 'WAF challenge page with 200' => [static fn() => self::response(200, '<html>Checking your browser…</html>')];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('inconclusiveAnswers')]
    public function testAnInconclusiveAnswerIsRetriedWithinTheHour(callable $answer): void
    {
        $_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.58';
        $this->answer($answer);

        Plugin::runUploadsProbe();

        self::assertSame('', get_transient(self::TRANSIENT));
        self::assertEqualsWithDelta(HOUR_IN_SECONDS, $this->transientLifetime(), 5);
        self::assertSame('', $this->notice(), 'unknown, so the server name decides: Apache is fine');
        self::assertFalse(wp_next_scheduled(Plugin::UPLOADS_PROBE_HOOK), 'the cached answer is used, no new probe queued');
    }

    public function testTheProbeFileIsRemovedAfterwards(): void
    {
        $this->answer(static fn(string $url): array => self::response(200, self::probeFileContent($url)));
        Plugin::runUploadsProbe();

        self::assertSame([], glob(wp_upload_dir()['basedir'] . '/fabricator-secure-pdf/probe-*.txt') ?: []);
    }

    public function testAnotherUsersViewAndADismissalHideTheNotice(): void
    {
        $this->answer(static fn(string $url): array => self::response(200, self::probeFileContent($url)));
        Plugin::runUploadsProbe();
        self::assertNotSame('', $this->notice());

        update_user_meta(get_current_user_id(), 'fabricator_uploads_notice_dismissed', time() + DAY_IN_SECONDS);
        self::assertSame('', $this->notice(), 'dismissed for 30 days');

        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        self::assertSame('', $this->notice(), 'only administrators see it');
    }

    public function testTheNoticeShowsOnThePluginsScreensOnly(): void
    {
        // Shown on every admin screen, and back every 30 days, it read as a nag (WordPress.org guideline 11).
        $this->answer(static fn(string $url): array => self::response(200, self::probeFileContent($url)));
        Plugin::runUploadsProbe();
        self::assertNotSame('', $this->notice('toplevel_page_fabricator-forms'), "this plugin's screens");
        self::assertNotSame('', $this->notice('plugins'), 'the Plugins list');
        self::assertSame('', $this->notice('dashboard'));
        self::assertSame('', $this->notice('edit-post'));
    }

    /**
     * Answers the probe's loopback request (and only that) through $answer, given the URL.
     */
    private function answer(callable $answer): void
    {
        add_filter('pre_http_request', static function ($pre, array $args, string $url) use ($answer) {
            return str_contains($url, '/fabricator-secure-pdf/probe-') ? $answer($url) : $pre;
        }, 10, 3);
    }

    /**
     * What a server that hands out the folder's files would send back: the probe file itself, read from disk.
     */
    private static function probeFileContent(string $url): string
    {
        $uploads = wp_upload_dir();
        $path    = $uploads['basedir'] . substr($url, strlen(rtrim($uploads['baseurl'], '/')));
        return (string) file_get_contents($path);
    }

    /**
     * @return array{headers: array, body: string, response: array{code: int, message: string}, cookies: array, filename: null}
     */
    private static function response(int $code, string $body): array
    {
        return ['headers' => [], 'body' => $body, 'response' => ['code' => $code, 'message' => ''], 'cookies' => [], 'filename' => null];
    }

    private function notice(string $screen = 'toplevel_page_fabricator-forms'): string
    {
        set_current_screen($screen);
        ob_start();
        Plugin::maybeWarnUnprotectedUploads();
        return (string) ob_get_clean();
    }

    private function transientLifetime(): int
    {
        return (int) get_option('_transient_timeout_' . self::TRANSIENT) - time();
    }
}
