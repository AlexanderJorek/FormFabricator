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
 * @version   1.0.7
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
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- stores AES-256-GCM ciphertext (iv|tag|ct) as text in wp_options, which cannot hold raw binary safely. Not obfuscation.
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
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- reads back the AES-256-GCM ciphertext stored by encryptKey(). Not obfuscation.
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
    //
    // Under rotateKey()'s lock, failing closed as it does: this rewrites the active key and the whole history, so a
    // rotation landing in between lost either the new key or the key it had just retired, and neither comes back.
    public static function encryptExistingKeys(): void
    {
        \FabricatorForms\Utils\OptionMutex::run(
            'fabricator_forms_seal_key_history',
            static function (): void {
                self::encryptExistingKeysLocked();
            },
            3000,
            true
        );
    }

    /**
     * encryptExistingKeys()'s work, run inside the seal-key lock.
     *
     * @return void
     */
    private static function encryptExistingKeysLocked(): void
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

        // History. A history that isn't a list has nothing to encrypt, but the stores below still do: returning here
        // left their plaintext keys as they were.
        $history = get_option('fabricator_forms_seal_key_history', []);
        if (is_array($history)) {
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

        self::encryptSetAsideKeys();
        self::encryptPendingDownload();
    }

    /**
     * Encrypts key material inside set-aside damaged records, which this pass used to walk past.
     *
     * A record set aside before encryption was switched on holds its key as plaintext, and nothing encrypted it
     * afterwards, so the one store meant to preserve an unreadable key was the one left readable.
     *
     * @return void
     */
    private static function encryptSetAsideKeys(): void
    {
        $damaged = get_option('fabricator_forms_seal_key_damaged', []);
        if (!is_array($damaged) || $damaged === []) {
            return;
        }
        $changed = false;
        foreach ($damaged as &$record) {
            $decoded = is_array($record) ? json_decode((string) ($record['value'] ?? ''), true) : null;
            // Only a record whose shape is still readable can be re-encrypted key-first; a truly unparseable one is
            // left exactly as found, since nothing here can tell key material from the damage around it.
            if (!is_array($decoded) || !isset($decoded['key']) || self::isEncryptedValue((string) $decoded['key'])) {
                continue;
            }
            $decoded['key']   = self::encryptKey((string) $decoded['key']);
            $record['value']  = (string) wp_json_encode($decoded);
            $changed          = true;
        }
        unset($record);
        if ($changed) {
            update_option('fabricator_forms_seal_key_damaged', $damaged, false);
        }
    }

    /**
     * Encrypts a pending one-shot download written before encryption was switched on.
     *
     * setPendingDownload() encrypts as it writes, but a transient already waiting for its admin keeps the plaintext
     * key until it expires.
     *
     * @return void
     */
    private static function encryptPendingDownload(): void
    {
        $raw = get_transient('fabricator_forms_seal_key_pending_download');
        if (!$raw) {
            return;
        }
        $record = json_decode((string) $raw, true);
        if (!is_array($record) || !isset($record['uuid'], $record['key']) || self::isEncryptedValue((string) $record['key'])) {
            return;
        }
        $record['key'] = self::encryptKey((string) $record['key']);
        set_transient('fabricator_forms_seal_key_pending_download', wp_json_encode($record), self::PENDING_DOWNLOAD_TTL);
    }

    /* ------------------------------------------------------------------ */
    /* Key management                                                       */
    /* ------------------------------------------------------------------ */

    /**
     * Returns the stored active key record, with the key as stored (possibly encrypted).
     *
     * @return array{uuid: string, key: string}|null Null when the record is absent or can't be read.
     */
    private static function storedActiveRecord(): ?array
    {
        $raw = get_option('fabricator_forms_seal_key');
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)
            || !is_string($decoded['uuid'] ?? null) || $decoded['uuid'] === ''
            || !is_string($decoded['key'] ?? null) || $decoded['key'] === ''
        ) {
            return null;
        }
        return ['uuid' => $decoded['uuid'], 'key' => $decoded['key']];
    }

    /**
     * Returns the active key record as ['uuid' => string, 'key' => plaintext string].
     *
     * Never creates a key; only createInitialKey() (setup) and rotateKey() do. Creating one here let two concurrent
     * requests each store their own (every PDF sealed with the losing key unverifiable for good) and replaced a record
     * that merely failed to decode. Throwing fails the submission closed: Generator returns false, the visitor gets a
     * retry error, and Plugin::maybeWarnSealKeyUnusable() tells the admin to rotate the key.
     *
     * @return array{uuid: string, key: string}
     * @throws \RuntimeException When the record is absent, unreadable or can't be decrypted.
     */
    private static function getActiveKeyRecord(): array
    {
        $stored = self::storedActiveRecord();
        if ($stored === null) {
            throw new \RuntimeException('FabricatorForms HashSeal: the seal key is missing or unreadable. Rotate it under Settings.');
        }
        return ['uuid' => $stored['uuid'], 'key' => self::decryptKey($stored['key'])];
    }

    /**
     * Creates the first seal key during setup and flags it for download.
     *
     * Atomic: INSERT IGNORE against wp_options' unique option_name, so an existing record (healthy, damaged, or just
     * written by a concurrent request) is never replaced. add_option() can't do this, as it upserts.
     *
     * @return bool True when this call created the key.
     */
    public static function createInitialKey(): bool
    {
        if (!current_user_can('manage_options')) {
            throw new \RuntimeException('Insufficient permissions to create the seal key.');
        }
        self::assertMasterKeyIfEncrypted();

        $uuid    = self::generateUuid();
        $raw_key = bin2hex(random_bytes(self::KDF_LEN));
        $record  = wp_json_encode(['uuid' => $uuid, 'key' => self::maybeEncrypt($raw_key)]);
        if ($record === false) {
            throw new \RuntimeException('FabricatorForms HashSeal: failed to encode the new seal key record.');
        }

        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic create-if-absent needs a direct query; forgetCachedOption() clears the option caches right after.
        $wpdb->query(
            $wpdb->prepare(
                "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
                'fabricator_forms_seal_key',
                $record
            )
        );
        $created = (int) $wpdb->rows_affected === 1;
        self::forgetCachedOption('fabricator_forms_seal_key');

        if ($created) {
            self::setPendingDownload($uuid, $raw_key);
        }
        return $created;
    }

    /**
     * Read-only health check for the admin notice. Never creates a key.
     *
     * @return string '' when sealing can run; otherwise 'missing', 'damaged' or 'undecryptable'.
     */
    public static function activeKeyProblem(): string
    {
        $raw = get_option('fabricator_forms_seal_key');
        if ($raw === false || $raw === '') {
            return 'missing';
        }
        $stored = self::storedActiveRecord();
        if ($stored === null) {
            return 'damaged';
        }
        try {
            $usable = self::decryptKey($stored['key']) !== '';
        } catch (\Exception $e) {
            return 'undecryptable';
        }
        return $usable ? '' : 'damaged';
    }

    /**
     * Clears WordPress's caches for an option written by a direct query, including the "notoptions" miss cache that
     * would otherwise keep reporting it absent (for the rest of the request, or longer under a persistent object cache).
     *
     * @param string $name Option name.
     * @return void
     */
    private static function forgetCachedOption(string $name): void
    {
        wp_cache_delete($name, 'options');
        $notoptions = wp_cache_get('notoptions', 'options');
        if (is_array($notoptions) && isset($notoptions[$name])) {
            unset($notoptions[$name]);
            wp_cache_set('notoptions', $notoptions, 'options');
        }
    }

    /**
     * Throws when encryption was chosen but FABRICATOR_SEAL_MASTER_KEY is absent or malformed. isEncryptionEnabled()
     * is false then, so maybeEncrypt() would quietly store a new key in plaintext.
     *
     * @return void
     */
    private static function assertMasterKeyIfEncrypted(): void
    {
        if (get_option('fabricator_forms_seal_encryption') === 'enabled') {
            self::masterKey();
        }
    }

    /**
     * True when a stored key value carries the encryptKey() prefix.
     *
     * @param string $value Stored key value.
     * @return bool
     */
    private static function isEncryptedValue(string $value): bool
    {
        return strncmp($value, self::ENC_PREFIX, strlen(self::ENC_PREFIX)) === 0;
    }

    /**
     * Copies an unreadable active key record to fabricator_forms_seal_key_damaged before rotation replaces it, so the
     * key inside can still be recovered by hand. Does nothing when there is no record at all.
     *
     * @return void
     */
    private static function setAsideDamagedRecord(): void
    {
        $raw = get_option('fabricator_forms_seal_key');
        if ($raw === false || $raw === '') {
            return;
        }
        $damaged = get_option('fabricator_forms_seal_key_damaged', []);
        if (!is_array($damaged)) {
            $damaged = [];
        }
        $damaged[] = [
            'value'        => is_string($raw) ? $raw : (string) wp_json_encode($raw),
            'set_aside_at' => gmdate('Y-m-d H:i:s') . ' UTC',
        ];
        update_option('fabricator_forms_seal_key_damaged', $damaged, false);
        \FabricatorForms\fabricator_log(
            'FabricatorForms HashSeal: an unreadable seal key record was kept in fabricator_forms_seal_key_damaged before rotation.'
        );
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
                // Same at-rest protection as the stored key: with encryption on, the transient never holds plaintext.
                'key'        => self::maybeEncrypt($plaintext_key),
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

        self::assertMasterKeyIfEncrypted();

        // Read and write under one lock: two rotations at the same moment each appended to the history they had read,
        // and the second write dropped the first one's retired key, leaving its PDFs unverifiable for good. Fail closed:
        // unlike an edited setting, a key lost that way can't be redone, so a busy lock refuses the rotation instead.
        return \FabricatorForms\Utils\OptionMutex::run(
            'fabricator_forms_seal_key_history',
            static fn(): array => self::rotateKeyLocked($compromised, $user_id, $user_login, $retired_at),
            3000,
            true
        );
    }

    /**
     * The rotation itself, run inside the seal-key lock. See rotateKey(), which checks permissions first.
     *
     * @param bool   $compromised True to flag the retiring key as compromised.
     * @param int    $user_id     Who rotated.
     * @param string $user_login  Their login name.
     * @param string $retired_at  Timestamp recorded on the retired key and the new one.
     * @return array{uuid: string, key: string, created_at: string}
     */
    private static function rotateKeyLocked(bool $compromised, int $user_id, string $user_login, string $retired_at): array
    {
        $history = get_option('fabricator_forms_seal_key_history', []);
        if (!is_array($history)) {
            $history = [];
        }

        $current = self::storedActiveRecord();
        if ($current !== null) {
            // An encrypted key moves as stored, without decrypting: rotating is how an admin recovers from a key the
            // master key can't open, and that key becomes verifiable again once the right master key is back.
            $retired_key = self::isEncryptedValue($current['key'])
                ? $current['key']
                : self::maybeEncrypt($current['key']);
            $history[]   = [
                'uuid'             => $current['uuid'],
                'key'              => $retired_key,
                'status'           => empty($history) ? 'initial' : 'rotated',
                'compromised'      => $compromised,
                'retired_at'       => $retired_at,
                'retired_by_id'    => $user_id,
                'retired_by_login' => $user_login,
            ];
        } else {
            // Nothing to retire when the record is gone; one that exists but can't be read is set aside, never overwritten.
            self::setAsideDamagedRecord();
        }

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
     * Reads the pending key download without consuming it — rendering/reloading must not burn the one-shot backup opportunity; only confirmDownload() deletes it.
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
     * Deletes the pending key download transient once the admin has confirmed they saved the plaintext key elsewhere.
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
            try {
                $record['key'] = self::decryptKey((string) $record['key']);
            } catch (\Exception $e) {
                \FabricatorForms\fabricator_log('FabricatorForms HashSeal: pending key download could not be decrypted — ' . $e->getMessage());
                return null;
            }
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

        // key_status distinguishes an unfamiliar-key state (not tampering) from a real HMAC mismatch, which previously looked identical.
        $key_id = isset($data['key_id']) && is_string($data['key_id']) ? $data['key_id'] : null;
        if ($key_id === null) {
            return ['valid' => false, 'key_status' => 'unknown-key', 'compromised' => false];
        }

        // Every key filed under this uuid is tried, not just the first: a mistyped legacy import under an existing
        // uuid would otherwise shadow the real key and permanently fail every PDF that key signed.
        $uuid_found    = false;
        $undecryptable = false;

        // Not getActiveKeyRecord(), which throws when the key is unusable: this read-only check must neither create a key
        // nor abort before the retired keys below are tried. Decrypted like those, so a missing or wrong master key
        // reaches the "Key Unreadable" verdict instead of a generic error.
        $active = self::storedActiveRecord();
        if ($active !== null && $active['uuid'] === $key_id) {
            $uuid_found = true;
            try {
                $active_key = self::decryptKey($active['key']);
                if (hash_equals(hash_hmac('sha256', $json, $active_key), $hmac)) {
                    return ['valid' => true, 'key_status' => 'active', 'compromised' => false];
                }
            } catch (\Exception $e) {
                $undecryptable = true;
            }
        }

        $history = get_option('fabricator_forms_seal_key_history', []);
        if (!is_array($history)) {
            $history = [];
        }

        foreach ($history as $entry) {
            if (($entry['uuid'] ?? null) !== $key_id) {
                continue;
            }
            $uuid_found = true;
            try {
                $entry_key = self::decryptKey((string)($entry['key'] ?? ''));
            } catch (\Exception $e) {
                // The key exists but cannot be read (wrong/absent FABRICATOR_SEAL_MASTER_KEY).
                $undecryptable = true;
                continue;
            }
            if ($entry_key !== '' && hash_equals(hash_hmac('sha256', $json, $entry_key), $hmac)) {
                return [
                    'valid'       => true,
                    'key_status'  => (string)($entry['status'] ?? 'rotated'),
                    'compromised' => !empty($entry['compromised']),
                ];
            }
        }

        if ($uuid_found) {
            return $undecryptable
                ? ['valid' => false, 'key_status' => 'undecryptable-key', 'compromised' => false]
                : ['valid' => false, 'key_status' => null, 'compromised' => false];
        }

        // Walked active + entire history without finding this uuid: the signing key is not here.
        return ['valid' => false, 'key_status' => 'unknown-key', 'compromised' => false];
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
     * Returns the ACTIVE key's uuid and short fingerprint. Reads the stored record, not getActiveKeyRecord(), which throws when the key can't be decrypted; the settings page must still render.
     *
     * @return array{uuid: string, fingerprint: string}|array Empty when no readable key is stored.
     */
    public static function getActiveKeyInfo(): array
    {
        // Same gate as getHistoryFingerprints(): this decrypts key material to fingerprint it.
        if (!current_user_can('manage_options')) {
            throw new \RuntimeException('Insufficient permissions to view the active seal key.');
        }

        $stored = self::storedActiveRecord();
        if ($stored === null) {
            return [];
        }
        try {
            $plaintext = self::decryptKey($stored['key']);
        } catch (\Exception $e) {
            $plaintext = '';
        }
        $fingerprint = $plaintext !== '' ? substr(hash('sha256', $plaintext), 0, 6) : '';
        unset($plaintext); // discard the key material immediately

        return ['uuid' => $stored['uuid'], 'fingerprint' => $fingerprint];
    }
    // Seal key length in bytes (hex-encoded to 64 chars for storage — see the format
    // addLegacyKey()'s importer validates). Keys are random; nothing is derived from a password.
    private const KDF_LEN    = 32;
    private const ENC_PREFIX = 'enc::';

    /* ------------------------------------------------------------------ */
    /* UUID                                                                 */
    /* ------------------------------------------------------------------ */
    /**
     * Deliberately skips getHistory(): decrypts, hashes, and discards each key one at a time instead of materializing all retired plaintext keys at once (NIST SSDF PW.9).
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

        // Validate at the boundary, not later as an unexplained unknown-key failure indistinguishable from real tampering.
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
            throw new \RuntimeException('Legacy key id must be a UUID.');
        }
        if (!preg_match('/^[0-9a-f]{64}$/i', $key_value)) {
            throw new \RuntimeException('Legacy key must be a 64-character hex string.');
        }

        // One uuid, one key. verify() now tries every entry under a uuid, but a second key filed under an existing uuid
        // still leaves history ambiguous, so refuse it here. Compared by uuid only: stored keys may be encrypted.
        $existing_uuids = [];
        $active_raw     = get_option('fabricator_forms_seal_key');
        $active_rec     = $active_raw ? json_decode((string) $active_raw, true) : null;
        if (is_array($active_rec) && isset($active_rec['uuid'])) {
            $existing_uuids[] = strtolower((string) $active_rec['uuid']);
        }
        foreach ((array) get_option('fabricator_forms_seal_key_history', []) as $existing_entry) {
            if (is_array($existing_entry) && isset($existing_entry['uuid'])) {
                $existing_uuids[] = strtolower((string) $existing_entry['uuid']);
            }
        }
        if (in_array(strtolower($uuid), $existing_uuids, true)) {
            throw new \RuntimeException('A seal key with this UUID is already stored.');
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
