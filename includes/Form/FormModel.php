<?php

/**
 * CRUD model for storing and retrieving form definitions.
 *
 * PHP Version 8.1
 *
 * @category  FormFabricator
 * @package   FormFabricator
 * @author    Alexander Jorek
 * @copyright 2026 Alexander Jorek
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 * @version   1.0.9
 * @link      https://github.com/AlexanderJorek/FormFabricator
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 3
 * of the License, or (at your option) any later version.
 */

namespace FabricatorForms\Form;

defined('ABSPATH') || exit;

/**
 * Thin wrapper around the fabricator_form custom post type.
 *
 * Forms are stored as posts; field definitions and notifications are stored as post meta.
 */
class FormModel
{
    /**
     * Post meta keys holding a form's definition and its save counter (snapshot()); Plugin::protectFormMeta() hides them
     * from core's custom-field APIs.
     *
     * @var string[]
     */
    public const META_KEYS = ['fabricator_form_fields', 'fabricator_form_notifications', 'fabricator_form_settings', 'fabricator_form_revision'];

    /**
     * How many forms one post query reads at a time; the callers page until a short batch comes back.
     *
     * @var int
     */
    public const QUERY_BATCH = 100;

    public int $id            = 0;
    public string $title         = '';
    public array $fields        = [];
    public array $notifications = [];
    public array $settings      = [];

    /**
     * Retrieves a form model by its post ID.
     *
     * @param int $form_id The post ID of the form.
     * @return self|null The form model, or null if not found.
     */
    public static function get(int $form_id): ?self
    {
        $post = get_post($form_id);
        if (!$post || $post->post_type !== 'fabricator_form') {
            return null;
        }
        // Match getAll()'s 'publish' filter so a draft/trashed form isn't reachable via direct lookup.
        if ($post->post_status !== 'publish') {
            return null;
        }

        $model                = new self();
        $model->id            = $form_id;
        $model->title         = $post->post_title;
        $model->fields        = self::decodeMeta($form_id, 'fabricator_form_fields');
        $model->notifications = self::decodeMeta($form_id, 'fabricator_form_notifications');
        $model->settings      = self::decodeMeta($form_id, 'fabricator_form_settings');

        return $model;
    }

    /**
     * Post meta counting the saves writeForm() made, part of the snapshot.
     *
     * @var string
     */
    private const REVISION_META = 'fabricator_form_revision';

    /**
     * Optimistic-concurrency snapshot token for a form: its post_modified_gmt and its save counter.
     *
     * The counter tells apart saves within one second; the timestamp catches changes made outside this plugin.
     *
     * @param int $form_id The post ID of the form.
     * @return string The snapshot token, or '' if the form doesn't exist.
     */
    public static function snapshot(int $form_id): string
    {
        $post = get_post($form_id);
        if (!$post || $post->post_type !== 'fabricator_form') {
            return '';
        }
        return $post->post_modified_gmt . '#' . (int) get_post_meta($form_id, self::REVISION_META, true);
    }

    /**
     * Creates or updates a form. Returns the form ID on success or WP_Error on failure.
     *
     * @param array $data           Keys: title, fields, notifications, settings.
     * @param int   $form_id        Existing post ID to update, or 0 to create.
     * @param bool  $nonce_verified Whether the caller already verified its own (action-specific)
     *                              nonce before calling this method. When false (the default), a generic
     *                              internal nonce check is performed as a backstop — see
     *                              {@see self::nonceVerifiedOrCheck()}.
     * @param string $expected_snapshot Optimistic-concurrency token; rejects with WP_Error on mismatch.
     */
    public static function save(array $data, int $form_id = 0, bool $nonce_verified = false, string $expected_snapshot = ''): int|\WP_Error
    {
        // Defense-in-depth: don't rely solely on callers remembering to gate on edit_forms.
        if (!\FabricatorForms\Plugin::userCan('edit_forms')) {
            return new \WP_Error('forbidden', __('Insufficient permissions.', 'formfabricator'));
        }
        if (!self::nonceVerifiedOrCheck($nonce_verified)) {
            return new \WP_Error('invalid_nonce', __('Security check failed. Please reload and try again.', 'formfabricator'));
        }

        // The snapshot check and the write that follows have to be one step, or two saves landing at the same moment
        // both read the same unchanged timestamp, both pass, and the later one silently overwrites the earlier.
        if ($form_id > 0 && $expected_snapshot !== '') {
            return \FabricatorForms\Utils\OptionMutex::run(
                'fabricator_form_save_' . $form_id,
                static fn() => self::writeForm($form_id, $data, $expected_snapshot)
            );
        }
        return self::writeForm($form_id, $data, $expected_snapshot);
    }

    /**
     * Writes a form, re-checking the optimistic-concurrency snapshot first. See save(), which gates access.
     *
     * @param int    $form_id           Post ID, or 0 for a new form.
     * @param array  $data              Form data: title, fields, notifications, settings.
     * @param string $expected_snapshot snapshot() this save was based on; '' skips the check.
     * @return int|\WP_Error The form's post ID, or an error.
     */
    private static function writeForm(int $form_id, array $data, string $expected_snapshot): int|\WP_Error
    {
        if ($form_id > 0 && $expected_snapshot !== '') {
            // Read inside the lock, never from before it: the point is that nothing can change between here and the write.
            // clean_post_cache() drops the cached meta too, so the counter is read fresh as well.
            clean_post_cache($form_id);
            $current = self::snapshot($form_id);
            if ($current !== '' && !hash_equals($current, $expected_snapshot)) {
                return new \WP_Error(
                    'conflict',
                    __('This form was changed in another tab or by another user. Please reload and try again.', 'formfabricator')
                );
            }
        }

        $title = sanitize_text_field(\FabricatorForms\Utils\Cast::stringOrDefault($data['title'] ?? null, 'Untitled Form'));
        // The title is plain text, escaped where shown; wp_filter_kses() would store "Q&A" as "Q&amp;A". Only that
        // filter, only for this write.
        $kses_title = has_filter('title_save_pre', 'wp_filter_kses');

        $post_data = [
            // wp_insert_post()/wp_update_post() unslash the title, just as update_post_meta() does the meta below.
            'post_title'  => wp_slash($title),
            'post_type'   => 'fabricator_form',
            'post_status' => 'publish',
        ];

        if ($form_id > 0) {
            $existing = get_post($form_id);
            if (!$existing || $existing->post_type !== 'fabricator_form') {
                return new \WP_Error('not_found', __('Form not found.', 'formfabricator'));
            }
            $post_data['ID'] = $form_id;
        }
        if ($kses_title !== false) {
            remove_filter('title_save_pre', 'wp_filter_kses', (int) $kses_title);
        }
        try {
            $result = $form_id > 0 ? wp_update_post($post_data, true) : wp_insert_post($post_data, true);
        } finally {
            if ($kses_title !== false) {
                add_filter('title_save_pre', 'wp_filter_kses', (int) $kses_title);
            }
        }

        if (is_wp_error($result)) {
            return $result;
        }

        $id = (int)$result;

        $fields        = $data['fields']        ?? [];
        $notifications = $data['notifications'] ?? [];
        $settings      = $data['settings']      ?? [];
        // update_post_meta() runs wp_unslash() on what it is given, but this is decoded JSON that was never slashed, so every
        // save would strip backslashes from email patterns, CSS escapes and HTML. wp_slash() adds exactly what it removes.
        update_post_meta($id, 'fabricator_form_fields', wp_slash($fields));
        update_post_meta($id, 'fabricator_form_notifications', wp_slash($notifications));
        update_post_meta($id, 'fabricator_form_settings', wp_slash($settings));
        // Inside the save lock (save()), so no two writes take the same number; see snapshot().
        update_post_meta($id, self::REVISION_META, (int) get_post_meta($id, self::REVISION_META, true) + 1);

        return $id;
    }

    /**
     * Creates a copy of an existing form. Forwarded to save() — see its docblock.
     *
     * @param int  $form_id        The post ID of the form to duplicate.
     * @param bool $nonce_verified Whether the caller already verified its own (action-specific)
     *                             nonce before calling this method.
     * @return int|\WP_Error New form post ID, or WP_Error on failure.
     */
    public static function duplicate(int $form_id, bool $nonce_verified = false): int|\WP_Error
    {
        $source = self::get($form_id);
        if (!$source) {
            return new \WP_Error('not_found', __('Form not found.', 'formfabricator'));
        }
        return self::save(
            [
            /* translators: %s: original form title. */
            'title'         => sprintf(__('%s (Copy)', 'formfabricator'), $source->title),
            'fields'        => $source->fields,
            'notifications' => $source->notifications,
            'settings'      => $source->settings,
            ],
            0,
            $nonce_verified
        );
    }

    /**
     * Permanently deletes a form post.
     *
     * @param int  $form_id        The post ID of the form to delete.
     * @param bool $nonce_verified Whether the caller already verified its own (action-specific)
     *                             nonce before calling this method. When false (the default), a generic
     *                             internal nonce check is performed as a backstop — see
     *                             {@see self::nonceVerifiedOrCheck()}.
     * @return bool True on success, false on failure.
     */
    public static function delete(int $form_id, bool $nonce_verified = false): bool
    {
        if (!\FabricatorForms\Plugin::userCan('edit_forms') && !current_user_can('manage_options')) {
            return false;
        }
        if (!self::nonceVerifiedOrCheck($nonce_verified)) {
            return false;
        }
        if (get_post_type($form_id) !== 'fabricator_form') {
            return false;
        }
        return (bool)wp_delete_post($form_id, true);
    }

    /**
     * Returns all fabricator_form posts as model instances. Gated on view_forms.
     *
     * @return self[]
     */
    public static function getAll(): array
    {
        if (!\FabricatorForms\Plugin::userCan('view_forms')) {
            return [];
        }
        // Read a page at a time rather than posts_per_page => -1: an unbounded query loads every form in one go, which
        // Plugin Check flags and which grows without limit on a site that keeps adding forms.
        $posts = [];
        for ($page_offset = 0; $page_offset < 100000; $page_offset += self::QUERY_BATCH) {
            $batch = get_posts(
                [
                'post_type'      => 'fabricator_form',
                'posts_per_page' => self::QUERY_BATCH,
                'offset'         => $page_offset,
                'post_status'    => 'publish',
                'orderby'        => 'title',
                'order'          => 'ASC',
                ]
            );
            $posts = array_merge($posts, $batch);
            if (count($batch) < self::QUERY_BATCH) {
                break;
            }
        }

        /* Prime the meta cache so subsequent get_post_meta() calls in decodeMeta() hit the object cache only. */
        update_meta_cache('post', wp_list_pluck($posts, 'ID'));

        $models = [];
        foreach ($posts as $post) {
            $m                = new self();
            $m->id            = $post->ID;
            $m->title         = $post->post_title;
            $m->fields        = self::decodeMeta($post->ID, 'fabricator_form_fields');
            $m->notifications = self::decodeMeta($post->ID, 'fabricator_form_notifications');
            $m->settings      = self::decodeMeta($post->ID, 'fabricator_form_settings');
            $models[]         = $m;
        }
        return $models;
    }

    /**
     * Drops a deleted form's "<form_id>|<slug>" entries from fabricator_forms_pdf_settings.
     *
     * @param int $post_id The WordPress post ID being deleted.
     * @return void
     */
    public static function removeFormPdfSettings(int $post_id): void
    {
        if (get_post_type($post_id) !== 'fabricator_form') {
            return;
        }
        // Under OptionMutex, like FormEditor::ajaxSave(): a form saved at the same moment rewrites this option too.
        \FabricatorForms\Utils\OptionMutex::run(
            'fabricator_forms_pdf_settings',
            static function () use ($post_id): void {
                $saved = get_option('fabricator_forms_pdf_settings', []);
                if (!is_array($saved) || empty($saved)) {
                    return;
                }
                $prefix  = $post_id . '|';
                $changed = false;
                foreach (array_keys($saved) as $key) {
                    if (strncmp((string) $key, $prefix, strlen($prefix)) === 0) {
                        unset($saved[$key]);
                        $changed = true;
                    }
                }
                if ($changed) {
                    update_option('fabricator_forms_pdf_settings', $saved, false);
                }
            }
        );
    }

    /**
     * Decodes a JSON post meta value into an array.
     *
     * @param int    $id  Post ID.
     * @param string $key Meta key to decode.
     * @return array Decoded array, or empty array on failure.
     */
    private static function decodeMeta(int $id, string $key): array
    {
        $raw = get_post_meta($id, $key, true);
        if (is_array($raw)) {
            return $raw;
        }
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true, 64, JSON_BIGINT_AS_STRING);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    /**
     * CSRF backstop for save()/duplicate()/delete(); falls back to the shared admin nonce if $nonce_verified is false.
     *
     * @param bool $nonce_verified Whether the caller already verified its own nonce.
     * @return bool True if the request may proceed.
     */
    private static function nonceVerifiedOrCheck(bool $nonce_verified): bool
    {
        if ($nonce_verified) {
            return true;
        }
        return (bool) check_ajax_referer('fabricator_forms_admin_nonce', 'nonce', false);
    }
}
