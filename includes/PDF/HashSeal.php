<?php

/**
 * Creates and verifies cryptographic hash seals on generated PDFs.
 *
 * PHP Version 8.1
 *
 * @category  FormFabricator
 * @package   FormFabricator
 * @author    Alexander Jorek
 * @copyright 2026 Alexander Jorek
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 * @version   1.0.6
 * @link      https://github.com/AlexanderJorek/FormFabricator
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 3
 * of the License, or (at your option) any later version.
 */

namespace FabricatorForms\PDF;

defined('ABSPATH') || exit;

/**
 * Manages PDF seal key generation, encryption, HMAC signing, and verification.
 */
class HashSeal
{
    // Seal key length in bytes (hex-encoded to 64 chars for storage — see the format
    // addLegacyKey()'s importer validates). Keys are random; nothing is derived from a password.
    private const KDF_LEN    = 32;
    private const ENC_PREFIX = 'enc::';

    /* ------------------------------------------------------------------ */
    /* UUID                                                                 */
    /* ------------------------------------------------------------------ */

    /**
     * Generates a random UUID v4.
     *
     * @return string UUID v4 string.
     */
    private static function generateUuid(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /* ------------------------------------------------------------------ */
    /* Encryption layer                                                     */
    /* ------------------------------------------------------------------ */

    /**
     * True when the admin has opted in and FABRICATOR_SEAL_MASTER_KEY is defined.
     *
     * @return bool True when encryption is active and the master key constant is set.
     */
    public static function isEncryptionEnabled(): bool
    {
        return get_option('fabricator_forms_seal_encryption') === 'enabled'
            && defined('FABRICATOR_SEAL_MASTER_KEY')
            && (string) FABRICATOR_SEAL_MASTER_KEY !== '';
    }

    /**
     * Returns the binary master key from the FABRICATOR_SEAL_MASTER_KEY constant.
     *
     * @return string Binary master key.
     */
    private static function masterKey(): string
    {
        if (!defined('FABRICATOR_SEAL_MASTER_KEY') || (string) FABRICATOR_SEAL_MASTER_KEY === '') {
            throw new \RuntimeException(
                'FabricatorForms: FABRICATOR_SEAL_MASTER_KEY is not defined. '
                . 'Add it to wp-config.php or disable encryption in plugin settings.'
            );
        }
        $bin = hex2bin((string) FABRICATOR_SEAL_MASTER_KEY);
        if ($bin === false || strlen($bin) !== 32) {
            throw new \RuntimeException('FabricatorForms: FABRICATOR_SEAL_MASTER_KEY must be a 64-char hex string.');
        }
        return $bin;
    }

    /**
     * Encrypts a key value using AES-256-GCM.
     *
     * @param string $plaintext Plaintext key value.
     * @return string Encrypted value prefixed with nonce and tag.
     */
    private static function encryptKey(string $plaintext): string
    {
        $iv  = random_bytes(12);
        $tag = '';
        $ct  = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            self::masterKey(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
        if ($ct === false) {
            throw new \RuntimeException('FabricatorForms: key encryption failed.');
        }
        return self::ENC_PREFIX . base64_encode($iv . $tag . $ct);
    }

    /**
     * Decrypts an encrypted key value; returns plaintext if not encrypted.
     *
     * @param string $value Encrypted or plaintext key value.
     * @return string Decrypted plaintext key.
     */
    private static function decryptKey(string $value): string
    {
        if (strncmp($value, self::ENC_PREFIX, strlen(self::ENC_PREFIX)) !== 0) {
            return $value; // unencrypted — plain hex
        }
        $data = base64_decode(substr($value, strlen(self::ENC_PREFIX)));
        if ($data === false || strlen($data) < 29) {
            throw new \RuntimeException('FabricatorForms: encrypted key data is malformed.');
        }
        $iv  = substr($data, 0, 12);
        $tag = substr($data, 12, 16);
        $ct  = substr($data, 28);
        $pt  = openssl_decrypt($ct, 'aes-256-gcm', self::masterKey(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($pt === false) {
            throw new \RuntimeException(
                'FabricatorForms: key decryption failed — master key may be incorrect or missing.'
            );
        }
        return $pt;
    }

    /**
     * Encrypts a value only when encryption is enabled; otherwise returns it as-is.
     *
     * @param string $plaintext Plaintext value to conditionally encrypt.
     * @return string Encrypted value or original plaintext.
     */
    private static function maybeEncrypt(string $plaintext): string
    {
        return self::isEncryptionEnabled() ? self::encryptKey($plaintext) : $plaintext;
    }

    // After the admin enables encryption, re-encrypt all existing plaintext keys in-place. Safe to call
    // multiple times — already-encrypted values are left untouched.
    public static function encryptExistingKeys(): void
    {
        // Active key
        $raw = get_option('fabricator_forms_seal_key');
        if ($raw) {
            $rec = json_decode((string) $raw, true);
            $not_yet_encrypted = strncmp((string)($rec['key'] ?? ''), self::ENC_PREFIX, strlen(self::ENC_PREFIX)) !== 0;
            if (is_array($rec) && isset($rec['uuid'], $rec['key']) && $not_yet_encrypted) {
                $rec['key'] = self::encryptKey($rec['key']);
                update_option('fabricator_forms_seal_key', wp_json_encode($rec), false);
            }
        }

        // History
        $history = get_option('fabricator_forms_seal_key_history', []);
        if (!is_array($history)) {
            return;
        }
        $changed = false;
        foreach ($history as &$entry) {
            $prefix              = self::ENC_PREFIX;
            $entry_not_encrypted = strncmp((string)($entry['key'] ?? ''), $prefix, strlen($prefix)) !== 0;
            if (isset($entry['key']) && $entry_not_encrypted) {
                $entry['key'] = self::encryptKey($entry['key']);
                $changed      = true;
            }
        }
        unset($entry);
        if ($changed) {
            update_option('fabricator_forms_seal_key_history', $history, false);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Key management                                                       */
    /* ------------------------------------------------------------------ */

    /**
     * Return the active key record as ['uuid' => string, 'key' => plaintext string]. Auto-generates and flags
     * pending download when no valid key exists.
     *
     * @return array|null Active key record, or null when none can be resolved.
     */
    private static function getActiveKeyRecord(): array
    {
        $raw = get_option('fabricator_forms_seal_key');
        if ($raw) {
            $decoded = json_decode((string) $raw, true);
            if (is_array($decoded) && isset($decoded['uuid'], $decoded['key'])) {
                return [
                    'uuid' => $decoded['uuid'],
                    'key'  => self::decryptKey($decoded['key']),
                ];
            }
        }

        // No valid key — generate the initial one.
        $uuid    = self::generateUuid();
        $raw_key = bin2hex(random_bytes(self::KDF_LEN));
        update_option(
            'fabricator_forms_seal_key',
            wp_json_encode(['uuid' => $uuid, 'key' => self::maybeEncrypt($raw_key)]),
            false
        );
        self::setPendingDownload($uuid, $raw_key);
        return ['uuid' => $uuid, 'key' => $raw_key];
    }

    /**
     * Stores a pending key download in the WordPress options table.
     *
     * @param string $uuid          UUID of the key.
     * @param string $plaintext_key Plaintext key value.
     * @return void
     */
    // Stored as a transient (not a plain option) so the plaintext key self-expires even if never downloaded.
    private const PENDING_DOWNLOAD_TTL = 10 * MINUTE_IN_SECONDS;

    private static function setPendingDownload(string $uuid, string $plaintext_key): void
    {
        set_transient(
            'fabricator_forms_seal_key_pending_download',
            wp_json_encode(
                [
                'uuid'       => $uuid,
                'key'        => $plaintext_key,
                'created_at' => gmdate('Y-m-d H:i:s') . ' UTC',
                ]
            ),
            self::PENDING_DOWNLOAD_TTL
        );
    }

    /**
     * Returns the active plaintext seal key.
     *
     * @return string Active seal key.
     */
    private static function getKey(): string
    {
        return self::getActiveKeyRecord()['key'];
    }

    /**
     * Returns the UUID of the currently active seal key.
     *
     * @return string UUID of the active key.
     */
    public static function getCurrentKeyId(): string
    {
        return self::getActiveKeyRecord()['uuid'];
    }

    /* deriveKey() (PBKDF2-SHA256 over PEPPER|uuid) was removed along with PEPPER/KDF_ROUNDS:
       seal keys are random_bytes() now, not password-derived. See rotateKey() for why. */

    /**
     * Rotates the active seal key. The new key is random.
     *
     * @param bool $compromised    True to flag the retiring key as compromised.
     * @param bool $nonce_verified True when the caller has already verified a CSRF nonce for
     *                              this request (e.g. via check_ajax_referer() in an AJAX
     *                              handler). When false, this method performs its own
     *                              fallback nonce check.
     * @return array{uuid: string, key: string, created_at: string}
     */
    public static function rotateKey(bool $compromised, bool $nonce_verified = false): array
    {
        // Defense-in-depth: don't rely solely on the caller to gate access to seal-key rotation.
        if (!current_user_can('manage_options')) {
            throw new \RuntimeException('Insufficient permissions to rotate the seal key.');
        }

        // Two separate fail-early statements (not one compound condition) so this check can't be bypassed.
        if (!$nonce_verified) {
            $nonce_verified = check_ajax_referer('fabricator_rotate_key', 'nonce', false) !== false;
        }
        if (!$nonce_verified) {
            throw new \RuntimeException('Invalid or missing security token.');
        }

        $user       = wp_get_current_user();
        $user_id    = (int) $user->ID;
        $user_login = (string) $user->user_login;
        $retired_at = gmdate('Y-m-d H:i:s') . ' UTC';

        $current = self::getActiveKeyRecord();
        $history = get_option('fabricator_forms_seal_key_history', []);
        if (!is_array($history)) {
            $history = [];
        }

        $history[] = [
            'uuid'             => $current['uuid'],
            'key'              => self::maybeEncrypt($current['key']),
            'status'           => empty($history) ? 'initial' : 'rotated',
            'compromised'      => $compromised,
            'retired_at'       => $retired_at,
            'retired_by_id'    => $user_id,
            'retired_by_login' => $user_login,
        ];

        $new_uuid = self::generateUuid();
        // Random, NOT derived from $password: deriving from a UUID+public-pepper salt let anyone with one sealed PDF brute-force the password offline.
        $new_raw_key = bin2hex(random_bytes(self::KDF_LEN));

        update_option(
            'fabricator_forms_seal_key',
            wp_json_encode(['uuid' => $new_uuid, 'key' => self::maybeEncrypt($new_raw_key)]),
            false
        );
        update_option('fabricator_forms_seal_key_history', $history, false);
        self::setPendingDownload($new_uuid, $new_raw_key);

        return ['uuid' => $new_uuid, 'key' => $new_raw_key, 'created_at' => $retired_at];
    }

    /**
     * Reads the pending key download transient without consuming it. Used at Settings page
     * render time — peekPendingDownload() (not claimPendingDownload()) is deliberate here:
     * rendering the page (or an admin reloading/navigating away without confirming the
     * download) must not burn the one-shot backup opportunity. Only confirmDownload(), fired
     * once the admin has actually saved the key file, deletes the transient.
     *
     * @return array{uuid: string, key: string, created_at: string}|null
     */
    public static function peekPendingDownload(): ?array
    {
        // Defense-in-depth: don't rely solely on the caller to gate access to the pending plaintext key download.
        if (!current_user_can('manage_options')) {
            throw new \RuntimeException('Insufficient permissions to read the pending seal key download.');
        }
        return self::decodePendingDownload(get_transient('fabricator_forms_seal_key_pending_download'));
    }

    /**
     * Deletes the pending key download transient, once the admin has confirmed (via the
     * download-modal's "I've saved it" button) that they've actually saved the plaintext key
     * elsewhere. Returns the record that was deleted, or null if none was pending.
     *
     * @return array{uuid: string, key: string, created_at: string}|null
     */
    public static function confirmDownload(): ?array
    {
        // Defense-in-depth: don't rely solely on the caller to gate access to the pending plaintext key download.
        if (!current_user_can('manage_options')) {
            throw new \RuntimeException('Insufficient permissions to confirm the pending seal key download.');
        }

        $record = self::decodePendingDownload(get_transient('fabricator_forms_seal_key_pending_download'));
        delete_transient('fabricator_forms_seal_key_pending_download');
        return $record;
    }

    /**
     * Decodes a pending-download transient's raw JSON value.
     *
     * @param mixed $raw Raw transient value (string JSON, or false when absent).
     * @return array{uuid: string, key: string, created_at: string}|null
     */
    private static function decodePendingDownload(mixed $raw): ?array
    {
        if (!$raw) {
            return null;
        }
        $record = json_decode((string) $raw, true);
        if (is_array($record) && isset($record['uuid'], $record['key'])) {
            return $record;
        }
        return null;
    }

    /**
     * Verifies an HMAC seal against the given data payload.
     *
     * @param array  $data The payload that was originally sealed.
     * @param string $hmac The HMAC seal to verify.
     * @return array{valid: bool, key_status: string|null, compromised: bool}
     */
    public static function verify(array $data, string $hmac): array
    {
        $json = wp_json_encode($data);
        if ($json === false) {
            return ['valid' => false, 'key_status' => null, 'compromised' => false];
        }

        $key_id = isset($data['key_id']) && is_string($data['key_id']) ? $data['key_id'] : null;
        if ($key_id === null) {
            return ['valid' => false, 'key_status' => null, 'compromised' => false];
        }

        $active = self::getActiveKeyRecord();
        if ($active['uuid'] === $key_id) {
            if (hash_equals(hash_hmac('sha256', $json, $active['key']), $hmac)) {
                return ['valid' => true, 'key_status' => 'active', 'compromised' => false];
            }
            return ['valid' => false, 'key_status' => null, 'compromised' => false];
        }

        $history = get_option('fabricator_forms_seal_key_history', []);
        if (!is_array($history)) {
            return ['valid' => false, 'key_status' => null, 'compromised' => false];
        }

        foreach ($history as $entry) {
            if (($entry['uuid'] ?? null) !== $key_id) {
                continue;
            }
            try {
                $entry_key = self::decryptKey((string)($entry['key'] ?? ''));
            } catch (\Exception $e) {
                return ['valid' => false, 'key_status' => null, 'compromised' => false];
            }
            if ($entry_key !== '' && hash_equals(hash_hmac('sha256', $json, $entry_key), $hmac)) {
                return [
                    'valid'       => true,
                    'key_status'  => (string)($entry['status'] ?? 'rotated'),
                    'compromised' => !empty($entry['compromised']),
                ];
            }
            return ['valid' => false, 'key_status' => null, 'compromised' => false];
        }

        return ['valid' => false, 'key_status' => null, 'compromised' => false];
    }

    /**
     * Returns history entries with keys decrypted (plaintext) for display and verification.
     *
     * @return array[]
     */
    public static function getHistory(): array
    {
        // Defense-in-depth: don't rely solely on the caller to gate access to decrypted plaintext key history.
        if (!current_user_can('manage_options')) {
            throw new \RuntimeException('Insufficient permissions to view the seal key history.');
        }

        $history = get_option('fabricator_forms_seal_key_history', []);
        if (!is_array($history)) {
            return [];
        }
        return array_map(
            function (array $entry): array {
                if (isset($entry['key'])) {
                    try {
                        $entry['key'] = self::decryptKey($entry['key']);
                    } catch (\Exception $e) {
                        $entry['key'] = '';
                    }
                }
                return $entry;
            },
            $history
        );
    }

    /**
     * Like getHistory(), but for display purposes that only ever need a short fingerprint, never
     * the plaintext key itself — each entry's 'key' is replaced with 'fingerprint' (the same
     * 6-char sha256 prefix callers were computing from the full decrypted key anyway).
     *
     * Deliberately does NOT go through getHistory(): that would materialize every retired key in
     * plaintext in one array, all at once, purely to throw away everything but six hex characters.
     * Here each key is decrypted, hashed, and discarded one at a time, so at most one plaintext
     * key exists in memory at any moment (NIST SSDF PW.9 — minimize secret exposure).
     *
     * @return array
     */
    public static function getHistoryFingerprints(): array
    {
        // Defense-in-depth: same gate as getHistory(), since this still decrypts key material.
        if (!current_user_can('manage_options')) {
            throw new \RuntimeException('Insufficient permissions to view the seal key history.');
        }

        $history = get_option('fabricator_forms_seal_key_history', []);
        if (!is_array($history)) {
            return [];
        }

        $out = [];
        foreach ($history as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $fingerprint = '';
            if (isset($entry['key'])) {
                try {
                    $plaintext   = self::decryptKey($entry['key']);
                    $fingerprint = $plaintext !== '' ? substr(hash('sha256', $plaintext), 0, 6) : '';
                } catch (\Exception $e) {
                    $fingerprint = '';
                }
                unset($plaintext); // discard before moving to the next entry
            }
            unset($entry['key']);
            $entry['fingerprint'] = $fingerprint;
            $out[] = $entry;
        }
        return $out;
    }

    /**
     * Manually imports a key into history as a legacy entry. Used when recovering keys after a server loss.
     *
     * @param string $uuid           UUID of the key to import.
     * @param string $key_value      Raw key value (hex string).
     * @param string $created_at     ISO 8601 creation timestamp, or empty for now.
     * @param string $status         One of 'rotated-legacy' or 'compromised-legacy'.
     * @param bool   $nonce_verified True when the caller has already verified a CSRF nonce for
     *                                this request (e.g. via check_ajax_referer() in an AJAX
     *                                handler). When false, this method performs its own
     *                                fallback nonce check.
     * @return void
     */
    public static function addLegacyKey(
        string $uuid,
        string $key_value,
        string $created_at = '',
        string $status = 'rotated-legacy',
        bool $nonce_verified = false
    ): void {
        // Defense-in-depth: mirror rotateKey()'s own guard rather than relying solely on the caller.
        if (!current_user_can('manage_options')) {
            throw new \RuntimeException('Insufficient permissions to import a legacy seal key.');
        }

        // Two separate fail-early statements (not one compound condition) so this check can't be bypassed.
        if (!$nonce_verified) {
            $nonce_verified = check_ajax_referer('fabricator_add_legacy_key', 'nonce', false) !== false;
        }
        if (!$nonce_verified) {
            throw new \RuntimeException('Invalid or missing security token.');
        }

        $allowed_statuses = ['rotated-legacy', 'compromised-legacy'];
        $safe_status      = in_array($status, $allowed_statuses, true) ? $status : 'rotated-legacy';
        $compromised      = $safe_status === 'compromised-legacy';

        $user    = wp_get_current_user();
        $history = get_option('fabricator_forms_seal_key_history', []);
        if (!is_array($history)) {
            $history = [];
        }
        $history[] = [
            'uuid'             => $uuid,
            'key'              => self::maybeEncrypt($key_value),
            'status'           => $safe_status,
            'compromised'      => $compromised,
            'retired_at'       => $created_at ?: gmdate('Y-m-d H:i:s') . ' UTC',
            'retired_by_id'    => (int) $user->ID,
            'retired_by_login' => (string) $user->user_login,
        ];
        update_option('fabricator_forms_seal_key_history', $history, false);
    }

    /* ------------------------------------------------------------------ */
    /* Seal generation (used by Generator.php)                             */
    /* ------------------------------------------------------------------ */

    /**
     * Generates an HMAC-SHA256 seal; deterministic per key, so identical payloads are linkable — not for public disclosure.
     *
     * @param array $data Payload to seal.
     * @return string Hex-encoded HMAC seal.
     */
    public static function generate(array $data): string
    {
        $json = wp_json_encode($data);
        if ($json === false) {
            throw new \RuntimeException('FabricatorForms HashSeal: failed to JSON-encode payload for HMAC');
        }
        return hash_hmac('sha256', $json, self::getKey());
    }
}
