<?php

/**
 * Direct debit mandate composite field: SEPA, Bacs (UK) or ACH (US) account details, holder and signature.
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
 * Direct debit mandate: the mandate's terms, the account details of the scheme the form collects through, the account
 * holder and a signature canvas.
 *
 * One scheme per field, each with its own account details (checked offline) and its own wording, so switching scheme
 * never carries one scheme's legal text into another's. Only SEPA ships default wording.
 */
class DirectDebitField extends BaseField
{
    /**
     * Per scheme: the account-detail inputs in render order, and the client rules that check them.
     *
     * @var array<string, array{parts: string[], rules: string[]}>
     */
    private const SCHEMES = [
        'sepa' => ['parts' => ['iban', 'bic', 'holder'], 'rules' => ['iban', 'debit-bic', 'debit-required']],
        'bacs' => ['parts' => ['sort_code', 'account', 'holder'], 'rules' => ['debit-sort-code', 'debit-account', 'debit-required']],
        'ach'  => ['parts' => ['routing', 'account', 'account_type', 'holder'], 'rules' => ['debit-routing', 'debit-account', 'debit-required']],
    ];

    /**
     * Every sub-input any scheme posts. extractValue() has no config, so it reads them all; what a scheme does not render
     * is never validated, recorded or read by a rule.
     *
     * @var string[]
     */
    private const ALL_PARTS = [
        'iban', 'bic', 'sort_code', 'routing', 'account', 'account_type', 'holder', 'street', 'postcode', 'city', 'country', 'place',
    ];

    /**
     * Debtor details a form usually collects in its own fields, asked for in the mandate only when switched on.
     * Setting => sub-inputs in render order.
     *
     * @var array<string, string[]>
     */
    private const DEBTOR_EXTRAS = [
        'debtor_address' => ['street', 'postcode', 'city', 'country'],
        'signing_place'  => ['place'],
    ];

    /**
     * Payment types a mandate can state (the SEPA rulebook's "recurrent" or "one-off"); 'none' states none.
     *
     * @var string[]
     */
    private const PAYMENT_TYPES = ['none', 'recurrent', 'one_off'];

    /**
     * ACH account types, as posted by the account-type select.
     *
     * @var string[]
     */
    private const ACCOUNT_TYPES = ['checking', 'savings'];

    /**
     * Adds the label-like keys to the plain-text allowlist; the mandate texts and notes stay excluded since their
     * schema allows HTML (\FabricatorForms\Utils\HtmlSanitizer::sanitize()).
     *
     * @return string[]
     */
    protected function plainTextConfigKeys(): array
    {
        return array_merge(
            parent::plainTextConfigKeys(),
            [
                'scheme', 'mandate_title', 'bacs_title', 'ach_title',
                'iban_label', 'bic_label', 'sort_code_label', 'routing_label', 'account_label', 'account_type_label',
                'holder_label', 'creditor_id', 'bacs_creditor_id', 'ach_creditor_id', 'mandate_ref', 'sig_label',
                'creditor_label', 'bacs_creditor_label', 'ach_creditor_label',
                'reference_label', 'bacs_reference_label', 'ach_reference_label',
                'ref_mode', 'mandate_ref_prefix', 'ref_pending_text',
                'account_type_placeholder', 'checking_label', 'savings_label', 'sig_hint', 'clear_label',
                'type_sig_label', 'draw_sig_label', 'typed_name_label',
                'street_label', 'postcode_label', 'city_label', 'country_label', 'place_label', 'date_label',
                'creditor_name', 'creditor_address', 'creditor_name_label', 'creditor_address_label',
                'payment_type', 'payment_type_label', 'recurrent_label', 'one_off_label',
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
        /* Validators must always run so that each account detail and the signature can show their own error
           messages. The debit-required validator handles all required-field checks. */
        return ['fn' => self::readFieldAsset('assets/js/fields/DirectDebitField.emptycheck.js')];
    }

    /**
     * Returns client-side validation rules: every scheme's, since the rule table is per field type. render() lists only
     * the chosen scheme's in data-validate.
     *
     * @return array
     */
    public function getClientValidation(): array
    {
        $rules = [];
        foreach (['iban', 'debit-bic', 'debit-sort-code', 'debit-routing', 'debit-account', 'debit-required'] as $rule) {
            $rules[] = ['rule' => $rule, 'fn' => self::readFieldAsset('assets/js/fields/DirectDebitField.' . $rule . '.js')];
        }
        return $rules;
    }

    /**
     * Returns field-specific CSS styles.
     *
     * @return string
     */
    public function getStyles(): string
    {
        return self::readFieldAsset('assets/css/fields/DirectDebitField.css');
    }

    /**
     * The inputs this field shows, as typed, in render order (BaseField::joinedSubValues()), as front.js reads the
     * "id[key]" inputs. The signature posts under "id-sig" and is not part of it.
     *
     * @param mixed $raw    What extractValue() returned.
     * @param array $config Field configuration.
     * @return mixed
     */
    public function conditionValue(mixed $raw, array $config): mixed
    {
        return self::joinedSubValues($raw, self::parts($config));
    }

    /**
     * Returns the field type label.
     *
     * @return string
     */
    public function getType(): string
    {
        return 'directdebit';
    }

    public function getLabel(): string
    {
        return __('Direct Debit Mandate', 'formfabricator');
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
        return self::readFieldAsset('assets/js/fields/DirectDebitField.js');
    }

    /**
     * The scheme this field collects through; anything unknown (an import, a crafted save) is SEPA.
     *
     * @param array $config Field configuration.
     * @return string A key of self::SCHEMES.
     */
    private static function scheme(array $config): string
    {
        $scheme = $config['scheme'] ?? 'sepa';
        return is_string($scheme) && isset(self::SCHEMES[$scheme]) ? $scheme : 'sepa';
    }

    /**
     * The sub-inputs this field shows, in render order: the scheme's account details and holder, then the debtor
     * details switched on (DEBTOR_EXTRAS). Rendering, checking, recording and the condition value all follow it.
     *
     * @param array $config Field configuration.
     * @return string[]
     */
    private static function parts(array $config): array
    {
        $parts = self::SCHEMES[self::scheme($config)]['parts'];
        foreach (self::DEBTOR_EXTRAS as $setting => $extra) {
            if (!empty($config[$setting])) {
                $parts = array_merge($parts, $extra);
            }
        }
        return $parts;
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
        $val      = is_array($value) ? $value : [];
        $scheme   = self::scheme($config);
        $required = !empty($config['required']);

        // The same terms mapNormalized() records, so the mail, the PDF and the seal hold what was shown here.
        $terms     = $this->shownTerms($config, $scheme);
        $sig_label = esc_html(self::configText($config, 'sig_label', __('Signature', 'formfabricator')));
        $req_attr  = $required ? ' data-required="true"' : '';
        $req_mark  = $required ? ' <span class="fabricator-required" aria-hidden="true">*</span>' : '';

        // A mandate without wording records a signature under empty terms, which is no valid mandate; until the text is
        // entered the field takes no details. The hint is for whoever can fix it, as in CaptchaField::render().
        if (!self::hasWording($terms)) {
            $message = \FabricatorForms\Plugin::userCan('edit_forms')
                ? __("Direct Debit Mandate: enter the mandate text in this field's settings. Until then, visitors cannot give a mandate here.", 'formfabricator')
                : __('Direct debits cannot be set up with this form at the moment.', 'formfabricator');
            return '<div class="fabricator-field fabricator-field--directdebit" data-field-id="' . esc_attr($field_id) . '">'
                . '<p class="fabricator-notice">' . esc_html($message) . '</p>'
                . '</div>';
        }

        $rules = self::SCHEMES[$scheme]['rules'];
        $html  = '<div class="fabricator-field fabricator-field--directdebit" data-field-id="' . esc_attr($field_id) . '"'
            . ' data-scheme="' . esc_attr($scheme) . '"'
            . $req_attr . ' data-validate="' . esc_attr(\FabricatorForms\Utils\Cast::jsonForAttribute($rules)) . '">';
        $html .= '<div class="fabricator-debit-mandate">';
        $html .= '<h3 class="fabricator-debit-title">' . esc_html($terms['title']) . '</h3>';
        if ($terms['text'] !== '') {
            $html .= '<div class="fabricator-debit-text">' . $terms['text'] . '</div>';
        }
        if ($terms['note'] !== '') {
            $html .= '<p class="fabricator-debit-note">' . $terms['note'] . '</p>';
        }

        foreach (self::parts($config) as $part) {
            $html .= $this->partInput($scheme, $part, $config, $field_id, $val, $req_mark);
        }

        /* ---- Creditor info: the creditor's lines as set, and the reference as written, or a note when it is generated ---- */
        $reference_shown = self::refMode($config) === 'generated'
            ? self::configText($config, 'ref_pending_text', __('assigned when the form is sent', 'formfabricator'))
            : self::configText($config, 'mandate_ref', '');
        $lines = self::creditorLines($config, $scheme, $terms);
        if ($reference_shown !== '') {
            $lines['ref'] = [self::referenceLabel($config, $scheme), $reference_shown];
        }
        if ($lines !== []) {
            $html .= '<div class="fabricator-debit-creditor">';
            foreach ($lines as [$line_label, $line_value]) {
                $html .= '<p>' . esc_html($line_label) . ': ' . esc_html($line_value) . '</p>';
            }
            $html .= '</div>';
        }

        /* ---- Signature ---- */
        $canvas_id     = esc_attr($field_id) . '-sig-canvas';
        $clear_label   = self::configText($config, 'clear_label', __('Clear signature', 'formfabricator'));
        // Clamped: an unbounded height produced a huge canvas, and a signature data URI to match.
        $canvas_height = min(600, max(80, (int)($config['canvas_height'] ?? 200)));
        $stroke_width  = (float)($config['stroke_width'] ?? 2);
        $html .= '<div class="fabricator-debit-signatures">';
        $html .= '<div class="fabricator-debit-sig-block">';
        $html .= '<div class="fabricator-debit-sig-label">' . $sig_label . $req_mark . '</div>';
        $html .= '<div class="fabricator-signature-wrap"'
            . ' data-field-id="' . esc_attr($field_id) . '-sig"'
            . $req_attr
            . ' data-stroke="' . esc_attr((string)$stroke_width) . '">';
        $html .= '<canvas id="' . $canvas_id . '" class="fabricator-signature-canvas"'
            . ' width="400" height="' . $canvas_height . '"'
            . ' style="height:' . $canvas_height . 'px"'
            . ' tabindex="0" aria-label="' . $sig_label . '"></canvas>';
        $html .= self::typedSignatureInput(
            $field_id . '-sig-typed',
            self::configText($config, 'typed_name_label', __('Your full name', 'formfabricator'))
        );
        $html .= '<input type="hidden"'
            . ' name="' . esc_attr($field_id) . '-sig"'
            . ' id="' . esc_attr($field_id) . '-sig-data"'
            . ' class="fabricator-debit-sig-data">';
        $html .= '<div class="fabricator-signature-toolbar">';
        $reset_icon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"'
            . ' aria-hidden="true" focusable="false">'
            . '<path d="M125.7 160H176a16 16 0 0 1 0 32H48a16 16 0 0 1-16-16V48a16 16 0 0 1 32 0v68.7'
            . 'C115.3 45.1 191.6 0 278 0c141.4 0 256 114.6 256 256S419.4 512 278 512'
            . 'C167.7 512 74.4 443.5 38 346a16 16 0 1 1 30-11c31.4 83.7 111.5 141 210 141'
            . ' 123.7 0 224-100.3 224-224S401.7 32 278 32c-78.1 0-145.8 39.4-185.3 99.3z"/>'
            . '</svg>';
        $html .= '<button type="button" class="fabricator-signature-clear"'
            . ' data-canvas="' . $canvas_id . '" title="' . esc_attr($clear_label) . '" aria-label="' . esc_attr($clear_label) . '">'
            . $reset_icon . '</button>';
        $html .= '<span class="fabricator-signature-hint">' . esc_html(self::configText($config, 'sig_hint', __('Sign here', 'formfabricator'))) . '</span>';
        $html .= self::signatureModeButton(
            self::configText($config, 'type_sig_label', __('Type your name instead', 'formfabricator')),
            self::configText($config, 'draw_sig_label', __('Draw instead', 'formfabricator'))
        );
        $html .= '</div>';
        $html .= '</div>';
        $html .= '<div class="fabricator-field-error fabricator-debit-sig-error"'
            . ' id="' . esc_attr($field_id) . '-sig-error" role="alert"></div>';
        $html .= '</div>'; /* sig-block */
        $html .= '</div>'; /* signatures */

        $html .= '<div class="fabricator-field-error" id="'
            . esc_attr($field_id) . '-error" role="alert" aria-live="polite"></div>';
        $html .= '</div>'; /* fabricator-debit-mandate */
        $html .= '</div>'; /* fabricator-field */

        return $html;
    }

    /**
     * One account-detail input with its label and error slot, posted as "id[part]".
     *
     * @param string $scheme   Key of self::SCHEMES.
     * @param string $part     Sub-input key.
     * @param array  $config   Field configuration.
     * @param string $field_id Field identifier.
     * @param array  $val      Current sub-values.
     * @param string $req_mark Required marker markup, or ''.
     * @return string
     */
    private function partInput(string $scheme, string $part, array $config, string $field_id, array $val, string $req_mark): string
    {
        $slug  = str_replace('_', '-', $part);
        $id    = $field_id . '-' . $slug;
        $value = is_scalar($val[$part] ?? null) ? (string) $val[$part] : '';
        $label = self::configText($config, $part . '_label', self::defaultPartLabel($part));

        // The BIC is needed only for an IBAN from outside the EEA (validate()): its required mark starts hidden, and
        // DirectDebitField.js shows it once the IBAN typed is from one of those countries.
        if ($part === 'bic' && $req_mark !== '') {
            $req_mark = ' <span class="fabricator-required fabricator-debit-bic-mark" aria-hidden="true" style="display:none">*</span>';
        }
        $html   = '<div class="fabricator-field-inner">';
        $html  .= '<label class="fabricator-label" for="' . esc_attr($id) . '">' . esc_html($label) . $req_mark . '</label>';
        $common = ' id="' . esc_attr($id) . '" name="' . esc_attr($field_id . '[' . $part . ']') . '"'
            . ' class="fabricator-input fabricator-debit-' . esc_attr($slug) . '" data-debit-part="' . esc_attr($part) . '"';
        if (self::isDebtorExtra($part)) {
            // Named by its label, which the admin sets: the browser shows the message the server would give.
            $common .= ' data-required-message="' . esc_attr(self::requiredMessage($part, $config)) . '"';
        }
        if ($part === 'account_type') {
            $choices = ['' => self::configText($config, 'account_type_placeholder', __('Please choose', 'formfabricator'))] + self::accountTypeLabels($config);
            $html .= '<select' . $common . '>';
            foreach ($choices as $choice => $choice_label) {
                $html .= '<option value="' . esc_attr((string) $choice) . '"' . selected($value, (string) $choice, false) . '>'
                    . esc_html($choice_label) . '</option>';
            }
            $html .= '</select>';
        } else {
            $html .= '<input type="text"' . $common . $this->partAttributes($scheme, $part, $config)
                . ' value="' . esc_attr($value) . '">';
        }
        if ($part === 'iban') {
            $html .= '<div class="fabricator-field-hint"></div>';
        }
        $html .= '<div class="fabricator-field-error" id="' . esc_attr($id) . '-error" role="alert"></div>';
        $html .= '</div>';
        return $html;
    }

    /**
     * The input attributes one account detail needs: length, keyboard, and for the IBAN its country settings.
     *
     * @param string $scheme Key of self::SCHEMES.
     * @param string $part   Sub-input key.
     * @param array  $config Field configuration.
     * @return string Escaped attribute markup, starting with a space.
     */
    private function partAttributes(string $scheme, string $part, array $config): string
    {
        $numeric = ' inputmode="numeric" autocomplete="off" spellcheck="false"';
        switch ($part) {
            case 'iban':
                $country_filter = $config['country_filter_mode'] ?? 'off';
                $country_list   = is_array($config['country_filter_list'] ?? null) ? $config['country_filter_list'] : [];
                $filter_attr    = $country_filter !== 'off' && !empty($country_list)
                    ? ' data-country-filter="' . esc_attr((string) $country_filter)
                        . '" data-country-list="' . esc_attr(implode(',', $country_list)) . '"'
                    : '';
                return ' maxlength="42" autocomplete="off" inputmode="text" spellcheck="false"'
                    . ' data-placeholder-country="' . esc_attr(self::configText($config, 'placeholder_country', 'DE')) . '"'
                    . $filter_attr;
            case 'bic':
                return ' placeholder="XXXXXXXXXXX" maxlength="11" autocomplete="off"';
            case 'sort_code':
                return ' placeholder="00-00-00" maxlength="8"' . $numeric;
            case 'routing':
                return ' maxlength="9"' . $numeric;
            case 'account':
                return ' maxlength="' . ($scheme === 'bacs' ? '8' : '17') . '"' . $numeric;
            case 'street':
                return ' autocomplete="street-address"';
            case 'postcode':
                return ' autocomplete="postal-code"';
            case 'city':
                return ' autocomplete="address-level2"';
            case 'country':
                return ' autocomplete="country-name"';
            default:
                return ' autocomplete="off"';
        }
    }

    /**
     * Returns the composite array (every scheme's account details, holder, signature); the signature posts under a
     * separate '-sig' key.
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
        $out = [];
        foreach (self::ALL_PARTS as $part) {
            $out[$part] = is_scalar($raw[$part] ?? null) ? sanitize_text_field((string) $raw[$part]) : '';
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce is verified once in FormProcessor::handle() before field extraction runs.
        $out['sig'] = sanitize_text_field(wp_unslash($_POST[$field_id . '-sig'] ?? ''));
        return $out;
    }

    /**
     * Country code => canonical IBAN length. Public so DirectDebitField.js gets it localized.
     *
     * @var array<string, int>
     */
    public const IBAN_LEN = [
        'AD' => 24, 'AE' => 23, 'AL' => 28, 'AT' => 20, 'AZ' => 28, 'BA' => 20, 'BE' => 16,
        'BG' => 22, 'BH' => 22, 'BI' => 27, 'BR' => 29, 'BY' => 28, 'CH' => 21, 'CR' => 22,
        'CY' => 28, 'CZ' => 24, 'DE' => 22, 'DJ' => 27, 'DK' => 18, 'DO' => 28, 'EE' => 20,
        'EG' => 29, 'ES' => 24, 'FI' => 18, 'FK' => 18, 'FO' => 18, 'FR' => 27, 'GB' => 22,
        'GE' => 22, 'GI' => 23, 'GL' => 18, 'GR' => 27, 'GT' => 28, 'HR' => 21, 'HU' => 28,
        'IE' => 22, 'IL' => 23, 'IQ' => 23, 'IS' => 26, 'IT' => 27, 'JO' => 30, 'KW' => 30,
        'KZ' => 20, 'LB' => 28, 'LC' => 32, 'LI' => 21, 'LT' => 20, 'LU' => 20, 'LV' => 21,
        'LY' => 25, 'MA' => 28, 'MC' => 27, 'MD' => 24, 'ME' => 22, 'MK' => 19, 'MN' => 20,
        'MR' => 27, 'MT' => 31, 'MU' => 30, 'NI' => 28, 'NL' => 18, 'NO' => 15, 'OM' => 23,
        'PK' => 24, 'PL' => 28, 'PS' => 29, 'PT' => 25, 'QA' => 29, 'RO' => 24, 'RS' => 22,
        'RU' => 33, 'SA' => 24, 'SC' => 31, 'SD' => 18, 'SE' => 24, 'SI' => 19, 'SK' => 24,
        'SM' => 27, 'SO' => 23, 'ST' => 25, 'SV' => 28, 'TL' => 23, 'TN' => 24, 'TR' => 26,
        'UA' => 29, 'VA' => 22, 'VG' => 24, 'XK' => 20, 'YE' => 30,
    ];

    /**
     * The IBAN country codes of the SEPA schemes' geographical scope, from EPC409-09 v8.0 (24 December 2025), section 5.
     * Territories bank under their parent's code. The field's country filter can only narrow these. Public so
     * DirectDebitField.js gets it localized.
     *
     * @var string[]
     */
    public const SEPA_COUNTRIES = [
        'AD', 'AL', 'AT', 'BE', 'BG', 'CH', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GB', 'GI', 'GR', 'HR', 'HU',
        'IE', 'IS', 'IT', 'LI', 'LT', 'LU', 'LV', 'MC', 'MD', 'ME', 'MK', 'MT', 'NL', 'NO', 'PL', 'PT', 'RO', 'RS', 'SE',
        'SI', 'SK', 'SM', 'VA',
    ];

    /**
     * The SEPA countries outside the EEA (EPC409-09 v8.0), whose IBANs need the debtor bank's BIC too. Public so
     * front.js gets it localized.
     *
     * @var string[]
     */
    public const SEPA_NON_EEA = ['AD', 'AL', 'CH', 'GB', 'GI', 'MC', 'MD', 'ME', 'MK', 'RS', 'SM', 'VA'];

    /**
     * Verifies the check digits of a cleaned IBAN: within 02-98 (ISO 13616) and the ISO 7064 mod-97 checksum.
     * Mirrored by DirectDebitField.js.
     *
     * @param string $iban Cleaned IBAN string.
     * @return bool True when the check digits are valid.
     */
    private static function ibanChecksumValid(string $iban): bool
    {
        $check_digits = (int) substr($iban, 2, 2);
        if ($check_digits < 2 || $check_digits > 98) {
            return false;
        }
        $rearranged = substr($iban, 4) . substr($iban, 0, 4);
        $numeric = '';
        foreach (str_split($rearranged) as $ch) {
            $numeric .= ctype_alpha($ch) ? (string)(ord($ch) - 55) : $ch;
        }
        // Mod-97 in chunks, without relying on bcmath.
        $remainder = 0;
        foreach (str_split($numeric, 7) as $chunk) {
            $remainder = (int) (($remainder . $chunk) % 97);
        }
        return $remainder === 1;
    }

    /**
     * Whether a 9-digit string is a possible ABA routing number: a Federal Reserve prefix (00-12, 21-32, 61-72, 80)
     * and the 3-7-1 check digit. Mirrored by DirectDebitField.debit-routing.js.
     *
     * @param string $routing Nine digits.
     * @return bool
     */
    private static function routingNumberValid(string $routing): bool
    {
        if (!preg_match('/^\d{9}$/', $routing) || $routing === '000000000') {
            return false;
        }
        $prefix = (int) substr($routing, 0, 2);
        if (!($prefix <= 12 || ($prefix >= 21 && $prefix <= 32) || ($prefix >= 61 && $prefix <= 72) || $prefix === 80)) {
            return false;
        }
        $d = array_map('intval', str_split($routing));
        return (3 * ($d[0] + $d[3] + $d[6]) + 7 * ($d[1] + $d[4] + $d[7]) + $d[2] + $d[5] + $d[8]) % 10 === 0;
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
            return $required ? __('Direct debit details are missing.', 'formfabricator') : true;
        }

        $scheme = self::scheme($config);
        $typed  = [];
        foreach (self::parts($config) as $part) {
            $typed[$part] = is_scalar($value[$part] ?? null) ? trim((string) $value[$part]) : '';
        }
        $sig = (string)($value['sig'] ?? '');

        // An optional mandate left untouched is fine.
        if (!$required && implode('', $typed) === '' && $sig === '') {
            return true;
        }

        // No wording, no mandate: render() showed a notice instead of the inputs, so a required mandate cannot be given
        // until the text is entered, and details posted anyway were not typed into this form.
        if (!self::hasWording($this->shownTerms($config, $scheme))) {
            return __('Direct debits cannot be set up with this form at the moment.', 'formfabricator');
        }

        // All or nothing, so a signature is never recorded over missing details. The BIC is needed only for a non-EEA
        // IBAN (the IBAN, checked first, is valid by then).
        foreach ($typed as $part => $typed_value) {
            if ($part === 'bic' && $typed_value === '') {
                $iban_cc = strtoupper(substr((string) preg_replace('/\s/', '', $typed['iban'] ?? ''), 0, 2));
                if (!in_array($iban_cc, self::SEPA_NON_EEA, true)) {
                    continue;
                }
                // translators: %s: two-letter IBAN country code, e.g. "CH".
                return sprintf(__('The BIC is needed for IBANs from %s.', 'formfabricator'), $iban_cc);
            }
            $result = $typed_value === ''
                ? self::requiredMessage($part, $config)
                : $this->checkPart($part, $typed_value, $scheme, $config);
            if ($result !== true) {
                return $result;
            }
        }

        if ($sig === '') {
            return __('Signature is a required field.', 'formfabricator');
        }
        if (self::isTypedSignature($sig)) {
            return self::validateTextHardCap($sig);
        }
        if (!self::isValidSignatureImage($sig)) {
            return __('The signature could not be read. Please sign again.', 'formfabricator');
        }

        return true;
    }

    /**
     * Checks one non-empty account detail.
     *
     * @param string $part   Sub-input key.
     * @param string $value  Trimmed value.
     * @param string $scheme Key of self::SCHEMES.
     * @param array  $config Field configuration.
     * @return bool|string True, or the message for the visitor.
     */
    private function checkPart(string $part, string $value, string $scheme, array $config): bool|string
    {
        $digits = (string) preg_replace('/[\s-]/', '', $value);
        switch ($part) {
            case 'iban':
                return $this->checkIban($value, $config);
            case 'bic':
                return preg_match('/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/i', $value)
                    ? true : __('Please enter a valid BIC.', 'formfabricator');
            case 'sort_code':
                return preg_match('/^\d{6}$/', $digits)
                    ? true : __('Please enter a valid sort code (6 digits).', 'formfabricator');
            case 'routing':
                return self::routingNumberValid($digits)
                    ? true : __('Please enter a valid routing number.', 'formfabricator');
            case 'account':
                if ($scheme === 'bacs') {
                    return preg_match('/^\d{8}$/', $digits)
                        ? true : __('Please enter a valid account number (8 digits).', 'formfabricator');
                }
                return preg_match('/^\d{4,17}$/', $digits)
                    ? true : __('Please enter a valid account number (4 to 17 digits).', 'formfabricator');
            case 'account_type':
                return in_array($value, self::ACCOUNT_TYPES, true)
                    ? true : __('Please choose the account type.', 'formfabricator');
            default:
                // The holder and the debtor's address: the same hard cap as every other text field.
                return self::validateTextHardCap($value);
        }
    }

    /**
     * Checks a non-empty IBAN: format, registry length, checksum and the field's country filter.
     *
     * @param string $iban   As typed.
     * @param array  $config Field configuration.
     * @return bool|string
     */
    private function checkIban(string $iban, array $config): bool|string
    {
        $iban_clean = strtoupper((string) preg_replace('/\s/', '', $iban));
        if (!preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/', $iban_clean)) {
            return __('Please enter a valid IBAN.', 'formfabricator');
        }
        // IBAN_LEN lists every SWIFT IBAN Registry participant; a country code absent
        // from it is not a valid IBAN country at all, not merely one we skip checking.
        $iban_cc = substr($iban_clean, 0, 2);
        if (!isset(self::IBAN_LEN[$iban_cc]) || strlen($iban_clean) !== self::IBAN_LEN[$iban_cc]) {
            return __('Please enter a valid IBAN.', 'formfabricator');
        }
        if (!self::ibanChecksumValid($iban_clean)) {
            return __('Please enter a valid IBAN.', 'formfabricator');
        }

        // SEPA countries only, then the field's own filter; front.js checks both too, but only for feedback.
        $filter_mode = $config['country_filter_mode'] ?? 'off';
        $filter_list = is_array($config['country_filter_list'] ?? null)
            ? array_map('strtoupper', $config['country_filter_list'])
            : [];
        $listed   = in_array($iban_cc, $filter_list, true);
        $filtered = $filter_list !== [] && (($filter_mode === 'allow' && !$listed) || ($filter_mode === 'disallow' && $listed));
        if ($filtered || !in_array($iban_cc, self::SEPA_COUNTRIES, true)) {
            // translators: %s: two-letter IBAN country code.
            return sprintf(__('IBANs from country "%s" are not allowed.', 'formfabricator'), esc_html($iban_cc));
        }
        return true;
    }

    /**
     * The message for a required account detail left empty; a debtor detail is named by its label.
     *
     * @param string $part   Sub-input key.
     * @param array  $config Field configuration.
     * @return string
     */
    private static function requiredMessage(string $part, array $config): string
    {
        if (self::isDebtorExtra($part)) {
            // translators: %s: field label.
            return sprintf(__('%s is a required field.', 'formfabricator'), rtrim(self::partLabel($part, $config), ': '));
        }
        return match ($part) {
            'iban'         => __('IBAN is a required field.', 'formfabricator'),
            'sort_code'    => __('Sort code is a required field.', 'formfabricator'),
            'routing'      => __('Routing number is a required field.', 'formfabricator'),
            'account'      => __('Account number is a required field.', 'formfabricator'),
            'account_type' => __('Please choose the account type.', 'formfabricator'),
            default        => __('Account holder is a required field.', 'formfabricator'),
        };
    }

    /**
     * One account detail as the mail, the PDF and the seal record it.
     *
     * @param string $part   Sub-input key.
     * @param mixed  $value  As submitted.
     * @param array  $config Field configuration (the account types' wording).
     * @return string '' when empty.
     */
    private static function formatPart(string $part, mixed $value, array $config = []): string
    {
        $value  = is_scalar($value) ? trim((string) $value) : '';
        $digits = (string) preg_replace('/[\s-]/', '', $value);
        switch ($part) {
            case 'iban':
                return rtrim(chunk_split(strtoupper((string) preg_replace('/\s/', '', $value)), 4, ' '));
            case 'bic':
                return strtoupper($value);
            case 'sort_code':
                return preg_match('/^\d{6}$/', $digits) ? implode('-', str_split($digits, 2)) : $value;
            case 'routing':
            case 'account':
                return preg_match('/^\d+$/', $digits) ? $digits : $value;
            case 'account_type':
                return self::accountTypeLabels($config)[$value] ?? $value;
            default:
                return $value;
        }
    }

    /**
     * Returns the normalized entries of one signed mandate, framed by a start and an end entry.
     *
     * The terms the debtor saw are recorded with the account details and signature, so the record proves what was
     * agreed even after the form's wording changes.
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
        $scheme = self::scheme($config);
        $shown  = [];
        foreach (self::parts($config) as $part) {
            $shown[$part] = self::formatPart($part, is_array($value) ? ($value[$part] ?? '') : '', $config);
        }
        $sig_val = is_array($value) && is_string($value['sig'] ?? null) ? $value['sig'] : '';

        // An optional mandate left untouched was not agreed to: record that, not the mandate's terms around empty account
        // data, which read like a mandate granted without details.
        if (implode('', $shown) === '' && $sig_val === '') {
            return [$field_id => [
                'label'              => $label,
                'type'               => 'directdebit',
                'value'              => __('[No entry]', 'formfabricator'),
                'materialized_files' => [],
            ]];
        }

        $terms = $this->shownTerms($config, $scheme);
        $body  = self::plainText($terms['text']);
        $note  = self::plainText($terms['note']);

        $entries = [
            $field_id . '_begin' => [
                'label'        => $terms['title'],
                'type'         => 'directdebit',
                'value'        => $note !== '' ? trim($body . "\n\n" . $note) : $body,
                'mandate_part' => 'begin',
            ],
        ];
        // The creditor's name, address and identifier and the payment type, as render() showed them.
        foreach (self::creditorLines($config, $scheme, $terms) as $line_key => [$line_label, $line_value]) {
            $entries[$field_id . '_' . $line_key] = [
                'label' => $line_label,
                'type'  => 'directdebit',
                'value' => $line_value,
            ];
        }
        // As the admin wrote it ("Your membership number"), or made for this mandate; none when neither is set.
        $reference = self::refMode($config) === 'generated' ? self::mandateReference($config) : self::configText($config, 'mandate_ref', '');
        if ($reference !== '') {
            $entries[$field_id . '_ref'] = [
                'label' => self::referenceLabel($config, $scheme),
                'type'  => 'directdebit',
                'value' => $reference,
            ];
        }
        foreach ($shown as $part => $part_value) {
            $entries[$field_id . '_' . $part] = [
                'label' => self::partLabel($part, $config),
                'type'  => 'directdebit',
                'value' => $part_value !== '' ? $part_value : __('[No entry]', 'formfabricator'),
            ];
        }
        // The day the mandate was signed, which every mandate names, as the site writes dates: the day it is sent, since
        // it is signed on the page.
        $entries[$field_id . '_date'] = [
            'label' => self::configText($config, 'date_label', __('Date of signing', 'formfabricator')),
            'type'  => 'directdebit',
            'value' => (string) wp_date((string) get_option('date_format', 'Y-m-d')),
        ];

        $sig_label = self::configText($config, 'sig_label', __('Signature', 'formfabricator'));
        if (self::isTypedSignature($sig_val)) {
            // A typed name is recorded, shown and sealed as text, like the account details.
            $entries[$field_id . '_sig'] = [
                'label' => $sig_label,
                'type'  => 'directdebit',
                'value' => self::typedSignatureRecord($sig_val),
            ];
        } else {
            $materialized = $sig_val !== '' ? self::materializeSignature($sig_val, 'mandate-signature.png') : [];
            // The mail reads the text, as for a Signature field; the PDF draws only the image, and the seal records the
            // image by hash, not this text.
            $entries[$field_id . '_sig'] = [
                'label'              => $sig_label,
                'type'               => 'signature',
                'value'              => $materialized ? __('[Signature present]', 'formfabricator') : __('[No entry]', 'formfabricator'),
                'materialized_files' => $materialized,
            ];
        }
        // Labelled, since the mail summary leaves unlabelled entries out; the label is the marker, there is no value.
        $entries[$field_id . '_end'] = [
            // translators: %s: the mandate's title, e.g. "SEPA Direct Debit Mandate".
            'label'        => sprintf(__('End of %s', 'formfabricator'), $terms['title']),
            'type'         => 'directdebit',
            'value'        => '',
            'mandate_part' => 'end',
        ];

        return $entries;
    }

    /**
     * Puts the mandate in a titled box: the start entry opens it (title as heading, text as cell), the end entry closes
     * it. Each cell holds exactly its sealed value, so the title lives in the box and the end cell stays empty.
     *
     * @param array $field Normalized entry from mapNormalized().
     * @return array
     */
    public function pdfData(array $field): array
    {
        $part  = $field['mandate_part'] ?? '';
        $label = is_string($field['label'] ?? null) ? $field['label'] : '';
        $value = is_string($field['value'] ?? null) ? $field['value'] : '';
        if ($part === 'begin') {
            return $this->pdf($field)->text(nl2br(esc_html($value)))->unlabeled()->opensFrame($label)->build();
        }
        if ($part === 'end') {
            return $this->pdf($field)->text('')->unlabeled()->closesFrame()->build();
        }
        return parent::pdfData($field);
    }

    /**
     * The mandate terms of the chosen scheme exactly as render() shows them: the title, creditor identifier and
     * reference as plain text, the mandate text and note as sanitized HTML. SEPA's keys carry no scheme prefix; only
     * SEPA falls back to default wording.
     *
     * @param array  $config Field configuration.
     * @param string $scheme Key of self::SCHEMES.
     * @return array{title: string, text: string, note: string, creditor_id: string}
     */
    private function shownTerms(array $config, string $scheme): array
    {
        $sepa   = $scheme === 'sepa';
        $prefix = $sepa ? 'mandate_' : $scheme . '_';
        return [
            'title'       => self::configText($config, $prefix . 'title', self::defaultTitle($scheme)),
            'text'        => \FabricatorForms\Utils\HtmlSanitizer::sanitize(
                self::configText($config, $prefix . 'text', $sepa ? $this->defaultMandateText() : '')
            ),
            'note'        => \FabricatorForms\Utils\HtmlSanitizer::sanitize(
                self::configText($config, $prefix . 'note', $sepa ? $this->defaultMandateNote() : '')
            ),
            'creditor_id' => self::configText($config, $sepa ? 'creditor_id' : $scheme . '_creditor_id', ''),
        ];
    }

    /**
     * A new mandate reference, made once per submission: the field's prefix, the date and 40 random bits, e.g.
     * "ACME-20260930-4F9C1A2B3D".
     *
     * The EPC rulebook wants a reference unique per creditor, at most 35 characters from the SEPA set, so the prefix
     * keeps 15 such characters. Bacs and ACH references are made the same way.
     *
     * @param array $config Field configuration.
     * @return string
     */
    private static function mandateReference(array $config): string
    {
        // Accented letters as their base letters, as WordPress writes them for the site's language (Ä as "Ae" in German),
        // so a name keeps its letters; whatever else lies outside the SEPA set is left out.
        $prefix = remove_accents(self::configText($config, 'mandate_ref_prefix', ''));
        $prefix = (string) preg_replace("#[^A-Za-z0-9/?:().,'+ -]#", '', $prefix);
        $prefix = rtrim(substr(trim($prefix), 0, 15), ' -');
        // The site's date, as the date of signing is written: both name the same day in the record.
        $unique = wp_date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(5)));
        return $prefix !== '' ? $prefix . '-' . $unique : $unique;
    }

    /**
     * The creditor's lines of the mandate (name, address, identifier, payment type), as label and value, each only
     * when set.
     *
     * @param array  $config Field configuration.
     * @param string $scheme Key of self::SCHEMES.
     * @param array  $terms  shownTerms().
     * @return array<string, array{0: string, 1: string}>
     */
    private static function creditorLines(array $config, string $scheme, array $terms): array
    {
        $lines = [];
        $name  = trim(self::configText($config, 'creditor_name', ''));
        if ($name !== '') {
            $lines['creditor_name'] = [self::configText($config, 'creditor_name_label', __('Creditor', 'formfabricator')), $name];
        }
        $address = trim(self::configText($config, 'creditor_address', ''));
        if ($address !== '') {
            $lines['creditor_address'] = [self::configText($config, 'creditor_address_label', __('Creditor address', 'formfabricator')), $address];
        }
        if ($terms['creditor_id'] !== '') {
            $lines['creditor'] = [self::creditorLabel($config, $scheme), $terms['creditor_id']];
        }
        $payment = self::paymentTypeText($config);
        if ($payment !== '') {
            $lines['payment_type'] = [self::configText($config, 'payment_type_label', __('Type of payment', 'formfabricator')), $payment];
        }
        return $lines;
    }

    /**
     * The payment type as the mandate states it, or '' when it states none.
     *
     * @param array $config Field configuration.
     * @return string
     */
    private static function paymentTypeText(array $config): string
    {
        $type = $config['payment_type'] ?? 'none';
        return match (in_array($type, self::PAYMENT_TYPES, true) ? $type : 'none') {
            'recurrent' => self::configText($config, 'recurrent_label', __('Recurrent payment', 'formfabricator')),
            'one_off'   => self::configText($config, 'one_off_label', __('One-off payment', 'formfabricator')),
            default     => '',
        };
    }

    /**
     * Whether a sub-input is one of the debtor details behind a setting (DEBTOR_EXTRAS).
     *
     * @param string $part Sub-input key.
     * @return bool
     */
    private static function isDebtorExtra(string $part): bool
    {
        return in_array($part, array_merge(...array_values(self::DEBTOR_EXTRAS)), true);
    }

    /**
     * The label of a sub-input, as set on the field.
     *
     * @param string $part   Sub-input key.
     * @param array  $config Field configuration.
     * @return string
     */
    private static function partLabel(string $part, array $config): string
    {
        return self::configText($config, $part . '_label', self::defaultPartLabel($part));
    }

    /**
     * Whether the mandate says anything the debtor agrees to: its text or its note has words in it.
     *
     * @param array{text: string, note: string} $terms From shownTerms().
     * @return bool
     */
    private static function hasWording(array $terms): bool
    {
        return self::plainText($terms['text']) !== '' || self::plainText($terms['note']) !== '';
    }

    /**
     * What a bank needs that a mandate with wording still leaves out (creditor name, identifier, the scheme's required
     * reference), for the builder's save warning. Each stays optional, since the mandate text may name them.
     *
     * @param array $config Field configuration.
     * @return string[] The names of what is missing, as the builder shows them; empty when nothing is.
     */
    public static function missingMandateElements(array $config): array
    {
        $scheme  = self::scheme($config);
        $missing = [];
        if (trim(self::configText($config, 'creditor_name', '')) === '') {
            $missing[] = __('Creditor name', 'formfabricator');
        }
        if (trim(self::configText($config, $scheme === 'sepa' ? 'creditor_id' : $scheme . '_creditor_id', '')) === '') {
            $missing[] = self::defaultCreditorLabel($scheme);
        }
        if ($scheme !== 'ach' && self::refMode($config) === 'text' && trim(self::configText($config, 'mandate_ref', '')) === '') {
            $missing[] = self::defaultReferenceLabel($scheme);
        }
        return $missing;
    }

    /**
     * Whether a saved field would show its "not set up" notice instead of the mandate, for the builder's save warning.
     *
     * @param array $config Field configuration.
     * @return bool
     */
    public static function lacksWording(array $config): bool
    {
        return !self::hasWording((new self())->shownTerms($config, self::scheme($config)));
    }

    /**
     * A string setting, or $default when it is missing or not a string.
     *
     * @param array  $config  Field configuration.
     * @param string $key     Setting.
     * @param string $default Fallback.
     * @return string
     */
    private static function configText(array $config, string $key, string $default): string
    {
        return is_string($config[$key] ?? null) ? $config[$key] : $default;
    }

    /**
     * The mandate's default title per scheme: a name, not wording the debtor agrees to.
     *
     * @param string $scheme Key of self::SCHEMES.
     * @return string
     */
    private static function defaultTitle(string $scheme): string
    {
        return match ($scheme) {
            'bacs'  => __('Direct Debit Instruction', 'formfabricator'),
            'ach'   => __('ACH Debit Authorization', 'formfabricator'),
            default => __('SEPA Direct Debit Mandate', 'formfabricator'),
        };
    }

    /**
     * How the reference is made: 'text', the admin's own wording, shown and recorded as typed (e.g. "Your membership
     * number"), or 'generated', one per mandate (mandateReference()).
     *
     * @param array $config Field configuration.
     * @return string
     */
    private static function refMode(array $config): string
    {
        return ($config['ref_mode'] ?? '') === 'generated' ? 'generated' : 'text';
    }

    /**
     * The ACH account types as the page shows and the record names them.
     *
     * @param array $config Field configuration.
     * @return array<string, string>
     */
    private static function accountTypeLabels(array $config): array
    {
        return [
            'checking' => self::configText($config, 'checking_label', __('Checking', 'formfabricator')),
            'savings'  => self::configText($config, 'savings_label', __('Savings', 'formfabricator')),
        ];
    }

    /**
     * The label of the creditor line, per scheme, as set on the field; the scheme's own term by default.
     *
     * @param array  $config Field configuration.
     * @param string $scheme Key of self::SCHEMES.
     * @return string
     */
    private static function creditorLabel(array $config, string $scheme): string
    {
        return self::configText($config, ($scheme === 'sepa' ? '' : $scheme . '_') . 'creditor_label', self::defaultCreditorLabel($scheme));
    }

    /**
     * The label of the reference line, per scheme, as set on the field; the scheme's own term by default.
     *
     * @param array  $config Field configuration.
     * @param string $scheme Key of self::SCHEMES.
     * @return string
     */
    private static function referenceLabel(array $config, string $scheme): string
    {
        return self::configText($config, ($scheme === 'sepa' ? '' : $scheme . '_') . 'reference_label', self::defaultReferenceLabel($scheme));
    }

    /**
     * What the creditor's identifier is called in the scheme.
     *
     * @param string $scheme Key of self::SCHEMES.
     * @return string
     */
    private static function defaultCreditorLabel(string $scheme): string
    {
        return match ($scheme) {
            'bacs'  => __('Service user number', 'formfabricator'),
            'ach'   => __('Company ID', 'formfabricator'),
            default => __('Creditor identification number', 'formfabricator'),
        };
    }

    /**
     * What the mandate's reference is called in the scheme.
     *
     * @param string $scheme Key of self::SCHEMES.
     * @return string
     */
    private static function defaultReferenceLabel(string $scheme): string
    {
        return match ($scheme) {
            'bacs'  => __('Reference', 'formfabricator'),
            'ach'   => __('Authorization reference', 'formfabricator'),
            default => __('Mandate reference', 'formfabricator'),
        };
    }

    /**
     * The default label of an account-detail input.
     *
     * @param string $part Sub-input key.
     * @return string
     */
    private static function defaultPartLabel(string $part): string
    {
        return match ($part) {
            'iban'         => __('IBAN:', 'formfabricator'),
            'bic'          => __('BIC:', 'formfabricator'),
            'sort_code'    => __('Sort code:', 'formfabricator'),
            'routing'      => __('Routing number:', 'formfabricator'),
            'account'      => __('Account number:', 'formfabricator'),
            'account_type' => __('Account type:', 'formfabricator'),
            'street'       => __('Street and number:', 'formfabricator'),
            'postcode'     => __('Postal code:', 'formfabricator'),
            'city'         => __('City:', 'formfabricator'),
            'country'      => __('Country:', 'formfabricator'),
            'place'        => __('Place of signing:', 'formfabricator'),
            default        => __('Account holder:', 'formfabricator'),
        };
    }

    /**
     * Sanitized HTML as plain text, keeping its paragraphs and line breaks as line breaks.
     *
     * @param string $html Sanitized HTML.
     * @return string
     */
    private static function plainText(string $html): string
    {
        $text = (string) preg_replace('#<br\s*/?>|</(?:p|div|li|h[1-6])>#i', "\n", $html);
        $text = html_entity_decode(wp_strip_all_tags($text, false), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim((string) preg_replace("/\n{3,}/", "\n\n", preg_replace("/[ \t]*\n[ \t]*/", "\n", $text)));
    }

    /**
     * Returns the default field configuration.
     *
     * @return array
     */
    public function getDefaultConfig(): array
    {
        return [
            'label'               => __('Direct Debit Mandate', 'formfabricator'),
            'required'            => true,
            'description'         => '',
            'scheme'              => 'sepa',
            'mandate_title'       => self::defaultTitle('sepa'),
            'mandate_text'        => $this->defaultMandateText(),
            'mandate_note'        => $this->defaultMandateNote(),
            // No wording outside SEPA: what a Bacs or ACH debtor agrees to is for the creditor and their bank to write.
            'bacs_title'          => self::defaultTitle('bacs'),
            'bacs_text'           => '',
            'bacs_note'           => '',
            'ach_title'           => self::defaultTitle('ach'),
            'ach_text'            => '',
            'ach_note'            => '',
            'iban_label'          => self::defaultPartLabel('iban'),
            'bic_label'           => self::defaultPartLabel('bic'),
            'sort_code_label'     => self::defaultPartLabel('sort_code'),
            'routing_label'       => self::defaultPartLabel('routing'),
            'account_label'       => self::defaultPartLabel('account'),
            'account_type_label'  => self::defaultPartLabel('account_type'),
            'holder_label'        => self::defaultPartLabel('holder'),
            // Debtor details the form may already collect elsewhere: asked for here only when switched on.
            'debtor_address'      => false,
            'street_label'        => self::defaultPartLabel('street'),
            'postcode_label'      => self::defaultPartLabel('postcode'),
            'city_label'          => self::defaultPartLabel('city'),
            'country_label'       => self::defaultPartLabel('country'),
            'signing_place'       => false,
            'place_label'         => self::defaultPartLabel('place'),
            'date_label'          => __('Date of signing', 'formfabricator'),
            'creditor_name'          => '',
            'creditor_name_label'    => __('Creditor', 'formfabricator'),
            'creditor_address'       => '',
            'creditor_address_label' => __('Creditor address', 'formfabricator'),
            'payment_type'        => 'none',
            'payment_type_label'  => __('Type of payment', 'formfabricator'),
            'recurrent_label'     => __('Recurrent payment', 'formfabricator'),
            'one_off_label'       => __('One-off payment', 'formfabricator'),
            'creditor_id'         => '',
            'bacs_creditor_id'    => '',
            'ach_creditor_id'     => '',
            'creditor_label'      => self::defaultCreditorLabel('sepa'),
            'bacs_creditor_label' => self::defaultCreditorLabel('bacs'),
            'ach_creditor_label'  => self::defaultCreditorLabel('ach'),
            'reference_label'      => self::defaultReferenceLabel('sepa'),
            'bacs_reference_label' => self::defaultReferenceLabel('bacs'),
            'ach_reference_label'  => self::defaultReferenceLabel('ach'),
            'ref_mode'            => 'text',
            'mandate_ref'         => '',
            'mandate_ref_prefix'  => '',
            'ref_pending_text'    => __('assigned when the form is sent', 'formfabricator'),
            'account_type_placeholder' => __('Please choose', 'formfabricator'),
            'checking_label'      => __('Checking', 'formfabricator'),
            'savings_label'       => __('Savings', 'formfabricator'),
            'sig_label'           => __('Signature', 'formfabricator'),
            'sig_hint'            => __('Sign here', 'formfabricator'),
            'clear_label'         => __('Clear signature', 'formfabricator'),
            'type_sig_label'      => __('Type your name instead', 'formfabricator'),
            'draw_sig_label'      => __('Draw instead', 'formfabricator'),
            'typed_name_label'    => __('Your full name', 'formfabricator'),
            'canvas_height'       => 200,
            'stroke_width'        => 2,
            'placeholder_country' => 'DE',
            'country_filter_mode' => 'off',
            'country_filter_list' => [],
        ];
    }

    /**
     * Returns the general settings schema: what the mandate says and collects. Labels are in getAdvancedSchema().
     *
     * @return array
     */
    public function getGeneralSchema(): array
    {
        $for = static fn(string $scheme): array => ['key' => 'scheme', 'is' => $scheme, 'default' => 'sepa'];

        $schema = [
            [
                'key'              => 'scheme',
                'type'             => 'pill3',
                'label'            => __('Scheme', 'formfabricator'),
                'values'           => array_keys(self::SCHEMES),
                'labels'           => ['SEPA', __('Bacs (UK)', 'formfabricator'), __('ACH (US)', 'formfabricator')],
                'default'          => 'sepa',
                'rebuild'          => true,
                'rebuild_advanced' => true,
            ],
            [
                'type'       => 'notice',
                'level'      => 'warning',
                'text'       => __('FormFabricator ships no wording for Bacs. Enter the instruction text and the Direct Debit Guarantee as your bank or payment provider requires them.', 'formfabricator'),
                'depends_on' => $for('bacs'),
            ],
            [
                'type'       => 'notice',
                'level'      => 'warning',
                'text'       => __('FormFabricator ships no wording for ACH. Enter the authorization text your bank or payment provider requires.', 'formfabricator'),
                'depends_on' => $for('ach'),
            ],
            ['type' => 'section_title', 'label' => __('Mandate', 'formfabricator')],
        ];

        foreach (array_keys(self::SCHEMES) as $scheme) {
            $prefix   = $scheme === 'sepa' ? 'mandate_' : $scheme . '_';
            $schema[] = ['key' => $prefix . 'title', 'type' => 'text', 'label' => __('Mandate title', 'formfabricator'), 'depends_on' => $for($scheme)];
            $schema[] = ['key' => $prefix . 'text', 'type' => 'textarea', 'label' => __('Mandate text (HTML allowed)', 'formfabricator'), 'depends_on' => $for($scheme)];
            $schema[] = ['key' => $prefix . 'note', 'type' => 'textarea', 'label' => __('Hint text (fine print, HTML allowed)', 'formfabricator'), 'depends_on' => $for($scheme)];
        }

        /* ---- Creditor and reference: shown and recorded when set ---- */
        $schema[] = ['type' => 'section_title', 'label' => __('Creditor', 'formfabricator')];
        $schema[] = ['key' => 'creditor_name', 'type' => 'text', 'label' => __('Creditor name', 'formfabricator'), 'hint' => __('Empty: not shown.', 'formfabricator')];
        $schema[] = ['key' => 'creditor_address', 'type' => 'text', 'label' => __('Creditor address', 'formfabricator'), 'hint' => __('Empty: not shown.', 'formfabricator')];
        $schema[] = ['key' => 'creditor_id', 'type' => 'text', 'label' => __('Creditor ID', 'formfabricator'), 'depends_on' => $for('sepa')];
        $schema[] = ['key' => 'bacs_creditor_id', 'type' => 'text', 'label' => __('Service user number (SUN)', 'formfabricator'), 'depends_on' => $for('bacs')];
        $schema[] = ['key' => 'ach_creditor_id', 'type' => 'text', 'label' => __('Company ID', 'formfabricator'), 'depends_on' => $for('ach')];
        $schema[] = [
            'key'              => 'payment_type',
            'type'             => 'pill3',
            'label'            => __('Type of payment', 'formfabricator'),
            'values'           => self::PAYMENT_TYPES,
            'labels'           => [__('Not stated', 'formfabricator'), __('Recurrent', 'formfabricator'), __('One-off', 'formfabricator')],
            'default'          => 'none',
            'rebuild_advanced' => true,
        ];
        $by_mode  = static fn(string $mode): array => ['key' => 'ref_mode', 'is' => $mode, 'default' => 'text'];
        $schema[] = [
            'key'     => 'ref_mode',
            'type'    => 'pill3',
            'label'   => __('Mandate reference', 'formfabricator'),
            'values'  => ['text', 'generated'],
            'labels'  => [__('Own text', 'formfabricator'), _x('Generated', 'mandate reference made per mandate', 'formfabricator')],
            'default' => 'text',
            'rebuild' => true,
        ];
        $schema[] = [
            'key'        => 'mandate_ref',
            'type'       => 'text',
            'label'      => __('Reference text', 'formfabricator'),
            'hint'       => __('Shown and recorded as typed, e.g. "Your membership number". Empty: no reference line.', 'formfabricator'),
            'depends_on' => $by_mode('text'),
        ];
        $schema[] = [
            'key'        => 'mandate_ref_prefix',
            'type'       => 'text',
            'label'      => __('Mandate reference prefix', 'formfabricator'),
            // phpcs:ignore Generic.Files.LineLength -- WordPress.WP.I18n.NonSingularStringLiteralText requires __() to receive a single unbroken string literal, so it cannot be wrapped via concatenation.
            'hint'       => __('Each mandate gets its own reference: this prefix, the date and a random part. Up to 15 characters of the SEPA set (letters, digits, spaces, / ? : ( ) . , \' + -); accents are dropped, other characters left out.', 'formfabricator'),
            'depends_on' => $by_mode('generated'),
        ];
        $schema[] = [
            'key'        => 'ref_pending_text',
            'type'       => 'text',
            'label'      => __('Shown in place of the reference before sending', 'formfabricator'),
            'hint'       => __('The visitor learns the reference only from an email: put {ID_ref}, with this field\'s ID for ID, into a notification sent to them.', 'formfabricator'),
            'depends_on' => $by_mode('generated'),
        ];

        /* ---- Debtor details: switched on when the rest of the form does not collect them ---- */
        $schema[] = ['type' => 'section_title', 'label' => __('Debtor details', 'formfabricator')];
        // One notice per scheme: SEPA's adds why the address matters most there.
        $sepa_notice = __('A mandate also names the debtor\'s address and where it was signed; for IBANs from outside the EEA, such as Swiss or British ones, banks need the address. Switch these on unless the form asks elsewhere.', 'formfabricator');
        $schema[]    = ['type' => 'notice', 'level' => 'info', 'text' => $sepa_notice, 'depends_on' => $for('sepa')];
        $schema[]    = [
            'type'       => 'notice',
            'level'      => 'info',
            'text'       => __('A mandate also names the debtor\'s address and where it was signed. Switch these on unless the form asks elsewhere.', 'formfabricator'),
            'depends_on' => ['key' => 'scheme', 'not' => 'sepa', 'default' => 'sepa'],
        ];
        // Their labels are in the Advanced tab, shown there while the switch is on. The date of signing is always recorded.
        $schema[] = ['key' => 'debtor_address', 'type' => 'checkbox', 'label' => __('Ask for the debtor\'s address', 'formfabricator'), 'rebuild_advanced' => true];
        $schema[] = ['key' => 'signing_place', 'type' => 'checkbox', 'label' => __('Ask for the place of signing', 'formfabricator'), 'rebuild_advanced' => true];
        return $schema;
    }

    /**
     * The builder's name for the label setting of a debtor detail.
     *
     * @param string $part Sub-input key.
     * @return string
     */
    private static function extraLabelSetting(string $part): string
    {
        return match ($part) {
            'street'   => __('Street label', 'formfabricator'),
            'postcode' => __('Postal code label', 'formfabricator'),
            'city'     => __('City label', 'formfabricator'),
            'country'  => __('Country label', 'formfabricator'),
            default    => __('Place of signing label', 'formfabricator'),
        };
    }

    /**
     * Returns the advanced settings schema: every label the mandate shows (each only while its scheme or setting is
     * on), then the IBAN format and the signature pad.
     *
     * @return array
     */
    public function getAdvancedSchema(): array
    {
        $for     = static fn(string $scheme): array => ['key' => 'scheme', 'is' => $scheme, 'default' => 'sepa'];
        $not_for = static fn(string $scheme): array => ['key' => 'scheme', 'not' => $scheme, 'default' => 'sepa'];

        $schema = [
            ['type' => 'section_title', 'label' => __('Labels', 'formfabricator')],
            ['key' => 'iban_label', 'type' => 'text', 'label' => __('IBAN label', 'formfabricator'), 'depends_on' => $for('sepa')],
            ['key' => 'bic_label', 'type' => 'text', 'label' => __('BIC label', 'formfabricator'), 'depends_on' => $for('sepa')],
            ['key' => 'sort_code_label', 'type' => 'text', 'label' => __('Sort code label', 'formfabricator'), 'depends_on' => $for('bacs')],
            ['key' => 'routing_label', 'type' => 'text', 'label' => __('Routing number label', 'formfabricator'), 'depends_on' => $for('ach')],
            ['key' => 'account_label', 'type' => 'text', 'label' => __('Account number label', 'formfabricator'), 'depends_on' => $not_for('sepa')],
            ['key' => 'account_type_label', 'type' => 'text', 'label' => __('Account type label', 'formfabricator'), 'depends_on' => $for('ach')],
            ['key' => 'account_type_placeholder', 'type' => 'text', 'label' => __('Account type: empty choice', 'formfabricator'), 'depends_on' => $for('ach')],
            ['key' => 'checking_label', 'type' => 'text', 'label' => __('Account type: checking', 'formfabricator'), 'depends_on' => $for('ach')],
            ['key' => 'savings_label', 'type' => 'text', 'label' => __('Account type: savings', 'formfabricator'), 'depends_on' => $for('ach')],
            ['key' => 'holder_label', 'type' => 'text', 'label' => __('Account holder label', 'formfabricator')],
        ];
        foreach (self::DEBTOR_EXTRAS['debtor_address'] as $part) {
            $schema[] = ['key' => $part . '_label', 'type' => 'text', 'label' => self::extraLabelSetting($part), 'depends_on' => ['debtor_address' => true]];
        }
        $schema[] = ['key' => 'place_label', 'type' => 'text', 'label' => self::extraLabelSetting('place'), 'depends_on' => ['signing_place' => true]];
        $schema[] = ['key' => 'date_label', 'type' => 'text', 'label' => __('Date of signing label', 'formfabricator')];
        $schema[] = ['key' => 'creditor_name_label', 'type' => 'text', 'label' => __('Label of the creditor name', 'formfabricator')];
        $schema[] = ['key' => 'creditor_address_label', 'type' => 'text', 'label' => __('Label of the creditor address', 'formfabricator')];
        foreach (array_keys(self::SCHEMES) as $scheme) {
            $prefix   = $scheme === 'sepa' ? '' : $scheme . '_';
            $schema[] = ['key' => $prefix . 'creditor_label', 'type' => 'text', 'label' => __('Label of the creditor line', 'formfabricator'), 'depends_on' => $for($scheme)];
            $schema[] = ['key' => $prefix . 'reference_label', 'type' => 'text', 'label' => __('Label of the reference line', 'formfabricator'), 'depends_on' => $for($scheme)];
        }
        $by_payment = ['key' => 'payment_type', 'not' => 'none', 'default' => 'none'];
        $schema[]   = ['key' => 'payment_type_label', 'type' => 'text', 'label' => __('Label of the payment type', 'formfabricator'), 'depends_on' => $by_payment];
        $schema[]   = ['key' => 'recurrent_label', 'type' => 'text', 'label' => __('Text for recurrent payments', 'formfabricator'), 'depends_on' => $by_payment];
        $schema[]   = ['key' => 'one_off_label', 'type' => 'text', 'label' => __('Text for a one-off payment', 'formfabricator'), 'depends_on' => $by_payment];
        $schema[]   = ['key' => 'sig_label', 'type' => 'text', 'label' => __('Signature label', 'formfabricator')];
        $schema[]   = ['key' => 'sig_hint', 'type' => 'text', 'label' => __('Signature hint', 'formfabricator')];
        $schema[]   = ['key' => 'clear_label', 'type' => 'text', 'label' => __('Clear button', 'formfabricator')];
        $schema[]   = ['key' => 'type_sig_label', 'type' => 'text', 'label' => __('Button to type the name instead', 'formfabricator')];
        $schema[]   = ['key' => 'draw_sig_label', 'type' => 'text', 'label' => __('Button to draw instead', 'formfabricator')];
        $schema[]   = ['key' => 'typed_name_label', 'type' => 'text', 'label' => __('Label of the typed name', 'formfabricator')];

        $schema[] = [
            'key'        => 'placeholder_country',
            'type'       => 'select',
            'label'      => __('Placeholder country (IBAN format)', 'formfabricator'),
            'default'    => 'DE',
            'options'    => $this->ibanCountryOptions(),
            'depends_on' => $for('sepa'),
        ];

        $schema[] = ['type' => 'section_title', 'label' => __('Signature', 'formfabricator')];
        $schema[] = ['key' => 'canvas_height', 'type' => 'number', 'label' => __('Signature height (px)', 'formfabricator'), 'default' => 200];
        $schema[] = ['key' => 'stroke_width', 'type' => 'number', 'label' => __('Stroke width', 'formfabricator'), 'default' => 2];
        return $schema;
    }

    /**
     * Returns the SEPA countries' IBAN options for the placeholder-country selector.
     *
     * @return array
     */
    private function ibanCountryOptions(): array
    {
        // SEPA_COUNTRIES only: an IBAN format from elsewhere would be a placeholder for an IBAN the field refuses.
        $countries = [
            'AD' => __('Andorra', 'formfabricator'), 'AL' => __('Albania', 'formfabricator'), 'AT' => __('Austria', 'formfabricator'),
            'BE' => __('Belgium', 'formfabricator'), 'BG' => __('Bulgaria', 'formfabricator'), 'CH' => __('Switzerland', 'formfabricator'),
            'CY' => __('Cyprus', 'formfabricator'), 'CZ' => __('Czechia', 'formfabricator'), 'DE' => __('Germany', 'formfabricator'),
            'DK' => __('Denmark', 'formfabricator'), 'EE' => __('Estonia', 'formfabricator'), 'ES' => __('Spain', 'formfabricator'),
            'FI' => __('Finland', 'formfabricator'), 'FR' => __('France', 'formfabricator'), 'GB' => __('United Kingdom', 'formfabricator'),
            'GI' => __('Gibraltar', 'formfabricator'), 'GR' => __('Greece', 'formfabricator'), 'HR' => __('Croatia', 'formfabricator'),
            'HU' => __('Hungary', 'formfabricator'), 'IE' => __('Ireland', 'formfabricator'), 'IS' => __('Iceland', 'formfabricator'),
            'IT' => __('Italy', 'formfabricator'), 'LI' => __('Liechtenstein', 'formfabricator'), 'LT' => __('Lithuania', 'formfabricator'),
            'LU' => __('Luxembourg', 'formfabricator'), 'LV' => __('Latvia', 'formfabricator'), 'MC' => __('Monaco', 'formfabricator'),
            'MD' => __('Moldova', 'formfabricator'), 'ME' => __('Montenegro', 'formfabricator'), 'MK' => __('North Macedonia', 'formfabricator'),
            'MT' => __('Malta', 'formfabricator'), 'NL' => __('Netherlands', 'formfabricator'), 'NO' => __('Norway', 'formfabricator'),
            'PL' => __('Poland', 'formfabricator'), 'PT' => __('Portugal', 'formfabricator'), 'RO' => __('Romania', 'formfabricator'),
            'RS' => __('Serbia', 'formfabricator'), 'SE' => __('Sweden', 'formfabricator'), 'SI' => __('Slovenia', 'formfabricator'),
            'SK' => __('Slovakia', 'formfabricator'), 'SM' => __('San Marino', 'formfabricator'), 'VA' => __('Vatican City', 'formfabricator'),
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
