<?php

/**
 * Google reCAPTCHA v2 field.
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
 * Google reCAPTCHA v2 field.
 */
class CaptchaField extends BaseField
{
    /**
     * Returns the field type label.
     *
     * @return string
     */
    public function getType(): string
    {
        return 'captcha';
    }

    public function getLabel(): string
    {
        return __('CAPTCHA', 'formfabricator');
    }

    /**
     * Returns the Font Awesome icon class.
     *
     * @return string
     */
    public function getIcon(): string
    {
        return 'fa-solid fa-robot';
    }

    // Does NOT enqueue the reCAPTCHA script — see render(). Loading google.com/recaptcha/api.js
    // unconditionally would connect the visitor's browser to Google on every page load containing this field,
    // before any interaction or consent (GDPR Art. 13 third-party disclosure). Instead the widget is loaded
    // on demand, from a click-to-activate placeholder rendered in render().
    public function enqueueFrontScripts(): void
    {
    }

    /**
     * Renders the field HTML. Renders a click-to-activate placeholder rather than the live widget: the
     * reCAPTCHA script (and the connection to Google it triggers) is only loaded once the visitor explicitly
     * clicks to enable it, not on page load.
     *
     * @param array  $config   Field configuration.
     * @param string $field_id Unique field identifier.
     * @param mixed  $value    Current field value.
     * @return string Rendered HTML.
     */
    public function render(array $config, string $field_id, mixed $value = null): string
    {
        $site_key = get_option('forge_forms_recaptcha_site_key', '');
        if ($site_key === '') {
            return '<div class="forge-field forge-field--captcha">'
                . '<p class="forge-notice">' . esc_html(__('reCAPTCHA: Please enter the site key in the plugin settings.', 'formfabricator')) . '</p>'
                . '</div>';
        }

        $inner = '<div class="forge-captcha-gate" data-sitekey="' . esc_attr($site_key) . '">'
            . '<button type="button" class="forge-captcha-activate">'
            . esc_html__('Load CAPTCHA', 'formfabricator')
            . '</button>'
            . '<p class="forge-field-hint">'
            . esc_html__('This loads a script from Google (reCAPTCHA) once activated.', 'formfabricator')
            . '</p>'
            . '</div>';
        return $this->wrap($field_id, $config, $inner);
    }

    /**
     * Reads the reCAPTCHA token from the fixed POST key injected by the widget.
     *
     * @param string $field_id Unused — reCAPTCHA always posts to 'g-recaptcha-response'.
     * @return string
     */
    public function extractValue(string $field_id): mixed
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce is verified once in FormProcessor::handle() before field extraction runs.
        $has_response = isset($_POST['g-recaptcha-response']);
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce is verified once in FormProcessor::handle() before field extraction runs.
        return $has_response ? sanitize_text_field(wp_unslash($_POST['g-recaptcha-response'])) : '';
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
        $secret = get_option('forge_forms_recaptcha_secret_key', '');
        if ($secret === '' || empty($value)) {
            return __('Please confirm the CAPTCHA.', 'formfabricator');
        }

        $response = wp_remote_post(
            'https://www.google.com/recaptcha/api/siteverify',
            [
            'timeout' => 5,
            'body'    => [
                'secret'   => $secret,
                'response' => sanitize_text_field((string)$value),
                'remoteip' => sanitize_text_field((string)\ForgeForms\Utils\ClientIp::resolve()),
            ],
            ]
        );

        if (is_wp_error($response)) {
            \ForgeForms\forge_log('ForgeForms CaptchaField: reCAPTCHA request failed — ' . $response->get_error_message());
            return __('CAPTCHA verification could not be completed. Please try again.', 'formfabricator');
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($data['success'])) {
            return __('Please confirm the CAPTCHA.', 'formfabricator');
        }
        return true;
    }

    /**
     * Maps the field value to a human-readable string for email and PDF output.
     *
     * @param mixed $value  Submitted value.
     * @param array $config Field configuration.
     * @return string Human-readable representation.
     */
    public function map(mixed $value, array $config): string
    {
        return __('[CAPTCHA confirmed]', 'formfabricator');
    }

    /**
     * Returns the default field configuration.
     *
     * @return array
     */
    public function getDefaultConfig(): array
    {
        return [
            'label'       => __('CAPTCHA', 'formfabricator'),
            'required'    => true,
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
                'type'  => 'notice',
                'level' => 'info',
                'text'  => __(
                    "This field sends the visitor's CAPTCHA response and IP address to Google (reCAPTCHA) for verification on every submission. Reflect this third-party data transfer in your site's privacy policy.",
                    'formfabricator'
                ),
            ],
        ];
    }
}
