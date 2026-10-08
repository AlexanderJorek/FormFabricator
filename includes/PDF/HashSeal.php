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
 * @version   1.0.9
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
     * Exception code of a key write refused because FABRICATOR_SEAL_MASTER_KEY is not the master key the stored keys
     * were encrypted with (masterKeyMatches()). Callers check getCode(), not the message.
     *
     * @var int
     */
    public const WRONG_MASTER_KEY = 1001;

    // The check value masterKeyMatches() reads: this text, encrypted under the master key.
    private const MASTER_CHECK_OPTION = 'fabricator_forms_seal_master_check';
    private const MASTER_CHECK_TEXT   = 'fabricator-master-key-check';

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
        return get_option('fabricator_forms_seal_encryption') === 'enabled' && self::masterKeyConfigured();
    }

    /**
     * True when FABRICATOR_SEAL_MASTER_KEY is defined in wp-config.php. Decides whether unencrypted keys are refused
     * (decryptKey()): unlike the storage option, the database cannot change it.
     *
     * @return bool
     */
    public static function masterKeyConfigured(): bool
    {
        return defined('FABRICATOR_SEAL_MASTER_KEY') && (string) FABRICATOR_SEAL_MASTER_KEY !== '';
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
     * @param string $aad       keyAad() of the record it will be stored in.
     * @return string Encrypted value prefixed with nonce and tag.
     */
    private static function encryptKey(string $plaintext, string $aad): string
    {
        self::requireOpenssl();
        $iv  = random_bytes(12);
        $tag = '';
        $ct  = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            self::masterKey(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $aad
        );
        if ($ct === false) {
            throw new \RuntimeException('FabricatorForms: key encryption failed.');
        }
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- stores AES-256-GCM ciphertext (iv|tag|ct) as text in wp_options, which cannot hold raw binary safely. Not obfuscation.
        return self::ENC_PREFIX . base64_encode($iv . $tag . $ct);
    }

    /**
     * Decrypts an encrypted key value; returns an unencrypted one as it is, unless a master key is configured.
     *
     * With FABRICATOR_SEAL_MASTER_KEY defined every stored key is encrypted, so an unencrypted one was planted by a
     * database write and is refused. The constant decides, not the storage option, which the same write could flip.
     * The ciphertext is bound to its record (keyAad()): moved, or with its "compromised" flag cleared, it no longer
     * decrypts.
     *
     * @param string $value Encrypted or plaintext key value.
     * @param string $aad   keyAad() of the record the value is stored in.
     * @return string Decrypted plaintext key.
     * @throws \RuntimeException When the value cannot be decrypted, or is unencrypted while a master key is configured.
     */
    private static function decryptKey(string $value, string $aad): string
    {
        if (strncmp($value, self::ENC_PREFIX, strlen(self::ENC_PREFIX)) !== 0) {
            if (self::masterKeyConfigured()) {
                throw new \RuntimeException('FabricatorForms: an unencrypted seal key was refused because a master key is configured.');
            }
            return $value; // unencrypted — plain hex
        }
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- reads back the AES-256-GCM ciphertext stored by encryptKey(). Not obfuscation.
        $data = base64_decode(substr($value, strlen(self::ENC_PREFIX)));
        if ($data === false || strlen($data) < 29) {
            throw new \RuntimeException('FabricatorForms: encrypted key data is malformed.');
        }
        self::requireOpenssl();
        $iv  = substr($data, 0, 12);
        $tag = substr($data, 12, 16);
        $ct  = substr($data, 28);
        $pt  = openssl_decrypt($ct, 'aes-256-gcm', self::masterKey(), OPENSSL_RAW_DATA, $iv, $tag, $aad);
        if ($pt === false) {
            throw new \RuntimeException(
                'FabricatorForms: key decryption failed — master key may be incorrect or missing.'
            );
        }
        return $pt;
    }

    /**
     * Encrypts a key whenever a master key is configured; otherwise returns it as-is.
     *
     * Same rule as decryptKey(): the constant, not the storage option. Following the option, a key written before
     * encryption is confirmed would be stored in plaintext and then refused.
     *
     * @param string $plaintext Plaintext value to conditionally encrypt.
     * @param string $aad       keyAad() of the record it will be stored in.
     * @return string Encrypted value or original plaintext.
     */
    private static function maybeEncrypt(string $plaintext, string $aad): string
    {
        return self::masterKeyConfigured() ? self::encryptKey($plaintext, $aad) : $plaintext;
    }

    /**
     * Throws a RuntimeException, which every caller handles, when openssl is missing. Calling the missing function
     * would throw an Error, which the catch (\Exception) blocks let through.
     *
     * @return void
     */
    private static function requireOpenssl(): void
    {
        if (!function_exists('openssl_encrypt')) {
            throw new \RuntimeException('FabricatorForms: the PHP openssl extension is missing.');
        }
    }

    /**
     * The associated data a stored key's ciphertext is bound to: its slot, its UUID, and for a retired key its status
     * and "compromised" flag. A database write that clears a leaked key's flag or copies a retired key into the active
     * slot leaves the key undecryptable, never trusted. Rotation re-encrypts a key for its new slot.
     *
     * @param string $slot        'active', 'retired', 'pending' (a download waiting), 'set-aside' (a damaged record) or
     *                            'check' (masterKeyMatches()).
     * @param string $uuid        The key's UUID.
     * @param string $status      A retired key's status ('initial', 'rotated', 'rotated-legacy', …).
     * @param bool   $compromised A retired key's "compromised" flag.
     * @return string
     */
    private static function keyAad(string $slot, string $uuid, string $status = '', bool $compromised = false): string
    {
        return implode("\x1F", ['fabricator-seal-key', $slot, $uuid, $status, $compromised ? '1' : '0']);
    }

    /**
     * keyAad() for a history entry, from the entry itself.
     *
     * @param array $entry History entry.
     * @return string
     */
    private static function historyAad(array $entry): string
    {
        return self::keyAad('retired', (string) ($entry['uuid'] ?? ''), (string) ($entry['status'] ?? ''), !empty($entry['compromised']));
    }

    // Encrypts the stored plaintext keys in place; already-encrypted ones are left alone.
    //
    // $only limits it to these UUIDs: when the master key predates the upgrade, encrypting a key planted in the
    // database would make it trusted, so the admin picks the keys they recognize (unencryptedKeys()). Null encrypts
    // them all, right after the master key is issued.
    //
    // Under rotateKey()'s lock, failing closed: a rotation in between would lose a key for good.
    public static function encryptExistingKeys(?array $only = null): void
    {
        \FabricatorForms\Utils\OptionMutex::run(
            'fabricator_forms_seal_key_history',
            static function () use ($only): void {
                self::encryptExistingKeysLocked($only);
            },
            3000,
            true
        );
    }

    /**
     * A key's fingerprint for the backup file and the upgrade dialog: the first 128 bits of SHA-256 over the plaintext
     * key, in groups of four hex characters. A UUID is only a label; this names the key itself. The key table shows its
     * first six characters.
     *
     * @param string $plaintext The key as 64 hex characters.
     * @return string
     */
    public static function keyFingerprint(string $plaintext): string
    {
        return implode(' ', str_split(substr(hash('sha256', $plaintext), 0, 32), 4));
    }

    /**
     * The stored keys that are not encrypted, once per UUID with their fingerprint, for the admin to check against
     * their backups before encryptExistingKeys().
     *
     * @return array<int, array{uuid: string, fingerprint: string, status: string, date: string}> status: active, retired,
     *                                                                                     set-aside, pending.
     */
    public static function unencryptedKeys(): array
    {
        $found = [];
        $add   = static function (mixed $record, string $status, string $date = '') use (&$found): void {
            if (!is_array($record) || !is_string($record['uuid'] ?? null) || !is_string($record['key'] ?? null)
                || $record['key'] === '' || self::isEncryptedValue($record['key']) || isset($found[$record['uuid']])
            ) {
                return;
            }
            $found[$record['uuid']] = [
                'uuid'        => $record['uuid'],
                'fingerprint' => self::keyFingerprint($record['key']),
                'status'      => $status,
                'date'        => $date,
            ];
        };

        $add(json_decode((string) get_option('fabricator_forms_seal_key', ''), true), 'active');
        $history = get_option('fabricator_forms_seal_key_history', []);
        foreach (is_array($history) ? $history : [] as $entry) {
            $add($entry, 'retired', is_array($entry) && is_string($entry['retired_at'] ?? null) ? $entry['retired_at'] : '');
        }
        $damaged = get_option('fabricator_forms_seal_key_damaged', []);
        foreach (is_array($damaged) ? $damaged : [] as $record) {
            $add(is_array($record) ? json_decode((string) ($record['value'] ?? ''), true) : null, 'set-aside');
        }
        $pending = json_decode((string) get_transient('fabricator_forms_seal_key_pending_download'), true);
        $add($pending, 'pending', is_array($pending) && is_string($pending['created_at'] ?? null) ? $pending['created_at'] : '');

        return array_values($found);
    }

    /**
     * encryptExistingKeys()'s work, run inside the seal-key lock.
     *
     * @param string[]|null $only UUIDs to encrypt, or null for all.
     * @return void
     */
    private static function encryptExistingKeysLocked(?array $only): void
    {
        self::assertMasterKeyMatches();
        $chosen = static fn(mixed $uuid): bool => $only === null || in_array($uuid, $only, true);

        // Active key
        $raw = get_option('fabricator_forms_seal_key');
        if ($raw) {
            $rec = json_decode((string) $raw, true);
            $not_yet_encrypted = strncmp((string)($rec['key'] ?? ''), self::ENC_PREFIX, strlen(self::ENC_PREFIX)) !== 0;
            if (is_array($rec) && isset($rec['uuid'], $rec['key']) && $not_yet_encrypted && $chosen($rec['uuid'])) {
                $rec['key'] = self::encryptKey($rec['key'], self::keyAad('active', (string) $rec['uuid']));
                update_option('fabricator_forms_seal_key', wp_json_encode($rec), false);
            }
        }

        // History. One that isn't a list has nothing to encrypt, but the stores below still do.
        $history = get_option('fabricator_forms_seal_key_history', []);
        if (is_array($history)) {
            $changed = false;
            foreach ($history as &$entry) {
                $prefix              = self::ENC_PREFIX;
                $entry_not_encrypted = strncmp((string)($entry['key'] ?? ''), $prefix, strlen($prefix)) !== 0;
                if (isset($entry['key']) && $entry_not_encrypted && $chosen($entry['uuid'] ?? null)) {
                    $entry['key'] = self::encryptKey($entry['key'], self::historyAad($entry));
                    $changed      = true;
                }
            }
            unset($entry);
            if ($changed) {
                update_option('fabricator_forms_seal_key_history', $history, false);
            }
        }

        self::encryptSetAsideKeys($chosen);
        self::encryptPendingDownload($chosen);
        self::rememberMasterKey();
    }

    /**
     * Encrypts key material inside set-aside damaged records.
     *
     * A record set aside before encryption was switched on holds its key as plaintext.
     *
     * @param callable $chosen Whether a UUID may be encrypted (encryptExistingKeys()'s $only).
     * @return void
     */
    private static function encryptSetAsideKeys(callable $chosen): void
    {
        $damaged = get_option('fabricator_forms_seal_key_damaged', []);
        if (!is_array($damaged) || $damaged === []) {
            return;
        }
        $changed = false;
        foreach ($damaged as &$record) {
            $decoded = is_array($record) ? json_decode((string) ($record['value'] ?? ''), true) : null;
            // An unparseable record is left as found: nothing can tell its key from the damage.
            if (!is_array($decoded) || !isset($decoded['key']) || self::isEncryptedValue((string) $decoded['key'])
                || !$chosen($decoded['uuid'] ?? null)
            ) {
                continue;
            }
            $decoded['key']   = self::encryptKey((string) $decoded['key'], self::keyAad('set-aside', (string) ($decoded['uuid'] ?? '')));
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
     * @param callable $chosen Whether a UUID may be encrypted (encryptExistingKeys()'s $only).
     * @return void
     */
    private static function encryptPendingDownload(callable $chosen): void
    {
        $raw = get_transient('fabricator_forms_seal_key_pending_download');
        if (!$raw) {
            return;
        }
        $record = json_decode((string) $raw, true);
        if (!is_array($record) || !isset($record['uuid'], $record['key']) || self::isEncryptedValue((string) $record['key'])
            || !$chosen($record['uuid'])
        ) {
            return;
        }
        $record['key'] = self::encryptKey((string) $record['key'], self::keyAad('pending', (string) $record['uuid']));
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
     * Never creates a key (only createInitialKey() and rotateKey() do): concurrent requests would each store their own,
     * and a record that merely failed to decode would be replaced. Throwing fails the submission closed, and
     * Plugin::maybeWarnSealKeyUnusable() tells the admin to rotate.
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
        if (self::isRetiredUuid($stored['uuid'], self::storedHistory())) {
            throw new \RuntimeException('FabricatorForms HashSeal: the active seal key record holds a retired key. Rotate it under Settings.');
        }
        return ['uuid' => $stored['uuid'], 'key' => self::decryptKey($stored['key'], self::keyAad('active', $stored['uuid']))];
    }

    /**
     * The stored key history, entries with their keys as stored.
     *
     * @return array[]
     */
    private static function storedHistory(): array
    {
        $history = get_option('fabricator_forms_seal_key_history', []);
        return is_array($history) ? array_values(array_filter($history, 'is_array')) : [];
    }

    /**
     * Whether the history holds a key with this UUID, i.e. it was retired.
     *
     * The live active key is never in the history, so an active record with a retired UUID was copied back from an
     * older database state. It still decrypts in the active slot, but is never trusted as active: its history entry
     * decides.
     *
     * @param string  $uuid    Key UUID.
     * @param array[] $history storedHistory().
     * @return bool
     */
    private static function isRetiredUuid(string $uuid, array $history): bool
    {
        foreach ($history as $entry) {
            if (($entry['uuid'] ?? null) === $uuid) {
                return true;
            }
        }
        return false;
    }

    /**
     * Creates the first seal key during setup and flags it for download.
     *
     * Atomic: INSERT IGNORE on the unique option_name never replaces an existing record (add_option() upserts).
     *
     * @return bool True when this call created the key.
     */
    public static function createInitialKey(): bool
    {
        if (!current_user_can('manage_options')) {
            throw new \RuntimeException('Insufficient permissions to create the seal key.');
        }
        self::assertMasterKeyIfEncrypted();
        self::assertMasterKeyMatches();

        $uuid    = self::generateUuid();
        $raw_key = bin2hex(random_bytes(self::KDF_LEN));
        $record  = wp_json_encode(['uuid' => $uuid, 'key' => self::maybeEncrypt($raw_key, self::keyAad('active', $uuid))]);
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
            self::rememberMasterKey();
            self::setPendingDownload($uuid, $raw_key);
        }
        return $created;
    }

    /**
     * Read-only health check for the admin notice. Never creates a key.
     *
     * @return string '' when sealing can run; otherwise 'missing', 'damaged', 'retired' (isRetiredUuid()), 'wrong-master-key'
     *                (masterKeyMatches()) or 'undecryptable'.
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
        if (self::isRetiredUuid($stored['uuid'], self::storedHistory())) {
            return 'retired';
        }
        try {
            $usable = self::decryptKey($stored['key'], self::keyAad('active', $stored['uuid'])) !== '';
        } catch (\Exception $e) {
            return self::masterKeyMatches() === false ? 'wrong-master-key' : 'undecryptable';
        }
        return $usable ? '' : 'damaged';
    }

    /**
     * Clears WordPress's caches for an option written by a direct query, including the "notoptions" miss cache.
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
     * Whether FABRICATOR_SEAL_MASTER_KEY is the master key the stored seal keys were encrypted with, read from a check
     * value encrypted under it. Null when unknown: no master key, no check value, or no openssl.
     *
     * Rotation moves an undecryptable record into the history as stored; under the wrong master key that would bury a
     * key the right one could still read. So every key write refuses while this is false (WRONG_MASTER_KEY).
     *
     * @return bool|null
     */
    private static function masterKeyMatches(): ?bool
    {
        if (!self::masterKeyConfigured() || !function_exists('openssl_decrypt')) {
            return null;
        }
        $check = get_option(self::MASTER_CHECK_OPTION);
        if (!is_string($check) || $check === '') {
            return null;
        }
        try {
            return self::decryptKey($check, self::keyAad('check', '')) === self::MASTER_CHECK_TEXT;
        } catch (\RuntimeException $e) {
            return false;
        }
    }

    /**
     * Records the check value for masterKeyMatches(), unless one exists. Called only where the configured master key
     * has just proven itself.
     *
     * @return void
     */
    private static function rememberMasterKey(): void
    {
        if (self::masterKeyConfigured() && !is_string(get_option(self::MASTER_CHECK_OPTION))) {
            update_option(self::MASTER_CHECK_OPTION, self::encryptKey(self::MASTER_CHECK_TEXT, self::keyAad('check', '')), false);
        }
    }

    /**
     * Throws when masterKeyMatches() is false.
     *
     * @return void
     * @throws \RuntimeException With the code WRONG_MASTER_KEY.
     */
    private static function assertMasterKeyMatches(): void
    {
        if (self::masterKeyMatches() === false) {
            \FabricatorForms\fabricator_log('FabricatorForms HashSeal: FABRICATOR_SEAL_MASTER_KEY does not open the stored check value; key write refused.');
            // Cast: the escaping sniff reads every argument of a throw as output, and takes an int cast as safe.
            throw new \RuntimeException('FabricatorForms HashSeal: the configured master key is not the one the seal keys were encrypted with.', (int) self::WRONG_MASTER_KEY);
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
     * Copies an unusable active key record to fabricator_forms_seal_key_damaged before rotation replaces it, so its key
     * can still be recovered by hand.
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
            'FabricatorForms HashSeal: an unusable seal key record was kept in fabricator_forms_seal_key_damaged before rotation.'
        );
    }

    // Stored as a transient (not a plain option) so the plaintext key self-expires even if never downloaded.
    private const PENDING_DOWNLOAD_TTL = 10 * MINUTE_IN_SECONDS;

    /**
     * Stores a pending key download as a transient that expires after PENDING_DOWNLOAD_TTL.
     *
     * @param string $uuid          UUID of the key.
     * @param string $plaintext_key Plaintext key value.
     * @return void
     */
    private static function setPendingDownload(string $uuid, string $plaintext_key): void
    {
        set_transient(
            'fabricator_forms_seal_key_pending_download',
            wp_json_encode(
                [
                'uuid'       => $uuid,
                // Same at-rest protection as the stored key: with encryption on, the transient never holds plaintext.
                'key'        => self::maybeEncrypt($plaintext_key, self::keyAad('pending', $uuid)),
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

    /**
     * Rotates the active seal key. The new key is random.
     *
     * @param bool $compromised     True to flag the retiring key as compromised.
     * @param bool $nonce_verified  True when the caller already checked a CSRF nonce; otherwise this checks its own.
     * @param bool $master_key_lost True when the admin confirms the old master key is lost: rotation then goes ahead
     *                              under the configured one (see masterKeyMatches()).
     * @return array{uuid: string, key: string, created_at: string}
     */
    public static function rotateKey(bool $compromised, bool $nonce_verified = false, bool $master_key_lost = false): array
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

        // One lock around read and write, or concurrent rotations drop each other's retired key. Fail closed: a lost
        // key can't be redone.
        return \FabricatorForms\Utils\OptionMutex::run(
            'fabricator_forms_seal_key_history',
            static fn(): array => self::rotateKeyLocked($compromised, $user_id, $user_login, $retired_at, $master_key_lost),
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
     * @param bool   $master_key_lost See rotateKey().
     * @return array{uuid: string, key: string, created_at: string}
     */
    private static function rotateKeyLocked(
        bool $compromised,
        int $user_id,
        string $user_login,
        string $retired_at,
        bool $master_key_lost
    ): array {
        if ($master_key_lost && self::masterKeyMatches() === false) {
            // The admin starts over: the configured master key becomes the one the check value names.
            \FabricatorForms\fabricator_log('FabricatorForms HashSeal: the stored master key check was replaced; the admin confirmed the old master key is lost.');
            delete_option(self::MASTER_CHECK_OPTION);
        }
        self::assertMasterKeyMatches();

        $history = get_option('fabricator_forms_seal_key_history', []);
        if (!is_array($history)) {
            $history = [];
        }

        $current = self::storedActiveRecord();
        if ($current !== null && self::isRetiredUuid($current['uuid'], self::storedHistory())) {
            // A retired key copied back: its history entry stays the only one, flags included, and the copy is set aside.
            self::setAsideDamagedRecord();
        } elseif ($current !== null) {
            // Re-encrypted for its history entry (keyAad()). One that doesn't decrypt is moved as stored: rotating is how
            // an admin recovers from a damaged record. An unencrypted key stays unencrypted: with a master key
            // configured it was planted, and encrypting it would make it trusted.
            $status      = empty($history) ? 'initial' : 'rotated';
            $retired_key = $current['key'];
            if (self::masterKeyConfigured() && self::isEncryptedValue($retired_key)) {
                try {
                    $plaintext   = self::decryptKey($retired_key, self::keyAad('active', $current['uuid']));
                    $retired_key = self::encryptKey($plaintext, self::keyAad('retired', $current['uuid'], $status, $compromised));
                    unset($plaintext);
                    self::rememberMasterKey();
                } catch (\RuntimeException $e) {
                    \FabricatorForms\fabricator_log('FabricatorForms HashSeal: the retiring key could not be re-encrypted and is kept as stored.');
                }
            }
            $history[]   = [
                'uuid'             => $current['uuid'],
                'key'              => $retired_key,
                'status'           => $status,
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
        // Random, not derived from a password, which anyone holding a sealed PDF could brute-force offline.
        $new_raw_key = bin2hex(random_bytes(self::KDF_LEN));

        update_option(
            'fabricator_forms_seal_key',
            wp_json_encode(['uuid' => $new_uuid, 'key' => self::maybeEncrypt($new_raw_key, self::keyAad('active', $new_uuid))]),
            false
        );
        update_option('fabricator_forms_seal_key_history', $history, false);
        self::setPendingDownload($new_uuid, $new_raw_key);

        return ['uuid' => $new_uuid, 'key' => $new_raw_key, 'created_at' => $retired_at];
    }

    /**
     * Reads the pending key download without consuming it, so a reload can't burn the one-shot backup.
     *
     * @return array{uuid: string, key: string, created_at: string, fingerprint: string}|null
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
     * @return array{uuid: string, key: string, created_at: string, fingerprint: string}|null
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
     * @return array{uuid: string, key: string, created_at: string, fingerprint: string}|null
     */
    private static function decodePendingDownload(mixed $raw): ?array
    {
        if (!$raw) {
            return null;
        }
        $record = json_decode((string) $raw, true);
        if (is_array($record) && isset($record['uuid'], $record['key'])) {
            try {
                $record['key'] = self::decryptKey((string) $record['key'], self::keyAad('pending', (string) $record['uuid']));
            } catch (\Exception $e) {
                \FabricatorForms\fabricator_log('FabricatorForms HashSeal: pending key download could not be decrypted — ' . $e->getMessage());
                return null;
            }
            // Into the backup file with the key, for the admin to compare with what a later dialog shows.
            $record['fingerprint'] = self::keyFingerprint($record['key']);
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

        // key_status distinguishes an unfamiliar-key state (not tampering) from a real HMAC mismatch.
        $key_id = isset($data['key_id']) && is_string($data['key_id']) ? $data['key_id'] : null;
        if ($key_id === null) {
            return ['valid' => false, 'key_status' => 'unknown-key', 'compromised' => false];
        }

        // Every key filed under this uuid is tried, so a mistyped legacy import can't shadow the real key.
        $uuid_found    = false;
        $undecryptable = false;

        // Not getActiveKeyRecord(), which throws: the retired keys below must still be tried, and a wrong master key
        // must reach "Key Unreadable". A retired key copied back (isRetiredUuid()) is vouched for only by its history entry.
        $active = self::storedActiveRecord();
        if ($active !== null && $active['uuid'] === $key_id && !self::isRetiredUuid($key_id, self::storedHistory())) {
            $uuid_found = true;
            try {
                $active_key = self::decryptKey($active['key'], self::keyAad('active', $active['uuid']));
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
                $entry_key = self::decryptKey((string)($entry['key'] ?? ''), self::historyAad($entry));
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
     * The active key's uuid and short fingerprint. Not via getActiveKeyRecord(), which throws: the settings page must
     * still render.
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
        // A retired key copied back is no active key (isRetiredUuid()); the history lists it, and a notice asks to rotate.
        if ($stored === null || self::isRetiredUuid($stored['uuid'], self::storedHistory())) {
            return [];
        }
        try {
            $plaintext = self::decryptKey($stored['key'], self::keyAad('active', $stored['uuid']));
        } catch (\Exception $e) {
            $plaintext = '';
        }
        $fingerprint = $plaintext !== '' ? substr(hash('sha256', $plaintext), 0, 6) : '';
        unset($plaintext); // discard the key material immediately

        return ['uuid' => $stored['uuid'], 'fingerprint' => $fingerprint];
    }
    // Seal key length in bytes, stored as 64 hex characters.
    private const KDF_LEN    = 32;
    private const ENC_PREFIX = 'enc::';

    /* ------------------------------------------------------------------ */
    /* Key fingerprints                                                     */
    /* ------------------------------------------------------------------ */
    /**
     * The retired keys' fingerprints. Decrypts, hashes and discards one key at a time, so the plaintext keys never sit
     * in memory together.
     *
     * @return array
     */
    public static function getHistoryFingerprints(): array
    {
        // Defense-in-depth: the caller's gate is not relied on alone, since this decrypts key material.
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
                    $plaintext   = self::decryptKey($entry['key'], self::historyAad($entry));
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
     * @param bool   $nonce_verified True when the caller already checked a CSRF nonce; otherwise this checks its own.
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

        // Under rotateKey()'s lock, failing closed: a rotation between read and write would lose the key it retired.
        \FabricatorForms\Utils\OptionMutex::run(
            'fabricator_forms_seal_key_history',
            static function () use ($uuid, $key_value, $created_at, $status): void {
                self::addLegacyKeyLocked($uuid, $key_value, $created_at, $status);
            },
            3000,
            true
        );
    }

    /**
     * addLegacyKey()'s work, run inside the seal-key lock once permission, nonce and format are checked.
     *
     * @param string $uuid       Validated key UUID.
     * @param string $key_value  Validated 64-character hex key.
     * @param string $created_at When the key was retired, or '' for now.
     * @param string $status     'rotated-legacy' or 'compromised-legacy'.
     * @return void
     */
    private static function addLegacyKeyLocked(string $uuid, string $key_value, string $created_at, string $status): void
    {
        // Encrypted under the wrong master key, the imported key would be unreadable once the right one is back.
        self::assertMasterKeyMatches();

        // One uuid, one key. Compared by uuid only: stored keys may be encrypted.
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
            'key'              => self::maybeEncrypt($key_value, self::keyAad('retired', $uuid, $safe_status, $compromised)),
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
     * Largest seal block (base64 text in the PDF) the generator writes and the verifier reads. One number for both, so
     * the verifier never refuses a genuine PDF; far above any real seal.
     *
     * @var int
     */
    public const MAX_SEAL_BLOCK_BYTES = 16777216;

    /**
     * Generates an HMAC-SHA256 seal. Deterministic per key, so identical payloads are linkable.
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
