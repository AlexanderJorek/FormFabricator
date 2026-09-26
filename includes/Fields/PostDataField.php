<?php

/**
 * Hidden field that captures WordPress post metadata.
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

namespace FabricatorForms\Fields;

defined('ABSPATH') || exit;

/**
 * Read-only field that displays WordPress post metadata.
 */
class PostDataField extends BaseField
{
    /**
     * Returns the field type label.
     *
     * @return string
     */
    public function getType(): string
    {
        return 'postdata';
    }

    public function getLabel(): string
    {
        return __('Post data', 'formfabricator');
    }

    /**
     * Returns the Font Awesome icon class.
     *
     * @return string
     */
    public function getIcon(): string
    {
        return 'fa-solid fa-database';
    }

    /**
     * Returns false because post-data fields have no required-toggle in the editor.
     *
     * @return bool
     */
    public function hasRequired(): bool
    {
        return false;
    }

    private const ALLOWED_FIELDS = ['post_title', 'post_url', 'post_id', 'post_author'];

    /**
     * Renders the field HTML.
     *
     * @param array  $config   Field configuration.
     * @param string $field_id Unique field identifier.
     * @param mixed  $value    Current field value.
     * @return string Rendered HTML.
     */
    public function render(array $config, string $field_id, mixed $value = null): string
    {
        $selected = (array)($config['post_field'] ?? ['post_title']);
        global $post;
        $out = '';
        foreach ($selected as $key) {
            if (!in_array($key, self::ALLOWED_FIELDS, true)) {
                continue;
            }
            $val = self::resolveField($key, $post);
            $out .= '<input type="hidden" name="' . esc_attr($field_id) . '[' . esc_attr($key) . ']"'
                . ' id="' . esc_attr($field_id . '_' . $key) . '"'
                . ' value="' . esc_attr($val) . '">';
        }
        // extractValue() needs the ID to re-derive values server-side, where global $post is unset. The signature binds it
        // to this field, so a visitor can't swap in another post for the one the mail and PDF record. Static per post,
        // so it survives full-page caching.
        $source_id = (int) ($post?->ID ?? 0);
        $out .= '<input type="hidden" name="' . esc_attr($field_id) . '[_source_post_id]"'
            . ' value="' . esc_attr($source_id ? (string) $source_id : '') . '">';
        $out .= '<input type="hidden" name="' . esc_attr($field_id) . '[_source_sig]"'
            . ' value="' . esc_attr($source_id ? self::sourceSignature($field_id, $source_id) : '') . '">';
        return $out;
    }

    /**
     * HMAC binding a rendered post ID to this field.
     *
     * @param string $field_id Field ID.
     * @param int    $post_id  Post the form was rendered on.
     * @return string Hex HMAC-SHA256.
     */
    private static function sourceSignature(string $field_id, int $post_id): string
    {
        return hash_hmac('sha256', 'fabricator_postdata|' . $field_id . '|' . $post_id, wp_salt('nonce'));
    }

    /**
     * Resolves one post-metadata value for $post. Uses get_the_author_meta() (explicit user ID)
     * instead of get_the_author(), which depends on Loop state extractValue() can't rely on.
     *
     * @param string       $key  One of self::ALLOWED_FIELDS.
     * @param \WP_Post|null $post The resolved post to read from.
     */
    private static function resolveField(string $key, ?\WP_Post $post): string
    {
        return match ($key) {
            'post_title'  => get_the_title($post?->ID ?? 0),
            'post_url'    => (string)get_permalink($post?->ID ?? 0),
            'post_id'     => (string)($post?->ID ?? ''),
            'post_author' => $post ? get_the_author_meta('display_name', (int)$post->post_author) : '',
            default       => '',
        };
    }

    /**
     * Regenerates post metadata server-side; only _source_post_id is taken from $_POST, and only when its
     * _source_sig verifies and get_post() finds it public (global $post is unset during admin-ajax.php).
     *
     * @param string $field_id The field element ID.
     */
    public function extractValue(string $field_id): mixed
    {
        self::assertRequestNonceVerified();
        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified above; only _source_post_id is used, unslashed and absint()'d below.
        $submitted   = isset($_POST[$field_id]) ? wp_unslash($_POST[$field_id]) : null;
        $source_id   = is_array($submitted) && isset($submitted['_source_post_id']) && is_scalar($submitted['_source_post_id'])
            ? absint($submitted['_source_post_id'])
            : 0;
        $signature   = is_array($submitted) && is_string($submitted['_source_sig'] ?? null) ? $submitted['_source_sig'] : '';
        // Only an ID this site rendered for this field counts; a swapped one records no post rather than a chosen one.
        if ($source_id && !hash_equals(self::sourceSignature($field_id, $source_id), $signature)) {
            $source_id = 0;
        }
        $post = $source_id ? get_post($source_id) : null;
        // Client-submitted post ID; reject non-public posts so drafts/private data can't leak into output.
        if ($post && ($post->post_status !== 'publish' || $post->post_password !== '')) {
            $post = null;
        }

        $out = [];
        foreach (self::ALLOWED_FIELDS as $key) {
            $out[$key] = self::resolveField($key, $post);
        }
        return $out;
    }

    /**
     * Maps the post-data array to a comma-separated string for email/PDF output, limited to the fields selected in the field configuration.
     *
     * @param mixed $value  Server-regenerated value (array of post field strings).
     * @param array $config Field configuration.
     */
    public function map(mixed $value, array $config): string
    {
        if (!is_array($value) || empty($value)) {
            return __('[No entry]', 'formfabricator');
        }
        $selected = (array)($config['post_field'] ?? ['post_title']);
        $filtered = array_intersect_key($value, array_flip($selected));
        return implode(', ', array_filter(array_map('strval', $filtered), static fn($v) => $v !== ''));
    }

    /**
     * Returns the default field configuration.
     *
     * @return array
     */
    public function getDefaultConfig(): array
    {
        return [
            'label'       => __('Post data', 'formfabricator'),
            'post_field'  => ['post_title'],
            'description' => '',
        ];
    }

    /**
     * Returns the general settings schema for the field editor.
     *
     * @return array
     */
    public function getGeneralSchema(): array
    {
        return [
            [
                'key'    => 'post_field',
                'type'   => 'pill_multi',
                'label'  => __('Post field', 'formfabricator'),
                'values' => ['post_title', 'post_url', 'post_id', 'post_author'],
                'labels' => [__('Title', 'formfabricator'), __('URL', 'formfabricator'), __('ID', 'formfabricator'), __('Author', 'formfabricator')],
            ],
        ];
    }
}
