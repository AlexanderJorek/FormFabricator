<?php

/**
 * GDPR consent checkbox field.
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

namespace FabricatorForms\Fields;

defined('ABSPATH') || exit;

/**
 * GDPR consent checkbox field.
 */
class GdprField extends BaseField
{
    /**
     * privacy_policy_text renders via esc_html(), never raw HTML — avoids wp_kses_post()+esc_html() double-encoding "&".
     *
     * @return string[]
     */
    protected function plainTextConfigKeys(): array
    {
        return array_merge(parent::plainTextConfigKeys(), ['privacy_policy_text']);
    }

    /**
     * Stores privacy_policy_url as a URL: the HTML sanitizer would double-encode "&", and sanitize_text_field() strips
     * percent-encoding.
     *
     * @param string $key   Config key.
     * @param string $value Raw value.
     * @return string Sanitized value.
     */
    public function sanitizeConfigValue(string $key, string $value): string
    {
        if ($key === 'privacy_policy_url') {
            return esc_url_raw(trim($value));
        }
        return parent::sanitizeConfigValue($key, $value);
    }

    /**
     * Returns field-specific CSS styles.
     *
     * @return string
     */
    public function getStyles(): string
    {
        return self::readFieldAsset('assets/css/fields/GdprField.css');
    }

    /**
     * Its inputs are checkboxes named after the field id, which front.js reads as an empty list while hidden.
     *
     * @return array
     */
    public function hiddenConditionValue(): array
    {
        return [];
    }

    /**
     * A list, as front.js reads a checkbox named after the field: ["1"] when ticked, [] when not. Read as the scalar
     * "1", "greater" and "less" rules compared a number on the server and a list in the browser.
     *
     * @param mixed $raw    What extractValue() returned.
     * @param array $config Field configuration.
     * @return mixed
     */
    public function conditionValue(mixed $raw, array $config): mixed
    {
        return is_scalar($raw) && (string) $raw !== '' ? [(string) $raw] : [];
    }

    /**
     * Returns the field type label.
     *
     * @return string
     */
    public function getType(): string
    {
        return 'gdpr';
    }

    public function getLabel(): string
    {
        return __('GDPR Checkbox', 'formfabricator');
    }

    // Returns false because GDPR acceptance is always mandatory — validate() enforces it unconditionally,
    // so a "required" toggle would be misleading.
    public function hasRequired(): bool
    {
        return false;
    }

    /**
     * Returns the Font Awesome icon class.
     *
     * @return string
     */
    public function getIcon(): string
    {
        return 'fa-solid fa-shield-halved';
    }

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
        // GDPR acceptance is always required by law — 'required' config is ignored (see hasRequired())
        $req     = ' required aria-required="true"';
        $checked = !empty($value) ? ' checked' : '';
        // trim()+?: not ??: these keys are always set, so ?? never fires and the WP privacy-policy fallback would be unreachable.
        $configured_url = trim((string) ($config['privacy_policy_url'] ?? ''));
        $policy_url     = esc_url($configured_url !== '' ? $configured_url : (string) get_privacy_policy_url());
        $configured_txt = trim((string) ($config['privacy_policy_text'] ?? ''));
        $policy_text    = esc_html($configured_txt !== '' ? $configured_txt : __('Privacy policy', 'formfabricator'));

        // <a> built here in PHP, not inside the translatable string — a supplied .mo could otherwise rewrite the anchor's attributes.
        if ($policy_url !== '') {
            // This is a GDPR Art. 13 acknowledgment, not freely-given consent — use ConsentField for that.
            $link = '<a href="' . $policy_url . '" target="_blank" rel="noopener">' . $policy_text . '</a>';
        } else {
            // No URL configured — an href="" link would silently be non-functional; fall back to plain text.
            // Logged once a day, not on every page view that renders the form.
            if (!get_transient('fabricator_gdpr_no_policy_logged')) {
                set_transient('fabricator_gdpr_no_policy_logged', 1, DAY_IN_SECONDS);
                \FabricatorForms\fabricator_log(
                    'FabricatorForms GdprField: no privacy_policy_url configured and no'
                    . ' WP privacy policy page is set — rendering acknowledgment text without a link.'
                );
            }
            $link = $policy_text;
        }
        // The translation is escaped before the link goes in: it comes from a .mo, which can be loaded from WP_LANG_DIR.
        $text = sprintf(
            // translators: %s: the privacy policy, linked when a URL is configured.
            esc_html__('I have read and acknowledge the %s.', 'formfabricator'),
            $link
        );

        $inner = '<label class="fabricator-consent-label fabricator-gdpr-label">'
            . '<input type="checkbox" id="' . esc_attr($field_id)
            . '" name="' . esc_attr($field_id) . '" value="1"' . $checked . $req . '>'
            . '<span class="fabricator-consent-text">' . $text . '</span>'
            . '</label>';

        // Required class passed as an extra class, not via 'required' config: front.js enforces it without adding a label asterisk.
        return $this->wrap($field_id, $config, $inner, 'fabricator-required-field');
    }

    /**
     * Client-side empty check: an unchecked box is empty (the generic fallback reads its value="1" as filled).
     *
     * @return array
     */
    public function getClientEmptyCheck(): array
    {
        return ['fn' => "function(f){return !f.querySelector('input[type=\"checkbox\"]:checked');}"];
    }

    /**
     * Validates the submitted value.
     *
     * @param mixed $value  Submitted value.
     * @param array $config Field configuration.
     * @return bool|string True on valid, error message string on invalid.
     */
    public function validate(mixed $value, array $config): bool|string
    {
        // Always mandatory regardless of the 'required' config flag — the privacy notice
        // acknowledgment can't be made optional, so it errors on empty unconditionally
        if (empty($value)) {
            return __('Please read and acknowledge the privacy policy.', 'formfabricator');
        }
        return true;
    }

    /**
     * Embeds policy text/URL and a timestamp (not a bare boolean) so GDPR Art. 7(1) notice is demonstrable.
     *
     * @param mixed $value  Submitted value.
     * @param array $config Field configuration.
     * @return string Human-readable representation.
     */
    public function map(mixed $value, array $config): string
    {
        if (empty($value)) {
            return __('Privacy notice not acknowledged', 'formfabricator');
        }
        // Same fallback semantics as render() — see the comment there for why ?? is wrong here.
        $configured_txt = trim((string) ($config['privacy_policy_text'] ?? ''));
        $policy_text = wp_strip_all_tags($configured_txt !== '' ? $configured_txt : __('Privacy policy', 'formfabricator'));
        $configured_url = trim((string) ($config['privacy_policy_url'] ?? ''));
        $policy_url  = $configured_url !== '' ? $configured_url : (string) get_privacy_policy_url();
        return sprintf(
            // translators: %1$s: privacy policy link text, %2$s: privacy policy URL, %3$s: acknowledgment timestamp.
            __('Acknowledged "%1$s" (%2$s) on %3$s', 'formfabricator'),
            $policy_text,
            $policy_url,
            // See ConsentField::map() — explicit UTC, not an unmarked local timestamp.
            gmdate('Y-m-d H:i:s') . ' UTC'
        );
    }

    /**
     * Returns the default field configuration.
     *
     * @return array
     */
    public function getDefaultConfig(): array
    {
        return array_merge(
            parent::getDefaultConfig(),
            [
            'privacy_policy_url'  => '',
            'privacy_policy_text' => __('Privacy policy', 'formfabricator'),
            ]
        );
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
                'key'   => 'privacy_policy_url',
                'type'  => 'text',
                'label' => __('Privacy URL', 'formfabricator'),
            ],
            [
                'key'   => 'privacy_policy_text',
                'type'  => 'text',
                'label' => __('Privacy link text', 'formfabricator'),
            ],
        ];
    }
}
