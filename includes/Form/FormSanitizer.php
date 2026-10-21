<?php

/**
 * Sanitizes a form as it is saved or imported: its fields, notifications and settings.
 *
 * PHP Version 8.1
 *
 * @category  FormFabricator
 * @package   FormFabricator
 * @author    Alexander Jorek
 * @copyright 2026 Alexander Jorek
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 * @version   1.1.1
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
 * Sanitizes what a form is saved from: the builder's payload (FormEditor) and an imported form (FormList). Both are
 * untrusted input, so the field configs, conditions, notifications and settings each get the sanitizer that fits
 * what reads them; an answer is compared with option and rule values as typed (answerText()).
 */
class FormSanitizer
{
    /* Field types that may never be a group child. Mirrors NO_GROUP_TYPES in admin-builder.js; enforced in
       sanitizeFields() for crafted saves and imports. */
    private const NO_GROUP_TYPES = ['group', 'pagebreak', 'page-header'];

    /**
     * Keys that may hold a list on any field, whatever its type declares: each has a purpose-built sanitizer in
     * sanitizeFields(). Any other key holds a list only where the field's own defaults say so.
     *
     * @var string[]
     */
    private const STRUCTURAL_ARRAY_KEYS = ['conditions', 'children', 'options'];

    /**
     * Names the form itself posts next to its fields (FormRenderer); a field with one of these IDs would overwrite it.
     *
     * @var string[]
     */
    private const RESERVED_FIELD_IDS = ['action', 'form_id', 'fabricator_nonce', 'fabricator_submission_token', 'fabricator_hp_field'];

    /**
     * Returns an error listing the field IDs a form can't use, or '' when every ID is usable.
     *
     * The builder's ID rule is client-side only, so an import could carry a duplicate ID, a name the form posts itself
     * ("action"), or characters PHP rewrites in POST keys, letting one field's input satisfy another's checks. Group
     * children share the POST namespace with top-level fields.
     *
     * @param array $fields Sanitized fields, as sanitizeFields() returns them.
     * @return string Translated error message, or ''.
     */
    public static function fieldIdError(array $fields): string
    {
        $ids      = [];
        $reserved = array_flip(self::RESERVED_FIELD_IDS);
        $collect  = static function (array $list) use (&$collect, &$ids, &$reserved): void {
            foreach ($list as $field) {
                if (!is_array($field)) {
                    continue;
                }
                $id = (string) ($field['id'] ?? '');
                if ($id !== '') {
                    $ids[] = $id;
                    // A direct debit mandate posts its signature under "<id>-sig"; a field with that ID would replace it.
                    if (($field['type'] ?? '') === 'directdebit') {
                        $reserved[$id . '-sig'] = true;
                    }
                }
                if (!empty($field['children']) && is_array($field['children'])) {
                    $collect($field['children']);
                }
            }
        };
        $collect($fields);

        $counts = array_count_values($ids);
        $bad    = [];
        foreach ($ids as $id) {
            if (!preg_match('/^[A-Za-z0-9-]+$/', $id) || isset($reserved[$id]) || $counts[$id] > 1) {
                $bad[$id] = true;
            }
        }
        if ($bad === []) {
            return '';
        }
        return sprintf(
            /* translators: %s: comma-separated list of field IDs. */
            __('These field IDs cannot be used: %s. A field ID may contain only letters, digits and hyphens, must be unique within the form, and must not be a reserved name such as "action".', 'formfabricator'),
            implode(', ', array_keys($bad))
        );
    }

    /**
     * Sanitizes the fields array from the builder.
     *
     * @param array $fields Raw fields array from the builder.
     * @return array Sanitized fields array.
     */
    public static function sanitizeFields(array $fields, int $depth = 0): array
    {
        /* Depth cap mirrors sanitizeArrayValue()'s (CWE-674): this recurses through group
           children, and an imported form is untrusted input that could nest them arbitrarily. */
        if ($depth > 10) {
            return [];
        }
        $clean = [];
        foreach ($fields as $field) {
            if (!is_array($field) || empty($field['id']) || empty($field['type'])) {
                continue;
            }
            // Server-side half of admin-builder.js's NO_GROUP_TYPES: prevents nesting that would skip validation on grandchildren.
            if ($depth > 0 && in_array((string) $field['type'], self::NO_GROUP_TYPES, true)) {
                continue;
            }
            // These structural/label keys bypass sanitizeConfigValue() — every field type handles them the same way regardless of its own rules.
            $plaintext_keys = ['id', 'type', 'label', 'placeholder', 'description', 'hint', 'name'];
            $handler  = \FabricatorForms\Fields\FieldRegistry::get((string)($field['type'] ?? ''));
            $defaults = $handler ? $handler->getDefaultConfig() : [];
            $f = [];
            foreach ($field as $k => $v) {
                $sk = \sanitize_key($k);
                // A structural or label key is always text. An array here (a crafted save or import) would reach the PDF
                // template's typed label and throw a TypeError on every submission of the form.
                if (in_array($sk, $plaintext_keys, true) && !is_string($v)) {
                    $f[$sk] = is_scalar($v) ? \sanitize_text_field((string) $v) : '';
                    continue;
                }
                // Every other key keeps the kind of value (list or single) its default has; an array under a
                // single-value key would throw a TypeError on render. The wrong kind gets the default.
                if (array_key_exists($sk, $defaults)) {
                    if (is_array($defaults[$sk]) !== is_array($v)) {
                        $v = $defaults[$sk];
                    }
                } elseif (is_array($v) && !in_array($sk, self::STRUCTURAL_ARRAY_KEYS, true)) {
                    continue; // a list under a key no field declares has no reader that expects one
                }
                if (is_string($v)) {
                    $f[$sk] = in_array($sk, $plaintext_keys, true)
                        ? \sanitize_text_field($v)
                        // $sk, not $k: plainTextConfigKeys() are lowercase.
                        : ($handler ? $handler->sanitizeConfigValue($sk, $v) : \FabricatorForms\Utils\Text\HtmlSanitizer::sanitize($v));
                } elseif (is_bool($v) || is_int($v) || is_float($v)) {
                    $f[$sk] = $v;
                } elseif (is_array($v)) {
                    // 'conditions'/'children' need their own sanitizers; HTML-sanitized rules would stop matching options.
                    if ($sk === 'conditions') {
                        $f[$sk] = self::sanitizeConditions($v);
                    } elseif ($sk === 'children') {
                        $f[$sk] = self::sanitizeFields($v, $depth + 1);
                    } else {
                        // The same plain-text/HTML split as sanitizeConfigValue().
                        $f[$sk] = self::sanitizeArrayValue(
                            $v,
                            0,
                            $handler ? $handler->isPlainTextConfigKey($sk) : false
                        );
                    }
                }
            }
            if (isset($f['options']) && is_array($f['options'])) {
                $clean_opts = [];
                $seen       = [];
                foreach ($f['options'] as $opt) {
                    if (!is_array($opt)) {
                        continue;
                    }
                    $label = \sanitize_text_field(\FabricatorForms\Utils\Cast::stringOrDefault($opt['label'] ?? ''));
                    $value = self::answerText($opt['value'] ?? '');
                    // A value is what the form posts and what rules, routing and the email compare: never empty (an
                    // option valued "" could not satisfy "required"), never shared (two options became one).
                    if ($value === '') {
                        $value = self::optionValueFromLabel($label, count($clean_opts) + 1);
                    }
                    $base = $value;
                    for ($n = 2; isset($seen[$value]); $n++) {
                        $value = $base . '-' . $n;
                    }
                    $seen[$value] = true;
                    $clean_opts[] = [
                        'value'   => $value,
                        'label'   => $label,
                        'default' => !empty($opt['default']),
                    ];
                }
                $f['options'] = $clean_opts;
            }
            $clean[] = $f;
        }
        return $clean;
    }

    /**
     * A value an answer is compared with (an option's value, a rule's value) as the browser posts it: sanitized as text,
     * then as plain characters, since answers are recorded as typed. sanitize_text_field() writes a lone "<" as "&lt;",
     * which a form shows and posts as "<". Escaped wherever it is printed.
     *
     * @param mixed $raw The saved value.
     * @return string
     */
    private static function answerText(mixed $raw): string
    {
        return html_entity_decode(\sanitize_text_field(\FabricatorForms\Utils\Cast::stringOrDefault($raw)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * An option value for an option saved without one: the builder's slugify() of its label, or "option-N" when
     * nothing ASCII is left. Not sanitize_title(), whose percent-encoding the submitted value would lose.
     *
     * @param string $label    Sanitized option label.
     * @param int    $position 1-based position of the option.
     * @return string
     */
    private static function optionValueFromLabel(string $label, int $position): string
    {
        $ascii = \remove_accents($label);
        $slug  = trim((string) preg_replace('/-{2,}/', '-', (string) preg_replace('/[^a-z0-9-]/', '', (string) preg_replace('/\s+/', '-', strtolower($ascii)))), '-');
        return $slug !== '' ? $slug : 'option-' . $position;
    }

    /**
     * Sanitizes a field-id reference; must match sanitizeFields()'s id sanitization exactly — sanitize_key() lowercases, which breaks mixed-case ids.
     *
     * @param mixed $raw Raw field-id reference from the builder payload.
     * @return string Sanitized field-id reference.
     */
    private static function sanitizeFieldRef(mixed $raw): string
    {
        return \sanitize_text_field(\FabricatorForms\Utils\Cast::stringOrDefault($raw ?? ''));
    }

    /**
     * Sanitizes a field's conditional-logic block; rule values are compared with answers as typed (answerText()).
     *
     * @param array $raw Raw conditions block: action, match, rules[].
     * @return array Sanitized conditions block.
     */
    private static function sanitizeConditions(array $raw): array
    {
        $rules = [];
        foreach ((array) ($raw['rules'] ?? []) as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $rules[] = [
                'field_id' => self::sanitizeFieldRef($rule['field_id'] ?? null),
                'operator' => \sanitize_key(\FabricatorForms\Utils\Cast::stringOrDefault($rule['operator'] ?? 'equals', 'equals')),
                'value'    => self::answerText($rule['value'] ?? ''),
            ];
        }
        return [
            'action' => in_array($raw['action'] ?? '', ['show', 'hide'], true) ? $raw['action'] : 'show',
            'match'  => in_array($raw['match'] ?? '', ['all', 'any'], true) ? $raw['match'] : 'all',
            'rules'  => $rules,
        ];
    }

    /**
     * Recursively sanitizes a nested array-valued field config entry (depth-capped against CWE-674).
     *
     * @param array $value      Raw nested array value.
     * @param int   $depth      Current recursion depth (internal use).
     * @param bool  $plain_text Sanitize string leaves with sanitize_text_field() instead of
     *                          Utils\Text\HtmlSanitizer::sanitize(); set for config keys the field declares plain text,
     *                          whose values are rendered through text sinks and would otherwise be
     *                          entity-encoded at save and shown encoded.
     * @return array Recursively sanitized array.
     */
    private static function sanitizeArrayValue(array $value, int $depth = 0, bool $plain_text = false): array
    {
        if ($depth > 10) {
            return [];
        }
        $clean = [];
        foreach ($value as $k => $v) {
            $sk = is_string($k) ? \sanitize_key($k) : $k;
            if (is_string($v)) {
                $clean[$sk] = $plain_text ? \sanitize_text_field($v) : \FabricatorForms\Utils\Text\HtmlSanitizer::sanitize($v);
            } elseif (is_bool($v) || is_int($v) || is_float($v)) {
                $clean[$sk] = $v;
            } elseif (is_array($v)) {
                $clean[$sk] = self::sanitizeArrayValue($v, $depth + 1, $plain_text);
            }
        }
        return $clean;
    }

    /**
     * Sanitizes the notifications array.
     *
     * @param array         $notifications Raw notifications array.
     * @param string[]|null $trimmed       Set to the names of the notifications whose message lost content.
     * @return array Sanitized notifications array.
     */
    public static function sanitizeNotifications(array $notifications, ?array &$trimmed = null): array
    {
        $clean   = [];
        $trimmed = [];
        foreach ($notifications as $n) {
            if (!is_array($n)) {
                continue;
            }
            /* Body is always HTML, authored via the Visual or Code view. */
            $body = \FabricatorForms\Utils\Text\EmailBodySanitizer::sanitize(\FabricatorForms\Utils\Cast::stringOrDefault($n['body'] ?? ''), $body_removed);
            $name = \sanitize_text_field(\FabricatorForms\Utils\Cast::stringOrDefault($n['name'] ?? ''));

            $routing_rules = [];
            foreach ((array)($n['routing_rules'] ?? []) as $rule) {
                if (!is_array($rule)) {
                    continue;
                }
                $routing_rules[] = [
                    'field_id' => self::sanitizeFieldRef($rule['field_id'] ?? null),
                    'operator' => \sanitize_key(\FabricatorForms\Utils\Cast::stringOrDefault($rule['operator'] ?? 'equals', 'equals')),
                    'value'    => self::answerText($rule['value'] ?? ''),
                    // An address or a placeholder, whose braces sanitize_email() would strip.
                    'email'    => \sanitize_text_field(\FabricatorForms\Utils\Cast::stringOrDefault($rule['email'] ?? '')),
                    'cc'       => \sanitize_text_field(\FabricatorForms\Utils\Cast::stringOrDefault($rule['cc'] ?? '')),
                    'bcc'      => \sanitize_text_field(\FabricatorForms\Utils\Cast::stringOrDefault($rule['bcc'] ?? '')),
                ];
            }
            $clean[] = [
                'slug'             => \sanitize_key(\FabricatorForms\Utils\Cast::stringOrDefault($n['slug'] ?? '', 'notification-' . \wp_generate_uuid4())),
                'name'             => $name,
                'recipient_mode'   => in_array($n['recipient_mode'] ?? '', ['single', 'routing'], true)
                    ? $n['recipient_mode'] : 'single',
                'to'               => \sanitize_text_field(\FabricatorForms\Utils\Cast::stringOrDefault($n['to']         ?? '')),
                'routing_rules'    => $routing_rules,
                'routing_fallback' =>
                    \sanitize_text_field(\FabricatorForms\Utils\Cast::stringOrDefault($n['routing_fallback'] ?? '')),
                'routing_fallback_cc' =>
                    \sanitize_text_field(\FabricatorForms\Utils\Cast::stringOrDefault($n['routing_fallback_cc'] ?? '')),
                'routing_fallback_bcc' =>
                    \sanitize_text_field(\FabricatorForms\Utils\Cast::stringOrDefault($n['routing_fallback_bcc'] ?? '')),
                'reply_to'         => \sanitize_text_field(\FabricatorForms\Utils\Cast::stringOrDefault($n['reply_to']   ?? '')),
                'subject'          => \sanitize_text_field(\FabricatorForms\Utils\Cast::stringOrDefault($n['subject']    ?? '')),
                'body'             => $body,
                'from_name'        => \sanitize_text_field(\FabricatorForms\Utils\Cast::stringOrDefault($n['from_name']  ?? '')),
                'from_email'       => self::sanitizeFromEmail(\FabricatorForms\Utils\Cast::stringOrDefault($n['from_email'] ?? '')),
                'cc'               => \sanitize_text_field(\FabricatorForms\Utils\Cast::stringOrDefault($n['cc']         ?? '')),
                'bcc'              => \sanitize_text_field(\FabricatorForms\Utils\Cast::stringOrDefault($n['bcc']        ?? '')),
                'attach_pdf'       => !empty($n['attach_pdf']),
                'attach_uploads'   => !empty($n['attach_uploads']),
                'automation_markers' => !empty($n['automation_markers']),
                'enabled'          => !isset($n['enabled']) || !empty($n['enabled']),
            ];
            if ($body_removed) {
                $trimmed[] = $name !== '' ? $name : $clean[array_key_last($clean)]['slug'];
            }
        }
        return $clean;
    }

    /**
     * A fixed address, or {admin_email}. Never a form field: mail from a visitor's domain can be silently discarded
     * under DMARC, losing the submission. Anything else is dropped, and the site's sender applies.
     *
     * @param string $raw Raw sender value from the builder.
     * @return string Sanitized address, '{admin_email}', or '' for the site's sender.
     */
    public static function sanitizeFromEmail(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '{admin_email}') {
            return $raw;
        }
        return \sanitize_email($raw);
    }

    /**
     * Sanitizes the form settings array.
     *
     * @param array $settings Raw settings array.
     * @return array Sanitized settings array.
     */
    public static function sanitizeSettings(array $settings): array
    {
        $rules = [];
        foreach ((array)($settings['submit_conditions']['rules'] ?? []) as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $rules[] = [
                'field_id'   => self::sanitizeFieldRef($rule['field_id']   ?? null),
                'operator'   => \sanitize_key(\FabricatorForms\Utils\Cast::stringOrDefault($rule['operator']   ?? 'equals', 'equals')),
                'value'      => \sanitize_text_field(\FabricatorForms\Utils\Cast::stringOrDefault($rule['value'] ?? '')),
                'use_option' => !empty($rule['use_option']),
            ];
        }

        return [
            'submit_label'      => \sanitize_text_field(\FabricatorForms\Utils\Cast::stringOrDefault($settings['submit_label']    ?? '', __('Submit', 'formfabricator'))),
            'submit_working'    => \sanitize_text_field(\FabricatorForms\Utils\Cast::stringOrDefault($settings['submit_working']   ?? '', __('Sending…', 'formfabricator'))),
            // Plain text: it is shown via .textContent.
            'success_message'   => \sanitize_textarea_field(\FabricatorForms\Utils\Cast::stringOrDefault($settings['success_message'] ?? '', __('Thank you!', 'formfabricator'))),
            // No 'enabled' flag: having rules switches this on.
            'submit_conditions' => [
                'match'   => in_array($settings['submit_conditions']['match'] ?? '', ['all', 'any'], true)
                    ? $settings['submit_conditions']['match'] : 'all',
                'rules'   => $rules,
            ],
        ];
    }
}
