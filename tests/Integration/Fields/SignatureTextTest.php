<?php

namespace FabricatorForms\Tests\Integration\Fields;

use FabricatorForms\Tests\Integration\AjaxTestCase;

/**
 * A Signature field with a text and a place of signing: shown on the page, the place required once signed, and all of
 * it recorded in the mail's titled box. The sealed PDF is checked in SealRoundTripTest.
 */
final class SignatureTextTest extends AjaxTestCase
{
    private const FIELD = [
        'id'              => 'sign',
        'type'            => 'signature',
        'label'           => 'Signature',
        'signature_title' => 'Order confirmation',
        'signature_text'   => '<p>I confirm the details above.</p><p>All of them.</p>',
        'signature_note'  => 'Keep a copy.',
        'signing_place'   => true,
        'place_label'     => 'Signed in:',
    ];

    public function testTheMailRecordsTheTextTheSignatureWhenAndWhereInABox(): void
    {
        $form = $this->createForm([self::FIELD], [self::notification()]);

        $r = $this->submit($form, ['sign' => 'Ada Lovelace', 'sign-place' => 'Berlin']);

        self::assertTrue($r['success'], (string) wp_json_encode($r));
        // The HTML part: the plain-text alternative before it holds the same words.
        $body  = substr((string) self::sentMail()[0]->body, (int) strpos((string) self::sentMail()[0]->body, 'text/html'));
        $order = array_map(static fn(string $needle): int|false => strpos($body, $needle), [
            '<table role="presentation"', '<strong>Order confirmation</strong>', 'I confirm the details above.', 'Keep a copy.',
            '<strong>Signature</strong>', '<strong>Signed on</strong>', '<strong>Signed in:</strong><br>Berlin', '</td></tr></table>',
        ]);
        self::assertNotContains(false, $order, $body);
        $sorted = $order;
        sort($sorted);
        self::assertSame($sorted, $order, 'text, signature, time and place, in order, in a box the title heads');
        self::assertStringNotContainsString('End of', $body, 'the box ends where the box ends; no line says so');
    }

    public function testASignatureWithoutThePlaceIsRefusedAndAnUnsignedOptionalOneRecordsNothingAroundIt(): void
    {
        $form = $this->createForm([self::FIELD], [self::notification()]);

        $refused = $this->submit($form, ['sign' => 'Ada Lovelace', 'sign-place' => '  ']);
        self::assertFalse($refused['success']);
        self::assertStringContainsString('Signed in is a required field.', (string) wp_json_encode($refused['data']), 'named by the label');

        reset_phpmailer_instance();
        $unsigned = $this->submit($form, ['sign' => '', 'sign-place' => 'Berlin']);
        self::assertTrue($unsigned['success'], (string) wp_json_encode($unsigned));
        $body = (string) self::sentMail()[0]->body;
        self::assertStringContainsString('[No entry]', $body);
        foreach (['Order confirmation', 'Berlin', 'Signed on'] as $absent) {
            self::assertStringNotContainsString($absent, $body, 'no text, place or time around a signature not given');
        }
    }

    public function testWithoutATitleOrTextTheLabelHeadsTheBoxAndTheSignatureIsNotNamedTwice(): void
    {
        $plain = ['id' => 'sign', 'type' => 'signature', 'label' => 'Membership signature'];
        $form  = $this->createForm([$plain], [self::notification()]);

        $r = $this->submit($form, ['sign' => 'Ada Lovelace', 'sign-place' => 'Berlin']);

        self::assertTrue($r['success'], (string) wp_json_encode($r));
        $body = (string) self::sentMail()[0]->body;
        $body = substr($body, (int) strpos($body, 'text/html'));
        self::assertStringContainsString('<table role="presentation"', $body, 'boxed like the mandate');
        self::assertSame(1, substr_count($body, 'Membership signature'), 'the label is the box\'s title, not repeated inside');
        self::assertStringContainsString('<strong>Signed on</strong>', $body);
        self::assertStringContainsString('</td></tr></table>', $body);
        self::assertStringNotContainsString('Berlin', $body, 'no place asked, none recorded');
        self::assertStringNotContainsString('End of', $body);
    }

    public function testEverySignatureIsBoxedOnThePage(): void
    {
        $handler = new \FabricatorForms\Fields\SignatureField();
        $html    = $handler->render(['id' => 'sign', 'type' => 'signature', 'label' => 'Signature'], 'sign');
        self::assertStringContainsString('fabricator-signature--boxed', $html);
        self::assertStringNotContainsString('fabricator-signature-texts', $html, 'nothing set, nothing above the pad');

        $titled = $handler->render(['id' => 'sign', 'type' => 'signature', 'label' => 'Signature', 'signature_title' => 'Membership'], 'sign');
        self::assertStringContainsString('<h3 class="fabricator-signature-title">Membership</h3>', $titled, 'a title alone shows');
    }
}
