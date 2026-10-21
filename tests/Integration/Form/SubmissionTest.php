<?php

namespace FabricatorForms\Tests\Integration\Form;

use FabricatorForms\Tests\Integration\AjaxTestCase;
use FabricatorForms\Utils\SingleUseToken;
use PHPUnit\Framework\Attributes\Group;

/**
 * A submission end to end — nonce, one-time token, rate limit, validation, conditions, mail — through the same AJAX
 * action the browser posts to. The TESTING.md §3 items these replace are named on each test.
 */
#[Group('package')]
final class SubmissionTest extends AjaxTestCase
{
    private const FIELDS = [
        ['id' => 'name', 'type' => 'text', 'label' => 'Name', 'required' => true],
        ['id' => 'email', 'type' => 'email', 'label' => 'Email'],
    ];

    public function testAValidSubmissionIsMailedToToCcAndBcc(): void
    {
        // TESTING.md §3: CC/BCC were saved but not sent.
        $form = $this->createForm(self::FIELDS, [self::notification(['cc' => 'cc@example.org', 'bcc' => 'bcc1@example.org, bcc2@example.org'])]);

        $r = $this->submit($form, ['name' => 'Ada', 'email' => 'ada@example.net']);

        self::assertTrue($r['success'], json_encode($r['data']));
        $mail = self::sentMail();
        self::assertCount(1, $mail);
        self::assertSame(['owner@example.org'], self::addresses($mail[0], 'to'));
        self::assertSame(['cc@example.org'], self::addresses($mail[0], 'cc'));
        self::assertSame(['bcc1@example.org', 'bcc2@example.org'], self::addresses($mail[0], 'bcc'));
        self::assertStringContainsString('Ada', $mail[0]->body);
    }

    public function testAnAnswerHoldingAPdfMarkerIsRefusedAtItsField(): void
    {
        // Typed into an answer, a seal delimiter or field marker lands in the PDF's text, where the verifier read it as
        // structure: the submitter's own genuine PDF then reported "Modified". Also when only the verifier's folding of the
        // text (entities, full-width forms) turns it into one, and inside a composite field's sub-values.
        $form = $this->createForm(
            [
                ['id' => 'name', 'type' => 'text', 'label' => 'Name'],
                ['id' => 'addr', 'type' => 'address', 'label' => 'Address', 'expanded' => true, 'street_enabled' => true, 'city_enabled' => true],
            ],
            [self::notification()]
        );

        $typed = $this->submit($form, ['name' => 'Ada ---BEGIN-SEAL---x', 'addr' => ['street' => 'Main St 1', 'city' => 'Berlin']]);
        self::assertFalse($typed['success']);
        self::assertStringContainsString('---BEGIN-SEAL---', (string) ($typed['data']['errors']['name'] ?? ''));

        $folded = $this->submit($form, ['name' => 'Ada', 'addr' => ['street' => '&#91;FABRICATOR_PDF_FIELD_END]', 'city' => 'Berlin']]);
        self::assertFalse($folded['success']);
        self::assertArrayHasKey('addr', $folded['data']['errors'] ?? []);
        self::assertSame([], self::sentMail(), 'nothing was sent');

        self::assertTrue($this->submit($form, ['name' => 'Ada [urgent] --- ok', 'addr' => ['street' => 'Main St 1', 'city' => 'Berlin']])['success']);
    }

    public function testBidiControlsAreRefusedAtTheirField(): void
    {
        // mPDF reorders text by U+202E and the isolates, so they could turn typed text into a marker only in the PDF, or
        // hide one. An answer holding one is refused, never changed.
        $form = $this->createForm([['id' => 'name', 'type' => 'text', 'label' => 'Name']], [self::notification()]);

        foreach (["[FABRICATOR\u{2066}_PDF_FIELD_END]", "\u{05D0}\u{202E}[DNE_DLEIF_FDP_ROTACIRBAF]"] as $typed) {
            $r = $this->submit($form, ['name' => $typed]);
            self::assertFalse($r['success']);
            self::assertArrayHasKey('name', $r['data']['errors'] ?? []);
        }
        self::assertSame([], self::sentMail());
    }

    public function testFieldPlaceholdersResolveInRecipientsAndHeaders(): void
    {
        // TESTING.md §3: {field_id} in To/CC/BCC, Reply-To, From Name and Subject — a feature, not an open relay.
        // Distinct addresses per list: PHPMailer drops an address already among the recipients of another list.
        $form = $this->createForm(self::FIELDS, [self::notification([
            'to'        => '{email}',
            'cc'        => '{name}@example.org, cc@example.org',
            'reply_to'  => '{email}',
            'from_name' => '{name} via the site',
            'subject'   => 'Message from {name}',
        ])]);

        self::assertTrue($this->submit($form, ['name' => 'Ada', 'email' => 'ada@example.net'])['success']);

        $mail = self::sentMail()[0];
        self::assertSame(['ada@example.net'], self::addresses($mail, 'to'));
        self::assertSame(['Ada@example.org', 'cc@example.org'], self::addresses($mail, 'cc'));
        self::assertSame('Message from Ada', $mail->subject);
        self::assertStringContainsString('Reply-To: ada@example.net', $mail->header);
        self::assertMatchesRegularExpression('/^From: Ada via the site </m', $mail->header);
    }

    public function testAFormFieldNeverBecomesTheSender(): void
    {
        // Mail "from" a visitor's own domain is discarded by strict domains after wp_mail() succeeded, and the
        // submission is stored nowhere else. Even a stored or imported {email} sender is ignored; Reply-To carries it.
        $form = $this->createForm(self::FIELDS, [self::notification(['from_email' => '{email}', 'reply_to' => '{email}'])]);

        self::assertTrue($this->submit($form, ['name' => 'Ada', 'email' => 'ada@gmail.com'])['success']);
        $mail = self::sentMail()[0];
        self::assertDoesNotMatchRegularExpression('/^From:.*ada@gmail\.com/m', $mail->header);
        self::assertStringContainsString('Reply-To: ada@gmail.com', $mail->header);
    }

    public function testABlankPlaceholderFallsBackToTheOtherRecipients(): void
    {
        // TESTING.md §3: leave a placeholder field blank — the mail still goes out.
        $form = $this->createForm(self::FIELDS, [self::notification(['to' => '{email}, owner@example.org'])]);

        self::assertTrue($this->submit($form, ['name' => 'Ada', 'email' => ''])['success']);
        self::assertSame(['owner@example.org'], self::addresses(self::sentMail()[0], 'to'));
    }

    public function testAVisitorCannotInjectHeadersThroughAPlaceholder(): void
    {
        // A one-line answer can't hold a line break at all; a textarea's can, and its placeholder still adds no header.
        $form = $this->createForm(
            array_merge(self::FIELDS, [['id' => 'msg', 'type' => 'textarea', 'label' => 'Message']]),
            [self::notification(['subject' => 'From {name} {msg}'])]
        );

        $refused = $this->submit($form, ['name' => "Ada\r\nBcc: victim@example.com", 'email' => '']);
        self::assertFalse($refused['success']);
        self::assertArrayHasKey('name', $refused['data']['errors'] ?? []);

        self::assertTrue($this->submit($form, ['name' => 'Ada', 'email' => '', 'msg' => "Hi\r\nBcc: victim@example.com"])['success']);
        $mail = self::sentMail()[0];
        self::assertSame([], self::addresses($mail, 'bcc'));
        self::assertStringNotContainsString("\nBcc: victim", $mail->header);
    }

    public function testARequiredFieldLeftEmptyIsRefusedWithItsError(): void
    {
        $form = $this->createForm(self::FIELDS, [self::notification()]);

        $r = $this->submit($form, ['name' => '', 'email' => '']);

        self::assertFalse($r['success']);
        self::assertArrayHasKey('name', $r['data']['errors']);
        self::assertSame([], self::sentMail());
    }

    public function testTheSameTokenIsAcceptedOnce(): void
    {
        // TESTING.md §3: a resubmission via the back button or a cached page is refused as a duplicate.
        $form  = $this->createForm(self::FIELDS, [self::notification()]);
        $token = SingleUseToken::issue($form);

        self::assertTrue($this->submit($form, ['name' => 'Ada'], $token)['success']);
        $again = $this->submit($form, ['name' => 'Ada'], $token);
        self::assertFalse($again['success']);
        self::assertStringContainsString('already been received', $again['data']['message']);
        self::assertTrue($this->submit($form, ['name' => 'Bob'])['success'], 'another visitor with a fresh token is not affected');
        self::assertCount(2, self::sentMail());
    }

    public function testAForgedTokenOrNonceIsRefused(): void
    {
        $form = $this->createForm(self::FIELDS, [self::notification()]);

        self::assertFalse($this->submit($form, ['name' => 'Ada'], 'not-a-token')['success']);
        self::assertFalse($this->submit($form, ['name' => 'Ada'], SingleUseToken::issue($form + 1))['success'], 'a token for another form');
        self::assertFalse($this->submit($form, ['name' => 'Ada'], null, 'badnonce')['success']);
        self::assertSame([], self::sentMail());
    }

    public function testTheEleventhSendInFiveMinutesFromOneAddressIsRefused(): void
    {
        // TESTING.md §3: "sending the form still stops after 10 sends in 5 minutes from one address".
        $form = $this->createForm(self::FIELDS, [self::notification()]);
        for ($i = 1; $i <= 10; $i++) {
            self::assertTrue($this->submit($form, ['name' => 'Send ' . $i])['success'], "send $i");
        }
        $r = $this->submit($form, ['name' => 'Send 11']);
        self::assertFalse($r['success']);
        self::assertArrayHasKey('retry_after', $r['data']);

        $_SERVER['REMOTE_ADDR'] = '198.51.100.7';
        self::assertTrue($this->submit($form, ['name' => 'Someone else'])['success'], 'another address has its own count');
    }

    public function testTheFiftyFirstSendAcrossAllFormsIsRefused(): void
    {
        // The per-form count alone allowed 10 sends per form, so a site with more forms allowed more from one address.
        // 50 across all forms leaves room for a shared network (school, office) and still bounds one address.
        $forms = [];
        for ($f = 0; $f < 6; $f++) {
            $forms[] = $this->createForm(self::FIELDS, [self::notification()]);
        }
        for ($i = 1; $i <= 50; $i++) {
            self::assertTrue($this->submit($forms[$i % 5], ['name' => 'Send ' . $i])['success'], "send $i");
        }
        $r = $this->submit($forms[5], ['name' => 'Send 51, to a form not used yet']);
        self::assertFalse($r['success']);
        self::assertArrayHasKey('retry_after', $r['data']);
    }

    public function testAFullIpv6Slash48WritesNoFurtherRows(): void
    {
        // Counted per /64 alone, one /48 (65,536 /64s, free from a tunnel broker) wrote two rows for every /64 it used.
        // The /48 is counted first; once full, a send from yet another /64 in it is refused and writes nothing.
        global $wpdb;
        $form  = $this->createForm(self::FIELDS, [self::notification()]);
        $rows  = static fn(): int => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like('fabricator_rl_') . '%'));
        $key48 = 'submit_48_' . hash_hmac('sha256', '2001:db8:1234::/48', wp_salt('auth'));
        for ($i = 0; $i < 200; $i++) {
            \FabricatorForms\Utils\RateLimiter::increment($key48, 5 * MINUTE_IN_SECONDS);
        }

        $_SERVER['REMOTE_ADDR'] = '2001:db8:1234:77::1';
        $before = $rows();
        $r      = $this->submit($form, ['name' => 'Send 201 from this /48']);
        self::assertFalse($r['success']);
        self::assertArrayHasKey('retry_after', $r['data']);
        self::assertSame($before, $rows(), 'no /64 rows once the /48 is full');

        $_SERVER['REMOTE_ADDR'] = '2001:db8:9999:1::1';
        self::assertTrue($this->submit($form, ['name' => 'Another /48'])['success']);
    }

    public function testASubmissionSweepsExpiredRowsAtMostHourly(): void
    {
        // The scheduled sweeps are set up on activation and from admin_init only: on a network site nobody opens wp-admin
        // on, or with WP-Cron off, the rows keyed on hashed visitor addresses would stay forever, and so would a
        // submission PDF a request that died left behind.
        global $wpdb;
        // Read from the table, as RateLimiter does: the sweep deletes with SQL, which the option cache does not see.
        $stored = static fn(string $name): bool => $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name)) !== null;
        $form   = $this->createForm(self::FIELDS, [self::notification()]);
        update_option(\FabricatorForms\Plugin::LAST_INLINE_SWEEP_OPTION, time() - 2 * HOUR_IN_SECONDS, false);
        add_option('fabricator_rl_expired_one', '3|' . (time() - 10), '', false);
        // Submission folders of other requests: one a killed request left, one still being sent.
        $pdf_dir = \FabricatorForms\Utils\PrivateDir::ensure() . '/pdf/';
        $stale   = $pdf_dir . bin2hex(random_bytes(16));
        $fresh   = $pdf_dir . bin2hex(random_bytes(16));
        mkdir($stale);
        mkdir($fresh);
        file_put_contents($stale . '/Entry.pdf', '%PDF-1.4');
        file_put_contents($fresh . '/Entry.pdf', '%PDF-1.4');
        touch($stale, time() - 2 * HOUR_IN_SECONDS);

        self::assertTrue($this->submit($form, ['name' => 'Ada'])['success']);
        self::assertFalse($stored('fabricator_rl_expired_one'), 'swept by the submission');
        self::assertDirectoryDoesNotExist($stale, 'a PDF left behind over an hour ago is removed');
        self::assertFileExists($fresh . '/Entry.pdf', 'one still being sent is not');
        \FabricatorForms\Utils\PrivateDir::removeTree($fresh);

        add_option('fabricator_rl_expired_two', '3|' . (time() - 10), '', false);
        self::assertTrue($this->submit($form, ['name' => 'Ada again'])['success']);
        self::assertTrue($stored('fabricator_rl_expired_two'), 'not again within the hour');
    }

    public function testAHoneypotHitWritesNoRateLimitRows(): void
    {
        // The honeypot, the form and its notifications are checked before any row is written: the nonce and token before
        // them are free to anyone who loads the page.
        global $wpdb;
        $form   = $this->createForm(self::FIELDS, [self::notification()]);
        $before = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like('fabricator_rl_') . '%'));
        self::assertTrue($this->submit($form, ['name' => 'Bot', 'fabricator_hp_field' => 'filled'])['success'], 'looks sent to the bot');
        self::assertSame($before, (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like('fabricator_rl_') . '%')));
        self::assertSame([], self::sentMail());
    }

    public function testRenderingTheFormWritesNoRateLimitRows(): void
    {
        // TESTING.md §3: loading a form page many times leaves no fabricator_rl_token_... rows.
        global $wpdb;
        $form   = $this->createForm(self::FIELDS, [self::notification()]);
        $before = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options}");
        for ($i = 0; $i < 20; $i++) {
            do_shortcode('[fabricator_form id="' . $form . '"]');
        }
        self::assertSame(0, (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like('fabricator_rl_') . '%')));
        self::assertLessThanOrEqual($before + 2, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options}"), 'rendering must not grow the options table per view');
    }

    public function testAFailedMailIsReportedAndTheVisitorCanRetry(): void
    {
        // TESTING.md §3: a mail failure gives the visitor an error, not a false "Thank you", and the retry works.
        $form  = $this->createForm(self::FIELDS, [self::notification()]);
        $token = SingleUseToken::issue($form);
        add_filter('pre_wp_mail', '__return_false');

        $r = $this->submit($form, ['name' => 'Ada'], $token);

        remove_filter('pre_wp_mail', '__return_false');
        self::assertFalse($r['success']);
        self::assertStringContainsString('could not be delivered', $r['data']['message']);
        self::assertTrue($this->submit($form, ['name' => 'Ada'], $token)['success'], 'the claim was released, so the same token works');
    }

    public function testFailedDeliveriesAreShownToFormManagersUntilASubmissionIsDelivered(): void
    {
        // Nothing is stored, so a mail setup that keeps failing loses every submission; the admin must learn of it
        // from more than the server log. The record says when, which form and what failed, never who or what.
        $form = $this->createForm(self::FIELDS, [self::notification()]);
        add_filter('pre_wp_mail', '__return_false');
        $this->submit($form, ['name' => 'Ada Lovelace', 'email' => 'ada@example.net']);
        $this->submit($form, ['name' => 'Ada Lovelace', 'email' => 'ada@example.net']);
        remove_filter('pre_wp_mail', '__return_false');
        $notice = static function (): string {
            ob_start();
            \FabricatorForms\Plugin::maybeWarnMailFailures();
            return (string) ob_get_clean();
        };

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $shown = $notice();
        self::assertStringContainsString('2 submissions could not be delivered', $shown);
        self::assertStringNotContainsString('Ada', $shown, 'no answer is shown');
        self::assertStringContainsString('could not send a notification', $shown, 'the cause');
        self::assertStringContainsString(get_the_title($form), $shown, 'the form');
        $stored = (string) wp_json_encode(get_option(\FabricatorForms\Form\MailSender::FAILURE_OPTION));
        self::assertStringNotContainsString('Ada', $stored, 'no answer is recorded');
        self::assertStringNotContainsString('@', $stored, 'no address is recorded');

        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        self::assertSame('', $notice(), 'only for those who may edit forms');

        // Another form that delivers ends nothing: it must not hide this one, nor can a visitor clear the notice with it.
        wp_update_post(['ID' => $form, 'post_title' => 'Failing form']);
        $other = $this->createForm(self::FIELDS, [self::notification()]);
        wp_update_post(['ID' => $other, 'post_title' => 'Working form']);
        wp_set_current_user(0);
        self::assertTrue($this->submit($other, ['name' => 'Ada Lovelace', 'email' => 'ada@example.net'])['success']);
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        self::assertStringContainsString('Form &quot;Failing form&quot;: since', $notice(), 'still shown after another form delivered');

        wp_set_current_user(0);
        self::assertTrue($this->submit($form, ['name' => 'Ada Lovelace', 'email' => 'ada@example.net'])['success']);
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        self::assertSame('', $notice(), 'a delivered submission to the failing form ends it');
    }

    public function testOnlyAnAdministratorsDismissalClearsTheNoticeForEveryone(): void
    {
        // A form editor who dismisses it stops seeing it; the administrators it is meant to reach still do.
        $form = $this->createForm(self::FIELDS, [self::notification()]);
        add_filter('pre_wp_mail', '__return_false');
        $this->submit($form, ['name' => 'Ada Lovelace']);
        $notice = static function (): string {
            ob_start();
            \FabricatorForms\Plugin::maybeWarnMailFailures();
            return (string) ob_get_clean();
        };
        update_option('fabricator_forms_access', ['roles' => ['editor' => ['edit_forms' => true]]]);
        $editor = self::factory()->user->create(['role' => 'editor']);
        $admin  = self::factory()->user->create(['role' => 'administrator']);
        if (is_multisite()) {
            grant_super_admin($admin);
        }

        wp_set_current_user($editor);
        self::assertStringContainsString('could not be delivered', $notice(), 'the editor sees it');
        \FabricatorForms\Plugin::dismissMailFailures();
        self::assertSame('', $notice(), 'hidden for the editor');
        wp_set_current_user($admin);
        self::assertStringContainsString('could not be delivered', $notice(), 'still there for the administrator');

        // A newer failure shows it to the editor again.
        sleep(1);
        wp_set_current_user(0);
        $this->submit($form, ['name' => 'Ada Lovelace']);
        remove_filter('pre_wp_mail', '__return_false');
        wp_set_current_user($editor);
        self::assertStringContainsString('2 submissions could not be delivered', $notice(), 'shown again after a newer failure');

        // An administrator's dismissal clears it for everyone.
        wp_set_current_user($admin);
        \FabricatorForms\Plugin::dismissMailFailures();
        self::assertSame('', $notice());
        wp_set_current_user($editor);
        self::assertSame('', $notice());
    }

    public function testEachFailingFormHasItsOwnLineInTheNotice(): void
    {
        $first  = $this->createForm(self::FIELDS, [self::notification()]);
        $second = $this->createForm(self::FIELDS, [self::notification()]);
        wp_update_post(['ID' => $first, 'post_title' => 'Orders']);
        wp_update_post(['ID' => $second, 'post_title' => 'Returns']);
        add_filter('pre_wp_mail', '__return_false');
        $this->submit($first, ['name' => 'Ada Lovelace']);
        $this->submit($second, ['name' => 'Ada Lovelace']);
        $this->submit($second, ['name' => 'Ada Lovelace']);
        remove_filter('pre_wp_mail', '__return_false');

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        ob_start();
        \FabricatorForms\Plugin::maybeWarnMailFailures();
        $shown = (string) ob_get_clean();

        self::assertStringContainsString('Form &quot;Orders&quot;: since', $shown);
        self::assertStringContainsString('1 submission could not be delivered', $shown);
        self::assertStringContainsString('Form &quot;Returns&quot;: since', $shown);
        self::assertStringContainsString('2 submissions could not be delivered', $shown);
        self::assertLessThan(strpos($shown, 'Orders'), strpos($shown, 'Returns'), 'the newest first');
    }

    public function testOneFailedNotificationOfTwoFailsTheSubmissionAndLogsNoAddress(): void
    {
        // TESTING.md §3: with two notifications, one failing — error for the visitor, a log line naming the form and
        // the notification but no email address, even with WP_DEBUG off.
        $form = $this->createForm(self::FIELDS, [
            self::notification(['slug' => 'owner', 'to' => 'owner@example.org']),
            self::notification(['slug' => 'visitor', 'to' => 'fails@example.org']),
        ]);
        $fail = static fn($return, array $atts) => str_contains((string) $atts['to'], 'fails@') ? false : $return;
        add_filter('pre_wp_mail', $fail, 10, 2);
        $log = tempnam(sys_get_temp_dir(), 'ff-log');
        $previousLog = ini_set('error_log', $log);

        $r = $this->submit($form, ['name' => 'Ada', 'email' => 'ada@example.net']);

        ini_set('error_log', (string) $previousLog);
        remove_filter('pre_wp_mail', $fail, 10);
        $logged = (string) file_get_contents($log);
        unlink($log);
        self::assertFalse($r['success']);
        self::assertStringContainsString('visitor', $logged, 'the failing notification is named');
        self::assertStringContainsString((string) $form, $logged, 'the form is named');
        self::assertDoesNotMatchRegularExpression('/[\w.+-]+@[\w-]+\.[\w.]+/', $logged, 'no email address in the log');
    }

    public function testAFormWithEveryNotificationDisabledRefusesTheSubmission(): void
    {
        // TESTING.md §3: refused with an error instead of accepted and silently discarded.
        $form = $this->createForm(self::FIELDS, [self::notification(['enabled' => false])]);

        $r = $this->submit($form, ['name' => 'Ada']);

        self::assertFalse($r['success']);
        self::assertSame([], self::sentMail());
    }

    public function testASubmitButtonConditionIsEnforcedOnTheServer(): void
    {
        // TESTING.md §3: posting anyway (Enter key, developer tools) while the button is hidden is refused.
        $form = $this->createForm(self::FIELDS, [self::notification()], ['submit_conditions' => [
            'match' => 'all',
            'rules' => [['field_id' => 'name', 'operator' => 'equals', 'value' => 'open sesame']],
        ]]);

        self::assertFalse($this->submit($form, ['name' => 'Ada'])['success']);
        self::assertTrue($this->submit($form, ['name' => 'Open Sesame'])['success'], 'conditions compare without case');
    }

    public function testAFieldHiddenByItsConditionIsNeitherRequiredNorMailed(): void
    {
        $fields = [
            ['id' => 'country', 'type' => 'text', 'label' => 'Country'],
            ['id' => 'vat', 'type' => 'text', 'label' => 'VAT number', 'required' => true, 'conditions' => [
                'action' => 'show', 'match' => 'all', 'rules' => [['field_id' => 'country', 'operator' => 'equals', 'value' => 'DE']],
            ]],
        ];
        $form = $this->createForm($fields, [self::notification()]);

        self::assertTrue($this->submit($form, ['country' => 'FR', 'vat' => 'posted anyway'])['success']);
        self::assertStringNotContainsString('posted anyway', self::sentMail()[0]->body, 'a hidden field never reaches the mail');
        self::assertFalse($this->submit($form, ['country' => 'DE', 'vat' => ''])['success'], 'visible again: required');
    }

    public function testAFieldShownBecauseTheFieldItTestsIsHiddenIsMailed(): void
    {
        // Chained conditions: "a" is hidden but still posted, so front.js reads it as empty and shows "b". The server
        // read the posted value, hid "b" too, and the visitor's answer never reached the mail while they were thanked.
        $show   = static fn(string $field, string $op, string $value = ''): array => [
            'action' => 'show', 'match' => 'all', 'rules' => [['field_id' => $field, 'operator' => $op, 'value' => $value]],
        ];
        $fields = [
            ['id' => 'gate', 'type' => 'text', 'label' => 'Gate'],
            ['id' => 'a', 'type' => 'text', 'label' => 'A', 'conditions' => $show('gate', 'equals', 'on')],
            ['id' => 'b', 'type' => 'text', 'label' => 'B', 'required' => true, 'conditions' => $show('a', 'empty')],
            ['id' => 'c', 'type' => 'text', 'label' => 'C', 'required' => true, 'conditions' => $show('a', 'equals', 'x')],
        ];
        $form = $this->createForm($fields, [self::notification()]);

        $r = $this->submit($form, ['gate' => 'off', 'a' => 'x', 'b' => 'PLEASE-CALL-ME-BACK', 'c' => '']);
        self::assertTrue($r['success'], 'c tests the hidden a, so it is hidden too and not required: ' . wp_json_encode($r));
        self::assertStringContainsString('PLEASE-CALL-ME-BACK', self::sentMail()[0]->body);
    }

    public function testTheHoneypotDropsTheSubmissionSilently(): void
    {
        $form = $this->createForm(self::FIELDS, [self::notification()]);

        self::assertTrue($this->submit($form, ['name' => 'Bot', 'fabricator_hp_field' => 'filled'])['success'], 'the bot is told it worked');
        self::assertSame([], self::sentMail());
    }
}
