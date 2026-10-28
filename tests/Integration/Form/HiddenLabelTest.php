<?php

namespace FabricatorForms\Tests\Integration\Form;

use FabricatorForms\Tests\Integration\AjaxTestCase;

/**
 * "Hide label" hides a field's label in the notification too, for every field type and inside a group: the answer is
 * listed without it, never left out. The PDF side is in SealRoundTripTest, which also verifies such a PDF.
 */
final class HiddenLabelTest extends AjaxTestCase
{
    public function testAHiddenLabelLeavesOnlyTheAnswerInTheMail(): void
    {
        $fields = [
            ['id' => 'name', 'type' => 'text', 'label' => 'Your full name', 'hide_label' => true],
            ['id' => 'email', 'type' => 'email', 'label' => 'Email', 'hide_label' => false],
            ['id' => 'box', 'type' => 'group', 'label' => 'Box', 'children' => [
                ['id' => 'note', 'type' => 'textarea', 'label' => 'Delivery wishes', 'hide_label' => true],
            ]],
        ];
        $form = $this->createForm($fields, [
            self::notification(),
            self::notification(['slug' => 'copy', 'to' => 'copy@example.org', 'body' => '<p>{name} / {note}</p>', 'subject' => 'Copy {all_fields}']),
        ]);

        $r = $this->submit($form, ['name' => 'Ada Lovelace', 'email' => 'ada@example.net', 'note' => 'Monday, please.']);

        self::assertTrue($r['success'], (string) wp_json_encode($r));
        $mail = self::sentMail();
        self::assertCount(2, $mail);
        $list = (string) $mail[0]->body;
        self::assertStringContainsString('Ada Lovelace<br><br>', $list, 'the answer is listed');
        self::assertStringContainsString('Monday, please.<br><br>', $list, 'a group child\'s answer is listed');
        self::assertStringNotContainsString('Your full name', $list, 'its label is not');
        self::assertStringNotContainsString('Delivery wishes', $list, 'nor the group child\'s');
        self::assertStringContainsString('<strong>Email</strong>', $list, 'a label that isn\'t hidden stays');

        // A single field's placeholder, and the plain-text field list in a subject, follow the same rule.
        self::assertStringContainsString('Ada Lovelace / Monday, please.', (string) $mail[1]->body);
        self::assertStringNotContainsString('Your full name', (string) $mail[1]->subject);
        self::assertStringContainsString('Ada Lovelace', (string) $mail[1]->subject);
        self::assertStringContainsString('Email: ada@example.net', (string) $mail[1]->subject);
    }

    public function testAFieldsPlaceholderIsItsAnswerAloneWhateverItsLabel(): void
    {
        // The placeholder stands in the admin's own sentence: no label, no line breaks around it.
        $form = $this->createForm(
            [['id' => 'name-1', 'type' => 'text', 'label' => 'Name']],
            [self::notification(['body' => '<p>Hallo {name-1},</p>'])]
        );

        self::assertTrue($this->submit($form, ['name-1' => 'Alexander Jorek'])['success']);

        $body = (string) self::sentMail()[0]->body;
        self::assertStringContainsString('<p>Hallo Alexander Jorek,</p>', $body);
        self::assertStringNotContainsString('<strong>Name</strong>', $body);
    }
}
