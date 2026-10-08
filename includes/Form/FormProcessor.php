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
 * @version   1.0.9
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
    // Passes after which condition rules that never settle stop; front.js (initConditions) stops after the same number.
    public const MAX_CONDITION_PASSES = 64;

    // Sends allowed per visitor address in 5 minutes: to one form, and to all of the site's forms together.
    private const SENDS_PER_FORM = 10;
    private const SENDS_PER_SITE = 50;
    // Sends allowed per IPv6 /48 in 5 minutes, across all forms: generous for one network, and a bound on its rows.
    private const SENDS_PER_PREFIX48 = 200;

    // The submit button's place in the hidden set of resolveVisibility(); no field id can contain a NUL byte.
    private const SUBMIT_KEY = "\0submit";

    // Set only by handle() once wp_verify_nonce() succeeds, so no field extracts input without a verified nonce.
    private static bool $nonceVerified = false;

    /**
     * Whether handle() has verified the current request's nonce (BaseField::assertRequestNonceVerified()).
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
        // Not the nonce, which visitors share. Issued and signed per submit by Plugin::ajaxGetToken().
        $submission_token = sanitize_text_field(wp_unslash($_POST['fabricator_submission_token'] ?? ''));
        if ($submission_token === '' || !\FabricatorForms\Utils\SingleUseToken::verifyIssued($submission_token, $form_id)) {
            wp_send_json_error(['message' => __('Security check failed.', 'formfabricator')], 403);
        }
        $claim_key = 'submit_' . md5($submission_token . '_' . $form_id);

        /* ---- Load form ---- */
        $form = FormModel::get($form_id);
        if (!$form) {
            wp_send_json_error(['message' => __('Form not found.', 'formfabricator')], 404);
        }

        /* ---- Somewhere to send it ---- */
        // Without an enabled notification the data would go nowhere while the visitor is told it was sent. A site that
        // handles fabricator_forms_submission itself can allow it.
        if (!MailSender::hasEnabledNotification($form->notifications)
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

        /* ---- Rate limit (per IP + form) to prevent replay/abuse ---- */
        // After the checks that write nothing, so every row written here is at least a real attempt.
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
        // The rows just written expire even where no scheduled sweep ever runs (no admin visits, WP-Cron off).
        \FabricatorForms\Plugin::sweepIfDue();

        /* ---- Pass 1: extract all values (no validation yet) ---- */
        // Two passes: Pass 2's conditional-visibility rules can reference any other field's value, including later ones.
        $raw = [];
        // What rules read, by field id (BaseField::conditionValue()), as front.js reads it. Group children are keyed
        // by their own ids.
        $flat = [];

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
                // A group's children render as top-level inputs, so they are read flat, as one copy; nothing posts
                // group[n][child] copies, and accepting them would multiply the validation work.
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
                    $flat[$child_id]      = $ch->conditionValue($flat_copy[$child_id], $child_cfg);
                }
                $raw[$field_id] = $flat_copy;
                continue;
            }

            $raw[$field_id]  = $handler->extractValue($field_id);
            $flat[$field_id] = $handler->conditionValue($raw[$field_id], $field_cfg);
        }

        /* ---- Visibility, decided as front.js decides it ---- */
        // From here on a hidden field reads as empty in $flat, so validation, the submit button and routing all see the
        // form the visitor saw.
        $submit_conditions = (array) ($form->settings['submit_conditions'] ?? []);
        [$hidden_set, $flat] = self::resolveVisibility($form->fields, $flat, $submit_conditions);

        // Bidi controls go before anything checks or prints the answers, as they can reorder typed text into a marker
        // (PdfUtils::stripBidiControls()). $flat keeps them, so the conditions agree with front.js.
        $raw = self::withoutBidiControls($raw);

        /* ---- Submit-button conditions ---- */
        // The button is only hidden in the browser, which stops nobody from posting, so the same rules decide here.
        if (isset($hidden_set[self::SUBMIT_KEY])) {
            wp_send_json_error(
                ['message' => __('This form cannot be submitted with the answers given.', 'formfabricator')],
                422
            );
        }

        /* ---- Pass 2: validate visible fields only ---- */
        $errors = [];
        // Checks that reach outside this request (the single-use CAPTCHA token) run only once every other field is valid.
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
            if (isset($hidden_set[$field_id])) {
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

                    /* Skip child if hidden by its own conditions. */
                    if (isset($hidden_set[$child_id])) {
                        continue;
                    }

                    // Errors are keyed by the child's own id, which is what front.js looks up.
                    $val = $group_raw[$child_id] ?? '';
                    if (!empty($child_cfg['required']) && $val === '') {
                        // Not escaped: front.js shows it via .textContent.
                        // translators: %s: field label.
                        $errors[$child_id] = sprintf(__('%s is a required field.', 'formfabricator'), $child_cfg['label'] ?? $child_id);
                    } elseif (($reserved = self::reservedMarkerError($val)) !== null) {
                        $errors[$child_id] = $reserved;
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
            if (($reserved = self::reservedMarkerError($value)) !== null) {
                $errors[$field_id] = $reserved;
                continue;
            }
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

        // Memory is reserved before the token claim, so a rejected visitor can retry. Only uploads this submission
        // processes count: not a hidden field's, nor a $_FILES entry no field declares.
        unset($hidden_set[self::SUBMIT_KEY]);
        $hidden_ids   = array_map('strval', array_keys($hidden_set));
        $uploads      = self::uploadEntries($form->fields, $hidden_ids);
        // Text counts too: mPDF lays out every answer (see MemoryBudget::TEXT_FACTOR); a signature's data URI is image data.
        [$text_bytes, $image_bytes] = self::answerPayloadBytes(array_diff_key($raw, array_flip($hidden_ids)));
        $attaches_pdf = MailSender::attachesPdf($form_id, $form->notifications);
        // The form's own text in the PDF (HTML blocks), twice: laid out in its cell, and again inside the seal.
        $form_text_bytes = $attaches_pdf ? 2 * self::configTextBytes($form->fields, $hidden_ids) : 0;
        $mem_estimate    = \FabricatorForms\Utils\MemoryBudget::estimateBytes(
            self::uploadPayloadBytes($uploads) + $image_bytes,
            $text_bytes + $form_text_bytes
        );
        if ($mem_estimate > \FabricatorForms\Utils\MemoryBudget::budgetBytes()) {
            // Waiting can't help a submission larger than the whole budget, so say what can change instead: the
            // visitor's files, or, when the form's own text alone is too much, the form.
            $form_alone = \FabricatorForms\Utils\MemoryBudget::estimateBytes(0, $form_text_bytes) > \FabricatorForms\Utils\MemoryBudget::budgetBytes();
            \FabricatorForms\fabricator_log(
                sprintf(
                    'FabricatorForms FormProcessor: a submission of form %d needs %dMB, more than the whole %dMB budget; '
                    . 'refused as too large%s. If this host has headroom, raise it with FABRICATOR_MEMORY_BUDGET_MB in wp-config.php.',
                    $form_id,
                    (int) round($mem_estimate / 1048576),
                    (int) round(\FabricatorForms\Utils\MemoryBudget::budgetBytes() / 1048576),
                    $form_alone ? ' (the HTML blocks alone are too large; shorten them)' : ''
                )
            );
            wp_send_json_error(['message' => $form_alone ? self::formTooLargeMessage() : self::uploadsTooLargeMessage()], 413);
        }

        // Images are decoded only into a PDF. With one, an image it can't decode is refused, so the visitor can send a
        // smaller one.
        $images        = [];
        $largest_image = 0;
        if ($attaches_pdf) {
            $images      = self::uploadedImages($uploads);
            $size_errors = self::imageSizeErrors($images, \FabricatorForms\PDF\PdfUtils::imagePixelLimit(), $mem_estimate, self::uploadPayloadBytes($uploads));
            if ($size_errors !== []) {
                wp_send_json_error(['message' => __('Please correct the highlighted fields.', 'formfabricator'), 'errors' => $size_errors], 422);
            }
            $largest_image = $images === [] ? 0 : max(array_column($images, 'pixels'));
            $mem_estimate  = self::estimateWithImage($mem_estimate, max($largest_image, self::largestLayoutImage()));
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

        // Released on shutdown, so a fatal can't leak the reservation (its TTL is the backstop).
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
        // $hidden_ids passed in, not unset after, so a hidden upload is never read, unvalidated.
        $mapped     = FieldRegistry::mapSubmission($form->fields, $raw, $_FILES, $hidden_ids);

        /* ---- Claim the replay-protection token ---- */
        // Right before the side effects, so an earlier failure can retry.
        if (!\FabricatorForms\Utils\SingleUseToken::claim($claim_key, \FabricatorForms\Utils\SingleUseToken::CLAIM_TTL)) {
            wp_send_json_error(['message' => __('This submission has already been received.', 'formfabricator')], 409);
        }

        /* ---- Fire submission hook (PDF generation + mail happens here) ---- */
        MailSender::resetDeliveryOutcome();
        // $flat lets routing rules test the submitted value, not its display text ("[No entry]", "12,50 EUR").
        do_action('fabricator_forms_submission', $form_id, $mapped, $form, $flat);

        // Nothing is stored locally, so an undelivered submission is lost: say so, and release the claim for the retry.
        if (MailSender::deliveryFailed()) {
            \FabricatorForms\Utils\SingleUseToken::release($claim_key);
            wp_send_json_error(
                ['message' => __('Your submission could not be delivered. Please try again later.', 'formfabricator')],
                500
            );
        }

        /* ---- Respond ---- */
        // Not escaped: front.js shows it via .textContent.
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
            // Flat entries only, as UploadField reads them: a nested one is never processed, so it claims no budget.
            // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- reached only after wp_verify_nonce() succeeded at the top of handle(); only PHP-generated size and tmp-path metadata is read from these entries, and isFlatFilesEntry() only inspects their shape.
            if (isset($_FILES[$id]) && \FabricatorForms\Utils\Cast::isFlatFilesEntry($_FILES[$id])) {
                // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- see above; $_FILES is never slashed, and nothing here is output or stored.
                $entries[$id] = $_FILES[$id];
            }
        }
        return $entries;
    }

    /**
     * The bytes of submitted text and of image data URIs (signatures) in the extracted answers, as [text, image].
     *
     * Text counts too: mPDF's layout costs many times its size in memory (MemoryBudget::TEXT_FACTOR).
     *
     * @param array $raw Extracted values of the fields that are shown, by field id.
     * @return array{0: int, 1: int}
     */
    private static function answerPayloadBytes(array $raw): array
    {
        $text  = 0;
        $image = 0;
        array_walk_recursive(
            $raw,
            static function ($leaf, $key) use (&$text, &$image): void {
                // $_FILES shapes (an upload's name, tmp_name, type) are no answer text; the file itself counts as upload.
                if (!is_string($leaf) || in_array($key, ['tmp_name', 'type', 'error', 'size'], true)) {
                    return;
                }
                if (str_starts_with($leaf, 'data:')) {
                    $image += strlen($leaf);
                } else {
                    $text += strlen($leaf);
                }
            }
        );
        return [$text, $image];
    }

    /**
     * Sums the entries' sizes as PHP measured them, not as the client claims.
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
     * The uploaded images among the entries, with the pixel counts their headers declare (file size says nothing
     * about decoding cost).
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
     * The largest of the PDF layout's own images, in pixels; 0 for none. mPDF decodes one image at a time, so the
     * largest image of a PDF, uploaded or the layout's, is what decoding costs.
     *
     * @return int Pixels.
     */
    private static function largestLayoutImage(): int
    {
        $paths = \FabricatorForms\PDF\PdfUtils::layoutImagePaths((array) get_option('fabricator_forms_pdf_layout', []));
        return max([0, ...array_map([\FabricatorForms\PDF\PdfUtils::class, 'filePixels'], $paths)]);
    }

    /**
     * The memory a submission needs to decode an image of $pixels: the files plus the decoded image, and never less
     * than the memory limit under which the PDF step accepts that image.
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
     * A message for each upload field holding an image the submission can't take, naming the first such image.
     *
     * An image above $pixel_limit gets a megapixel message; any other refused image lacks room next to the other
     * files and gets a megabyte one. Rounding keeps the two numbers in each message from reading the same.
     *
     * @param array $images      From uploadedImages().
     * @param int   $pixel_limit From PdfUtils::imagePixelLimit().
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
            // Not escaped: front.js shows these via .textContent.
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
     * The refusal when the form's own text in the PDF is beyond the memory budget: nothing the visitor sends helps.
     *
     * @return string
     */
    private static function formTooLargeMessage(): string
    {
        return __('This form is too large for the server to create its PDF. Please let the site operator know.', 'formfabricator');
    }

    /**
     * Bytes of text the shown fields put into the PDF from their configuration (BaseField::configTextBytes()), group
     * children included; a hidden group hides its children.
     *
     * @param array    $fields     The form's field configurations.
     * @param string[] $hidden_ids Ids of the fields hidden by conditions.
     * @return int
     */
    private static function configTextBytes(array $fields, array $hidden_ids): int
    {
        $hidden    = array_flip($hidden_ids);
        $is_hidden = static fn(mixed $c): bool => !is_array($c) || (is_string($c['id'] ?? null) && isset($hidden[$c['id']]));
        $bytes     = 0;
        foreach ($fields as $cfg) {
            if ($is_hidden($cfg)) {
                continue;
            }
            foreach (array_merge([$cfg], (array) ($cfg['children'] ?? [])) as $one) {
                if ($is_hidden($one)) {
                    continue;
                }
                $handler = is_string($one['type'] ?? null) ? FieldRegistry::get($one['type']) : null;
                $bytes  += $handler ? $handler->configTextBytes($one) : 0;
            }
        }
        return $bytes;
    }

    /**
     * Answers a request that runs out of memory while handling uploads with uploadsTooLargeMessage() instead of
     * WordPress's critical-error page, and releases the claim so the visitor can retry with smaller files.
     *
     * @param string $claim_key Replay-protection claim of this submission.
     * @return void
     */
    private static function answerMemoryExhaustionAsTooLarge(string $claim_key): void
    {
        // WordPress's fatal-error handler answers through these filters, and its AJAX die handler prints the message as is.
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
                if (wp_is_fatal_error_handler_enabled()) {
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
     * The JSON answer for a memory-exhausted submission, or null for any other error. Releases the claim and raises the
     * limit a little for the allocations still needed.
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
     * $raw with every string in it, nested ones included, passed through PdfUtils::stripBidiControls().
     *
     * @param array<string, mixed> $raw What the fields' extractValue() returned, by field id.
     * @return array<string, mixed>
     */
    private static function withoutBidiControls(array $raw): array
    {
        array_walk_recursive(
            $raw,
            static function (&$leaf): void {
                if (is_string($leaf)) {
                    $leaf = \FabricatorForms\PDF\PdfUtils::stripBidiControls($leaf);
                }
            }
        );
        return $raw;
    }

    /**
     * The error for an answer, sub-values included, that holds text the PDF verifier reads as structure
     * (PdfUtils::reservedMarker()), or null.
     *
     * @param mixed $value What the field's extractValue() returned.
     * @return string|null
     */
    private static function reservedMarkerError(mixed $value): ?string
    {
        $strings = is_string($value) ? [$value] : [];
        if (is_array($value)) {
            array_walk_recursive(
                $value,
                static function ($leaf) use (&$strings): void {
                    if (is_string($leaf)) {
                        $strings[] = $leaf;
                    }
                }
            );
        }
        foreach ($strings as $text) {
            $marker = \FabricatorForms\PDF\PdfUtils::reservedMarker($text);
            if ($marker !== null) {
                // Not escaped: front.js shows it via .textContent.
                return sprintf(
                    /* translators: %s: the character sequence that is not allowed, e.g. "---BEGIN-SEAL---". */
                    __('This answer contains "%s", which the sealed PDF uses for itself. Please remove it.', 'formfabricator'),
                    $marker
                );
            }
        }
        return null;
    }

    /**
     * Checks and increments the per-address submission counters (per form, and across all forms) to slow down
     * scripted abuse.
     *
     * @param int $form_id The form being submitted.
     * @return int|null Seconds until the caller's window resets, or null when not limited.
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
        // Bucketed per IPv6 /64, so rotating addresses within it doesn't escape the limit, and counted per form and
        // across all forms. The /48 comes first, and once full nothing else is written: a /48 holds 65,536 /64s.
        $prefix48 = \FabricatorForms\Utils\ClientIp::prefix48($ip);
        if ($prefix48 !== null) {
            $key48 = 'submit_48_' . hash_hmac('sha256', $prefix48, wp_salt('auth'));
            if (\FabricatorForms\Utils\RateLimiter::increment($key48, 5 * MINUTE_IN_SECONDS) > self::SENDS_PER_PREFIX48) {
                return max(1, \FabricatorForms\Utils\RateLimiter::secondsUntilReset($key48));
            }
        }
        $bucket = \FabricatorForms\Utils\ClientIp::bucket($ip);
        $limits = [
            'submit_' . hash_hmac('sha256', $bucket . '_' . $form_id, wp_salt('auth')) => self::SENDS_PER_FORM,
            'submit_all_' . hash_hmac('sha256', $bucket, wp_salt('auth'))             => self::SENDS_PER_SITE,
        ];
        $retry = null;
        foreach ($limits as $key => $limit) {
            if (\FabricatorForms\Utils\RateLimiter::increment($key, 5 * MINUTE_IN_SECONDS) > $limit) {
                // secondsUntilReset() re-reads the row just written, so a just-reset window counts.
                $retry = max($retry ?? 1, \FabricatorForms\Utils\RateLimiter::secondsUntilReset($key));
            }
        }
        return $retry;
    }

    /**
     * Decides which fields are hidden exactly as front.js does, and returns the values the rules read with it.
     *
     * A rule reads a hidden field as empty (BaseField::hiddenConditionValue()), so hiding one field can change another.
     * Starting from "all visible", passes repeat until nothing changes, at most MAX_CONDITION_PASSES, as in front.js.
     *
     * @param array $fields            Form field configs.
     * @param array $flat              Flat map of field_id → submitted value.
     * @param array $submit_conditions The form's submit_conditions setting: shown when its rules match.
     * @return array{0: array<string, true>, 1: array} The hidden ids (SUBMIT_KEY for a hidden submit button), and
     *                                                 $flat with hidden fields read as empty.
     */
    private static function resolveVisibility(array $fields, array $flat, array $submit_conditions): array
    {
        $empty = [];
        foreach ($fields as $field_cfg) {
            foreach (array_merge([$field_cfg], (array) ($field_cfg['children'] ?? [])) as $cfg) {
                $handler = is_array($cfg) ? FieldRegistry::get((string) ($cfg['type'] ?? '')) : null;
                if ($handler && is_string($cfg['id'] ?? null)) {
                    $empty[$cfg['id']] = $handler->hiddenConditionValue();
                }
            }
        }
        $submit = empty($submit_conditions['rules']) ? null : ['conditions' => ['action' => 'show'] + $submit_conditions];

        $hidden = [];
        $view   = $flat;
        for ($pass = 0; $pass < self::MAX_CONDITION_PASSES; $pass++) {
            $next = array_fill_keys(self::collectHiddenIds($fields, $view), true);
            if ($submit !== null && self::isHiddenByConditions($submit, $view)) {
                $next[self::SUBMIT_KEY] = true;
            }
            $settled = count($next) === count($hidden) && array_diff_key($next, $hidden) === [];
            $hidden  = $next;
            if ($settled) {
                break;
            }
            $view = $flat;
            foreach (array_keys($hidden) as $id) {
                if (isset($empty[$id])) {
                    $view[$id] = $empty[$id];
                }
            }
        }

        return [$hidden, $view];
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
     * Lowercases as front.js's String.prototype.toLowerCase() does, on every supported PHP version.
     *
     * Before PHP 8.3, mb_strtolower() lowercases a word-final "Σ" to "σ" where JavaScript gives "ς".
     *
     * @param string $s        Text to lowercase.
     * @param bool   $emulated Apply the final-sigma rule by hand; tests set it to check the rule on PHP 8.3+.
     * @return string
     */
    private static function lowerLikeJs(string $s, bool $emulated = PHP_VERSION_ID < 80300): string
    {
        if ($emulated && str_contains($s, 'Σ')) {
            // Final_Sigma: after a letter (skipping case-ignorable marks such as the acute accent) and before none.
            $s = (string) preg_replace('/(?<=\p{L})([\p{Mn}\p{Me}\p{Lm}\p{Sk}\x{0027}\x{2019}]*)Σ(?![\p{Mn}\p{Me}]*\p{L})/u', '$1ς', $s);
        }
        return mb_strtolower($s);
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
        // Multibyte-aware, as front.js lowercases "Ä" too.
        $rv    = self::lowerLikeJs((string)($rule['value'] ?? ''));
        $val   = $flat[$fid] ?? '';
        // Strip the "Other" free-text key so it can't accidentally satisfy an equals/contains condition rule.
        if (is_array($val)) {
            unset($val['__other_text__']);
        }
        $isArr = is_array($val);
        // Coerces to strings first: a crafted nested-array POST (e.g. checkboxfield[0][0]=x) would otherwise TypeError strtolower().
        $scalars = $isArr ? array_map(static fn($v) => is_scalar($v) ? (string)$v : '', $val) : [];
        $str     = $isArr ? self::lowerLikeJs(implode(',', $scalars)) : self::lowerLikeJs((string)$val);
        $lower   = $isArr ? array_map(static fn(string $v): string => self::lowerLikeJs($v), $scalars) : [];

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
            // Never for a list, as in front.js: a ticked checkbox worth "10" is no number.
            'greater'      => !$isArr && is_numeric($str) && is_numeric($rv) && (float)$str > (float)$rv,
            'less'         => !$isArr && is_numeric($str) && is_numeric($rv) && (float)$str < (float)$rv,
            // Fail safe on an unrecognized operator: treat as unsatisfied rather than silently hiding/showing.
            default        => false,
        };
    }
}
