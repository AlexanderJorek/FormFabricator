<?php

namespace FabricatorForms\Tests\Unit\PDF;

use FabricatorForms\PDF\GuardedPdfParser;
use FabricatorForms\PDF\PdfUtils;
use FabricatorForms\Tests\Support\PdfFixtures;
use FabricatorForms\Tests\Support\TestCase;

/**
 * GuardedPdfParser refuses what it must not unpack (LZW, RunLength, filter chains, ASCII85 forms, streams past the
 * allowance) and lists each refusal, while still reading the page text next to it.
 */
final class GuardedPdfParserTest extends TestCase
{
    private const IMAGE_RGB = '/Type /XObject /Subtype /Image /ColorSpace /DeviceRGB /BitsPerComponent 8';

    public function testRefusedStreamsAreListedAndTheTextIsStillRead(): void
    {
        $objects = PdfFixtures::page('/Im1 6 0 R /Im2 7 0 R /Im3 8 0 R /Im4 9 0 R /Fm1 10 0 R') + [
            6  => PdfFixtures::stream(self::IMAGE_RGB . ' /Width 800 /Height 600 /Filter /LZWDecode', str_repeat("\x5a\xa5", 1024)),
            7  => PdfFixtures::stream(
                '/Type /XObject /Subtype /Image /Width 10 /Height 10 /ColorSpace /DeviceGray /BitsPerComponent 8 /Filter [/FlateDecode /FlateDecode]',
                gzcompress(gzcompress(str_repeat("\x00", 1048576), 9), 9)
            ),
            8  => PdfFixtures::stream('/Filter /RunLengthDecode', str_repeat("\x81\x00", 1024)),
            9  => self::cleanImage(),
            10 => PdfFixtures::stream('/Type /XObject /Subtype /Form /BBox [0 0 1 1] /Filter /ASCII85Decode', str_repeat('z', 4096) . '~>'),
        ];
        $pdf = PdfFixtures::build($objects);

        [$text, $refused, $unlisted] = self::parse($pdf, PdfUtils::inflatedStreamBytes($pdf, PHP_INT_MAX) + 16 * 1048576);

        self::assertStringContainsString('Hello Guard', $text);
        $by = array_column($refused, null, 'object');
        self::assertSame([6, 7, 8, 10], array_keys($by));
        self::assertSame(['Image', 800, 600, ['LZWDecode']], [$by[6]['subtype'], $by[6]['width'], $by[6]['height'], $by[6]['filters']]);
        self::assertSame([['FlateDecode', 'FlateDecode'], 'filter'], [$by[7]['filters'], $by[7]['reason']]);
        self::assertSame(['RunLengthDecode'], $by[8]['filters']);
        self::assertSame(['XObject', 'Form', ['ASCII85Decode']], [$by[10]['type'], $by[10]['subtype'], $by[10]['filters']]);
        self::assertSame(0, $unlisted);
    }

    public function testATightAllowanceLeavesEvenTheContentStreamPacked(): void
    {
        [$text, $refused] = self::parse(PdfFixtures::build(PdfFixtures::page()), 10);

        self::assertSame(['4'], array_column($refused, 'object'));
        self::assertSame('size', $refused[0]['reason']);
        self::assertStringNotContainsString('Hello Guard', $text);
    }

    public function testACleanFileIsUntouched(): void
    {
        [$text, $refused] = self::parse(PdfFixtures::build(PdfFixtures::page('/Im1 6 0 R') + [6 => self::cleanImage()]), 64 * 1048576);

        self::assertSame([], $refused);
        self::assertStringContainsString('Hello Guard', $text);
    }

    public function testOnlyTheFirst50RefusalsAreListed(): void
    {
        $objects = PdfFixtures::page(implode(' ', array_map(static fn($i) => "/X$i " . (10 + $i) . ' 0 R', range(1, 60))));
        foreach (range(1, 60) as $i) {
            $objects[10 + $i] = PdfFixtures::stream('/Type /XObject /Subtype /Form /BBox [0 0 1 1] /Filter /LZWDecode', 'x');
        }

        [, $refused, $unlisted] = self::parse(PdfFixtures::build($objects), 64 * 1048576);

        self::assertCount(50, $refused);
        self::assertSame(10, $unlisted);
    }

    private static function cleanImage(): string
    {
        return PdfFixtures::stream(
            self::IMAGE_RGB . ' /Width 3 /Height 1 /Filter /FlateDecode /DecodeParms << /Predictor 15 /Colors 3 /Columns 3 >>',
            gzcompress("\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09")
        );
    }

    /**
     * @return array{string, array<int, array<string, mixed>>, int}
     */
    private static function parse(string $pdf, int $allowance): array
    {
        $config = new \Smalot\PdfParser\Config();
        $config->setRetainImageContent(false);
        $parser = new GuardedPdfParser($config, $allowance);
        $doc    = $parser->parseContent($pdf);
        return [$doc->getText(), $parser->refusedStreams(), $parser->unlistedRefusedCount()];
    }
}
