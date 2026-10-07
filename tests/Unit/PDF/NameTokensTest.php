<?php

namespace FabricatorForms\Tests\Unit\PDF;

use FabricatorForms\PDF\PdfUtils;
use FabricatorForms\Tests\Support\TestCase;

/**
 * PdfUtils::nameTokens() and escapedName(): the names a viewer reads from the file's syntax, never text inside a
 * string, a comment or stream data; and a name spelled with "#xx" escapes, which a viewer decodes and the checks don't.
 */
final class NameTokensTest extends TestCase
{
    public function testOnlyNamesOutsideStringsCommentsAndStreamDataAreRead(): void
    {
        $image = "/Fake#41 (endstream) \nendstream /Hidden";
        $pdf   = "%PDF-1.4\n%\xE2\xE3\xCF\xD3 /InHeaderComment\n"
            . "1 0 obj\n<< /Type /Annot /URI (https://club.example/Annots \\) /StillString) /H <2F41> % /InComment\n"
            . " /A [/B << /C 1 >>] >>\nendobj\n"
            . "2 0 obj\n<< /Subtype /Image /Length " . strlen($image) . " >>\nstream\n" . $image . "\nendstream\nendobj\n"
            . "trailer\n<< /Root 1 0 R >>\n%%EOF\n";

        self::assertSame(
            ['Type', 'Annot', 'URI', 'H', 'A', 'B', 'C', 'Subtype', 'Image', 'Length', 'Root'],
            array_column(iterator_to_array(PdfUtils::nameTokens($pdf), false), 0)
        );
    }

    public function testEachNameEndsWhereItsValueBegins(): void
    {
        $pdf = '<< /Annots[3 0 R] /Kids 4 0 R >>';
        [$annots, $kids] = iterator_to_array(PdfUtils::nameTokens($pdf), false);

        self::assertSame(['Annots', strpos($pdf, '[')], $annots);
        self::assertSame(['Kids', strpos($pdf, ' 4 0 R')], $kids);
    }

    public function testStreamDataEndsAtTheNextEndstreamWhenTheLengthIsWrong(): void
    {
        $pdf = "1 0 obj\n<< /Length 999 >>\nstream\n/Inside\nendstream\nendobj\n2 0 obj\n<< /After 1 >>\nendobj\n";
        self::assertSame(['Length', 'After'], array_column(iterator_to_array(PdfUtils::nameTokens($pdf), false), 0));
    }

    public function testAnUnterminatedStreamOrStringEndsTheWalk(): void
    {
        self::assertSame(['Length'], array_column(iterator_to_array(PdfUtils::nameTokens("<< /Length 9 >>\nstream\n/Never"), false), 0));
        self::assertSame(['A'], array_column(iterator_to_array(PdfUtils::nameTokens('<< /A (open /Never >>'), false), 0));
    }

    /**
     * @return iterable<string, array{0: string, 1: string|null}>
     */
    public static function files(): iterable
    {
        yield 'plain names' => ["<< /Type /Page /Annots [3 0 R] >>\n", null];
        yield 'escaped key' => ["<< /Type /Page /Ann#6Fts [3 0 R] >>\n", 'Ann#6Fts'];
        yield 'escaped value' => ["<< /Type /Ann#6Ft /Subtype /FreeText >>\n", 'Ann#6Ft'];
        yield 'escape inside a string' => ["<< /URI (https://club.example/a#b /X#41) >>\n", null];
        yield 'escape inside a comment' => ["<< /A 1 % /X#41\n>>\n", null];
        yield 'escape inside stream data' => ["<< /Length 6 >>\nstream\n/X#41 \nendstream\n", null];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('files')]
    public function testAnEscapedNameIsFoundOnlyWhereAViewerReadsIt(string $pdf, ?string $expected): void
    {
        self::assertSame($expected, PdfUtils::escapedName($pdf));
    }
}
