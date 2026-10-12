<?php

namespace FabricatorForms\Tests\Integration\Admin;

use FabricatorForms\Admin\FormEditor;
use FabricatorForms\Admin\FormList;
use FabricatorForms\Form\FormModel;
use FabricatorForms\Form\FormRenderer;
use FabricatorForms\Tests\Integration\AjaxTestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * What the builder's save, and the form list's export and import, do on the server (TESTING.md §2, §3, §4): the sender
 * rule, the save warnings, the page break's button labels, the import preview, and wording that has to survive a trip
 * between sites in different languages. What the builder shows while typing is covered by the JS suite.
 */
final class FormEditorServerTest extends AjaxTestCase
{
    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- overrides WP_UnitTestCase's snake_case fixture method.
    public function set_up(): void
    {
        parent::set_up();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        // The plugin loads the admin classes only when is_admin(), which the test bootstrap is not; hook them as
        // admin-ajax.php would.
        FormEditor::init();
        FormList::init();
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- overrides WP_UnitTestCase's snake_case fixture method.
    public function tear_down(): void
    {
        if (is_locale_switched()) {
            restore_current_locale();
        }
        unload_textdomain('formfabricator');
        parent::tear_down();
    }

    public function testAFieldPlaceholderTypedAsTheSenderIsEmptiedOnSave(): void
    {
        $r = $this->saveForm([['id' => 'email', 'type' => 'email', 'label' => 'Email']], [
            self::notification(['slug' => 'a', 'from_email' => '{email}']),
            self::notification(['slug' => 'b', 'from_email' => '{admin_email}']),
            self::notification(['slug' => 'c', 'from_email' => 'shop@example.org']),
        ]);

        self::assertTrue($r['success'], wp_json_encode($r));
        $senders = array_column(FormModel::get($r['data']['form_id'])->notifications, 'from_email', 'slug');
        self::assertSame(['a' => '', 'b' => '{admin_email}', 'c' => 'shop@example.org'], $senders);
    }

    public function testSavingWithoutAnActiveNotificationWarns(): void
    {
        $off = $this->saveForm([['id' => 'name', 'type' => 'text', 'label' => 'Name']], [self::notification(['enabled' => false])]);
        self::assertTrue($off['success'], 'saved either way');
        self::assertStringContainsString('no active notification', $off['data']['warning']);

        $on = $this->saveForm([['id' => 'name', 'type' => 'text', 'label' => 'Name']], [self::notification()]);
        self::assertSame('', $on['data']['warning']);
    }

    public function testSavingAMandateWithoutWordingWarns(): void
    {
        $bacs = ['id' => 'dd', 'type' => 'directdebit', 'label' => 'Direct debit', 'scheme' => 'bacs', 'bacs_text' => ''];

        $r = $this->saveForm([$bacs], [self::notification()]);
        self::assertStringContainsString('has no mandate text', $r['data']['warning']);

        $grouped = $this->saveForm([['id' => 'g', 'type' => 'group', 'label' => 'Group', 'children' => [$bacs]]], [self::notification()]);
        self::assertStringContainsString('has no mandate text', $grouped['data']['warning'], 'inside a group too');

        // Attaching the PDF, so the mandate's signature reaches the recipient and only the wording is in question.
        $complete = ['bacs_text' => '<p>I instruct my bank to pay Acme.</p>', 'creditor_name' => 'Acme Ltd', 'bacs_creditor_id' => '123456', 'mandate_ref' => 'Your customer number'];
        $worded   = $this->saveForm([$complete + $bacs], [self::notification(['attach_pdf' => true])]);
        self::assertSame('', $worded['data']['warning']);
    }

    public function testSavingAConsentFieldWithItsPlaceholderTextWarns(): void
    {
        // "I agree to the terms." names no purpose and no way to withdraw (GDPR Art. 7(3)): no valid consent.
        $consent = ['id' => 'ok', 'type' => 'consent', 'label' => 'Consent'];
        self::assertStringContainsString('still shows its placeholder text', $this->saveForm([$consent], [self::notification()])['data']['warning']);
        $grouped = $this->saveForm([['id' => 'g', 'type' => 'group', 'label' => 'Group', 'children' => [$consent]]], [self::notification()]);
        self::assertStringContainsString('still shows its placeholder text', $grouped['data']['warning'], 'inside a group too');

        $written = ['consent_text' => '<p>We use your email to send the newsletter. You can withdraw at any time by emailing privacy@example.org.</p>'] + $consent;
        self::assertSame('', $this->saveForm([$written], [self::notification()])['data']['warning']);
    }

    public function testSavingAMessageThatLosesContentNamesItsNotification(): void
    {
        $consent = ['id' => 'ok', 'type' => 'consent', 'label' => 'Consent', 'consent_text' => '<p>Newsletter; withdraw by mail to privacy@example.org.</p>'];
        $removed = [
            'a script'          => '<p>Hi</p><script>alert(1)</script>',
            'an event handler'  => '<p onclick="steal()">Hi</p>',
            'a frame'           => '<p>Hi</p><iframe src="https://example.org/"></iframe>',
            'a tag outside'     => '<p>Hi <blink>now</blink></p>',
            'a style property'  => '<p style="color: red; animation: blink 1s">Hi</p>',
            'a data: image'     => '<img src="data:image/png;base64,iVBORw0KGgo=" alt="Logo">',
            'an @import'        => '<style>@import url(https://example.org/t.css); p { color: red; }</style><p>Hi</p>',
            'a link with a form action' => '<form action="https://example.org/"><button>Go</button></form>',
        ];
        foreach ($removed as $what => $body) {
            $warning = $this->saveForm([$consent], [self::notification(['name' => 'Owner copy', 'body' => $body])])['data']['warning'];
            self::assertStringContainsString('Content was removed from the message', $warning, $what);
            self::assertStringContainsString('Owner copy', $warning, $what);
        }

        // What the Visual view writes, and what saving only normalizes: no warning.
        $kept = [
            'the Visual view'      => '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body><p><b>Hi</b> &amp; <i>welcome</i></p><ul><li>One</li></ul><p><a href="https://example.org/">Link</a></p>{all_fields}</body></html>',
            'a link to a new tab'  => '<p><a href="https://example.org/" target="_blank">Link</a></p>',
            'content after </html>' => '<html><body><p>Hi</p></body></html>{all_fields}',
            'spelling'             => '<P CLASS=intro STYLE="color:red">Hi<BR/>there &copy; 2026</P><!-- note -->',
            'a style comment'      => '<style>/* brand */ p { color: #333; }</style><p style="">Hi</p>',
        ];
        foreach ($kept as $what => $body) {
            $warning = $this->saveForm([$consent], [self::notification(['name' => 'Owner copy', 'body' => $body])])['data']['warning'];
            self::assertStringNotContainsString('Content was removed', $warning, $what);
        }

        // A notification without a name is named by its slug, as in the other warnings.
        $warning = $this->saveForm([$consent], [self::notification(['slug' => 'copy-2', 'body' => '<script>x</script>'])])['data']['warning'];
        self::assertStringContainsString('copy-2', $warning);
    }

    public function testATestEmailGoesToTheUserWithTheNotificationsSender(): void
    {
        $user  = wp_get_current_user();
        $nonce = wp_create_nonce('fabricator_forms_admin_nonce');

        $r = $this->ajax('fabricator_forms_send_test_mail', ['nonce' => $nonce, 'from_name' => 'Acme {form_title}', 'from_email' => 'shop@example.org', 'form_title' => 'Orders']);

        self::assertTrue($r['success'], wp_json_encode($r));
        self::assertStringContainsString($user->user_email, $r['data']['message']);
        $mail = self::sentMail();
        self::assertCount(1, $mail);
        self::assertSame([$user->user_email], self::addresses($mail[0], 'to'), 'only to the user, whatever the notification names');
        self::assertStringContainsString('From: Acme Orders <shop@example.org>', $mail[0]->header);

        // A form field as sender is dropped, as when the form is saved: the site's sender applies.
        reset_phpmailer_instance();
        $this->ajax('fabricator_forms_send_test_mail', ['nonce' => $nonce, 'from_name' => '', 'from_email' => '{email}', 'form_title' => 'Orders']);
        self::assertStringContainsString('<' . get_option('admin_email') . '>', self::sentMail()[0]->header);

        // A failure is reported, not hidden.
        add_filter('pre_wp_mail', '__return_false');
        $failed = $this->ajax('fabricator_forms_send_test_mail', ['nonce' => $nonce, 'from_name' => '', 'from_email' => '', 'form_title' => '']);
        remove_filter('pre_wp_mail', '__return_false');
        self::assertFalse($failed['success']);
        self::assertStringContainsString('could not be sent', $failed['data']['message']);

        // A form editor who manages nothing else learns that it failed, not what the mail system said.
        update_option('fabricator_forms_access', ['roles' => ['editor' => ['edit_forms' => true]]]);
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        $fail_with_details = static function (): void {
            do_action('wp_mail_failed', new \WP_Error('wp_mail_failed', 'SMTP Error: could not authenticate as relay@mail.internal'));
        };
        add_filter('pre_wp_mail', static function () use ($fail_with_details): bool {
            $fail_with_details();
            return false;
        });
        $editor = $this->ajax('fabricator_forms_send_test_mail', ['nonce' => wp_create_nonce('fabricator_forms_admin_nonce'), 'from_name' => '', 'from_email' => '', 'form_title' => '']);
        remove_all_filters('pre_wp_mail');
        self::assertFalse($editor['success']);
        self::assertStringContainsString('Ask the site\'s administrator', $editor['data']['message']);
        self::assertStringNotContainsString('mail.internal', $editor['data']['message']);

        // Only for those who may edit forms.
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        reset_phpmailer_instance();
        $refused = $this->ajax('fabricator_forms_send_test_mail', ['nonce' => wp_create_nonce('fabricator_forms_admin_nonce'), 'from_name' => '', 'from_email' => '', 'form_title' => '']);
        self::assertFalse($refused['success']);
        self::assertSame([], self::sentMail());
    }

    public function testTestEmailsAreLimitedPerUser(): void
    {
        // Each is a real email: a loop must not use up the site's sending quota, which the notifications need.
        $nonce = wp_create_nonce('fabricator_forms_admin_nonce');
        $post  = ['nonce' => $nonce, 'from_name' => '', 'from_email' => '', 'form_title' => ''];
        for ($i = 0; $i < 5; $i++) {
            self::assertTrue($this->ajax('fabricator_forms_send_test_mail', $post)['success'], 'test ' . ($i + 1));
        }
        $sent = count(self::sentMail());

        $refused = $this->ajax('fabricator_forms_send_test_mail', $post);

        self::assertFalse($refused['success']);
        self::assertStringContainsString('wait a few minutes', $refused['data']['message']);
        self::assertCount($sent, self::sentMail(), 'no sixth email');
    }

    public function testSavingAConsentFieldWithoutTextWarns(): void
    {
        // The visitor would agree to nothing, recorded as 'Agreed to ""' (GDPR Art. 4(11), 7(1)).
        foreach (['', '<p>&nbsp;</p>', ' <p> </p> '] as $text) {
            $consent = ['id' => 'ok', 'type' => 'consent', 'label' => 'Consent', 'consent_text' => $text];
            $warning = $this->saveForm([$consent], [self::notification()])['data']['warning'];
            self::assertStringContainsString('has no text', $warning, var_export($text, true));
            self::assertStringNotContainsString('still shows its placeholder text', $warning, var_export($text, true));
        }
        $grouped = ['id' => 'g', 'type' => 'group', 'label' => 'Group', 'children' => [['id' => 'ok', 'type' => 'consent', 'label' => 'Consent', 'consent_text' => '']]];
        self::assertStringContainsString('has no text', $this->saveForm([$grouped], [self::notification()])['data']['warning'], 'inside a group too');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTheConsentPlaceholderIsRecognisedInEveryInstalledLanguage(): void
    {
        // Created by an admin using German, saved by one using English: the placeholder is German, the saving admin's
        // English. German is installed here through the filters WordPress reads, with the plugin's own German .mo.
        add_filter('get_available_languages', static fn(): array => ['de_DE']);
        add_filter(
            'lang_dir_for_domain',
            static fn($path, string $domain): mixed => $domain === 'formfabricator' ? FABRICATOR_FORMS_PATH . 'languages/' : $path,
            10,
            2
        );
        // The switcher reads the installed languages once, when it is made.
        $GLOBALS['wp_locale_switcher'] = new \WP_Locale_Switcher();
        $GLOBALS['wp_locale_switcher']->init();
        self::assertTrue(switch_to_locale('de_DE'));
        self::assertSame('Ich stimme den Bedingungen zu.', __('I agree to the terms.', 'formfabricator'), 'the German translation is there');
        restore_previous_locale();

        // Three consent fields, one language switch: the placeholders are listed once per request, not per field.
        $switches = 0;
        add_action('switch_locale', static function () use (&$switches): void {
            $switches++;
        });
        $german = ['id' => 'ok', 'type' => 'consent', 'label' => 'Consent', 'consent_text' => 'Ich stimme den Bedingungen zu.'];
        $more   = [['id' => 'ok2'] + $german, ['id' => 'ok3'] + $german];
        self::assertStringContainsString('still shows its placeholder text', $this->saveForm(array_merge([$german], $more), [self::notification()])['data']['warning']);
        self::assertSame(1, $switches);
        $english = ['consent_text' => 'I agree to the terms.'] + $german;
        self::assertStringContainsString('still shows its placeholder text', $this->saveForm([$english], [self::notification()])['data']['warning']);
    }

    public function testSavingAMandateThatDoesNotNameWhoCollectsWarns(): void
    {
        // Visitors would hand over their account details for a mandate their bank cannot collect on, not knowing for whom.
        $sepa = ['id' => 'dd', 'type' => 'directdebit', 'label' => 'Direct debit'];
        $bare = $this->saveForm([$sepa], [self::notification(['attach_pdf' => true])]);
        self::assertStringContainsString('does not name: Creditor name, Creditor identification number, Mandate reference.', $bare['data']['warning']);

        $grouped = $this->saveForm([['id' => 'g', 'type' => 'group', 'label' => 'Group', 'children' => [$sepa]]], [self::notification(['attach_pdf' => true])]);
        self::assertStringContainsString('does not name', $grouped['data']['warning'], 'inside a group too');

        $named = ['creditor_name' => 'Club e.V.', 'creditor_id' => 'DE98ZZZ09999999999', 'ref_mode' => 'generated'] + $sepa;
        self::assertSame('', $this->saveForm([$named], [self::notification(['attach_pdf' => true])])['data']['warning']);
    }

    public function testSavingAFormWhoseSignaturesReachNoRecipientWarns(): void
    {
        // A signature travels only inside the attached PDF or as an attached file; without either, the email only says
        // that one is present. The builder names the notifications that carry neither.
        $sig  = ['id' => 'sig', 'type' => 'signature', 'label' => 'Signature'];
        $bare = $this->saveForm([$sig], [self::notification(['name' => 'Office copy'])]);
        self::assertStringContainsString('only says a signature is present: Office copy.', $bare['data']['warning']);

        $grouped = $this->saveForm([['id' => 'g', 'type' => 'group', 'label' => 'Group', 'children' => [$sig]]], [self::notification(['name' => 'Office copy'])]);
        self::assertStringContainsString('Office copy', $grouped['data']['warning'], 'inside a group too');

        self::assertSame('', $this->saveForm([$sig], [self::notification(['attach_pdf' => true])])['data']['warning'], 'in the PDF');
        self::assertSame('', $this->saveForm([$sig], [self::notification(['attach_uploads' => true])])['data']['warning'], 'as a file');
        self::assertSame('', $this->saveForm([['id' => 'name', 'type' => 'text', 'label' => 'Name']], [self::notification()])['data']['warning'], 'no signature');

        update_option('fabricator_forms_pdf_layout', ['section_hidden' => ['signatures']]);
        self::assertStringContainsString('Office copy', $this->saveForm([$sig], [self::notification(['name' => 'Office copy', 'attach_pdf' => true])])['data']['warning'], 'the PDF hides signatures');
        update_option('fabricator_forms_pdf_layout', ['section_hidden' => ['fields']]);
        self::assertStringContainsString('Office copy', $this->saveForm([$sig], [self::notification(['name' => 'Office copy', 'attach_pdf' => true])])['data']['warning'], 'the PDF hides every field');
    }

    public function testAMandateWithoutWordingTellsTheAdminAndShowsVisitorsAPlainNotice(): void
    {
        $form = $this->createForm([['id' => 'dd', 'type' => 'directdebit', 'label' => 'Direct debit', 'scheme' => 'ach', 'ach_text' => '']], [self::notification()]);

        $asAdmin = FormRenderer::render($form);
        self::assertStringContainsString('enter the mandate text in this field', $asAdmin);

        wp_set_current_user(0);
        $asVisitor = FormRenderer::render($form);
        self::assertStringContainsString('Direct debits cannot be set up with this form at the moment.', $asVisitor);
        self::assertStringNotContainsString('field\'s settings', $asVisitor, 'nothing about settings a visitor cannot open');
        self::assertStringNotContainsString('name="dd[', $asVisitor, 'and no inputs for account details');
    }

    public function testThePageBreakButtonLabelsReachTheFrontEnd(): void
    {
        $form = $this->createForm([
            ['id' => 'a', 'type' => 'text', 'label' => 'A'],
            ['id' => 'pb', 'type' => 'pagebreak', 'label' => 'Page break', 'prev_btn' => 'Zurück, bitte', 'next_btn' => 'Weiter & fertig'],
            ['id' => 'b', 'type' => 'text', 'label' => 'B'],
        ], [self::notification()]);

        $html = FormRenderer::render($form);

        self::assertStringContainsString('>Weiter &amp; fertig</button>', $html);
        self::assertStringContainsString('>Zurück, bitte</button>', $html);
    }

    public function testTheImportPreviewListsWhereTheFormWouldSendSubmissions(): void
    {
        // A string pasted from elsewhere, in the export format (deflated JSON, base64url).
        $payload = [
            'v' => 2,
            't' => 'Offer $& $1 for $\'you\'',
            'f' => [['id' => 'email', 'type' => 'email', 'label' => 'Email']],
            'n' => [
                self::notification(['to' => 'owner@example.org', 'cc' => 'cc@example.org', 'bcc' => 'hidden@example.net', 'reply_to' => '{email}']),
                self::notification(['slug' => 'off', 'enabled' => false, 'to' => 'dormant@example.com']),
            ],
        ];
        $string = rtrim(strtr(base64_encode((string) gzdeflate((string) wp_json_encode($payload), 9)), '+/', '-_'), '=');

        $preview = $this->ajax('fabricator_forms_import', ['nonce' => wp_create_nonce('fabricator_forms_import'), 'string' => $string, 'preview' => '1']);

        self::assertTrue($preview['success'], wp_json_encode($preview));
        self::assertSame('Offer $& $1 for $\'you\'', $preview['data']['title'], 'the title as it is, for the page to show literally');
        $all = array_merge(...array_column($preview['data']['recipients'], 'recipients'));
        foreach (['owner@example.org', 'cc@example.org', 'hidden@example.net', '{email}', 'dormant@example.com'] as $address) {
            self::assertContains($address, $all, "$address is shown before importing");
        }
        self::assertSame([true, false], array_column($preview['data']['recipients'], 'enabled'));
        self::assertCount(0, FormModel::getAll(), 'a preview imports nothing');
    }

    public function testAMandateExportedFromAGermanSiteKeepsItsLegalTextOnAnEnglishOne(): void
    {
        // The builder stores the mandate with the wording the site showed: the German translation of the default text.
        $mo = dirname(__DIR__, 3) . '/languages/formfabricator-de_DE.mo';
        switch_to_locale('de_DE');
        self::assertTrue(load_textdomain('formfabricator', $mo, 'de_DE'));
        $german = (new \FabricatorForms\Fields\DirectDebitField())->getDefaultConfig();
        self::assertNotSame('Direct Debit Mandate', $german['label'], 'the German translation is loaded');
        $form = $this->createForm([['id' => 'dd', 'type' => 'directdebit', 'creditor_id' => 'DE98ZZZ09999999999'] + $german], [self::notification()]);
        $string = $this->export($form);

        restore_current_locale();
        unload_textdomain('formfabricator');
        self::assertSame('Direct Debit Mandate', (new \FabricatorForms\Fields\DirectDebitField())->getDefaultConfig()['label'], 'now an English site');
        $import = $this->ajax('fabricator_forms_import', ['nonce' => wp_create_nonce('fabricator_forms_import'), 'string' => $string]);

        self::assertTrue($import['success'], wp_json_encode($import));
        $source   = FormModel::get($form)->fields[0];
        $imported = FormModel::get((int) $import['data']['new_id'])->fields[0];
        $english  = (new \FabricatorForms\Fields\DirectDebitField())->getDefaultConfig();
        $changed  = 0;
        foreach (['mandate_title', 'mandate_text', 'mandate_note', 'label', 'iban_label', 'bic_label', 'holder_label', 'sig_label'] as $key) {
            self::assertSame($source[$key], $imported[$key], "$key arrives as the German site had it");
            $changed += $source[$key] !== $english[$key] ? 1 : 0;
        }
        self::assertGreaterThanOrEqual(4, $changed, 'most of that wording differs from the English defaults, so a swap would show');
    }

    /**
     * Saves a new form through the builder's AJAX action and returns the response.
     *
     * @param array<int, array<string, mixed>> $fields
     * @param array<int, array<string, mixed>> $notifications
     * @return array{success: bool, data: mixed}
     */
    private function saveForm(array $fields, array $notifications): array
    {
        $data = ['id' => 0, 'title' => 'Saved in the builder', 'fields' => $fields, 'notifications' => $notifications, 'settings' => [], 'snapshot' => ''];
        return $this->ajax('fabricator_forms_save_form', [
            'nonce'     => wp_create_nonce('fabricator_forms_admin_nonce'),
            'form_data' => base64_encode((string) wp_json_encode($data)),
        ]);
    }

    private function export(int $form): string
    {
        $r = $this->ajax('fabricator_forms_export', ['form_id' => (string) $form, 'nonce' => wp_create_nonce('fabricator_forms_export_' . $form)]);
        self::assertTrue($r['success'], wp_json_encode($r));
        return $r['data']['string'];
    }
}
