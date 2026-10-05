<?php

namespace FabricatorForms\Tests\Unit\Utils;

use Brain\Monkey\Functions;
use FabricatorForms\Tests\Support\TestCase;
use FabricatorForms\Utils\ClientIp;

/**
 * Trusted proxy ranges wide enough to trust a large part of the internet are warned about on the settings page: any
 * visitor inside one can claim another address and pass the per-address sending limit.
 */
final class ClientIpTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('sanitize_text_field')->alias(static fn($s) => trim(strip_tags((string) $s)));
        Functions\when('wp_unslash')->returnArg(1);
    }

    public function testCatchAllAndVeryWideRangesAreReported(): void
    {
        Functions\when('get_option')->justReturn('0.0.0.0/0, ::/0, 10.0.0.0/7, 2000::/15, 203.0.113.10');

        self::assertSame(['0.0.0.0/0', '::/0', '10.0.0.0/7', '2000::/15'], ClientIp::wideTrustedEntries());
    }

    public function testAForwardedAddressFromAnUntrustedSenderIsIgnored(): void
    {
        // Anyone can send X-Forwarded-For; only a trusted proxy's is believed.
        \FabricatorForms\Tests\Support\FakeWordPress::install();
        $_SERVER['REMOTE_ADDR']          = '203.0.113.9';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.20';
        try {
            self::assertSame('203.0.113.9', ClientIp::resolve());
        } finally {
            unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR']);
        }
    }

    public function testOrdinaryProxyAndCdnRangesAreNot(): void
    {
        // Cloudflare's widest published ranges, a private network, and single addresses.
        Functions\when('get_option')->justReturn('104.16.0.0/13, 2400:cb00::/32, 10.0.0.0/8, 2001:db8::/16, 198.51.100.7, ::1');

        self::assertSame([], ClientIp::wideTrustedEntries());
    }
}
