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
 * @version   1.0.8
 * @link      https://github.com/AlexanderJorek/FormFabricator
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 3
 * of the License, or (at your option) any later version.
 */

namespace FabricatorForms\Form;

use FabricatorForms\Fields\FieldRegistry;
use FabricatorForms\Form\FormModel;
use FabricatorForms\Admin\FormSettings;
use FabricatorForms\PDF\Generator;

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
     * Masks an email address for debug logging, since error_log is a sink this plugin can't clean up on uninstall.
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
     * Masks every email address inside free text, such as a PHPMailer error ("Invalid address: (to): ...").
     *
     * @param string $text Text that may contain email addresses.
     * @return string
     */
    private static function maskEmailsIn(string $text): string
    {
        return (string) preg_replace_callback(
            self::EMAIL_IN_TEXT_RE,
            static fn(array $m): string => self::maskEmail($m[0]),
            $text
        );
    }

    /**
     * Replaces every email address inside free text with "[address]".
     *
     * @param string $text Text that may contain email addresses.
     * @return string
     */
    private static function redactEmailsIn(string $text): string
    {
        return (string) preg_replace(self::EMAIL_IN_TEXT_RE, '[address]', $text);
    }

    /**
     * An email address inside free text, such as a PHPMailer error.
     *
     * @var string
     */
    private const EMAIL_IN_TEXT_RE = '/[^\s<>()";:,]+@[^\s<>()";:,]+/';

    /**
     * Logs a delivery failure even without WP_DEBUG: it is the only trace of a lost notification. Callers pass no
     * email address.
     *
     * @param string $message Log line without the class prefix.
     * @return void
     */
    private static function logFailure(string $message): void
    {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- deliberate production log of delivery failures; addresses masked, no form data.
        error_log('FabricatorForms MailSender: ' . self::logSafe($message));
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
                // Addresses masked. Site-wide, so WP_DEBUG only; this plugin's failures are logged in onSubmission().
                \FabricatorForms\fabricator_log('FabricatorForms MailSender: wp_mail_failed — ' . self::maskEmailsIn($error->get_error_message()));
            }
        );
    }

    /**
     * Outcome of the last onSubmission() in this request: null before one runs, true when a notification it had to
     * deliver was not sent.
     *
     * @var bool|null
     */
    private static ?bool $delivery_failed = null;

    /**
     * Clears the recorded outcome; FormProcessor calls this right before firing fabricator_forms_submission.
     *
     * @return void
     */
    public static function resetDeliveryOutcome(): void
    {
        self::$delivery_failed = null;
    }

    /**
     * Whether the last onSubmission() failed to deliver: an email failed, no notification had a usable recipient, or
     * a needed PDF failed (decided before any email goes out).
     *
     * @return bool
     */
    public static function deliveryFailed(): bool
    {
        return self::$delivery_failed === true;
    }

    /**
     * Whether a notification list has at least one enabled notification, i.e. somewhere a submission is sent.
     *
     * @param mixed $notifications A form's notifications (FormModel::$notifications, or the builder's sanitized list).
     * @return bool
     */
    public static function hasEnabledNotification(mixed $notifications): bool
    {
        foreach ((array) $notifications as $notif) {
            if (is_array($notif) && !empty($notif['enabled'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * The names of the enabled notifications whose email carries no signature image although the form takes signatures
     * (a Signature field or a Direct Debit Mandate, also inside a group).
     *
     * A signature reaches the recipient only in the PDF (unless the layout hides it) or with "Attach uploaded files".
     *
     * @param int   $form_id       Form ID.
     * @param array $fields        The form's fields.
     * @param mixed $notifications The form's notifications.
     * @return string[]
     */
    public static function notificationsWithoutSignatures(int $form_id, array $fields, mixed $notifications): array
    {
        $takes_signatures = false;
        array_walk_recursive(
            $fields,
            static function ($leaf, $key) use (&$takes_signatures): void {
                if ($key === 'type' && in_array($leaf, ['signature', 'directdebit'], true)) {
                    $takes_signatures = true;
                }
            }
        );
        if (!$takes_signatures) {
            return [];
        }
        $layout      = (array) get_option('fabricator_forms_pdf_layout', []);
        // "Form fields" leaves every field out of the PDF, signatures and mandates included; "Signatures & Uploads" those.
        $pdf_hides   = array_intersect(['signatures', 'fields'], (array) ($layout['section_hidden'] ?? [])) !== [];
        $without     = [];
        foreach ((array) $notifications as $notif) {
            if (!is_array($notif) || empty($notif['enabled']) || !empty($notif['attach_uploads'])) {
                continue;
            }
            if ($pdf_hides || !self::notificationAttachesPdf($form_id, $notif)) {
                $without[] = (string) ($notif['name'] ?? '') !== '' ? (string) $notif['name'] : (string) ($notif['slug'] ?? '');
            }
        }
        return $without;
    }

    /**
     * Whether a submission of the form makes a PDF: some enabled notification attaches it.
     *
     * @param int   $form_id       Form ID.
     * @param mixed $notifications The form's notifications (FormModel::$notifications).
     * @return bool
     */
    public static function attachesPdf(int $form_id, mixed $notifications): bool
    {
        foreach ((array) $notifications as $notif) {
            if (is_array($notif) && !empty($notif['enabled']) && self::notificationAttachesPdf($form_id, $notif)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether one notification attaches the generated PDF, set on the notification or in the form's PDF settings.
     *
     * @param int   $form_id Form ID.
     * @param array $notif   The notification.
     * @return bool
     */
    private static function notificationAttachesPdf(int $form_id, array $notif): bool
    {
        return !empty($notif['attach_pdf'])
            || FormSettings::shouldAttachPdf($form_id, $notif['slug'] ?? '');
    }

    /**
     * Hooked into fabricator_forms_submission; resolves every notification, generates the PDF once if one needs it,
     * then sends one email per enabled notification.
     *
     * @param int       $form_id    The form post ID.
     * @param array     $mapped     Normalized field data from FieldRegistry::mapSubmission().
     * @param FormModel $form       The form model object.
     * @param array     $raw_values Flat field_id => submitted value map, for routing comparisons.
     */
    public static function onSubmission(int $form_id, array $mapped, FormModel $form, array $raw_values = []): void
    {
        self::$delivery_failed = false;

        \FabricatorForms\fabricator_log(
            "FabricatorForms MailSender: onSubmission fired for form {$form_id}, "
            . count($form->notifications ?? []) . ' notification(s) configured'
        );

        if (empty($form->notifications)) {
            \FabricatorForms\fabricator_log(
                "FabricatorForms MailSender: no notifications for form {$form_id}, aborting"
            );
            return;
        }

        $global_from_email = get_option('fabricator_forms_from_email')
            ?: get_option('admin_email');
        $global_from_name  = get_option('fabricator_forms_from_name')
            ?: get_bloginfo('name');

        /* ---- Pass 1: resolve every notification before anything is generated or sent ---- */
        $jobs       = [];
        $unroutable = 0;

        foreach ($form->notifications as $notif) {
            if (empty($notif['enabled'])) {
                \FabricatorForms\fabricator_log(
                    'FabricatorForms MailSender: notification '
                    . self::logSafe($notif['slug'] ?? '?') . ' disabled, skipping'
                );
                continue;
            }

            // Single mode uses resolveRecipientList() (like Cc/Bcc): a single-address resolver would drop a multi-recipient
            // "a@x, b@y" as unroutable.
            if (($notif['recipient_mode'] ?? 'single') === 'routing') {
                $routed  = self::resolveRoutedRecipients($notif, $mapped, $form, $raw_values);
                $to      = $routed['to'];
                $cc_raw  = $routed['cc'];
                $bcc_raw = $routed['bcc'];
            } else {
                $to = implode(', ', self::resolveRecipientList(
                    \FabricatorForms\Utils\Cast::stringOrDefault($notif['to'] ?? null),
                    $mapped,
                    $form
                ));
                $cc_raw  = \FabricatorForms\Utils\Cast::stringOrDefault($notif['cc'] ?? null);
                $bcc_raw = \FabricatorForms\Utils\Cast::stringOrDefault($notif['bcc'] ?? null);
            }
            if (empty($to)) {
                // Don't log $notif['to'] verbatim — in routing mode it's derived from a
                // submitted field value and could itself be personal data.
                \FabricatorForms\fabricator_log(
                    'FabricatorForms MailSender: notification ' . self::logSafe($notif['slug'] ?? '?')
                    . ' has no resolvable recipient, skipping'
                );
                $unroutable++;
                continue;
            }

            $should_attach         = self::notificationAttachesPdf($form_id, $notif);
            $should_attach_uploads = !empty($notif['attach_uploads']);

            // as_html=false: the subject is a header, not markup.
            $subject = self::replacePlaceholders(
                \FabricatorForms\Utils\Cast::stringOrDefault($notif['subject'] ?? null, __('New Submission', 'formfabricator')),
                $mapped,
                $form,
                false
            );
            $body    = self::buildEmailBody(
                \FabricatorForms\Utils\Cast::stringOrDefault($notif['body'] ?? null),
                $mapped,
                $form
            );

            // Never a form field: see FormEditor::sanitizeFromEmail(). A stored or imported {field} is ignored here too, and
            // the site's sender applies.
            $notif_email = trim(\FabricatorForms\Utils\Cast::stringOrDefault($notif['from_email'] ?? null));
            if ($notif_email === '{admin_email}') {
                $notif_email = (string) get_option('admin_email');
            }
            // as_html=false: a display-name sink, not markup.
            $notif_name  = self::replacePlaceholders(
                \FabricatorForms\Utils\Cast::stringOrDefault($notif['from_name'] ?? null),
                $mapped,
                $form,
                false
            );
            $from_email = ('' !== $notif_email && is_email($notif_email))
                ? $notif_email
                : $global_from_email;
            $from_name  = '' !== $notif_name ? $notif_name : $global_from_name;

            /* Strip CR/LF (header injection) and <, >, , from from_name (mailbox smuggling into the From header). */
            $from_name = sanitize_text_field(str_replace(["\r", "\n", '<', '>', ','], '', $from_name));
            $from_email = str_replace(["\r", "\n"], '', sanitize_email($from_email));
            $subject = sanitize_text_field(str_replace(["\r", "\n"], '', $subject));

            $headers = [
                'Content-Type: text/html; charset=UTF-8',
                'From: ' . $from_name . ' <' . $from_email . '>',
            ];

            $reply_to = self::resolveRecipient(
                \FabricatorForms\Utils\Cast::stringOrDefault($notif['reply_to'] ?? null),
                $mapped,
                $form
            );
            if ($reply_to) {
                $headers[] = 'Reply-To: ' . str_replace(["\r", "\n"], '', $reply_to);
            }

            // Cc/Bcc come from the notification in single mode and from the MATCHED ROUTING RULE
            // in routing mode (see resolveRoutedRecipients); each entry validates like To.
            foreach (['Cc' => $cc_raw, 'Bcc' => $bcc_raw] as $header_name => $header_raw) {
                $resolved = self::resolveRecipientList($header_raw, $mapped, $form);
                if ($resolved !== []) {
                    $headers[] = $header_name . ': ' . implode(', ', $resolved);
                }
            }

            $jobs[] = [
                'slug'           => \FabricatorForms\Utils\Cast::stringOrDefault($notif['slug'] ?? null, '?'),
                'to'             => $to,
                'subject'        => $subject,
                'body'           => $body,
                'headers'        => $headers,
                'attach_pdf'     => $should_attach,
                'attach_uploads' => $should_attach_uploads,
            ];
        }

        if ($jobs === []) {
            // Enabled notifications that all failed to route deliver nothing; disabling every notification is a choice.
            if ($unroutable > 0) {
                self::logFailure("form {$form_id}: no enabled notification has a usable recipient; nothing sent");
                self::$delivery_failed = true;
            }
            return;
        }

        /* ---- Generate the PDF once, only when a notification attaches it ---- */
        // No memory reservation/limit raise here: FormProcessor::handle() already holds both for this request.
        $pdf_path = false;
        if (in_array(true, array_column($jobs, 'attach_pdf'), true)) {
            $pdf_path = Generator::generate($mapped, $form_id, $form->title);
            if ($pdf_path === false) {
                // Stop before any email: mailing the rest without the PDF would lose it, and a retry would duplicate them.
                self::logFailure("PDF generation failed for form {$form_id}; no email sent");
                self::$delivery_failed = true;
                return;
            }
            // Removal registered the moment the PDF exists; shutdown functions run even after a memory or time fatal.
            register_shutdown_function(
                static function () use ($pdf_path): void {
                    if (file_exists($pdf_path)) {
                        wp_delete_file($pdf_path);
                        if (file_exists($pdf_path)) {
                            \FabricatorForms\fabricator_log("FabricatorForms MailSender: failed to remove temp PDF {$pdf_path}");
                        }
                    }
                }
            );
        }

        /* ---- Materialize uploads for mail attachment (split by type); their directory removes itself on shutdown ---- */
        $uploads = self::materializeUploadAttachments($mapped);

        /* ---- Pass 2: send ---- */
        $sent_count = 0;
        foreach ($jobs as $job) {
            $attachments = [];
            if ($job['attach_pdf'] && $pdf_path && file_exists($pdf_path)) {
                $attachments[] = $pdf_path;
                /* Non-images can't be embedded in the PDF, so always attach them alongside it. */
                foreach ($uploads['others'] as $p) {
                    $attachments[] = $p;
                }
                /* Images are embedded in the PDF already; only attach as
                   separate files when the admin also enables attach_uploads. */
                if ($job['attach_uploads']) {
                    foreach ($uploads['images'] as $p) {
                        $attachments[] = $p;
                    }
                }
            } elseif ($job['attach_uploads']) {
                /* No PDF — attach everything once. */
                foreach (array_merge($uploads['images'], $uploads['others']) as $p) {
                    $attachments[] = $p;
                }
            }

            /* Plain text is derived from the HTML body at send time for clients that don't render HTML. */
            $body          = $job['body'];
            $altBodySetter = static function ($phpmailer) use ($body): void {
                $phpmailer->isHTML(true);
                $phpmailer->Body    = $body;
                $phpmailer->AltBody = self::htmlToPlainText($body);
            };
            add_action('phpmailer_init', $altBodySetter);

            // Catches this send's own failure reason; the site-wide wp_mail_failed logger in init() stays WP_DEBUG-only.
            $mail_error   = '';
            $errorCatcher = static function (\WP_Error $error) use (&$mail_error): void {
                $mail_error = $error->get_error_message();
            };
            add_action('wp_mail_failed', $errorCatcher);

            $sent = wp_mail(
                $job['to'],
                $job['subject'],
                $body,
                $job['headers'],
                $attachments
            );

            remove_action('wp_mail_failed', $errorCatcher);
            remove_action('phpmailer_init', $altBodySetter);

            if ($sent) {
                $sent_count++;
                \FabricatorForms\fabricator_log('FabricatorForms MailSender: wp_mail to ' . self::maskEmail($job['to']) . ' returned true');
            } else {
                self::logFailure(
                    'notification ' . $job['slug'] . " of form {$form_id} was not sent: "
                    . ($mail_error !== '' ? self::redactEmailsIn($mail_error) : 'wp_mail() returned false without a reason')
                );
                \FabricatorForms\fabricator_log('FabricatorForms MailSender: wp_mail to ' . self::maskEmail($job['to']) . ' returned false');
            }
        }

        // Any unsent notification fails the submission, or the site owner's copy could be lost while the visitor is
        // thanked. The retry may duplicate a sent copy; a duplicate is recoverable, a lost submission is not.
        if ($sent_count < count($jobs)) {
            self::$delivery_failed = true;
        }
    }

    /**
     * Removes a MailSender temp directory: its files, its one level of per-file subdirectories, then itself.
     * Public for Generator::cronSweepTmpDirs(), the backstop when a request dies before its shutdown cleanup.
     *
     * @param string $dir Directory created by materializeUploadAttachments().
     * @return void
     */
    public static function removeTempTree(string $dir): void
    {
        $dir = rtrim($dir, '/\\');
        if ($dir === '' || is_link($dir) || !is_dir($dir)) {
            return;
        }
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $entry) {
            if (is_dir($entry) && !is_link($entry)) {
                foreach (glob($entry . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
                    wp_delete_file($f);
                    if (file_exists($f)) {
                        \FabricatorForms\fabricator_log('FabricatorForms MailSender: failed to remove temp file ' . \FabricatorForms\fabricator_log_file(basename($f)));
                    }
                }
                self::removeDir($entry);
                continue;
            }
            wp_delete_file($entry);
            if (file_exists($entry)) {
                \FabricatorForms\fabricator_log('FabricatorForms MailSender: failed to remove temp file ' . \FabricatorForms\fabricator_log_file(basename($entry)));
            }
        }
        self::removeDir($dir);
    }

    /**
     * Removes an emptied directory, logging instead of warning when it can't.
     *
     * @param string $dir Directory to remove.
     * @return void
     */
    private static function removeDir(string $dir): void
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- shutdown/cron cleanup of a plugin-owned temp dir; no WP_Filesystem credentials available here.
        if (!\FabricatorForms\Utils\Cast::withoutWarnings(static fn() => rmdir($dir))) {
            \FabricatorForms\fabricator_log("FabricatorForms MailSender: failed to remove temp dir {$dir}");
        }
    }

    /**
     * Where per-request attachment folders are created.
     *
     * The system temp dir, unless get_temp_dir() fell back to a web-served folder inside the site; then the protected
     * PDF folder.
     *
     * @return string Directory path with a trailing slash.
     */
    public static function tempBaseDir(): string
    {
        $temp = trailingslashit(wp_normalize_path(get_temp_dir()));
        foreach ([ABSPATH, WP_CONTENT_DIR] as $site_dir) {
            if (str_starts_with($temp, trailingslashit(wp_normalize_path($site_dir)))) {
                $secure = wp_normalize_path(wp_upload_dir()['basedir']) . '/fabricator-secure-pdf';
                \FabricatorForms\Utils\SecureDir::harden($secure, [$secure, $secure . '/mail']);
                return $secure . '/mail/';
            }
        }
        return $temp;
    }

    /**
     * Creates a directory readable by the web server user only.
     *
     * @param string $dir Directory to create; its parent must exist.
     * @return bool True when the directory now exists.
     */
    private static function makePrivateDir(string $dir): bool
    {
        // mkdir() with 0700: wp_mkdir_p() copies the parent's mode (0777 for /tmp). Only this call's own success counts:
        // an existing directory in a shared temp dir may belong to another local user.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir,PHPCS_SecurityAudit.BadFunctions.FilesystemFunctions.WarnFilesystem -- front-end AJAX submission, no WP_Filesystem credentials; $dir is built from get_temp_dir() and random_bytes(), never request input.
        return (bool) \FabricatorForms\Utils\Cast::withoutWarnings(static fn() => mkdir($dir, 0700));
    }

    /**
     * Writes uploaded files to temp paths under their original filenames, so wp_mail() can attach them.
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

        // One subdirectory per file keeps original names without collisions. random_bytes(), not wp_generate_uuid4()
        // (mt_rand()), so the name can't be predicted and pre-created.
        $tmp_dir = self::tempBaseDir() . 'fabricator_' . bin2hex(random_bytes(16)) . DIRECTORY_SEPARATOR;
        if (!self::makePrivateDir($tmp_dir)) {
            \FabricatorForms\fabricator_log("FabricatorForms: could not create temp dir {$tmp_dir}");
            return $result;
        }
        $result['tmp_dir'] = $tmp_dir;
        // Removal registered before the first file is written.
        register_shutdown_function([self::class, 'removeTempTree'], $tmp_dir);

        $file_no = 0;
        foreach ($mapped as $field) {
            foreach ($field['materialized_files'] ?? [] as $file) {
                $b64    = $file['base64'] ?? '';
                // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decodes binary handed over the array boundary described at the encode site (strict mode). Not obfuscation.
                $binary = $b64 !== '' ? base64_decode($b64, true) : false;
                if ($binary === false) {
                    continue;
                }
                $mime = $file['mime'] ?? '';
                $name = sanitize_file_name($file['name'] ?? 'upload');
                if ($name === '') {
                    $name = 'upload';
                }
                $file_dir = $tmp_dir . 'f' . (++$file_no) . DIRECTORY_SEPARATOR;
                if (!self::makePrivateDir($file_dir)) {
                    \FabricatorForms\fabricator_log("FabricatorForms: could not create temp dir {$file_dir}");
                    continue;
                }
                $dest = $file_dir . $name;
                // Same helper the directory hardening uses: WP_Filesystem where the host's
                // transport is 'direct', a direct write (justified once, in SecureDir) elsewhere.
                if (!\FabricatorForms\Utils\SecureDir::putFile($dest, $binary, 0600)) {
                    // The upload's own name, which may name a person, only as fabricator_log_file() records it.
                    \FabricatorForms\fabricator_log('FabricatorForms: failed to write temp file ' . \FabricatorForms\fabricator_log_file($name));
                    continue;
                }
                // Images the PDF shows are attached only where uploads are; everything else goes next to the PDF.
                if (\FabricatorForms\PDF\PdfUtils::embeddableImageMime($mime)) {
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
        // as_html=false: a recipient address, not markup. See replacePlaceholders().
        $to = self::replacePlaceholders($to, $mapped, $form, false);
        $to = sanitize_email(trim($to));
        return is_email($to) ? $to : '';
    }

    /**
     * Resolves a multi-address field (Cc/Bcc) into a list of validated addresses.
     *
     * @param string    $raw    Raw semicolon/comma-separated field value.
     * @param array     $mapped Mapped submission data.
     * @param FormModel $form   The form model instance.
     * @return string[] Validated addresses; empty when none resolve.
     */
    private static function resolveRecipientList(
        string $raw,
        array $mapped,
        FormModel $form
    ): array {
        if (trim($raw) === '') {
            return [];
        }
        $out = [];
        foreach (preg_split('/[;,]+/', $raw) ?: [] as $candidate) {
            $address = self::resolveRecipient(trim($candidate), $mapped, $form);
            if ($address !== '' && !in_array($address, $out, true)) {
                $out[] = $address;
            }
        }
        return $out;
    }

    /**
     * Resolves the recipient for a notification in "routing" mode. Walks routing_rules in order and returns
     * the email of the first matching rule, falling back to routing_fallback when none match.
     *
     * @param array     $notif      Notification config (routing_rules, routing_fallback).
     * @param array     $mapped     Mapped submission data.
     * @param FormModel $form       The form model instance (for option labels).
     * @param array     $raw_values Flat field_id => submitted value map.
     * @return array{to: string, cc: string, bcc: string} Resolved recipients; empty strings when none apply.
     */
    private static function resolveRoutedRecipients(
        array $notif,
        array $mapped,
        FormModel $form,
        array $raw_values = []
    ): array {
        $empty = ['to' => '', 'cc' => '', 'bcc' => ''];

        foreach ((array) ($notif['routing_rules'] ?? []) as $rule) {
            $field_id = $rule['field_id'] ?? '';
            /* Multi-address, like every other recipient field: a rule that routes to a whole team
               is the common case (see routing_fallback below and the To field in single mode). */
            $to = self::resolveRecipientList((string) ($rule['email'] ?? ''), $mapped, $form);
            if ($field_id === '' || $to === []) {
                continue;
            }
            $actual   = (string) ($mapped[$field_id]['value'] ?? '');
            $operator = $rule['operator'] ?? 'equals';
            /* The rule stores the option value ("yes"), $mapped its label ("Ja"). */
            $expected = self::resolveOptionLabel(
                $form,
                $field_id,
                (string) ($rule['value'] ?? '')
            );
            $raw = array_key_exists($field_id, $raw_values) ? $raw_values[$field_id] : null;
            if (self::ruleMatches($actual, $operator, $expected, $raw, (string) ($rule['value'] ?? ''))) {
                // Cc/Bcc travel with the matched rule, not the notification, so routing can send different groups to different copy lists.
                return [
                    'to'  => implode(', ', $to),
                    'cc'  => (string) ($rule['cc'] ?? ''),
                    'bcc' => (string) ($rule['bcc'] ?? ''),
                ];
            }
        }

        $fallback = self::resolveRecipientList(
            (string) ($notif['routing_fallback'] ?? ''),
            $mapped,
            $form
        );
        if ($fallback === []) {
            return $empty;
        }
        return [
            'to'  => implode(', ', $fallback),
            'cc'  => (string) ($notif['routing_fallback_cc'] ?? ''),
            'bcc' => (string) ($notif['routing_fallback_bcc'] ?? ''),
        ];
    }

    /**
     * Translates a field's raw option value to its display label, unchanged for non-choice fields or unknown options.
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
        // Children too: a group's choice fields are mapped by their own id, so a rule on one found nothing here and
        // then compared the stored option value against the displayed label.
        foreach ((array) ($form->fields ?? []) as $field_cfg) {
            $candidates = array_merge([$field_cfg], (array) ($field_cfg['children'] ?? []));
            foreach ($candidates as $candidate) {
                if (!is_array($candidate) || ($candidate['id'] ?? '') !== $field_id) {
                    continue;
                }
                foreach ((array) ($candidate['options'] ?? []) as $opt) {
                    if ((string) ($opt['value'] ?? '') === $raw) {
                        return (string) ($opt['label'] ?? $raw);
                    }
                }
                return $raw;
            }
        }
        return $raw;
    }

    /**
     * Evaluates a single routing-rule comparison against a submitted value.
     *
     * @param string $actual   The field's display value, as it appears in the email and PDF.
     * @param string $operator One of the supported comparison operators.
     * @param string $expected The rule's comparison value.
     * @param mixed  $raw      The value as submitted, or null when it isn't available.
     * @param string $option   The rule's value as stored (an option value, before its label replaced it in $expected).
     * @return bool Whether the rule matches.
     */
    private static function ruleMatches(
        string $actual,
        string $operator,
        string $expected,
        mixed $raw = null,
        string $option = ''
    ): bool {
        // A multi-value answer equals an option when it is among those chosen, as in FormProcessor::evalConditionRule().
        if (is_array($raw) && ($operator === 'equals' || $operator === 'not_equals')) {
            unset($raw['__other_text__']);
            $chosen = array_map(static fn($v) => is_scalar($v) ? mb_strtolower((string) $v) : '', $raw);
            $hit    = in_array(mb_strtolower($option !== '' ? $option : $expected), $chosen, true);
            return $operator === 'equals' ? $hit : !$hit;
        }
        switch ($operator) {
            case 'not_equals':
                return mb_strtolower($actual) !== mb_strtolower($expected);
            case 'contains':
                return $expected !== '' && mb_stripos($actual, $expected) !== false;
            case 'not_contains':
                // Empty value = half-configured routing rule: unsatisfied, or it would route every submission.
                return $expected !== '' && mb_stripos($actual, $expected) === false;
            case 'empty':
                return self::valueIsEmpty($actual, $raw);
            case 'not_empty':
                return !self::valueIsEmpty($actual, $raw);
            case 'greater':
                $number = self::numericValue($actual, $raw);
                return $number !== null && is_numeric($expected) && $number > (float) $expected;
            case 'less':
                $number = self::numericValue($actual, $raw);
                return $number !== null && is_numeric($expected) && $number < (float) $expected;
            case 'equals':
            default:
                return mb_strtolower($actual) === mb_strtolower($expected);
        }
    }

    /**
     * Whether a field was left blank, judged on the value as submitted.
     *
     * A visitor can type "[No entry]", so the display value decides only when the submitted one is unavailable.
     *
     * @param string $display The field's display value.
     * @param mixed  $raw     The value as submitted, or null when it isn't available.
     * @return bool
     */
    private static function valueIsEmpty(string $display, mixed $raw): bool
    {
        if ($raw === null) {
            return trim($display) === '';
        }
        if (is_array($raw)) {
            foreach ($raw as $item) {
                if (is_scalar($item) && trim((string) $item) !== '') {
                    return false;
                }
            }
            return true;
        }
        return trim((string) $raw) === '';
    }

    /**
     * The number behind a field value, for the greater/less operators.
     *
     * Prefers the value as submitted; a display value ("1.234,56 €") is read back through the site's number format.
     *
     * @param string $display The field's display value.
     * @param mixed  $raw     The value as submitted, or null when it isn't available.
     * @return float|null Null when neither holds a number.
     */
    private static function numericValue(string $display, mixed $raw): ?float
    {
        if (is_numeric($raw)) {
            return (float) $raw;
        }
        $display = trim($display);
        if (is_numeric($display)) {
            return (float) $display;
        }
        global $wp_locale;
        $decimal   = is_object($wp_locale) ? (string) ($wp_locale->number_format['decimal_point'] ?? '.') : '.';
        $thousands = is_object($wp_locale) ? (string) ($wp_locale->number_format['thousands_sep'] ?? ',') : ',';
        $plain     = str_replace([$thousands, ' ', "\xc2\xa0"], '', $display);
        if ($decimal !== '') {
            $plain = str_replace($decimal, '.', $plain);
        }
        return preg_match('/-?\d+(?:\.\d+)?/', $plain, $m) === 1 ? (float) $m[0] : null;
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
        FormModel $form,
        bool $as_html = true
    ): string {
        // Build the full token map then substitute via one strtr() pass, so a submitted value containing
        // literal "{token}" text can't get expanded by a later sequential str_replace() re-scan.
        $tokens = [
            '{admin_email}' => get_option('admin_email'),
            '{form_title}'  => $form->title,
            '{site_name}'   => get_bloginfo('name'),
        ];

        $needs_all_fields = str_contains($text, '{all_fields}');
        $inline           = get_option('fabricator_forms_field_layout', 'block') === 'inline';
        $all = '';
        // A field id matching a reserved token name (e.g. "admin_email") must not shadow the built-in token.
        $reserved_tokens = array_merge(array_keys($tokens), ['{all_fields}']);

        foreach ($mapped as $key => $entry) {
            $handler = FieldRegistry::get($entry['type'] ?? '');

            /* Per-field placeholder token. */
            $raw_val   = $entry['value'] ?? '';
            $plain_val = is_array($raw_val) ? implode(', ', $raw_val) : (string) $raw_val;

            // Both forms are always computed: {all_fields} below needs the matching pair for this context.
            if ($as_html) {
                // rawEmailHtml() fields already passed through wp_kses(), so inject as-is.
                $safe_val = ($handler && $handler->rawEmailHtml())
                    ? (is_array($raw_val) ? implode('', $raw_val) : (string)$raw_val)
                    : nl2br(esc_html($plain_val));
                $safe_lbl = esc_html((string)($entry['label'] ?? ''));
                $token    = $safe_lbl !== ''
                    ? ($inline
                        ? '<strong>' . $safe_lbl . ':</strong> ' . $safe_val . '<br>'
                        : '<strong>' . $safe_lbl . '</strong><br>' . $safe_val . '<br><br>')
                    : $safe_val;
            } else {
                // Plain-text sink (address, subject, From name).
                $safe_val = $plain_val;
                $safe_lbl = (string) ($entry['label'] ?? '');
                $token    = $plain_val;
            }
            $token_key = '{' . $key . '}';
            if (!in_array($token_key, $reserved_tokens, true) && !isset($tokens[$token_key])) {
                $tokens[$token_key] = $token;
            } else {
                // Reserved collision: keep whatever already claimed this key and alias this field's value instead.
                $alias_key = '{field_' . $key . '}';
                if (isset($tokens[$alias_key])) {
                    \FabricatorForms\fabricator_log(
                        "FabricatorForms MailSender: field id '{$key}' collides with the "
                        . 'placeholder alias namespace; its value was dropped.'
                    );
                } else {
                    $tokens[$alias_key] = $token;
                    \FabricatorForms\fabricator_log(
                        "FabricatorForms MailSender: field id '{$key}' collides with a reserved "
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
                    if ($as_html) {
                        $all .= $inline
                            ? '<strong>' . $safe_lbl . ':</strong> ' . $safe_val . '<br>'
                            : '<strong>' . $safe_lbl . '</strong><br>' . $safe_val . '<br><br>';
                    } else {
                        // Plain-text {all_fields} (e.g. in a subject line): no markup at all.
                        $all .= $safe_lbl . ': ' . $safe_val . "\n";
                    }
                } elseif ($handler && $handler->rawEmailHtml()) {
                    // Unlabeled HTML blocks still appear (e.g. banners), unlike other unlabeled fields.
                    $all .= $safe_val;
                }
            }
        }

        if ($needs_all_fields) {
            $tokens['{all_fields}'] = $all;
        }

        $filled = strtr($text, $tokens);
        return $as_html ? self::restrictLinkProtocols($filled) : $filled;
    }

    /**
     * Keeps every href/src in a finished HTML body to a harmless protocol.
     *
     * Placeholders are filled in after the body was sanitized, so an answer inside href="{field}" could bring its own
     * scheme; checked here on the finished body.
     *
     * @param string $html Email body with placeholders already filled in.
     * @return string
     */
    private static function restrictLinkProtocols(string $html): string
    {
        $checked = preg_replace_callback(
            '/\s(href|src)\s*=\s*(["\'])((?:(?!\2).)*)\2/is',
            static function (array $m): string {
                $value = html_entity_decode($m[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $safe  = wp_kses_bad_protocol($value, ['http', 'https', 'mailto', 'cid']);
                if (trim($safe) !== trim($value)) {
                    \FabricatorForms\fabricator_log(
                        'FabricatorForms MailSender: dropped a link in an email body whose protocol is not allowed.'
                    );
                    return ' ' . $m[1] . '=' . $m[2] . $m[2];
                }
                return $m[0];
            },
            $html
        );
        if ($checked !== null) {
            return $checked;
        }
        // PCRE gave up (backtrack/JIT limit), so no link was checked. Fail closed: no link or source survives, while the
        // text still goes out. This pattern cannot backtrack; should even it fail, the markup goes too.
        \FabricatorForms\fabricator_log('FabricatorForms MailSender: link check failed (' . preg_last_error_msg() . '); all links removed from this email.');
        $neutral = preg_replace('/\s(?:href|src)(?=\s*=)/i', ' data-removed', $html);
        return $neutral ?? nl2br(esc_html(wp_strip_all_tags($html)));
    }

    /**
     * Builds a complete HTML email body from a template, wrapping bare markup in a minimal document if needed.
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
