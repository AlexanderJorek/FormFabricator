<?php

namespace FabricatorForms\Tests\Integration\Admin;

use FabricatorForms\Admin\FormSettings;
use FabricatorForms\PDF\HashSeal;
use FabricatorForms\Tests\Integration\AjaxTestCase;
use FabricatorForms\Tests\Support\Overrides;
use FabricatorForms\Tests\Support\Reflect;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * The Security card on FormFabricator → Settings (TESTING.md §1): "Standard — unencrypted" with its switch to encrypted
 * storage, "Encrypted", and "Encrypted — master key missing". A test that defines FABRICATOR_SEAL_MASTER_KEY runs in its
 * own process, since no later test may see the constant; editing wp-config.php itself is the part left to a person.
 */
final class SealKeyCardTest extends AjaxTestCase
{
    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- overrides WP_UnitTestCase's snake_case fixture method.
    public function set_up(): void
    {
        parent::set_up();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        // The plugin loads the admin classes only when is_admin(), which the test bootstrap is not; hook them as
        // admin-ajax.php would.
        FormSettings::init();
        delete_option('fabricator_forms_seal_setup_done');
        delete_option('fabricator_forms_seal_encryption');
        delete_option('fabricator_forms_seal_key');
        delete_option('fabricator_forms_seal_key_history');
        delete_option('fabricator_forms_seal_master_check');
        delete_transient('fabricator_forms_seal_key_pending_download');
    }

    public function testAStandardSiteOffersTheSwitchWithADefineLine(): void
    {
        $this->setUpStandard();

        $card = $this->securityCard();
        self::assertStringContainsString('Standard — unencrypted', $card);
        self::assertStringContainsString('id="fabricator-upgrade-enc-btn"', $card);

        $upgrade = $this->ajax('fabricator_upgrade_get_master_key', ['nonce' => wp_create_nonce('fabricator_seal_setup')]);
        self::assertTrue($upgrade['success'], wp_json_encode($upgrade));
        self::assertMatchesRegularExpression("/^define\\('FABRICATOR_SEAL_MASTER_KEY', '[0-9a-f]{64}'\\);$/", $upgrade['data']['define_line']);
        self::assertArrayNotHasKey('existing', $upgrade['data']);
        self::assertNotSame('enabled', get_option('fabricator_forms_seal_encryption'), 'nothing is committed until the line is confirmed');
    }

    public function testConfirmingWithoutTheLineInWpConfigChangesNothing(): void
    {
        $this->setUpStandard();
        $this->ajax('fabricator_upgrade_get_master_key', ['nonce' => wp_create_nonce('fabricator_seal_setup')]);

        $confirm = $this->ajax('fabricator_setup_confirm_secure', ['nonce' => wp_create_nonce('fabricator_seal_setup')]);

        self::assertFalse($confirm['success']);
        self::assertStringContainsString('FABRICATOR_SEAL_MASTER_KEY not found', $confirm['data']['message']);
        self::assertStringContainsString('Standard — unencrypted', $this->securityCard());
    }

    public function testEncryptedStorageWithoutTheMasterKeySaysSoInsteadOfOfferingTheUpgrade(): void
    {
        // Encrypted storage chosen, then the define() line removed from wp-config.php (this process never defines it).
        $this->setUpStandard();
        update_option('fabricator_forms_seal_encryption', 'enabled', false);

        $card = $this->securityCard();

        self::assertStringContainsString('Encrypted — master key missing', $card);
        self::assertStringNotContainsString('id="fabricator-upgrade-enc-btn"', $card);
        self::assertStringNotContainsString('Keys are secured with AES-256-GCM', $card);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTheIssuedLineOnceInWpConfigTurnsTheCardEncrypted(): void
    {
        $this->setUpStandard();
        $upgrade = $this->ajax('fabricator_upgrade_get_master_key', ['nonce' => wp_create_nonce('fabricator_seal_setup')]);
        self::assertSame(1, preg_match("/'([0-9a-f]{64})'/", $upgrade['data']['define_line'], $master));

        define('FABRICATOR_SEAL_MASTER_KEY', $master[1]); // the admin pasted the line into wp-config.php
        $confirm = $this->ajax('fabricator_setup_confirm_secure', ['nonce' => wp_create_nonce('fabricator_seal_setup')]);

        self::assertTrue($confirm['success'], wp_json_encode($confirm));
        $card = $this->securityCard();
        self::assertStringContainsString('Keys are secured with AES-256-GCM', $card);
        self::assertStringNotContainsString('Standard — unencrypted', $card);
        self::assertSame('', HashSeal::activeKeyProblem(), 'the key was encrypted in place and still reads');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAnExistingMasterKeyListsTheUnencryptedKeysByTheFingerprintsOfTheirBackups(): void
    {
        // A Standard site whose wp-config.php already holds a master key (an upgrade never confirmed, or a kept line).
        $backups = [$this->setUpStandard()];
        HashSeal::rotateKey(false, true);
        $backups[] = HashSeal::peekPendingDownload();
        define('FABRICATOR_SEAL_MASTER_KEY', str_repeat('ab', 32));

        $card = $this->securityCard();
        self::assertStringContainsString('Standard — unencrypted', $card, 'the same button');

        $upgrade = $this->ajax('fabricator_upgrade_get_master_key', ['nonce' => wp_create_nonce('fabricator_seal_setup')]);
        self::assertTrue($upgrade['success'], wp_json_encode($upgrade));
        self::assertTrue($upgrade['data']['existing']);
        self::assertArrayNotHasKey('define_line', $upgrade['data'], 'no new line to paste over the one already there');
        $listed = array_column($upgrade['data']['keys'], 'fingerprint', 'uuid');
        foreach ($backups as $backup) {
            self::assertSame($backup['fingerprint'], $listed[$backup['uuid']] ?? null, 'listed with the fingerprint its backup file carries');
        }

        // Tick only the newer key: it is encrypted, the other stays refused.
        $confirm = $this->ajax('fabricator_setup_confirm_secure', [
            'nonce' => wp_create_nonce('fabricator_seal_setup'),
            'keys'  => [$backups[1]['uuid']],
        ]);
        self::assertTrue($confirm['success'], wp_json_encode($confirm));
        self::assertStringContainsString('Keys are secured with AES-256-GCM', $this->securityCard());
        self::assertSame('', HashSeal::activeKeyProblem(), 'the ticked (active) key reads');
        $still = array_column(HashSeal::unencryptedKeys(), 'uuid');
        self::assertSame([$backups[0]['uuid']], $still, 'the unticked key is still unencrypted, so still refused');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testUnderAnotherMasterKeyRotationRefusesUntilTheAdminSaysTheOldOneIsLost(): void
    {
        // The keys were encrypted under master key B; wp-config.php now holds A. Rotating would bury the current key,
        // still bound to the active slot, where B could not read it either.
        define('FABRICATOR_SEAL_MASTER_KEY', str_repeat('ab', 32));
        $b   = str_repeat('cd', 32);
        $aad = static fn(string $slot, string $uuid): string => Reflect::call(HashSeal::class, 'keyAad', $slot, $uuid);
        update_option('fabricator_forms_seal_setup_done', true, false);
        update_option('fabricator_forms_seal_encryption', 'enabled', false);
        update_option('fabricator_forms_seal_key', wp_json_encode(['uuid' => 'u-b', 'key' => self::encryptWith($b, str_repeat('e', 64), $aad('active', 'u-b'))]), false);
        update_option('fabricator_forms_seal_master_check', self::encryptWith($b, 'fabricator-master-key-check', $aad('check', '')), false);

        self::assertStringContainsString('id="fabricator_key_master_lost"', $this->settingsPage());
        $notice = $this->sealKeyNotice();
        self::assertStringContainsString('does not open your PDF seal keys', $notice, 'the admin notice names the wrong master key');
        self::assertStringContainsString('rotating the PDF key now would leave the current key unreadable for good', $notice);
        $nonce   = wp_create_nonce('fabricator_rotate_key');
        $refused = $this->ajax('fabricator_forms_rotate_key', ['nonce' => $nonce, 'key_compromised' => '0']);
        self::assertFalse($refused['success']);
        self::assertStringContainsString('does not open your seal keys', $refused['data']['message']);
        self::assertSame('u-b', json_decode((string) get_option('fabricator_forms_seal_key'), true)['uuid'], 'nothing was changed');

        $rotated = $this->ajax('fabricator_forms_rotate_key', ['nonce' => $nonce, 'key_compromised' => '0', 'master_key_lost' => '1']);
        self::assertTrue($rotated['success'], wp_json_encode($rotated));
        self::assertSame('', HashSeal::activeKeyProblem());
        self::assertStringNotContainsString('id="fabricator_key_master_lost"', $this->settingsPage());
        self::assertSame('', $this->sealKeyNotice(), 'the notice is gone with the new key');
    }

    public function testWithoutOpensslTheSettingsPageLoadsAndEncryptedStorageSaysWhatIsMissing(): void
    {
        // A PHP built without the openssl extension, which encrypted key storage needs: calling its functions would
        // end the request in an Error that no handler catches.
        Overrides::$missingFunctions = ['openssl_encrypt', 'openssl_decrypt'];
        $needs = 'Encrypted key storage needs the PHP openssl extension';

        self::assertStringContainsString('fabricator-settings', $this->settingsPage(), 'the first-run page loads');
        $secure = $this->ajax('fabricator_setup_get_master_key', ['nonce' => wp_create_nonce('fabricator_seal_setup')]);
        self::assertFalse($secure['success']);
        self::assertStringContainsString($needs, $secure['data']['message']);

        $this->setUpStandard();
        self::assertStringContainsString('Standard — unencrypted', $this->securityCard(), 'Standard storage works without it');
        self::assertSame('', HashSeal::activeKeyProblem());
        foreach (['fabricator_upgrade_get_master_key', 'fabricator_setup_confirm_secure'] as $action) {
            $refused = $this->ajax($action, ['nonce' => wp_create_nonce('fabricator_seal_setup')]);
            self::assertFalse($refused['success'], $action);
            self::assertStringContainsString($needs, $refused['data']['message'], $action);
        }
        self::assertNotSame('enabled', get_option('fabricator_forms_seal_encryption'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAnEncryptedSiteMovedToAPhpWithoutOpensslStillLoadsItsSettingsAndSaysTheKeyCannotBeRead(): void
    {
        $master = str_repeat('ab', 32);
        define('FABRICATOR_SEAL_MASTER_KEY', $master);
        $aad = static fn(string $slot, string $uuid): string => Reflect::call(HashSeal::class, 'keyAad', $slot, $uuid);
        update_option('fabricator_forms_seal_setup_done', true, false);
        update_option('fabricator_forms_seal_encryption', 'enabled', false);
        update_option('fabricator_forms_seal_key', wp_json_encode(['uuid' => 'u-a', 'key' => self::encryptWith($master, str_repeat('e', 64), $aad('active', 'u-a'))]), false);
        update_option('fabricator_forms_seal_master_check', self::encryptWith($master, 'fabricator-master-key-check', $aad('check', '')), false);
        self::assertSame('', HashSeal::activeKeyProblem(), 'with openssl, the key reads');

        Overrides::$missingFunctions = ['openssl_encrypt', 'openssl_decrypt'];
        self::assertStringContainsString('fabricator-settings-card--security', $this->settingsPage(), 'the page loads');
        self::assertSame('undecryptable', HashSeal::activeKeyProblem());
        self::assertStringContainsString('The PDF seal key cannot be decrypted', $this->sealKeyNotice());
    }

    /**
     * The "enc::" format HashSeal writes, under a master key of the test's choosing.
     */
    private static function encryptWith(string $hex_master, string $plain, string $aad): string
    {
        $iv  = random_bytes(12);
        $tag = '';
        $ct  = openssl_encrypt($plain, 'aes-256-gcm', (string) hex2bin($hex_master), OPENSSL_RAW_DATA, $iv, $tag, $aad);
        return 'enc::' . base64_encode($iv . $tag . $ct);
    }

    /**
     * Completes setup in Standard mode and returns the key's backup (uuid, key, fingerprint).
     *
     * @return array{uuid: string, key: string, fingerprint: string}
     */
    private function setUpStandard(): array
    {
        $setup = $this->ajax('fabricator_setup_keep_default', ['nonce' => wp_create_nonce('fabricator_seal_setup')]);
        self::assertTrue($setup['success'], wp_json_encode($setup));
        return $setup['data'];
    }

    /**
     * The rendered settings page.
     */
    private function settingsPage(): string
    {
        ob_start();
        FormSettings::renderSettingsPage();
        return (string) ob_get_clean();
    }

    /**
     * The admin notice every screen shows while the seal key is unusable, or ''.
     */
    private function sealKeyNotice(): string
    {
        ob_start();
        \FabricatorForms\Plugin::maybeWarnSealKeyUnusable();
        return (string) ob_get_clean();
    }

    /**
     * The Security card of the rendered settings page.
     */
    private function securityCard(): string
    {
        $page = $this->settingsPage();
        $at   = strpos($page, 'fabricator-settings-card--security');
        self::assertNotFalse($at, 'the page has a Security card');
        return substr($page, $at, (int) strpos($page, 'id="fabricator-rotate-key-trigger"', $at) - $at);
    }
}
