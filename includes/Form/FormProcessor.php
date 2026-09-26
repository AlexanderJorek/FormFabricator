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
 * @version   1.0.7
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
        // Not keyed on $nonce (shared across visitors per ~12h tick). Issued per submit by Plugin::ajaxGetToken() and
        // signed there, so a client can't mint its own and every unclaimed token expires with ISSUED_MAX_AGE.
        $submission_token = sanitize_text_field(wp_unslash($_POST['fabricator_submission_token'] ?? ''));
        if ($submission_token === '' || !\FabricatorForms\Utils\SingleUseToken::verifyIssued($submission_token, $form_id)) {
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

        /* ---- Somewhere to send it ---- */
        // With every notification missing or disabled, the data (consent, signature, IBAN) went nowhere while the visitor was
        // told it had been sent. A site that handles submissions itself on fabricator_forms_submission can allow it.
        if (!MailSender::hasEnabledNotification($form->notifications ?? [])
            && !apply_filters('fabricator_forms_accept_without_notifications', false, $form_id)
        ) {
            \FabricatorForms\fabricator_log('FabricatorForms FormProcessor: form ' . $form_id . ' has no enabled notification; submission refused.');
            wp_send_json_error(['message' => __('This form cannot be sent at the moment. Please try again later.', 'formfabricator')], 503);
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
                // A group renders its children as ordinary top-level inputs, so their values are read flat, as one copy.
                // Nothing in the renderer or front.js posts group[n][child] copies; accepting them let a crafted POST send
                // up to 100 copies per group, re-validating the same upload for each and unslashing every value twice.
                $flat_copy = [];
                foreach (($field_cfg['children'] ?? []) as $child_cfg) {
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
                $raw[$field_id] = $flat_copy;
                continue;
            }

            $raw[$field_id] = $handler->extractValue($field_id);
        }

        /* ---- Build flat value map for condition evaluation ---- */
        // A group's value is its children's values keyed by child id; they join the map as top-level entries, which is how
        // the renderer names them. Group ids are tracked so a field that legitimately returns an array isn't spread out.
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
                foreach ($val as $cid => $cv) {
                    $flat[$cid] = $cv;
                }
            } else {
                $flat[$fid] = $val;
            }
        }

        /* ---- Submit-button conditions ---- */
        // The button is only hidden in the browser, which stops nobody from posting, so the same rules decide here.
        $submit_conditions = (array) ($form->settings['submit_conditions'] ?? []);
        $submit_blocked    = !empty($submit_conditions['rules'])
            && self::isHiddenByConditions(['conditions' => ['action' => 'show'] + $submit_conditions], $flat);
        if ($submit_blocked) {
            wp_send_json_error(
                ['message' => __('This form cannot be submitted with the answers given.', 'formfabricator')],
                422
            );
        }

        /* ---- Pass 2: validate visible fields only ---- */
        $errors = [];
        // Checks that reach outside this request run only once every other field is correct: the CAPTCHA token is
        // single-use, and spending it on a submission that fails another field made every retry fail the CAPTCHA too.
        $deferred = [];

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

            /* A hidden field (or group, with all its children) is neither validated nor required-checked, whatever its type. */
            if (self::isHiddenByConditions($field_cfg, $flat)) {
                continue;
            }

            if ($handler->isGroupContainer()) {
                $group_raw = is_array($raw[$field_id] ?? null) ? $raw[$field_id] : [];
                foreach (($field_cfg['children'] ?? []) as $child_cfg) {
                    $child_id   = $child_cfg['id']   ?? '';
                    $child_type = $child_cfg['type'] ?? '';
                    if (!$child_id || !$child_type) {
                        continue;
                    }
                    $ch = FieldRegistry::get($child_type);
                    if (!$ch || $ch->skipValidation()) {
                        continue;
                    }

                    /* Skip child if hidden by its own conditions, subject to the same opt-out. */
                    if (self::isHiddenByConditions($child_cfg, $flat)) {
                        continue;
                    }

                    // Errors are keyed by the child's own id, which is what front.js looks up.
                    $val = $group_raw[$child_id] ?? '';
                    if (!empty($child_cfg['required']) && $val === '') {
                        // Not esc_html()'d: front.js only shows it via .textContent, so escaping here would double-encode into literal entities.
                        // translators: %s: field label.
                        $errors[$child_id] = sprintf(__('%s is a required field.', 'formfabricator'), $child_cfg['label'] ?? $child_id);
                    } else {
                        $child_cfg['field_id'] = $child_id;
                        if ($ch->defersValidation()) {
                            $deferred[$child_id] = [$ch, $val, $child_cfg];
                            continue;
                        }
                        $result = $ch->validate($val, $child_cfg);
                        if ($result !== true) {
                            $errors[$child_id] = $result;
                        }
                    }
                }
                continue;
            }

            $value             = $raw[$field_id] ?? '';
            $field_cfg['field_id'] = $field_id;
            if ($handler->defersValidation()) {
                $deferred[$field_id] = [$handler, $value, $field_cfg];
                continue;
            }
            $result = $handler->validate($value, $field_cfg);
            if ($result !== true) {
                $errors[$field_id] = $result;
            }
        }

        if (!empty($errors)) {
            wp_send_json_error(['message' => __('Please correct the highlighted fields.', 'formfabricator'), 'errors' => $errors], 422);
        }

        foreach ($deferred as $deferred_id => $deferred_entry) {
            [$deferred_handler, $deferred_value, $deferred_cfg] = $deferred_entry;
            $result = $deferred_handler->validate($deferred_value, $deferred_cfg);
            if ($result !== true) {
                $errors[$deferred_id] = $result;
            }
        }

        if (!empty($errors)) {
            wp_send_json_error(['message' => __('Please correct the highlighted fields.', 'formfabricator'), 'errors' => $errors], 422);
        }

        // Reserve memory sized to this upload's payload before the token claim, so a rejected visitor can retry instead of being locked out.
        // Only uploads this submission processes count: a hidden field's upload is skipped, and a $_FILES entry no field
        // declares is never read, so neither may claim the memory budget that other submissions share.
        $hidden_ids   = self::collectHiddenIds($form->fields, $flat);
        $uploads      = self::uploadEntries($form->fields, $hidden_ids);
        $mem_estimate = \FabricatorForms\Utils\MemoryBudget::estimateBytes(self::uploadPayloadBytes($uploads));
        if ($mem_estimate > \FabricatorForms\Utils\MemoryBudget::budgetBytes()) {
            // Waiting can't help a submission larger than the whole budget, so say what the visitor can change instead.
            \FabricatorForms\fabricator_log(
                sprintf(
                    'FabricatorForms FormProcessor: a submission of form %d needs %dMB, more than the whole %dMB budget; '
                    . 'refused as too large. If this host has headroom, raise it with FABRICATOR_MEMORY_BUDGET_MB in wp-config.php.',
                    $form_id,
                    (int) round($mem_estimate / 1048576),
                    (int) round(\FabricatorForms\Utils\MemoryBudget::budgetBytes() / 1048576)
                )
            );
            wp_send_json_error(['message' => self::uploadsTooLargeMessage()], 413);
        }

        // Images are decoded only into a PDF, so without one they cost no more than their files. With one, every image
        // has to go into it: one the PDF step can't decode is refused, so the visitor can send a smaller one.
        $images        = [];
        $largest_image = 0;
        if (MailSender::attachesPdf($form_id, $form->notifications ?? [])) {
            $images      = self::uploadedImages($uploads);
            $size_errors = self::imageSizeErrors($images, self::imagePixelLimit(), $mem_estimate, self::uploadPayloadBytes($uploads));
            if ($size_errors !== []) {
                wp_send_json_error(['message' => __('Please correct the highlighted fields.', 'formfabricator'), 'errors' => $size_errors], 422);
            }
            // mPDF decodes one image at a time and keeps only its compressed data, so the largest image is what it costs.
            $largest_image = $images === [] ? 0 : max(array_column($images, 'pixels'));
            $mem_estimate  = self::estimateWithImage($mem_estimate, $largest_image);
        }

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
        // estimateWithImage() already covers the limit under which the PDF step decodes the largest image.
        \FabricatorForms\Utils\MemoryBudget::raiseTo($mem_estimate);
        register_shutdown_function(
            static function () use ($mem_token): void {
                \FabricatorForms\Utils\MemoryBudget::releaseReservation($mem_token);
            }
        );
        if ($largest_image > \FabricatorForms\PDF\PdfUtils::maxSafePixels()) {
            // The host kept a lower memory limit than it let this request expect, so the PDF step would leave images out.
            wp_send_json_error(
                [
                    'message' => __('Please correct the highlighted fields.', 'formfabricator'),
                    'errors'  => self::imageSizeErrors($images, \FabricatorForms\PDF\PdfUtils::maxSafePixels(), 0, 0),
                ],
                422
            );
        }
        if ($uploads !== []) {
            self::answerMemoryExhaustionAsTooLarge($claim_key);
        }

        /* ---- Map to human-readable for PDF/email ---- */
        /* $hidden_ids, collected before the memory reservation, removes hidden field entries. */
        // Passed IN rather than unset after: a hidden upload was otherwise read/base64-encoded before validate() ever ran.
        $mapped     = FieldRegistry::mapSubmission($form->fields, $raw, $_FILES, $hidden_ids);

        /* ---- Claim the replay-protection token ---- */
        // Placed right before the side-effecting action so earlier validation failures can retry without touching the claim table.
        if (!\FabricatorForms\Utils\SingleUseToken::claim($claim_key, \FabricatorForms\Utils\SingleUseToken::CLAIM_TTL)) {
            wp_send_json_error(['message' => __('This submission has already been received.', 'formfabricator')], 409);
        }

        /* ---- Fire submission hook (PDF generation + mail happens here) ---- */
        MailSender::resetDeliveryOutcome();
        /* $flat travels along so routing rules can test the submitted value itself, not its display text: an empty
           field reads "[No entry]", which a visitor can also type, and an amount reads "12,50 EUR". */
        do_action('fabricator_forms_submission', $form_id, $mapped, $form, $flat);

        // Nothing is stored locally, so an undelivered submission is a lost one: say so instead of "Thank you", and
        // release the claim so the retry isn't rejected as a duplicate.
        if (MailSender::deliveryFailed()) {
            \FabricatorForms\Utils\SingleUseToken::release($claim_key);
            wp_send_json_error(
                ['message' => __('Your submission could not be delivered. Please try again later.', 'formfabricator')],
                500
            );
        }

        /* ---- Respond ---- */
        // Not esc_html()'d: front.js inserts this via .textContent only, and double-escaping showed literal HTML entities.
        $success_msg = $form->settings['success_message'] ?? __('Thank you for your submission!', 'formfabricator');
        wp_send_json_success(['message' => $success_msg]);
    }

    /**
     * The $_FILES entries of the upload fields this submission processes: declared by the form and not hidden.
     *
     * @param array    $fields     The form's field configs.
     * @param string[] $hidden_ids Ids hidden by their conditions, from collectHiddenIds() (a hidden group's children included).
     * @return array<string, array> $_FILES entries by field id.
     */
    private static function uploadEntries(array $fields, array $hidden_ids): array
    {
        $configs = [];
        foreach ($fields as $cfg) {
            if (!is_array($cfg)) {
                continue;
            }
            $configs[] = $cfg;
            foreach ((array) ($cfg['children'] ?? []) as $child) {
                if (is_array($child)) {
                    $configs[] = $child;
                }
            }
        }

        $hidden  = array_flip($hidden_ids);
        $entries = [];
        foreach ($configs as $cfg) {
            $id      = $cfg['id'] ?? '';
            $handler = is_string($cfg['type'] ?? null) ? FieldRegistry::get($cfg['type']) : null;
            if (!is_string($id) || $id === '' || isset($hidden[$id]) || !$handler || !$handler->needsMultipartEncoding()) {
                continue;
            }
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- reached only after wp_verify_nonce() succeeded at the top of handle(); only PHP-generated size and tmp-path metadata is read from these entries.
            if (isset($_FILES[$id]) && is_array($_FILES[$id])) {
                // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- see above; $_FILES is never slashed, and nothing here is output or stored.
                $entries[$id] = $_FILES[$id];
            }
        }
        return $entries;
    }

    /**
     * Sums the entries' sizes from $_FILES (immune to client understatement); signature/SEPA data URIs are excluded since post_max_size already bounds those.
     *
     * @param array<string, array> $entries From uploadEntries().
     * @return int Sum of uploaded file sizes in bytes.
     */
    private static function uploadPayloadBytes(array $entries): int
    {
        $total = 0;
        foreach ($entries as $file) {
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
     * The uploaded images among the entries, with the dimensions their headers declare; a few-KB PNG can declare
     * gigapixel dimensions, so the file size alone says nothing about the memory decoding takes.
     *
     * @param array<string, array> $entries From uploadEntries(); each tmp path is gated by is_uploaded_file() below.
     * @return array<int, array{field: string, name: string, bytes: int, pixels: int}>
     */
    private static function uploadedImages(array $entries): array
    {
        $images = [];
        foreach ($entries as $field_id => $file) {
            if (!is_array($file) || !isset($file['tmp_name'])) {
                continue;
            }
            $names = (array) ($file['name'] ?? []);
            $sizes = (array) ($file['size'] ?? []);
            foreach ((array) $file['tmp_name'] as $i => $tmp) {
                if (!is_string($tmp) || $tmp === '' || !is_uploaded_file($tmp)) {
                    continue;
                }
                $size = wp_getimagesize($tmp);
                if (!is_array($size) || empty($size[0]) || empty($size[1])) {
                    continue;
                }
                // Images the PDF can't read (TIFF) are never decoded; they are attached like documents.
                if (!\FabricatorForms\PDF\PdfUtils::embeddableImageMime(is_string($size['mime'] ?? null) ? $size['mime'] : '')) {
                    continue;
                }
                $images[] = [
                    'field'  => (string) $field_id,
                    'name'   => sanitize_file_name(is_string($names[$i] ?? null) ? $names[$i] : ''),
                    'bytes'  => max(0, (int) ($sizes[$i] ?? 0)),
                    'pixels' => (int) $size[0] * (int) $size[1],
                ];
            }
        }
        return $images;
    }

    /**
     * The memory a submission needs once the PDF step decodes an image of $pixels: the files plus the decoded image, and
     * never less than the memory limit under which the PDF step accepts that image (PdfUtils::maxSafePixels() keeps
     * half of the limit free). The request's memory limit is raised to exactly this, so it stays within its reservation.
     *
     * @param int $base   The estimate for the uploaded files alone.
     * @param int $pixels The largest image; 0 for none.
     * @return int Bytes.
     */
    private static function estimateWithImage(int $base, int $pixels): int
    {
        return max(
            $base + $pixels * \FabricatorForms\PDF\PdfUtils::DECODE_BYTES_PER_PIXEL,
            \FabricatorForms\PDF\PdfUtils::memoryLimitFor($pixels)
        );
    }

    /**
     * The largest image, in pixels, this server takes whatever else is uploaded: within the PDF step's own limit where
     * the host fixes the memory limit (elsewhere this request raises it), and needing no more memory than the budget
     * holds next to 1 MB of files. Up to it, an image is refused only for the size of the files beside it.
     *
     * @return int Pixels.
     */
    private static function imagePixelLimit(): int
    {
        $own    = \FabricatorForms\Utils\MemoryBudget::canRaiseLimit()
            ? \FabricatorForms\PDF\PdfUtils::safePixelsFor(-1)
            : \FabricatorForms\PDF\PdfUtils::maxSafePixels();
        $budget = \FabricatorForms\Utils\MemoryBudget::budgetBytes();
        $files  = \FabricatorForms\Utils\MemoryBudget::estimateBytes(1024 * 1024);
        return min(
            $own,
            intdiv($budget, \FabricatorForms\PDF\PdfUtils::memoryLimitFor(1)),
            intdiv(max(0, $budget - $files), \FabricatorForms\PDF\PdfUtils::DECODE_BYTES_PER_PIXEL)
        );
    }

    /**
     * A message for each upload field holding an image the submission can't take, naming the first such image.
     *
     * An image above $pixel_limit is too large in itself: the message compares megapixels, the image's rounded up and the
     * limit's down, so the two never read the same. Any other refused image only lacks room next to the other files:
     * the message compares megabytes, which is what a visitor can change, and the maximum shown (rounded down) is one at
     * which this image fits.
     *
     * @param array $images      From uploadedImages().
     * @param int   $pixel_limit From imagePixelLimit().
     * @param int   $base        The estimate for the uploaded files alone.
     * @param int   $total_bytes The uploaded files' total size.
     * @return array<string, string> Field id => message.
     */
    private static function imageSizeErrors(array $images, int $pixel_limit, int $base, int $total_bytes): array
    {
        $budget = \FabricatorForms\Utils\MemoryBudget::budgetBytes();
        $errors = [];
        foreach ($images as $image) {
            if (isset($errors[$image['field']])) {
                continue;
            }
            // Not esc_html()'d: front.js only shows these via .textContent, so escaping here would double-encode into literal entities.
            if ($image['pixels'] > $pixel_limit) {
                $errors[$image['field']] = sprintf(
                    // translators: 1: file name, 2: the image's megapixels, 3: its file size in MB, 4: largest accepted image in megapixels.
                    __('"%1$s" has %2$s megapixels (%3$s MB), but images can have at most %4$s megapixels. Please upload a smaller image.', 'formfabricator'),
                    $image['name'],
                    number_format_i18n(ceil($image['pixels'] / 100000) / 10, 1),
                    number_format_i18n(ceil($image['bytes'] / 104857.6) / 10, 1),
                    number_format_i18n(floor($pixel_limit / 100000) / 10, 1)
                );
            } elseif (self::estimateWithImage($base, $image['pixels']) > $budget) {
                $room = \FabricatorForms\Utils\MemoryBudget::largestUploadBytes(
                    $image['pixels'] * \FabricatorForms\PDF\PdfUtils::DECODE_BYTES_PER_PIXEL
                );
                $errors[$image['field']] = sprintf(
                    // translators: 1: image file name, 2: size of all uploaded files together in MB, 3: largest total in MB that fits with this image.
                    __('All files together are %2$s MB, but with "%1$s" they can be at most %3$s MB. Please upload smaller files.', 'formfabricator'),
                    $image['name'],
                    number_format_i18n(ceil($total_bytes / 104857.6) / 10, 1),
                    number_format_i18n(floor($room / 104857.6) / 10, 1)
                );
            }
        }
        return $errors;
    }

    /**
     * What a visitor reads when the submission's files need more memory than the server can give.
     *
     * @return string
     */
    private static function uploadsTooLargeMessage(): string
    {
        return __('The attached files are too large in total. Please attach fewer or smaller files.', 'formfabricator');
    }

    /**
     * Answers a request that runs out of memory while handling uploads with uploadsTooLargeMessage(), since the estimate
     * is only an estimate. Otherwise WordPress answers with its critical-error page, which the form can't show. The
     * submission's claim is released, so the visitor can send it again with smaller files.
     *
     * @param string $claim_key Replay-protection claim of this submission.
     * @return void
     */
    private static function answerMemoryExhaustionAsTooLarge(string $claim_key): void
    {
        // WordPress's fatal-error handler builds its answer through these two filters, which run only for a fatal
        // error; its AJAX die handler prints the message unchanged, so the form receives the JSON it reads.
        add_filter(
            'wp_php_error_message',
            static function ($message, $error) use ($claim_key) {
                $answer = self::memoryExhaustionAnswer($error, $claim_key);
                return $answer ?? $message;
            },
            10,
            2
        );
        add_filter(
            'wp_php_error_args',
            static fn($args, $error) => self::isMemoryExhaustion($error) ? array_merge((array) $args, ['response' => 413]) : $args,
            10,
            2
        );

        // The same answer where the site has switched that handler off.
        register_shutdown_function(
            static function () use ($claim_key): void {
                if (!function_exists('wp_is_fatal_error_handler_enabled') || wp_is_fatal_error_handler_enabled()) {
                    return;
                }
                $answer = self::memoryExhaustionAnswer(error_get_last(), $claim_key);
                if ($answer === null) {
                    return;
                }
                if (!headers_sent()) {
                    status_header(413);
                    header('Content-Type: application/json; charset=' . get_option('blog_charset'));
                }
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON from wp_json_encode(), read by front.js as data.
                echo $answer;
            }
        );
    }

    /**
     * The JSON answer for a memory-exhausted submission, or null for any other error. Releases the claim and leaves the
     * few allocations still needed some room, since the failed request keeps holding its memory.
     *
     * @param mixed  $error     error_get_last() shape.
     * @param string $claim_key Replay-protection claim of this submission.
     * @return string|null
     */
    private static function memoryExhaustionAnswer(mixed $error, string $claim_key): ?string
    {
        if (!self::isMemoryExhaustion($error)) {
            return null;
        }
        \FabricatorForms\Utils\MemoryBudget::raiseTo(\FabricatorForms\Utils\MemoryBudget::phpMemoryLimitBytes() + 32 * 1024 * 1024);
        \FabricatorForms\Utils\SingleUseToken::release($claim_key);
        \FabricatorForms\fabricator_log('FabricatorForms FormProcessor: ran out of memory while handling uploads: ' . (string) ($error['message'] ?? ''));
        return (string) wp_json_encode(['success' => false, 'data' => ['message' => self::uploadsTooLargeMessage()]]);
    }

    /**
     * Whether a PHP error is the memory limit, or the system, running out of memory.
     *
     * @param mixed $error error_get_last() shape.
     * @return bool
     */
    private static function isMemoryExhaustion(mixed $error): bool
    {
        $message = is_array($error) && is_string($error['message'] ?? null) ? $error['message'] : '';
        return str_starts_with($message, 'Allowed memory size of') || str_starts_with($message, 'Out of memory');
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
        // Bucketed (IPv6 /64) so rotating addresses inside one prefix neither escapes the limit nor mints a row per address.
        $key   = 'submit_' . hash_hmac('sha256', \FabricatorForms\Utils\ClientIp::bucket($ip) . '_' . $form_id, wp_salt('auth'));
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
     * @return array<string>
     */
    private static function collectHiddenIds(array $fields, array $flat): array
    {
        $hidden = [];

        foreach ($fields as $field_cfg) {
            $field_id = $field_cfg['id'] ?? '';
            if (!$field_id) {
                continue;
            }

            $group_hidden = self::isHiddenByConditions($field_cfg, $flat);

            if ($group_hidden) {
                $hidden[] = $field_id;
                foreach ($field_cfg['children'] ?? [] as $child) {
                    $cid = $child['id'] ?? '';
                    if (!$cid) {
                        continue;
                    }
                    $hidden[] = $cid;
                }
                continue;
            }

            /* Group visible — still check each child's own conditions. */
            foreach ($field_cfg['children'] ?? [] as $child) {
                $cid = $child['id'] ?? '';
                if (!$cid || !self::isHiddenByConditions($child, $flat)) {
                    continue;
                }
                $hidden[] = $cid;
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
        // mb_strtolower(), not strtolower(): the byte-wise one leaves "A"-with-umlaut alone while front.js lowercases it,
        // so a field the visitor could see counted as hidden here and was dropped from the email and the PDF.
        $rv    = mb_strtolower((string)($rule['value'] ?? ''));
        $val   = $flat[$fid] ?? '';
        // Strip the "Other" free-text key so it can't accidentally satisfy an equals/contains condition rule.
        if (is_array($val)) {
            unset($val['__other_text__']);
        }
        $isArr = is_array($val);
        // Coerces to strings first: a crafted nested-array POST (e.g. checkboxfield[0][0]=x) would otherwise TypeError strtolower().
        $scalars = $isArr ? array_map(static fn($v) => is_scalar($v) ? (string)$v : '', $val) : [];
        $str     = $isArr ? mb_strtolower(implode(',', $scalars)) : mb_strtolower((string)$val);
        $lower   = $isArr ? array_map('mb_strtolower', $scalars) : [];

        return match ($op) {
            'equals'       => $isArr ? in_array($rv, $lower, strict: true) : $str === $rv,
            'not_equals'   => $isArr ? !in_array($rv, $lower, strict: true) : $str !== $rv,
            'contains'     => $rv !== '' && ($isArr
                ? (bool) array_filter($lower, static fn($v) => str_contains($v, $rv))
                : str_contains($str, $rv)),
            // An empty value is a half-configured rule, not "contains nothing": unsatisfied, like 'contains' above.
            'not_contains' => $rv !== '' && ($isArr
                ? !array_filter($lower, static fn($v) => str_contains($v, $rv))
                : !str_contains($str, $rv)),
            'empty'        => $isArr ? empty($val) : $str === '',
            'not_empty'    => $isArr ? !empty($val) : $str !== '',
            // !$isArr, like front.js, which has no joined string to read a number out of: a single ticked checkbox
            // worth "10" counted as the number 10 here while the browser saw no number at all.
            'greater'      => !$isArr && is_numeric($str) && is_numeric($rv) && (float)$str > (float)$rv,
            'less'         => !$isArr && is_numeric($str) && is_numeric($rv) && (float)$str < (float)$rv,
            // Fail safe on an unrecognized operator: treat as unsatisfied rather than silently hiding/showing.
            default        => false,
        };
    }
}
