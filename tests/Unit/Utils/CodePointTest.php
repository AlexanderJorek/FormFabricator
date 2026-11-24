<?php

namespace FabricatorForms\Tests\Unit\Utils;

use FabricatorForms\Tests\Support\TestCase;
use FabricatorForms\Utils\Text\CodePoint;

/**
 * CodePoint does what mb_ord() and mb_chr() do, which the shipped code doesn't call (Plugin Check dates them to
 * WordPress 7.1). The two are the oracle here, on every code point.
 */
final class CodePointTest extends TestCase
{
    public function testEveryCodePointGoesBothWaysAsMbstringDoes(): void
    {
        $wrong = [];
        for ($code = 0; $code <= 0x10FFFF; $code++) {
            $expected = mb_chr($code, 'UTF-8');
            $char     = CodePoint::char($code);
            if (($expected === false ? null : $expected) !== $char) {
                $wrong[] = sprintf('char U+%04X', $code);
            } elseif ($char !== null && CodePoint::of($char) !== mb_ord($char, 'UTF-8')) {
                $wrong[] = sprintf('of U+%04X', $code);
            }
            if (count($wrong) >= 5) {
                break;
            }
        }
        self::assertSame([], $wrong);
    }

    public function testOfReadsTheFirstCharacter(): void
    {
        self::assertSame(mb_ord('€uro', 'UTF-8'), CodePoint::of('€uro'));
        self::assertSame(0x1F468, CodePoint::of("\u{1F468}\u{200D}\u{1F469}"));
        self::assertSame(0, CodePoint::of(''));
    }
}
