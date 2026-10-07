<?php

namespace FabricatorForms\Tests\Unit\PDF;

use FabricatorForms\PDF\PdfUtils;
use FabricatorForms\Tests\Support\TestCase;

/**
 * PdfUtils::linkTarget() and the dictionary reader under it: a link exactly as mPDF writes one (an invisible click
 * area) has its target; anything a viewer would draw has none, however its dictionary is spelled.
 */
final class LinkTargetTest extends TestCase
{
    private const MPDF_LINK = "<</Type /Annot /Subtype /Link /Rect [104.659 536.259 210.402 523.455] /NM (0001-0000) /M (D:20261006094202)"
        . " /Border [0 0 0] /A <</S /URI /URI (mailto:info@club.example)>> >>\n";

    /**
     * @return iterable<string, array{0: string, 1: string|null}>
     */
    public static function annotations(): iterable
    {
        yield 'mPDF mailto link' => [self::MPDF_LINK, 'mailto:info@club.example'];
        yield 'mPDF web link' => [str_replace('mailto:info@club.example', 'https://club.example/terms?a=(1)', self::MPDF_LINK), 'https://club.example/terms?a=(1)'];
        yield 'URI as a hex string' => [str_replace('(mailto:info@club.example)', '<6D61696C746F3A6140622E6578>', self::MPDF_LINK), 'mailto:a@b.ex'];
        yield 'no border key' => [str_replace(' /Border [0 0 0]', '', self::MPDF_LINK), 'mailto:info@club.example'];
        yield 'in-document destination' => ['<< /Type /Annot /Subtype /Link /Rect [0 0 9 9] /Border [0 0 0] /Dest [3 0 R /XYZ 0 800 0] >>', '#'];
        yield 'GoTo action' => ['<< /Type /Annot /Subtype /Link /Rect [0 0 9 9] /A << /S /GoTo /D [3 0 R /Fit] >> >>', '#'];
        yield 'with a page reference' => [str_replace('/NM', '/P 3 0 R /NM', self::MPDF_LINK), 'mailto:info@club.example'];
        yield 'FreeText box' => ['<< /Type /Annot /Subtype /FreeText /Rect [40 600 560 760] /Contents (Ada Lovelace) /DA (/Helv 60 Tf) /IC [1 1 1] >>', null];
        yield 'FreeText spelling /Subtype /Link in a string' => ['<< /Type /Annot /Contents (/Subtype /Link) /Subtype /FreeText /A << /S /URI /URI (mailto:info@club.example) >> >>', null];
        yield 'link spelled first, FreeText last' => ['<< /Type /Annot /Subtype /Link /Subtype /FreeText /A << /S /URI /URI (mailto:info@club.example) >> >>', null];
        yield 'FreeText hidden in a nested dictionary' => ['<< /Type /Annot /X << /Subtype /Link >> /Subtype /FreeText /A << /S /URI /URI (x) >> >>', null];
        yield 'link with an appearance stream' => [str_replace('/Border', '/AP << /N 9 0 R >> /Border', self::MPDF_LINK), null];
        yield 'link with a visible border' => [str_replace('[0 0 0]', '[0 0 9]', self::MPDF_LINK), null];
        yield 'link with a border style' => [str_replace('/Border [0 0 0]', '/BS << /W 40 >>', self::MPDF_LINK), null];
        yield 'link running a script' => ['<< /Type /Annot /Subtype /Link /A << /S /JavaScript /JS (app.alert(1)) >> >>', null];
        yield 'link without any target' => ['<< /Type /Annot /Subtype /Link /Rect [0 0 9 9] >>', null];
        yield 'another type than Annot' => [str_replace('/Type /Annot', '/Type /XObject', self::MPDF_LINK), null];
        yield 'unterminated dictionary' => ['<< /Type /Annot /Subtype /Link /A << /S /URI /URI (mailto:x) >>', null];
        yield 'unterminated string' => ['<< /Type /Annot /Subtype /Link /A << /S /URI /URI (mailto:x >> >>', null];
        yield 'mismatched brackets' => ['<< /Type /Annot /Subtype /Link /Rect [0 0 9 9>> /A << /S /URI /URI (x) >> >>', null];
        yield 'no dictionary' => ['/Subtype /Link', null];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('annotations')]
    public function testOnlyAnInvisibleLinkHasATarget(string $body, ?string $expected): void
    {
        self::assertSame($expected, PdfUtils::linkTarget($body));
    }

    /**
     * linkAddress() reads where any link leads, drawn or not, so a sealed link drawn visibly is told from an added one;
     * what isn't a link with an address or a destination still has none.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('annotations')]
    public function testAnyLinkHasAnAddressHoweverItIsDrawn(string $body, ?string $expected): void
    {
        $drawn = ['link with an appearance stream', 'link with a visible border', 'link with a border style'];
        $name  = (string) $this->dataName();
        self::assertSame(in_array($name, $drawn, true) ? 'mailto:info@club.example' : $expected, PdfUtils::linkAddress($body));
    }

    public function testEntriesAreReadAsWrittenWithTheLastKeyWinning(): void
    {
        $entries = PdfUtils::dictEntries("<< /A 1 /B (x \\) /C y) % /D (comment)\n /E [1 [2] <<\n/F (])>>] /G 3 0 R /A /Name /H <0F> >>");
        self::assertSame(['A' => '/Name', 'B' => '(x \\) /C y)', 'E' => "[1 [2] <<\n/F (])>>]", 'G' => '3 0 R', 'H' => '<0F>'], $entries);
    }

    public function testTheGeneratorsListHoldsEveryLinkOnceSorted(): void
    {
        $pdf = "%PDF-1.4\n5 0 obj\n" . self::MPDF_LINK . "endobj\n"
            . "6 0 obj\n" . str_replace('mailto:info@club.example', 'https://a.example', self::MPDF_LINK) . "endobj\n"
            . "7 0 obj\n<< /Type /Annot /Subtype /FreeText /Contents (x) >>\nendobj\n"
            . "8 0 obj\n<< /Type /Page /Annots [5 0 R 6 0 R] >>\nendobj\n%%EOF\n";
        self::assertSame(['https://a.example', 'mailto:info@club.example'], PdfUtils::linkTargets($pdf));
    }
}
