<?php

namespace FabricatorForms\Tests\Unit\PDF;

use FabricatorForms\PDF\PdfUtils;
use FabricatorForms\Tests\Support\TestCase;

/**
 * The single-pass stream scans against the strpos-per-stream oracle below, with one deliberate difference (in
 * nextStreamKeyword()): the "stream" inside a stray "endstream" opens nothing. The generated files include stray
 * "endstream"s to exercise it. Speed and memory are in Perf\PdfScanPerfTest.
 */
final class StreamScanTest extends TestCase
{
    private const PAYLOADS = [
        'BT /F1 12 Tf 72 712 Td (Hello) Tj ET',  // content stream
        'q 1 0 0 1 0 0 cm /Im1 Do Q',            // content stream
        "\x00\x01\x02binary image bytes\xff",    // not a content stream
        'ToUnicode CMap-ish text without operators',
        '',
    ];

    public function testHashAllCompressedStreamsMatchesTheOldScanOnGeneratedFiles(): void
    {
        foreach (self::generatedFiles() as $case => $pdf) {
            self::assertSame(self::oldAll($pdf), PdfUtils::hashAllCompressedStreams($pdf), "case $case");
        }
    }

    public function testHashPageContentStreamsMatchesTheOldScanOnGeneratedFiles(): void
    {
        foreach (self::generatedFiles() as $case => $pdf) {
            self::assertSame(self::oldContent($pdf), PdfUtils::hashPageContentStreams($pdf, 2), "case $case");
        }
    }

    public function testManyStreamsWithoutCrlfAndAnUnclosedTailMatchTheOldScan(): void
    {
        $pdf = "%PDF-1.4\n";
        for ($i = 0; $i < 2000; $i++) {
            $data = gzcompress('BT /F1 12 Tf 72 712 Td (Page ' . $i . ') Tj ET');
            $pdf .= ($i + 1) . " 0 obj\n<< /Length " . strlen($data) . " >>\nstream\n" . $data . "\nendstream\nendobj\n";
        }
        $pdf .= "9999 0 obj\n<< /Length 999 >>\nstream\n" . str_repeat('x', 200000) . "\n";

        self::assertSame(self::oldAll($pdf), PdfUtils::hashAllCompressedStreams($pdf));
    }

    public function testStreamKeywordsWithoutEndstreamMatchTheOldScan(): void
    {
        $data = gzcompress('BT /F1 12 Tf 72 712 Td (Hi) Tj ET');
        $pdf  = "%PDF-1.4\n1 0 obj\n<< /Length " . strlen($data) . " >>\nstream\n" . $data . "\nendstream\nendobj\n"
            . "2 0 obj\n<< /Length 600000 >>\n" . str_repeat("stream\n", 2000) . "\n";

        self::assertSame(self::oldAll($pdf), PdfUtils::hashAllCompressedStreams($pdf));
    }

    public function testAStrayEndstreamOpensNoStream(): void
    {
        // The two shapes the old loops misread, as measured in review (old found 0 and 1, the walk 1 and 0).
        $data   = gzcompress('BT /F1 12 Tf 72 712 Td (Hi) Tj ET');
        $before = "%PDF-1.4\nendstream\n1 0 obj\nstream\n" . $data . "\nendstream\nendobj\n";
        self::assertCount(1, PdfUtils::hashPageContentStreams($before, 2), 'a stray endstream before a real stream');

        $loose = "%PDF-1.4\n% something endstream\n" . $data . "\nendstream\n";
        self::assertSame([], PdfUtils::hashPageContentStreams($loose, 2), 'loose text ending in endstream opens nothing');
    }

    public function testAFileOfBareKeywordsHasNothingToInflate(): void
    {
        self::assertSame(0, PdfUtils::inflatedStreamBytes(str_repeat("stream\n", 10000), PHP_INT_MAX));
    }

    public function testBodiesThatAreNotZlibYieldNoHashes(): void
    {
        $pdf = "%PDF-1.4\n";
        for ($obj = 1; $obj <= 500; $obj++) {
            $pdf .= $obj . " 0 obj\n<< /Length 4 >>\nstream\nAAAA\nendstream\nendobj\n";
        }
        self::assertSame([], PdfUtils::hashAllCompressedStreams($pdf));
    }

    /**
     * 600 seeded random files: compressed, raw-deflated, plain and random bodies, both EOL styles, loose text that
     * mentions "endstream", and sometimes a truncated final object.
     *
     * @return \Generator<int, string>
     */
    private static function generatedFiles(): \Generator
    {
        mt_srand(20260917);
        $eols = ["\n", "\r\n"];
        for ($case = 0; $case < 600; $case++) {
            $pdf   = "%PDF-1.4\n";
            $count = mt_rand(1, 12);
            for ($i = 0; $i < $count; $i++) {
                $plain = self::PAYLOADS[mt_rand(0, count(self::PAYLOADS) - 1)];
                $data  = match (mt_rand(0, 3)) {
                    0       => gzcompress($plain),
                    1       => gzdeflate($plain),
                    2       => $plain,
                    default => self::randomBytes(mt_rand(1, 40)),
                };
                $eol  = $eols[mt_rand(0, 1)];
                $pdf .= ($i + 1) . " 0 obj\n<< /Length " . strlen($data) . " >>\nstream" . $eol . $data . "\nendstream\nendobj\n";
                if (mt_rand(0, 6) === 0) {
                    $pdf .= "% a stream of consciousness, endstream included\n";
                }
                // Stray "endstream"s directly followed by a line break, the shape the old loops misread: alone, and
                // with a compressed body after it.
                if (mt_rand(0, 9) === 0) {
                    $pdf .= "endstream\n";
                }
                if (mt_rand(0, 9) === 0) {
                    $pdf .= "% loose endstream\n" . gzcompress($plain) . "\nendstream\n";
                }
            }
            if (mt_rand(0, 8) === 0) {
                $pdf .= "99 0 obj\n<< /Length 10 >>\nstream\nabcdefghij\n";
            }
            $pdf .= "trailer\n<< /Size " . ($count + 1) . " >>\n%%EOF\n";
            yield $case => $pdf;
        }
    }

    /**
     * Seeded bytes (random_bytes() would make a failing case impossible to reproduce).
     */
    private static function randomBytes(int $length): string
    {
        $bytes = '';
        for ($i = 0; $i < $length; $i++) {
            $bytes .= chr(mt_rand(0, 255));
        }
        return $bytes;
    }

    /**
     * hashAllCompressedStreams() as it stood before the single-pass walk.
     *
     * @return string[]
     */
    private static function oldAll(string $pdf_raw): array
    {
        $hashes = [];
        $offset = 0;
        while (true) {
            $pos = self::nextStreamKeyword($pdf_raw, $offset);
            if ($pos === false) {
                break;
            }
            $eol = (substr($pdf_raw, $pos + 6, 2) === "\r\n") ? 2 : 1;
            $bs  = $pos + 6 + $eol;
            $be  = strpos($pdf_raw, 'endstream', $bs);
            if ($be === false) {
                $offset = $bs;
                continue;
            }
            $body   = substr($pdf_raw, $bs, $be - $bs);
            $offset = $be + 9;
            if (strlen($body) > 67108864) {
                continue;
            }
            $dec = PdfUtils::inflateEither($body);
            if (is_string($dec) && !PdfUtils::looksLikeContentStream($dec)) {
                $hashes[] = hash('sha256', $dec);
            }
        }
        sort($hashes);
        return $hashes;
    }

    /**
     * hashPageContentStreams() as it stood before the single-pass walk.
     *
     * @return string[]
     */
    private static function oldContent(string $pdf_raw): array
    {
        $hashes = [];
        $offset = 0;
        while (true) {
            $pos = self::nextStreamKeyword($pdf_raw, $offset);
            if ($pos === false) {
                break;
            }
            $eol = (substr($pdf_raw, $pos + 6, 2) === "\r\n") ? 2 : 1;
            $bs  = $pos + 6 + $eol;
            $be  = strpos($pdf_raw, 'endstream', $bs);
            if ($be === false) {
                $offset = $pos + 7;
                continue;
            }
            $body   = substr($pdf_raw, $bs, $be - $bs);
            $offset = $be + 9;
            if (strlen($body) > 67108864) {
                continue;
            }
            $dec = PdfUtils::inflateEither($body, 2);
            if (is_string($dec) && PdfUtils::looksLikeContentStream($dec)) {
                $hashes[] = hash('sha256', $dec);
            }
        }
        sort($hashes);
        return $hashes;
    }

    /**
     * The old keyword search, plus the one deliberate difference (see the class docblock): a "stream" that ends an
     * "endstream" is skipped.
     */
    private static function nextStreamKeyword(string $pdf_raw, int $offset): int|false
    {
        while (true) {
            $crlf = strpos($pdf_raw, "stream\r\n", $offset);
            $lf   = strpos($pdf_raw, "stream\n", $offset);
            $pos  = $crlf === false ? $lf : (($lf !== false && $lf < $crlf) ? $lf : $crlf);
            if ($pos === false || substr($pdf_raw, max(0, $pos - 3), 3) !== 'end') {
                return $pos;
            }
            $offset = $pos + 6;
        }
    }
}
