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
 * SEPA direct debit mandate composite field with IBAN, BIC, and a signature canvas.
 */
class SepaField extends BaseField
{
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
        return ['fn' => 'function(){return false;}'];
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
                'fn'   => <<<'JS'
                function (fieldEl) {
                    var inp = fieldEl.querySelector('.forge-sepa-iban');
                    if (!inp) return null;
                    var raw = inp.value.replace(/[^A-Za-z0-9]/g, '');
                    if (!raw) return null;
                    var _i18n = window.ForgeForms && window.ForgeForms.i18n;
                    if (inp._forgeIbanInvalid) return (_i18n && _i18n.sepa_iban_invalid)    || 'Invalid IBAN (check digit incorrect).';
                    if (!inp._forgeIbanValid)  return (_i18n && _i18n.sepa_iban_incomplete) || 'Please enter a complete and valid IBAN.';
                    return null;
                }
                JS,
            ],
            [
                'rule' => 'sepa-bic',
                'fn'   => <<<'JS'
                function (fieldEl) {
                    var bic = fieldEl.querySelector('.forge-sepa-bic');
                    if (!bic || !bic.value.trim()) return null;
                    var bicErr = bic.parentNode.querySelector('.forge-field-error');
                    if (/^[A-Za-z]{6}[A-Za-z0-9]{2}([A-Za-z0-9]{3})?$/.test(bic.value.trim())) return null;
                    var _i18nB = window.ForgeForms && window.ForgeForms.i18n;
                    if (bicErr && !bicErr.textContent) bicErr.textContent = (_i18nB && _i18nB.sepa_bic_invalid) || 'Please enter a valid BIC.';
                    return '​';
                }
                JS,
            ],
            [
                'rule' => 'sepa-required',
                'fn'   => <<<'JS'
                function (fieldEl) {
                    if (fieldEl.dataset.required !== 'true') return null;
                    var missing = false;
                    var iban = fieldEl.querySelector('.forge-sepa-iban');
                    var ibanErr = iban ? iban.parentNode.querySelector('.forge-field-error') : null;
                    if (iban && !iban.value.replace(/[^A-Za-z0-9]/g, '')) {
                        var _i18nR = window.ForgeForms && window.ForgeForms.i18n;
                        if (ibanErr && !ibanErr.textContent) ibanErr.textContent = (_i18nR && _i18nR.sepa_iban_required) || 'IBAN is required.';
                        missing = true;
                    }
                    var bic = fieldEl.querySelector('.forge-sepa-bic');
                    var bicErr = bic ? bic.parentNode.querySelector('.forge-field-error') : null;
                    if (bic && !bic.value.trim()) {
                        var _i18nBr = window.ForgeForms && window.ForgeForms.i18n;
                        if (bicErr && !bicErr.textContent) bicErr.textContent = (_i18nBr && _i18nBr.sepa_bic_required) || 'BIC is required.';
                        missing = true;
                    }
                    var holder = fieldEl.querySelector('.forge-sepa-holder');
                    var holderErr = holder ? holder.parentNode.querySelector('.forge-field-error') : null;
                    if (holder && !holder.value.trim()) {
                        var _i18nH = window.ForgeForms && window.ForgeForms.i18n;
                        if (holderErr && !holderErr.textContent)
                            holderErr.textContent = (_i18nH && _i18nH.sepa_holder_required) || 'Account holder is required.';
                        missing = true;
                    }
                    var sig = fieldEl.querySelector('.forge-sepa-sig-data');
                    var sigErr = fieldEl.querySelector('.forge-sepa-sig-error');
                    if (sig && !sig.value) {
                        var _i18nSig = window.ForgeForms && window.ForgeForms.i18n;
                        if (sigErr && !sigErr.textContent) sigErr.textContent = (_i18nSig && _i18nSig.sepa_sig_required) || 'Please sign.';
                        missing = true;
                    }
                    return missing ? '​' : null;
                }
                JS,
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
        return <<<'CSS'
.forge-field--sepa {
    border: 1px solid var(--forge-border);
    border-radius: var(--forge-radius);
    padding: 24px;
    margin-bottom: 24px;
    background: var(--forge-bg);
}
.forge-sepa-title { font-size: 16px; font-weight: 700; margin: 0 0 14px; color: var(--forge-text); }
.forge-sepa-text { font-size: 14px; line-height: 1.65; color: var(--forge-text); margin-bottom: 10px; }
.forge-sepa-note { font-size: 12px; color: var(--forge-text-muted); margin-bottom: 20px; line-height: 1.55; }
.forge-sepa-lookup-notice { font-size: 11px; color: var(--forge-text-muted); margin: 4px 0 0; line-height: 1.4; }
.forge-sepa-creditor {
    background: var(--forge-bg-subtle);
    border: 1px solid var(--forge-border);
    padding: 10px 14px;
    border-radius: var(--forge-radius-sm);
    font-size: 13px;
    color: var(--forge-text-muted);
    margin-bottom: 20px;
    line-height: 1.6;
}
.forge-sepa-creditor p { margin: 1px 0; }
.forge-field-inner { margin-bottom: 14px; }
.forge-sepa-signatures { display: flex; flex-direction: column; gap: 20px; margin-top: 20px; }
.forge-sepa-dual-sig { flex-direction: row; flex-wrap: wrap; gap: 16px; }
.forge-sepa-dual-sig .forge-sepa-sig-block { flex: 1; min-width: 240px; }
.forge-sepa-sig-block { display: flex; flex-direction: column; gap: 0; }
.forge-sepa-sig-label { font-size: 13px; font-weight: 600; margin: 0 !important; color: var(--forge-text); }
.forge-sepa-iban { font-family: monospace; letter-spacing: 1px; font-size: 15px; }
@media (max-width: 600px) {
    .forge-sepa-dual-sig { flex-direction: column; }
    .forge-field--sepa { padding: 16px; }
}
CSS;
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
        return <<<'JS'
        function (root) {
            var IBAN_LEN = {
                AD:24,AE:23,AL:28,AT:20,AZ:28,BA:20,BE:16,BG:22,BH:22,BI:27,BR:29,BY:28,
                CH:21,CR:22,CY:28,CZ:24,DE:22,DJ:27,DK:18,DO:28,EE:20,EG:29,ES:24,FI:18,
                FK:18,FO:18,FR:27,GB:22,GE:22,GI:23,GL:18,GR:27,GT:28,HR:21,HU:28,IE:22,
                IL:23,IQ:23,IS:26,IT:27,JO:30,KW:30,KZ:20,LB:28,LC:32,LI:21,LT:20,LU:20,
                LV:21,LY:25,MC:27,MD:24,ME:22,MK:19,MN:20,MR:27,MT:31,MU:30,NI:28,NL:18,
                NO:15,OM:23,PK:24,PL:28,PS:29,PT:25,QA:29,RO:24,RS:22,RU:33,SA:24,SC:31,
                SD:18,SE:24,SI:19,SK:24,SM:27,SO:23,ST:25,SV:28,TL:23,TN:24,TR:26,UA:29,
                VA:22,VG:24,XK:20,YE:30
            };
            function ibanTemplate(cc) {
                var len = IBAN_LEN[cc];
                if (!len) return cc + '__ …';
                var raw = cc + new Array(len - 1).join('_');
                var out = '', pos = 0;
                while (pos < raw.length) {
                    if (pos > 0) out += ' ';
                    out += raw.substring(pos, pos + 4);
                    pos += 4;
                }
                return out;
            }
            root.querySelectorAll('.forge-sepa-iban').forEach(function (input) {
                if (input._forgeIbanInited) return;
                input._forgeIbanInited = true;
                var filterMode  = input.dataset.countryFilter || 'off';
                var filterList  = input.dataset.countryList
                    ? input.dataset.countryList.toUpperCase().split(',') : [];
                var defaultCc   = (input.dataset.placeholderCountry || 'DE').toUpperCase();
                var errorEl     = input.parentNode.querySelector('.forge-field-error');
                var lastValidCc = IBAN_LEN[defaultCc] ? defaultCc : 'DE';
                input.placeholder = ibanTemplate(lastValidCc);
                function countryAllowed(cc) {
                    if (filterMode === 'off' || !filterList.length) return true;
                    var inList = filterList.indexOf(cc) !== -1;
                    if (filterMode === 'allow')    return inList;
                    if (filterMode === 'disallow') return !inList;
                    return true;
                }
                var noticeEl = input.parentNode.querySelector('.forge-field-hint');
                function showError(msg)       { if (errorEl)  errorEl.textContent  = msg; }
                function showIbanNotice(msg)  { if (noticeEl) noticeEl.textContent = msg; }
                function getBicInput() {
                    var field = input.closest('.forge-field--sepa');
                    return field ? field.querySelector('.forge-sepa-bic') : null;
                }
                var bicManuallyEntered = false;
                function lookupBic(iban) {
                    if (bicManuallyEntered) return;
                    if (input.dataset.liveLookup === '0') return;
                    var bicInput = getBicInput();
                    if (!bicInput) return;
                    var ajaxUrl = (window.ForgeForms && window.ForgeForms.ajaxUrl) || '';
                    if (!ajaxUrl) return;
                    bicInput.value    = '';
                    bicInput.disabled = true;
                    var dots = 0;
                    var dotTimer = setInterval(function () {
                        dots = (dots + 1) % 4;
                        var _i18nLu = window.ForgeForms && window.ForgeForms.i18n;
                        bicInput.placeholder = ((_i18nLu && _i18nLu.sepa_looking_up) || 'Looking up') + '.'.repeat(dots);
                    }, 400);
                    var body = new FormData();
                    body.append('action', 'forge_iban_bic');
                    body.append('iban', iban);
                    body.append('nonce', (window.ForgeForms && window.ForgeForms.ibanBicNonce) || '');
                    fetch(ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
                        .then(function (r) { return r.json(); })
                        .then(function (res) {
                            clearInterval(dotTimer);
                            bicInput.disabled    = false;
                            bicInput.placeholder = 'XXXXXXXXXXX';
                            var d = res.data || {};
                            if (!d.valid) {
                                input._forgeIbanValid   = false;
                                input._forgeIbanInvalid = true;
                                var _i18nIi = window.ForgeForms && window.ForgeForms.i18n;
                                showError((_i18nIi && _i18nIi.sepa_iban_invalid) || 'Invalid IBAN (check digit incorrect).');
                            } else {
                                input._forgeIbanValid   = true;
                                input._forgeIbanInvalid = false;
                                showError('');
                                if (d.bic && !bicManuallyEntered) {
                                    bicInput.value = d.bic;
                                } else if (!d.bankCodeFound) {
                                    var _i18nUv = window.ForgeForms && window.ForgeForms.i18n;
                                    showIbanNotice((_i18nUv && _i18nUv.sepa_iban_unvalidated) || 'Could not be validated.');
                                }
                            }
                        })
                        .catch(function () {
                            clearInterval(dotTimer);
                            bicInput.disabled    = false;
                            bicInput.placeholder = 'XXXXXXXXXXX';
                        });
                }
                var bicEl = getBicInput();
                if (bicEl) {
                    bicEl.addEventListener('input', function () { bicManuallyEntered = !!this.value; });
                }
                function getRaw() { return input.value.replace(/[^A-Za-z0-9]/g, '').toUpperCase(); }
                input.addEventListener('input', function () {
                    input._forgeIbanValid   = false;
                    input._forgeIbanInvalid = false;
                    var raw = getRaw();
                    var cc  = raw.substring(0, 2);
                    if (cc.length === 2 && IBAN_LEN[cc]) {
                        if (countryAllowed(cc)) { lastValidCc = cc; showError(''); showIbanNotice(''); }
                        else { var _i18nCb = window.ForgeForms && window.ForgeForms.i18n; showError((_i18nCb && _i18nCb.sepa_country_blocked) || 'This country is not allowed.'); }
                    } else { showError(''); showIbanNotice(''); }
                    this.placeholder = ibanTemplate(lastValidCc);
                    var maxLen = IBAN_LEN[cc] || 34;
                    raw = raw.substring(0, maxLen);
                    var out = '', pos = 0;
                    while (pos < raw.length) {
                        if (pos > 0) out += ' ';
                        out += raw.substring(pos, pos + 4);
                        pos += 4;
                    }
                    this.value = out;
                    if (cc.length === 2 && IBAN_LEN[cc] && raw.length === IBAN_LEN[cc] && countryAllowed(cc)) {
                        lookupBic(raw);
                    }
                });
                input.addEventListener('keydown', function (e) {
                    if (e.key === 'Backspace') {
                        var pos = this.selectionStart;
                        if (pos > 0 && this.value[pos - 1] === ' ') {
                            this.value = this.value.slice(0, pos - 1) + this.value.slice(pos);
                            this.setSelectionRange(pos - 1, pos - 1);
                            e.preventDefault();
                        }
                    }
                });
            });
            root.querySelectorAll('.forge-sepa-bic').forEach(function (input) {
                if (input._forgeBicInited) return;
                input._forgeBicInited = true;
                input.addEventListener('input', function () {
                    this.value = this.value.replace(/[^A-Za-z0-9]/g, '').toUpperCase().slice(0, 11);
                });
            });
            /* Signature canvas init — self-contained so SepaField has no dependency
               on SignatureField being registered. Sets data-forge-file-count so
               front.js can include SEPA signatures in the total file count. */
            var sepaSigSel = '.forge-sepa-mandate .forge-signature-wrap';
            root.querySelectorAll(sepaSigSel).forEach(function (wrap) {
                if (wrap._forgeCanvasInited) return;
                wrap._forgeCanvasInited = true;
                var canvas   = wrap.querySelector('.forge-signature-canvas');
                var input    = wrap.querySelector('input[type="hidden"]');
                var clearBtn = wrap.querySelector('.forge-signature-clear');
                if (!canvas || !input) return;
                var ctx    = canvas.getContext('2d');
                var stroke = parseFloat(wrap.dataset.stroke || '2');
                var fmt    = wrap.dataset.format || 'png';
                var drawing = false;
                function resize() {
                    var rect  = canvas.getBoundingClientRect();
                    var ratio = window.devicePixelRatio || 1;
                    var cssW  = rect.width  || canvas.offsetWidth;
                    var fallH = parseFloat(canvas.getAttribute('height') || '160');
                    var cssH  = rect.height || canvas.offsetHeight || fallH;
                    if (!cssW || !cssH) return;
                    canvas.width  = Math.round(cssW * ratio);
                    canvas.height = Math.round(cssH * ratio);
                    ctx.scale(ratio, ratio);
                    ctx.fillStyle = '#ffffff';
                    ctx.fillRect(0, 0, cssW, cssH);
                    ctx.strokeStyle = '#1d2327';
                    ctx.lineWidth   = stroke;
                    ctx.lineCap     = 'round';
                    ctx.lineJoin    = 'round';
                }
                function pos(e) {
                    var rect = canvas.getBoundingClientRect();
                    var src  = e.touches ? e.touches[0] : e;
                    return { x: src.clientX - rect.left, y: src.clientY - rect.top };
                }
                function start(e) {
                    e.preventDefault();
                    drawing = true;
                    var p = pos(e);
                    ctx.beginPath();
                    ctx.moveTo(p.x, p.y);
                }
                function move(e) {
                    if (!drawing) return;
                    e.preventDefault();
                    var p = pos(e);
                    ctx.lineTo(p.x, p.y);
                    ctx.stroke();
                }
                function end() {
                    if (!drawing) return;
                    drawing = false;
                    var mime = fmt === 'jpeg' ? 'image/jpeg' : 'image/png';
                    input.value = canvas.toDataURL(mime);
                }
                canvas.addEventListener('mousedown',  start, { passive: false });
                canvas.addEventListener('mousemove',  move,  { passive: false });
                document.addEventListener('mouseup',  end);
                canvas.addEventListener('touchstart', start, { passive: false });
                canvas.addEventListener('touchmove',  move,  { passive: false });
                canvas.addEventListener('touchend',   end);
                if (clearBtn) {
                    clearBtn.addEventListener('click', function () {
                        ctx.fillStyle = '#ffffff';
                        ctx.fillRect(0, 0, canvas.width, canvas.height);
                        input.value = '';
                        wrap.dataset.forgeFileCount = '0';
                    });
                }
                var ownerForm = canvas.closest('form');
                if (ownerForm) {
                    ownerForm.addEventListener('reset', function () {
                        ctx.fillStyle = '#ffffff';
                        ctx.fillRect(0, 0, canvas.width, canvas.height);
                        input.value = '';
                    });
                }
                resize();
                window.addEventListener('resize', resize);
                if (typeof ResizeObserver !== 'undefined') {
                    new ResizeObserver(function (entries) {
                        if (entries[0].contentRect.width > 0) resize();
                    }).observe(canvas);
                }
            });
        }
        JS;
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
        $html  = '<div class="forge-field forge-field--sepa" data-field-id="' . esc_attr($field_id) . '"'
            . $req_attr . ' data-validate="' . esc_attr(wp_json_encode($rules)) . '">';
        $html .= '<div class="forge-sepa-mandate">';
        $html .= '<h3 class="forge-sepa-title">' . $mandate_title . '</h3>';
        $html .= '<div class="forge-sepa-text">' . $mandate_text . '</div>';

        if ($mandate_note !== '') {
            $html .= '<p class="forge-sepa-note">' . $mandate_note . '</p>';
        }

        /* ---- IBAN ---- */
        $html .= '<div class="forge-field-inner">';
        $html .= '<label class="forge-label" for="' . esc_attr($field_id) . '-iban">' . $iban_label;
        if (!empty($config['required'])) {
            $html .= ' <span class="forge-required" aria-hidden="true">*</span>';
        }
        $html .= '</label>';
        // live_iban_lookup defaults to false (opt-in, GDPR). When enabled, the BIC
        // lookup AJAX call (which relays the visitor's IBAN to the third-party
        // openiban.com service pre-submission) runs client-side; otherwise the
        // account holder enters the BIC manually.
        $live_lookup = isset($config['live_iban_lookup']) && !empty($config['live_iban_lookup']);
        $html .= '<input type="text" id="' . esc_attr($field_id) . '-iban"'
            . ' name="' . esc_attr($field_id) . '[iban]"'
            . ' class="forge-input forge-sepa-iban"'
            . ' maxlength="42" autocomplete="off"'
            . ' inputmode="text" spellcheck="false"'
            . ' data-placeholder-country="' . $placeholder_cc . '"'
            . ' data-live-lookup="' . ($live_lookup ? '1' : '0') . '"'
            . $filter_attr
            . ' value="' . $iban_val . '">';
        $html .= '<div class="forge-field-hint"></div>';
        if ($live_lookup) {
            // GDPR Art. 13 transparency: live_iban_lookup sends the IBAN to the
            // third-party openiban.com service as soon as it's fully typed, before
            // submission — disclose this to the visitor, not just the admin
            // configuring the field.
            $html .= '<p class="forge-sepa-lookup-notice">'
                . esc_html__(
                    'Your IBAN is sent to a third-party service (openiban.com) to look up the BIC.',
                    'formfabricator'
                ) . '</p>';
        }
        $html .= '<div class="forge-field-error" id="' . esc_attr($field_id) . '-iban-error" role="alert"></div>';
        $html .= '</div>';

        /* ---- BIC ---- */
        $html .= '<div class="forge-field-inner">';
        $html .= '<label class="forge-label" for="' . esc_attr($field_id) . '-bic">' . $bic_label;
        if (!empty($config['required'])) {
            $html .= ' <span class="forge-required" aria-hidden="true">*</span>';
        }
        $html .= '</label>';
        $html .= '<input type="text" id="' . esc_attr($field_id) . '-bic"'
            . ' name="' . esc_attr($field_id) . '[bic]"'
            . ' class="forge-input forge-sepa-bic"'
            . ' placeholder="XXXXXXXXXXX" maxlength="11" autocomplete="off"'
            . ' value="' . $bic_val . '">';
        $html .= '<div class="forge-field-error" id="' . esc_attr($field_id) . '-bic-error" role="alert"></div>';
        $html .= '</div>';

        /* ---- Kontoinhaber ---- */
        $html .= '<div class="forge-field-inner">';
        $html .= '<label class="forge-label" for="' . esc_attr($field_id) . '-holder">' . $holder_label;
        if (!empty($config['required'])) {
            $html .= ' <span class="forge-required" aria-hidden="true">*</span>';
        }
        $html .= '</label>';
        $html .= '<input type="text" id="' . esc_attr($field_id) . '-holder"'
            . ' name="' . esc_attr($field_id) . '[holder]"'
            . ' class="forge-input forge-sepa-holder" autocomplete="off"'
            . ' value="' . $holder_val . '">';
        $html .= '<div class="forge-field-error" id="' . esc_attr($field_id) . '-holder-error" role="alert"></div>';
        $html .= '</div>';

        /* ---- Static creditor info ---- */
        if ($creditor_id !== '' || $mandate_ref !== '') {
            $html .= '<div class="forge-sepa-creditor">';
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
        $html .= '<div class="forge-sepa-signatures">';
        $html .= '<div class="forge-sepa-sig-block">';
        $html .= '<div class="forge-sepa-sig-label">' . $sig_label;
        if (!empty($config['required'])) {
            $html .= ' <span class="forge-required" aria-hidden="true">*</span>';
        }
        $html .= '</div>';
        $html .= '<div class="forge-signature-wrap"'
            . ' data-field-id="' . esc_attr($field_id) . '-sig"'
            . (!empty($config['required']) ? ' data-required="true"' : '')
            . ' data-stroke="' . esc_attr((string)$stroke_width) . '">';
        $html .= '<canvas id="' . $canvas_id . '" class="forge-signature-canvas"'
            . ' width="400" height="' . $canvas_height . '"'
            . ' style="height:' . $canvas_height . 'px"'
            . ' tabindex="0" aria-label="' . $sig_label . '"></canvas>';
        $html .= '<input type="hidden"'
            . ' name="' . esc_attr($field_id) . '-sig"'
            . ' id="' . esc_attr($field_id) . '-sig-data"'
            . ' class="forge-sepa-sig-data">';
        $html .= '<div class="forge-signature-toolbar">';
        $reset_icon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"'
            . ' aria-hidden="true" focusable="false">'
            . '<path d="M125.7 160H176a16 16 0 0 1 0 32H48a16 16 0 0 1-16-16V48a16 16 0 0 1 32 0v68.7'
            . 'C115.3 45.1 191.6 0 278 0c141.4 0 256 114.6 256 256S419.4 512 278 512'
            . 'C167.7 512 74.4 443.5 38 346a16 16 0 1 1 30-11c31.4 83.7 111.5 141 210 141'
            . ' 123.7 0 224-100.3 224-224S401.7 32 278 32c-78.1 0-145.8 39.4-185.3 99.3z"/>'
            . '</svg>';
        $html .= '<button type="button" class="forge-signature-clear"'
            . ' data-canvas="' . $canvas_id . '" title="' . esc_attr__('Clear', 'formfabricator') . '" aria-label="' . esc_attr__('Clear signature', 'formfabricator') . '">'
            . $reset_icon . '</button>';
        $html .= '<span class="forge-signature-hint">' . esc_html__('Sign here', 'formfabricator') . '</span>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '<div class="forge-field-error forge-sepa-sig-error"'
            . ' id="' . esc_attr($field_id) . '-sig-error" role="alert"></div>';
        $html .= '</div>'; /* sig-block */
        $html .= '</div>'; /* signatures */

        $html .= '<div class="forge-field-error" id="'
            . esc_attr($field_id) . '-error" role="alert" aria-live="polite"></div>';
        $html .= '</div>'; /* forge-sepa-mandate */
        $html .= '</div>'; /* forge-field */

        return $html;
    }

    /**
     * Returns the composite SEPA array: IBAN, BIC, Kontoinhaber, and signature. The signature canvas posts to a separate key ($field_id . '-sig') because the main composite array is name-indexed as $field_id[iban], $field_id[bic], etc.
     *
     * @param string $field_id The field element ID.
     */
    public function extractValue(string $field_id): mixed
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce is verified once in FormProcessor::handle() before field extraction runs.
        $raw = isset($_POST[$field_id])
            // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce is verified once in FormProcessor::handle() before field extraction runs; value is unslashed and sanitize_text_field()'d via map_deep()/capRawArray(), WPCS doesn't recognize sanitization via the string-callback form.
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
     * Verifies the ISO 7064 mod-97 checksum of a cleaned (no-space, uppercase) IBAN. Moves the country+check-digits to the end, converts letters to numbers (A=10..Z=35), then requires the result mod 97 == 1.
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
        // The signature size cap must apply regardless of whether the field is
        // required, mirroring SignatureField::validate() — extractValue() has no
        // upper bound of its own, so a crafted oversized data URI could otherwise
        // inflate memory/CPU use even on an optional SEPA field.
        if (is_array($value) && strlen((string)($value['sig'] ?? '')) > 2 * 1024 * 1024) {
            return __('Signature data is too large.', 'formfabricator');
        }

        if (empty($config['required'])) {
            return true;
        }

        if (!is_array($value)) {
            return __('SEPA data missing.', 'formfabricator');
        }

        $iban   = trim((string)($value['iban']   ?? ''));
        $bic    = trim((string)($value['bic']    ?? ''));
        $holder = trim((string)($value['holder'] ?? ''));

        if ($iban === '') {
            return __('IBAN is a required field.', 'formfabricator');
        }
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

        if ($bic === '') {
            return __('BIC is a required field.', 'formfabricator');
        }
        if (!preg_match('/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/i', $bic)) {
            return __('Please enter a valid BIC.', 'formfabricator');
        }
        if ($holder === '') {
            return __('Account holder is a required field.', 'formfabricator');
        }

        $sig = (string)($value['sig'] ?? '');
        if ($sig === '' || !self::isSignatureDataUri($sig)) {
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
