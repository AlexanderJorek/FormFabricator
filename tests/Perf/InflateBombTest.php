<?php

namespace FabricatorForms\Tests\Perf;

use FabricatorForms\PDF\GuardedPdfParser;
use FabricatorForms\PDF\PdfUtils;
use FabricatorForms\Tests\Support\Measure;
use FabricatorForms\Tests\Support\PdfFixtures;
use FabricatorForms\Tests\Support\PerfTestCase;
use PHPUnit\Framework\Attributes\RequiresFunction;

/**
 * Decompression bombs: every inflate stops at its cap instead of unpacking hundreds of MB, and the guarded parser
 * refuses them while still reading the page text beside them.
 */
#[RequiresFunction('memory_reset_peak_usage')]
final class InflateBombTest extends PerfTestCase
{
    private const MB = 1048576;

    private static string $bomb = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        // ~300 KB that inflates to 300 MB.
        self::$bomb = gzcompress(str_repeat("\x00", 300 * self::MB), 9);
    }

    public static function tearDownAfterClass(): void
    {
        self::$bomb = '';
        parent::tearDownAfterClass();
    }

    public function testInflateWithinStopsAtTheCap(): void
    {
        $run = Measure::run(static fn() => PdfUtils::inflateWithin(self::$bomb));
        self::assertNull($run['result']);
        self::assertLessThan(2 * PdfUtils::INFLATE_STREAM_CAP + 16 * self::MB, $run['peak']);
    }

    public function testInflatedStreamBytesCountsABombAtTheCapAndStopsPastTheLimit(): void
    {
        $cap     = PdfUtils::INFLATE_STREAM_CAP;
        $bombPdf = str_repeat("<< /Filter /FlateDecode >>\nstream\n" . self::$bomb . "\nendstream\n", 3);

        self::assertSame(3 * $cap, PdfUtils::inflatedStreamBytes($bombPdf, PHP_INT_MAX));
        self::assertSame(2 * $cap, PdfUtils::inflatedStreamBytes($bombPdf, $cap), 'counting stops once past the limit');
    }

    public function testTheGuardedParserRefusesAFormBombForSize(): void
    {
        $pdf = PdfFixtures::build(PdfFixtures::page('/Fm1 6 0 R') + [
            6 => PdfFixtures::stream('/Type /XObject /Subtype /Form /BBox [0 0 1 1] /Filter /FlateDecode', self::$bomb),
        ]);
        $run = Measure::run(static fn() => self::parse($pdf));
        [$text, $refused] = $run['result'];

        self::assertCount(1, $refused);
        self::assertSame(['6', 'size'], [$refused[0]['object'], $refused[0]['reason']]);
        self::assertStringContainsString('Hello Guard', $text);
        self::assertLessThan(200 * self::MB, $run['peak']);
    }

    public function testTheGuardedParserSkipsAnImageBombUnread(): void
    {
        $pdf = PdfFixtures::build(PdfFixtures::page('/Im1 6 0 R') + [
            6 => PdfFixtures::stream('/Type /XObject /Subtype /Image /Width 1 /Height 1 /Filter /FlateDecode', self::$bomb),
        ]);
        $run = Measure::run(static fn() => self::parse($pdf));
        [$text, $refused] = $run['result'];

        self::assertSame([], $refused);
        self::assertStringContainsString('Hello Guard', $text);
        self::assertLessThan(32 * self::MB, $run['peak']);
    }

    public function testTheGuardedParserHandlesALargeCraftedFileWithinBounds(): void
    {
        $pdf = PdfFixtures::build(PdfFixtures::page('/Im1 6 0 R /Im2 7 0 R /Im3 8 0 R /Fm1 10 0 R') + [
            6  => PdfFixtures::stream('/Type /XObject /Subtype /Image /Width 800 /Height 600 /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /LZWDecode', random_bytes(512 * 1024)),
            7  => PdfFixtures::stream(
                '/Type /XObject /Subtype /Image /Width 10 /Height 10 /ColorSpace /DeviceGray /BitsPerComponent 8 /Filter [/FlateDecode /FlateDecode]',
                gzcompress(gzcompress(str_repeat("\x00", 50 * self::MB), 9), 9)
            ),
            8  => PdfFixtures::stream('/Filter /RunLengthDecode', str_repeat("\x81\x00", self::MB)),
            10 => PdfFixtures::stream('/Type /XObject /Subtype /Form /BBox [0 0 1 1] /Filter /ASCII85Decode', str_repeat('z', 4 * self::MB) . '~>'),
        ]);
        $run = Measure::run(static fn() => self::parse($pdf));

        self::assertStringContainsString('Hello Guard', $run['result'][0]);
        self::assertSame([6, 7, 8, 10], array_keys(array_column($run['result'][1], null, 'object')));
        self::assertLessThan(5.0, $run['seconds']);
        self::assertLessThan(200 * self::MB, $run['peak']);
    }

    /**
     * @return array{string, array<int, array<string, mixed>>}
     */
    private static function parse(string $pdf): array
    {
        $config = new \Smalot\PdfParser\Config();
        $config->setRetainImageContent(false);
        $parser = new GuardedPdfParser($config, PdfUtils::inflatedStreamBytes($pdf, PHP_INT_MAX) + 16 * self::MB);
        return [$parser->parseContent($pdf)->getText(), $parser->refusedStreams()];
    }
}
