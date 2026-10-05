<?php

namespace FabricatorForms\Tests\Integration\Utils;

use FabricatorForms\Tests\Integration\TestCase;
use FabricatorForms\Utils\ConcurrencySlot;
use FabricatorForms\Utils\OptionMutex;
use FabricatorForms\Utils\RateLimiter;
use FabricatorForms\Utils\SingleUseToken;

/**
 * The helpers that let the database decide a race (RateLimiter, SingleUseToken, ConcurrencySlot, OptionMutex),
 * against real MySQL/MariaDB: the unit suite's fake $wpdb only sees which queries are issued.
 */
final class DatabaseHelpersTest extends TestCase
{
    private static function row(string $name): ?string
    {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name));
    }

    private static function setRow(string $name, string $value): void
    {
        global $wpdb;
        $wpdb->replace($wpdb->options, ['option_name' => $name, 'option_value' => $value, 'autoload' => 'no']);
    }

    private static function rowsLike(string $prefix): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like($prefix) . '%'));
    }

    // ---- RateLimiter ----

    public function testTheRateLimiterCountsWithinItsWindow(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            self::assertSame($i, RateLimiter::increment('it_counts', 300));
        }
        self::assertSame(11, RateLimiter::increment('it_counts', 300), 'the 11th send in the window is the one the form refuses');
        self::assertEqualsWithDelta(300, RateLimiter::secondsUntilReset('it_counts'), 2);
    }

    public function testAnExpiredWindowStartsOverAtOne(): void
    {
        self::setRow('fabricator_rl_it_expired', '9|' . (time() - 1));
        self::assertSame(1, RateLimiter::increment('it_expired', 300));
        self::assertSame(0, RateLimiter::secondsUntilReset('it_missing'));
    }

    public function testTheRateLimiterSweepRemovesOnlyExpiredCounters(): void
    {
        self::setRow('fabricator_rl_it_old', '3|' . (time() - 10));
        RateLimiter::increment('it_live', 300);
        RateLimiter::cronSweepExpired();
        self::assertNull(self::row('fabricator_rl_it_old'));
        self::assertNotNull(self::row('fabricator_rl_it_live'));
    }

    // ---- SingleUseToken ----

    public function testAClaimSucceedsExactlyOnceUntilReleased(): void
    {
        self::assertTrue(SingleUseToken::claim('it_claim', 60));
        self::assertFalse(SingleUseToken::claim('it_claim', 60), 'a duplicate submission is refused');
        SingleUseToken::release('it_claim');
        self::assertTrue(SingleUseToken::claim('it_claim', 60), 'a released claim can be retried');
    }

    public function testAnIssuedTokenIsBoundToItsFormAndItsSignature(): void
    {
        $token = SingleUseToken::issue(7);
        self::assertTrue(SingleUseToken::verifyIssued($token, 7));
        self::assertFalse(SingleUseToken::verifyIssued($token, 8), 'another form');
        self::assertFalse(SingleUseToken::verifyIssued(substr($token, 0, -1) . (substr($token, -1) === '0' ? '1' : '0'), 7), 'tampered signature');
        [$id, , $mac] = explode('.', $token);
        self::assertFalse(SingleUseToken::verifyIssued($id . '.' . (time() - SingleUseToken::ISSUED_MAX_AGE - 1) . '.' . $mac, 7), 'expired');
        self::assertSame(0, self::rowsLike('fabricator_su_'), 'issuing writes nothing');
    }

    public function testTheClaimSweepRemovesOnlyExpiredClaims(): void
    {
        self::setRow('fabricator_su_it_old', (string) (time() - 1));
        SingleUseToken::claim('it_live', 60);
        SingleUseToken::cronSweepExpired();
        self::assertNull(self::row('fabricator_su_it_old'));
        self::assertNotNull(self::row('fabricator_su_it_live'));
    }

    // ---- ConcurrencySlot ----

    public function testSlotsAreAdmittedUpToTheByteBudget(): void
    {
        $a = ConcurrencySlot::reserve('it_bytes', 60, 100, 60);
        self::assertIsString($a);
        self::assertFalse(ConcurrencySlot::reserve('it_bytes', 50, 100, 60), '60 + 50 is over a budget of 100');
        self::assertSame(1, self::rowsLike('fabricator_cs_it_bytes_'), 'a refused reservation removes its own row');
        $b = ConcurrencySlot::reserve('it_bytes', 40, 100, 60);
        self::assertIsString($b, '60 + 40 fits exactly');
        self::assertSame(100, ConcurrencySlot::reservedBytes('it_bytes'));

        ConcurrencySlot::release('it_bytes', $a);
        self::assertSame(40, ConcurrencySlot::reservedBytes('it_bytes'));
        self::assertIsString(ConcurrencySlot::reserve('it_bytes', 60, 100, 60), 'a released slot frees its bytes');
    }

    public function testSlotsAreCappedByHolderCountAndIgnoreExpiredHolders(): void
    {
        self::assertIsString(ConcurrencySlot::reserve('it_holders', 1, 1000, 60, 2));
        self::assertIsString(ConcurrencySlot::reserve('it_holders', 1, 1000, 60, 2));
        self::assertFalse(ConcurrencySlot::reserve('it_holders', 1, 1000, 60, 2), 'a third holder past max_holders = 2');

        self::setRow('fabricator_cs_it_dead_abc', (time() - 1) . '|900');
        self::assertIsString(ConcurrencySlot::reserve('it_dead', 500, 1000, 60), 'an expired holder does not count');
        self::assertFalse(ConcurrencySlot::reserve('it_huge', 2000, 1000, 60), 'more than the empty budget is never admitted');
        self::assertSame(0, self::rowsLike('fabricator_cs_it_huge_'), '... and costs no write');
    }

    // ---- OptionMutex ----

    public function testTheMutexRunsTheCallbackAndReleasesTheLock(): void
    {
        $result = OptionMutex::run('it_option', static fn() => 'done', 200, true);
        self::assertSame('done', $result);
        self::assertNull(self::row('fabricator_lock_opt_it_option'));
    }

    public function testALiveLockIsWaitedOnThenRefusedWhenFailClosed(): void
    {
        self::setRow('fabricator_lock_opt_it_busy', (string) (time() + 60));
        $ran = false;
        try {
            OptionMutex::run('it_busy', static function () use (&$ran): void {
                $ran = true;
            }, 150, true);
            self::fail('a held lock must refuse when fail-closed');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Lock busy', $e->getMessage());
        }
        self::assertFalse($ran);
    }

    public function testAStaleLockIsBrokenRatherThanWaitedOnForever(): void
    {
        self::setRow('fabricator_lock_opt_it_stale', (string) (time() - 1));
        self::assertSame('ran', OptionMutex::run('it_stale', static fn() => 'ran', 500, true));
    }

    public function testASlowHolderDoesNotReleaseTheLockOfTheRequestThatBrokeIt(): void
    {
        // The holder outlives STALE_AFTER: another request breaks its lock and takes it. When the slow holder finishes,
        // it must leave that request's row alone, or a third request walks in beside it.
        OptionMutex::run('it_slow', static function (): void {
            self::setRow('fabricator_lock_opt_it_slow', '9999999999:someoneelse');
        }, 200, true);

        self::assertSame('9999999999:someoneelse', self::row('fabricator_lock_opt_it_slow'));
    }

    public function testTheLockIsReleasedWhenTheCallbackThrows(): void
    {
        try {
            OptionMutex::run('it_throws', static function (): void {
                throw new \DomainException('boom');
            }, 200, true);
        } catch (\DomainException $e) {
            self::assertSame('boom', $e->getMessage());
        }
        self::assertNull(self::row('fabricator_lock_opt_it_throws'));
    }
}
