<?php

namespace FabricatorForms\Tests\Unit\PDF;

use FabricatorForms\PDF\PdfUtils;
use FabricatorForms\Tests\Support\TestCase;

/**
 * PdfUtils::xrefConflicts(): a viewer finds objects through the cross-reference table, the verifier's checks take each
 * object's last definition. Every object whose two readings differ is named.
 */
final class XrefConflictsTest extends TestCase
{
    public function testAFileWhoseTableMatchesItsObjectsHasNone(): void
    {
        $pdf = self::pdf(["1 0 obj\n<< /Type /Catalog >>\nendobj\n", "2 0 obj\n<< /Length 3 >>\nstream\nabc\nendstream\nendobj\n"]);
        self::assertSame([], self::conflicts($pdf));
    }

    public function testASecondDefinitionTheTablePointsAtIsNamed(): void
    {
        // The forged copy first, the table pointing at it; the original last, which the checks read.
        $pdf = self::pdf(["1 0 obj\n<< /Type /Catalog >>\nendobj\n", "2 0 obj\n<< /Forged true >>\nendobj\n2 0 obj\n<< /Original true >>\nendobj\n"]);
        self::assertSame(['2'], self::conflicts($pdf));
    }

    public function testATableEntryPointingAtNoObjectIsNamed(): void
    {
        $pdf = self::pdf(["1 0 obj\n<< >>\nendobj\n"]);
        $pdf = (string) preg_replace('/^(\d{10})( 00000 n)/m', '0000000001$2', $pdf, 1);
        self::assertSame(['1'], self::conflicts($pdf));
    }

    public function testFreeEntriesAndSeveralSubsectionsAreRead(): void
    {
        $pdf    = "%PDF-1.4\n";
        $first  = strlen($pdf);
        $pdf   .= "1 0 obj\n<< >>\nendobj\n";
        $third  = strlen($pdf);
        $pdf   .= "3 0 obj\n<< >>\nendobj\n";
        $xrefAt = strlen($pdf);
        $pdf   .= "xref\n0 2\n0000000000 65535 f \n" . sprintf("%010d 00000 n \n", $first)
            . "3 1\n" . sprintf("%010d 00000 n\r\n", $third) . "trailer\n<< /Size 4 >>\nstartxref\n$xrefAt\n%%EOF\n";
        self::assertSame([], self::conflicts($pdf));
    }

    public function testAFileWithoutAReadableTableSaysSo(): void
    {
        self::assertSame(['unreadable'], self::conflicts("%PDF-1.4\n1 0 obj\n<< >>\nendobj\n%%EOF\n"));
        self::assertSame(['unreadable'], self::conflicts("%PDF-1.4\n1 0 obj\n<< >>\nendobj\nstartxref\n9\n%%EOF\n"));
        $pdf = self::pdf(["1 0 obj\n<< >>\nendobj\n"]);
        self::assertSame(['unreadable'], self::conflicts(str_replace(' 00000 n ', ' 0000 n  ', $pdf)), 'a malformed entry');
    }

    public function testATableListingMoreEntriesThanTheCeilingIsRefused(): void
    {
        $pdf = "%PDF-1.4\nxref\n0 " . (PdfUtils::MAX_OBJECTS + 1) . "\n" . str_repeat("0000000000 65535 f \n", PdfUtils::MAX_OBJECTS + 1)
            . "trailer\n<< >>\nstartxref\n9\n%%EOF\n";
        $this->expectException(\LengthException::class);
        self::conflicts($pdf);
    }

    /**
     * A PDF of $objects with a classic table whose entries point at each object number's FIRST header.
     *
     * @param string[] $objects
     */
    private static function pdf(array $objects): string
    {
        $pdf    = "%PDF-1.4\n" . implode('', $objects);
        preg_match_all('/(?<![0-9])(\d+) 0 obj/', $pdf, $m, PREG_OFFSET_CAPTURE);
        $first  = [];
        foreach ($m[1] as $i => [$num]) {
            $first[(int) $num] ??= $m[0][$i][1];
        }
        $size    = max(array_keys($first)) + 1;
        $entries = "0000000000 65535 f \n";
        for ($n = 1; $n < $size; $n++) {
            $entries .= isset($first[$n]) ? sprintf("%010d 00000 n \n", $first[$n]) : "0000000000 65535 f \n";
        }
        $xrefAt = strlen($pdf);
        return $pdf . "xref\n0 $size\n" . $entries . "trailer\n<< /Size $size >>\nstartxref\n$xrefAt\n%%EOF\n";
    }

    private static function conflicts(string $pdf): array
    {
        return PdfUtils::xrefConflicts($pdf, PdfUtils::objectDefinitionIndex($pdf));
    }
}
