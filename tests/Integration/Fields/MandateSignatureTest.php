<?php

namespace FabricatorForms\Tests\Integration\Fields;

use FabricatorForms\Fields\FieldRegistry;
use FabricatorForms\Tests\Integration\TestCase;

/**
 * A mandate signed by drawing reports its signature in the mail as a Signature field does: "[Signature present]", with
 * the image for the PDF beside it. An empty line in the mail reads as an unsigned mandate.
 */
final class MandateSignatureTest extends TestCase
{
    public function testADrawnMandateSignatureIsReportedAsPresent(): void
    {
        $im = imagecreatetruecolor(40, 20);
        imagefill($im, 0, 0, (int) imagecolorallocate($im, 255, 255, 255));
        imageline($im, 2, 10, 38, 12, (int) imagecolorallocate($im, 0, 0, 0));
        ob_start();
        imagepng($im);
        $png = 'data:image/png;base64,' . base64_encode((string) ob_get_clean());

        $field  = ['id' => 'dd', 'type' => 'directdebit', 'label' => 'Direct debit'];
        $mapped = FieldRegistry::mapSubmission([$field], ['dd' => ['iban' => 'DE89370400440532013000', 'holder' => 'Ada', 'sig' => $png]], [], []);
        self::assertSame('[Signature present]', $mapped['dd_sig']['value']);
        self::assertSame('signature', $mapped['dd_sig']['type']);
        self::assertCount(1, $mapped['dd_sig']['materialized_files'] ?? [], 'the image the PDF draws');

        $unsigned = FieldRegistry::mapSubmission([$field], ['dd' => ['iban' => 'DE89370400440532013000', 'holder' => 'Ada', 'sig' => '']], [], []);
        self::assertSame('[No entry]', $unsigned['dd_sig']['value']);
    }
}
