<?php

namespace FabricatorForms\Tests\Unit\PDF;

use FabricatorForms\PDF\HashSeal;
use FabricatorForms\Tests\Support\FakeWordPress;
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

    public function testAnEncryptedKeyRoundTrips(): void
    {
        HashSeal::createInitialKey();
        self::assertStringStartsWith('enc::', json_decode(get_option(self::KEY_OPTION), true)['key']);

        $payload = ['key_id' => HashSeal::getCurrentKeyId(), 'x' => 1];
        $v       = HashSeal::verify($payload, HashSeal::generate($payload));
        self::assertTrue($v['valid']);
        self::assertSame('active', $v['key_status']);
    }

    public function testAPlaintextKeyIsEncryptedOnRetirementAndStillVerifies(): void
    {
        $plain = str_repeat('d', 64);
        $this->wp->options[self::KEY_OPTION] = serialize(json_encode(['uuid' => 'u-plain', 'key' => $plain]));
        $payload = ['key_id' => 'u-plain', 'y' => 2];
        $hmac    = hash_hmac('sha256', (string) json_encode($payload), $plain);

        HashSeal::rotateKey(false, true);

        $history = get_option(self::HISTORY);
        $last    = end($history);
        self::assertSame('u-plain', $last['uuid']);
        self::assertStringStartsWith('enc::', $last['key']);
        self::assertTrue(HashSeal::verify($payload, $hmac)['valid']);
    }
}
