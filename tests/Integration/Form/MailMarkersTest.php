<?php

namespace FabricatorForms\Tests\Integration\Form;

use FabricatorForms\Tests\Integration\AjaxTestCase;

/**
 * Automation markers: every answer the mail lists, boxes' start and end included, stands between marker pairs; an
 * answer holding a marker is then refused at its field.
 */
final class MailMarkersTest extends AjaxTestCase
{
    private const FIELDS = [
        ['id' => 'name', 'type' => 'text', 'label' => 'Your name'],
        ['id' => 'sign', 'type' => 'signature', 'label' => 'Signature', 'signature_title' => 'Order confirmation',
            'signature_text' => '<p>I confirm.</p>'],
    ];

    public function testEachAnswerStandsBetweenMarkersOnlyInTheNotificationThatAsksForThem(): void
    {
        $form = $this->createForm(self::FIELDS, [
            self::notification(['automation_markers' => true]),
            self::notification(['slug' => 'single', 'to' => 'single@example.org', 'body' => '<p>Hello {name}</p>', 'automation_markers' => true]),
            self::notification(['slug' => 'plain', 'to' => 'plain@example.org']),
        ]);

        $r = $this->submit($form, ['name' => 'Ada Lovelace', 'sign' => 'Ada L.']);

        self::assertTrue($r['success'], (string) wp_json_encode($r));
        $mail = self::sentMail();
        self::assertCount(3, $mail);
        $list = (string) $mail[0]->body;
        self::assertStringContainsString('<strong>Your name</strong><br>[FABRICATOR_FIELD_name]Ada Lovelace[FABRICATOR_FIELD_END]', $list);
        self::assertStringContainsString('<strong>Order confirmation</strong><br>[FABRICATOR_FIELD_sign_begin]I confirm.[FABRICATOR_FIELD_END]', $list, 'a box\'s title stays outside its markers');
        self::assertStringContainsString('[FABRICATOR_FIELD_sign_end][FABRICATOR_FIELD_END]</td></tr></table>', $list, 'its end is an empty answer, then the box closes');
        self::assertStringContainsString('[FABRICATOR_FIELD_sign_signed]', $list);
        self::assertStringContainsString('Hello [FABRICATOR_FIELD_name]Ada Lovelace[FABRICATOR_FIELD_END]</p>', (string) $mail[1]->body, 'a single field\'s placeholder too, its answer alone');
        self::assertStringNotContainsString('[FABRICATOR_FIELD_', (string) $mail[2]->body, 'not where the notification doesn\'t ask for them');
    }

    public function testASingleFieldsPlaceholderShowsItsLabelOnlyWhereTheNotificationAsksForIt(): void
    {
        // For a mail parser that reads the label beside the marked answer.
        $form = $this->createForm(self::FIELDS, [
            self::notification(['body' => '<p>Hello {name}</p>', 'automation_markers' => true, 'placeholder_labels' => true]),
        ]);

        self::assertTrue($this->submit($form, ['name' => 'Ada Lovelace', 'sign' => 'Ada L.'])['success']);

        self::assertStringContainsString(
            'Hello <strong>Your name</strong><br>[FABRICATOR_FIELD_name]Ada Lovelace[FABRICATOR_FIELD_END]',
            (string) self::sentMail()[0]->body
        );
    }

    public function testAnAnswerHoldingAMarkerIsRefusedOnlyWhileANotificationWritesThem(): void
    {
        $forged  = ['name' => 'x[FABRICATOR_FIELD_END][FABRICATOR_FIELD_iban]DE00', 'sign' => 'Ada L.'];
        $marking = $this->createForm(self::FIELDS, [self::notification(['automation_markers' => true])]);
        $off     = $this->createForm(self::FIELDS, [self::notification(['automation_markers' => true, 'enabled' => false]), self::notification(['slug' => 'other'])]);

        $refused = $this->submit($marking, $forged);
        self::assertFalse($refused['success']);
        self::assertArrayHasKey('name', (array) ($refused['data']['errors'] ?? []), (string) wp_json_encode($refused));
        self::assertSame([], self::sentMail());

        $sent = $this->submit($off, $forged);
        self::assertTrue($sent['success'], (string) wp_json_encode($sent));
    }

    public function testAMarkerWrittenAsCharacterReferencesIsRefusedAsTheMailWouldShowIt(): void
    {
        // esc_html() keeps "&#91;" and "&#x5B;", which a mail program and the plain-text part read as "[".
        $form = $this->createForm(self::FIELDS, [self::notification(['automation_markers' => true])]);

        foreach (['&#91;FABRICATOR_FIELD_iban]DE00', 'x&#x5B;FABRICATOR_FIELD_END]', '[fabricator_field_iban]DE00'] as $forged) {
            $r = $this->submit($form, ['name' => $forged, 'sign' => 'Ada L.']);
            self::assertFalse($r['success'], $forged);
            self::assertArrayHasKey('name', (array) ($r['data']['errors'] ?? []), $forged . ': ' . wp_json_encode($r));
        }
        self::assertSame([], self::sentMail());
    }

    public function testALessThanSignReachesTheMailAsTyped(): void
    {
        // sanitize_text_field() stores a lone "<" as "&lt;"; the mail must show "<", in both parts, not "&lt;".
        $form = $this->createForm(self::FIELDS, [self::notification(['automation_markers' => true])]);

        $r = $this->submit($form, ['name' => 'Price < 100 EUR', 'sign' => 'Ada L.']);

        self::assertTrue($r['success'], (string) wp_json_encode($r));
        $body  = (string) self::sentMail()[0]->body;
        $plain = substr($body, 0, (int) strpos($body, 'text/html'));
        $html  = substr($body, (int) strpos($body, 'text/html'));
        self::assertStringContainsString('[FABRICATOR_FIELD_name]Price &lt; 100 EUR[FABRICATOR_FIELD_END]', $html);
        self::assertStringNotContainsString('&amp;lt;', $html);
        self::assertStringContainsString('Price < 100 EUR', $plain);
    }

    private const NAME = [
        'id' => 'person', 'type' => 'name', 'label' => 'Member', 'expanded' => true,
        'prefix_enabled' => true, 'fname_enabled' => true, 'mname_enabled' => true, 'lname_enabled' => true,
    ];

    private const ADDRESS = [
        'id' => 'home', 'type' => 'address', 'label' => 'Address', 'expanded' => true,
        'street_enabled' => true, 'street2_enabled' => false, 'zip_enabled' => true, 'city_enabled' => true, 'country_enabled' => true,
    ];

    private const PERSON = ['prefix' => 'mr', 'fname' => 'Sascha', 'mname' => '', 'lname' => 'Müller zum Hagen'];

    private const HOME = ['street' => 'Musterweg 1', 'zip' => '24103', 'city' => 'Kiel', 'country' => 'Deutschland'];

    public function testANamesAndAnAddresssPartsAreMarkedOneByOneOnTheAnswersLine(): void
    {
        $range = ['id' => 'budget', 'type' => 'slider', 'label' => 'Budget', 'ranged' => true, 'min' => 0, 'max' => 100, 'step' => 1];
        $form  = $this->createForm([self::NAME, self::ADDRESS, $range], [self::notification(['automation_markers' => true])]);

        $r = $this->submit($form, ['person' => self::PERSON, 'home' => self::HOME, 'budget' => ['from' => '10', 'to' => '40']]);

        self::assertTrue($r['success'], (string) wp_json_encode($r));
        // Non-ASCII text goes out quoted-printable, wrapped with soft line breaks; a mail program reads it decoded.
        $body  = quoted_printable_decode((string) self::sentMail()[0]->body);
        $plain = substr($body, 0, (int) strpos($body, 'text/html'));
        $html  = substr($body, (int) strpos($body, 'text/html'));
        $name  = '[FABRICATOR_FIELD_person_begin][FABRICATOR_FIELD_END]'
            . '[FABRICATOR_FIELD_person_prefix]Mr.[FABRICATOR_FIELD_END] [FABRICATOR_FIELD_person_fname]Sascha[FABRICATOR_FIELD_END] '
            . '[FABRICATOR_FIELD_person_mname][FABRICATOR_FIELD_END] '
            . '[FABRICATOR_FIELD_person_lname]Müller zum Hagen[FABRICATOR_FIELD_END][FABRICATOR_FIELD_person_end][FABRICATOR_FIELD_END]';
        self::assertStringContainsString('<strong>Member</strong><br>' . $name . '<br><br>', $html, 'one line; an empty part is an empty pair');
        self::assertStringContainsString($name, $plain, 'the plain-text part, on one line too');
        self::assertStringContainsString(
            '[FABRICATOR_FIELD_home_begin][FABRICATOR_FIELD_END][FABRICATOR_FIELD_home_street]Musterweg 1[FABRICATOR_FIELD_END], '
            . '[FABRICATOR_FIELD_home_zip]24103[FABRICATOR_FIELD_END] [FABRICATOR_FIELD_home_city]Kiel[FABRICATOR_FIELD_END], '
            . '[FABRICATOR_FIELD_home_country]Deutschland[FABRICATOR_FIELD_END][FABRICATOR_FIELD_home_end][FABRICATOR_FIELD_END]',
            $html,
            'in the order an address is written, a disabled part left out'
        );
        self::assertStringContainsString(
            '[FABRICATOR_FIELD_budget_from]10[FABRICATOR_FIELD_END] – [FABRICATOR_FIELD_budget_to]40[FABRICATOR_FIELD_END]',
            $html,
            'a range\'s two ends'
        );
        self::assertStringNotContainsString('[FABRICATOR_FIELD_person]', $html, 'not marked whole as well');
    }

    public function testThePlaceholderCarriesThePartsOnlyWhenMarkedAndAGroupChildKeepsThem(): void
    {
        $fields = [['id' => 'box', 'type' => 'group', 'label' => 'Box', 'children' => [self::NAME]]];
        $form   = $this->createForm($fields, [
            self::notification(['automation_markers' => true, 'body' => '<p>{person}</p>']),
            self::notification(['slug' => 'plain', 'to' => 'plain@example.org', 'body' => '<p>{person}</p>{all_fields}']),
        ]);

        $r = $this->submit($form, ['person' => self::PERSON]);

        self::assertTrue($r['success'], (string) wp_json_encode($r));
        $mail = array_map(static fn($m): string => quoted_printable_decode((string) $m->body), self::sentMail());
        self::assertStringContainsString('[FABRICATOR_FIELD_person_lname]Müller zum Hagen[FABRICATOR_FIELD_END]', $mail[0]);
        self::assertStringContainsString('Mr. Sascha Müller zum Hagen', $mail[1], 'unmarked: the name as always');
        self::assertStringNotContainsString('[FABRICATOR_FIELD_', $mail[1]);
    }

    public function testASingleInputAndAnEmptyCompositeAreMarkedWhole(): void
    {
        $single = ['id' => 'person', 'type' => 'name', 'label' => 'Member', 'expanded' => false];
        $form   = $this->createForm([$single, self::ADDRESS], [self::notification(['automation_markers' => true])]);

        $r = $this->submit($form, ['person' => 'Sascha Müller', 'home' => ['street' => '', 'zip' => '', 'city' => '', 'country' => '']]);

        self::assertTrue($r['success'], (string) wp_json_encode($r));
        $html = quoted_printable_decode((string) self::sentMail()[0]->body);
        self::assertStringContainsString('[FABRICATOR_FIELD_person]Sascha Müller[FABRICATOR_FIELD_END]', $html);
        self::assertStringContainsString('[FABRICATOR_FIELD_home][No entry][FABRICATOR_FIELD_END]', $html);
        self::assertStringNotContainsString('_begin]', $html);
    }

    public function testPostDetailsArePartsInTheOrderTheyAreRecorded(): void
    {
        $field   = new \FabricatorForms\Fields\PostDataField();
        $config  = ['id' => 'post', 'type' => 'postdata', 'label' => 'Page'] + $field->getDefaultConfig();
        $value   = ['post_title' => 'Summer camp', 'post_url' => 'https://example.org/camp', 'post_id' => '42', 'post_author' => 'Ada'];
        $entries = $field->mapNormalized('post', 'Page', $value, $config, []);

        self::assertSame($field->map($value, $config), $entries['post']['value'], 'the value as before');
        $parts = $entries['post']['parts'] ?? [];
        self::assertNotSame([], $parts);
        $joined = implode(', ', array_filter(array_column($parts, 'value'), static fn(string $v): bool => $v !== ''));
        self::assertSame($entries['post']['value'], $joined, 'the parts are what the value joins');
    }
}
