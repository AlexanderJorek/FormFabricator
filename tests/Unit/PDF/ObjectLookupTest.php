<?php

namespace FabricatorForms\Tests\Unit\PDF;

use FabricatorForms\PDF\PdfUtils;
use FabricatorForms\Tests\Support\PdfFixtures;
use FabricatorForms\Tests\Support\TestCase;

/**
 * Object lookups and dictionary reads: PdfUtils::lastObjectDefinition(), the one-pass index that must give the same
 * answer, and dictTextEntry() — on synthetic edge cases and on real mPDF output.
 */
final class ObjectLookupTest extends TestCase
{
    private static string $tmp = '';

    public static function tearDownAfterClass(): void
    {
        PdfFixtures::removeTree(self::$tmp);
        parent::tearDownAfterClass();
    }

    public function testNumberBoundariesAndLastDefinition(): void
    {
        $pdf = "112 0 obj\n<< /Which /Decoy >>\nendobj\n12 0 obj\n<< /Which /Real >>\nendobj\n";
        self::assertSame('<< /Which /Real >>', self::body(PdfUtils::lastObjectDefinition($pdf, '12', '0')));
        self::assertSame('<< /Which /Decoy >>', self::body(PdfUtils::lastObjectDefinition($pdf, '112', '0')));
        self::assertNull(PdfUtils::lastObjectDefinition($pdf, '2', '0'));

        $pdf = "5 0 obj\n300\nendobj\nxref\n5 0 obj\n400\nendobj\n";
        self::assertSame('400', self::body(PdfUtils::lastObjectDefinition($pdf, '5', '0')), 'last definition wins');
        self::assertSame('400', self::body(PdfUtils::lastObjectDefinition($pdf, '005', '0')), 'leading zeros in the reference');
        self::assertNull(PdfUtils::lastObjectDefinition($pdf, '5', '1'), 'generation filter');
        self::assertSame('7', self::body(PdfUtils::lastObjectDefinition('5 3 obj 7 endobj', '5')), 'any generation');
        self::assertNull(PdfUtils::lastObjectDefinition($pdf, '5\s+|.*', '0'), 'non-digit input refused');
    }

    public function testAStreamContainingEndobjIsKeptWhole(): void
    {
        $data = gzcompress('abc') . 'xx endobj yy';
        $d    = PdfUtils::lastObjectDefinition("9 0 obj\n<< /Length 20 >>\nstream\n" . $data . "\nendstream\nendobj\n", '9', '0');
        self::assertNotNull($d);
        self::assertStringContainsString("\nendstream", $d['body']);
        self::assertSame('9 0 obj', $d['header']);
    }

    public function testAnUnclosedTrailingDefinitionIsSkipped(): void
    {
        self::assertSame('<< /A 1 >>', self::body(PdfUtils::lastObjectDefinition("7 0 obj << /A 1 >> endobj\n7 0 obj << /A 2 >>", '7', '0')));
    }

    public function testTheIndexAnswersExactlyAsLastObjectDefinition(): void
    {
        $pdf = self::mpdfDocument('Index check');
        $index = PdfUtils::objectDefinitionIndex($pdf);
        preg_match_all('/(?<![0-9])([0-9]+)\s+([0-9]+)\s+obj/', $pdf, $m, PREG_SET_ORDER);
        self::assertNotEmpty($m);
        foreach ($m as [, $num, $gen]) {
            self::assertSame(PdfUtils::lastObjectDefinition($pdf, $num, $gen), PdfUtils::definitionFromIndex($index, $pdf, $num, $gen), "$num $gen");
            self::assertSame(PdfUtils::lastObjectDefinition($pdf, $num), PdfUtils::definitionFromIndex($index, $pdf, $num), "$num any");
        }
        self::assertNull(PdfUtils::definitionFromIndex($index, $pdf, '999999', '0'));
    }

    public function testMpdfPaletteFontAndInfoLookupsMatchTheOldFirstMatchRegexes(): void
    {
        $pdf = self::mpdfDocument('Order (2026) \\ back >> slash äöü', true);

        preg_match_all('/\/Indexed\s+\/DeviceRGB\s+(\d+)\s+(\d+)\s+0\s+R/', $pdf, $pm, PREG_SET_ORDER);
        self::assertNotEmpty($pm, 'palettes found');
        foreach ($pm as $p) {
            preg_match('/' . $p[2] . '\s+0\s+obj\s+(.*?)\s+endobj/s', $pdf, $o);
            self::assertSame($o[1] ?? null, self::body(PdfUtils::lastObjectDefinition($pdf, $p[2], '0')));
        }

        preg_match_all('/\/Font\s*<<([\s\S]*?)>>/i', $pdf, $blocks);
        $refs = [];
        foreach ($blocks[1] as $block) {
            if (preg_match_all('/\/\w+\s+(\d+\s+\d+)\s+R/', $block, $mm)) {
                $refs += array_fill_keys($mm[1], true);
            }
        }
        self::assertNotEmpty($refs, 'font refs found');
        foreach (array_keys($refs) as $ref) {
            $old      = preg_match('/' . preg_quote($ref, '/') . '\s+obj\s*<<(.*?)>>/is', $pdf, $o) ? $o[1] : null;
            [$fn, $fg] = preg_split('/\s+/', (string) $ref);
            $d        = PdfUtils::lastObjectDefinition($pdf, $fn, $fg);
            self::assertSame($old, ($d !== null && preg_match('/\A\s*<<(.*?)>>/s', $d['body'], $o2)) ? $o2[1] : null, "font $ref");
        }

        preg_match('/\/Info\s+(\d+)\s+\d+\s+R/', $pdf, $ir);
        preg_match_all('/' . preg_quote($ir[1], '/') . '\s+\d+\s+obj\s*<<(.*?)>>/s', $pdf, $im);
        $d = self::infoObject($pdf);
        self::assertSame(end($im[1]), preg_match('/\A\s*<<(.*?)>>/s', $d['body'], $o3) ? $o3[1] : null);
    }

    /**
     * @return array<string, array{string, ?string}>
     */
    public static function dictStrings(): array
    {
        return [
            'plain'                            => ['<< /Title (Kontakt) >>', 'Kontakt'],
            'escaped and balanced parens'      => ['<< /Title (a \\( b (c) d \\) e \\\\ f) >>', 'a ( b (c) d ) e \\ f'],
            'octal and named escapes'          => ['<< /Title (\\101\\102\\7x\\n\\t) >>', "AB\x07x\n\t"],
            'line continuation'                => ["<< /Title (ab\\\r\ncd) >>", 'abcd'],
            'unescaped CR LF reads as LF'      => ["<< /Title (ab\r\ncd) >>", "ab\ncd"],
            'hex string, odd length'           => ['<< /Title <4142 43 4> >>', 'ABC@'],
            'hex UTF-16BE'                     => ['<< /Title <FEFF00E400F6> >>', 'äö'],
            'name inside another string'       => ['<< /Subject (x /Title \\(y\\)) /Title (real) >>', 'real'],
            'nested dict key ignored'          => ['<< /Sub << /Title (inner) >> /Author (a) >>', null],
            'last duplicate wins'              => ['<< /Title (one) /Title (two) >>', 'two'],
            'prefix name not matched'          => ['<< /TitleX (no) >>', null],
            'comment skipped'                  => ["<< % /Title (no)\n /Title (yes) >>", 'yes'],
            'not a dictionary'                 => ['(x)', null],
            'value after dict end ignored'     => ['<< /A 1 >> /Title (no)', null],
            'array before key'                 => ['<< /K [ (a) /Title (b) ] /Title (c) >>', 'c'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('dictStrings')]
    public function testDictTextEntry(string $dict, ?string $expected): void
    {
        self::assertSame($expected, PdfUtils::dictTextEntry($dict, 'Title'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function mpdfTitles(): array
    {
        // Includes characters whose UTF-16 bytes are "(" ")" "\" CR or ">>".
        return [
            'parens, backslash, >>, umlauts' => ['Order (2026) \\ back >> slash äöü'],
            'bracket-like UTF-16 bytes'      => ["Malayalam \u{0D28} bracket-bytes \u{2929} \u{5C5C} \u{0128} \u{3E3E}"],
            'plain'                          => ['Plain title'],
            'quotes and tags'                => ['Ünïcödé — “quotes” & <tags>'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('mpdfTitles')]
    public function testMpdfMetadataRoundTrips(string $title): void
    {
        $info = self::infoObject(self::mpdfDocument($title));
        self::assertSame($title, PdfUtils::dictTextEntry($info['body'], 'Title'));
        self::assertSame('Café (Berlin) \\ GmbH', PdfUtils::dictTextEntry($info['body'], 'Author'));
        self::assertSame('FormFabricator', PdfUtils::dictTextEntry($info['body'], 'Creator'));
    }

    private static function body(?array $d): ?string
    {
        return $d === null ? null : trim($d['body']);
    }

    private static function infoObject(string $pdf): array
    {
        preg_match_all('/\/Info\s+(\d+)\s+(\d+)\s+R/', $pdf, $irs, PREG_SET_ORDER);
        $ref = end($irs);
        $d   = PdfUtils::lastObjectDefinition($pdf, $ref[1], $ref[2]);
        self::assertNotNull($d, 'the /Info object resolves');
        return $d;
    }

    /**
     * A real mPDF document; with $images, fifteen palette PNGs and two embedded font families as well.
     */
    private static function mpdfDocument(string $title, bool $images = false): string
    {
        if (self::$tmp === '') {
            self::$tmp = PdfFixtures::tempDir('fabricator-mpdf-');
        }
        $mpdf = PdfFixtures::mpdf(self::$tmp);
        $mpdf->SetTitle($title);
        $mpdf->SetAuthor('Café (Berlin) \\ GmbH');
        $mpdf->SetCreator('FormFabricator');
        $html = '<p style="font-family:dejavusans">H&auml;llo</p>';
        if ($images) {
            $png = self::palettePng();
            file_put_contents(self::$tmp . '/pal.png', $png);
            for ($i = 0; $i < 15; $i++) {
                $html .= '<img src="' . self::$tmp . '/pal.png" width="' . (10 + $i) . '"><p style="font-family:dejavuserif">p' . $i . '</p>';
            }
        }
        $mpdf->WriteHTML($html);
        return $mpdf->Output('', 'S');
    }

    private static function palettePng(): string
    {
        $chunk = static fn(string $type, string $data): string => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        $raw   = '';
        for ($y = 0; $y < 4; $y++) {
            $raw .= "\0" . chr($y % 3) . chr(($y + 1) % 3) . chr(($y + 2) % 3) . chr($y % 3);
        }
        return "\x89PNG\r\n\x1a\n"
            . $chunk('IHDR', pack('NNCCCCC', 4, 4, 8, 3, 0, 0, 0))
            . $chunk('PLTE', "\xff\x00\x00\x00\xff\x00\x00\x00\xff")
            . $chunk('IDAT', gzcompress($raw))
            . $chunk('IEND', '');
    }
}
