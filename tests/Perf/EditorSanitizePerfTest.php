<?php

namespace FabricatorForms\Tests\Perf;

use FabricatorForms\Admin\FormEditor;
use FabricatorForms\Tests\Support\Measure;
use FabricatorForms\Tests\Support\PerfTestCase;
use FabricatorForms\Tests\Support\Reflect;

/**
 * An email body saved or imported by anyone with the (delegable) edit_forms right is cleaned in linear time, whatever
 * its shape, including many unclosed <iframe/<object openers.
 */
final class EditorSanitizePerfTest extends PerfTestCase
{
    public function testUnclosedOpenersAreStrippedInLinearTime(): void
    {
        $build = static fn(int $n): string => str_repeat('<iframe src=x> text ', $n);
        $strip = static fn(string $html): string => Reflect::call(FormEditor::class, 'stripElements', $html, ['iframe', 'object']);

        self::assertSame('', $strip('<iframe src=x>'), 'an unclosed opener is removed to its ">"');
        $r = Measure::settledGrowthExponent(1.5, $build, $strip, 2000, 0.05, 200000);
        if (!$r['trusted']) {
            self::assertLessThan(0.25, $r['grown'], sprintf('n=%d took %.3f s', $r['n'] * 4, $r['grown']));
            return;
        }
        self::assertLessThan(1.5, $r['exponent'], sprintf('n=%d: %.3f s, 4n: %.3f s, time grows as n^%.2f', $r['n'], $r['base'], $r['grown'], $r['exponent']));
    }
}
