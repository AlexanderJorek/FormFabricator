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
 * @version   1.1.1
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
use FabricatorForms\PDF\Generator;

defined('ABSPATH') || exit;

/**
 * Sends form submission email notifications with optional PDF attachments.
 */
class MailSender
{
    /**
     * The option holding each form's failures since its last delivered submission (recordFailure()).
     *
     * @var string
     */
    public const FAILURE_OPTION = 'fabricator_forms_mail_failures';

    /**
     * What can stop a submission's delivery: no usable recipient, the PDF, the uploads as attachments, or the send.
     *
     * @var string[]
     */
    public const FAILURE_REASONS = ['unroutable', 'pdf', 'uploads', 'send'];

    /**
     * How many forms' failures the record keeps, newest first.
     *
     * @var int
     */
    private const FAILURE_FORMS_KEPT = 20;

    /**
     * Starts an automation marker: "[FABRICATOR_FIELD_<id>]" before an answer, FIELD_MARKER_END after it, written into
     * the mail when the notification adds automation markers, so a mail parser can find each answer.
     *
     * @var string
     */
    public const FIELD_MARKER = '[FABRICATOR_FIELD_';

    /**
     * Ends the answer an automation marker started.
     *
     * @var string
     */
    public const FIELD_MARKER_END = '[FABRICATOR_FIELD_END]';

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
        // phpcs:ignore PHPCS_SecurityAudit.BadFunctions.CallbackFunctions.WarnCallbackFunctions -- a fixed callback (a closure or a function name), never input.
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
        // phpcs:ignore PHPCS_SecurityAudit.BadFunctions.CallbackFunctions.WarnCallbackFunctions -- a fixed callback (a closure or a function name), never input.
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
        // phpcs:ignore PHPCS_SecurityAudit.BadFunctions.PregReplace.PregReplaceDyn -- a pattern this class builds from constants; PHP 8 has no /e modifier.
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
     * a needed PDF or upload attachment failed (decided before any email goes out).
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
        // phpcs:ignore PHPCS_SecurityAudit.BadFunctions.CallbackFunctions.WarnCallbackFunctions -- a fixed callback (a closure or a function name), never input.
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
        if (!empty($notif['attach_pdf'])) {
            return true;
        }
        $saved = get_option('fabricator_forms_pdf_settings', []);
        return is_array($saved) && !empty($saved[$form_id . '|' . ($notif['slug'] ?? '')]);
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
            . count($form->notifications) . ' notification(s) configured'
        );

        if (empty($form->notifications)) {
            \FabricatorForms\fabricator_log(
                "FabricatorForms MailSender: no notifications for form {$form_id}, aborting"
            );
            return;
        }

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
                $form,
                !empty($notif['automation_markers'])
            );

            $subject = sanitize_text_field(str_replace(["\r", "\n"], '', $subject));

            $headers = [
                'Content-Type: text/html; charset=UTF-8',
                self::fromHeader($notif, $mapped, $form),
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
                self::recordFailure($form_id, 'unroutable');
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
                self::recordFailure($form_id, 'pdf');
                return;
            }
        }

        /* ---- Materialize uploads for mail attachment (split by type); their folder removes itself on shutdown ---- */
        // Only when a notification carries them: one with the PDF gets the files the PDF can't show, one with the
        // uploads gets all of them.
        $uploads = ['images' => [], 'others' => [], 'complete' => true];
        if (in_array(true, array_column($jobs, 'attach_pdf'), true) || in_array(true, array_column($jobs, 'attach_uploads'), true)) {
            $uploads = self::materializeUploadAttachments($mapped);
            if (!$uploads['complete']) {
                // Stop before any email, as for the PDF: nothing is stored locally, so mailing without the visitor's
                // files would lose them while the submission reads as sent. The visitor is asked to try again.
                self::logFailure("form {$form_id}: the uploads could not be prepared as attachments (uploads folder not writable, or disk full?); no email sent");
                self::$delivery_failed = true;
                self::recordFailure($form_id, 'uploads');
                return;
            }
        }

        /* ---- Pass 2: send ---- */
        $sent_count = 0;
        foreach ($jobs as $job) {
            $attachments = [];
            // phpcs:ignore PHPCS_SecurityAudit.BadFunctions.FilesystemFunctions.WarnFilesystem -- this request's PDF in the plugin's folder.
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
            self::recordFailure($form_id, 'send');
            return;
        }
        self::clearFailures($form_id);
    }

    /**
     * The From header of a notification: its own sender, else the plugin's, else the site's.
     *
     * @param array     $notif  The notification.
     * @param array     $mapped Mapped submission data, for placeholders in the sender name.
     * @param FormModel $form   The form.
     * @return string
     */
    private static function fromHeader(array $notif, array $mapped, FormModel $form): string
    {
        // Never a form field: see FormSanitizer::sanitizeFromEmail(). A stored or imported {field} is ignored here too, and
        // the site's sender applies.
        $notif_email = trim(\FabricatorForms\Utils\Cast::stringOrDefault($notif['from_email'] ?? null));
        if ($notif_email === '{admin_email}') {
            $notif_email = (string) get_option('admin_email');
        }
        // as_html=false: a display-name sink, not markup.
        $notif_name = self::replacePlaceholders(
            \FabricatorForms\Utils\Cast::stringOrDefault($notif['from_name'] ?? null),
            $mapped,
            $form,
            false
        );
        $from_email = ('' !== $notif_email && is_email($notif_email))
            ? $notif_email
            : (get_option('fabricator_forms_from_email') ?: get_option('admin_email'));
        $from_name  = '' !== $notif_name ? $notif_name : (get_option('fabricator_forms_from_name') ?: get_bloginfo('name'));

        /* Strip CR/LF (header injection) and <, >, , from from_name (mailbox smuggling into the From header). */
        $from_name  = sanitize_text_field(str_replace(["\r", "\n", '<', '>', ','], '', (string) $from_name));
        $from_email = str_replace(["\r", "\n"], '', sanitize_email((string) $from_email));
        return 'From: ' . $from_name . ' <' . $from_email . '>';
    }

    /**
     * Sends a test email with a notification's sender, so an admin finds out now, not from lost submissions, whether
     * this site's mail setup delivers it.
     *
     * @param array  $notif      The notification as the builder holds it (its sender settings are used).
     * @param string $to         The address to send to.
     * @param string $form_title The form's title, for {form_title} in the sender name.
     * @return string '' when wp_mail() accepted it, else why not.
     */
    public static function sendTest(array $notif, string $to, string $form_title): string
    {
        $form        = new FormModel();
        $form->title = $form_title;
        $site        = wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES);
        $subject     = sanitize_text_field(sprintf(
            /* translators: %s: site name. */
            __('Test email from %s', 'formfabricator'),
            $site
        ));
        $body = '<p>' . esc_html__('This is a test email from FormFabricator. It was sent with the sender this notification uses, so this notification\'s emails can reach this inbox too.', 'formfabricator') . '</p>';

        $mail_error   = '';
        $errorCatcher = static function (\WP_Error $error) use (&$mail_error): void {
            $mail_error = $error->get_error_message();
        };
        add_action('wp_mail_failed', $errorCatcher);
        try {
            $sent = wp_mail($to, $subject, $body, ['Content-Type: text/html; charset=UTF-8', self::fromHeader($notif, [], $form)]);
        } finally {
            remove_action('wp_mail_failed', $errorCatcher);
        }
        if ($sent) {
            return '';
        }
        return $mail_error !== '' ? $mail_error : __('The mail function reported a failure without a reason.', 'formfabricator');
    }

    /**
     * Records that a submission could not be delivered, for the admin notice (Notices::maybeWarnMailFailures()): when,
     * which form and what failed, never an address or an answer. Nothing is stored locally, so a mail setup that
     * keeps failing loses every submission, while the visitors' "try again" reaches no one who can fix it.
     *
     * Kept per form, so only that form's next delivered submission clears it: one form that delivers must not hide
     * another that keeps failing.
     *
     * @param int    $form_id The form.
     * @param string $reason  One of FAILURE_REASONS.
     * @return void
     */
    private static function recordFailure(int $form_id, string $reason): void
    {
        // Locked: two submissions failing at once, or one failing while another form's delivery clears its own entry,
        // must not undo each other's write.
        \FabricatorForms\Utils\Storage\OptionMutex::run(
            self::FAILURE_OPTION,
            static function () use ($form_id, $reason): void {
                $records = self::failureRecords();
                $now     = time();
                $entry   = [
                    'first'  => $records[$form_id]['first'] ?? $now,
                    'last'   => $now,
                    'count'  => ($records[$form_id]['count'] ?? 0) + 1,
                    'reason' => $reason,
                ];
                unset($records[$form_id]);
                // Newest first, so failures within the same second keep their order. Bounded: form IDs come from
                // submissions, and the notice needs only the most recent few.
                $records = [$form_id => $entry] + $records;
                update_option(self::FAILURE_OPTION, array_slice($records, 0, self::FAILURE_FORMS_KEPT, true), false);
            }
        );
    }

    /**
     * The failures since each form's last delivered submission, newest first.
     *
     * @return array<int, array{first: int, last: int, count: int, reason: string}> Keyed by form ID.
     */
    public static function failureRecords(): array
    {
        $stored  = get_option(self::FAILURE_OPTION);
        $records = [];
        foreach (is_array($stored) ? $stored : [] as $form_id => $record) {
            if ((int) $form_id <= 0 || !is_array($record) || !in_array($record['reason'] ?? null, self::FAILURE_REASONS, true)) {
                continue;
            }
            $records[(int) $form_id] = [
                'first'  => (int) ($record['first'] ?? 0),
                'last'   => (int) ($record['last'] ?? 0),
                'count'  => max(1, (int) ($record['count'] ?? 1)),
                'reason' => (string) $record['reason'],
            ];
        }
        // phpcs:ignore PHPCS_SecurityAudit.BadFunctions.CallbackFunctions.WarnCallbackFunctions -- a fixed callback (a closure or a function name), never input.
        uasort($records, static fn(array $a, array $b): int => $b['last'] <=> $a['last']);
        return $records;
    }

    /**
     * Forgets recorded failures: one form's, when a submission to it was delivered, or all, when the admin dismisses
     * the notice.
     *
     * @param int|null $form_id The form, or null for every form.
     * @return void
     */
    public static function clearFailures(?int $form_id = null): void
    {
        // Checked first: most submissions find nothing recorded, and taking the lock would cost queries each time.
        $records = self::failureRecords();
        if ($records === [] || ($form_id !== null && !isset($records[$form_id]))) {
            return;
        }
        if ($form_id === null) {
            delete_option(self::FAILURE_OPTION);
            return;
        }
        \FabricatorForms\Utils\Storage\OptionMutex::run(
            self::FAILURE_OPTION,
            static function () use ($form_id): void {
                $records = self::failureRecords();
                unset($records[$form_id]);
                if ($records === []) {
                    delete_option(self::FAILURE_OPTION);
                } else {
                    update_option(self::FAILURE_OPTION, $records, false);
                }
            }
        );
    }

    /**
     * Writes uploaded files to temp paths under their original filenames, so wp_mail() can attach them.
     *
     * @param array $mapped Normalized submission data.
     * @return array{images: string[], others: string[], complete: bool} Absolute paths of the written files, by kind;
     *                                                                 complete is false when any file could not be
     *                                                                 written.
     */
    private static function materializeUploadAttachments(array $mapped): array
    {
        $result  = ['images' => [], 'others' => [], 'complete' => true];

        // phpcs:ignore PHPCS_SecurityAudit.BadFunctions.CallbackFunctions.WarnCallbackFunctions -- a fixed callback (a closure or a function name), never input.
        $has_files = (bool) array_filter($mapped, static fn($f) => !empty($f['materialized_files']));
        if (!$has_files) {
            return $result;
        }

        // The submission's mail folder (PrivateDir), which removes itself at the end of the request.
        $tmp_dir = \FabricatorForms\Utils\Storage\PrivateDir::forSubmission('mail');
        if ($tmp_dir === '') {
            $result['complete'] = false;
            return $result;
        }

        foreach ($mapped as $field) {
            foreach ($field['materialized_files'] ?? [] as $file) {
                $b64    = $file['base64'] ?? '';
                // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decodes binary handed over the array boundary described at the encode site (strict mode). Not obfuscation.
                $binary = $b64 !== '' ? base64_decode($b64, true) : false;
                if ($binary === false) {
                    $result['complete'] = false;
                    continue;
                }
                $mime = $file['mime'] ?? '';
                $name = sanitize_file_name($file['name'] ?? 'upload');
                if ($name === '') {
                    $name = 'upload';
                }
                // One subfolder per file keeps the original names without collisions.
                $file_dir = $tmp_dir . '/f' . bin2hex(random_bytes(8)) . '/';
                if (!\FabricatorForms\Utils\Storage\PrivateDir::makeDir($file_dir)) {
                    \FabricatorForms\fabricator_log("FabricatorForms: could not create temp dir {$file_dir}");
                    $result['complete'] = false;
                    continue;
                }
                $dest = $file_dir . $name;
                // Same helper the directory hardening uses: WP_Filesystem where the host's
                // transport is 'direct', a direct write (justified once, in SecureDir) elsewhere.
                if (!\FabricatorForms\Utils\Storage\SecureDir::putFile($dest, $binary, 0600)) {
                    // The upload's own name, which may name a person, only as fabricator_log_file() records it.
                    \FabricatorForms\fabricator_log('FabricatorForms: failed to write temp file ' . \FabricatorForms\fabricator_log_file($name));
                    $result['complete'] = false;
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
            if (ConditionRule::routes($actual, $operator, $expected, $raw, (string) ($rule['value'] ?? ''))) {
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
        foreach ($form->fields as $field_cfg) {
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
     * Replaces template placeholders in a text string.
     *
     * @param string    $text    Template string with placeholders.
     * @param array     $mapped  Mapped submission data.
     * @param FormModel $form    The form model instance.
     * @param bool      $as_html Whether the text is markup (a body) or plain text (an address, a subject).
     * @param bool      $markers Whether each answer is wrapped in automation markers (FIELD_MARKER); markup only.
     * @return string Text with placeholders replaced.
     */
    private static function replacePlaceholders(
        string $text,
        array $mapped,
        FormModel $form,
        bool $as_html = true,
        bool $markers = false
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
        // Boxes {all_fields} has opened and not yet closed (BaseField::framePart()).
        $open_frames = 0;
        // A field id matching a reserved token name (e.g. "admin_email") must not shadow the built-in token.
        $reserved_tokens = array_merge(array_keys($tokens), ['{all_fields}']);

        foreach ($mapped as $key => $entry) {
            $handler = FieldRegistry::get($entry['type'] ?? '');

            /* Per-field placeholder token. */
            $raw_val   = $entry['value'] ?? '';
            $plain_val = is_array($raw_val) ? implode(', ', $raw_val) : (string) $raw_val;

            // "Hide label" (FieldRegistry::withHiddenLabel()): the answer is listed without its label, never left out.
            $shown_label = empty($entry['label_hidden']) ? (string) ($entry['label'] ?? '') : '';
            // A box's start or end (signature text, mandate): its label is the box's title, not an answer's label.
            $frame = $handler ? $handler->framePart($entry) : '';

            // Both forms are always computed: {all_fields} below needs the matching pair for this context.
            if ($as_html) {
                // rawEmailHtml() fields already passed through wp_kses(), so inject as-is.
                $is_raw     = $handler && $handler->rawEmailHtml();
                $value_html = $is_raw
                    ? (is_array($raw_val) ? implode('', $raw_val) : (string)$raw_val)
                    : nl2br(esc_html($plain_val));
                $label_text = $frame === '' ? $shown_label : '';
                // Spaced like any other answer: no outer paragraph margins, and one break less after a closing block.
                $ends_block  = false;
                $listed_html = $is_raw ? self::withoutOuterMargins($value_html, $ends_block) : $value_html;
                if ($markers) {
                    // An answer made of parts (a name's, an address's) marks each part on its line; any other is
                    // marked whole. The end marker is text after the block, so the next answer needs its line break.
                    $parts       = self::markerParts($entry);
                    $listed_html = $parts !== [] ? self::markedParts((string) $key, $parts) : self::marked((string) $key, $listed_html);
                    $value_html  = $parts !== [] ? $listed_html : self::marked((string) $key, $value_html);
                    $ends_block  = false;
                }
                $tail_html = $inline ? ($ends_block ? '' : '<br>') : ($ends_block ? '<br>' : '<br><br>');
                if ($frame === 'open') {
                    $title        = (string) ($entry['label'] ?? '');
                    $heading_html = $title !== '' ? '<strong>' . esc_html($title) . '</strong><br>' : '';
                    // Without a text (and unmarked), the heading alone, a line above the box's first answer; without
                    // either, nothing.
                    $entry_html = $listed_html !== ''
                        ? $heading_html . $listed_html . $tail_html
                        : ($heading_html !== '' && !$inline ? $heading_html . '<br>' : $heading_html);
                    $token      = $heading_html . $value_html;
                } elseif ($frame === 'close') {
                    // Nothing of its own to show: the end of the box is the box closing (marked, an empty answer).
                    $entry_html = $markers ? $listed_html . $tail_html : '';
                    $token      = $markers ? $value_html : '';
                } else {
                    $entry_html = $label_text !== ''
                        ? ($inline
                            ? '<strong>' . esc_html($label_text) . ':</strong> ' . $listed_html . $tail_html
                            : '<strong>' . esc_html($label_text) . '</strong><br>' . $listed_html . $tail_html)
                        : $listed_html . $tail_html;
                    $token      = $label_text !== '' ? $entry_html : $value_html;
                }
            } else {
                // Plain-text sink (address, subject, From name).
                $safe_val = $plain_val;
                $safe_lbl = $shown_label;
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
                if ($frame !== '' && $as_html) {
                    if ($frame === 'open') {
                        $all .= self::FRAME_OPEN . $entry_html;
                        $open_frames++;
                    } elseif ($open_frames > 0) {
                        $all = self::withoutTrailingBreaks($all . $entry_html) . self::FRAME_CLOSE;
                        $open_frames--;
                    }
                } elseif ($frame === 'close' || ($frame === 'open' && trim($plain_val) === '')) {
                    // Plain text has no box: its end names no answer, nor does a start without a text.
                    continue;
                } elseif ($label !== '') {
                    if ($as_html) {
                        $all .= $entry_html;
                    } else {
                        // Plain-text {all_fields} (e.g. in a subject line): no markup at all.
                        $all .= ($safe_lbl !== '' ? $safe_lbl . ': ' : '') . $safe_val . "\n";
                    }
                } elseif ($handler && $handler->rawEmailHtml()) {
                    // Unlabeled HTML blocks still appear (e.g. banners), unlike other unlabeled fields.
                    $all .= $as_html ? $entry_html : $safe_val;
                }
            }
        }

        if ($needs_all_fields) {
            // A box whose end isn't listed still closes, so the markup after {all_fields} stays outside it.
            if ($open_frames > 0) {
                $all = self::withoutTrailingBreaks($all) . str_repeat(self::FRAME_CLOSE, $open_frames);
            }
            $tokens['{all_fields}'] = $all;
        }

        $filled = strtr($text, $tokens);
        return $as_html ? self::restrictLinkProtocols($filled) : $filled;
    }

    /**
     * The start of a box around a group of answers in the mail: a one-cell table, since mail programs (Outlook among
     * them) draw a table's border where they drop a div's.
     *
     * @var string
     */
    private const FRAME_OPEN = '<table role="presentation" cellpadding="0" cellspacing="0" '
        . 'style="border:1px solid #c3c4c7;border-collapse:collapse;margin:0 0 14px;width:100%;">'
        . '<tr><td style="padding:10px 12px;">';

    /**
     * The end of a box FRAME_OPEN started.
     *
     * @var string
     */
    private const FRAME_CLOSE = '</td></tr></table>';

    /**
     * An answer between automation markers: FIELD_MARKER with the entry's key before it, FIELD_MARKER_END after it.
     *
     * @param string $key  The entry's key in the mapped submission.
     * @param string $html The answer as the mail shows it.
     * @return string
     */
    private static function marked(string $key, string $html): string
    {
        return self::FIELD_MARKER . esc_html($key) . ']' . $html . self::FIELD_MARKER_END;
    }

    /**
     * An entry's parts (BaseField::withMarkerParts()), each with a string key, value and separator; malformed ones are
     * left out.
     *
     * @param array $entry The entry.
     * @return array<int, array{key: string, value: string, sep: string}>
     */
    private static function markerParts(array $entry): array
    {
        $parts = [];
        foreach ((array) ($entry['parts'] ?? []) as $part) {
            if (is_array($part) && is_string($part['key'] ?? null) && is_string($part['value'] ?? null)) {
                $parts[] = ['key' => $part['key'], 'value' => $part['value'], 'sep' => is_string($part['sep'] ?? null) ? $part['sep'] : ' '];
            }
        }
        return $parts;
    }

    /**
     * An answer made of parts on one line: an empty "<key>_begin" pair, a "<key>_<part>" pair per part, an empty
     * "<key>_end" pair. No pair nests, so a parser reads each part as an answer of its own.
     *
     * @param string                                                  $key   The entry's key.
     * @param array<int, array{key: string, value: string, sep: string}> $parts The entry's parts.
     * @return string
     */
    private static function markedParts(string $key, array $parts): string
    {
        $line = self::marked($key . '_begin', '');
        foreach ($parts as $i => $part) {
            $line .= ($i > 0 ? esc_html($part['sep']) : '') . self::marked($key . '_' . $part['key'], esc_html($part['value']));
        }
        return $line . self::marked($key . '_end', '');
    }

    /**
     * $html without the line breaks at its end, so a box closes right after its last answer.
     *
     * @param string $html Mail markup.
     * @return string
     */
    private static function withoutTrailingBreaks(string $html): string
    {
        return (string) preg_replace('/(?:<br>)+$/', '', $html);
    }

    /**
     * Whether any of a form's enabled notifications adds automation markers, so answers may not contain one.
     *
     * @param FormModel $form The form.
     * @return bool
     */
    public static function marksAnswers(FormModel $form): bool
    {
        foreach ($form->notifications as $notif) {
            if (is_array($notif) && !empty($notif['enabled']) && !empty($notif['automation_markers'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Elements whose default margins put a blank line around them in a mail program.
     *
     * @var string[]
     */
    private const MARGIN_BLOCKS = [
        'P', 'DIV', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6', 'UL', 'OL', 'DL', 'TABLE', 'BLOCKQUOTE', 'PRE', 'FIGURE',
        'SECTION', 'ARTICLE', 'HEADER', 'FOOTER', 'ADDRESS', 'HR',
    ];

    /**
     * Elements without a closing tag, which open no level.
     *
     * @var string[]
     */
    private const VOID_ELEMENTS = [
        'AREA', 'BASE', 'BR', 'COL', 'EMBED', 'HR', 'IMG', 'INPUT', 'LINK', 'META', 'SOURCE', 'TRACK', 'WBR',
    ];

    /**
     * Zeroes the outer margins of the first and last top-level blocks, which mail programs pad with blank lines.
     * A margin the author set in the element's style still wins: it comes later in the attribute.
     *
     * @param string $html            Sanitized HTML.
     * @param bool   $ends_with_block Set to whether the HTML ends with a block, which ends its line itself.
     * @return string
     */
    private static function withoutOuterMargins(string $html, bool &$ends_with_block): string
    {
        $ends_with_block = false;
        $tags            = new \WP_HTML_Tag_Processor($html);
        $depth           = 0;
        $seen_content    = false;
        $last_is_block   = false;
        while ($tags->next_token()) {
            $type = $tags->get_token_type();
            if ($type === '#text') {
                if ($depth === 0 && trim($tags->get_modifiable_text()) !== '') {
                    $seen_content  = true;
                    $last_is_block = false;
                }
                continue;
            }
            if ($type !== '#tag') {
                continue;
            }
            $tag = (string) $tags->get_tag();
            if ($tags->is_tag_closer()) {
                $depth = max(0, $depth - 1);
                continue;
            }
            if ($depth === 0) {
                $is_block = in_array($tag, self::MARGIN_BLOCKS, true);
                if (!$seen_content && $is_block) {
                    $tags->set_attribute('style', 'margin-top:0;' . (string) $tags->get_attribute('style'));
                }
                $seen_content  = true;
                $last_is_block = $is_block;
                if ($is_block) {
                    $tags->set_bookmark('last-block');
                }
            }
            if (!in_array($tag, self::VOID_ELEMENTS, true)) {
                $depth++;
            }
        }
        if ($last_is_block && $tags->seek('last-block')) {
            $tags->set_attribute('style', 'margin-bottom:0;' . (string) $tags->get_attribute('style'));
            $ends_with_block = true;
        }
        return $tags->get_updated_html();
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
        // phpcs:ignore PHPCS_SecurityAudit.BadFunctions.CallbackFunctions.WarnCallbackFunctions -- a fixed callback (a closure or a function name), never input.
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
     * @param bool      $markers       Whether each answer is wrapped in automation markers (FIELD_MARKER).
     * @return string Complete HTML email body.
     */
    private static function buildEmailBody(
        string $body_template,
        array $mapped,
        FormModel $form,
        bool $markers = false
    ): string {
        $body_html = self::replacePlaceholders($body_template, $mapped, $form, true, $markers);

        if (stripos($body_html, '<html') !== false || stripos($body_html, '<body') !== false) {
            return $body_html;
        }

        return '<!DOCTYPE html><html>'
            . '<body style="font-family:Arial,sans-serif;font-size:14px;color:#333;">'
            . $body_html
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
