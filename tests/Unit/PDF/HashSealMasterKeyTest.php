<?php

namespace FabricatorForms\Tests\Unit\PDF;

use FabricatorForms\PDF\HashSeal;
use FabricatorForms\Tests\Support\FakeWordPress;
use FabricatorForms\Tests\Support\Reflect;
use FabricatorForms\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * HashSeal with encryption chosen and FABRICATOR_SEAL_MASTER_KEY defined. Each test runs in its own process: the
 * constant cannot be undefined, and it would change every other HashSeal test.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class HashSealMasterKeyTest extends TestCase
{
    private const KEY_OPTION = 'fabricator_forms_seal_key';
    private const HISTORY    = 'fabricator_forms_seal_key_history';
    private const MASTER     = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private FakeWordPress $wp;

    protected function setUp(): void
    {
        parent::setUp();
        define('FABRICATOR_SEAL_MASTER_KEY', self::MASTER);
        $this->wp = FakeWordPress::install();
        $this->wp->options['fabricator_forms_seal_encryption'] = serialize('enabled');
    }

    public function testAKeyEncryptedWithAnotherMasterKeyIsRetiredAsStoredAndReplaced(): void
    {
        $foreign = HashSealTest::encryptWith(str_repeat('b', 64), str_repeat('c', 64));
        $this->wp->options[self::KEY_OPTION] = serialize(json_encode(['uuid' => 'u-enc', 'key' => $foreign]));

        self::assertSame('undecryptable', HashSeal::activeKeyProblem());
        try {
            HashSeal::generate(['key_id' => 'x']);
            self::fail('generate() must refuse an unreadable key');
        } catch (\RuntimeException $e) {
            self::assertNotSame('', $e->getMessage());
        }

        HashSeal::rotateKey(false, true);

        $history = get_option(self::HISTORY);
        $last    = end($history);
        self::assertSame(['u-enc', $foreign], [$last['uuid'], $last['key']], 'the unreadable key is kept exactly as stored');
        self::assertStringStartsWith('enc::', json_decode(get_option(self::KEY_OPTION), true)['key']);
        self::assertSame('', HashSeal::activeKeyProblem());
        self::assertSame('undecryptable-key', HashSeal::verify(['key_id' => 'u-enc'], 'h')['key_status']);
    }

    public function testWithTheMasterKeyAlreadySetOnlyTheKeysTheAdminTicksBecomeTrusted(): void
    {
        // The upgrade with FABRICATOR_SEAL_MASTER_KEY already in wp-config.php: unencrypted keys are refused, and
        // encrypting one makes it trusted, so a key written into the database must not come along with the admin's own.
        $mine    = str_repeat('1', 64);
        $planted = str_repeat('2', 64);
        $this->wp->options[self::KEY_OPTION] = serialize(json_encode(['uuid' => 'u-mine', 'key' => $mine]));
        $this->wp->options[self::HISTORY]    = serialize([
            ['uuid' => 'u-planted', 'key' => $planted, 'status' => 'rotated', 'retired_at' => '2026-01-02 03:04:05 UTC'],
        ]);

        // Listed with their fingerprints, which the backup file carries too: a planted key under a genuine UUID would
        // look right by its label, but not by its fingerprint. 128 bits, the first 32 hex of SHA-256 over the key.
        $fp = static fn(string $key): string => implode(' ', str_split(substr(hash('sha256', $key), 0, 32), 4));
        self::assertSame($fp($mine), HashSeal::keyFingerprint($mine));
        self::assertSame(
            [
                ['uuid' => 'u-mine', 'fingerprint' => $fp($mine), 'status' => 'active', 'date' => ''],
                ['uuid' => 'u-planted', 'fingerprint' => $fp($planted), 'status' => 'retired', 'date' => '2026-01-02 03:04:05 UTC'],
            ],
            HashSeal::unencryptedKeys()
        );

        HashSeal::encryptExistingKeys(['u-mine']);

        self::assertStringStartsWith('enc::', json_decode(get_option(self::KEY_OPTION), true)['key']);
        self::assertSame($planted, get_option(self::HISTORY)[0]['key'], 'left as stored, so still refused');
        self::assertSame(['u-planted'], array_column(HashSeal::unencryptedKeys(), 'uuid'));
        $payload = ['key_id' => 'u-mine', 'm' => 1];
        self::assertTrue(HashSeal::verify($payload, HashSeal::generate($payload))['valid']);
        self::assertFalse(HashSeal::verify(['key_id' => 'u-planted'], hash_hmac('sha256', '{"key_id":"u-planted"}', $planted))['valid']);
    }

    public function testAKeyRecordEditedInTheDatabaseIsUnreadableNotTrusted(): void
    {
        // The ciphertext is bound to its slot, UUID, status and "compromised" flag (AES-GCM associated data). Without that,
        // a database write could clear a leaked key's flag, or copy it into the active slot, and forged PDFs verified.
        HashSeal::createInitialKey();
        $payload = ['key_id' => HashSeal::getCurrentKeyId(), 'n' => 1];
        $hmac    = HashSeal::generate($payload);

        HashSeal::rotateKey(true, true); // retired as compromised: re-encrypted for its history entry
        self::assertSame(['valid' => true, 'key_status' => 'initial', 'compromised' => true], HashSeal::verify($payload, $hmac));

        $history = get_option(self::HISTORY);
        $cleared = $history;
        $cleared[0]['compromised'] = false;
        $this->wp->options[self::HISTORY] = serialize($cleared);
        self::assertSame('undecryptable-key', HashSeal::verify($payload, $hmac)['key_status'], 'flag cleared');

        $this->wp->options[self::HISTORY]    = serialize([]);
        $this->wp->options[self::KEY_OPTION] = serialize(json_encode(['uuid' => $history[0]['uuid'], 'key' => $history[0]['key']]));
        self::assertSame('undecryptable-key', HashSeal::verify($payload, $hmac)['key_status'], 'copied into the active slot');
    }

    public function testAnOldActiveRecordCopiedBackIsNeitherUsedNorTrustedAsActive(): void
    {
        // The old row still decrypts in the active slot, which its associated data names. Copied back after the key was
        // retired as compromised, it would make PDFs forged with the leaked key verify as "active", and seal new ones with it.
        HashSeal::createInitialKey();
        $old_row = $this->wp->options[self::KEY_OPTION];
        $payload = ['key_id' => HashSeal::getCurrentKeyId(), 'n' => 1];
        $forged  = HashSeal::generate($payload);
        HashSeal::rotateKey(true, true);

        $this->wp->options[self::KEY_OPTION] = $old_row;
        self::assertSame(['valid' => true, 'key_status' => 'initial', 'compromised' => true], HashSeal::verify($payload, $forged));
        self::assertSame('retired', HashSeal::activeKeyProblem());
        self::assertSame([], HashSeal::getActiveKeyInfo());
        try {
            HashSeal::generate(['key_id' => 'x']);
            self::fail('generate() must refuse a retired key');
        } catch (\RuntimeException $e) {
            self::assertNotSame('', $e->getMessage());
        }

        HashSeal::rotateKey(false, true);
        self::assertCount(1, get_option(self::HISTORY), 'the key keeps its one history entry, flags included');
        self::assertSame(unserialize($old_row), get_option('fabricator_forms_seal_key_damaged')[0]['value'], 'the copy is set aside');
        self::assertSame('', HashSeal::activeKeyProblem());
        self::assertTrue(HashSeal::verify($payload, $forged)['compromised']);
    }

    public function testUnderAnotherMasterKeyRotationRefusesUnlessTheOldOneIsLost(): void
    {
        // The site's keys were encrypted under master key B; wp-config.php now holds A (a typo, a swapped file). Rotating
        // would move the current key into the history still bound to the active slot, where B itself can't read it either.
        $b     = str_repeat('b', 64);
        $aad   = static fn(string $slot, string $uuid): string => Reflect::call(HashSeal::class, 'keyAad', $slot, $uuid);
        $row   = serialize(json_encode(['uuid' => 'u-b', 'key' => HashSealTest::encryptWith($b, str_repeat('c', 64), $aad('active', 'u-b'))]));
        $this->wp->options[self::KEY_OPTION]                          = $row;
        $this->wp->options['fabricator_forms_seal_master_check'] = serialize(HashSealTest::encryptWith($b, 'fabricator-master-key-check', $aad('check', '')));

        self::assertSame('wrong-master-key', HashSeal::activeKeyProblem());
        try {
            HashSeal::rotateKey(false, true);
            self::fail('rotation must refuse under another master key');
        } catch (\RuntimeException $e) {
            self::assertSame(HashSeal::WRONG_MASTER_KEY, $e->getCode());
        }
        self::assertSame($row, $this->wp->options[self::KEY_OPTION], 'the current key is left where B can read it');
        self::assertFalse(get_option(self::HISTORY));

        // The admin confirms B is gone for good: the key is moved as stored, and A becomes the master key.
        HashSeal::rotateKey(false, true, true);
        self::assertSame('u-b', get_option(self::HISTORY)[0]['uuid']);
        self::assertSame('', HashSeal::activeKeyProblem());
        HashSeal::rotateKey(false, true);
        self::assertCount(2, get_option(self::HISTORY));
    }

    public function testUnderTheRightMasterKeyADamagedRecordIsStillRetiredAsStored(): void
    {
        HashSeal::createInitialKey(); // records the check value under this master key
        $foreign = HashSealTest::encryptWith(str_repeat('b', 64), str_repeat('c', 64));
        $this->wp->options[self::KEY_OPTION] = serialize(json_encode(['uuid' => 'u-damaged', 'key' => $foreign]));

        self::assertSame('undecryptable', HashSeal::activeKeyProblem());
        HashSeal::rotateKey(false, true);
        self::assertSame($foreign, get_option(self::HISTORY)[0]['key']);
    }

    public function testAnEncryptedKeyRoundTrips(): void
    {
        HashSeal::createInitialKey();
        self::assertStringStartsWith('enc::', json_decode(get_option(self::KEY_OPTION), true)['key']);

        $payload = ['key_id' => HashSeal::getCurrentKeyId(), 'x' => 1];
        $v       = HashSeal::verify($payload, HashSeal::generate($payload));
        self::assertTrue($v['valid']);
        self::assertSame('active', $v['key_status']);
    }

    public function testKeysAreWrittenEncryptedWhileTheMasterKeyIsSetEvenBeforeEncryptionIsConfirmed(): void
    {
        // The upgrade window (constant pasted, confirm not yet clicked) or a leftover constant: the option does not say
        // "enabled". Written in plaintext by the option, such keys were refused by the constant, and so was every rotation.
        $this->wp->options['fabricator_forms_seal_encryption'] = serialize('disabled');

        HashSeal::createInitialKey();
        self::assertStringStartsWith('enc::', json_decode(get_option(self::KEY_OPTION), true)['key']);
        self::assertSame('', HashSeal::activeKeyProblem());

        HashSeal::rotateKey(false, true);
        self::assertStringStartsWith('enc::', json_decode(get_option(self::KEY_OPTION), true)['key']);
        $payload = ['key_id' => HashSeal::getCurrentKeyId(), 'w' => 1];
        self::assertTrue(HashSeal::verify($payload, HashSeal::generate($payload))['valid']);
    }

    public function testAnUnencryptedKeyIsRefusedAndStaysRefusedAfterRotation(): void
    {
        // With a master key configured every stored key is encrypted, so an unencrypted one was written into the database
        // by someone else: a key they know. It must never verify, and rotating must not encrypt it into a trusted one.
        $plain = str_repeat('d', 64);
        $this->wp->options[self::KEY_OPTION] = serialize(json_encode(['uuid' => 'u-plain', 'key' => $plain]));
        $payload = ['key_id' => 'u-plain', 'y' => 2];
        $hmac    = hash_hmac('sha256', (string) json_encode($payload), $plain);

        self::assertFalse(HashSeal::verify($payload, $hmac)['valid']);
        self::assertSame('undecryptable', HashSeal::activeKeyProblem(), 'the settings page warns about it');

        $history = (array) get_option(self::HISTORY, []);
        $history[] = ['uuid' => 'u-planted', 'key' => $plain, 'status' => 'rotated'];
        $this->wp->options[self::HISTORY] = serialize($history);
        $planted = ['key_id' => 'u-planted', 'z' => 3];
        self::assertFalse(HashSeal::verify($planted, hash_hmac('sha256', (string) json_encode($planted), $plain))['valid']);

        HashSeal::rotateKey(false, true);

        $history = get_option(self::HISTORY);
        $last    = end($history);
        self::assertSame('u-plain', $last['uuid']);
        self::assertSame($plain, $last['key'], 'retired as stored, not encrypted into a trusted key');
        self::assertFalse(HashSeal::verify($payload, $hmac)['valid']);
        self::assertSame('', HashSeal::activeKeyProblem(), 'the new active key is encrypted and usable');
    }
}
