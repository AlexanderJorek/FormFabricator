<?php

namespace FabricatorForms\Tests\Unit\PDF;

use FabricatorForms\PDF\PdfUtils;
use FabricatorForms\Tests\Support\TestCase;

/**
 * PdfUtils::nextAt() promises exactly strpos()'s answer for every call, whatever order the offsets come in; it may
 * only save the search. Its speed is covered by the perf suite's definition-index shapes.
 */
final class NextAtTest extends TestCase
{
    public function testAlwaysAnswersAsStrposDoesOnRandomWalks(): void
    {
        mt_srand(20260929);
        $needles = ['endobj', 'stream', 'endstream', 'obj'];
        $parts   = ['endobj', 'stream', 'endstream', ' obj ', 'x', "\n", 'end', 'str'];
        for ($case = 0; $case < 2000; $case++) {
            $haystack = '';
            for ($i = mt_rand(0, 30); $i > 0; $i--) {
                $haystack .= $parts[mt_rand(0, count($parts) - 1)];
            }
            $cache  = [];
            $offset = 0;
            for ($step = 0; $step < 40; $step++) {
                // Mostly forward, as the walks use it; sometimes backwards, which must search afresh.
                $offset = mt_rand(0, 4) === 0 ? mt_rand(0, strlen($haystack)) : min(strlen($haystack), $offset + mt_rand(0, 8));
                $needle = $needles[mt_rand(0, count($needles) - 1)];
                self::assertSame(
                    strpos($haystack, $needle, $offset),
                    PdfUtils::nextAt($haystack, $needle, $offset, $cache),
                    "case $case, step $step: '$needle' from $offset in " . json_encode($haystack)
                );
            }
        }
    }
}
