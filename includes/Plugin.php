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
 * @version   1.1.1
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
 * Bootstraps the FormFabricator plugin: loads the field classes, registers the form post type, and wires all hooks.
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
        'fabricator_verifier_sweep_expired', // Utils\Storage\VerifierCleanup::HOOK
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
        Utils\Security\RateLimiter::cronSweepExpired();
        Utils\Security\SingleUseToken::cronSweepExpired();
        Utils\Security\ConcurrencySlot::cronSweepExpired();
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
        add_filter('map_meta_cap', [Capabilities::class, 'mapCreateFormCap'], 10, 3);
        add_filter('is_protected_meta', [self::class, 'protectFormMeta'], 10, 3);
        add_filter('user_has_cap', [Capabilities::class, 'grantAccessCaps'], 10, 4);

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
        add_action('wp_ajax_fabricator_forms_altcha_challenge', [Utils\Security\Altcha::class, 'ajaxChallenge']);
        add_action('wp_ajax_nopriv_fabricator_forms_altcha_challenge', [Utils\Security\Altcha::class, 'ajaxChallenge']);

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
        add_action('fabricator_rl_sweep_expired', [Utils\Security\RateLimiter::class, 'cronSweepExpired']);

        /* Sweeps expired fabricator_su_* single-use-claim rows — same rationale as the sweep above. */
        add_action('fabricator_su_sweep_expired', [Utils\Security\SingleUseToken::class, 'cronSweepExpired']);

        /* Sweeps expired fabricator_cs_* concurrency-slot rows — same rationale as the sweep above. */
        add_action('fabricator_cs_sweep_expired', [Utils\Security\ConcurrencySlot::class, 'cronSweepExpired']);

        /* Background loopback probe of the protected PDF folder, queued by Admin\Notices::maybeWarnUnprotectedUploads(). */
        add_action(Admin\Notices::UPLOADS_PROBE_HOOK, [Admin\Notices::class, 'runUploadsProbe']);

        // Unused verification copies go on the first request of any kind once one is due, at their own one-off event, and
        // hourly as a backstop. In Utils, since front-end requests never load VerificationPage.
        add_action('init', [Utils\Storage\VerifierCleanup::class, 'maybeSweep']);
        add_action(Utils\Storage\VerifierCleanup::HOOK, [Utils\Storage\VerifierCleanup::class, 'sweep']);
        add_action('fabricator_verifier_sweep_tmp_dirs', [Utils\Storage\VerifierCleanup::class, 'sweep']);

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
            Utils\Storage\AdminLock::init();
            Admin\VerificationPage::register();
            add_action('admin_enqueue_scripts', [Utils\Assets::class, 'enqueueAdmin']);
            add_action('admin_init', [Admin\SealKeySettings::class, 'maybeSealSetupRedirect']);
            add_action('admin_notices', [Admin\Notices::class, 'maybeWarnUnprotectedUploads']);
            add_action('admin_notices', [Admin\Notices::class, 'maybeWarnSealKeyUnusable']);
            add_action('admin_notices', [Admin\Notices::class, 'maybeWarnMailFailures']);
            add_action('admin_init', [Admin\Notices::class, 'maybeDismissUploadsNotice']);
            add_action('admin_init', [Admin\Notices::class, 'maybeDismissMailFailures']);
            /* Self-heal for sites upgraded in place (no activation hook fires) or whose cron
               array was cleared. Admin-only, so public page loads never pay for the lookup. */
            add_action('admin_init', [self::class, 'scheduleSweeps']);
            /* Surfaces the privacy disclosures in Settings > Privacy. */
            add_action('admin_init', [Admin\PrivacyPolicy::class, 'register']);
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
            'token' => Utils\Security\SingleUseToken::issue($form_id),
            ]
        );
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
            // screens gate on Capabilities::userCan() instead.
            'capability_type'     => ['fabricator_form', 'fabricator_forms'],
            // create_posts uses a custom cap so it can be granted to users with the
            // plugin's own 'edit_forms' permission, not just WP admins (see mapCreateFormCap())
            'capabilities'        => ['create_posts' => 'create_fabricator_forms'],
            'map_meta_cap'        => true,
            ]
        );
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
}
