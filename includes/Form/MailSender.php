<?php

/**
 * Sends form submission emails with optional PDF attachment.
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

namespace ForgeForms\Form;

use ForgeForms\Fields\FieldRegistry;
use ForgeForms\Form\FormModel;
use ForgeForms\Admin\FormSettings;
use ForgeForms\PDF\Generator;
use ForgeForms\PDF\PdfUtils;

defined('ABSPATH') || exit;

/**
 * Sends form submission email notifications with optional PDF attachments.
 */
class MailSender
{
    /**
     * Strips newlines from a value before it's interpolated into a log line, preventing a crafted
     * slug/recipient from forging adjacent log entries.
     *
     * @param mixed $value Value to sanitize for logging.
     */
    private static function logSafe(mixed $value): string
    {
        return str_replace(["\r", "\n"], ' ', (string)$value);
    }

    /**
     * Masks an email address (or comma-separated list of them) for debug logging, keeping only enough to
     * distinguish log lines during troubleshooting without writing full recipient addresses — personal data
     * — into PHP's error_log, a sink this plugin doesn't control and can't clean up on uninstall.
     *
     * @param mixed $value Email address, or comma-separated list of addresses.
     */
    private static function maskEmail(mixed $value): string
    {
        $value = self::logSafe($value);
        if ($value === '') {
            return '(none)';
        }
        return implode(', ', array_map(
            static function (string $addr): string {
                $addr = trim($addr);
                $at   = strrpos($addr, '@');
                if ($at === false) {
                    return $addr === '' ? '' : substr($addr, 0, 1) . '***';
                }
                $local  = substr($addr, 0, $at);
                $domain = substr($addr, $at + 1);
                return substr($local, 0, 1) . '***@' . $domain;
            },
            explode(',', $value)
        ));
    }

    /**
     * Registers the wp_mail_failed logger once at class load time.
     *
     * @return void
     */
    public static function init(): void
    {
        add_action(
            'wp_mail_failed',
            static function (\WP_Error $error): void {
                \ForgeForms\forge_log(
                    'ForgeForms MailSender: wp_mail_failed — ' . $error->get_error_message()
                );
            }
        );
    }

    /**
     * Hooked into forge_forms_submission; generates and emails the submission PDF. Generates the PDF once and
     * sends one email per enabled notification, attaching the PDF to those with attach_pdf = true.
     *
     * @param int       $form_id The form post ID.
     * @param array     $mapped  Normalized field data from FieldRegistry::mapSubmission().
     * @param FormModel $form    The form model object.
     */
    public static function onSubmission(int $form_id, array $mapped, FormModel $form): void
    {
        \ForgeForms\forge_log(
            "ForgeForms MailSender: onSubmission fired for form {$form_id}, "
            . count($form->notifications ?? []) . ' notification(s) configured'
        );

        if (empty($form->notifications)) {
            \ForgeForms\forge_log(
                "ForgeForms MailSender: no notifications for form {$form_id}, aborting"
            );
            return;
        }

        /* ---- Generate PDF once ---- */
        $pdf_path = Generator::generate($mapped, $form_id, $form->title);

        /* ---- Materialize uploads for mail attachment (split by type) ---- */
        $uploads = self::materializeUploadAttachments($mapped);

        if ($pdf_path === false) {
            \ForgeForms\forge_log(
                "ForgeForms MailSender: PDF generation failed for form {$form_id}"
            );
        }

        /* PHPMailer assembles the full MIME message (base64'd attachments) in memory before send,
           so raise the ceiling for large multi-file sets — only ever up, never down. */
        $has_attachable_content = ($pdf_path !== false && $pdf_path !== '')
            || !empty($uploads['images'])
            || !empty($uploads['others']);
        if ($has_attachable_content) {
            $current_limit = PdfUtils::phpMemoryLimitBytes();
            if ($current_limit !== -1 && $current_limit < 3072 * 1024 * 1024) {
                // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- legitimate resource-limit raise, mirrors Verificationpage.php's pattern.
                @ini_set('memory_limit', '3072M');
            }
        }

        $global_from_email = get_option('forge_forms_from_email')
            ?: get_option('admin_email');
        $global_from_name  = get_option('forge_forms_from_name')
            ?: get_bloginfo('name');

        foreach ($form->notifications as $notif) {
            if (empty($notif['enabled'])) {
                \ForgeForms\forge_log(
                    'ForgeForms MailSender: notification '
                    . self::logSafe($notif['slug'] ?? '?') . ' disabled, skipping'
                );
                continue;
            }

            $to = ($notif['recipient_mode'] ?? 'single') === 'routing'
                ? self::resolveRoutedRecipient($notif, $mapped, $form)
                : self::resolveRecipient(\ForgeForms\Utils\Sanitize::str($notif['to'] ?? null), $mapped, $form);
            if (empty($to)) {
                // Don't log $notif['to'] verbatim — in routing mode it's derived from a
                // submitted field value and could itself be personal data.
                \ForgeForms\forge_log(
                    'ForgeForms MailSender: notification ' . self::logSafe($notif['slug'] ?? '?')
                    . ' has no resolvable recipient, skipping'
                );
                continue;
            }

            $should_attach         = !empty($notif['attach_pdf'])
                || FormSettings::shouldAttachPdf($form_id, $notif['slug'] ?? '');
            $should_attach_uploads = !empty($notif['attach_uploads']);

            $subject = self::replacePlaceholders(
                \ForgeForms\Utils\Sanitize::str($notif['subject'] ?? null, __('New Submission', 'formfabricator')),
                $mapped,
                $form
            );
            $body    = self::buildEmailBody(
                \ForgeForms\Utils\Sanitize::str($notif['body'] ?? null),
                $mapped,
                $form
            );

            $notif_email = self::replacePlaceholders(
                \ForgeForms\Utils\Sanitize::str($notif['from_email'] ?? null),
                $mapped,
                $form
            );
            $notif_name  = self::replacePlaceholders(
                \ForgeForms\Utils\Sanitize::str($notif['from_name'] ?? null),
                $mapped,
                $form
            );
            $from_email = ('' !== $notif_email && is_email($notif_email))
                ? $notif_email
                : $global_from_email;
            $from_name  = '' !== $notif_name ? $notif_name : $global_from_name;

            /* from_name/subject may contain user-submitted placeholder values;
               strip CR/LF so a submitter can't inject extra mail headers, then
               run through sanitize_text_field() for the same reason the
               recipient/reply-to paths do — it strips other control chars,
               not just \r\n, that some MTAs also treat as line separators.
               Also strip <, >, and , from from_name — otherwise a submitted
               value could smuggle a second mailbox into the single From header
               (e.g. "Name <a@a.com>, spoof@evil.com"). */
            $from_name = sanitize_text_field(str_replace(["\r", "\n", '<', '>', ','], '', $from_name));
            $from_email = str_replace(["\r", "\n"], '', sanitize_email($from_email));
            $subject = sanitize_text_field(str_replace(["\r", "\n"], '', $subject));

            $headers = [
                'Content-Type: text/html; charset=UTF-8',
                'From: ' . $from_name . ' <' . $from_email . '>',
            ];

            $reply_to = self::resolveRecipient(
                \ForgeForms\Utils\Sanitize::str($notif['reply_to'] ?? null),
                $mapped,
                $form
            );
            if ($reply_to) {
                $headers[] = 'Reply-To: ' . str_replace(["\r", "\n"], '', $reply_to);
            }

            $attachments = [];
            if ($should_attach && $pdf_path && file_exists($pdf_path)) {
                $attachments[] = $pdf_path;
                /* Non-images can't be embedded in the PDF visually — always
                   attach them alongside it so nothing is silently dropped. */
                foreach ($uploads['others'] as $p) {
                    $attachments[] = $p;
                }
                /* Images are embedded in the PDF already; only attach as
                   separate files when the admin also enables attach_uploads. */
                if ($should_attach_uploads) {
                    foreach ($uploads['images'] as $p) {
                        $attachments[] = $p;
                    }
                }
            } elseif ($should_attach_uploads) {
                /* No PDF — attach everything once. */
                foreach (array_merge($uploads['images'], $uploads['others']) as $p) {
                    $attachments[] = $p;
                }
            }

            /* Plain text is never authored or stored — it's derived from the
               HTML body at send time and attached as the multipart/alternative
               part for clients that don't render HTML. */
            $altBodySetter = static function ($phpmailer) use ($body): void {
                $phpmailer->isHTML(true);
                $phpmailer->Body    = $body;
                $phpmailer->AltBody = self::htmlToPlainText($body);
            };
            add_action('phpmailer_init', $altBodySetter);

            $sent = wp_mail(
                $to,
                $subject,
                $body,
                $headers,
                $attachments
            );

            remove_action('phpmailer_init', $altBodySetter);

            \ForgeForms\forge_log(
                'ForgeForms MailSender: wp_mail to ' . self::maskEmail($to) . ' returned '
                . ($sent ? 'true' : 'false')
            );
        }

        /* ---- Clean up PDF and upload temp dir after all emails sent ---- */
        register_shutdown_function(
            static function () use ($pdf_path, $uploads): void {
                if ($pdf_path && file_exists($pdf_path)) {
                    wp_delete_file($pdf_path);
                    if (file_exists($pdf_path)) {
                        \ForgeForms\forge_log("ForgeForms MailSender: failed to remove temp PDF {$pdf_path}");
                    }
                }
                $tmp_dir = $uploads['tmp_dir'] ?? '';
                if ($tmp_dir !== '' && is_dir($tmp_dir)) {
                    foreach (glob($tmp_dir . '*') ?: [] as $f) {
                        wp_delete_file($f);
                        if (file_exists($f)) {
                            \ForgeForms\forge_log("ForgeForms MailSender: failed to remove temp file {$f}");
                        }
                    }
                    // This shutdown-function cleanup runs after a front-end form submission (no WP admin
                    // context and no request left to display a credentials prompt), so WP_Filesystem()
                    // direct-mode initialization cannot be relied on here; $tmp_dir is a plugin-owned temp
                    // directory, not user-facing WP_Filesystem-managed content.
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- see comment above
                    if (!@rmdir($tmp_dir)) {
                        \ForgeForms\forge_log("ForgeForms MailSender: failed to remove temp dir {$tmp_dir}");
                    }
                }
            }
        );
    }

    /**
     * Writes non-image uploaded files to temp paths so wp_mail() can attach them. Each path preserves the
     * original filename so mail clients display it right. Caller is responsible for unlinking after send.
     *
     * @param array $mapped Normalized submission data.
     * @return array Absolute paths to materialized temp files.
     */
    private static function materializeUploadAttachments(array $mapped): array
    {
        $result  = ['images' => [], 'others' => [], 'tmp_dir' => ''];

        $has_files = (bool) array_filter($mapped, static fn($f) => !empty($f['materialized_files']));
        if (!$has_files) {
            return $result;
        }

        /* Use a per-request unique directory so original filenames are
           preserved for mail clients and concurrent requests can never
           collide on the same path (wp_unique_filename is not atomic). */
        $tmp_dir = get_temp_dir() . 'forge_' . wp_generate_uuid4() . DIRECTORY_SEPARATOR;
        // Harden against shared/world-listable system temp dirs, same as Generator.php's PDF temp dir.
        $prev_umask = umask(0077);
        try {
            if (!wp_mkdir_p($tmp_dir)) {
                \ForgeForms\forge_log("ForgeForms: could not create temp dir {$tmp_dir}");
                return $result;
            }
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- runs during front-end AJAX submission handling, no WP_Filesystem credentials available; mirrors Generator.php.
            chmod($tmp_dir, 0700);
        } finally {
            umask($prev_umask);
        }
        $result['tmp_dir'] = $tmp_dir;

        foreach ($mapped as $field) {
            foreach ($field['materialized_files'] ?? [] as $file) {
                $b64    = $file['base64'] ?? '';
                $binary = $b64 !== '' ? base64_decode($b64, true) : false;
                if ($binary === false) {
                    continue;
                }
                $mime = $file['mime'] ?? '';
                $name = sanitize_file_name($file['name'] ?? 'upload');
                $dest = $tmp_dir . uniqid('', true) . '_' . $name;
                if (file_put_contents($dest, $binary) === false) {
                    \ForgeForms\forge_log(
                        "ForgeForms: failed to write temp file {$dest}"
                    );
                    continue;
                }
                if (str_starts_with($mime, 'image/')) {
                    $result['images'][] = $dest;
                } else {
                    $result['others'][] = $dest;
                }
            }
        }

        return $result;
    }

    /**
     * Replaces placeholders in a recipient address and validates it as an email.
     *
     * @param string    $to     Recipient address or placeholder.
     * @param array     $mapped Mapped submission data.
     * @param FormModel $form   The form model instance.
     * @return string Resolved email address, or empty string when invalid.
     */
    private static function resolveRecipient(
        string $to,
        array $mapped,
        FormModel $form
    ): string {
        $to = self::replacePlaceholders($to, $mapped, $form);
        $to = sanitize_email(trim($to));
        return is_email($to) ? $to : '';
    }

    /**
     * Resolves the recipient for a notification in "routing" mode. Walks routing_rules in order and returns
     * the email of the first matching rule, falling back to routing_fallback when none match.
     *
     * @param array     $notif  Notification config (routing_rules, routing_fallback).
     * @param array     $mapped Mapped submission data.
     * @param FormModel $form   The form model instance (for option labels).
     * @return string Resolved email, or empty string when none apply.
     */
    private static function resolveRoutedRecipient(
        array $notif,
        array $mapped,
        FormModel $form
    ): string {
        foreach ((array) ($notif['routing_rules'] ?? []) as $rule) {
            $field_id = $rule['field_id'] ?? '';
            $email    = self::replacePlaceholders(
                (string) ($rule['email'] ?? ''),
                $mapped,
                $form
            );
            $email = sanitize_email(trim($email));
            if ($field_id === '' || !is_email($email)) {
                continue;
            }
            $actual   = (string) ($mapped[$field_id]['value'] ?? '');
            $operator = $rule['operator'] ?? 'equals';
            /* The rule stores the raw option value (e.g. "yes"), but $mapped
               holds the field handler's human-readable label (e.g. "Ja") —
               translate before comparing so choice-field rules can match. */
            $expected = self::resolveOptionLabel(
                $form,
                $field_id,
                (string) ($rule['value'] ?? '')
            );
            if (self::ruleMatches($actual, $operator, $expected)) {
                return $email;
            }
        }

        $fallback = self::replacePlaceholders(
            (string) ($notif['routing_fallback'] ?? ''),
            $mapped,
            $form
        );
        $fallback = sanitize_email(trim($fallback));
        return is_email($fallback) ? $fallback : '';
    }

    /**
     * Translates a field's raw option value to its display label. Mirrors how field handlers (e.g.
     * SelectField::map) render submitted values. Returns the input unchanged for non-choice fields or unknown
     * options.
     *
     * @param FormModel $form     The form model instance.
     * @param string    $field_id Field identifier to look up.
     * @param string    $raw      Raw option value from the routing rule.
     * @return string The option's label, or $raw unchanged.
     */
    private static function resolveOptionLabel(
        FormModel $form,
        string $field_id,
        string $raw
    ): string {
        foreach ((array) ($form->fields ?? []) as $field_cfg) {
            if (($field_cfg['id'] ?? '') !== $field_id) {
                continue;
            }
            foreach ((array) ($field_cfg['options'] ?? []) as $opt) {
                $opt_val = is_array($opt) ? ($opt['value'] ?? '') : $opt;
                if ((string) $opt_val === $raw) {
                    return is_array($opt)
                        ? (string) ($opt['label'] ?? $raw)
                        : (string) $opt;
                }
            }
            break;
        }
        return $raw;
    }

    /**
     * Evaluates a single routing-rule comparison against a submitted value.
     *
     * @param string $actual   The submitted field value.
     * @param string $operator One of the supported comparison operators.
     * @param string $expected The rule's comparison value.
     * @return bool Whether the rule matches.
     */
    private static function ruleMatches(
        string $actual,
        string $operator,
        string $expected
    ): bool {
        switch ($operator) {
            case 'not_equals':
                return mb_strtolower($actual) !== mb_strtolower($expected);
            case 'contains':
                return $expected !== '' && mb_stripos($actual, $expected) !== false;
            case 'not_contains':
                return $expected === '' || mb_stripos($actual, $expected) === false;
            case 'empty':
                return trim($actual) === '';
            case 'not_empty':
                return trim($actual) !== '';
            case 'greater':
                return is_numeric($actual) && is_numeric($expected)
                && (float) $actual > (float) $expected;
            case 'less':
                return is_numeric($actual) && is_numeric($expected)
                && (float) $actual < (float) $expected;
            case 'equals':
            default:
                return mb_strtolower($actual) === mb_strtolower($expected);
        }
    }

    /**
     * Replaces template placeholders in a text string.
     *
     * @param string    $text   Template string with placeholders.
     * @param array     $mapped Mapped submission data.
     * @param FormModel $form   The form model instance.
     * @return string Text with placeholders replaced.
     */
    private static function replacePlaceholders(
        string $text,
        array $mapped,
        FormModel $form
    ): string {
        // Build the full token => replacement map first, then substitute in a
        // single strtr() pass. Sequential str_replace() calls re-scan the
        // *entire* string after every replacement, so if a submitted field
        // value happens to contain literal text like "{all_fields}" or
        // "{other_field_id}", an earlier replacement could insert that text
        // and a later pass would then expand it — letting a submitter's own
        // free-text input trigger placeholders the template author never
        // referenced. strtr() replaces all tokens in one left-to-right scan
        // of the original string, so already-substituted content is never
        // rescanned.
        $tokens = [
            '{admin_email}' => get_option('admin_email'),
            '{form_title}'  => $form->title,
            '{site_name}'   => get_bloginfo('name'),
        ];

        $needs_all_fields = str_contains($text, '{all_fields}');
        $inline           = get_option('forge_forms_field_layout', 'block') === 'inline';
        $all = '';
        // Field IDs are admin-chosen when building the form — if one happens to
        // equal a reserved token name (e.g. a field literally called
        // "admin_email"), it must not silently shadow the built-in token for
        // every notification on that form.
        $reserved_tokens = array_merge(array_keys($tokens), ['{all_fields}']);

        foreach ($mapped as $key => $entry) {
            $handler = FieldRegistry::get($entry['type'] ?? '');

            /* Per-field placeholder token. */
            $raw_val = $entry['value'] ?? '';
            // rawEmailHtml() fields already passed through wp_kses(), so inject as-is.
            $safe_val = ($handler && $handler->rawEmailHtml())
                ? (is_array($raw_val) ? implode('', $raw_val) : (string)$raw_val)
                : nl2br(esc_html(is_array($raw_val) ? implode(', ', $raw_val) : (string)$raw_val));
            $safe_lbl = esc_html((string)($entry['label'] ?? ''));
            if ($safe_lbl !== '') {
                $token = $inline
                    ? '<strong>' . $safe_lbl . ':</strong> ' . $safe_val . '<br>'
                    : '<strong>' . $safe_lbl . '</strong><br>' . $safe_val . '<br><br>';
            } else {
                $token = $safe_val;
            }
            $token_key = '{' . $key . '}';
            if (!in_array($token_key, $reserved_tokens, true) && !isset($tokens[$token_key])) {
                $tokens[$token_key] = $token;
            } else {
                // Reserved collision (or a second field id colliding with the
                // alias namespace itself, e.g. a field literally named
                // "field_admin_email"): keep whatever already claimed this key
                // intact, and expose this field's value under its own alias
                // instead of silently overwriting/dropping it.
                $alias_key = '{field_' . $key . '}';
                if (isset($tokens[$alias_key])) {
                    \ForgeForms\forge_log(
                        "ForgeForms MailSender: field id '{$key}' collides with the "
                        . 'placeholder alias namespace; its value was dropped.'
                    );
                } else {
                    $tokens[$alias_key] = $token;
                    \ForgeForms\forge_log(
                        "ForgeForms MailSender: field id '{$key}' collides with a reserved "
                        . "placeholder name; use {field_{$key}} in templates to reference it."
                    );
                }
            }

            /* {all_fields} accumulation — reuse the same handler lookup. */
            if ($needs_all_fields) {
                if ($handler && !$handler->includeInEmailSummary()) {
                    continue;
                }
                $label = $entry['label'] ?? '';
                if ($label !== '') {
                    $all .= $inline
                        ? '<strong>' . $safe_lbl . ':</strong> ' . $safe_val . '<br>'
                        : '<strong>' . $safe_lbl . '</strong><br>' . $safe_val . '<br><br>';
                } elseif ($handler && $handler->rawEmailHtml()) {
                    // Unlabeled HTML blocks still appear (e.g. banners), unlike other unlabeled fields.
                    $all .= $safe_val;
                }
            }
        }

        if ($needs_all_fields) {
            $tokens['{all_fields}'] = $all;
        }

        return strtr($text, $tokens);
    }

    /**
     * Builds a complete HTML email body from a template. The notification body is always stored as HTML
     * (whether authored via the visual editor or the HTML source editor — they share one field), so the
     * only decision left here is structural: wrap bare markup in a minimal document if the admin didn't
     * already author a full one.
     *
     * @param string    $body_template Email body template string (HTML).
     * @param array     $mapped        Mapped submission data.
     * @param FormModel $form          The form model instance.
     * @return string Complete HTML email body.
     */
    private static function buildEmailBody(
        string $body_template,
        array $mapped,
        FormModel $form
    ): string {
        $body = self::replacePlaceholders($body_template, $mapped, $form);

        if (stripos($body, '<html') !== false || stripos($body, '<body') !== false) {
            return $body;
        }

        $style = 'font-family:Arial,sans-serif;font-size:14px;color:#333;';
        return '<!DOCTYPE html><html>'
            . '<body style="' . $style . '">'
            . $body
            . '</body></html>';
    }

    /**
     * Derives a readable plain-text version of an HTML email body for the multipart/alternative AltBody part.
     * Never stored — generated fresh for every send.
     *
     * @param string $html HTML email body.
     * @return string Plain text equivalent.
     */
    private static function htmlToPlainText(string $html): string
    {
        $text = preg_replace('/<br\s*\/?>/i', "\n", $html);
        $text = preg_replace('/<\/(p|div|tr|li|h[1-6])>/i', "\n", $text);
        $text = wp_strip_all_tags((string) $text);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $text = preg_replace("/\n{3,}/", "\n\n", $text);
        return trim((string) $text);
    }
}
