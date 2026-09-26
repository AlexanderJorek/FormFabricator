<?php

namespace FabricatorForms\Tests\Support;

/**
 * Time and memory measurements for the perf suite.
 *
 * Absolute time limits make a suite flaky on slow CI runners, so the main time assertion is a growth exponent: the same
 * shape is timed at size n and 4n, and k in "time grows as n^k" is read off the two. A linear scan comes out near 1, a
 * quadratic one near 2 — whatever the machine's speed. Quadrupling rather than doubling keeps the two far apart even
 * when a fixed per-run cost flattens the curve (a doubling ratio put a real quadratic regression at x3.01, against x2
 * for linear).
 */
final class Measure
{
    /**
     * Runs $fn and returns its wall time and the peak memory it used beyond what was allocated before it started.
     *
     * @param callable $fn The work to measure.
     * @return array{seconds: float, peak: int, result: mixed}
     */
    public static function run(callable $fn): array
    {
        gc_collect_cycles();
        $before = memory_get_usage();
        memory_reset_peak_usage();
        $t0     = hrtime(true);
        $result = $fn();
        $secs   = (hrtime(true) - $t0) / 1e9;
        $peak   = memory_get_peak_usage() - $before;
        return ['seconds' => $secs, 'peak' => max(0, $peak), 'result' => $result];
    }

    /**
     * Best of three runs, which filters out a one-off pause (GC, scheduler) better than an average.
     *
     * @param callable $fn The work to time.
     */
    public static function seconds(callable $fn): float
    {
        $best = INF;
        for ($i = 0; $i < 3; $i++) {
            $best = min($best, self::run($fn)['seconds']);
            if ($best > 1.0) {
                break; // already far past any noise; repeating would only triple a regression's run time
            }
        }
        return $best;
    }

    /**
     * The exponent k in "time grows as n^k" for a scan, from runs at n and 4n.
     *
     * The base size is grown until one run takes at least $min_seconds, so the exponent is not measuring timer noise. A
     * scan that stays under that even at the largest size is reported as not trusted: it is fast in absolute terms, and
     * the caller asserts a time bound instead.
     *
     * @param callable $build Builds the input for a size: fn(int $n): string (bytes, or a path to a file it wrote).
     * @param callable $scan  The scan under test: fn(string $input): mixed.
     * @param int      $n     Starting size.
     * @param float    $min_seconds Shortest base run the exponent is trusted at.
     * @param int      $max_n Largest base size to try.
     * @param int      $max_bytes Largest base input to build, when the input is the bytes themselves.
     * @return array{exponent: float, n: int, base: float, grown: float, trusted: bool}
     */
    public static function growthExponent(
        callable $build,
        callable $scan,
        int $n,
        float $min_seconds = 0.03,
        int $max_n = PHP_INT_MAX,
        int $max_bytes = 8388608
    ): array {
        while (true) {
            $input = $build($n);
            $base  = self::seconds(static fn() => $scan($input));
            $large = is_string($input) && strlen($input) > 4096 && strlen($input) * 2 > $max_bytes; // > 4 KB: not a path
            if ($base >= $min_seconds || $n * 2 > $max_n || $large) {
                break;
            }
            $n *= 2;
        }
        unset($input);
        $input = $build($n * 4);
        $grown = self::seconds(static fn() => $scan($input));
        return [
            'exponent' => log(max($grown, 1e-9) / max($base, 1e-9), 4),
            'n'        => $n,
            'base'     => $base,
            'grown'    => $grown,
            'trusted'  => $base >= $min_seconds,
        ];
    }
}
