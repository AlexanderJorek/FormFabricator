<?php

/**
 * Main plugin class that registers all hooks and bootstraps the plugin.
 *
 * PHP Version 8.1
 *
 * @category  FormFabricator
 * @package   FormFabricator
 * @author    Alexander Jorek
 * @copyright 2026 Alexander Jorek
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 * @version   1.0.8
 * @link      https://github.com/AlexanderJorek/FormFabricator
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 3
 * of the License, or (at your option) any later version.
 */

namespace FabricatorForms;

defined('ABSPATH') || exit;

/**
 * A visitor's file name as a log line may record it: a short hash and the extension, never the name, which often holds a
 * person's name (GDPR Art. 5(1)(c)). The hash still lets an admin match two lines about the same file.
 *
 * @param string $name File name as uploaded.
 * @return string E.g. "file #3f9a1c2e.pdf".
 */
function fabricator_log_file(string $name): string
{
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    return 'file #' . substr(hash('sha256', $name), 0, 8) . (preg_match('/^[a-z0-9]{1,10}$/', $ext) === 1 ? '.' . $ext : '');
}

/**
 * Logs a debug message when WP_DEBUG is enabled.
 *
 * @param string $message The message to log.
 */
function fabricator_log(string $message): void
{
    if (defined('WP_DEBUG') && WP_DEBUG) {
        // Gated on WP_DEBUG so this isn't leftover production debug code.
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- see comment above
        error_log($message);
    }
}

/**
 * Bootstraps the FormFabricator plugin: loads dependencies, registers CPT, and wires all hooks.
 */
class Plugin
{
    private static bool $initialized = false;

    /* Scheduled hourly by scheduleSweeps() and cleared on deactivation/uninstall; one-off events belong in ONE_OFF_CRON_HOOKS instead. */
    public const CRON_HOOKS = [
        'fabricator_generator_sweep_tmp_dirs',
        'fabricator_rl_sweep_expired',
        'fabricator_su_sweep_expired',
        'fabricator_cs_sweep_expired',
        'fabricator_verifier_sweep_tmp_dirs',
    ];

    /* Single events scheduled on demand; cleared on deactivation and deletion too. */
    public const ONE_OFF_CRON_HOOKS = [
        'fabricator_verifier_sweep_expired', // Utils\VerifierCleanup::HOOK
        'fabricator_uploads_probe_run',
    ];

    /**
     * Bootstraps the plugin on first call; subsequent calls are no-ops.
     *
     * @return void
     */
    public static function init(): void
    {
        if (self::$initialized) {
            return;
        }
        self::$initialized = true;

        self::load();
        self::hooks();
    }

    /**
     * Loads the field classes FieldRegistry::FIELD_MAP lists, so that registerDefaults() finds them among the declared
     * classes. Every other class is autoloaded when it is first used.
     *
     * @return void
     */
    private static function load(): void
    {
        foreach (array_keys(Fields\FieldRegistry::FIELD_MAP) as $shortName) {
            class_exists(__NAMESPACE__ . '\\Fields\\' . $shortName);
        }
    }

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
        // On this plugin's own screens and the Plugins list only: shown on every admin screen and back every 30 days, it
        // read as the kind of nag WordPress.org's guideline 11 asks plugins not to put up.
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
        $path = rtrim($url_path, '/') . '/fabricator-secure-pdf/';

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
        $upload_dir = wp_upload_dir();
        $safe_dir   = $upload_dir['basedir'] . '/fabricator-secure-pdf';
        $safe_url   = rtrim((string) ($upload_dir['baseurl'] ?? ''), '/') . '/fabricator-secure-pdf';
        Utils\SecureDir::harden($safe_dir);

        $file   = 'probe-' . bin2hex(random_bytes(8)) . '.txt';
        $token  = bin2hex(random_bytes(16));
        $result = '';
        // 0644: the web server must be able to read it, or a refusal would prove nothing.
        if (Utils\SecureDir::putFile($safe_dir . '/' . $file, $token, 0644)) {
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
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- $_SERVER is never slashed by wp_magic_quotes(); the value is only str_contains()-matched against literal allow-lists and never echoed.
        $software = strtolower((string) ($_SERVER['SERVER_SOFTWARE'] ?? ''));

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
            fabricator_log(
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
        $problem = PDF\HashSeal::activeKeyProblem();
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

    /**
     * Ensures every recurring sweep is scheduled. Idempotent.
     *
     * Called from the activation hook, and again from admin_init as a self-heal for sites upgraded in place.
     *
     * @return void
     */
    public static function scheduleSweeps(): void
    {
        foreach (self::CRON_HOOKS as $hook) {
            // Scheduling the verifier sweep here too is harmless and keeps all scheduling in one place.
            if (!wp_next_scheduled($hook)) {
                wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', $hook);
            }
        }
    }

    /**
     * When the last inline sweep ran (sweepIfDue()), as a Unix time.
     *
     * @var string
     */
    public const LAST_INLINE_SWEEP_OPTION = 'fabricator_last_inline_sweep';

    /**
     * Runs the rate-limit, single-use, concurrency-slot and temp-file sweeps from the request that writes those rows,
     * at most once an hour.
     *
     * WP-Cron may never run (WP-Cron off, a network site nobody administers), yet the privacy text promises these rows
     * and files don't stay. The submission handler calls this after writing its rows.
     *
     * @return void
     */
    public static function sweepIfDue(): void
    {
        $last = (int) get_option(self::LAST_INLINE_SWEEP_OPTION, 0);
        if (time() - $last < HOUR_IN_SECONDS) {
            return;
        }
        // Claimed before the work, so requests arriving together do not all sweep.
        update_option(self::LAST_INLINE_SWEEP_OPTION, time(), false);
        Utils\RateLimiter::cronSweepExpired();
        Utils\SingleUseToken::cronSweepExpired();
        Utils\ConcurrencySlot::cronSweepExpired();
        // Only files older than an hour, so a submission still being sent keeps its own.
        PDF\Generator::cronSweepTmpDirs();
    }

    /**
     * Registers all WordPress actions, filters, and shortcodes.
     *
     * @return void
     */
    private static function hooks(): void
    {
        /* Register CPT */
        add_action('init', [self::class, 'registerCpt']);
        add_filter('map_meta_cap', [self::class, 'mapCreateFormCap'], 10, 3);
        add_filter('is_protected_meta', [self::class, 'protectFormMeta'], 10, 3);
        add_filter('user_has_cap', [self::class, 'grantAccessCaps'], 10, 4);

        /* Register field types */
        add_action('init', [Fields\FieldRegistry::class, 'registerDefaults']);

        /* Shortcodes */
        add_shortcode('fabricator_form', [Form\FormRenderer::class, 'shortcode']);
        add_shortcode('fabricator_form_select', [Admin\FormSelectList::class, 'shortcode']);

        /* AJAX form submission */
        add_action('wp_ajax_fabricator_forms_submit', [Form\FormProcessor::class, 'handle']);
        add_action('wp_ajax_nopriv_fabricator_forms_submit', [Form\FormProcessor::class, 'handle']);

        /* Mints a fresh fabricator_nonce/fabricator_submission_token pair; not embedded in cacheable form HTML. */
        add_action('wp_ajax_fabricator_forms_get_token', [self::class, 'ajaxGetToken']);
        add_action('wp_ajax_nopriv_fabricator_forms_get_token', [self::class, 'ajaxGetToken']);

        /* A proof-of-work challenge for an ALTCHA CAPTCHA field, fetched by its widget (GET). */
        add_action('wp_ajax_fabricator_altcha_challenge', [Utils\Altcha::class, 'ajaxChallenge']);
        add_action('wp_ajax_nopriv_fabricator_altcha_challenge', [Utils\Altcha::class, 'ajaxChallenge']);

        /* PDF mail hook */
        Form\MailSender::init();
        add_action(
            'fabricator_forms_submission',
            [Form\MailSender::class, 'onSubmission'],
            10,
            4
        );

        // Temp PDFs a dead request left behind; registered everywhere, as PDFs are made on public requests too.
        add_action('fabricator_generator_sweep_tmp_dirs', [PDF\Generator::class, 'cronSweepTmpDirs']);

        // Expired rate-limit rows, keyed on hashed IPs (GDPR storage limitation).
        add_action('fabricator_rl_sweep_expired', [Utils\RateLimiter::class, 'cronSweepExpired']);

        /* Sweeps expired fabricator_su_* single-use-claim rows — same rationale as the sweep above. */
        add_action('fabricator_su_sweep_expired', [Utils\SingleUseToken::class, 'cronSweepExpired']);

        /* Sweeps expired fabricator_cs_* concurrency-slot rows — same rationale as the sweep above. */
        add_action('fabricator_cs_sweep_expired', [Utils\ConcurrencySlot::class, 'cronSweepExpired']);

        /* Background loopback probe of the protected PDF folder, queued by maybeWarnUnprotectedUploads(). */
        add_action(self::UPLOADS_PROBE_HOOK, [self::class, 'runUploadsProbe']);

        // Unused verification copies go on the first request of any kind once one is due, at their own one-off event, and
        // hourly as a backstop. In Utils, since front-end requests never load Verificationpage.
        add_action('init', [Utils\VerifierCleanup::class, 'maybeSweep']);
        add_action(Utils\VerifierCleanup::HOOK, [Utils\VerifierCleanup::class, 'sweep']);
        add_action('fabricator_verifier_sweep_tmp_dirs', [Utils\VerifierCleanup::class, 'sweep']);

        /* Remove deleted forms from all FormSelect lists */
        add_action('before_delete_post', [Form\FormSelectModel::class, 'removeFormId'], 10, 1);

        /* Remove deleted forms' per-notification PDF-attachment flags */
        add_action('before_delete_post', [Form\FormModel::class, 'removeFormPdfSettings'], 10, 1);

        /* Assets */
        add_action('wp_enqueue_scripts', [Utils\Assets::class, 'enqueueFront']);

        if (is_admin()) {
            Admin\FormList::init();
            Admin\FormEditor::init();
            Admin\FormSelectList::init();
            Admin\FormSettings::init();
            Admin\PDFLayoutEditor::init();
            Admin\Verificationpage::register();
            add_action('admin_enqueue_scripts', [Utils\Assets::class, 'enqueueAdmin']);
            add_action('admin_init', [self::class, 'maybeSealSetupRedirect']);
            add_action('admin_notices', [self::class, 'maybeWarnUnprotectedUploads']);
            add_action('admin_notices', [self::class, 'maybeWarnSealKeyUnusable']);
            add_action('admin_init', [self::class, 'maybeDismissUploadsNotice']);
            /* Self-heal for sites upgraded in place (no activation hook fires) or whose cron
               array was cleared. Admin-only, so public page loads never pay for the lookup. */
            add_action('admin_init', [self::class, 'scheduleSweeps']);
            /* Surfaces the privacy disclosures in Settings > Privacy. */
            add_action('admin_init', [self::class, 'registerPrivacyPolicyContent']);
        }
    }

    /**
     * Mints a fresh nonce/token pair. Writes nothing and is not rate-limited, by design.
     *
     * A per-address count here would write rows faster than the sweep removes them. The pair sends nothing by itself:
     * FormProcessor::handle() checks both before its own per-address limit.
     *
     * @return void
     */
    public static function ajaxGetToken(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- endpoint mints the nonce/token pair itself, so there is nothing to verify yet (see method docblock).
        $form_id = isset($_POST['form_id']) ? absint(wp_unslash($_POST['form_id'])) : 0;
        if (!$form_id || !Form\FormModel::get($form_id)) {
            wp_send_json_error();
            return;
        }

        wp_send_json_success(
            [
            'nonce' => wp_create_nonce('fabricator_forms_submit_' . $form_id),
            /* Replay protection (the nonce is shared by anonymous visitors), signed and bound to this form. */
            'token' => Utils\SingleUseToken::issue($form_id),
            ]
        );
    }

    /**
     * Locales available for the privacy-policy text: 'en', plus every locale with both a plugin translation in
     * WordPress's language folder and the core locale installed (switch_to_locale() refuses others).
     *
     * @return array<string,string> Locale code => human-readable language name.
     */
    public static function availablePrivacyLanguages(): array
    {
        $langs     = ['en' => 'English'];
        $installed = function_exists('get_available_languages') ? get_available_languages() : [];
        // WP_LANG_DIR/plugins is where WP.org language packs install.
        $dirs = [
            rtrim((string) WP_LANG_DIR, '/\\') . '/plugins',
        ];
        foreach ($dirs as $dir) {
            foreach (glob($dir . '/formfabricator-*.mo') ?: [] as $path) {
                $is_plugin_mo = preg_match('/^formfabricator-([A-Za-z]{2,3}(?:_[A-Za-z]{2,4})?)\.mo$/', basename($path), $m) === 1;
                if ($is_plugin_mo && in_array($m[1], $installed, true)) {
                    $langs[$m[1]] = self::localeDisplayName($m[1]);
                }
            }
        }
        return $langs;
    }

    /**
     * Human-readable name for a locale code, using WP core's offline lookup table.
     *
     * @param string $locale Locale code, e.g. 'de_DE'.
     */
    private static function localeDisplayName(string $locale): string
    {
        if (!function_exists('format_code_lang')) {
            require_once ABSPATH . 'wp-admin/includes/misc.php';
        }
        $name = function_exists('format_code_lang') ? format_code_lang($locale) : '';
        return $name !== '' ? $name : $locale;
    }

    /**
     * Raw privacy-policy paragraphs: what happens to a submission, and the one third-party data flow (Google reCAPTCHA).
     *
     * @return string[] [0] form submissions, [1] Google reCAPTCHA.
     */
    private static function privacyPolicyParagraphs(): array
    {
        $paragraphs = [
            __('Contact forms', 'formfabricator') . "\n" . __(
                // phpcs:ignore Generic.Files.LineLength -- must be a single string literal for WordPress i18n tooling to extract it correctly, see WordPress.WP.I18n.NonSingularStringLiteralText
                "When you submit a form on this website, your entries are not stored in the website's database. They are processed only while your submission is being handled and are then sent by email, possibly together with a generated PDF document, to the recipients chosen by the website operator. While this happens, uploaded files and the PDF document are written to temporary files on the server, which are deleted once the emails have been sent. To limit spam and abuse, a one-way hash of your IP address is kept for a short time to count how often a form is submitted, and is then deleted automatically. If the website operator checks a PDF document with the plugin's verification tool, the uploaded copy is deleted from the server right after the check, or about 10 minutes after its last use if the check is not completed.",
                'formfabricator'
            ),
            __('Google reCAPTCHA', 'formfabricator') . "\n" . __(
                // phpcs:ignore Generic.Files.LineLength -- must be a single string literal for WordPress i18n tooling to extract it correctly, see WordPress.WP.I18n.NonSingularStringLiteralText
                "To prevent spam and fraudulent form submissions, we use Google reCAPTCHA. Among other things, the IP address, the CAPTCHA response, and other technical information are transmitted to Google and processed there to determine whether the input was made by a human. This may involve the transfer of personal data to the United States. For more information, please see Google's Privacy Policy.",
                'formfabricator'
            ),
        ];
        // Only a site that uses reCAPTCHA sends anything to Google: without a site key, no CAPTCHA can load.
        if (trim((string) get_option('fabricator_forms_recaptcha_site_key', '')) === '') {
            array_pop($paragraphs);
        }
        return $paragraphs;
    }

    /**
     * Registers the third-party disclosure with WordPress's Privacy Policy Guide (Settings > Privacy).
     *
     * @return void
     */
    public static function registerPrivacyPolicyContent(): void
    {
        if (!function_exists('wp_add_privacy_policy_content')) {
            return;
        }
        wp_add_privacy_policy_content('FormFabricator', self::privacyPolicyHtml());
    }

    /**
     * The suggested privacy-policy text as the Privacy Policy Guide shows and copies it.
     *
     * @return string
     */
    private static function privacyPolicyHtml(): string
    {
        $html = '';
        foreach (self::privacyPolicyParagraphs() as $paragraph) {
            // Each paragraph is "Heading\nBody" — render the heading as a sub-heading.
            $parts   = explode("\n", $paragraph, 2);
            $heading = $parts[0];
            $body    = $parts[1] ?? '';
            // A plain paragraph: WordPress's "Copy suggested policy text" leaves out anything marked
            // "privacy-policy-tutorial" (it is guidance for the site owner), which left only the two headings.
            $html   .= '<h3>' . esc_html($heading) . '</h3>'
                . '<p>' . esc_html($body) . '</p>';
        }
        return $html;
    }

    /**
     * Suggested privacy-policy text, rendered in $lang regardless of the site's current admin-UI locale.
     *
     * @param string $lang Locale code from availablePrivacyLanguages().
     * @return string The disclosure paragraphs, blank-line separated.
     */
    public static function privacyPolicyPlainText(string $lang): string
    {
        return self::withPluginLocale(
            $lang,
            static fn(): string => implode("\n\n", self::privacyPolicyParagraphs())
        );
    }

    /**
     * Runs $callback with this plugin's textdomain swapped to $locale, then restores it.
     *
     * @param string   $locale   Locale code, e.g. 'de_DE', or 'en' for the gettext source language (no
     *                           .mo to load).
     * @param callable $callback Produces the string once the locale is active.
     */
    private static function withPluginLocale(string $locale, callable $callback): string
    {
        // Uses WP's switch_to_locale()/restore_previous_locale() API rather than reaching into the $l10n global directly.
        // 'en' -> 'en_US': this plugin's gettext source language has no .mo, so translation falls through to the original msgids.
        $target = ($locale === 'en' || $locale === '') ? 'en_US' : $locale;
        if (!preg_match('/^[A-Za-z]{2,3}(?:_[A-Za-z]{2,4})?$/', $target)) {
            return $callback();
        }

        $switched = switch_to_locale($target);
        try {
            return $callback();
        } finally {
            // Only restore when the switch actually took effect; restoring otherwise would pop a
            // locale off the switcher's stack that this call never pushed.
            if ($switched) {
                restore_previous_locale();
            }
        }
    }

    /**
     * Registers the fabricator_form custom post type.
     *
     * @return void
     */
    public static function registerCpt(): void
    {
        register_post_type(
            'fabricator_form',
            [
            'label'               => __('Forms', 'formfabricator'),
            'labels'              => [
                'name'          => __('FormFabricator', 'formfabricator'),
                'singular_name' => __('Form', 'formfabricator'),
                'add_new_item'  => __('Add New Form', 'formfabricator'),
                'edit_item'     => __('Edit Form', 'formfabricator'),
            ],
            // public/show_ui/show_in_menu/show_in_rest are false — forms use this plugin's own custom admin UI, not WP's post-editing screens.
            'public'              => false,
            'show_ui'             => false,
            'show_in_menu'        => false,
            'show_in_rest'        => false,
            'supports'            => ['title'],
            // Own capability type, which nobody holds, so core's post APIs (XML-RPC) refuse everyone; the plugin's
            // screens gate on Plugin::userCan() instead.
            'capability_type'     => ['fabricator_form', 'fabricator_forms'],
            // create_posts uses a custom cap so it can be granted to users with the
            // plugin's own 'edit_forms' permission, not just WP admins (see mapCreateFormCap())
            'capabilities'        => ['create_posts' => 'create_fabricator_forms'],
            'map_meta_cap'        => true,
            ]
        );
    }

    /**
     * Grants the create_fabricator_forms capability to users with the plugin's own edit_forms permission
     * (Plugin::userCan() already lets admins through).
     *
     * @param string[] $caps    Required primitive capabilities.
     * @param string   $cap     Requested meta capability.
     * @param int      $user_id The user being asked about: not always the current one (user_can($other, …)).
     * @return string[]
     */
    public static function mapCreateFormCap(array $caps, string $cap, int $user_id = 0): array
    {
        if ($cap === 'create_fabricator_forms') {
            return self::userCan('edit_forms', $user_id) ? ['exist'] : ['do_not_allow'];
        }
        return $caps;
    }

    /**
     * Marks the form-definition meta keys protected, so core's custom-field APIs neither list nor accept them.
     *
     * @param bool   $protected Core's verdict.
     * @param string $meta_key  Meta key being checked.
     * @param string $meta_type Object type ('post', 'user', ...), '' when unspecified.
     * @return bool
     */
    public static function protectFormMeta($protected, $meta_key, $meta_type = ''): bool
    {
        if (in_array($meta_type, ['', 'post'], true) && in_array($meta_key, Form\FormModel::META_KEYS, true)) {
            return true;
        }
        return (bool) $protected;
    }

    /**
     * Prefix of the capabilities grantAccessCaps() derives from this plugin's access settings.
     *
     * @var string
     */
    public const ACCESS_CAP_PREFIX = 'fabricator_access_';

    /**
     * Grants "fabricator_access_<cap>" exactly when userCan(<cap>) allows it, so WordPress itself enforces the admin
     * screens' capability.
     *
     * @param array $allcaps Capabilities the user has.
     * @param array $caps    Primitive capabilities being checked.
     * @param array $args    Original has_cap() arguments.
     * @param mixed $user    The WP_User being checked.
     * @return array
     */
    public static function grantAccessCaps(array $allcaps, array $caps, array $args, $user): array
    {
        foreach ($caps as $cap) {
            if (!is_string($cap) || !str_starts_with($cap, self::ACCESS_CAP_PREFIX)) {
                continue;
            }
            // userCan() treats user 0 as "the current user", so a logged-out check must not reach it.
            $allcaps[$cap] = $user instanceof \WP_User && $user->ID > 0
                && self::userCan(substr($cap, strlen(self::ACCESS_CAP_PREFIX)), (int) $user->ID);
        }
        return $allcaps;
    }

    /**
     * Checks if the given user has a specific FormFabricator capability.
     *
     * @param string $cap     The capability slug to check.
     * @param int    $user_id User ID, or 0 for the current user.
     */
    public static function userCan(string $cap, int $user_id = 0): bool
    {
        $user_id = $user_id ?: get_current_user_id();
        if (!$user_id) {
            return false;
        }
        if (user_can($user_id, 'manage_options')) {
            return true;
        }
        // Not memoized in a function-static — that risked serving a stale value if a later call in the same request saved this option.
        $access = get_option('fabricator_forms_access', []);
        $user_overrides = $access['users'] ?? [];
        // A per-user entry GRANTS on top of the role, it does not replace it (else "add user" silently revoked role caps).
        if (isset($user_overrides[$user_id])
            && is_array($user_overrides[$user_id])
            && !empty($user_overrides[$user_id][$cap])
        ) {
            return true;
        }
        $user = get_userdata($user_id);
        if (!$user) {
            return false;
        }
        $role_perms = $access['roles'] ?? [];
        foreach ($user->roles as $role) {
            if (!empty($role_perms[$role][$cap])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Redirects admins to settings page until PDF seal setup is complete.
     *
     * @return void
     */
    public static function maybeSealSetupRedirect(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        if (get_option('fabricator_forms_seal_setup_done', false)) {
            return;
        }
        if (wp_doing_ajax() || !isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'GET') {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing decision (which admin page to redirect to); gated by manage_options above, no data written.
        $current_page = sanitize_text_field(wp_unslash($_GET['page'] ?? ''));
        // Only redirect within FormFabricator pages, not the whole WP admin. The verifier's slug has another prefix; before
        // setup it has no key to check a PDF against, so every PDF would come back unknown.
        if (strncmp($current_page, 'fabricator-forms', 16) !== 0 && $current_page !== 'fabricator-pdf-verification') {
            return;
        }
        if ($current_page === 'fabricator-forms-settings') {
            return;
        }
        wp_safe_redirect(admin_url('admin.php?page=fabricator-forms-settings'));
        exit;
    }
}
