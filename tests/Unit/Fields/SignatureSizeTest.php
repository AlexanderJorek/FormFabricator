<?php

namespace FabricatorForms\Tests\Unit\Fields;

use FabricatorForms\Fields\DirectDebitField;
use FabricatorForms\Fields\SignatureField;
use FabricatorForms\Tests\Support\FieldStubs;
use FabricatorForms\Tests\Support\TestCase;

/**
 * A signature is decoded into the PDF outside the upload budget, so one declaring any size it liked was decoded
 * unbounded or silently left out of the PDF while the submission counted as signed. It is refused instead.
 */
final class SignatureSizeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FieldStubs::install();
    }

    public function testASignatureDeclaringAHugeSizeIsRefused(): void
    {
        $huge = self::dataUri(self::pngHeader(20000, 20000));

        self::assertIsString((new SignatureField())->validate($huge, ['export_format' => 'png']));
        self::assertIsString((new DirectDebitField())->validate(self::sepa($huge), []));
    }

    public function testAnImageWithoutAReadableSizeIsRefused(): void
    {
        $unreadable = self::dataUri("\x89PNG\r\n\x1a\n" . str_repeat("\0", 40));

        self::assertIsString((new SignatureField())->validate($unreadable, ['export_format' => 'png']));
    }

    public function testASignaturePadImageIsAccepted(): void
    {
        $im = imagecreatetruecolor(1600, 400); // a wide canvas at a pixel ratio of 2
        ob_start();
        imagepng($im);
        $pad = self::dataUri((string) ob_get_clean());

        self::assertTrue((new SignatureField())->validate($pad, ['export_format' => 'png']));
        self::assertTrue((new DirectDebitField())->validate(self::sepa($pad), []));
    }

    /**
     * A PNG signature and IHDR chunk declaring $w × $h, and nothing else: all getimagesizefromstring() reads.
     */
    private static function pngHeader(int $w, int $h): string
    {
        $ihdr = pack('NN', $w, $h) . "\x08\x02\x00\x00\x00";
        return "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . $ihdr . pack('N', crc32('IHDR' . $ihdr));
    }

    private static function dataUri(string $binary): string
    {
        return 'data:image/png;base64,' . base64_encode($binary);
    }

    /**
     * @return array<string, string>
     */
    private static function sepa(string $sig): array
    {
        return ['iban' => 'DE89370400440532013000', 'bic' => 'COBADEFFXXX', 'holder' => 'Ada Lovelace', 'sig' => $sig];
    }

    public function testANameTypedInsteadOfDrawnSignsAndIsRecordedAsText(): void
    {
        // For anyone who cannot draw with a mouse, finger or pen (WCAG 2.1.1): sealed and shown as text, marked as typed.
        $field = new SignatureField();
        self::assertTrue($field->validate('Ada Lovelace', ['required' => true]));
        $entry = $field->mapNormalized('sg', 'Signature', 'Ada Lovelace', [], [])['sg'];
        self::assertSame(['label' => 'Signature', 'type' => 'text', 'value' => 'Ada Lovelace (signed by typing the name)'], $entry);
        self::assertSame('Ada Lovelace (signed by typing the name)', $field->map('Ada Lovelace', []));

        $mandate = (new DirectDebitField())->mapNormalized(
            'dd',
            'Mandate',
            ['iban' => 'DE89370400440532013000', 'bic' => '', 'holder' => 'Ada Lovelace', 'sig' => 'Ada Lovelace'],
            [],
            []
        );
        self::assertSame(['label' => 'Signature', 'type' => 'directdebit', 'value' => 'Ada Lovelace (signed by typing the name)'], $mandate['dd_sig']);
        self::assertTrue((new DirectDebitField())->validate(
            ['iban' => 'DE89370400440532013000', 'bic' => '', 'holder' => 'Ada Lovelace', 'sig' => 'Ada Lovelace'],
            ['required' => true]
        ));
    }
}
