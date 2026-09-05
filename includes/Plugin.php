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
 * @version   1.0.5
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
        // This is the plugin's shared WP_DEBUG-gated logging helper, called from many files;
        // it never runs unless WP_DEBUG is on, so it's not leftover production debug code.
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

    // Every recurring cron hook this plugin schedules. Kept in one place so
    // register_deactivation_hook() (forge-forms.php) can clear all of them without the
    // cleanup list drifting out of sync as new sweeps are added here.
    public const CRON_HOOKS = [
        'fabricator_generator_sweep_tmp_dirs',
        'fabricator_rl_sweep_expired',
        'fabricator_su_sweep_expired',
        'fabricator_cs_sweep_expired',
        'fabricator_verifier_sweep_tmp_dirs',
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
            'Utils/AdminLock.php',
            'Utils/Sanitize.php',
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
            // Dev-only test harness — never loaded (or registered as a menu page) on
            // a production site where WP_DEBUG is off
            if (defined('WP_DEBUG') && WP_DEBUG) {
                $adminFiles[] = 'Admin/FieldTestPage.php';
            }
            foreach ($adminFiles as $file) {
                // phpcs:ignore PHPCS_SecurityAudit.Misc.IncludeMismatch.ErrMiscIncludeMismatchNoExt -- $file is drawn from the hardcoded $adminFiles array above (every entry already ends in .php); nothing here is attacker- or request-influenced.
                include_once FABRICATOR_FORMS_PATH . 'includes/' . $file;
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
        if (!wp_next_scheduled('fabricator_generator_sweep_tmp_dirs')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', 'fabricator_generator_sweep_tmp_dirs');
        }

        /* Fallback sweep for expired fabricator_rl_* rate-limit rows — without this,
           every distinct IP+form bucket that ever hits RateLimiter::increment()
           leaves a permanent wp_options row (GDPR storage-limitation: the key
           embeds a hash of the visitor's IP). */
        add_action('fabricator_rl_sweep_expired', [Utils\RateLimiter::class, 'cronSweepExpired']);
        if (!wp_next_scheduled('fabricator_rl_sweep_expired')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', 'fabricator_rl_sweep_expired');
        }

        /* Sweeps expired fabricator_su_* single-use-claim rows — same rationale as the sweep above. */
        add_action('fabricator_su_sweep_expired', [Utils\SingleUseToken::class, 'cronSweepExpired']);
        if (!wp_next_scheduled('fabricator_su_sweep_expired')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', 'fabricator_su_sweep_expired');
        }

        /* Sweeps expired fabricator_cs_* concurrency-slot rows — same rationale as the sweep above. */
        add_action('fabricator_cs_sweep_expired', [Utils\ConcurrencySlot::class, 'cronSweepExpired']);
        if (!wp_next_scheduled('fabricator_cs_sweep_expired')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', 'fabricator_cs_sweep_expired');
        }

        /* Remove deleted forms from all FormSelect lists */
        add_action('before_delete_post', [Form\FormSelectModel::class, 'removeFormId'], 10, 1);

        /* Assets */
        add_action('wp_enqueue_scripts', [Utils\Assets::class, 'enqueueFront']);

        if (is_admin()) {
            Admin\FormList::init();
            Admin\FormEditor::init();
            Admin\FormSelectList::init();
            Admin\FormSettings::init();
            Admin\PDFLayoutEditor::init();
            Admin\Verificationpage::register();
            if (defined('WP_DEBUG') && WP_DEBUG) {
                Admin\FieldTestPage::register();
            }
            add_action('admin_enqueue_scripts', [Utils\Assets::class, 'enqueueAdmin']);
            add_action('admin_init', [self::class, 'maybeSealSetupRedirect']);
            add_filter('plugin_action_links_' . FABRICATOR_FORMS_BASENAME, [self::class, 'addDeleteWarningLink']);
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
        // Nonce is only emitted on pages embedding a form, so this can't be a bare IBAN/BIC oracle.
        // Checked before the rate-limit increment below so an unauthenticated request without a
        // valid nonce can't write wp_options rows via RateLimiter::increment() at all.
        if (!check_ajax_referer('fabricator_iban_bic', 'nonce', false)) {
            wp_send_json_error();
            return;
        }

        // Proxied through this WP endpoint (rather than calling openiban.com from the
        // browser) to avoid CORS issues and to rate-limit our own usage of their API.
        // See includes/Utils/ClientIp.php for the trusted-proxy-aware IP resolution.
        $ip  = Utils\ClientIp::resolve();
        $key = 'iban_' . hash_hmac('sha256', $ip, wp_salt('auth'));
        if (Utils\RateLimiter::increment($key, MINUTE_IN_SECONDS) > 20) {
            wp_send_json_error();
            return;
        }

        // 15 is the shortest valid IBAN length (Norway); the openiban.com call below
        // rejects anything malformed regardless, this is just an early cheap reject
        $iban = preg_replace('/[^A-Z0-9]/', '', strtoupper(sanitize_text_field(wp_unslash($_POST['iban'] ?? ''))));
        if (strlen($iban) < 15) {
            wp_send_json_error();
            return;
        }

        $url      = 'https://openiban.com/validate/' . rawurlencode($iban) . '?getBIC=true&validateBankCode=true';
        $response = wp_remote_get($url, ['timeout' => 8]);

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
        foreach (glob(FABRICATOR_FORMS_PATH . 'languages/formfabricator-*.mo') ?: [] as $path) {
            if (preg_match('/^formfabricator-([A-Za-z]{2,3}(?:_[A-Za-z]{2,4})?)\.mo$/', basename($path), $m)) {
                $langs[$m[1]] = self::localeDisplayName($m[1]);
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
                "If you enter an IBAN in a form, it will be transmitted to the OpenIBAN service (openiban.com) for validation and to determine the corresponding BIC. Only the IBAN you entered and the connection data required for technical transmission will be processed. This processing is carried out for the purpose of verifying the bank account information. For more information on data processing, please refer to OpenIBAN's Privacy Policy.",
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
     * Suggested privacy-policy text, rendered in $lang regardless of the site's current admin-UI locale.
     *
     * @param string $lang Locale code from availablePrivacyLanguages().
     */
    public static function privacyPolicyPlainText(string $lang): string
    {
        return self::withPluginLocale(
            $lang,
            static fn(): string => implode("\n\n", self::privacyPolicyParagraphs())
        );
    }

    /**
     * Runs $callback with this plugin's textdomain swapped to $locale, then restores it; saves/restores only
     * $l10n['formfabricator'] rather than switch_to_locale() to avoid mutating WP's global locale stack. For
     * 'en' installs NOOP_Translations instead of unsetting the key, since unsetting re-triggers WP 6.7's
     * just-in-time textdomain auto-reload (from the site's locale, not English) on the next __()/_e() call.
     *
     * @param string   $locale   Locale code, e.g. 'de_DE', or 'en' for the gettext source language (no
     *                           .mo to load).
     * @param callable $callback Produces the string once the locale is active.
     */
    private static function withPluginLocale(string $locale, callable $callback): string
    {
        global $l10n;
        $had_previous = array_key_exists('formfabricator', $l10n);
        $previous     = $had_previous ? $l10n['formfabricator'] : null;

        if ($locale === 'en' || $locale === '') {
            if (!class_exists(\NOOP_Translations::class)) {
                require_once ABSPATH . WPINC . '/pomo/translations.php';
            }
            $l10n['formfabricator'] = new \NOOP_Translations();
        } elseif (preg_match('/^[A-Za-z]{2,3}(?:_[A-Za-z]{2,4})?$/', $locale)) {
            unset($l10n['formfabricator']);
            // Reads from WP_LANG_DIR, where WordPress.org's language-pack system installs it (no bundled .mo ships).
            $mofile = WP_LANG_DIR . '/plugins/formfabricator-' . $locale . '.mo';
            if (file_exists($mofile)) {
                load_textdomain('formfabricator', $mofile);
            }
        }

        try {
            return $callback();
        } finally {
            if ($had_previous) {
                $l10n['formfabricator'] = $previous;
            } else {
                unset($l10n['formfabricator']);
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
            // public/show_ui/show_in_menu/show_in_rest are all false because forms are
            // managed entirely through this plugin's own custom admin UI (Admin\FormEditor,
            // Admin\FormList), not WordPress's default post-editing screens
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
        if (isset($links['delete'])) {
            $links['delete'] = preg_replace(
                '/(<a\s)/i',
                '$1onclick="return confirm(\''
                    . esc_js(__('WARNING: Deleting the plugin will permanently delete all PDF seal keys. Make sure you have backed up your keys. Continue?', 'formfabricator'))
                    . '\');" ',
                (string) $links['delete']
            );
        }
        return $links;
    }

    /**
     * Checks if the given user has a specific FormFabricator capability. Valid caps: view_forms, edit_forms,
     * edit_pdf_layout, use_verifier, settings. Administrators always pass; user-specific override wins over
     * role setting.
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
        static $access = null;
        if ($access === null) {
            $access = get_option('fabricator_forms_access', []);
        }
        $user_overrides = $access['users'] ?? [];
        if (isset($user_overrides[$user_id]) && is_array($user_overrides[$user_id])) {
            return !empty($user_overrides[$user_id][$cap]);
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
