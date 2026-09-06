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
 * @version   1.0.6
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

    /* Recurring sweeps: scheduled hourly by scheduleSweeps() AND cleared on deactivation/uninstall.
       Anything added here starts firing every hour, so one-off events belong in ONE_OFF_CRON_HOOKS. */
    public const CRON_HOOKS = [
        'fabricator_generator_sweep_tmp_dirs',
        'fabricator_rl_sweep_expired',
        'fabricator_su_sweep_expired',
        'fabricator_cs_sweep_expired',
        'fabricator_verifier_sweep_tmp_dirs',
    ];

    /* Cron hooks that are scheduled on demand as single events (never by scheduleSweeps()) and so must
       NOT be added to CRON_HOOKS above -- listing one there would schedule it as a recurring hourly job.
       They can still be pending when the plugin is deactivated or deleted, so teardown must clear them. */
    public const ONE_OFF_CRON_HOOKS = [
        'fabricator_verifier_cleanup_files',
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
     * Requires all plugin PHP files; loads admin files when in admin context.
     *
     * @return void
     */
    private static function load(): void
    {
        // Filter glob() results against FieldRegistry::FIELD_MAP; glob() alone isn't a trust boundary.
        // phpcs:ignore PHPCS_SecurityAudit.Misc.IncludeMismatch.ErrMiscIncludeMismatchNoExt -- hardcoded literal path, not attacker- or request-influenced.
        include_once FABRICATOR_FORMS_PATH . 'includes/Fields/FieldRegistry.php';
        // phpcs:ignore PHPCS_SecurityAudit.Misc.IncludeMismatch.ErrMiscIncludeMismatchNoExt -- hardcoded literal path, not attacker- or request-influenced.
        include_once FABRICATOR_FORMS_PATH . 'includes/Fields/BaseField.php';
        $knownFieldClasses = array_flip(array_keys(\FabricatorForms\Fields\FieldRegistry::FIELD_MAP));
        $fieldFiles = [];
        foreach (glob(FABRICATOR_FORMS_PATH . 'includes/Fields/*Field.php') ?: [] as $path) {
            $basename = basename($path, '.php');
            if (isset($knownFieldClasses[$basename])) {
                $fieldFiles[] = 'Fields/' . $basename . '.php';
            }
        }

        $files = array_merge($fieldFiles, [
            'Form/FormModel.php',
            'Form/FormSelectModel.php',
            'Admin/FormSelectList.php',
            'Form/FormProcessor.php',
            'Form/FormRenderer.php',
            'PDF/HashSeal.php',
            'PDF/PdfUtils.php',
            'PDF/PdfDescriptor.php',
            'PDF/Generator.php',
            'Form/MailSender.php',
            'Utils/Assets.php',
            'Utils/ClientIp.php',
            'Utils/RateLimiter.php',
            'Utils/SingleUseToken.php',
            'Utils/ConcurrencySlot.php',
            'Utils/MemoryBudget.php',
            'Utils/AdminLock.php',
            'Utils/Cast.php',
            'Utils/AjaxGuard.php',
            'Utils/SecureDir.php',
        ]);

        foreach ($files as $file) {
            // phpcs:ignore PHPCS_SecurityAudit.Misc.IncludeMismatch.ErrMiscIncludeMismatchNoExt -- $file is drawn from the hardcoded $files array above (every entry already ends in .php); nothing here is attacker- or request-influenced.
            include_once FABRICATOR_FORMS_PATH . 'includes/' . $file;
        }

        if (is_admin()) {
            $adminFiles = [
                'Admin/FormList.php', 'Admin/FormEditor.php', 'Admin/FormSettings.php',
                'Admin/PDFLayoutEditor.php', 'Admin/Verificationpage.php',
            ];
            // Dev-only test harness, off in production; file_exists() since build.ps1 strips it from the shipped package.
            if (defined('WP_DEBUG') && WP_DEBUG
                && file_exists(FABRICATOR_FORMS_PATH . 'includes/Admin/FieldTestPage.php')
            ) {
                $adminFiles[] = 'Admin/FieldTestPage.php';
            }
            foreach ($adminFiles as $file) {
                // phpcs:ignore PHPCS_SecurityAudit.Misc.IncludeMismatch.ErrMiscIncludeMismatchNoExt -- $file is drawn from the hardcoded $adminFiles array above (every entry already ends in .php); nothing here is attacker- or request-influenced.
                include_once FABRICATOR_FORMS_PATH . 'includes/' . $file;
            }
        }
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
     * Registers all WordPress actions, filters, and shortcodes.
     *
     * @return void
     */
    private static function hooks(): void
    {
        /* Register CPT */
        add_action('init', [self::class, 'registerCpt']);
        add_filter('map_meta_cap', [self::class, 'mapCreateFormCap'], 10, 2);

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

        /* IBAN → BIC lookup (proxied through WP to avoid CORS) */
        add_action('wp_ajax_fabricator_iban_bic', [self::class, 'ajaxIbanBic']);
        add_action('wp_ajax_nopriv_fabricator_iban_bic', [self::class, 'ajaxIbanBic']);

        /* PDF mail hook */
        Form\MailSender::init();
        add_action(
            'fabricator_forms_submission',
            [Form\MailSender::class, 'onSubmission'],
            10,
            3
        );

        /* Fallback sweep for temp PDFs the Generator creates — safety net for
           when a request dies before its own SL_*.pdf/Entry_*.pdf cleanup runs.
           Registered unconditionally (not inside is_admin()) since generation
           happens on public form submissions and wp-cron.php requests aren't
           admin requests either. */
        add_action('fabricator_generator_sweep_tmp_dirs', [PDF\Generator::class, 'cronSweepTmpDirs']);

        /* Fallback sweep for expired fabricator_rl_* rate-limit rows — without this,
           every distinct IP+form bucket that ever hits RateLimiter::increment()
           leaves a permanent wp_options row (GDPR storage-limitation: the key
           embeds a hash of the visitor's IP). */
        add_action('fabricator_rl_sweep_expired', [Utils\RateLimiter::class, 'cronSweepExpired']);

        /* Sweeps expired fabricator_su_* single-use-claim rows — same rationale as the sweep above. */
        add_action('fabricator_su_sweep_expired', [Utils\SingleUseToken::class, 'cronSweepExpired']);

        /* Sweeps expired fabricator_cs_* concurrency-slot rows — same rationale as the sweep above. */
        add_action('fabricator_cs_sweep_expired', [Utils\ConcurrencySlot::class, 'cronSweepExpired']);

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
            // class_exists(): the harness is absent from release builds (see load()).
            if (defined('WP_DEBUG') && WP_DEBUG && class_exists(Admin\FieldTestPage::class)) {
                Admin\FieldTestPage::register();
            }
            add_action('admin_enqueue_scripts', [Utils\Assets::class, 'enqueueAdmin']);
            add_action('admin_init', [self::class, 'maybeSealSetupRedirect']);
            /* Self-heal for sites upgraded in place (no activation hook fires) or whose cron
               array was cleared. Admin-only, so public page loads never pay for the lookup. */
            add_action('admin_init', [self::class, 'scheduleSweeps']);
            /* Surfaces the openiban.com / reCAPTCHA disclosure in Settings > Privacy. */
            add_action('admin_init', [self::class, 'registerPrivacyPolicyContent']);
            add_filter('plugin_action_links_' . FABRICATOR_FORMS_BASENAME, [self::class, 'addDeleteWarningLink']);
            add_action('admin_enqueue_scripts', [self::class, 'enqueuePluginDeleteWarning']);
        }
    }

    /**
     * Mints a fresh nonce/token pair; rate-limited per IP+form since there's no nonce yet to verify.
     *
     * @return void
     */
    public static function ajaxGetToken(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- endpoint mints the nonce/token pair itself; rate-limited per IP+form instead (see method docblock).
        $form_id = isset($_POST['form_id']) ? absint(wp_unslash($_POST['form_id'])) : 0;
        if (!$form_id || !Form\FormModel::get($form_id)) {
            wp_send_json_error();
            return;
        }

        $ip  = Utils\ClientIp::resolve();
        $key = 'token_' . $form_id . '_' . hash_hmac('sha256', $ip, wp_salt('auth'));
        if (Utils\RateLimiter::increment($key, MINUTE_IN_SECONDS) > 20) {
            wp_send_json_error();
            return;
        }

        wp_send_json_success(
            [
            'nonce' => wp_create_nonce('fabricator_forms_submit_' . $form_id),
            /* Replay-protection token, separate from the nonce above (which collides across
               anonymous visitors). See Utils/SingleUseToken.php and FormProcessor::handle(). */
            'token' => wp_generate_uuid4(),
            ]
        );
    }

    /**
     * AJAX handler that proxies IBAN/BIC lookup to openiban.com.
     *
     * @return void
     */
    public static function ajaxIbanBic(): void
    {
        // Checked before the rate-limit increment so a request without a valid nonce can't write wp_options rows at all.
        if (!check_ajax_referer('fabricator_iban_bic', 'nonce', false)) {
            wp_send_json_error();
            return;
        }

        // Proxied through this WP endpoint to avoid CORS and to rate-limit our own usage of the openiban.com API.
        $ip  = Utils\ClientIp::resolve();
        $key = 'iban_' . hash_hmac('sha256', $ip, wp_salt('auth'));
        if (Utils\RateLimiter::increment($key, MINUTE_IN_SECONDS) > 20) {
            wp_send_json_error();
            return;
        }

        // 15 = shortest valid IBAN (Norway), 34 = longest (ISO 13616); cheap early reject before hitting openiban.com.
        $iban = preg_replace('/[^A-Z0-9]/', '', strtoupper(sanitize_text_field(wp_unslash($_POST['iban'] ?? ''))));
        if (strlen($iban) < 15 || strlen($iban) > 34) {
            wp_send_json_error();
            return;
        }

        // Global concurrency cap, not just a per-IP rate limit: the nopriv nonce is identical for
        // every anonymous visitor for its ~12-24h tick window (that's inherent to how WP nonces
        // work for logged-out actions, not something this endpoint can change), so a distributed
        // source can stay under the 20/min-per-IP cap and still occupy a worker per request for
        // the whole outbound round trip. 4 concurrent × the 5s timeout below bounds the worst
        // case to a small, fixed slice of any FPM pool; live BIC lookup is inherently low-volume,
        // so a tight cap costs legitimate visitors nothing.
        // This job is bounded by worker occupancy, not memory, so it reserves a nominal 1 byte
        // against a 1-byte-per-holder budget and lets ConcurrencySlot's $max_holders cap (4) be
        // the binding constraint.
        $cs_bucket = 'iban_bic';
        $cs_token  = Utils\ConcurrencySlot::reserve($cs_bucket, 1, 4, 15, 4);
        if ($cs_token === false) {
            wp_send_json_error();
            return;
        }
        register_shutdown_function(
            static function () use ($cs_bucket, $cs_token): void {
                Utils\ConcurrencySlot::release($cs_bucket, $cs_token);
            }
        );

        $url      = 'https://openiban.com/validate/' . rawurlencode($iban) . '?getBIC=true&validateBankCode=true';
        // 5s, matching CaptchaField's outbound timeout — long enough for a lookup API, short
        // enough that a stalled upstream can't pin workers for the old 8s each.
        $response = wp_remote_get($url, ['timeout' => 5]);

        if (is_wp_error($response)) {
            wp_send_json_error();
            return;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body)) {
            wp_send_json_error();
            return;
        }
        $valid = !empty($body['valid']);
        $bic   = $body['bankData']['bic'] ?? '';

        wp_send_json_success(
            [
            'valid'          => $valid,
            'bic'            => $valid ? sanitize_text_field($bic) : '',
            'bankCodeFound'  => !empty($body['checkResults']['bankCodeCheck']),
            ]
        );
    }

    /**
     * Locales available for the privacy-policy text: 'en' plus every locale with a shipped .mo file.
     *
     * @return array<string,string> Locale code => human-readable language name.
     */
    public static function availablePrivacyLanguages(): array
    {
        $langs = ['en' => 'English'];
        // WP_LANG_DIR/plugins is where WP.org language packs install (the only one populated in production); the bundled path covers dev checkouts.
        $dirs = [
            rtrim((string) WP_LANG_DIR, '/\\') . '/plugins',
            rtrim(FABRICATOR_FORMS_PATH, '/\\') . '/languages',
        ];
        foreach ($dirs as $dir) {
            foreach (glob($dir . '/formfabricator-*.mo') ?: [] as $path) {
                if (preg_match('/^formfabricator-([A-Za-z]{2,3}(?:_[A-Za-z]{2,4})?)\.mo$/', basename($path), $m)) {
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
     * Raw privacy-policy paragraphs disclosing the two third-party data flows (openiban.com, Google reCAPTCHA).
     *
     * @return string[] Two paragraphs: [0] openiban.com, [1] Google reCAPTCHA.
     */
    private static function privacyPolicyParagraphs(): array
    {
        return [
            __('SEPA Direct Debit (OpenIBAN)', 'formfabricator') . "\n" . __(
                // phpcs:ignore Generic.Files.LineLength -- must be a single string literal for WordPress i18n tooling to extract it correctly, see WordPress.WP.I18n.NonSingularStringLiteralText
                "If a form on this site uses a SEPA Direct Debit field with live IBAN lookup enabled, the IBAN you type is sent to our server, which then queries the OpenIBAN service (openiban.com) to validate it and determine the corresponding BIC. The request to OpenIBAN is made by our server, not by your browser, so your IP address is not disclosed to OpenIBAN. Only the IBAN itself is transmitted. This processing is carried out for the purpose of verifying the bank account information. Where live lookup is not enabled, no IBAN data leaves this site before you submit the form. For more information on data processing, please refer to OpenIBAN's Privacy Policy.",
                'formfabricator'
            ),
            __('Google reCAPTCHA', 'formfabricator') . "\n" . __(
                // phpcs:ignore Generic.Files.LineLength -- must be a single string literal for WordPress i18n tooling to extract it correctly, see WordPress.WP.I18n.NonSingularStringLiteralText
                "To prevent spam and fraudulent form submissions, we use Google reCAPTCHA. Among other things, the IP address, the CAPTCHA response, and other technical information are transmitted to Google and processed there to determine whether the input was made by a human. This may involve the transfer of personal data to the United States. For more information, please see Google's Privacy Policy.",
                'formfabricator'
            ),
        ];
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
        $html = '';
        foreach (self::privacyPolicyParagraphs() as $paragraph) {
            // Each paragraph is "Heading\nBody" — render the heading as a sub-heading.
            $parts   = explode("\n", $paragraph, 2);
            $heading = $parts[0];
            $body    = $parts[1] ?? '';
            $html   .= '<h3>' . esc_html($heading) . '</h3>'
                . '<p class="privacy-policy-tutorial">' . esc_html($body) . '</p>';
        }
        wp_add_privacy_policy_content('FormFabricator', $html);
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
            'capability_type'     => 'post',
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
     * @return string[]
     */
    public static function mapCreateFormCap(array $caps, string $cap): array
    {
        if ($cap === 'create_fabricator_forms') {
            return self::userCan('edit_forms') ? ['exist'] : ['do_not_allow'];
        }
        return $caps;
    }

    /**
     * Adds an inline JS confirmation to the plugin list delete link.
     *
     * @param string[] $links Plugin action links array.
     * @return string[]
     */
    public static function addDeleteWarningLink(array $links): array
    {
        if (!isset($links['delete'])) {
            return $links;
        }
        // Wraps core's delete link rather than regex-injecting an inline onclick (which Plugin Check flags); behavior lives in admin-plugin-delete-warning.js.
        $links['delete'] = '<span class="fabricator-delete-warning" data-fabricator-warning="'
            . esc_attr(__('WARNING: Deleting the plugin will permanently delete all PDF seal keys. Make sure you have backed up your keys. Continue?', 'formfabricator'))
            . '">' . $links['delete'] . '</span>';
        return $links;
    }

    /**
     * Enqueues the plugins-screen delete confirmation handler. Hooked to admin_enqueue_scripts.
     *
     * @param string $hook Current admin page hook suffix.
     * @return void
     */
    public static function enqueuePluginDeleteWarning(string $hook): void
    {
        if ($hook !== 'plugins.php') {
            return;
        }
        wp_enqueue_script(
            'fabricator-forms-plugin-delete-warning',
            FABRICATOR_FORMS_URL . 'assets/js/admin-plugin-delete-warning.js',
            [],
            FABRICATOR_FORMS_VERSION,
            true
        );
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
        // Only redirect within FormFabricator pages, not the whole WP admin.
        if (strncmp($current_page, 'fabricator-forms', 16) !== 0) {
            return;
        }
        if ($current_page === 'fabricator-forms-settings') {
            return;
        }
        wp_safe_redirect(admin_url('admin.php?page=fabricator-forms-settings'));
        exit;
    }
}
