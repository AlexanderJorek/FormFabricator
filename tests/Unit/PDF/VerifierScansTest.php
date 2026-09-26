<?php

namespace FabricatorForms\Tests\Unit\PDF;

use FabricatorForms\Admin\Verificationpage;
use FabricatorForms\PDF\PdfUtils;
use FabricatorForms\Tests\Support\Reflect;
use FabricatorForms\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The scans over uploaded PDF bytes that used to build whole-file indexes — PdfUtils::rawStreamBlocks() and the
 * verifier's object walk and /Type list — must give the results the old versions gave. Old versions kept as reference.
 */
final class VerifierScansTest extends TestCase
{
    private const DICTS = [
        '<< /Type /Page >>',
        '<< /Type /Font /Subtype /Type1 >>',
        '<</Filter/FlateDecode/Length 4>>',
        '<< >>',
        '<< /Type /Annot /Subtype /Link >>',
        '<< /Title (a > b) >>',
        '<</Type/XObject/Subtype/Image>>',
    ];

    public function testThreeScansMatchTheOldVersionsOnGeneratedFiles(): void
    {
        foreach (self::generatedFiles() as $case => $pdf) {
            self::assertSame(self::oldBlocks($pdf), iterator_to_array(PdfUtils::rawStreamBlocks($pdf), false), "rawStreamBlocks, case $case");
            self::assertSame(self::oldBodies($pdf), iterator_to_array(self::scanBodies($pdf), false), "object walk, case $case");
            self::assertSame(self::oldTypeSet($pdf), array_column(self::typeNames($pdf), 1), "type names, case $case");
        }
    }

    public function testTypeNamesStopAt256PlusTheOverflowMarker(): void
    {
        $pdf = "%PDF-1.4\n";
        for ($i = 1; $i <= 1000; $i++) {
            $pdf .= $i . " 0 obj\n<< /Type /T" . ($i % 400) . " >>\nendobj\n";
        }
        self::assertCount(257, self::typeNames($pdf));
    }

    public function testDeclaredObjectCountIsExactOnPlainHeaders(): void
    {
        $pdf = str_repeat("1 0 obj\n<<>>\nendobj\n", 1234);
        self::assertSame(1234, PdfUtils::declaredObjectCount($pdf));
        self::assertSame(0, PdfUtils::declaredObjectCount('endobj endobj'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function headerWhitespace(): array
    {
        return ['LF' => ["\n"], 'CR' => ["\r"], 'tab' => ["\t"], 'VT' => ["\x0B"], 'FF' => ["\x0C"], 'space' => [' ']];
    }

    #[DataProvider('headerWhitespace')]
    public function testDeclaredObjectCountSeesEveryWhitespaceBeforeObj(string $space): void
    {
        self::assertSame(1, PdfUtils::declaredObjectCount('1 0' . $space . 'obj'));
    }

    public function testTheObjectWalkRefusesPastTheCeiling(): void
    {
        $over = '';
        for ($i = 1; $i <= PdfUtils::MAX_OBJECTS + 1; $i++) {
            $over .= $i . " 0 obj\n<<>>\nendobj\n";
        }
        $this->expectException(\LengthException::class);
        $this->expectExceptionMessage('Too many objects');
        foreach (self::scanBodies($over) as $unused) {
        }
    }

    private static function scanBodies(string $pdf): \Generator
    {
        return Reflect::call(Verificationpage::class, 'scanObjectBodies', $pdf);
    }

    private static function typeNames(string $pdf): array
    {
        return Reflect::call(Verificationpage::class, 'distinctTypeNames', $pdf);
    }

    /**
     * @return \Generator<int, string>
     */
    private static function generatedFiles(): \Generator
    {
        mt_srand(20260925);
        for ($case = 0; $case < 800; $case++) {
            $pdf   = "%PDF-1.4\n";
            $count = mt_rand(1, 12);
            for ($i = 0; $i < $count; $i++) {
                $pdf .= mt_rand(1, 20) . ' ' . mt_rand(0, 1) . " obj\n" . self::DICTS[mt_rand(0, count(self::DICTS) - 1)];
                if (mt_rand(0, 1)) {
                    $body = 'x endobj >> << stream';
                    if (mt_rand(0, 3)) {
                        $body = '';
                        for ($b = mt_rand(1, 30); $b > 0; $b--) {
                            $body .= chr(mt_rand(0, 255));
                        }
                    }
                    $pdf .= (mt_rand(0, 1) ? "\n" : ' ') . 'stream' . (mt_rand(0, 3) ? "\n" : "\r\n") . $body . "\nendstream";
                }
                $pdf .= "\nendobj\n";
                if (mt_rand(0, 7) === 0) {
                    $pdf .= ">>stream\n"; // a stray keyword with no dictionary before it
                }
            }
            if (mt_rand(0, 6) === 0) {
                $pdf .= "99 0 obj\n<< /L 5 >>\nstream\nnever closed";
            }
            yield $case => $pdf;
        }
    }

    /**
     * rawStreamBlocks() as it stood before, returning the whole list at once.
     *
     * @return array<int, array{string, string, string}>
     */
    private static function oldBlocks(string $raw): array
    {
        $out     = [];
        $len     = strlen($raw);
        $resume  = 0;
        $keyword = 0;
        while (($keyword = strpos($raw, 'stream', $keyword)) !== false) {
            $kw          = $keyword;
            $keyword    += 6;
            $body_start  = $kw + 6;
            if (($raw[$body_start] ?? '') === "\r") {
                $body_start++;
            }
            if (($raw[$body_start] ?? '') !== "\n") {
                continue;
            }
            $body_start++;
            $p = $kw;
            while ($p > 0 && strpos(" \t\n\r\x0B\x0C", $raw[$p - 1]) !== false) {
                $p--;
            }
            $close = $p - 2;
            if ($close < $resume || $close < 2 || substr($raw, $close, 2) !== '>>') {
                continue;
            }
            $last_gt = strrpos($raw, '>', $close - $len - 1);
            $open    = strpos($raw, '<<', max($resume, $last_gt === false ? 0 : $last_gt + 1));
            if ($open === false || $open + 2 > $close) {
                continue;
            }
            $body_end = strpos($raw, "\nendstream", $body_start);
            if ($body_end === false) {
                break;
            }
            $out[]   = ['', substr($raw, $open + 2, $close - $open - 2), substr($raw, $body_start, $body_end - $body_start)];
            $resume  = $body_end + 10;
            $keyword = $resume;
        }
        return $out;
    }

    /**
     * The verifier's object walk as it stood before (preg_match_all() over the whole file).
     *
     * @return array<int, array{string, string}>
     */
    private static function oldBodies(string $pdf_raw): array
    {
        $pairs = [];
        if (!preg_match_all('/(\d+\s+\d+)\s+obj/', $pdf_raw, $hm, PREG_OFFSET_CAPTURE)) {
            return $pairs;
        }
        $resume = 0;
        foreach ($hm[0] as $i => $match) {
            if ($match[1] < $resume) {
                continue;
            }
            $body_start = $match[1] + strlen($match[0]);
            $end        = strpos($pdf_raw, 'endobj', $body_start);
            if ($end === false) {
                break;
            }
            $pairs[] = [$hm[1][$i][0], substr($pdf_raw, $body_start, $end - $body_start)];
            $resume  = $end + 6;
        }
        return $pairs;
    }

    /**
     * @return string[]
     */
    private static function oldTypeSet(string $pdf_raw): array
    {
        if (!preg_match_all('/\/Type\s*\/(\w+)/', $pdf_raw, $m)) {
            return [];
        }
        return array_values(array_unique($m[1]));
    }
}
