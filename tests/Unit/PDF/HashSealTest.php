<?php

namespace FabricatorForms\Tests\Unit\PDF;

use FabricatorForms\PDF\HashSeal;
use FabricatorForms\Tests\Support\FakeWordPress;
use FabricatorForms\Tests\Support\TestCase;

/**
 * HashSeal's key handling fails closed: nothing mints a key behind the admin's back, a damaged or unreadable key is
 * never silently replaced, and every retired key keeps verifying the PDFs it signed.
 *
 * Encryption with FABRICATOR_SEAL_MASTER_KEY defined is in HashSealMasterKeyTest, which runs in its own process
 * because a PHP constant cannot be undefined again.
 */
final class HashSealTest extends TestCase
{
    private const KEY_OPTION = 'fabricator_forms_seal_key';
    private const HISTORY    = 'fabricator_forms_seal_key_history';
    private const PENDING    = 'fabricator_forms_seal_key_pending_download';

    private FakeWordPress $wp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->wp = FakeWordPress::install();
    }

    public function testGenerateWithoutAKeyThrowsAndMintsNothing(): void
    {
        self::assertThrows(static fn() => HashSeal::generate(['key_id' => 'x']));
        self::assertArrayNotHasKey(self::KEY_OPTION, $this->wp->options);
    }

    public function testVerifyWithoutAKeyReportsAnUnknownKeyAndMintsNothing(): void
    {
        $v = HashSeal::verify(['key_id' => 'abc'], 'h');
        self::assertFalse($v['valid']);
        self::assertSame('unknown-key', $v['key_status']);
        self::assertArrayNotHasKey(self::KEY_OPTION, $this->wp->options);
        self::assertSame('missing', HashSeal::activeKeyProblem());
    }

    public function testCreateInitialKeyCreatesOnceAndOffersOneDownload(): void
    {
        $this->wp->cache['options']['notoptions'] = [self::KEY_OPTION => true];

        self::assertTrue(HashSeal::createInitialKey());
        $first = $this->wp->options[self::KEY_OPTION];
        self::assertArrayHasKey(self::PENDING, $this->wp->transients);
        self::assertArrayNotHasKey(self::KEY_OPTION, $this->wp->cache['options']['notoptions'] ?? [], 'cached "no such option" cleared');

        unset($this->wp->transients[self::PENDING]);
        self::assertFalse(HashSeal::createInitialKey(), 'a second call is a no-op');
        self::assertSame($first, $this->wp->options[self::KEY_OPTION]);
        self::assertArrayNotHasKey(self::PENDING, $this->wp->transients);
        self::assertSame('', HashSeal::activeKeyProblem());
    }

    public function testCreateInitialKeyRequiresManageOptions(): void
    {
        $this->wp->capabilities = [];
        self::assertThrows(static fn() => HashSeal::createInitialKey());
        self::assertArrayNotHasKey(self::KEY_OPTION, $this->wp->options);
    }

    public function testASealVerifiesAsActiveAndATamperedPayloadDoesNot(): void
    {
        HashSeal::createInitialKey();
        $payload = self::payload();
        $hmac    = HashSeal::generate($payload);

        $v = HashSeal::verify($payload, $hmac);
        self::assertTrue($v['valid']);
        self::assertSame('active', $v['key_status']);

        $tampered      = $payload;
        $tampered['x'] = 2;
        $v             = HashSeal::verify($tampered, $hmac);
        self::assertFalse($v['valid']);
        self::assertNull($v['key_status'], 'a known key with a wrong HMAC is tampering, not an unknown key');
    }

    public function testADamagedRecordIsReportedAndSetAsideByRotationNeverOverwritten(): void
    {
        $this->wp->options[self::KEY_OPTION] = serialize('{broken');

        self::assertSame('damaged', HashSeal::activeKeyProblem());
        self::assertThrows(static fn() => HashSeal::generate(['key_id' => 'x']));
        self::assertFalse(HashSeal::createInitialKey());
        self::assertSame('{broken', unserialize($this->wp->options[self::KEY_OPTION]), 'createInitialKey leaves it alone');

        $new = HashSeal::rotateKey(false, true);
        self::assertSame('{broken', unserialize($this->wp->options['fabricator_forms_seal_key_damaged'])[0]['value']);
        self::assertSame([], get_option(self::HISTORY, []), 'a damaged record is not a key to retire');
        self::assertSame('', HashSeal::activeKeyProblem());
        self::assertSame($new['uuid'], HashSeal::getCurrentKeyId());
    }

    public function testRetiredKeysKeepVerifyingWithTheirStatus(): void
    {
        HashSeal::createInitialKey();
        $p1 = self::payload();
        $h1 = HashSeal::generate($p1);
        HashSeal::rotateKey(false, true);

        $v = HashSeal::verify($p1, $h1);
        self::assertTrue($v['valid']);
        self::assertSame('initial', $v['key_status']);

        $p2 = self::payload();
        $h2 = HashSeal::generate($p2);
        HashSeal::rotateKey(true, true);

        $v = HashSeal::verify($p2, $h2);
        self::assertTrue($v['valid']);
        self::assertSame('rotated', $v['key_status']);
        self::assertTrue($v['compromised']);
    }

    public function testRotatingAMissingRecordAddsNoHistoryEntry(): void
    {
        HashSeal::createInitialKey();
        HashSeal::rotateKey(false, true);
        $before = count(get_option(self::HISTORY));

        unset($this->wp->options[self::KEY_OPTION]);
        HashSeal::rotateKey(false, true);

        self::assertCount($before, get_option(self::HISTORY));
        self::assertSame('', HashSeal::activeKeyProblem());
    }

    public function testRotationRequiresManageOptionsAndANonce(): void
    {
        HashSeal::createInitialKey();
        $key = $this->wp->options[self::KEY_OPTION];

        $this->wp->capabilities = [];
        self::assertThrows(static fn() => HashSeal::rotateKey(false, true));

        $this->wp->capabilities = ['manage_options' => true];
        $this->wp->nonceValid   = false;
        self::assertThrows(static fn() => HashSeal::rotateKey(false));

        self::assertSame($key, $this->wp->options[self::KEY_OPTION]);
    }

    public function testRotationRefusesWhileAnotherRotationHoldsTheLock(): void
    {
        HashSeal::createInitialKey();
        $key = $this->wp->options[self::KEY_OPTION];
        // A live lock row, as a rotation running in another request leaves it (OptionMutex).
        $this->wp->options['fabricator_lock_opt_' . self::HISTORY] = serialize((string) (time() + 60));

        self::assertThrows(static fn() => HashSeal::rotateKey(false, true));
        self::assertSame($key, $this->wp->options[self::KEY_OPTION], 'the key is unchanged');
        self::assertSame([], get_option(self::HISTORY, []), 'nothing retired');
    }

    public function testChoosingEncryptionWithoutTheMasterKeyRefusesToWriteAKey(): void
    {
        HashSeal::createInitialKey();
        $this->wp->options['fabricator_forms_seal_encryption'] = serialize('enabled');
        $key = $this->wp->options[self::KEY_OPTION];

        self::assertThrows(static fn() => HashSeal::rotateKey(false, true));
        self::assertSame($key, $this->wp->options[self::KEY_OPTION]);

        unset($this->wp->options[self::KEY_OPTION]);
        self::assertThrows(static fn() => HashSeal::createInitialKey());
        self::assertArrayNotHasKey(self::KEY_OPTION, $this->wp->options);
    }

    public function testAnEncryptedKeyWithoutTheMasterKeyIsUndecryptableNotAnError(): void
    {
        $this->wp->options[self::KEY_OPTION] = serialize(json_encode([
            'uuid' => 'u-enc',
            'key'  => self::encryptWith(str_repeat('b', 64), str_repeat('c', 64)),
        ]));

        self::assertSame('undecryptable', HashSeal::activeKeyProblem());
        self::assertSame('undecryptable-key', HashSeal::verify(['key_id' => 'u-enc'], 'h')['key_status']);
    }

    /**
     * A payload signed with whatever key is active now.
     *
     * @return array<string, mixed>
     */
    private static function payload(): array
    {
        return ['generated' => 'now', 'key_id' => HashSeal::getCurrentKeyId(), 'x' => 1];
    }

    /**
     * The "enc::" format HashSeal writes: AES-256-GCM, base64(iv . tag . ciphertext).
     */
    public static function encryptWith(string $hex_master, string $plain): string
    {
        $iv  = random_bytes(12);
        $tag = '';
        $ct  = openssl_encrypt($plain, 'aes-256-gcm', (string) hex2bin($hex_master), OPENSSL_RAW_DATA, $iv, $tag);
        return 'enc::' . base64_encode($iv . $tag . $ct);
    }

    private static function assertThrows(callable $fn): void
    {
        try {
            $fn();
        } catch (\RuntimeException $e) {
            self::assertNotSame('', $e->getMessage());
            return;
        }
        self::fail('expected a RuntimeException');
    }
}
