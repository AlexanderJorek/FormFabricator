<?php

namespace FabricatorForms\Tests\Perf;

use FabricatorForms\Admin\Verificationpage;
use FabricatorForms\PDF\PdfUtils;
use FabricatorForms\Tests\Support\Measure;
use FabricatorForms\Tests\Support\PdfFixtures;
use FabricatorForms\Tests\Support\Reflect;
use FabricatorForms\Tests\Support\PerfTestCase;
use PHPUnit\Framework\Attributes\RequiresFunction;

/**
 * The pathological shapes behind CLAUDE.md's "Scanning untrusted PDF bytes": every scan over uploaded bytes must stay
 * linear in time and must not hold memory in proportion to the number of matches.
 *
 * Time is asserted as a growth exponent (see Measure): linear scans come out near 1, the quadratic bugs this suite
 * exists for near 2. MAX_EXPONENT sits between the two.
 */
#[RequiresFunction('memory_reset_peak_usage')]
final class PdfScanPerfTest extends PerfTestCase
{
    private const MAX_EXPONENT = 1.5;
    private const MB           = 1048576;

    protected function setUp(): void
    {
        parent::setUp();
        // Compiling the verifier's 4,500-line class costs ~12 MB; done here, it never lands inside a measured peak.
        class_exists(Verificationpage::class);
    }

    // ---- stream scans ----

    public function testStreamKeywordsWithoutEndstreamScaleLinearly(): void
    {
        $build = static function (int $n): string {
            $data = gzcompress('BT /F1 12 Tf 72 712 Td (Hi) Tj ET');
            return "%PDF-1.4\n1 0 obj\n<< /Length " . strlen($data) . " >>\nstream\n" . $data . "\nendstream\nendobj\n"
                . "2 0 obj\n<< /Length 600000 >>\n" . str_repeat("stream\n", $n) . "\n";
        };
        $this->assertLinear($build, static fn($pdf) => PdfUtils::hashAllCompressedStreams($pdf), 20000);
        $this->assertLinear($build, static fn($pdf) => PdfUtils::hashPageContentStreams($pdf, 2), 20000);
    }

    public function testManyStreamsWithAnUnclosedTailScaleLinearly(): void
    {
        $build = static function (int $n): string {
            $pdf = "%PDF-1.4\n";
            for ($i = 0; $i < $n; $i++) {
                $data = gzcompress('BT /F1 12 Tf 72 712 Td (Page ' . $i . ') Tj ET');
                $pdf .= ($i + 1) . " 0 obj\n<< /Length " . strlen($data) . " >>\nstream\n" . $data . "\nendstream\nendobj\n";
            }
            return $pdf . "9999 0 obj\n<< /Length 999 >>\nstream\n" . str_repeat('x', 200000) . "\n";
        };
        $this->assertLinear($build, static fn($pdf) => PdfUtils::hashAllCompressedStreams($pdf), 2000);
    }

    public function testAFileOfBareKeywordsCostsNoMemoryOfItsOwn(): void
    {
        $keywords = str_repeat("stream\n", 700000); // ~4.7 MB

        $sizing = Measure::run(static fn() => PdfUtils::inflatedStreamBytes($keywords, PHP_INT_MAX));
        self::assertSame(0, $sizing['result']);
        self::assertLessThan(2 * self::MB, $sizing['peak'], 'sizing pass');

        $hashing = Measure::run(static fn() => PdfUtils::hashAllCompressedStreams($keywords));
        self::assertLessThan(2 * self::MB, $hashing['peak'], 'hashing pass');
    }

    public function testManySmallStreamsCostLessThanTheFileItself(): void
    {
        $many = "%PDF-1.4\n";
        $obj  = 0;
        while (strlen($many) < 12 * self::MB) {
            $many .= (++$obj) . " 0 obj\n<< /Length 4 >>\nstream\nAAAA\nendstream\nendobj\n";
        }
        $run = Measure::run(static fn() => PdfUtils::hashAllCompressedStreams($many));
        self::assertSame([], $run['result']);
        self::assertLessThan(strlen($many), $run['peak']);
    }

    // ---- object index ----

    public function testStreamLessObjectsAtTheCeilingAreIndexedInLessMemoryThanTheFile(): void
    {
        $pdf = "%PDF-1.4\n" . str_repeat("1 0 obj\n<< /Type /Page >>\nendobj\n", PdfUtils::MAX_OBJECTS);
        $run = Measure::run(static fn() => PdfUtils::indexObjects($pdf));
        self::assertCount(1, $run['result']);
        self::assertLessThan(strlen($pdf), $run['peak']);
        self::assertLessThan(2.0, $run['seconds']);
    }

    public function testStreamLessObjectsScaleLinearly(): void
    {
        $this->assertLinear(
            static fn(int $n) => "%PDF-1.4\n" . str_repeat("1 0 obj\n<< /Type /Page >>\nendobj\n", $n),
            static fn($pdf) => PdfUtils::indexObjects($pdf),
            5000,
            PdfUtils::MAX_OBJECTS / 4
        );
    }

    public function testUnclosedStreamHeadersScaleLinearly(): void
    {
        $build = static function (int $n): string {
            $pdf = "%PDF-1.4\n";
            for ($obj = 1; $obj <= $n; $obj++) {
                $pdf .= $obj . " 0 obj\n<< /L 1 >>\nstream\nz\nendobj\n";
            }
            return $pdf;
        };
        $this->assertLinear($build, static fn($pdf) => PdfUtils::indexObjects($pdf), 5000, PdfUtils::MAX_OBJECTS / 4);
    }

    public function testTheDefinitionIndexScalesLinearlyWhenNothingCloses(): void
    {
        // Each object is padded so the file outgrows the object count: without nextAt()'s remembered answers every search
        // runs to the end of the file, and that shows as n^2 (measured: n^1.97) well below the object ceiling. Unpadded,
        // the quadratic version stayed just under the absolute fallback bound.
        $pad = str_repeat(' ', 200);
        // Every header's "endobj" search would run to the end of the file.
        $this->assertLinear(
            static fn(int $n) => "%PDF-1.4\n" . str_repeat("1 0 obj\n<< /A 1 >>" . $pad . "\n", $n),
            static fn($pdf) => PdfUtils::objectDefinitionIndex($pdf),
            2000,
            PdfUtils::MAX_OBJECTS / 4
        );
        // Every stream's "endstream" search likewise; "endobj" closes each object, so the stream branch runs for all.
        $this->assertLinear(
            static function (int $n) use ($pad): string {
                $pdf = "%PDF-1.4\n";
                for ($obj = 1; $obj <= $n; $obj++) {
                    $pdf .= $obj . " 0 obj\n<< /L 1 >>\nstream\nz" . $pad . "\nendobj\n";
                }
                return $pdf;
            },
            static fn($pdf) => PdfUtils::objectDefinitionIndex($pdf),
            2000,
            PdfUtils::MAX_OBJECTS / 4
        );
    }

    public function testAFilePastTheCeilingIsRefusedQuickly(): void
    {
        $pdf = str_repeat("1 0 obj\n<<>>\nendobj\n", PdfUtils::MAX_OBJECTS * 3);
        $t0  = hrtime(true);
        try {
            PdfUtils::indexObjects($pdf);
            self::fail('a file past the object ceiling must be refused');
        } catch (\LengthException $e) {
            self::assertStringContainsString('Too many objects', $e->getMessage());
        }
        self::assertLessThan(2.0, (hrtime(true) - $t0) / 1e9);
    }

    public function testIndexObjectsAtTheCeilingHasAFixedBound(): void
    {
        $run = Measure::run(static fn() => PdfUtils::indexObjects(self::tinyObjects(PdfUtils::MAX_OBJECTS)));
        self::assertLessThan(40 * self::MB, $run['peak']);
    }

    // ---- the verifier's own scans ----

    public function testRawStreamBlocksWithoutAnOpeningBracketScaleLinearlyInConstantMemory(): void
    {
        $walk = static function (string $raw): void {
            foreach (PdfUtils::rawStreamBlocks($raw) as $unused) {
            }
        };
        $this->assertLinear(static fn(int $n) => str_repeat(">>stream\nx\n", $n), $walk, 50000);

        $no_open = str_repeat(">>stream\nx\n", 400000); // ~4.2 MB
        self::assertLessThan(self::MB, Measure::run(static fn() => $walk($no_open))['peak']);

        $blocks = str_repeat("<</Filter/FlateDecode>>stream\nAAAA\nendstream\n", 200000);
        self::assertLessThan(self::MB, Measure::run(static fn() => $walk($blocks))['peak']);
    }

    public function testTheObjectWalkAndTypeListHoldConstantMemory(): void
    {
        $tiny = self::tinyObjects(PdfUtils::MAX_OBJECTS);
        $walk = Measure::run(static function () use ($tiny): void {
            foreach (Reflect::call(Verificationpage::class, 'scanObjectBodies', $tiny) as $unused) {
            }
        });
        self::assertLessThan(self::MB, $walk['peak'], 'object walk');

        $types = Measure::run(static fn() => Reflect::call(Verificationpage::class, 'distinctTypeNames', $tiny));
        self::assertCount(257, $types['result']);
        self::assertLessThan(self::MB, $types['peak'], 'type names');
    }

    public function testUnclosedSealMarkersScaleLinearly(): void
    {
        $this->assertLinear(
            static fn(int $n) => str_repeat('---BEGIN-SEAL---x', $n),
            static function (string $text): void {
                PdfUtils::sealBlocks($text);
                PdfUtils::withoutSealBlocks($text . '---END-SEAL---' . $text);
            },
            50000
        );
    }

    public function testTheRawSealCheckReadsALargeUtf16StreamInLinearTime(): void
    {
        $dir = PdfFixtures::tempDir('fabricator-perf-');
        try {
            $this->assertLinear(
                static function (int $n) use ($dir): string {
                    $path = $dir . "/utf16-$n.pdf";
                    file_put_contents($path, PdfFixtures::build([1 => PdfFixtures::stream('/Filter /FlateDecode', gzcompress(str_repeat("\x00-\x01\x02", $n)))]));
                    return $path;
                },
                static fn($path) => Reflect::call(Verificationpage::class, 'rawPdfHasSeal', $path),
                250000,
                8000000
            );
        } finally {
            PdfFixtures::removeTree($dir);
        }
    }

    /**
     * $n tiny objects with 400 distinct /Type names between them.
     */
    private static function tinyObjects(int $n): string
    {
        $pdf = "%PDF-1.4\n";
        for ($i = 1; $i <= $n; $i++) {
            $pdf .= $i . " 0 obj\n<< /Type /T" . ($i % 400) . " >>\nendobj\n";
        }
        return $pdf;
    }

    private function assertLinear(callable $build, callable $scan, int $n, int|float $max_n = PHP_INT_MAX): void
    {
        $r = Measure::growthExponent($build, $scan, $n, 0.05, (int) $max_n);
        if (!$r['trusted']) {
            // Still under the noise floor at the largest input: fast in absolute terms, which a quadratic scan is not.
            self::assertLessThan(0.25, $r['grown'], sprintf('n=%d took %.3f s', $r['n'] * 4, $r['grown']));
            return;
        }
        self::assertLessThan(
            self::MAX_EXPONENT,
            $r['exponent'],
            sprintf('n=%d took %.3f s, 4n took %.3f s: time grows as n^%.2f; a linear scan stays near n^1', $r['n'], $r['base'], $r['grown'], $r['exponent'])
        );
    }
}
