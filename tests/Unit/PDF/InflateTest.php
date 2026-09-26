<?php

namespace FabricatorForms\Tests\Unit\PDF;

use FabricatorForms\PDF\PdfUtils;
use FabricatorForms\Tests\Support\TestCase;

/**
 * PdfUtils::inflateWithin() must answer exactly as gzuncompress()/gzinflate() do, only with a size cap; and
 * inflatedStreamBytes() must count what the streams really inflate to. Decompression bombs are in Perf\InflateBombTest.
 */
final class InflateTest extends TestCase
{
    public function testMatchesPhpInflateOnValidAndDamagedInput(): void
    {
        mt_srand(20260927);
        for ($n = 0; $n < 3000; $n++) {
            $plain = mt_rand(0, 1)
                ? (mt_rand(0, 9) ? self::bytes(mt_rand(1, 20000)) : '')
                : str_repeat(self::bytes(mt_rand(1, 4)), mt_rand(0, 50000));
            $z = gzcompress($plain, mt_rand(0, 9));
            $d = gzdeflate($plain, mt_rand(0, 9));
            self::assertSame($plain, PdfUtils::inflateWithin($z), "zlib round trip $n");
            self::assertSame($plain, PdfUtils::inflateWithin($d, PdfUtils::INFLATE_STREAM_CAP, true), "deflate round trip $n");

            $mid      = intdiv(strlen($z), 2);
            $variants = [
                'truncated' => substr($z, 0, mt_rand(0, max(0, strlen($z) - 1))),
                'garbage'   => self::bytes(mt_rand(1, 300)),
                'trailing'  => $z . self::bytes(mt_rand(1, 50)),
                'flipped'   => strlen($z) > 3 ? substr_replace($z, chr(ord($z[$mid]) ^ 0x5a), $mid, 1) : $z,
            ];
            foreach ($variants as $name => $data) {
                self::assertSame(self::quiet(static fn() => gzuncompress($data)), PdfUtils::inflateWithin($data), "zlib $name $n");
                self::assertSame(
                    self::quiet(static fn() => gzinflate($data)),
                    PdfUtils::inflateWithin($data, PdfUtils::INFLATE_STREAM_CAP, true),
                    "deflate $name $n"
                );
            }
            if (strlen($plain) > 10) {
                self::assertNull(PdfUtils::inflateWithin($z, strlen($plain) - 1), "cap below size $n");
                self::assertSame($plain, PdfUtils::inflateWithin($z, strlen($plain)), "cap at size $n");
            }
        }
    }

    public function testInflatedStreamBytesCountsWhatTheStreamsInflateTo(): void
    {
        mt_srand(20260928);
        $cap = PdfUtils::INFLATE_STREAM_CAP;
        for ($n = 0; $n < 300; $n++) {
            $raw    = "%PDF-1.4\n";
            $expect = 0;
            $datas  = [];
            for ($s = mt_rand(0, 5); $s > 0; $s--) {
                $kind    = mt_rand(0, 3);
                $plain   = $kind === 0 ? self::bytes(mt_rand(0, 50000)) : str_repeat(chr(mt_rand(65, 90)), mt_rand(0, 400000));
                $data    = gzcompress($plain);
                $datas[] = $data;
                if ($kind === 2) {
                    $data    = self::bytes(200); // not zlib at all: counts nothing
                    $datas[] = $data;
                    $raw    .= "1 0 obj\n<< /Filter /FlateDecode >>\nstream\n" . $data . "\nendstream\nendobj\n";
                    continue;
                }
                $dict = match ($kind) {
                    3       => '<< /Length ' . strlen($data) . ' >>', // no filter named: counted all the same
                    1       => '<< /Filter /FlateDecode /DecodeParms << /Predictor 15 >> /Length ' . strlen($data) . ' >>',
                    default => '<< /Filter /FlateDecode /Length ' . strlen($data) . ' >>',
                };
                $raw    .= "1 0 obj\n" . $dict . "\nstream\n" . $data . "\nendstream\nendobj\n";
                $expect += min(strlen($plain), $cap);
            }
            // A body whose compressed bytes contain "\nendstream" can't be delimited by any scanner; out of scope here.
            if (!array_filter($datas, static fn($d) => str_contains($d, "\nendstream"))) {
                self::assertSame($expect, PdfUtils::inflatedStreamBytes($raw, PHP_INT_MAX), "case $n");
            }
        }
    }

    public function testNestedDecodeParmsDictionaryIsCounted(): void
    {
        $png = gzcompress(str_repeat("\x00\xff\x10", 100000));
        $raw = "1 0 obj\n<< /Type /XObject /Subtype /Image /Filter /FlateDecode /DecodeParms << /Predictor 15 /Columns 3 >> /Length "
            . strlen($png) . " >>\nstream\n" . $png . "\nendstream\nendobj\n";
        self::assertSame(300000, PdfUtils::inflatedStreamBytes($raw, PHP_INT_MAX));
    }

    private static function bytes(int $length): string
    {
        $bytes = '';
        for ($i = 0; $i < $length; $i++) {
            $bytes .= chr(mt_rand(0, 255));
        }
        return $bytes;
    }

    /**
     * PHP's inflate functions warn on bad data; the comparison is about their return value.
     */
    private static function quiet(callable $fn): mixed
    {
        set_error_handler(static fn() => true);
        try {
            return $fn();
        } finally {
            restore_error_handler();
        }
    }
}
