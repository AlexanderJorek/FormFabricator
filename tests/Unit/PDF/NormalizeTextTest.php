<?php

namespace FabricatorForms\Tests\Unit\PDF;

use FabricatorForms\PDF\PdfUtils;
use FabricatorForms\Tests\Support\TestCase;

/**
 * PdfUtils::normalizeText(), the verifier's reading of the PDF's text and of the seal's values: a soft hyphen or a
 * no-break space reads as a space, and every other character stays, including those whose UTF-8 holds the byte 0xAD
 * ("í" is C3 AD).
 */
final class NormalizeTextTest extends TestCase
{
    public function testCharactersHoldingTheSoftHyphensByteStay(): void
    {
        // "í" is C3 AD, "ŭ" C5 AD, "ح" D8 AD, "ꭣ" EA AD A3.
        self::assertSame('Martínez Ŭ ح ꭣ', PdfUtils::normalizeText('Martínez Ŭ ح ꭣ'));
    }

    public function testSoftHyphensAndNoBreakSpacesReadAsSpaces(): void
    {
        self::assertSame('a b c', PdfUtils::normalizeText("a\u{00AD}b\u{00A0}c"));
    }

    public function testFieldMarkersAreFoundInTextWithSuchCharacters(): void
    {
        $text = PdfUtils::normalizeText('[FABRICATOR_PDF_FIELD_field_name]Martínez[FABRICATOR_PDF_FIELD_END]');
        self::assertCount(1, PdfUtils::fieldMarkerMatches($text));
    }
}
