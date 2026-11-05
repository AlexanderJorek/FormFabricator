<?php

namespace FabricatorForms\Tests\Unit\Utils;

use Brain\Monkey\Functions;
use FabricatorForms\Tests\Support\FieldStubs;
use FabricatorForms\Tests\Support\TestCase;
use FabricatorForms\Utils\Text\SigningTime;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A signing or consent record names its day unambiguously: the site's date format when it states the year, the month
 * and the day, Y-m-d when it leaves one out (GDPR Art. 7(1): consent must be demonstrable).
 */
final class SigningTimeTest extends TestCase
{
    /** 2026-10-15 22:30:00 UTC. */
    private const MOMENT = 1792103400;

    protected function setUp(): void
    {
        parent::setUp();
        FieldStubs::install();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function formats(): iterable
    {
        yield 'a complete format stays'          => ['d.m.Y', '15.10.2026, 22:30:00 UTC'];
        yield 'with the month spelled out'       => ['F j, Y', 'October 15, 2026, 22:30:00 UTC'];
        yield 'a two-digit year counts'          => ['y/m/d', '26/10/15, 22:30:00 UTC'];
        yield 'a whole-date character counts'    => ['c', '2026-10-15T22:30:00+00:00, 22:30:00 UTC'];
        yield 'no year'                          => ['F j', '2026-10-15, 22:30:00 UTC'];
        yield 'no year, a weekday instead'       => ['l, F j', '2026-10-15, 22:30:00 UTC'];
        yield 'no year, short names'             => ['D, M j', '2026-10-15, 22:30:00 UTC'];
        yield 'a complete format with a weekday' => ['l, j. F Y', 'Thursday, 15. October 2026, 22:30:00 UTC'];
        yield 'no day'                           => ['F Y', '2026-10-15, 22:30:00 UTC'];
        yield 'no month'                         => ['j Y', '2026-10-15, 22:30:00 UTC'];
        yield 'a weekday names no date'          => ['l, F Y', '2026-10-15, 22:30:00 UTC'];
        yield 'an escaped letter is no year'     => ['j. F \Y', '2026-10-15, 22:30:00 UTC'];
        yield 'an empty format'                  => ['', '2026-10-15, 22:30:00 UTC'];
    }

    #[DataProvider('formats')]
    public function testTheRecordNamesTheDayWhateverTheSitesFormat(string $format, string $expected): void
    {
        Functions\when('get_option')->alias(static fn($key, $default = false) => $key === 'date_format' ? $format : $default);

        self::assertSame($expected, SigningTime::format(self::MOMENT));
    }
}
