<?php

/**
 * Enqueues and manages front-end CSS and JS assets.
 *
 * PHP Version 8.1
 *
 * @category  FormFabricator
 * @package   FormFabricator
 * @author    Alexander Jorek
 * @copyright 2026 Alexander Jorek
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 * @version   1.0.7
 * @link      https://github.com/AlexanderJorek/FormFabricator
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 3
 * of the License, or (at your option) any later version.
 */

namespace FabricatorForms\Utils;

defined('ABSPATH') || exit;

/**
 * Enqueues front-end and admin CSS/JS assets for FormFabricator.
 */
class Assets
{
    // Vendored locally (no CDN, no SRI needed); public so other admin pages don't keep their own copy to drift.
    public const FONT_AWESOME_VERSION = '6.5.2';

    /**
     * Guard so ensureFrontAssets() is idempotent across its two entry points.
     *
     * @var bool
     */
    private static bool $front_assets_done = false;

    /**
     * wp_enqueue_scripts fast path: loads assets when the shortcode is detectable in post_content; FormRenderer::render() is the authoritative fallback for widgets/page builders/FSE.
     *
     * @return void
     */
    public static function enqueueFront(): void
    {
        if (!self::pageHasForm()) {
            return;
        }
        self::ensureFrontAssets();
    }

    /**
     * Enqueues the front-end CSS/JS a rendered form needs (safe to call more than once); a late call from FormRenderer::render() still works since WP prints late-enqueued styles in wp_footer.
     *
     * @return void
     */
    public static function ensureFrontAssets(): void
    {
        if (self::$front_assets_done) {
            return;
        }
        self::$front_assets_done = true;

        \wp_enqueue_style(
            'fabricator-forms-front',
            FABRICATOR_FORMS_URL . 'assets/css/front.css',
            [],
            FABRICATOR_FORMS_VERSION
        );

        /* Only override CSS variables when the user has explicitly saved a value.
           If no option exists yet, front.css defaults apply (or the theme wins). */
        $accent = \get_option('fabricator_forms_accent_color', false);
        $border = \get_option('fabricator_forms_border_color', false);
        $vars   = [];
        if ($accent && preg_match('/^#[0-9a-fA-F]{6}$/', $accent)) {
            $vars[] = '--fabricator-accent: ' . $accent;
        }
        if ($border && preg_match('/^#[0-9a-fA-F]{6}$/', $border)) {
            $vars[] = '--fabricator-border-input: ' . $border;
        }
        if (!empty($vars)) {
            \wp_add_inline_style('fabricator-forms-front', ':root { ' . implode('; ', $vars) . '; }');
        }

        \wp_enqueue_script(
            'fabricator-forms-front',
            FABRICATOR_FORMS_URL . 'assets/js/front.js',
            [],
            FABRICATOR_FORMS_VERSION,
            true
        );

        \wp_localize_script(
            'fabricator-forms-front',
            'FabricatorForms',
            [
            'ajaxUrl'      => \admin_url('admin-ajax.php'),
            // Single source of truth for per-country IBAN length lives in SepaField::IBAN_LEN;
            // localized here rather than duplicated in SepaField.js.
            'ibanLen'      => \FabricatorForms\Fields\SepaField::IBAN_LEN,
            'i18n'         => [
                'submitting'              => __('Sending…', 'formfabricator'),
                'error_server'            => __('Server error. Please try again.', 'formfabricator'),
                'field_required'          => __('This field is required.', 'formfabricator'),
                'validation_both'         => __('Please fill in all required fields and correct the invalid entries.', 'formfabricator'),
                'validation_required'     => __('Please fill in all required fields.', 'formfabricator'),
                'validation_invalid'      => __('Please enter valid data.', 'formfabricator'),
                'thank_you'               => __('Thank you!', 'formfabricator'),
                // CAPTCHA
                'recaptcha_blocked'       => __('Could not load CAPTCHA. Please disable content blockers for this site or try another browser.', 'formfabricator'),
                // Upload field
                'upload_remove_prefix'    => __('Remove: ', 'formfabricator'),
                // translators: %d: maximum number of files allowed (substituted client-side).
                'upload_too_many'         => __('Too many files. Maximum %d allowed.', 'formfabricator'),
                'upload_no_types'         => __('No allowed file types in selection.', 'formfabricator'),
                'upload_skipped_one'      => __('1 file was skipped due to file type.', 'formfabricator'),
                // translators: %d: number of files skipped due to file type (substituted client-side).
                'upload_skipped_many'     => __('%d files were skipped due to file type.', 'formfabricator'),
                // translators: %1$d: total number of files submitted, %2$d: maximum allowed per submission (both substituted client-side).
                'upload_overflow'         => __('Too many files total (%1$d). Max. %2$d per submission.', 'formfabricator'),
                // Checkbox field
                // translators: %d: minimum number of options that must be selected (substituted client-side).
                'checkbox_min'            => __('Please select at least %d option(s).', 'formfabricator'),
                // translators: %d: maximum number of options that may be selected (substituted client-side).
                'checkbox_max'            => __('Please select at most %d option(s).', 'formfabricator'),
                // SEPA field
                'sepa_iban_invalid'       => __('Invalid IBAN (check digit incorrect).', 'formfabricator'),
                'sepa_iban_incomplete'    => __('Please enter a complete and valid IBAN.', 'formfabricator'),
                'sepa_bic_invalid'        => __('Please enter a valid BIC.', 'formfabricator'),
                'sepa_iban_required'      => __('IBAN is required.', 'formfabricator'),
                'sepa_bic_required'       => __('BIC is required.', 'formfabricator'),
                'sepa_holder_required'    => __('Account holder is required.', 'formfabricator'),
                'sepa_sig_required'       => __('Please sign.', 'formfabricator'),
                'sepa_country_blocked'    => __('This country is not allowed.', 'formfabricator'),
                // Phone field
                'phone_invalid'           => __('Please enter a valid phone number.', 'formfabricator'),
                'phone_intl_required'     => __('Please enter the number with international prefix (+...).', 'formfabricator'),
                'phone_country_blocked'   => __('This phone number is not allowed for your country.', 'formfabricator'),
                // Slider field
                'slider_invalid_value'    => __('Please enter a valid value.', 'formfabricator'),
                // translators: %1$s: minimum allowed value, %2$s: maximum allowed value (both substituted client-side).
                'slider_out_of_range'     => __('Value outside the allowed range (%1$s–%2$s).', 'formfabricator'),
                // translators: %s: minimum allowed value (substituted client-side).
                'slider_min'              => __('Minimum value: %s', 'formfabricator'),
                // translators: %s: maximum allowed value (substituted client-side).
                'slider_max'              => __('Maximum value: %s', 'formfabricator'),
                // Text / textarea word limit
                // translators: %1$d: maximum word count allowed, %2$d: current word count (both substituted client-side).
                'word_limit_exceeded'     => __('Please enter at most %1$d words (currently: %2$d).', 'formfabricator'),
                // "Other" free-text word limit (Checkbox/Radio/Select, shared via BaseField::otherTextClientRule())
                // translators: %1$d: maximum word count allowed, %2$d: current word count (both substituted client-side).
                'other_word_limit_exceeded' => __('Please enter at most %1$d words for "Other" (currently: %2$d).', 'formfabricator'),
                // Website field
                'website_invalid_url'     => __('Please enter a valid URL (e.g. https://example.com).', 'formfabricator'),
                // Currency field
                'currency_invalid_amount' => __('Please enter a valid amount.', 'formfabricator'),
                'currency_decimals'       => __('Please enter an amount with at most two decimal places.', 'formfabricator'),
                // translators: %s: minimum allowed value (substituted client-side).
                'currency_min'            => __('Minimum value: %s', 'formfabricator'),
                // translators: %s: maximum allowed value (substituted client-side).
                'currency_max'            => __('Maximum value: %s', 'formfabricator'),
                // Date field
                // translators: %s: expected date pattern, e.g. DD.MM.YYYY (substituted client-side).
                'date_invalid_format'     => __('Please enter a date in %s format.', 'formfabricator'),
                'date_invalid_date'       => __('Please enter a valid date.', 'formfabricator'),
                // Email field
                'email_invalid'           => __('Please enter a valid email address.', 'formfabricator'),
                // Number field
                'number_invalid'          => __('Please enter a valid number.', 'formfabricator'),
                // translators: %s: minimum allowed value (substituted client-side).
                'number_min'              => __('Minimum value: %s', 'formfabricator'),
                // translators: %s: maximum allowed value (substituted client-side).
                'number_max'              => __('Maximum value: %s', 'formfabricator'),
                'number_not_integer'      => __('Please enter a whole number.', 'formfabricator'),
                'number_not_positive'     => __('Please enter a positive number.', 'formfabricator'),
                'number_not_positive_int' => __('Please enter a positive whole number.', 'formfabricator'),
                // translators: %s: step size (substituted client-side).
                'number_step'             => __('Please enter a value in steps of %s.', 'formfabricator'),
                // Time field (12-hour hint)
                'time_am'                 => __('AM', 'formfabricator'),
                'time_pm'                 => __('PM', 'formfabricator'),
            ],
            ]
        );

        /* Single pass over all field classes — collect CSS, empty-checks,
         * validators, inits, and skip-validation flags without re-instantiating. */
        $fieldCss    = [];
        $emptyChecks = [];
        $pairs       = [];
        $seenRules   = [];
        $inits       = [];
        $skip        = [];

        foreach (\FabricatorForms\Fields\FieldRegistry::all() as $type => $class) {
            $handler = new $class();

            $css = trim($handler->getStyles());
            if ($css !== '') {
                $fieldCss[] = $css;
            }

            $entry = $handler->getClientEmptyCheck();
            if (!empty($entry['fn'])) {
                $emptyChecks[] = wp_json_encode($type) . ':' . trim($entry['fn']);
            }

            foreach ($handler->getClientValidation() as $vEntry) {
                $rule = $vEntry['rule'] ?? '';
                $fn   = $vEntry['fn']   ?? '';
                if ($rule !== '' && $fn !== '' && !isset($seenRules[$rule])) {
                    $seenRules[$rule] = true;
                    $pairs[]          = wp_json_encode($rule) . ':' . trim($fn);
                }
            }

            $fn = $handler->getClientInit();
            if ($fn !== '') {
                $inits[] = wp_json_encode($type) . ':' . trim($fn);
            }

            if ($handler->skipValidation()) {
                $skip[] = wp_json_encode($type);
            }
        }

        // Built entirely from this plugin's own field-class string literals (never request input).
        if (!empty($fieldCss)) {
            \wp_add_inline_style('fabricator-forms-front', implode("\n", $fieldCss));
        }
        if (!empty($emptyChecks)) {
            \wp_add_inline_script(
                'fabricator-forms-front',
                'window.FabricatorEmptyChecks={' . implode(',', $emptyChecks) . '};',
                'before'
            );
        }
        if (!empty($pairs)) {
            \wp_add_inline_script(
                'fabricator-forms-front',
                'window.FabricatorValidators={' . implode(',', $pairs) . '};',
                'before'
            );
        }
        if (!empty($inits)) {
            \wp_add_inline_script(
                'fabricator-forms-front',
                'window.FabricatorFieldInits={' . implode(',', $inits) . '};',
                'before'
            );
        }
        if (!empty($skip)) {
            \wp_add_inline_script(
                'fabricator-forms-front',
                'window.FabricatorSkipValidation=[' . implode(',', $skip) . '];',
                'before'
            );
        }

        /* Form-select shortcode assets — enqueued here when detectable so they reach <head>;
           FormSelectList::shortcode() calls ensureFormSelectAssets() as the same fallback. */
        if (self::pageHasFormSelect()) {
            self::ensureFormSelectAssets();
        }
    }

    /**
     * Guard so ensureFormSelectAssets() is idempotent across its two entry points.
     *
     * @var bool
     */
    private static bool $select_assets_done = false;

    /**
     * Enqueues the [fabricator_form_select] assets. Safe to call more than once.
     *
     * @return void
     */
    public static function ensureFormSelectAssets(): void
    {
        if (self::$select_assets_done) {
            return;
        }
        self::$select_assets_done = true;

        \wp_enqueue_style(
            'fabricator-form-select',
            FABRICATOR_FORMS_URL . 'assets/css/form-select.css',
            [],
            FABRICATOR_FORMS_VERSION
        );
        \wp_enqueue_script(
            'fabricator-form-select',
            FABRICATOR_FORMS_URL . 'assets/js/form-select.js',
            [],
            FABRICATOR_FORMS_VERSION,
            true
        );
    }

    /**
     * Enqueues the Font Awesome stylesheet vendored locally under
     * assets/vendor/fontawesome/ (see the FONT_AWESOME_VERSION docblock above).
     *
     * @return void
     */
    private static function enqueueFontAwesome(): void
    {
        \wp_enqueue_style(
            'fabricator-forms-font-awesome',
            FABRICATOR_FORMS_URL . 'assets/vendor/fontawesome/css/all.min.css',
            [],
            self::FONT_AWESOME_VERSION
        );
    }

    /**
     * Enqueues admin CSS and JS for FormFabricator admin pages.
     *
     * @param string $hook Current admin page hook suffix.
     */
    public static function enqueueAdmin(string $hook): void
    {
        /* Form editor page */
        if (str_contains($hook, 'fabricator-forms-editor')) {
            self::enqueueFontAwesome();

            \wp_enqueue_style(
                'fabricator-forms-admin',
                FABRICATOR_FORMS_URL . 'assets/css/admin.css',
                ['fabricator-forms-font-awesome'],
                FABRICATOR_FORMS_VERSION
            );
            self::addAdminCssVars();

            \wp_enqueue_style('wp-color-picker');
            \wp_enqueue_script('wp-color-picker');

            \wp_enqueue_script(
                'fabricator-forms-builder',
                FABRICATOR_FORMS_URL . 'assets/js/admin-builder.js',
                [],
                FABRICATOR_FORMS_VERSION,
                true
            );
            \wp_enqueue_script(
                'fabricator-forms-editor-canvas',
                FABRICATOR_FORMS_URL . 'assets/js/admin-editor-canvas.js',
                [],
                FABRICATOR_FORMS_VERSION,
                true
            );
            \wp_enqueue_script(
                'fabricator-forms-editor-lock',
                FABRICATOR_FORMS_URL . 'assets/js/admin-editor-lock.js',
                ['jquery'],
                FABRICATOR_FORMS_VERSION,
                true
            );

            \wp_enqueue_media();

        /* General admin pages (non-editor) */
        } elseif (str_contains($hook, 'fabricator-forms')) {
            self::enqueueFontAwesome();
            \wp_enqueue_style(
                'fabricator-forms-admin',
                FABRICATOR_FORMS_URL . 'assets/css/admin.css',
                ['fabricator-forms-font-awesome'],
                FABRICATOR_FORMS_VERSION
            );
            self::addAdminCssVars();
            if (str_ends_with($hook, 'fabricator-forms')) {
                \wp_enqueue_script(
                    'fabricator-forms-editor-canvas',
                    FABRICATOR_FORMS_URL . 'assets/js/admin-editor-canvas.js',
                    [],
                    FABRICATOR_FORMS_VERSION,
                    true
                );
                \wp_enqueue_script(
                    'fabricator-forms-admin-formlist',
                    FABRICATOR_FORMS_URL . 'assets/js/admin-formlist.js',
                    [],
                    FABRICATOR_FORMS_VERSION,
                    true
                );
            }
            if (str_contains($hook, 'fabricator-forms-select')) {
                \wp_enqueue_script(
                    'fabricator-forms-editor-canvas',
                    FABRICATOR_FORMS_URL . 'assets/js/admin-editor-canvas.js',
                    [],
                    FABRICATOR_FORMS_VERSION,
                    true
                );
                \wp_enqueue_script(
                    'fabricator-forms-admin-formselect',
                    FABRICATOR_FORMS_URL . 'assets/js/admin-formselect.js',
                    [],
                    FABRICATOR_FORMS_VERSION,
                    true
                );
            }
            $needs_picker = str_contains($hook, 'fabricator-forms-settings')
                         || str_contains($hook, 'fabricator-forms-pdf-layout');
            if ($needs_picker) {
                \wp_enqueue_style('wp-color-picker');
                \wp_enqueue_script('wp-color-picker');
            }
            if (str_contains($hook, 'fabricator-forms-settings')) {
                \wp_enqueue_script(
                    'fabricator-forms-editor-canvas',
                    FABRICATOR_FORMS_URL . 'assets/js/admin-editor-canvas.js',
                    [],
                    FABRICATOR_FORMS_VERSION,
                    true
                );
                \wp_enqueue_script(
                    'fabricator-forms-settings-lock',
                    FABRICATOR_FORMS_URL . 'assets/js/admin-settings-lock.js',
                    ['jquery'],
                    FABRICATOR_FORMS_VERSION,
                    true
                );
                \wp_enqueue_script(
                    'fabricator-forms-admin-settings',
                    FABRICATOR_FORMS_URL . 'assets/js/admin-settings.js',
                    ['jquery', 'wp-color-picker'],
                    FABRICATOR_FORMS_VERSION,
                    true
                );
            }
            if (str_contains($hook, 'fabricator-forms-pdf-layout')) {
                \wp_enqueue_script(
                    'fabricator-forms-editor-canvas',
                    FABRICATOR_FORMS_URL . 'assets/js/admin-editor-canvas.js',
                    [],
                    FABRICATOR_FORMS_VERSION,
                    true
                );
                \wp_enqueue_script(
                    'fabricator-forms-pdflayout-lock',
                    FABRICATOR_FORMS_URL . 'assets/js/admin-pdflayout-lock.js',
                    ['jquery'],
                    FABRICATOR_FORMS_VERSION,
                    true
                );
                \wp_enqueue_script(
                    'fabricator-forms-admin-pdflayout',
                    FABRICATOR_FORMS_URL . 'assets/js/admin-pdflayout.js',
                    ['wp-color-picker'],
                    FABRICATOR_FORMS_VERSION,
                    true
                );
                $fn = 'window.fabricatorPdfUpdatePreview';
                $cb = 'if(' . $fn . ')setTimeout(' . $fn . ',0);';
                $picker_js = 'jQuery(function($){'
                    . '$(".fabricator-iris-input").wpColorPicker({'
                    . 'change:function(){' . $cb . '},'
                    . 'clear:function(){' . $cb . '}'
                    . '});});';
                \wp_add_inline_script('wp-color-picker', $picker_js);
            }
        }

        // Field test harness (dev-only, WP_DEBUG-gated — see Plugin.php::load()); build.ps1 strips it from releases so this hook never fires there.
        if (str_contains($hook, 'fabricator-field-tests')) {
            \wp_enqueue_style(
                'fabricator-forms-admin-fieldtest',
                FABRICATOR_FORMS_URL . 'assets/css/admin-fieldtest.css',
                [],
                FABRICATOR_FORMS_VERSION
            );
        }

        /* Verification page */
        if (str_contains($hook, 'fabricator-pdf-verification')) {
            \wp_enqueue_style(
                'fabricator-forms-admin',
                FABRICATOR_FORMS_URL . 'assets/css/admin.css',
                [],
                FABRICATOR_FORMS_VERSION
            );
            self::addAdminCssVars();
            \wp_enqueue_style(
                'fabricator-forms-admin-verification',
                FABRICATOR_FORMS_URL . 'assets/css/admin-verification.css',
                ['fabricator-forms-admin'],
                FABRICATOR_FORMS_VERSION
            );
            \wp_enqueue_script(
                'fabricator-forms-admin-verification',
                FABRICATOR_FORMS_URL . 'assets/js/admin-verification.js',
                [],
                FABRICATOR_FORMS_VERSION,
                true
            );
            \wp_localize_script(
                'fabricator-forms-admin-verification',
                'FabricatorVerifyPage',
                ['i18n' => ['remove' => __('Remove', 'formfabricator')]]
            );
            // pdf.js 6.x is ES-modules only; wp_localize_script has no module equivalent, so data is injected via a separate src-less classic script.
            \wp_register_script('fabricator-verifier-data', false, [], FABRICATOR_FORMS_VERSION, true);
            \wp_enqueue_script('fabricator-verifier-data');
            \wp_enqueue_script_module(
                'fabricator-forms-verification',
                FABRICATOR_FORMS_URL . 'assets/js/verification.js',
                [],
                FABRICATOR_FORMS_VERSION
            );
            // Enqueued modules are printed by WP_Script_Modules' printers, which older supported WordPress releases hook only
            // on front-end actions. Attach them to the admin footer when core hasn't, and never twice.
            $modules = \wp_script_modules();
            foreach (['print_import_map', 'print_enqueued_script_modules', 'print_script_module_preloads'] as $printer) {
                if (method_exists($modules, $printer)
                    && \has_action('admin_print_footer_scripts', [$modules, $printer]) === false
                ) {
                    \add_action('admin_print_footer_scripts', [$modules, $printer]);
                }
            }
            \wp_localize_script(
                'fabricator-verifier-data',
                'FabricatorVerifier',
                [
                'ajaxUrl'     => \admin_url('admin-ajax.php'),
                'nonce'       => \wp_create_nonce('fabricator_verifier_nonce'),
                // .js, not pdf.js's own .mjs name: many servers have no MIME type for .mjs, and module workers refuse to run
                // anything not served as JavaScript.
                // Both carry the plugin version: a static import from verification.js would resolve without one, so a
                // browser could pair a cached older library with a freshly fetched worker, which pdf.js refuses to run.
                'pdfJsModule' => FABRICATOR_FORMS_URL . 'vendor/pdfjs/pdf.js?ver=' . FABRICATOR_FORMS_VERSION,
                'pdfJsWorker' => FABRICATOR_FORMS_URL . 'vendor/pdfjs/pdf.worker.js?ver=' . FABRICATOR_FORMS_VERSION,
                'i18n'        => [
                    'loading'          => __('Loading…', 'formfabricator'),
                    'pdf_loading'      => __('Loading PDF…', 'formfabricator'),
                    // translators: %1$d: download progress percentage, 0-100 (substituted client-side).
                    'downloading'      => __('Downloading… (%1$d%%)', 'formfabricator'),
                    // translators: %1$d: current page number, %2$d: total page count (both substituted client-side).
                    'page_reading'     => __('Reading page %1$d of %2$d…', 'formfabricator'),
                    'text_extracted'   => __('Text extracted — server analyzing…', 'formfabricator'),
                    // translators: %1$d: seconds remaining before this PDF's verification request is sent (substituted client-side).
                    'queued'           => __('Waiting in queue (%1$ds)…', 'formfabricator'),
                    'queued_for_download' => __('Waiting to download…', 'formfabricator'),
                    'queued_for_verify' => __('Waiting for a free verification slot…', 'formfabricator'),
                    'rate_limited_retry' => __('Rate limited — retrying…', 'formfabricator'),
                    // translators: %1$d: seconds remaining before the next automatic retry (substituted client-side).
                    'server_busy_retry' => __('Server busy — retrying in %1$ds…', 'formfabricator'),
                    'processing'       => __('Processing response…', 'formfabricator'),
                    'done'             => __('Done', 'formfabricator'),
                    // translators: %d: HTTP status code (substituted client-side).
                    'server_error'     => __('Server error (HTTP %d)', 'formfabricator'),
                    'network_error'    => __('Network error', 'formfabricator'),
                    'pdf_load_error'   => __('PDF load error: ', 'formfabricator'),
                    'error_prefix'     => __('Error: ', 'formfabricator'),
                    'unknown_error'    => __('Unknown server error', 'formfabricator'),
                ],
                ]
            );
        }
    }

    /**
     * Injects --fabricator-admin-accent and --fabricator-hover-color onto the fabricator-forms-admin stylesheet.
     *
     * @return void
     */
    private static function addAdminCssVars(): void
    {
        // Guarded here, not in callers: the verification hook reaches this twice, and this holds regardless of how hook branches are rearranged.
        static $emitted = false;
        if ($emitted) {
            return;
        }
        $emitted = true;

        $hover        = \get_option('fabricator_forms_hover_color', '#1d2327');
        $admin_accent = \get_option('fabricator_forms_admin_accent', '#2271b1');
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $hover)) {
            $hover = '#1d2327';
        }
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $admin_accent)) {
            $admin_accent = '#2271b1';
        }
        $r = hexdec(substr($admin_accent, 1, 2));
        $g = hexdec(substr($admin_accent, 3, 2));
        $b = hexdec(substr($admin_accent, 5, 2));
        $luminance   = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;
        $accent_text = $luminance > 0.55 ? '#1d2327' : '#ffffff';
        $accent_fg   = $luminance > 0.55 ? '#1d2327' : $admin_accent;

        $hr = hexdec(substr($hover, 1, 2));
        $hg = hexdec(substr($hover, 3, 2));
        $hb = hexdec(substr($hover, 5, 2));
        $hover_lum = (0.299 * $hr + 0.587 * $hg + 0.114 * $hb) / 255;
        $hover_fg  = $hover_lum > 0.55 ? '#1d2327' : $hover;

        \wp_add_inline_style(
            'fabricator-forms-admin',
            ':root { --fabricator-admin-accent: ' . $admin_accent
                . '; --fabricator-admin-accent--rgb: ' . $r . ',' . $g . ',' . $b
                . '; --fabricator-hover-color: ' . $hover
                . '; --fabricator-hover-color-fg: ' . $hover_fg
                . '; --fabricator-accent-text: ' . $accent_text
                . '; --fabricator-admin-accent-fg: ' . $accent_fg . '; }'
        );
    }

    /**
     * Returns true when the current post contains a [fabricator_form] shortcode.
     *
     * @return bool True when a fabricator form shortcode is present in the post content.
     */
    private static function pageHasForm(): bool
    {
        global $post;
        if (!$post || !\is_a($post, 'WP_Post')) {
            return false;
        }
        // NOTE: the unanchored '[fabricator_form' prefix also matches '[fabricator_form_select' —
        // harmless here since a form-select page needs these front-end assets too
        return str_contains((string)$post->post_content, '[fabricator_form');
    }

    /**
     * Returns true when the current post contains a [fabricator_form_select] shortcode.
     *
     * @return bool True when a [fabricator_form_select] shortcode is found in the post.
     */
    private static function pageHasFormSelect(): bool
    {
        global $post;
        if (!$post || !\is_a($post, 'WP_Post')) {
            return false;
        }
        return str_contains((string)$post->post_content, '[fabricator_form_select');
    }

    /**
     * Prints the notice-collection container. Call exactly once per page — core clones notices into every .wp-header-end it finds.
     *
     * @return void
     */
    public static function renderNoticeDock(): void
    {
        echo '<div class="fabricator-notice-dock"><hr class="wp-header-end" style="display:none"></div>';
    }
}
