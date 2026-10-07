<?php

/**
 * Plugin Name: FormFabricator E2E site
 * Description: Test-only stand-ins for what the E2E site has no real counterpart of. Dev-only, never shipped.
 *
 * - Mail: every wp_mail() is written to WP_CONTENT_DIR/e2e-outbox/ as JSON (recipients, subject, body, attachment
 *   names) instead of being sent; the specs read it there (tests/e2e/support/site.js).
 * - reCAPTCHA: Google's siteverify answers "success" for the token "e2e-pass" and "failure" for any other; nothing
 *   leaves the machine.
 * - An admin notice on every screen, as a core update notice would be, for the notice dock checks.
 */

add_filter('pre_wp_mail', static function ($short_circuit, array $atts) {
    $dir = WP_CONTENT_DIR . '/e2e-outbox';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    $mail = [
        'to'          => array_values((array) $atts['to']),
        'subject'     => (string) $atts['subject'],
        'message'     => (string) $atts['message'],
        'headers'     => $atts['headers'],
        'attachments' => array_map('basename', array_values((array) $atts['attachments'])),
    ];
    file_put_contents($dir . '/' . sprintf('%.6f', microtime(true)) . '-' . wp_rand() . '.json', wp_json_encode($mail));
    return true;
}, 10, 2);

add_filter('pre_http_request', static function ($preempt, array $args, string $url) {
    if (!str_starts_with($url, 'https://www.google.com/recaptcha/api/siteverify')) {
        return $preempt;
    }
    $ok = ($args['body']['response'] ?? '') === 'e2e-pass';
    return [
        'headers'  => [],
        'body'     => wp_json_encode($ok ? ['success' => true, 'hostname' => wp_parse_url(home_url(), PHP_URL_HOST)] : ['success' => false, 'error-codes' => ['invalid-input-response']]),
        'response' => ['code' => 200, 'message' => 'OK'],
        'cookies'  => [],
        'filename' => null,
    ];
}, 10, 3);

add_action('admin_notices', static function (): void {
    echo '<div class="notice notice-warning" id="e2e-sample-notice"><p>E2E sample notice: a core update is available.</p></div>';
});
