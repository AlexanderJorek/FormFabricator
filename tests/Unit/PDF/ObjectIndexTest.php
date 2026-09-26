<?php

namespace FabricatorForms\Tests\Unit\PDF;

use FabricatorForms\PDF\PdfUtils;
use FabricatorForms\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * PdfUtils::indexObjects() must return exactly what the preg_match_all() version it replaced returned.
 *
 * Speed and memory on the shapes that made the old version quadratic are covered in Perf\PdfScanPerfTest.
 */
final class ObjectIndexTest extends TestCase
{
    private const DICTS = [
        '<< /Type /Page /Parent 2 0 R >>',
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Length 12 /Filter /FlateDecode >>',
        '<< /Title (endobj inside a string) >>',
        '<< >>',
        '<</Type/Font/Subtype/Type1>>',
    ];

    public function testMatchesTheOldIndexOnGeneratedFiles(): void
    {
        mt_srand(20260920);
        $eols = ["\n", "\r\n", "\r"];
        for ($case = 0; $case < 800; $case++) {
            $pdf   = "%PDF-1.4\n";
            $count = mt_rand(1, 10);
            for ($i = 0; $i < $count; $i++) {
                $num  = mt_rand(0, 3) === 0 ? mt_rand(1, 3) : $i + 1; // sometimes reuse a number, so "last wins" matters
                $pdf .= $num . " 0 obj\n" . self::DICTS[mt_rand(0, count(self::DICTS) - 1)];
                if (mt_rand(0, 1)) {
                    // A stream object whose bytes sometimes contain the words the scan looks for.
                    $data = match (mt_rand(0, 3)) {
                        0       => gzcompress('BT /F1 12 Tf (hi) Tj ET'),
                        1       => 'plain bytes with endobj inside',
                        2       => str_repeat(chr(mt_rand(0, 255)), mt_rand(1, 60)),
                        default => '',
                    };
                    $pdf .= $eols[mt_rand(0, 2)] === "\r" ? "\nstream\n" : ' stream' . $eols[mt_rand(0, 1)];
                    $pdf .= $data . "\nendstream";
                }
                $pdf .= "\nendobj\n";
                if (mt_rand(0, 8) === 0) {
                    $pdf .= "% a comment mentioning 3 0 obj and endstream\n";
                }
            }
            if (mt_rand(0, 6) === 0) {
                $pdf .= "77 0 obj\n<< /Length 5 >>\nstream\nnever closed\n"; // no endstream, no endobj
            }
            $pdf .= "trailer\n<< /Size " . ($count + 1) . " >>\n%%EOF\n";

            self::assertSame(self::oldIndex($pdf), PdfUtils::indexObjects($pdf), "case $case");
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function edgeCases(): array
    {
        return [
            'no objects at all'           => ["%PDF-1.4\ntrailer\n<< >>\n%%EOF\n"],
            'object with no endobj'       => ["%PDF-1.4\n1 0 obj\n<< /A 1 >>\n"],
            'stream with no endstream'    => ["%PDF-1.4\n1 0 obj\n<< /L 3 >>\nstream\nabc\nendobj\n"],
            'two definitions, last wins'  => ["%PDF-1.4\n1 0 obj\n<< /V 1 >>\nendobj\n1 0 obj\n<< /V 2 >>\nendobj\n"],
            'digit-bounded number'        => ["%PDF-1.4\n112 0 obj\n<< /A 1 >>\nendobj\n12 0 obj\n<< /B 2 >>\nendobj\n"],
            'CRLF before the stream data' => ["%PDF-1.4\n1 0 obj\n<< /L 3 >>\r\nstream\r\nabc\nendstream\nendobj\n"],
            'endobj inside stream bytes'  => ["%PDF-1.4\n1 0 obj\n<< /L 9 >>\nstream\nxx endobj xx\nendstream\nendobj\n"],
            'leading zeros in the number' => ["%PDF-1.4\n007 0 obj\n<< /A 1 >>\nendobj\n"],
        ];
    }

    #[DataProvider('edgeCases')]
    public function testMatchesTheOldIndexOnEdgeCases(string $pdf): void
    {
        self::assertSame(self::oldIndex($pdf), PdfUtils::indexObjects($pdf));
    }

    public function testAReusedObjectNumberIndexesOnce(): void
    {
        $pdf = "%PDF-1.4\n" . str_repeat("1 0 obj\n<< /Type /Page >>\nendobj\n", 1000);
        self::assertCount(1, PdfUtils::indexObjects($pdf));
    }

    public function testAFilePastTheObjectCeilingIsRefused(): void
    {
        $this->expectException(\LengthException::class);
        PdfUtils::indexObjects(str_repeat("1 0 obj\n<<>>\nendobj\n", PdfUtils::MAX_OBJECTS + 1));
    }

    /**
     * indexObjects() as it stood before: preg_match_all() over the file, then a stream probe that scanned to EOF.
     *
     * @return array<int, array{dict: string, stream: ?string}>
     */
    private static function oldIndex(string $pdf_raw): array
    {
        $objects = [];
        if (!preg_match_all('/(?:^|[^0-9])(\d+)\s+0\s+obj\b/', $pdf_raw, $m, PREG_OFFSET_CAPTURE)) {
            return $objects;
        }
        $count = count($m[0]);
        for ($i = 0; $i < $count; $i++) {
            $start = $m[0][$i][1] + strlen($m[0][$i][0]);
            $end   = strpos($pdf_raw, 'endobj', $start);
            if ($end === false) {
                continue;
            }
            $record = ['dict' => substr($pdf_raw, $start, $end - $start), 'stream' => null];
            if (preg_match('/>>\s*stream(\r\n|\n|\r)/', $pdf_raw, $sm, PREG_OFFSET_CAPTURE, $start) === 1 && $sm[0][1] < $end) {
                $data_start = $sm[0][1] + strlen($sm[0][0]);
                $data_end   = strpos($pdf_raw, 'endstream', $data_start);
                if ($data_end !== false) {
                    $record['dict']   = substr($pdf_raw, $start, $sm[0][1] + 2 - $start);
                    $record['stream'] = substr($pdf_raw, $data_start, $data_end - $data_start);
                }
            }
            $objects[(int) $m[1][$i][0]] = $record;
        }
        return $objects;
    }
}
