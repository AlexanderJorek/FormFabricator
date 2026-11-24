<?php

namespace FabricatorForms\Tests\Integration\Form;

use FabricatorForms\Form\FormSanitizer;
use FabricatorForms\PDF\HashSeal;
use FabricatorForms\Tests\Integration\AjaxTestCase;

/**
 * An answer is recorded as the visitor typed it, trimmed of the spaces around it, or refused at its field: nothing in it
 * is removed or replaced (Utils\Text\AnswerText), although FormProcessor reads every answer through
 * sanitize_textarea_field(). Rules compare it as typed, as front.js reads it.
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
            'site'  => 'https://de.wikipedia.org/wiki/München',
            'name'  => '  Müller & Söhne "Café"  ',
            'msg'   => "50 % off, 100%ige\n  indented  twice",
            'other' => "O'Brien & Co",
        ]);

        self::assertTrue($r['success'], (string) wp_json_encode($r));
        $body = quoted_printable_decode((string) self::sentMail()[0]->body);
        self::assertStringContainsString('https://de.wikipedia.org/wiki/München', $body);
        self::assertStringContainsString('Müller &amp; Söhne &quot;Café&quot;', $body, 'trimmed, otherwise as typed, escaped in the HTML part');
        self::assertStringContainsString('50 % off, 100%ige', $body);
        self::assertStringContainsString('indented  twice', $body, 'spaces inside kept');
        self::assertStringContainsString('O&#039;Brien &amp; Co', $body, 'a group child too');
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
        ];
        foreach ($refused as $values) {
            $r = $this->submit($form, $values);
            self::assertFalse($r['success'], (string) wp_json_encode($values));
            self::assertArrayHasKey((string) array_key_first($values), (array) ($r['data']['errors'] ?? []), (string) wp_json_encode($r));
        }
        self::assertSame([], self::sentMail(), 'nothing was sent');

        self::assertTrue($this->submit($form, ['name' => 'AT&T R&D', 'msg' => "two\r\nlines\tand a tab"])['success']);
    }

    public function testARequestThatSkipsTheBrowserHasItsAnswerSanitized(): void
    {
        // Only a request that skips front.js, which refuses these, can send them; the sanitizer then changes the answer,
        // and bytes that are no UTF-8 empty it.
        $form = $this->createForm([
            ['id' => 'name', 'type' => 'text', 'label' => 'Name'],
            ['id' => 'msg', 'type' => 'textarea', 'label' => 'Message'],
        ], [self::notification()]);

        // What the sanitizer leaves of these ("3 &lt; 5", ">") is still refused at the field.
        foreach (['3 < 5', 'a > b'] as $typed) {
            $r = $this->submit($form, ['name' => $typed]);
            self::assertArrayHasKey('name', (array) ($r['data']['errors'] ?? []), $typed . ': ' . wp_json_encode($r));
        }
        self::assertTrue($this->submit($form, ['name' => 'a<b and c>d', 'msg' => '20%AB off'])['success']);
        $body = quoted_printable_decode((string) self::sentMail()[0]->body);
        self::assertStringNotContainsString('a<b', $body);
        self::assertStringNotContainsString('%AB', $body);
        self::assertTrue($this->submit($form, ['name' => "\xC3\x28", 'msg' => 'Hello'])['success']);
        self::assertStringNotContainsString("\xC3\x28", (string) self::sentMail()[1]->body);
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

        // The zero-width non-joiner and joiner are typed: a Persian keyboard writes the one, a family emoji holds the other.
        self::assertTrue($this->submit($no_pdf, ['name' => "می\u{200C}خواهم 👨\u{200D}👩\u{200D}👧"])['success'], 'the joiners are typed');
    }

    public function testThePdfFontsAreReadOnlyOnceTheCaptchaPassed(): void
    {
        // Reading the fonts loads mPDF: a visitor without a solved CAPTCHA never gets that far.
        $form = $this->createForm(
            [['id' => 'name', 'type' => 'text', 'label' => 'Name'], ['id' => 'cap', 'type' => 'captcha', 'label' => 'CAPTCHA', 'required' => true, 'provider' => 'altcha']],
            [self::notification(['attach_pdf' => true])]
        );

        $r = $this->submit($form, ['name' => 'Ada 🎀', 'cap' => 'not-a-solution']);

        self::assertFalse($r['success']);
        self::assertArrayHasKey('cap', (array) ($r['data']['errors'] ?? []), (string) wp_json_encode($r));
        self::assertArrayNotHasKey('name', (array) ($r['data']['errors'] ?? []), 'the fonts were not read');
    }

    public function testEveryAnswerTheCheckAcceptsPassesTheSanitizerUnchanged(): void
    {
        // What makes reading the answers through sanitize_textarea_field() safe: it leaves alone every answer
        // AnswerText lets through, so a visitor's answer is never changed, only refused (in the browser first).
        mt_srand(20261110);
        $alphabet = array_merge(
            str_split(" abcXYZ019&%<>\"'=/;:!?.,-_()[]{}#*+\n\t\r"),
            ['ä', 'ß', '€', '漢', '😀', 'é', "\u{200D}", 'ـ', "\u{00A0}"]
        );
        $changed  = [];
        $accepted = 0;
        for ($i = 0; $i < 20000; $i++) {
            $text = '';
            for ($j = mt_rand(1, 30); $j > 0; $j--) {
                $text .= $alphabet[mt_rand(0, count($alphabet) - 1)];
            }
            $text = trim($text);
            if (\FabricatorForms\Utils\Text\AnswerText::problem($text, true) !== null) {
                continue;
            }
            $accepted++;
            if (sanitize_textarea_field($text) !== $text) {
                $changed[] = $text;
            }
        }
        self::assertGreaterThan(1000, $accepted, 'the sample reaches the case');
        self::assertSame([], array_slice($changed, 0, 5));
    }

    public function testAnOptionSavedInTheBuilderMatchesWhatTheFormPosts(): void
    {
        // sanitize_text_field() writes "&" as "&amp;"; the form shows and posts "&". "<", ">" and a percent code can't
        // be posted unchanged, so they are left out of the saved value.
        $fields = FormSanitizer::sanitizeFields([
            ['id' => 'size', 'type' => 'radio', 'label' => 'Size', 'options' => [['label' => 'a < b', 'value' => 'a < b%20'], ['label' => 'Tom & Jerry', 'value' => 'Tom &amp; Jerry']]],
            ['id' => 'note', 'type' => 'text', 'label' => 'Note', 'required' => true, 'conditions' => [
                'action' => 'show', 'match' => 'all', 'rules' => [['field_id' => 'size', 'operator' => 'equals', 'value' => 'a < b']],
            ]],
        ]);
        self::assertSame('a  b', $fields[0]['options'][0]['value']);
        self::assertSame('a  b', $fields[1]['conditions']['rules'][0]['value']);
        $form = $this->createForm($fields, [self::notification()]);

        $shown = $this->submit($form, ['size' => 'a  b', 'note' => '']);
        self::assertArrayNotHasKey('size', (array) ($shown['data']['errors'] ?? []), (string) wp_json_encode($shown));
        self::assertArrayHasKey('note', (array) ($shown['data']['errors'] ?? []), 'the rule matched: the note shows');

        self::assertTrue($this->submit($form, ['size' => 'Tom & Jerry'])['success']);
    }

    public function testARuleReadsTheAnswerAsTyped(): void
    {
        $form = $this->createForm([
            ['id' => 'site', 'type' => 'website', 'label' => 'Website'],
            ['id' => 'note', 'type' => 'text', 'label' => 'Note', 'required' => true, 'conditions' => [
                'action' => 'show', 'match' => 'all', 'rules' => [['field_id' => 'site', 'operator' => 'contains', 'value' => 'München']],
            ]],
        ], [self::notification()]);

        $r = $this->submit($form, ['site' => 'https://x.org/wiki/München', 'note' => '']);

        self::assertFalse($r['success'], 'the note shows and is required');
        self::assertArrayHasKey('note', (array) ($r['data']['errors'] ?? []), (string) wp_json_encode($r));
    }
}
