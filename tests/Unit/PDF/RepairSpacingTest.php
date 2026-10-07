<?php

namespace FabricatorForms\Tests\Unit\PDF;

use FabricatorForms\Admin\Verificationpage;
use FabricatorForms\PDF\PdfUtils;
use FabricatorForms\Tests\Support\Reflect;
use FabricatorForms\Tests\Support\TestCase;

/**
 * Verificationpage::comparableText() and repairSpacing(): a field's text from the PDF matches its sealed value when they
 * differ in spaces only. pdfparser loses a space where a line wraps and gains one where the text switches to a
 * fallback font, and Unicode normalization sorts combining marks differently on either side of a lost space.
 */
final class RepairSpacingTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: string, 2: string|null}>
     */
    public static function cases(): iterable
    {
        yield 'equal' => ['Ada Lovelace', 'Ada Lovelace', 'Ada Lovelace'];
        yield 'space lost at a line wrap' => ['AdaLovelace', 'Ada Lovelace', 'Ada Lovelace'];
        yield 'space gained at a font switch' => ["x \u{0E4F} y", "x\u{0E4F} y", "x\u{0E4F} y"];
        yield 'a letter changed' => ['Ada Lovelaca', 'Ada Lovelace', null];
        yield 'a digit added' => ['1000', '100', null];
        yield 'a digit removed' => ['10', '100', null];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('cases')]
    public function testOnlySpacesMayDiffer(string $pdf, string $sealed, ?string $expected): void
    {
        self::assertSame($expected, Reflect::call(Verificationpage::class, 'repairSpacing', $pdf, $sealed));
    }

    public function testASpaceCarryingMarksLostAtALineWrapStillMatches(): void
    {
        if (!class_exists('Normalizer')) {
            self::markTestSkipped('Needs the intl extension: only its Normalizer sorts combining marks.');
        }
        // "r" with two marks, then a space with two marks of its own. Without the space, normalization sorts all four
        // marks as one cluster (U+0336 and U+0335 before U+0317 and U+0316); with it, as two.
        $sealed = "r\u{0336}\u{0317} \u{0335}\u{0316}i";
        $pdf    = "r\u{0336}\u{0317}\u{0335}\u{0316}i";
        $read   = static fn(string $s): string => Reflect::call(Verificationpage::class, 'comparableText', PdfUtils::normalizeText($s));

        self::assertNull(Reflect::call(Verificationpage::class, 'repairSpacing', PdfUtils::normalizeText($pdf), PdfUtils::normalizeText($sealed)), 'respacing alone fails');
        self::assertSame($read($sealed), $read($pdf));
        self::assertNotSame($read($sealed), $read("r\u{0336}\u{0317}\u{0335}\u{0316}x"), 'a changed letter still differs');
    }
}
