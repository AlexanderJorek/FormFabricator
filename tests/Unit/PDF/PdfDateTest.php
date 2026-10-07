<?php

namespace FabricatorForms\Tests\Unit\PDF;

use FabricatorForms\PDF\PdfUtils;
use FabricatorForms\Tests\Support\TestCase;

/**
 * PdfUtils::pdfDateTimestamp(): the verifier reads /CreationDate and /ModDate with it, so a date mPDF writes must read
 * as the moment it names, and anything that is no PDF date as null rather than as some moment.
 */
final class PdfDateTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: int|null}>
     */
    public static function dates(): iterable
    {
        $noon = gmmktime(12, 30, 15, 10, 6, 2026);
        yield 'as mPDF writes it, UTC' => ["D:20261006123015+00'00'", $noon];
        yield 'east of UTC' => ["D:20261006143015+02'00'", $noon];
        yield 'west of UTC, with minutes' => ["D:20261006070015-05'30'", $noon];
        yield 'Z' => ['D:20261006123015Z', $noon];
        yield 'no offset reads as UTC' => ['D:20261006123015', $noon];
        yield 'PDF 2.0, no closing apostrophe' => ["D:20261006143015+02'00", $noon];
        yield 'without the D: prefix' => ["20261006123015+00'00'", $noon];
        yield 'year only' => ['D:2026', gmmktime(0, 0, 0, 1, 1, 2026)];
        yield 'surrounding space' => [" D:20261006123015Z\n", $noon];
        yield 'empty' => ['', null];
        yield 'not a date' => ['yesterday', null];
        yield 'month 13' => ['D:20261306123015Z', null];
        yield '30 February' => ['D:20260230123015Z', null];
        yield 'hour 24' => ['D:20261006243015Z', null];
        yield 'trailing text' => ["D:20261006123015+00'00' and more", null];
        yield 'odd digit count' => ['D:202610061', null];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('dates')]
    public function testReadsADateAsTheMomentItNames(string $value, ?int $expected): void
    {
        self::assertSame($expected, PdfUtils::pdfDateTimestamp($value));
    }
}
