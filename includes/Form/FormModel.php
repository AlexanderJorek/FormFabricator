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
 * @version   1.0.4
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

        $model                = new self();
        $model->id            = $form_id;
        $model->title         = $post->post_title;
        $model->fields        = self::decodeMeta($form_id, 'fabricator_form_fields');
        $model->notifications = self::decodeMeta($form_id, 'fabricator_form_notifications');
        $model->settings      = self::decodeMeta($form_id, 'fabricator_form_settings');

        return $model;
    }

    /**
     * Optimistic-concurrency snapshot token for a form (its post_modified_gmt).
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
        return (string) $post->post_modified_gmt;
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
        // Defense-in-depth: every current admin call site already gates on
        // Plugin::userCan('edit_forms') before reaching here, but this model
        // method should not rely solely on callers remembering to check —
        // a single missed gate anywhere in the admin layer would otherwise be
        // a full privilege-escalation/CSRF path with no second line of defense.
        if (!\FabricatorForms\Plugin::userCan('edit_forms')) {
            return new \WP_Error('forbidden', __('Insufficient permissions.', 'formfabricator'));
        }
        if (!self::nonceVerifiedOrCheck($nonce_verified)) {
            return new \WP_Error('invalid_nonce', __('Security check failed. Please reload and try again.', 'formfabricator'));
        }

        if ($form_id > 0 && $expected_snapshot !== '') {
            $current = get_post($form_id);
            if ($current && $current->post_type === 'fabricator_form' && $current->post_modified_gmt !== $expected_snapshot) {
                return new \WP_Error(
                    'conflict',
                    __('This form was changed in another tab or by another user. Please reload and try again.', 'formfabricator')
                );
            }
        }

        $title = sanitize_text_field(\FabricatorForms\Utils\Sanitize::str($data['title'] ?? null, 'Untitled Form'));

        $post_data = [
            'post_title'  => $title,
            'post_type'   => 'fabricator_form',
            'post_status' => 'publish',
        ];

        if ($form_id > 0) {
            $post_data['ID'] = $form_id;
            $result = wp_update_post($post_data, true);
        } else {
            $result = wp_insert_post($post_data, true);
        }

        if (is_wp_error($result)) {
            return $result;
        }

        $id = (int)$result;

        $fields        = $data['fields']        ?? [];
        $notifications = $data['notifications'] ?? [];
        $settings      = $data['settings']      ?? [];
        update_post_meta($id, 'fabricator_form_fields', $fields);
        update_post_meta($id, 'fabricator_form_notifications', $notifications);
        update_post_meta($id, 'fabricator_form_settings', $settings);

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
        return (bool)wp_delete_post($form_id, true);
    }

    /**
     * Returns all fabricator_form posts as model instances. Gated on view_forms (the least-privilege FormFabricator
     * capability for read access) rather than edit_forms — every current call site (FormList.php's and
     * FormSelectList.php's admin listing pages) is already an admin screen gated on view_forms before it ever
     * reaches this method, so this mirrors that without narrowing legitimate access.
     *
     * @return self[]
     */
    public static function getAll(): array
    {
        if (!\FabricatorForms\Plugin::userCan('view_forms')) {
            return [];
        }
        $posts = get_posts(
            [
            'post_type'      => 'fabricator_form',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'orderby'        => 'title',
            'order'          => 'ASC',
            ]
        );

        /* Prime the meta cache for all posts in one query so subsequent
           get_post_meta() calls inside decodeMeta() hit the object cache only. */
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
     * CSRF backstop for save()/duplicate()/delete(). Every current admin call site (FormEditor.php,
     * FormList.php) already performs its own, more specific nonce check — a per-form action like
     * 'fabricator_forms_delete_{id}', or the shared 'fabricator_forms_admin_nonce' for save/import — before calling
     * into this model, and passes $nonce_verified: true to acknowledge that so this method is a no-op for
     * them. If a future (or forgotten) call site omits $nonce_verified, this falls back to checking the
     * shared admin AJAX nonce so the request is never silently accepted without any CSRF check at all.
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
