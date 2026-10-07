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
 * @version   1.0.8
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
 * CAPTCHA field, by one of two providers chosen per field: ALTCHA, a proof-of-work check this site hosts and verifies
 * itself (Utils\Altcha, vendor/altcha), or Google reCAPTCHA v2, which loads only after a click.
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

    /**
     * Hands the ALTCHA widget the field's translated texts.
     *
     * @return string
     */
    public function getClientInit(): string
    {
        return self::readFieldAsset('assets/js/fields/CaptchaField.js');
    }

    // front.js's generic empty-check skips hidden inputs and would read the ALTCHA widget's own checkbox, never empty.
    public function getClientEmptyCheck(): array
    {
        return ['fn' => self::readFieldAsset('assets/js/fields/CaptchaField.emptycheck.js')];
    }

    /**
     * Renders a click-to-activate placeholder; the reCAPTCHA script loads only after an explicit click.
     *
     * @param array  $config   Field configuration.
     * @param string $field_id Unique field identifier.
     * @param mixed  $value    Current field value.
     * @return string Rendered HTML.
     */
    public function render(array $config, string $field_id, mixed $value = null): string
    {
        if (self::provider($config) === 'altcha') {
            return $this->renderAltcha($config, $field_id);
        }
        $site_key = get_option('fabricator_forms_recaptcha_site_key', '');
        if ($site_key === '') {
            // The setup hint only for whoever can fix it. The form stays blocked: skipping the check would invite spam.
            $message = \FabricatorForms\Plugin::userCan('settings')
                ? __('reCAPTCHA: Please enter the site key in the plugin settings.', 'formfabricator')
                : __('This form cannot be sent at the moment. Please try again later.', 'formfabricator');
            return '<div class="fabricator-field fabricator-field--captcha">'
                . '<p class="fabricator-notice">' . esc_html($message) . '</p>'
                . '</div>';
        }

        $inner = '<div class="fabricator-captcha-gate" data-sitekey="' . esc_attr($site_key) . '">'
            . '<button type="button" class="fabricator-captcha-activate">'
            . esc_html__('Load CAPTCHA', 'formfabricator')
            . '</button>'
            . '<p class="fabricator-field-hint">'
            . esc_html__('This loads a script from Google (reCAPTCHA) once activated.', 'formfabricator')
            . '</p>'
            . '</div>';
        return $this->wrap($field_id, $config, $inner);
    }

    /**
     * The ALTCHA widget: fetches a challenge from this site, solves it in the browser and posts the solution under the
     * field's id. Its texts are the plugin's own translations (language "fabricator" in CaptchaField.js).
     *
     * @param array  $config   Field configuration.
     * @param string $field_id Field identifier.
     * @return string
     */
    private function renderAltcha(array $config, string $field_id): string
    {
        // A script module: the widget's own top-level names stay out of the page's scope. Printed in the footer.
        wp_enqueue_script_module('fabricator-altcha', FABRICATOR_FORMS_URL . 'vendor/altcha/altcha.min.js', [], FABRICATOR_FORMS_VERSION);
        $strings = [
            'cancel'               => __('Cancel', 'formfabricator'),
            'error'                => __('Verification failed. Try again later.', 'formfabricator'),
            'expired'              => __('Verification expired. Try again.', 'formfabricator'),
            'footer'               => '',
            'label'                => __("I'm not a robot", 'formfabricator'),
            'loading'              => __('Loading…', 'formfabricator'),
            'reload'               => __('Reload', 'formfabricator'),
            'verify'               => __('Verify', 'formfabricator'),
            'verificationRequired' => __('Verification required!', 'formfabricator'),
            'verified'             => __('Verified', 'formfabricator'),
            'verifying'            => __('Verifying…', 'formfabricator'),
            'waitAlert'            => __('Verifying… please wait.', 'formfabricator'),
        ];
        $inner = '<altcha-widget'
            . ' challenge="' . esc_url(admin_url('admin-ajax.php?action=fabricator_altcha_challenge')) . '"'
            . ' name="' . esc_attr($field_id) . '"'
            . ' language="fabricator"'
            // Starts solving as soon as the visitor works on the form, so the check is usually done before they reach it.
            . ' auto="onfocus"'
            // No footer link to altcha.org, and no recording of the visitor's pointer, scroll and focus movements (the
            // "human interaction signature"): this site never asks for them.
            . ' configuration="' . esc_attr((string) wp_json_encode(['hideFooter' => true, 'hideLogo' => true, 'humanInteractionSignature' => false])) . '"'
            . ' data-strings="' . esc_attr(\FabricatorForms\Utils\Cast::jsonForAttribute($strings)) . '"></altcha-widget>';
        return $this->wrap($field_id, $config, $inner);
    }

    /**
     * The field's provider: 'recaptcha' when chosen, 'altcha' otherwise.
     *
     * @param array $config Field configuration.
     * @return string
     */
    private static function provider(array $config): string
    {
        return ($config['provider'] ?? '') === 'recaptcha' ? 'recaptcha' : 'altcha';
    }

    /**
     * Reads the CAPTCHA answer: the ALTCHA widget's payload, posted under the field's id, or else reCAPTCHA's token,
     * which its widget always posts as 'g-recaptcha-response'. No reCAPTCHA field posts anything under its own id.
     *
     * @param string $field_id The field's id.
     * @return string
     */
    public function extractValue(string $field_id): mixed
    {
        self::assertRequestNonceVerified();
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above via assertRequestNonceVerified().
        if (isset($_POST[$field_id])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above via assertRequestNonceVerified().
            return sanitize_text_field(wp_unslash($_POST[$field_id]));
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above via assertRequestNonceVerified().
        $has_response = isset($_POST['g-recaptcha-response']);
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce is verified once in FormProcessor::handle() before field extraction runs.
        return $has_response ? sanitize_text_field(wp_unslash($_POST['g-recaptcha-response'])) : '';
    }

    /**
     * Checked only after every other field passes: the token is single-use, so a submission that fails elsewhere
     * would use it up and make the retry fail with "Please confirm the CAPTCHA".
     *
     * @return bool
     */
    public function defersValidation(): bool
    {
        return true;
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
        if (self::provider($config) === 'altcha') {
            return is_string($value) && $value !== '' && \FabricatorForms\Utils\Altcha::verify($value)
                ? true
                : __('Please confirm the CAPTCHA.', 'formfabricator');
        }
        $secret = get_option('fabricator_forms_recaptcha_secret_key', '');
        if ($secret === '' || empty($value)) {
            return __('Please confirm the CAPTCHA.', 'formfabricator');
        }

        // One siteverify call per token per request. Every Captcha field reads the same g-recaptcha-response, and a
        // token is single-use, so a second Captcha field on the form asked Google again, failed, and blocked the form.
        $cache_key = hash('sha256', (string) $value);
        if (!array_key_exists($cache_key, self::$verified)) {
            self::$verified[$cache_key] = self::verifyToken((string) $value, (string) $secret);
        }
        return self::$verified[$cache_key];
    }

    /**
     * siteverify outcomes for this request, keyed by token hash.
     *
     * @var array<string, bool|string>
     */
    private static array $verified = [];

    /**
     * Asks Google whether a reCAPTCHA token is valid for this site.
     *
     * @param string $token  The g-recaptcha-response value.
     * @param string $secret The reCAPTCHA secret key.
     * @return bool|string True when valid, otherwise the message for the visitor.
     */
    private static function verifyToken(string $token, string $secret): bool|string
    {
        $response = wp_remote_post(
            'https://www.google.com/recaptcha/api/siteverify',
            [
            'timeout' => 5,
            // No 'remoteip': it is optional, and sending every visitor's address to Google isn't needed to verify a token.
            'body'    => [
                'secret'   => $secret,
                'response' => sanitize_text_field($token),
            ],
            ]
        );

        if (is_wp_error($response)) {
            \FabricatorForms\fabricator_log('FabricatorForms CaptchaField: reCAPTCHA request failed — ' . $response->get_error_message());
            return __('CAPTCHA verification could not be completed. Please try again.', 'formfabricator');
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($data['success'])) {
            return __('Please confirm the CAPTCHA.', 'formfabricator');
        }
        // Prevents a token minted on another site sharing the same reCAPTCHA site key from verifying here.
        $allowed_hosts = self::allowedCaptchaHosts();
        $reported_host = strtolower(trim((string) ($data['hostname'] ?? '')));
        if (!empty($allowed_hosts) && !in_array($reported_host, $allowed_hosts, true)) {
            \FabricatorForms\fabricator_log(
                'FabricatorForms CaptchaField: reCAPTCHA hostname mismatch — allowed "'
                . implode(', ', $allowed_hosts) . '", got "' . $reported_host . '". If this site is '
                . 'reachable on a hostname not listed, add it via the fabricator_recaptcha_allowed_hosts filter.'
            );
            return __('Please confirm the CAPTCHA.', 'formfabricator');
        }
        return true;
    }

    /**
     * Hostnames a reCAPTCHA token may legitimately have been minted on (apex + www by default).
     *
     * @return string[] Lowercased hostnames; empty disables the check entirely.
     */
    private static function allowedCaptchaHosts(): array
    {
        $home = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
        $hosts = [];
        if ($home !== '') {
            $hosts[] = $home;
            $hosts[] = str_starts_with($home, 'www.') ? substr($home, 4) : 'www.' . $home;
        }

        /**
         * Filters the hostnames accepted in a reCAPTCHA siteverify response; empty disables the check.
         *
         * @param string[] $hosts Default: the home_url() host plus its apex/www counterpart.
         */
        // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- already lowercase/underscored.
        $filtered = apply_filters('fabricator_recaptcha_allowed_hosts', $hosts);

        if (!is_array($filtered)) {
            return $hosts;
        }
        $out = [];
        foreach ($filtered as $host) {
            if (!is_string($host)) {
                continue;
            }
            $host = strtolower(trim($host));
            if ($host !== '') {
                $out[] = $host;
            }
        }
        return array_values(array_unique($out));
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
            // Works without setup and sends nothing to a third party; provider() reads a missing key the same way.
            'provider'    => 'altcha',
        ];
    }

    /**
     * Returns the general settings schema for the field editor.
     *
     * @return array
     */
    public function getGeneralSchema(): array
    {
        $by = static fn(string $provider): array => ['key' => 'provider', 'is' => $provider, 'default' => 'altcha'];
        return [
            [
                'key'     => 'provider',
                'type'    => 'pill3',
                'label'   => __('Provider', 'formfabricator'),
                'values'  => ['altcha', 'recaptcha'],
                'labels'  => [__('ALTCHA (on this site)', 'formfabricator'), __('reCAPTCHA (Google)', 'formfabricator')],
                'default' => 'altcha',
                'rebuild' => true,
            ],
            [
                'type'       => 'notice',
                'level'      => 'info',
                'text'       => __(
                    "Checked on this site: the visitor's browser solves a small calculation. No third party is involved, and nothing is stored about the visitor. Needs HTTPS: browsers allow the calculation only on secure pages.",
                    'formfabricator'
                ),
                'depends_on' => $by('altcha'),
            ],
            [
                'type'       => 'notice',
                'level'      => 'info',
                'depends_on' => $by('recaptcha'),
                // What the code does, not more: verifyToken() sends no remoteip, and the check runs only once every other
                // field has passed. The visitor's browser still connects to Google when the CAPTCHA loads.
                'text'  => __(
                    "The visitor's browser connects to Google (reCAPTCHA) when the CAPTCHA loads, so Google sees their IP address. Once the rest of the form is valid, this site sends the CAPTCHA answer to Google. Say so in your privacy policy.",
                    'formfabricator'
                ),
            ],
        ];
    }
}
