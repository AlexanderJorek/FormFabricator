<?php

/**
 * The admin notices for problems that lose submissions: unprotected PDFs, an unusable seal key, failed delivery.
 *
 * PHP Version 8.1
 *
 * @category  FormFabricator
 * @package   FormFabricator
 * @author    Alexander Jorek
 * @copyright 2026 Alexander Jorek
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 * @version   1.1.1
 * @link      https://github.com/AlexanderJorek/FormFabricator
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 3
 * of the License, or (at your option) any later version.
 */

namespace FabricatorForms\Admin;

defined('ABSPATH') || exit;

/**
 * Admin notices for what would otherwise only reach the server log: generated PDFs a web server hands out (found by a
 * loopback probe, queued as a one-off cron event), a seal key that can't seal, and submissions that could not be
 * delivered. Each can be dismissed, but never hidden for good while the problem lasts.
 */
class Notices
{
    /* Web servers that serve PHP directly and do not read .htaccess. Nginx behind Apache would
       report Apache here, so a match means PHP really is being served by the listed server. */
    private const HTACCESS_BLIND_SERVERS = ['nginx', 'openresty', 'caddy', 'lighttpd'];

    /* Servers known to honour .htaccess or web.config. Anything in neither list is unknown, and
       is logged rather than warned about. */
    private const HTACCESS_HONOURING_SERVERS = ['apache', 'litespeed', 'iis'];

    private const UPLOADS_NOTICE_META    = 'fabricator_uploads_notice_dismissed';
    private const UPLOADS_NOTICE_DISMISS = 'fabricator_dismiss_uploads_notice';

    /**
     * Records a 30-day dismissal of the unprotected-uploads notice; not permanent since the exposure is still real.
     *
     * @return void
     */
    public static function maybeDismissUploadsNotice(): void
    {
        if (!isset($_GET[self::UPLOADS_NOTICE_DISMISS]) || !current_user_can('manage_options')) {
            return;
        }
        check_admin_referer(self::UPLOADS_NOTICE_DISMISS);
        update_user_meta(get_current_user_id(), self::UPLOADS_NOTICE_META, time() + (30 * DAY_IN_SECONDS));
        wp_safe_redirect(remove_query_arg([self::UPLOADS_NOTICE_DISMISS, '_wpnonce']));
        exit;
    }

    /**
     * Warns when the server ignores both deny-rule files (.htaccess/web.config) protecting the plugin's PDF directory.
     *
     * @return void
     */
    public static function maybeWarnUnprotectedUploads(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        // On this plugin's own screens and the Plugins list only: on every admin screen, and back every 30 days, it
        // would be the kind of nag WordPress.org's guideline 11 asks plugins not to put up.
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || (!str_contains((string) $screen->id, 'fabricator') && $screen->base !== 'plugins')) {
            return;
        }
        // Stored as an expiry timestamp, so the dismissal lapses rather than silencing the
        // notice permanently.
        if ((int) get_user_meta(get_current_user_id(), self::UPLOADS_NOTICE_META, true) > time()) {
            return;
        }
        // A real request first: SERVER_SOFTWARE alone never saw Nginx serving uploads in front of Apache (Plesk, cPanel),
        // where it reads "Apache" while Nginx hands out the files itself and ignores .htaccess.
        $exposure = self::uploadsExposure();
        if ($exposure === 'protected' || ($exposure === '' && !self::serverIgnoresDenyFiles())) {
            return;
        }

        // Derived from the uploads URL, not basedir: avoids handing an absolute filesystem path to a rule that matches nothing.
        $upload_dir = wp_upload_dir();
        $url_path   = (string) wp_parse_url((string) ($upload_dir['baseurl'] ?? ''), PHP_URL_PATH);
        if ($url_path === '') {
            return;
        }
        $path = rtrim($url_path, '/') . '/' . \FabricatorForms\Utils\Storage\PrivateDir::NAME . '/';

        $dismiss = wp_nonce_url(
            add_query_arg(self::UPLOADS_NOTICE_DISMISS, '1'),
            self::UPLOADS_NOTICE_DISMISS
        );

        // fabricator-uploads-notice: a styling hook only; no notice is hidden on this plugin's screens (see admin.css).
        // Not is-dismissible: that X only hid the notice until the next page load. The "Dismiss for 30 days" link below saves it.
        echo '<div class="notice notice-warning fabricator-uploads-notice"><p><strong>'
            . esc_html__('FormFabricator: generated PDFs are not protected by a server rule.', 'formfabricator')
            . '</strong></p><p>'
            . ($exposure === 'exposed'
                ? esc_html__(
                    'A test file placed in this folder could be downloaded from outside, so the deny rules this plugin writes have no effect here, for example because Nginx serves uploaded files in front of Apache.',
                    'formfabricator'
                )
                : esc_html__(
                    'This site serves PHP with a web server that reads neither .htaccess nor web.config, so the deny rules this plugin writes have no effect.',
                    'formfabricator'
                ))
            . ' '
            . esc_html__(
                'Generated PDFs contain submitted personal data and are currently reachable by anyone who knows or guesses a file URL. Add a deny rule for this path to your server configuration:',
                'formfabricator'
            )
            . '</p><p><code>' . esc_html($path) . '</code></p><p>'
            . esc_html__('Nginx:', 'formfabricator') . ' <code>'
            . esc_html('location ^~ ' . $path . ' { deny all; }')
            . '</code><br>'
            . esc_html__('Caddy:', 'formfabricator') . ' <code>'
            . esc_html('respond ' . $path . '* 403')
            . '</code></p><p><a href="' . esc_url($dismiss) . '">'
            . esc_html__('Dismiss this notice for 30 days', 'formfabricator')
            . '</a></p></div>';
    }

    /**
     * Transient caching probeUploadsExposure()'s answer.
     *
     * @var string
     */
    private const UPLOADS_PROBE_TRANSIENT = 'fabricator_uploads_probe';

    /**
     * One-off cron event that runs the probe in the background.
     *
     * @var string
     */
    public const UPLOADS_PROBE_HOOK = 'fabricator_uploads_probe_run';

    /**
     * Whether the PDF folder can be downloaded from, as last probed: 'exposed', 'protected', or '' when unknown.
     *
     * Never probed inline: the probe is a one-off cron event (runUploadsProbe()), and until it answers the caller falls
     * back to the server check.
     *
     * @return string
     */
    private static function uploadsExposure(): string
    {
        $cached = get_transient(self::UPLOADS_PROBE_TRANSIENT);
        if (is_string($cached)) {
            return $cached;
        }
        if (!wp_next_scheduled(self::UPLOADS_PROBE_HOOK)) {
            wp_schedule_single_event(time(), self::UPLOADS_PROBE_HOOK);
        }
        return '';
    }

    /**
     * Cron callback for the probe queued by uploadsExposure().
     *
     * @return void
     */
    public static function runUploadsProbe(): void
    {
        self::probeUploadsExposure();
    }

    /**
     * Places a random file in the protected folder and requests its URL from the site itself, as Site Health does
     * for its loopback checks. Works whatever serves the files, which a server-name check cannot know.
     *
     * @return string 'exposed' when the file's content came back, 'protected' when the server answered 403, '' for
     *                anything else (no file written, no loopback response, or an answer that proves nothing).
     */
    private static function probeUploadsExposure(): string
    {
        $safe_dir = \FabricatorForms\Utils\Storage\PrivateDir::base();
        $safe_url = \FabricatorForms\Utils\Storage\PrivateDir::url();
        \FabricatorForms\Utils\Storage\SecureDir::harden($safe_dir);

        $file   = 'probe-' . bin2hex(random_bytes(8)) . '.txt';
        $token  = bin2hex(random_bytes(16));
        $result = '';
        // 0644: the web server must be able to read it, or a refusal would prove nothing.
        if (\FabricatorForms\Utils\Storage\SecureDir::putFile($safe_dir . '/' . $file, $token, 0644)) {
            $response = wp_remote_get(
                $safe_url . '/' . $file,
                [
                    'timeout'     => 5,
                    'redirection' => 3,
                    // Core's own filter for loopback requests; a self-signed local certificate must not block the probe.
                    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WP core's own loopback filter, applied as core applies it, so a site's customization of it holds here too.
                    'sslverify'   => apply_filters('https_local_ssl_verify', false),
                ]
            );
            if (!is_wp_error($response)) {
                $code = (int) wp_remote_retrieve_response_code($response);
                if ($code === 200 && trim((string) wp_remote_retrieve_body($response)) === $token) {
                    $result = 'exposed';
                } elseif ($code === 403) {
                    // Only a 403 for this file counts as protected; any other status says nothing about the folder.
                    $result = 'protected';
                }
            }
            wp_delete_file($safe_dir . '/' . $file);
        }
        // A day for a definite answer; an hour when the probe could not run, so a passing hiccup is retried soon.
        set_transient(self::UPLOADS_PROBE_TRANSIENT, $result, $result === '' ? HOUR_IN_SECONDS : DAY_IN_SECONDS);
        return $result;
    }

    /**
     * Fallback for when the probe cannot run: true when SERVER_SOFTWARE names a server that ignores .htaccess and
     * web.config.
     *
     * @return bool
     */
    private static function serverIgnoresDenyFiles(): bool
    {
        $software = isset($_SERVER['SERVER_SOFTWARE']) ? strtolower(sanitize_text_field(wp_unslash($_SERVER['SERVER_SOFTWARE']))) : '';

        foreach (self::HTACCESS_BLIND_SERVERS as $name) {
            if (str_contains($software, $name)) {
                return true;
            }
        }
        // Log rather than warn on an unrecognised server, once a day, to avoid crying wolf on every unknown SAPI.
        $known_safe = false;
        foreach (self::HTACCESS_HONOURING_SERVERS as $name) {
            if (str_contains($software, $name)) {
                $known_safe = true;
                break;
            }
        }
        if (!$known_safe && !get_transient('fabricator_unknown_server_logged')) {
            set_transient('fabricator_unknown_server_logged', true, DAY_IN_SECONDS);
            \FabricatorForms\fabricator_log(
                'FabricatorForms: unrecognised SERVER_SOFTWARE "' . $software
                . '" — cannot tell whether .htaccess/web.config protect the PDF directory. Verify manually.'
            );
        }
        return false;
    }

    /**
     * Tells admins when the PDF seal key is unusable. Sealing then fails closed, so every form that attaches a sealed
     * PDF answers visitors with an error until the key is fixed; without this, only the server log would say why.
     *
     * @return void
     */
    public static function maybeWarnSealKeyUnusable(): void
    {
        // Before setup the key doesn't exist yet by design, and maybeSealSetupRedirect() leads admins to create it.
        if (!current_user_can('manage_options') || !get_option('fabricator_forms_seal_setup_done', false)) {
            return;
        }
        $problem = \FabricatorForms\PDF\HashSeal::activeKeyProblem();
        if ($problem === '') {
            return;
        }
        if ($problem === 'wrong-master-key') {
            $fix = __('FABRICATOR_SEAL_MASTER_KEY in wp-config.php does not open your PDF seal keys. Put the right master key back: rotating the PDF key now would leave the current key unreadable for good.', 'formfabricator');
        } elseif ($problem === 'undecryptable') {
            $fix = __('The PDF seal key cannot be decrypted. Check FABRICATOR_SEAL_MASTER_KEY in wp-config.php, or rotate the PDF key.', 'formfabricator');
        } elseif ($problem === 'retired') {
            $fix = __('The PDF seal key is one that was already retired: its record was restored from an older copy of the database. Rotate the PDF key to create a new one.', 'formfabricator');
        } else {
            $fix = __('The PDF seal key is missing or damaged. Rotate the PDF key to create a new one.', 'formfabricator');
        }

        echo '<div class="notice notice-error"><p><strong>'
            . esc_html__('FormFabricator: forms that attach a sealed PDF cannot be submitted.', 'formfabricator')
            . '</strong></p><p>' . esc_html($fix) . ' <a href="' . esc_url(admin_url('admin.php?page=fabricator-forms-settings')) . '">'
            . esc_html__('Open settings', 'formfabricator')
            . '</a></p></div>';
    }

    private const MAIL_FAILURES_DISMISS = 'fabricator_dismiss_mail_failures';

    /* Per user and per site (a user option): when someone who can't clear the record last dismissed the notice. */
    public const MAIL_FAILURES_SEEN = 'fabricator_mail_failures_seen';

    /**
     * Tells form managers when submissions could not be delivered (MailSender::failureRecords()). Each visitor was
     * asked to try again, but nothing is stored, so until the cause is fixed every submission is lost; without this,
     * only the server log would say so.
     *
     * @return void
     */
    public static function maybeWarnMailFailures(): void
    {
        if (!\FabricatorForms\Capabilities::userCan('edit_forms')) {
            return;
        }
        $records = \FabricatorForms\Form\MailSender::failureRecords();
        if ($records === []) {
            return;
        }
        // Dismissed by a user who can't clear the record: hidden for them until a newer failure is recorded.
        $seen = (int) get_user_option(self::MAIL_FAILURES_SEEN);
        if (!current_user_can('manage_options') && max(array_column($records, 'last')) <= $seen) {
            return;
        }
        $causes = [
            'unroutable' => __('No enabled notification had a usable recipient.', 'formfabricator'),
            'pdf'        => __('The PDF could not be created.', 'formfabricator'),
            'uploads'    => __('The uploaded files could not be prepared as attachments. Check that the uploads folder is writable and the disk is not full.', 'formfabricator'),
            'send'       => __('The site could not send a notification. Check the site\'s mail setup, or the mail plugin it uses.', 'formfabricator'),
        ];
        $format  = get_option('date_format') . ' ' . get_option('time_format');
        $dismiss = wp_nonce_url(add_query_arg(self::MAIL_FAILURES_DISMISS, '1'), self::MAIL_FAILURES_DISMISS);

        echo '<div class="notice notice-error"><p><strong>'
            . esc_html__('FormFabricator: submissions could not be delivered.', 'formfabricator')
            . '</strong></p>';
        // One line per form, newest first: each is cleared by its own form's next delivered submission.
        foreach ($records as $form_id => $record) {
            $title = (string) get_post_field('post_title', $form_id);
            echo '<p>' . esc_html(sprintf(
                /* translators: 1: form title, 2: date and time of the first failure, 3: number of submissions, 4: date and time of the latest. */
                _n(
                    'Form "%1$s": since %2$s, %3$d submission could not be delivered, the latest on %4$s.',
                    'Form "%1$s": since %2$s, %3$d submissions could not be delivered, the latest on %4$s.',
                    $record['count'],
                    'formfabricator'
                ),
                $title !== '' ? $title : '#' . $form_id,
                wp_date($format, $record['first']),
                $record['count'],
                wp_date($format, $record['last'])
            ))
                . ' ' . esc_html($causes[$record['reason']]) . '</p>';
        }
        echo '<p>'
            . esc_html__(
                'Each visitor was asked to try again, and nothing was stored. To check the mail setup, send a test email from a notification\'s Sender settings. A form\'s line goes away once a submission to it is delivered.',
                'formfabricator'
            )
            . ' <a href="' . esc_url($dismiss) . '">' . esc_html__('Dismiss', 'formfabricator') . '</a></p></div>';
    }

    /**
     * Dismisses the delivery-failure notice. An administrator clears the record for everyone; any other form manager
     * only stops seeing it until a newer failure is recorded, so no one below them can hide an ongoing loss of
     * submissions from the administrators the notice is meant to reach.
     *
     * @return void
     */
    public static function maybeDismissMailFailures(): void
    {
        if (!isset($_GET[self::MAIL_FAILURES_DISMISS]) || !\FabricatorForms\Capabilities::userCan('edit_forms')) {
            return;
        }
        check_admin_referer(self::MAIL_FAILURES_DISMISS);
        self::dismissMailFailures();
        wp_safe_redirect(remove_query_arg([self::MAIL_FAILURES_DISMISS, '_wpnonce']));
        exit;
    }

    /**
     * What dismissing the delivery-failure notice does for the current user (maybeDismissMailFailures()).
     *
     * @return void
     */
    public static function dismissMailFailures(): void
    {
        if (current_user_can('manage_options')) {
            \FabricatorForms\Form\MailSender::clearFailures();
            return;
        }
        update_user_option(get_current_user_id(), self::MAIL_FAILURES_SEEN, time());
    }
}
