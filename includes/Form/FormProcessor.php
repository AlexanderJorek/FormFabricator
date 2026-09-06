<?php

/**
 * Handles form submission validation and processing.
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

namespace FabricatorForms\Form;

defined('ABSPATH') || exit;

use FabricatorForms\Fields\FieldRegistry;

/**
 * Handles fabricator_form AJAX submissions, validates fields, and fires the
 * submission action.
 */
class FormProcessor
{
    // Set only by handle() once wp_verify_nonce() succeeds; a structural guard against field extraction without a verified nonce.
    private static bool $nonceVerified = false;

    /**
     * Whether the current request's nonce has been verified by handle(). Read by
     * BaseField::assertRequestNonceVerified() before any field extracts $_POST/$_FILES data.
     *
     * @return bool
     */
    public static function nonceVerified(): bool
    {
        return self::$nonceVerified;
    }

    /**
     * AJAX handler for the fabricator_forms_submit action.
     *
     * Expects form_id, nonce, and field values in POST/FILES.
     *
     * @return void
     */
    public static function handle(): void
    {
        /* ---- Nonce ---- */
        $nonce   = sanitize_key(wp_unslash($_POST['fabricator_nonce'] ?? ''));
        $form_id = absint(wp_unslash($_POST['form_id'] ?? 0));

        if (!$form_id || !wp_verify_nonce($nonce, 'fabricator_forms_submit_' . $form_id)) {
            wp_send_json_error(['message' => __('Security check failed.', 'formfabricator')], 403);
        }
        self::$nonceVerified = true;

        /* ---- Replay-protection token ---- */
        // Not keyed on $nonce (shared across visitors per ~12h tick); fresh per render instead — see FormRenderer::render().
        $submission_token = sanitize_text_field(wp_unslash($_POST['fabricator_submission_token'] ?? ''));
        if ($submission_token === '') {
            wp_send_json_error(['message' => __('Security check failed.', 'formfabricator')], 403);
        }
        $claim_key = 'submit_' . md5($submission_token . '_' . $form_id);

        /* ---- Rate limit (per IP + form) to prevent replay/abuse ---- */
        $retry_after = self::rateLimitRetryAfter($form_id);
        if ($retry_after !== null) {
            $message = $retry_after > 60
                ? sprintf(
                    /* translators: %d: number of minutes until the visitor can submit again */
                    __('Too many submissions. Please try again in %d minutes.', 'formfabricator'),
                    (int) ceil($retry_after / 60)
                )
                : sprintf(
                    /* translators: %d: number of seconds until the visitor can submit again */
                    __('Too many submissions. Please try again in %d seconds.', 'formfabricator'),
                    max(1, $retry_after)
                );
            wp_send_json_error(['message' => $message, 'retry_after' => $retry_after], 429);
        }

        /* ---- Load form ---- */
        $form = FormModel::get($form_id);
        if (!$form) {
            wp_send_json_error(['message' => __('Form not found.', 'formfabricator')], 404);
        }

        /* ---- Honeypot check (before any expensive work) ---- */
        // Logged because an autofilling browser/password manager can silently drop a real visitor's submission here.
        if (!empty($_POST['fabricator_hp_field'])) {
            \FabricatorForms\fabricator_log(
                'FabricatorForms FormProcessor: honeypot triggered for form ' . $form_id
                . ' — submission discarded. If a real visitor reports a lost submission, an'
                . ' autofilling browser/password manager filling the hidden field is the likely cause.'
            );
            $hp_msg = $form->settings['success_message'] ?? __('Thank you for your submission!', 'formfabricator');
            wp_send_json_success(['message' => $hp_msg]);
        }

        /* ---- Pass 1: extract all values (no validation yet) ---- */
        // Two passes: Pass 2's conditional-visibility rules can reference any other field's value, including later ones.
        $raw = [];

        foreach ($form->fields as $field_cfg) {
            $field_id   = $field_cfg['id'] ?? '';
            $field_type = $field_cfg['type'] ?? '';

            if (!$field_id || !$field_type) {
                continue;
            }

            $handler = FieldRegistry::get($field_type);
            if (!$handler || $handler->skipValidation()) {
                continue;
            }

            if ($handler->isGroupContainer()) {
                $children   = $field_cfg['children'] ?? [];
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each leaf value is sanitized per-field below via extractFromRaw()/extractFromRawWithOther() before use.
                $group_post = isset($_POST[$field_id]) && is_array($_POST[$field_id]) ? wp_unslash($_POST[$field_id]) : [];
                $sanitized  = [];
                // Cap repeatable-group copies processed per field to prevent an unbounded amplification vector.
                $max_group_copies = 100;
                $processed_copies  = 0;

                if (!empty($group_post)) {
                    foreach ($group_post as $copy_idx => $copy_data) {
                        if ($processed_copies >= $max_group_copies) {
                            break;
                        }
                        $copy_idx = (int) $copy_idx;
                        if ($copy_idx < 1 || !is_array($copy_data)) {
                            continue;
                        }
                        $processed_copies++;
                        $sanitized[$copy_idx] = [];
                        foreach ($children as $child_cfg) {
                            $child_id   = $child_cfg['id']   ?? '';
                            $child_type = $child_cfg['type'] ?? '';
                            if (!$child_id || !$child_type) {
                                continue;
                            }
                            $ch = FieldRegistry::get($child_type);
                            if (!$ch) {
                                continue;
                            }
                            $sanitized[$copy_idx][$child_id] = $ch->extractFromRawWithOther(
                                $copy_data[$child_id] ?? '',
                                $copy_data[$child_id . '_other'] ?? null
                            );
                        }
                    }
                } else {
                    $flat_copy = [];
                    foreach ($children as $child_cfg) {
                        $child_id   = $child_cfg['id']   ?? '';
                        $child_type = $child_cfg['type'] ?? '';
                        if (!$child_id || !$child_type) {
                            continue;
                        }
                        $ch = FieldRegistry::get($child_type);
                        if (!$ch || $ch->skipValidation()) {
                            continue;
                        }
                        $flat_copy[$child_id] = $ch->extractValue($child_id);
                    }
                    if (!empty($flat_copy)) {
                        $sanitized[1] = $flat_copy;
                    }
                }

                $raw[$field_id] = $sanitized;
                continue;
            }

            $raw[$field_id] = $handler->extractValue($field_id);
        }

        /* ---- Build flat value map for condition evaluation ---- */
        // Track which field IDs are group containers, to distinguish a group's copy structure from a field that legitimately returns an array.
        $group_ids = [];
        foreach ($form->fields as $fc) {
            $h = FieldRegistry::get($fc['type'] ?? '');
            if ($h && $h->isGroupContainer()) {
                $group_ids[$fc['id'] ?? ''] = true;
            }
        }

        $flat = [];
        foreach ($raw as $fid => $val) {
            if (isset($group_ids[$fid]) && is_array($val)) {
                foreach ($val as $copy) {
                    if (is_array($copy)) {
                        foreach ($copy as $cid => $cv) {
                            $flat[$cid] = $cv;
                        }
                    }
                }
            } else {
                $flat[$fid] = $val;
            }
        }

        /* ---- Pass 2: validate visible fields only ---- */
        $errors = [];

        foreach ($form->fields as $field_cfg) {
            $field_id   = $field_cfg['id'] ?? '';
            $field_type = $field_cfg['type'] ?? '';

            if (!$field_id || !$field_type) {
                continue;
            }

            $handler = FieldRegistry::get($field_type);
            if (!$handler || $handler->skipValidation()) {
                continue;
            }

            /* Skip the entire group + all its children when the group is hidden. */
            if (self::isHiddenByConditions($field_cfg, $flat)) {
                continue;
            }

            if ($handler->isGroupContainer()) {
                $children  = $field_cfg['children'] ?? [];
                $group_raw = $raw[$field_id] ?? [];

                foreach ($group_raw as $copy_idx => $copy_data) {
                    if (!is_array($copy_data)) {
                        continue;
                    }
                    foreach ($children as $child_cfg) {
                        $child_id   = $child_cfg['id']   ?? '';
                        $child_type = $child_cfg['type'] ?? '';
                        if (!$child_id || !$child_type) {
                            continue;
                        }
                        $ch = FieldRegistry::get($child_type);
                        if (!$ch || $ch->skipValidation()) {
                            continue;
                        }

                        /* Skip child if hidden by its own conditions. */
                        if (self::isHiddenByConditions($child_cfg, $flat)) {
                            continue;
                        }

                        $val  = $copy_data[$child_id] ?? '';
                        // Bare child_id when only one copy exists — front.js's error-display lookup expects that shorter key.
                        $ekey = count($group_raw) > 1
                            ? $field_id . '[' . $copy_idx . '][' . $child_id . ']'
                            : $child_id;

                        if (!empty($child_cfg['required']) && $val === '') {
                            // Not esc_html()'d — matches BaseField::validate(), whose error message
                            // this mirrors: front.js only ever shows it via .textContent, so
                            // escaping here would double-encode it into visible literal entities.
                            // translators: %s: field label.
                            $errors[$ekey] = sprintf(__('%s is a required field.', 'formfabricator'), $child_cfg['label'] ?? $child_id);
                        } else {
                            $child_cfg['field_id'] = $child_id;
                            $result = $ch->validate($val, $child_cfg);
                            if ($result !== true) {
                                $errors[$ekey] = $result;
                            }
                        }
                    }
                }
                continue;
            }

            $value             = $raw[$field_id] ?? '';
            $field_cfg['field_id'] = $field_id;
            $result = $handler->validate($value, $field_cfg);
            if ($result !== true) {
                $errors[$field_id] = $result;
            }
        }

        if (!empty($errors)) {
            wp_send_json_error(['message' => __('Please correct the highlighted fields.', 'formfabricator'), 'errors' => $errors], 422);
        }

        /* ---- Reserve memory against the shared host budget ----
           Sized to this submission's real upload payload, and placed before the token claim below
           so a rejected visitor can retry with the same token instead of being locked out. */
        $mem_estimate = \FabricatorForms\Utils\MemoryBudget::estimateBytes(self::uploadPayloadBytes());
        $mem_token    = \FabricatorForms\Utils\MemoryBudget::reserve($mem_estimate, 300);
        if ($mem_token === false) {
            \FabricatorForms\fabricator_log(
                sprintf(
                    'FabricatorForms FormProcessor: memory budget unavailable for form %d '
                    . '(needed %dMB, budget %dMB, %dMB already reserved). If this host has headroom, '
                    . 'raise it with FABRICATOR_MEMORY_BUDGET_MB in wp-config.php.',
                    $form_id,
                    (int) round($mem_estimate / 1048576),
                    (int) round(\FabricatorForms\Utils\MemoryBudget::budgetBytes() / 1048576),
                    (int) round(\FabricatorForms\Utils\MemoryBudget::reservedBytes() / 1048576)
                )
            );
            wp_send_json_error(
                [
                    'message'     => __('The server is busy processing other submissions. Please try again in a moment.', 'formfabricator'),
                    'retry_after' => 15,
                ],
                429
            );
        }

        // Held for the rest of the request; released on shutdown so a fatal mid-render can't leak
        // the reservation (the row's own TTL is the backstop if even shutdown doesn't run).
        $mem_restore = \FabricatorForms\Utils\MemoryBudget::raiseTo($mem_estimate);
        register_shutdown_function(
            static function () use ($mem_token, $mem_restore): void {
                \FabricatorForms\Utils\MemoryBudget::releaseReservation($mem_token);
                \FabricatorForms\Utils\MemoryBudget::restore($mem_restore);
            }
        );

        /* ---- Map to human-readable for PDF/email ---- */
        /* $flat is already built; use it to remove hidden field entries. */
        $hidden_ids = self::collectHiddenIds($form->fields, $flat, $raw);
        // Passed IN rather than unset after: a hidden upload was otherwise read/base64-encoded before validate() ever ran.
        $mapped     = FieldRegistry::mapSubmission($form->fields, $raw, $_FILES, $hidden_ids);

        /* ---- Claim the replay-protection token ---- */
        // Placed right before the side-effecting action so earlier validation failures can retry without touching the claim table.
        if (!\FabricatorForms\Utils\SingleUseToken::claim($claim_key, 2 * DAY_IN_SECONDS)) {
            wp_send_json_error(['message' => __('This submission has already been received.', 'formfabricator')], 409);
        }

        /* ---- Fire submission hook (PDF generation + mail happens here) ---- */
        do_action('fabricator_forms_submission', $form_id, $mapped, $form);

        /* ---- Respond ---- */
        // Not esc_html()'d: front.js inserts this via .textContent only, and double-escaping showed literal HTML entities.
        $success_msg = $form->settings['success_message'] ?? __('Thank you for your submission!', 'formfabricator');
        wp_send_json_success(['message' => $success_msg]);
    }

    /**
     * Total bytes of uploaded files in this request, used to size the memory reservation.
     *
     * Uses PHP's own $_FILES['size'] (can't be understated by a crafted client); signature/SEPA
     * data URIs are excluded since post_max_size, not this reservation, already bounds those.
     *
     * @return int Sum of uploaded file sizes in bytes.
     */
    private static function uploadPayloadBytes(): int
    {
        $total = 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- reached only after wp_verify_nonce() succeeded at the top of handle(); reads PHP-generated size metadata, not user text.
        foreach ($_FILES as $file) {
            if (!is_array($file) || !isset($file['size'])) {
                continue;
            }
            if (is_array($file['size'])) {
                foreach ($file['size'] as $size) {
                    $total += max(0, (int) $size);
                }
                continue;
            }
            $total += max(0, (int) $file['size']);
        }
        return $total;
    }

    /**
     * Checks and increments a per-IP, per-form submission counter to slow down scripted abuse.
     *
     * @param int $form_id The form being submitted.
     * @return int|null Seconds until the caller's window resets, or null when the caller is within the
     *                  allowed rate (not limited).
     */
    private static function rateLimitRetryAfter(int $form_id): ?int
    {
        $ip = \FabricatorForms\Utils\ClientIp::resolve();
        if ($ip === '') {
            // Unknown client: fail closed rather than pooling requests into one shared rate-limit bucket.
            \FabricatorForms\fabricator_log(
                'FabricatorForms rateLimitRetryAfter: fail-closed — ClientIp::resolve() '
                . 'returned empty for form ' . $form_id . '. REMOTE_ADDR='
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- diagnostic log only (WP_DEBUG-gated), never echoed/stored.
                . (isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '(unset)')
            );
            return 5 * MINUTE_IN_SECONDS;
        }
        $key   = 'submit_' . hash_hmac('sha256', $ip . '_' . $form_id, wp_salt('auth'));
        $count = \FabricatorForms\Utils\RateLimiter::increment($key, 5 * MINUTE_IN_SECONDS);
        if ($count <= 10) {
            return null;
        }
        // secondsUntilReset() re-reads the row increment() just wrote, so a just-reset window is reflected correctly.
        return max(1, \FabricatorForms\Utils\RateLimiter::secondsUntilReset($key));
    }

    /**
     * Returns all field IDs (top-level and group children) that should be hidden based on the submitted values and the form's condition rules.
     *
     * @param array $fields Form field configs.
     * @param array $flat   Flat map of field_id → submitted value.
     * @param array $raw    Raw per-field submitted values (used for group copy counts).
     * @return array<string>
     */
    private static function collectHiddenIds(array $fields, array $flat, array $raw): array
    {
        $hidden = [];

        foreach ($fields as $field_cfg) {
            $field_id = $field_cfg['id'] ?? '';
            if (!$field_id) {
                continue;
            }

            $group_hidden = self::isHiddenByConditions($field_cfg, $flat);

            // Only real group-container fields use the copy-indexed shape; a Radio/Select "Other" value is also array-shaped but isn't copies.
            $field_handler = FieldRegistry::get($field_cfg['type'] ?? '');
            $is_group      = $field_handler && $field_handler->isGroupContainer();
            $copies        = ($is_group && is_array($raw[$field_id] ?? null)) ? $raw[$field_id] : [];
            $copy_count    = count($copies);

            if ($group_hidden) {
                $hidden[] = $field_id;
                foreach ($field_cfg['children'] ?? [] as $child) {
                    $cid = $child['id'] ?? '';
                    if (!$cid) {
                        continue;
                    }
                    if ($copy_count > 1) {
                        foreach (array_keys($copies) as $idx) {
                            $hidden[] = $cid . '_copy_' . $idx;
                        }
                    } else {
                        $hidden[] = $cid;
                    }
                }
                continue;
            }

            /* Group visible — still check each child's own conditions. */
            foreach ($field_cfg['children'] ?? [] as $child) {
                $cid = $child['id'] ?? '';
                if (!$cid || !self::isHiddenByConditions($child, $flat)) {
                    continue;
                }
                if ($copy_count > 1) {
                    foreach (array_keys($copies) as $idx) {
                        $hidden[] = $cid . '_copy_' . $idx;
                    }
                } else {
                    $hidden[] = $cid;
                }
            }
        }

        return $hidden;
    }

    /**
     * Evaluates a single field's condition config against the flat value map. Returns true when the field should be hidden.
     *
     * @param array $field_cfg Field or child config with optional 'conditions' key.
     * @param array $flat      Flat field_id → value map.
     */
    private static function isHiddenByConditions(array $field_cfg, array $flat): bool
    {
        $rules = $field_cfg['conditions']['rules'] ?? [];
        if (empty($rules)) {
            return false;
        }

        $action = $field_cfg['conditions']['action'] ?? 'show';
        $match  = $field_cfg['conditions']['match']  ?? 'all';

        $results = [];
        foreach ($rules as $r) {
            if (is_array($r)) {
                $results[] = self::evalConditionRule($r, $flat);
            }
        }

        $pass = $match === 'any'
            ? in_array(true, $results, strict: true)
            : !in_array(false, $results, strict: true);

        return $action === 'hide' ? $pass : !$pass;
    }

    /**
     * Evaluates one condition rule against the flat value map.
     *
     * @param array $rule Rule: field_id, operator, value.
     * @param array $flat Flat field_id → value map.
     */
    private static function evalConditionRule(array $rule, array $flat): bool
    {
        $fid   = $rule['field_id'] ?? '';
        $op    = $rule['operator'] ?? 'equals';
        $rv    = strtolower((string)($rule['value'] ?? ''));
        $val   = $flat[$fid] ?? '';
        // Strip the "Other" free-text key so it can't accidentally satisfy an equals/contains condition rule.
        if (is_array($val)) {
            unset($val['__other_text__']);
        }
        $isArr = is_array($val);
        // Coerces to strings first: a crafted nested-array POST (e.g. checkboxfield[0][0]=x) would otherwise TypeError strtolower().
        $scalars = $isArr ? array_map(static fn($v) => is_scalar($v) ? (string)$v : '', $val) : [];
        $str     = $isArr ? strtolower(implode(',', $scalars)) : strtolower((string)$val);
        $lower   = $isArr ? array_map('strtolower', $scalars) : [];

        return match ($op) {
            'equals'       => $isArr ? in_array($rv, $lower, strict: true) : $str === $rv,
            'not_equals'   => $isArr ? !in_array($rv, $lower, strict: true) : $str !== $rv,
            'contains'     => $rv !== '' && ($isArr
                ? (bool) array_filter($lower, static fn($v) => str_contains($v, $rv))
                : str_contains($str, $rv)),
            'not_contains' => $rv === '' || ($isArr
                ? !array_filter($lower, static fn($v) => str_contains($v, $rv))
                : !str_contains($str, $rv)),
            'empty'        => $isArr ? empty($val) : $str === '',
            'not_empty'    => $isArr ? !empty($val) : $str !== '',
            'greater'      => is_numeric($str) && is_numeric($rv) && (float)$str > (float)$rv,
            'less'         => is_numeric($str) && is_numeric($rv) && (float)$str < (float)$rv,
            // Fail safe on an unrecognized operator: treat as unsatisfied rather than silently hiding/showing.
            default        => false,
        };
    }
}
