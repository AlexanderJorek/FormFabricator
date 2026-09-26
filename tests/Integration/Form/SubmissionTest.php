<?php

namespace FabricatorForms\Tests\Integration\Form;

use FabricatorForms\Tests\Integration\AjaxTestCase;
use FabricatorForms\Utils\SingleUseToken;

/**
 * A submission end to end — nonce, one-time token, rate limit, validation, conditions, mail — through the same AJAX
 * action the browser posts to. The TESTING.md §3 items these replace are named on each test.
 */
final class SubmissionTest extends AjaxTestCase
{
    private const FIELDS = [
        ['id' => 'name', 'type' => 'text', 'label' => 'Name', 'required' => true],
        ['id' => 'email', 'type' => 'email', 'label' => 'Email'],
    ];

    public function testAValidSubmissionIsMailedToToCcAndBcc(): void
    {
        // TESTING.md §3: CC/BCC were saved but not sent (1.0.6).
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

    public function testFieldPlaceholdersResolveInRecipientsAndHeaders(): void
    {
        // TESTING.md §3: {field_id} in To/CC/BCC, Reply-To, From Name and Subject (1.0.6) — a feature, not an open relay.
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

    public function testABlankPlaceholderFallsBackToTheOtherRecipients(): void
    {
        // TESTING.md §3: leave a placeholder field blank — the mail still goes out.
        $form = $this->createForm(self::FIELDS, [self::notification(['to' => '{email}, owner@example.org'])]);

        self::assertTrue($this->submit($form, ['name' => 'Ada', 'email' => ''])['success']);
        self::assertSame(['owner@example.org'], self::addresses(self::sentMail()[0], 'to'));
    }

    public function testAVisitorCannotInjectHeadersThroughAPlaceholder(): void
    {
        $form = $this->createForm(self::FIELDS, [self::notification(['subject' => 'From {name}'])]);

        self::assertTrue($this->submit($form, ['name' => "Ada\r\nBcc: victim@example.com", 'email' => ''])['success']);
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
        // TESTING.md §3: a resubmission via the back button or a cached page is refused as a duplicate (1.0.2).
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
        // TESTING.md §3: "sending the form still stops after 10 sends in 5 minutes from one address" (1.0.7).
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

    public function testRenderingTheFormWritesNoRateLimitRows(): void
    {
        // TESTING.md §3: loading a form page many times leaves no fabricator_rl_token_... rows (1.0.7).
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
        // TESTING.md §3: a mail failure gives the visitor an error, not a false "Thank you" (1.0.7), and the retry works.
        $form  = $this->createForm(self::FIELDS, [self::notification()]);
        $token = SingleUseToken::issue($form);
        add_filter('pre_wp_mail', '__return_false');

        $r = $this->submit($form, ['name' => 'Ada'], $token);

        remove_filter('pre_wp_mail', '__return_false');
        self::assertFalse($r['success']);
        self::assertStringContainsString('could not be delivered', $r['data']['message']);
        self::assertTrue($this->submit($form, ['name' => 'Ada'], $token)['success'], 'the claim was released, so the same token works');
    }

    public function testOneFailedNotificationOfTwoFailsTheSubmissionAndLogsNoAddress(): void
    {
        // TESTING.md §3: with two notifications, one failing — error for the visitor, a log line naming the form and
        // the notification but no email address, even with WP_DEBUG off (1.0.7).
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
        // TESTING.md §3: refused with an error instead of accepted and silently discarded (1.0.7).
        $form = $this->createForm(self::FIELDS, [self::notification(['enabled' => false])]);

        $r = $this->submit($form, ['name' => 'Ada']);

        self::assertFalse($r['success']);
        self::assertSame([], self::sentMail());
    }

    public function testASubmitButtonConditionIsEnforcedOnTheServer(): void
    {
        // TESTING.md §3: posting anyway (Enter key, developer tools) while the button is hidden is refused (1.0.7).
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

    public function testTheHoneypotDropsTheSubmissionSilently(): void
    {
        $form = $this->createForm(self::FIELDS, [self::notification()]);

        self::assertTrue($this->submit($form, ['name' => 'Bot', 'fabricator_hp_field' => 'filled'])['success'], 'the bot is told it worked');
        self::assertSame([], self::sentMail());
    }
}
