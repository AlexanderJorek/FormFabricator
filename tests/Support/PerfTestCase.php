<?php

namespace FabricatorForms\Tests\Support;

/**
 * Base case for the perf suite.
 *
 * A quadratic regression on the fixed-size shapes (700,000 bare keywords, 12 MB of tiny streams) does not fail, it runs
 * for tens of minutes. PHPUnit's own time limits need pcntl, which Windows lacks, so each test gets a fresh PHP time
 * limit instead: a regression then ends the run with "Maximum execution time exceeded" pointing at the test, rather
 * than hanging the build. The clean suite takes well under a second per test.
 */
abstract class PerfTestCase extends TestCase
{
    private const SECONDS_PER_TEST = 60;

    private static string $running = '';
    private static bool $shutdownHooked = false;

    protected function setUp(): void
    {
        parent::setUp();
        self::$running = static::class . '::' . $this->name();
        if (!self::$shutdownHooked) {
            self::$shutdownHooked = true;
            // The fatal ends PHPUnit before it prints its summary, so name the test here.
            register_shutdown_function(static function (): void {
                $error = error_get_last();
                if ($error !== null && str_contains($error['message'], 'Maximum execution time') && self::$running !== '') {
                    fwrite(STDERR, "\nPerf test exceeded " . self::SECONDS_PER_TEST . ' s (a scan has likely turned quadratic): ' . self::$running . "\n");
                }
            });
        }
        set_time_limit(self::SECONDS_PER_TEST);
    }

    protected function tearDown(): void
    {
        set_time_limit(0);
        self::$running = '';
        parent::tearDown();
    }
}
