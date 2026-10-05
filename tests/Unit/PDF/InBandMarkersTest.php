<?php

namespace FabricatorForms\Tests\Unit\PDF;

use FabricatorForms\Admin\Verificationpage;
use FabricatorForms\PDF\PdfUtils;
use FabricatorForms\Tests\Support\Reflect;
use FabricatorForms\Tests\Support\TestCase;

/**
 * The verifier reads structure from text and bytes that a submitter partly controls: an answer lands in the PDF's text,
 * an uploaded image's bytes in its streams. A submitter could type a field marker or a seal delimiter, or upload a JPEG
 * whose comment reads "%%EOF", and so make their own genuine PDF report "Modified", then deny it.
 */
final class InBandMarkersTest extends TestCase
{
    public function testMarkersAreFoundAsTypedAndInEveryFormTheVerifierFoldsTo(): void
    {
        self::assertSame('[FABRICATOR_PDF_', PdfUtils::reservedMarker('see [FABRICATOR_PDF_FIELD_END] here'));
        self::assertSame('---BEGIN-SEAL---', PdfUtils::reservedMarker('x---BEGIN-SEAL---y'));
        self::assertSame('---END-SEAL---', PdfUtils::reservedMarker('---END-SEAL---'));
        // Folded into markers only by the verifier's normalization: entities, control characters, full-width forms.
        self::assertSame('[FABRICATOR_PDF_', PdfUtils::reservedMarker('&#91;FABRICATOR_PDF_FIELD_END]'));
        self::assertSame('[FABRICATOR_PDF_', PdfUtils::reservedMarker("[FABRICATOR\x01_PDF_FIELD_END]"));
        if (class_exists('Normalizer')) {
            self::assertSame('[FABRICATOR_PDF_', PdfUtils::reservedMarker("\u{FF3B}FABRICATOR_PDF_FIELD_END\u{FF3D}"));
        }
        self::assertNull(PdfUtils::reservedMarker('Order #42 [urgent] --- see below; BEGIN SEAL'));
    }

    public function testBidiControlsAreStrippedAndTheMarksKept(): void
    {
        $controls = "\u{202A}\u{202B}\u{202C}\u{202D}\u{202E}\u{2066}\u{2067}\u{2068}\u{2069}";
        self::assertSame('ab', PdfUtils::stripBidiControls('a' . $controls . 'b'));
        self::assertSame("a\u{200E}\u{200F}\u{061C}b", PdfUtils::stripBidiControls("a\u{200E}\u{200F}\u{061C}b"));
        // Invalid UTF-8 around a control: scrubbed, and the control still goes.
        $stripped = PdfUtils::stripBidiControls("\xE2\u{202E}\x80x");
        self::assertTrue(mb_check_encoding($stripped, 'UTF-8'));
        self::assertStringNotContainsString("\u{202E}", $stripped);
        self::assertStringEndsWith('x', $stripped);
    }

    public function testImageDataIsLeftOutOfTheRawCounts(): void
    {
        // An image whose data holds every string the verifier counts, and "\nendstream" before its real end, with the
        // nested /DecodeParms dictionary mPDF writes for PNG images; then an uncompressed content stream that does carry an
        // injected seal, which must still count.
        $image = "\xFF\xD8comment: %%EOF ---BEGIN-SEAL---x---END-SEAL--- /Type /Evil\nendstream fake\xFF\xD9";
        $text  = 'BT (---BEGIN-SEAL---) Tj ET';
        $pdf   = "%PDF-1.4\n"
            . "1 0 obj\n<< /Type /XObject /Subtype /Image /DecodeParms << /Predictor 15 >> /Length " . strlen($image) . " >>\nstream\n"
            . $image . "\nendstream\nendobj\n"
            . "2 0 obj\n<< /Length " . strlen($text) . " >>\nstream\n" . $text . "\nendstream\nendobj\n"
            . "3 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n";

        self::assertSame(1, PdfUtils::countOutsideImageData($pdf, '%%EOF'), 'the real end of file only');
        self::assertSame(1, PdfUtils::countOutsideImageData($pdf, '---BEGIN-SEAL---'), 'the content stream, not the image');
        self::assertSame(2, substr_count($pdf, '%%EOF'), 'what the raw count saw');

        $types = array_column(Reflect::call(Verificationpage::class, 'distinctTypeNames', $pdf), 1);
        self::assertSame(['XObject', 'Catalog'], $types, 'no "/Type /Evil" from inside the image');
    }

    public function testObjectsInsideImageDataAreNoObjects(): void
    {
        // An uploaded image may hold "N 0 obj … endobj" of its own; read as the last definition of N, a fake catalog would
        // take over the page tree and the submitter's genuine PDF would fail. Every object reader skips image data alike.
        $fake  = "\nendobj\n1 0 obj\n<< /Type /Catalog /Pages 9 0 R >>\nendobj\n3 0 obj\n<< /Fake true >>\nendobj\n";
        $image = "\xFF\xD8" . $fake . "\xFF\xD9";
        $pdf   = "%PDF-1.4\n"
            . "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n"
            . "2 0 obj\n<< /Type /Pages /Kids [] /Count 0 >>\nendobj\n"
            . "3 0 obj\n<< /Type /XObject /Subtype /Image /Length " . strlen($image) . " >>\nstream\n" . $image . "\nendstream\nendobj\n"
            . "%%EOF\n";

        $objects = PdfUtils::indexObjects($pdf);
        self::assertSame([1, 2, 3], array_keys($objects));
        self::assertStringContainsString('/Pages 2 0 R', $objects[1]['dict']);
        self::assertStringContainsString('/Subtype /Image', $objects[3]['dict']);

        $index = PdfUtils::objectDefinitionIndex($pdf);
        self::assertSame([1, 2, 3], array_keys($index['by_num']));
        self::assertStringContainsString('/Pages 2 0 R', PdfUtils::definitionFromIndex($index, $pdf, '1')['body']);
        self::assertStringContainsString('/Pages 2 0 R', PdfUtils::lastObjectDefinition($pdf, '1')['body']);

        $walked = iterator_to_array(Reflect::call(Verificationpage::class, 'scanObjectBodies', $pdf), false);
        self::assertSame(['1 0', '2 0', '3 0'], array_column($walked, 0));
    }

    public function testHeadersInImageDataDoNotCountTowardsTheObjectCeiling(): void
    {
        // A JPEG comment repeating " obj" would otherwise get the submitter's own genuine PDF refused as too large.
        $image = "\xFF\xD8" . str_repeat(" obj\nobj", 500) . "\xFF\xD9";
        $pdf   = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n"
            . "2 0 obj\n<< /Subtype /Image /Length " . strlen($image) . " >>\nstream\n" . $image . "\nendstream\nendobj\n"
            . "3 0 obj\n<< /Length 5 >>\nstream\n7 obj\nendstream\nendobj\n%%EOF\n";

        self::assertSame(4, PdfUtils::declaredObjectCount($pdf), 'three headers, and the look-alike in a content stream');
    }

    public function testAnImageWithoutAUsableLengthEndsAtTheNextEndstream(): void
    {
        $pdf = "%PDF-1.4\n1 0 obj\n<< /Subtype /Image /Length 9 0 R >>\nstream\n%%EOF data\nendstream\nendobj\n%%EOF\n";
        self::assertSame(1, PdfUtils::countOutsideImageData($pdf, '%%EOF'));
        // Unterminated: everything after it is its data.
        self::assertSame(0, PdfUtils::countOutsideImageData("1 0 obj\n<< /Subtype /Image >>\nstream\n%%EOF", '%%EOF'));
    }
}
