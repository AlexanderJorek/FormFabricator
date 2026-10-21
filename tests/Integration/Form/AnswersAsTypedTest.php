<?php

namespace FabricatorForms\Tests\Integration\Form;

use FabricatorForms\Form\FormSanitizer;
use FabricatorForms\PDF\HashSeal;
use FabricatorForms\Tests\Integration\AjaxTestCase;

/**
 * An answer is recorded as the visitor typed it, trimmed of the spaces around it, or refused at its field: nothing in it
 * is removed or replaced (Utils\Text\AnswerText). Rules compare it as typed, as front.js reads it.
 */
final class AnswersAsTypedTest extends AjaxTestCase
{
    public function testAnswersReachTheMailAsTyped(): void
    {
        $form = $this->createForm([
            ['id' => 'site', 'type' => 'website', 'label' => 'Website'],
            ['id' => 'name', 'type' => 'text', 'label' => 'Name'],
            ['id' => 'msg', 'type' => 'textarea', 'label' => 'Message'],
            ['id' => 'box', 'type' => 'group', 'label' => 'Box', 'children' => [['id' => 'other', 'type' => 'text', 'label' => 'Other']]],
        ], [self::notification()]);

        $r = $this->submit($form, [
            'site'  => 'https://de.wikipedia.org/wiki/M%C3%BCnchen',
            'name'  => '  a<b and c>d  ',
            'msg'   => "20%AB off\n  indented",
            'other' => 'x <em>y</em> z',
        ]);

        self::assertTrue($r['success'], (string) wp_json_encode($r));
        $body = quoted_printable_decode((string) self::sentMail()[0]->body);
        self::assertStringContainsString('https://de.wikipedia.org/wiki/M%C3%BCnchen', $body, 'percent codes kept');
        self::assertStringContainsString('a&lt;b and c&gt;d', $body, 'trimmed, otherwise as typed, escaped in the HTML part');
        self::assertStringContainsString('20%AB off', $body);
        self::assertStringContainsString('x &lt;em&gt;y&lt;/em&gt; z', $body, 'a group child too');
    }

    public function testWhatCantBeRecordedAsTypedIsRefusedAtItsField(): void
    {
        $form = $this->createForm([
            ['id' => 'name', 'type' => 'text', 'label' => 'Name'],
            ['id' => 'msg', 'type' => 'textarea', 'label' => 'Message'],
        ], [self::notification()]);

        $refused = [
            ['name' => "Ada\u{200B}Lovelace"],
            ['name' => "Ada\u{00AD}Lovelace"],
            ['name' => "Ada\nLovelace"],
            ['name' => "Ada\tLovelace"],
            ['name' => 'Smith &amp; Co'],
            ['name' => 'caf&#233;'],
            ['msg' => "line\u{2028}break"],
            ['msg' => "bell\u{0007}"],
            ['name' => "\xC3\x28"],
        ];
        foreach ($refused as $values) {
            $r = $this->submit($form, $values);
            self::assertFalse($r['success'], (string) wp_json_encode($values));
            self::assertArrayHasKey((string) array_key_first($values), (array) ($r['data']['errors'] ?? []), (string) wp_json_encode($r));
        }
        self::assertSame([], self::sentMail(), 'nothing was sent');

        self::assertTrue($this->submit($form, ['name' => 'AT&T R&D', 'msg' => "two\r\nlines\tand a tab"])['success']);
    }

    public function testCharactersThePdfCantShowAreRefusedOnlyWhereAPdfIsMade(): void
    {
        $visitor = get_current_user_id();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        if (HashSeal::activeKeyProblem() !== '') {
            HashSeal::createInitialKey();
        }
        wp_set_current_user($visitor);
        $fields = [['id' => 'name', 'type' => 'text', 'label' => 'Name']];
        $no_pdf = $this->createForm($fields, [self::notification()]);
        $pdf    = $this->createForm($fields, [self::notification(['attach_pdf' => true])]);

        self::assertTrue($this->submit($no_pdf, ['name' => 'Ada 🎀'])['success'], 'the mail shows it');

        $r = $this->submit($pdf, ['name' => 'Ada 🎀']);
        self::assertFalse($r['success']);
        self::assertStringContainsString('U+1F380', (string) ($r['data']['errors']['name'] ?? ''));

        self::assertTrue($this->submit($pdf, ['name' => 'Grüße, Ελλάδα, ℊ'])['success'], 'the shipped fonts draw these');
    }

    public function testAnOptionSavedInTheBuilderMatchesWhatTheFormPosts(): void
    {
        // sanitize_text_field() writes a lone "<" as "&lt;"; the form shows and posts "<", the answer as typed.
        $fields = FormSanitizer::sanitizeFields([
            ['id' => 'size', 'type' => 'radio', 'label' => 'Size', 'options' => [['label' => 'a < b', 'value' => 'a < b'], ['label' => 'Tom & Jerry', 'value' => 'Tom &amp; Jerry']]],
            ['id' => 'note', 'type' => 'text', 'label' => 'Note', 'required' => true, 'conditions' => [
                'action' => 'show', 'match' => 'all', 'rules' => [['field_id' => 'size', 'operator' => 'equals', 'value' => 'a < b']],
            ]],
        ]);
        $form = $this->createForm($fields, [self::notification()]);

        $shown = $this->submit($form, ['size' => 'a < b', 'note' => '']);
        self::assertArrayNotHasKey('size', (array) ($shown['data']['errors'] ?? []), (string) wp_json_encode($shown));
        self::assertArrayHasKey('note', (array) ($shown['data']['errors'] ?? []), 'the rule matched: the note shows');

        self::assertTrue($this->submit($form, ['size' => 'Tom & Jerry'])['success']);
    }

    public function testARuleReadsTheAnswerAsTyped(): void
    {
        $form = $this->createForm([
            ['id' => 'site', 'type' => 'website', 'label' => 'Website'],
            ['id' => 'note', 'type' => 'text', 'label' => 'Note', 'required' => true, 'conditions' => [
                'action' => 'show', 'match' => 'all', 'rules' => [['field_id' => 'site', 'operator' => 'contains', 'value' => 'M%C3%BC']],
            ]],
        ], [self::notification()]);

        $r = $this->submit($form, ['site' => 'https://x.org/wiki/M%C3%BCnchen', 'note' => '']);

        self::assertFalse($r['success'], 'the note shows and is required');
        self::assertArrayHasKey('note', (array) ($r['data']['errors'] ?? []), (string) wp_json_encode($r));
    }
}
