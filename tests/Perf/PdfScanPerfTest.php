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
 * The pathological shapes behind CONTRIBUTING.md's "Scanning untrusted PDF bytes": every scan stays linear in time
 * and holds no memory per match. Time is a growth exponent (Measure): linear near 1, quadratic near 2.
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
        // Padded, so the file outgrows the object count and a search to the end per object shows as n^2 well below
        // the object ceiling.
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

    public function testACrossReferenceTableOfTinyEntriesIsRefusedBeforePdfparserHoldsThem(): void
    {
        // A million five-byte entries all naming one object: pdfparser keeps an array entry per line, about twenty times
        // the table's size, before building an object for each. Counted as read, the file is refused at the ceiling.
        $head  = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n";
        $count = 1000000;
        $pdf   = $head . "xref\n0 $count\n" . str_repeat("9 0n\n", $count) . "trailer\n<< /Size $count /Root 1 0 R >>\nstartxref\n"
            . strlen($head) . "\n%%EOF\n";
        $run = Measure::run(static function () use ($pdf): string {
            try {
                (new \FabricatorForms\PDF\GuardedPdfParser(new \Smalot\PdfParser\Config(), 64 * self::MB))->parseContent($pdf);
                return 'parsed';
            } catch (\LengthException $e) {
                return $e->getMessage();
            }
        });
        self::assertSame('Too many objects: cross-reference entries.', $run['result']);
        self::assertLessThan(2 * strlen($pdf), $run['peak'], 'no more than the file twice over');
        self::assertLessThan(5.0, $run['seconds']);
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

        \Brain\Monkey\Functions\when('__')->returnArg(1); // the overflow marker is a translated label
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

    public function testNestedObjectHeadersWithOneEndobjScaleLinearlyAndCopyNoMoreThanTheFile(): void
    {
        // Many headers sharing one "endobj": copying each up to it would be quadratic in time and memory. The verifier
        // reaches indexObjects() for any file holding a seal marker, whether or not its HMAC checks out.
        $build = static fn(int $n): string => "%PDF-1.4\n" . str_repeat("1 0 obj\n<< /A 1 >>\n", $n) . "endobj\n";
        $this->assertLinear($build, static fn($pdf) => PdfUtils::indexObjects($pdf), 2000, PdfUtils::MAX_OBJECTS / 4);

        $pdf = $build((int) (PdfUtils::MAX_OBJECTS / 2));
        $run = Measure::run(static fn() => PdfUtils::indexObjects($pdf));
        self::assertLessThan(2 * strlen($pdf) + 4 * self::MB, $run['peak'], 'peak ' . round($run['peak'] / self::MB, 1) . ' MB');
    }

    public function testALongContentsListScalesLinearly(): void
    {
        // One page whose /Contents lists $n streams, and those $n stream objects: in_array() on the list once per stream
        // was quadratic (a 4 MB file took 15.5 s).
        $build = static function (int $n): string {
            $refs = '';
            $objs = '';
            for ($i = 0; $i < $n; $i++) {
                $refs .= (10 + $i) . ' 0 R ';
                $objs .= (10 + $i) . " 0 obj\n<< /Length 3 >>\nstream\nq Q\nendstream\nendobj\n";
            }
            return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n"
                . "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n"
                . "3 0 obj\n<< /Type /Page /Parent 2 0 R /Contents [" . $refs . "] >>\nendobj\n" . $objs
                . "trailer\n<< /Root 1 0 R >>\n%%EOF\n";
        };
        $this->assertLinear($build, static fn($pdf) => PdfUtils::verifyContentStreams($pdf, [], 'x'), 1000, PdfUtils::MAX_OBJECTS / 4);
    }

    public function testTheAnnotsEntriesScaleLinearly(): void
    {
        // Every page naming one array object of $n references: reading the array once per page is quadratic. Then $n
        // arrays that never close, and $n small arrays each closed right after it.
        $shared   = static function (int $n): string {
            $refs = '';
            for ($i = 0; $i < $n; $i++) {
                $refs .= (10 + $i) . ' 0 R ';
            }
            return "%PDF-1.4\n5 0 obj\n[" . $refs . "]\nendobj\n" . str_repeat("<< /Type /Page /Annots 5 0 R >>\n", $n);
        };
        $unclosed = static fn(int $n): string => "%PDF-1.4\n" . str_repeat("<< /Annots [ 7 0 R >>\n", $n);
        $closed   = static fn(int $n): string => "%PDF-1.4\n" . str_repeat("<< /Annots [ 7 0 R ] >>\n", $n);
        $collect  = static fn($pdf) => Reflect::call(
            Verificationpage::class,
            'collectAnnotations',
            $pdf,
            PdfUtils::objectDefinitionIndex($pdf),
            ['start' => microtime(true), 'max' => 600]
        );
        foreach ([$shared, $unclosed, $closed] as $build) {
            $this->assertLinear($build, $collect, 1000, PdfUtils::MAX_OBJECTS / 4);
        }
    }

    public function testTheNameWalkScalesLinearly(): void
    {
        // PdfUtils::nameTokens(), behind escapedName() and the /Annots entries: streams whose /Length is wrong, so each
        // end is searched for; nested dictionaries, each top-level one read for its /Length; strings and hex strings
        // that never close.
        $wrong_length = static fn(int $n): string => "%PDF-1.4\n" . str_repeat("<< /Length 999999 >>\nstream\n/A\nendstream\n", $n);
        $nested       = static fn(int $n): string => "%PDF-1.4\n" . str_repeat("<< /A << /B (x) /C [<0F> /D] >> >>\n", $n);
        $open_strings = static fn(int $n): string => "%PDF-1.4\n<< /A " . str_repeat('(x \\) /B ', $n);
        $open_hex     = static fn(int $n): string => "%PDF-1.4\n<< /A " . str_repeat('<0F /B ', $n);
        foreach ([$wrong_length, $nested, $open_strings, $open_hex] as $build) {
            $this->assertLinear($build, static fn($pdf) => PdfUtils::escapedName($pdf), 5000);
        }
    }

    public function testTheImageDataWalkScalesLinearly(): void
    {
        // PdfUtils::imageStreamSpans(), behind the verifier's raw counts and its type scan: images whose /Length is wrong,
        // so each end is searched for; keywords after ">>" with no line break; image headers with no endstream at all.
        $wrong_length = static function (int $n): string {
            $pdf = "%PDF-1.4\n";
            for ($i = 1; $i <= $n; $i++) {
                $pdf .= $i . " 0 obj\n<< /Type /XObject /Subtype /Image /Length 999999 >>\nstream\n%%EOF /Type /X\nendstream\nendobj\n";
            }
            return $pdf;
        };
        $no_break = static fn(int $n): string => "%PDF-1.4\n" . str_repeat('<< /Subtype /Image >>stream ', $n);
        $unclosed = static fn(int $n): string => "%PDF-1.4\n" . str_repeat("1 0 obj\n<< /Subtype /Image >>\nstream\n", $n);
        $types    = static fn($pdf) => Reflect::call(Verificationpage::class, 'distinctTypeNames', $pdf);
        foreach ([$wrong_length, $no_break, $unclosed] as $build) {
            $this->assertLinear($build, static fn($pdf) => PdfUtils::countOutsideImageData($pdf, '%%EOF'), 5000);
            $this->assertLinear($build, $types, 5000);
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
        $r = Measure::settledGrowthExponent(self::MAX_EXPONENT, $build, $scan, $n, 0.05, (int) $max_n);
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
