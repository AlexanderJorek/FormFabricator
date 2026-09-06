<?php

/**
 * SEPA direct debit mandate composite field (IBAN, BIC, signature).
 *
 * PHP Version 8.1
 *
 * @category  FormFabricator
 * @package   FormFabricator
 * @author    Alexander Jorek
 * @copyright 2026 Alexander Jorek
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 * @version   1.0.6
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
 * SEPA direct debit mandate composite field with IBAN, BIC, and a signature canvas.
 */
class SepaField extends BaseField
{
    /**
     * Adds this field's label-like keys — all rendered via esc_html(), never as raw HTML — to
     * the base plain-text allowlist. mandate_text/mandate_note are intentionally excluded: their
     * schema entries say "HTML allowed" and render() passes them through wp_kses_post().
     *
     * @return string[]
     */
    protected function plainTextConfigKeys(): array
    {
        return array_merge(
            parent::plainTextConfigKeys(),
            [
                'mandate_title', 'iban_label', 'bic_label', 'holder_label',
                'creditor_id', 'mandate_ref', 'sig_label',
                // esc_attr()'d into a data- attribute at render, never HTML.
                'placeholder_country',
            ]
        );
    }

    /**
     * Returns the client-side empty-check function for the required validator.
     *
     * @return array
     */
    public function getClientEmptyCheck(): array
    {
        /* Validators must always run so that BIC/Kontoinhaber/Sig can show
           their own error messages. The sepa-required validator handles all
           required-field checks, including IBAN. */
        return ['fn' => self::readFieldAsset('assets/js/fields/SepaField.emptycheck.js')];
    }

    /**
     * Returns client-side validation rules.
     *
     * @return array
     */
    public function getClientValidation(): array
    {
        return [
            [
                'rule' => 'iban',
                'fn'   => self::readFieldAsset('assets/js/fields/SepaField.iban.js'),
            ],
            [
                'rule' => 'sepa-bic',
                'fn'   => self::readFieldAsset('assets/js/fields/SepaField.sepa-bic.js'),
            ],
            [
                'rule' => 'sepa-required',
                'fn'   => self::readFieldAsset('assets/js/fields/SepaField.sepa-required.js'),
            ],
        ];
    }

    /**
     * Returns field-specific CSS styles.
     *
     * @return string
     */
    public function getStyles(): string
    {
        return self::readFieldAsset('assets/css/fields/SepaField.css');
    }

    /**
     * Returns the field type label.
     *
     * @return string
     */
    public function getType(): string
    {
        return 'sepa';
    }

    public function getLabel(): string
    {
        return __('SEPA Direct Debit', 'formfabricator');
    }

    /**
     * Returns the Font Awesome icon class.
     *
     * @return string
     */
    public function getIcon(): string
    {
        return 'fa-solid fa-building-columns';
    }

    /**
     * Returns client-side initialization JavaScript function body.
     *
     * @return string
     */
    public function getClientInit(): string
    {
        return self::readFieldAsset('assets/js/fields/SepaField.js');
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
        $val = is_array($value) ? $value : [];

        $mandate_title = esc_html($config['mandate_title'] ?? __('SEPA Direct Debit Mandate', 'formfabricator'));
        $mandate_text  = wp_kses_post($config['mandate_text'] ?? $this->defaultMandateText());
        $mandate_note  = wp_kses_post($config['mandate_note'] ?? $this->defaultMandateNote());
        $iban_label    = esc_html($config['iban_label']    ?? __('IBAN:', 'formfabricator'));
        $bic_label     = esc_html($config['bic_label']     ?? __('BIC:', 'formfabricator'));
        $holder_label  = esc_html($config['holder_label']  ?? __('Account holder:', 'formfabricator'));
        $creditor_id   = esc_html($config['creditor_id']   ?? '');
        $mandate_ref   = esc_html($config['mandate_ref']   ?? __('Your member number', 'formfabricator'));
        $sig_label     = esc_html($config['sig_label']     ?? __('Signature', 'formfabricator'));
        $req_attr      = !empty($config['required']) ? ' data-required="true"' : '';

        $placeholder_cc    = esc_attr($config['placeholder_country'] ?? 'DE');
        $country_filter    = $config['country_filter_mode'] ?? 'off';
        $country_list      = is_array($config['country_filter_list'] ?? null)
            ? $config['country_filter_list'] : [];
        $filter_attr       = $country_filter !== 'off' && !empty($country_list)
            ? ' data-country-filter="' . esc_attr($country_filter)
                . '" data-country-list="' . esc_attr(implode(',', $country_list)) . '"'
            : '';

        $iban_val   = esc_attr($val['iban']   ?? '');
        $bic_val    = esc_attr($val['bic']    ?? '');
        $holder_val = esc_attr($val['holder'] ?? '');

        $rules = array_column($this->getClientValidation(), 'rule');
        $html  = '<div class="fabricator-field fabricator-field--sepa" data-field-id="' . esc_attr($field_id) . '"'
            . $req_attr . ' data-validate="' . esc_attr(wp_json_encode($rules)) . '">';
        $html .= '<div class="fabricator-sepa-mandate">';
        $html .= '<h3 class="fabricator-sepa-title">' . $mandate_title . '</h3>';
        $html .= '<div class="fabricator-sepa-text">' . $mandate_text . '</div>';

        if ($mandate_note !== '') {
            $html .= '<p class="fabricator-sepa-note">' . $mandate_note . '</p>';
        }

        /* ---- IBAN ---- */
        $html .= '<div class="fabricator-field-inner">';
        $html .= '<label class="fabricator-label" for="' . esc_attr($field_id) . '-iban">' . $iban_label;
        if (!empty($config['required'])) {
            $html .= ' <span class="fabricator-required" aria-hidden="true">*</span>';
        }
        $html .= '</label>';
        // live_iban_lookup defaults to false (opt-in, GDPR) since enabling it relays the IBAN to openiban.com pre-submission.
        $live_lookup = isset($config['live_iban_lookup']) && !empty($config['live_iban_lookup']);
        $html .= '<input type="text" id="' . esc_attr($field_id) . '-iban"'
            . ' name="' . esc_attr($field_id) . '[iban]"'
            . ' class="fabricator-input fabricator-sepa-iban"'
            . ' maxlength="42" autocomplete="off"'
            . ' inputmode="text" spellcheck="false"'
            . ' data-placeholder-country="' . $placeholder_cc . '"'
            . ' data-live-lookup="' . ($live_lookup ? '1' : '0') . '"'
            . $filter_attr
            . ' value="' . $iban_val . '">';
        $html .= '<div class="fabricator-field-hint"></div>';
        if ($live_lookup) {
            // GDPR Art. 13 transparency: disclose the live IBAN lookup to the visitor, not just the admin.
            $html .= '<p class="fabricator-sepa-lookup-notice">'
                . esc_html__(
                    'Your IBAN is sent to a third-party service (openiban.com) to look up the BIC.',
                    'formfabricator'
                ) . '</p>';
        }
        $html .= '<div class="fabricator-field-error" id="' . esc_attr($field_id) . '-iban-error" role="alert"></div>';
        $html .= '</div>';

        /* ---- BIC ---- */
        $html .= '<div class="fabricator-field-inner">';
        $html .= '<label class="fabricator-label" for="' . esc_attr($field_id) . '-bic">' . $bic_label;
        if (!empty($config['required'])) {
            $html .= ' <span class="fabricator-required" aria-hidden="true">*</span>';
        }
        $html .= '</label>';
        $html .= '<input type="text" id="' . esc_attr($field_id) . '-bic"'
            . ' name="' . esc_attr($field_id) . '[bic]"'
            . ' class="fabricator-input fabricator-sepa-bic"'
            . ' placeholder="XXXXXXXXXXX" maxlength="11" autocomplete="off"'
            . ' value="' . $bic_val . '">';
        $html .= '<div class="fabricator-field-error" id="' . esc_attr($field_id) . '-bic-error" role="alert"></div>';
        $html .= '</div>';

        /* ---- Kontoinhaber ---- */
        $html .= '<div class="fabricator-field-inner">';
        $html .= '<label class="fabricator-label" for="' . esc_attr($field_id) . '-holder">' . $holder_label;
        if (!empty($config['required'])) {
            $html .= ' <span class="fabricator-required" aria-hidden="true">*</span>';
        }
        $html .= '</label>';
        $html .= '<input type="text" id="' . esc_attr($field_id) . '-holder"'
            . ' name="' . esc_attr($field_id) . '[holder]"'
            . ' class="fabricator-input fabricator-sepa-holder" autocomplete="off"'
            . ' value="' . $holder_val . '">';
        $html .= '<div class="fabricator-field-error" id="' . esc_attr($field_id) . '-holder-error" role="alert"></div>';
        $html .= '</div>';

        /* ---- Static creditor info ---- */
        if ($creditor_id !== '' || $mandate_ref !== '') {
            $html .= '<div class="fabricator-sepa-creditor">';
            if ($creditor_id !== '') {
                $html .= '<p>' . esc_html__('Creditor identification number:', 'formfabricator') . ' ' . $creditor_id . '</p>';
            }
            if ($mandate_ref !== '') {
                $html .= '<p>' . esc_html__('Mandate reference:', 'formfabricator') . ' ' . $mandate_ref . '</p>';
            }
            $html .= '</div>';
        }

        /* ---- Signature ---- */
        $canvas_id     = esc_attr($field_id) . '-sig-canvas';
        $canvas_height = (int)($config['canvas_height'] ?? 200);
        $stroke_width  = (float)($config['stroke_width'] ?? 2);
        $html .= '<div class="fabricator-sepa-signatures">';
        $html .= '<div class="fabricator-sepa-sig-block">';
        $html .= '<div class="fabricator-sepa-sig-label">' . $sig_label;
        if (!empty($config['required'])) {
            $html .= ' <span class="fabricator-required" aria-hidden="true">*</span>';
        }
        $html .= '</div>';
        $html .= '<div class="fabricator-signature-wrap"'
            . ' data-field-id="' . esc_attr($field_id) . '-sig"'
            . (!empty($config['required']) ? ' data-required="true"' : '')
            . ' data-stroke="' . esc_attr((string)$stroke_width) . '">';
        $html .= '<canvas id="' . $canvas_id . '" class="fabricator-signature-canvas"'
            . ' width="400" height="' . $canvas_height . '"'
            . ' style="height:' . $canvas_height . 'px"'
            . ' tabindex="0" aria-label="' . $sig_label . '"></canvas>';
        $html .= '<input type="hidden"'
            . ' name="' . esc_attr($field_id) . '-sig"'
            . ' id="' . esc_attr($field_id) . '-sig-data"'
            . ' class="fabricator-sepa-sig-data">';
        $html .= '<div class="fabricator-signature-toolbar">';
        $reset_icon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"'
            . ' aria-hidden="true" focusable="false">'
            . '<path d="M125.7 160H176a16 16 0 0 1 0 32H48a16 16 0 0 1-16-16V48a16 16 0 0 1 32 0v68.7'
            . 'C115.3 45.1 191.6 0 278 0c141.4 0 256 114.6 256 256S419.4 512 278 512'
            . 'C167.7 512 74.4 443.5 38 346a16 16 0 1 1 30-11c31.4 83.7 111.5 141 210 141'
            . ' 123.7 0 224-100.3 224-224S401.7 32 278 32c-78.1 0-145.8 39.4-185.3 99.3z"/>'
            . '</svg>';
        $html .= '<button type="button" class="fabricator-signature-clear"'
            . ' data-canvas="' . $canvas_id . '" title="' . esc_attr__('Clear', 'formfabricator') . '" aria-label="' . esc_attr__('Clear signature', 'formfabricator') . '">'
            . $reset_icon . '</button>';
        $html .= '<span class="fabricator-signature-hint">' . esc_html__('Sign here', 'formfabricator') . '</span>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '<div class="fabricator-field-error fabricator-sepa-sig-error"'
            . ' id="' . esc_attr($field_id) . '-sig-error" role="alert"></div>';
        $html .= '</div>'; /* sig-block */
        $html .= '</div>'; /* signatures */

        $html .= '<div class="fabricator-field-error" id="'
            . esc_attr($field_id) . '-error" role="alert" aria-live="polite"></div>';
        $html .= '</div>'; /* fabricator-sepa-mandate */
        $html .= '</div>'; /* fabricator-field */

        return $html;
    }

    /**
     * Returns the composite SEPA array (IBAN, BIC, Kontoinhaber, signature); signature posts under a separate '-sig' key.
     *
     * @param string $field_id The field element ID.
     */
    public function extractValue(string $field_id): mixed
    {
        self::assertRequestNonceVerified();
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above via assertRequestNonceVerified().
        $raw = isset($_POST[$field_id])
            // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified above; map_deep()/capRawArray() sanitizes, WPCS misses the callback form.
            ? map_deep(self::capRawArray(wp_unslash($_POST[$field_id])), 'sanitize_text_field')
            : [];
        if (!is_array($raw)) {
            return null;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce is verified once in FormProcessor::handle() before field extraction runs.
        $sig_raw = sanitize_text_field(wp_unslash($_POST[$field_id . '-sig'] ?? ''));
        return [
            'iban'   => sanitize_text_field($raw['iban']   ?? ''),
            'bic'    => sanitize_text_field($raw['bic']    ?? ''),
            'holder' => sanitize_text_field($raw['holder'] ?? ''),
            'sig'    => sanitize_text_field((string)$sig_raw),
        ];
    }

    /**
     * Country code => canonical IBAN length. Mirrors the IBAN_LEN table in this field's client-side JS (used there for input masking/placeholder).
     *
     * @var array<string, int>
     */
    private const IBAN_LEN = [
        'AD' => 24, 'AE' => 23, 'AL' => 28, 'AT' => 20, 'AZ' => 28, 'BA' => 20, 'BE' => 16,
        'BG' => 22, 'BH' => 22, 'BI' => 27, 'BR' => 29, 'BY' => 28, 'CH' => 21, 'CR' => 22,
        'CY' => 28, 'CZ' => 24, 'DE' => 22, 'DJ' => 27, 'DK' => 18, 'DO' => 28, 'EE' => 20,
        'EG' => 29, 'ES' => 24, 'FI' => 18, 'FK' => 18, 'FO' => 18, 'FR' => 27, 'GB' => 22,
        'GE' => 22, 'GI' => 23, 'GL' => 18, 'GR' => 27, 'GT' => 28, 'HR' => 21, 'HU' => 28,
        'IE' => 22, 'IL' => 23, 'IQ' => 23, 'IS' => 26, 'IT' => 27, 'JO' => 30, 'KW' => 30,
        'KZ' => 20, 'LB' => 28, 'LC' => 32, 'LI' => 21, 'LT' => 20, 'LU' => 20, 'LV' => 21,
        'LY' => 25, 'MC' => 27, 'MD' => 24, 'ME' => 22, 'MK' => 19, 'MN' => 20, 'MR' => 27,
        'MT' => 31, 'MU' => 30, 'NI' => 28, 'NL' => 18, 'NO' => 15, 'OM' => 23, 'PK' => 24,
        'PL' => 28, 'PS' => 29, 'PT' => 25, 'QA' => 29, 'RO' => 24, 'RS' => 22, 'RU' => 33,
        'SA' => 24, 'SC' => 31, 'SD' => 18, 'SE' => 24, 'SI' => 19, 'SK' => 24, 'SM' => 27,
        'SO' => 23, 'ST' => 25, 'SV' => 28, 'TL' => 23, 'TN' => 24, 'TR' => 26, 'UA' => 29,
        'VA' => 22, 'VG' => 24, 'XK' => 20, 'YE' => 30,
    ];

    /**
     * Verifies the ISO 7064 mod-97 checksum of a cleaned (no-space, uppercase) IBAN.
     *
     * @param string $iban Cleaned IBAN string.
     * @return bool True when the check digits are valid.
     */
    private static function ibanChecksumValid(string $iban): bool
    {
        $rearranged = substr($iban, 4) . substr($iban, 0, 4);
        $numeric = '';
        foreach (str_split($rearranged) as $ch) {
            $numeric .= ctype_alpha($ch) ? (string)(ord($ch) - 55) : $ch;
        }
        // Mod-97 over a (potentially 30+ digit) numeric string without bcmath —
        // process in chunks so we never rely on an optional PHP extension for
        // a check that runs on every SEPA form submission.
        $remainder = 0;
        foreach (str_split($numeric, 7) as $chunk) {
            $remainder = (int) (($remainder . $chunk) % 97);
        }
        return $remainder === 1;
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
        // Signature size cap applies even when the field is optional, since extractValue() has no bound of its own.
        if (is_array($value) && strlen((string)($value['sig'] ?? '')) > 2 * 1024 * 1024) {
            return __('Signature data is too large.', 'formfabricator');
        }

        $required = !empty($config['required']);

        if (!is_array($value)) {
            return $required ? __('SEPA data missing.', 'formfabricator') : true;
        }

        $iban   = trim((string)($value['iban']   ?? ''));
        $bic    = trim((string)($value['bic']    ?? ''));
        $holder = trim((string)($value['holder'] ?? ''));
        $sig    = (string)($value['sig'] ?? '');

        // Optional and fully empty is fine, but any partial data must still be well-formed.
        if (!$required && $iban === '' && $bic === '' && $holder === '' && $sig === '') {
            return true;
        }

        if ($iban === '') {
            if ($required) {
                return __('IBAN is a required field.', 'formfabricator');
            }
        } else {
            $iban_clean = strtoupper(preg_replace('/\s/', '', $iban));
            if (!preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/', $iban_clean)) {
                return __('Please enter a valid IBAN.', 'formfabricator');
            }
            $iban_cc = substr($iban_clean, 0, 2);
            if (isset(self::IBAN_LEN[$iban_cc]) && strlen($iban_clean) !== self::IBAN_LEN[$iban_cc]) {
                return __('Please enter a valid IBAN.', 'formfabricator');
            }
            if (!self::ibanChecksumValid($iban_clean)) {
                return __('Please enter a valid IBAN.', 'formfabricator');
            }

            // Re-checks the country allow/disallow list server-side even though front.js
            // does the same check for instant feedback — the client check alone could be
            // bypassed by a direct POST
            $filter_mode = $config['country_filter_mode'] ?? 'off';
            $filter_list = is_array($config['country_filter_list'] ?? null)
                ? array_map('strtoupper', $config['country_filter_list'])
                : [];
            $iban_country = substr($iban_clean, 0, 2);

            if ($filter_mode === 'allow' && !empty($filter_list)) {
                if (!in_array($iban_country, $filter_list, true)) {
                    // translators: %s: two-letter IBAN country code.
                    return sprintf(__('IBANs from country "%s" are not allowed.', 'formfabricator'), esc_html($iban_country));
                }
            } elseif ($filter_mode === 'disallow' && !empty($filter_list)) {
                if (in_array($iban_country, $filter_list, true)) {
                    // translators: %s: two-letter IBAN country code.
                    return sprintf(__('IBANs from country "%s" are not allowed.', 'formfabricator'), esc_html($iban_country));
                }
            }
        }

        if ($bic === '') {
            if ($required) {
                return __('BIC is a required field.', 'formfabricator');
            }
        } elseif (!preg_match('/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/i', $bic)) {
            return __('Please enter a valid BIC.', 'formfabricator');
        }

        if ($holder === '' && $required) {
            return __('Account holder is a required field.', 'formfabricator');
        }

        if ($required && $sig === '') {
            return __('Signature is a required field.', 'formfabricator');
        }
        if ($sig !== '' && !self::isSignatureDataUri($sig)) {
            return __('Signature is a required field.', 'formfabricator');
        }

        return true;
    }

    /**
     * Returns normalized entries for IBAN, BIC, Kontoinhaber, and signature.
     *
     * @param string $field_id Field identifier.
     * @param string $label    Field label.
     * @param mixed  $value    Raw submitted value.
     * @param array  $config   Field configuration.
     * @param array  $context  Submission context.
     * @return array<string, array>
     */
    public function mapNormalized(
        string $field_id,
        string $label,
        mixed $value,
        array $config,
        array $context
    ): array {
        if (!is_array($value)) {
            return [$field_id => [
                'label'              => $label,
                'type'               => 'sepa',
                'value'              => __('[No entry]', 'formfabricator'),
                'materialized_files' => [],
            ]];
        }

        $iban   = strtoupper(
            preg_replace('/\s/', '', (string)($value['iban'] ?? ''))
        );
        $bic    = strtoupper((string)($value['bic']    ?? ''));
        $holder = (string)($value['holder'] ?? '');

        $entries = [
            $field_id . '_iban' => [
                'label' => $config['iban_label'] ?? __('IBAN', 'formfabricator'),
                'type'  => 'sepa',
                'value' => $iban !== ''
                    ? chunk_split($iban, 4, ' ') : __('[No entry]', 'formfabricator'),
            ],
            $field_id . '_bic' => [
                'label' => $config['bic_label'] ?? __('BIC', 'formfabricator'),
                'type'  => 'sepa',
                'value' => $bic !== '' ? $bic : __('[No entry]', 'formfabricator'),
            ],
            $field_id . '_holder' => [
                'label' => $config['holder_label'] ?? __('Account holder', 'formfabricator'),
                'type'  => 'sepa',
                'value' => $holder !== '' ? $holder : __('[No entry]', 'formfabricator'),
            ],
        ];

        $sig_val      = $value['sig'] ?? '';
        $materialized = $sig_val !== ''
            ? self::materializeSignature($sig_val, 'sepa-signature.png')
            : [];
        $entries[$field_id . '_sig'] = [
            'label'              => $config['sig_label'] ?? __('Signature', 'formfabricator'),
            'type'               => 'signature',
            'value'              => $materialized ? '' : __('[No entry]', 'formfabricator'),
            'materialized_files' => $materialized,
        ];

        return $entries;
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
        if (!is_array($value)) {
            return __('[No entry]', 'formfabricator');
        }
        $iban   = strtoupper(preg_replace('/\s/', '', (string)($value['iban']   ?? '')));
        $bic    = strtoupper((string)($value['bic']    ?? ''));
        $holder = (string)($value['holder'] ?? '');

        $parts = array_filter(
            [
            // translators: %s: formatted IBAN.
            $iban   !== '' ? sprintf(__('IBAN: %s', 'formfabricator'), wordwrap($iban, 4, ' ', true)) : '',
            // translators: %s: BIC.
            $bic    !== '' ? sprintf(__('BIC: %s', 'formfabricator'), $bic) : '',
            // translators: %s: account holder name.
            $holder !== '' ? sprintf(__('Account holder: %s', 'formfabricator'), $holder) : '',
            ]
        );
        return $parts ? trim(implode(' | ', $parts)) : __('[No entry]', 'formfabricator');
    }

    /**
     * Returns the default field configuration.
     *
     * @return array
     */
    public function getDefaultConfig(): array
    {
        return [
            'label'               => __('SEPA Direct Debit Mandate', 'formfabricator'),
            'required'            => true,
            'description'         => '',
            'mandate_title'       => __('SEPA Direct Debit Mandate', 'formfabricator'),
            'mandate_text'        => $this->defaultMandateText(),
            'mandate_note'        => $this->defaultMandateNote(),
            'iban_label'          => __('IBAN:', 'formfabricator'),
            'bic_label'           => __('BIC:', 'formfabricator'),
            'holder_label'        => __('Account holder:', 'formfabricator'),
            'creditor_id'         => '',
            'mandate_ref'         => '',
            'sig_label'           => __('Signature', 'formfabricator'),
            'canvas_height'       => 200,
            'stroke_width'        => 2,
            'placeholder_country' => 'DE',
            'country_filter_mode' => 'off',
            'country_filter_list' => [],
            'live_iban_lookup'    => false,
        ];
    }

    /**
     * Returns the general settings schema for the field editor.
     *
     * @return array
     */
    public function getGeneralSchema(): array
    {
        $country_options = $this->ibanCountryOptions();
        return [
            [
                'key'        => 'live_iban_lookup',
                'type'       => 'checkbox',
                'label'      => __('Auto-fill BIC via live IBAN lookup', 'formfabricator'),
                'default'    => false,
                'disclaimer' => __(
                    "When enabled, the visitor's IBAN is sent to the third-party service openiban.com as soon as it's fully typed — before the form is submitted — to look up the matching BIC. Disable this to have visitors enter the BIC manually instead, keeping IBAN data on your own site until submission.", // phpcs:ignore Generic.Files.LineLength
                    'formfabricator'
                ),
            ],
            [
                'key'   => 'mandate_title',
                'type'  => 'text',
                'label' => __('Mandate title', 'formfabricator'),
            ],
            [
                'key'   => 'mandate_text',
                'type'  => 'textarea',
                'label' => __('Mandate text (HTML allowed)', 'formfabricator'),
            ],
            [
                'key'   => 'mandate_note',
                'type'  => 'textarea',
                'label' => __('Hint text (fine print, HTML allowed)', 'formfabricator'),
            ],
            [
                'key'   => 'iban_label',
                'type'  => 'text',
                'label' => __('IBAN label', 'formfabricator'),
            ],
            [
                'key'     => 'placeholder_country',
                'type'    => 'select',
                'label'   => __('Placeholder country (IBAN format)', 'formfabricator'),
                'default' => 'DE',
                'options' => $country_options,
            ],
            [
                'key'   => 'bic_label',
                'type'  => 'text',
                'label' => __('BIC label', 'formfabricator'),
            ],
            [
                'key'   => 'holder_label',
                'type'  => 'text',
                'label' => __('Account holder label', 'formfabricator'),
            ],
            [
                'key'   => 'creditor_id',
                'type'  => 'text',
                'label' => __('Creditor ID', 'formfabricator'),
            ],
            [
                'key'   => 'mandate_ref',
                'type'  => 'text',
                'label' => __('Mandate reference', 'formfabricator'),
            ],
            [
                'key'   => 'sig_label',
                'type'  => 'text',
                'label' => __('Signature label', 'formfabricator'),
            ],
        ];
    }

    /**
     * Returns the advanced settings schema for the field editor.
     *
     * @return array
     */
    public function getAdvancedSchema(): array
    {
        return [
            [
                'key'     => 'canvas_height',
                'type'    => 'number',
                'label'   => __('Signature height (px)', 'formfabricator'),
                'default' => 200,
            ],
            [
                'key'     => 'stroke_width',
                'type'    => 'number',
                'label'   => __('Stroke width', 'formfabricator'),
                'default' => 2,
            ],
        ];
    }

    /**
     * Returns the list of IBAN country options for the country selector.
     *
     * @return array
     */
    private function ibanCountryOptions(): array
    {
        $countries = [
            'AD' => __('Andorra', 'formfabricator'),               'AE' => __('United Arab Emirates', 'formfabricator'), 'AL' => __('Albania', 'formfabricator'),
            'AT' => __('Austria', 'formfabricator'),                'AZ' => __('Azerbaijan', 'formfabricator'),           'BA' => __('Bosnia and Herzegovina', 'formfabricator'),
            'BE' => __('Belgium', 'formfabricator'),                'BG' => __('Bulgaria', 'formfabricator'),             'BH' => __('Bahrain', 'formfabricator'),
            'BR' => __('Brazil', 'formfabricator'),                 'CH' => __('Switzerland', 'formfabricator'),          'CR' => __('Costa Rica', 'formfabricator'),
            'CY' => __('Cyprus', 'formfabricator'),                 'CZ' => __('Czechia', 'formfabricator'),              'DE' => __('Germany', 'formfabricator'),
            'DJ' => __('Djibouti', 'formfabricator'),               'DK' => __('Denmark', 'formfabricator'),              'DO' => __('Dominican Republic', 'formfabricator'),
            'EE' => __('Estonia', 'formfabricator'),                'EG' => __('Egypt', 'formfabricator'),                'ES' => __('Spain', 'formfabricator'),
            'FI' => __('Finland', 'formfabricator'),                'FR' => __('France', 'formfabricator'),               'GB' => __('United Kingdom', 'formfabricator'),
            'GE' => __('Georgia', 'formfabricator'),                'GI' => __('Gibraltar', 'formfabricator'),            'GL' => __('Greenland', 'formfabricator'),
            'GR' => __('Greece', 'formfabricator'),                 'GT' => __('Guatemala', 'formfabricator'),            'HR' => __('Croatia', 'formfabricator'),
            'HU' => __('Hungary', 'formfabricator'),                'IE' => __('Ireland', 'formfabricator'),              'IL' => __('Israel', 'formfabricator'),
            'IQ' => __('Iraq', 'formfabricator'),                   'IS' => __('Iceland', 'formfabricator'),              'IT' => __('Italy', 'formfabricator'),
            'JO' => __('Jordan', 'formfabricator'),                 'KW' => __('Kuwait', 'formfabricator'),               'KZ' => __('Kazakhstan', 'formfabricator'),
            'LB' => __('Lebanon', 'formfabricator'),                'LC' => __('St. Lucia', 'formfabricator'),            'LI' => __('Liechtenstein', 'formfabricator'),
            'LT' => __('Lithuania', 'formfabricator'),              'LU' => __('Luxembourg', 'formfabricator'),           'LV' => __('Latvia', 'formfabricator'),
            'LY' => __('Libya', 'formfabricator'),                  'MA' => __('Morocco', 'formfabricator'),              'MC' => __('Monaco', 'formfabricator'),
            'MD' => __('Moldova', 'formfabricator'),                'ME' => __('Montenegro', 'formfabricator'),           'MK' => __('North Macedonia', 'formfabricator'),
            'MR' => __('Mauritania', 'formfabricator'),             'MT' => __('Malta', 'formfabricator'),                'MU' => __('Mauritius', 'formfabricator'),
            'NI' => __('Nicaragua', 'formfabricator'),              'NL' => __('Netherlands', 'formfabricator'),          'NO' => __('Norway', 'formfabricator'),
            'PK' => __('Pakistan', 'formfabricator'),               'PL' => __('Poland', 'formfabricator'),               'PT' => __('Portugal', 'formfabricator'),
            'QA' => __('Qatar', 'formfabricator'),                  'RO' => __('Romania', 'formfabricator'),              'RS' => __('Serbia', 'formfabricator'),
            'SA' => __('Saudi Arabia', 'formfabricator'),           'SE' => __('Sweden', 'formfabricator'),               'SI' => __('Slovenia', 'formfabricator'),
            'SK' => __('Slovakia', 'formfabricator'),               'SM' => __('San Marino', 'formfabricator'),           'SV' => __('El Salvador', 'formfabricator'),
            'TN' => __('Tunisia', 'formfabricator'),                'TR' => __('Turkey', 'formfabricator'),               'UA' => __('Ukraine', 'formfabricator'),
            'VA' => __('Vatican City', 'formfabricator'),           'VG' => __('British Virgin Islands', 'formfabricator'), 'XK' => __('Kosovo', 'formfabricator'),
        ];

        $opts = [];
        foreach ($countries as $code => $name) {
            $opts[] = ['value' => $code, 'label' => $code . ' – ' . $name];
        }
        return $opts;
    }

    /**
     * Returns the default SEPA mandate body text.
     *
     * @return string
     */
    private function defaultMandateText(): string
    {
        return '<p>' . __(
            'I hereby authorize the creditor to collect payments from my account by direct debit. At the same time, I instruct my bank to honor the direct debits drawn by the creditor on my account.',
            'formfabricator'
        ) . '</p>'
            . '<p>' . __(
                'If my account does not have sufficient funds, my bank is under no obligation to honor the direct debit. Partial payments will not be made under the direct debit scheme. I bear the costs of the returned direct debit.',
                'formfabricator'
            ) . '</p>';
    }

    /**
     * Returns the default SEPA mandate fine-print note.
     *
     * @return string
     */
    private function defaultMandateNote(): string
    {
        return '<small>' . __(
            'Note: I can request a refund of the debited amount within eight weeks, starting from the debit date. The terms agreed with my bank apply.',
            'formfabricator'
        ) . '</small>';
    }
}
