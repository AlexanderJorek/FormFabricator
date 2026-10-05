<?php

namespace FabricatorForms\Tests\Integration\Admin;

use FabricatorForms\Admin\FormSettings;
use FabricatorForms\PDF\HashSeal;
use FabricatorForms\Tests\Integration\AjaxTestCase;

/**
 * A key backup file carries its key's fingerprint (HashSeal::keyFingerprint()), the one the master-key upgrade dialog
 * shows. Importing it again checks the two still belong together; a file without one, still imports.
 */
final class KeyImportTest extends AjaxTestCase
{
    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- overrides WP_UnitTestCase's snake_case fixture method.
    public function set_up(): void
    {
        parent::set_up();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        // The plugin loads the admin classes only when is_admin(), which the test bootstrap is not; hook them as
        // admin-ajax.php would.
        FormSettings::init();
    }

    public function testAKeyFileWhoseFingerprintNoLongerMatchesItsKeyIsRefused(): void
    {
        $key  = str_repeat('ab', 32);
        $file = ['plugin' => 'FormFabricator PDF Seal Key', 'uuid' => wp_generate_uuid4(), 'key' => $key, 'created_at' => '2026-01-02 03:04:05 UTC'];

        $changed = $this->import($file + ['fingerprint' => HashSeal::keyFingerprint(str_repeat('cd', 32))]);
        self::assertFalse($changed['success']);
        self::assertSame('The fingerprint in this key file does not match its key. The file is damaged or was changed.', $changed['data']['message']);

        self::assertTrue($this->import($file + ['fingerprint' => strtoupper(HashSeal::keyFingerprint($key))])['success'], 'case and spacing do not matter');
        self::assertTrue($this->import(['uuid' => wp_generate_uuid4()] + $file)['success'], 'a file from before fingerprints');
    }

    /**
     * Posts a key file to the import action as the settings page does.
     *
     * @param array<string, string> $file
     * @return array{success: bool, data: mixed}
     */
    private function import(array $file): array
    {
        return $this->ajax('fabricator_add_legacy_key', ['key_json' => wp_json_encode($file), 'nonce' => wp_create_nonce('fabricator_add_legacy_key')]);
    }
}
