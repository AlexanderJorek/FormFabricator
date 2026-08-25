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
 * @version   1.0.3
 * @link      https://github.com/AlexanderJorek/FormFabricator
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 3
 * of the License, or (at your option) any later version.
 */

namespace ForgeForms\Fields;

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
        // extractValue() needs this to re-derive values server-side, where global $post is unset.
        $out .= '<input type="hidden" name="' . esc_attr($field_id) . '[_source_post_id]"'
            . ' value="' . esc_attr((string)($post?->ID ?? '')) . '">';
        return $out;
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
     * Regenerates post metadata server-side; only _source_post_id is trusted from $_POST, and
     * only after validation via get_post() (global $post is unset during admin-ajax.php).
     *
     * @param string $field_id The field element ID.
     */
    public function extractValue(string $field_id): mixed
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- form-wide nonce already verified in FormProcessor::handle(); the only value actually used from this array (_source_post_id) is unslashed and absint()'d below before use.
        $submitted   = isset($_POST[$field_id]) ? wp_unslash($_POST[$field_id]) : null;
        $source_id   = is_array($submitted) && isset($submitted['_source_post_id'])
            ? absint($submitted['_source_post_id'])
            : 0;
        $post = $source_id ? get_post($source_id) : null;

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
