<?php

/**
 * Atomic, exactly-once claim primitive.
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

namespace FabricatorForms\Utils;

defined('ABSPATH') || exit;

// Atomic, exactly-once claim backed by wp_options' UNIQUE KEY (INSERT IGNORE) — closes the TOCTOU
// window a get_transient()/set_transient() read-then-write would leave open.
class SingleUseToken
{
    /**
     * How long an issued submission token is accepted. Matches wp_verify_nonce()'s outer bound, which
     * the submit nonce minted alongside it cannot outlive either.
     *
     * @var int
     */
    public const ISSUED_MAX_AGE = 86400;

    /**
     * How long a claim row is kept: the token's own lifetime plus clock slack, after which a replay
     * would fail the age check in verifyIssued() anyway.
     *
     * @var int
     */
    public const CLAIM_TTL = 90000;

    /**
     * Mints a submission token bound to $form_id and its issue time: "<uuid>.<issued>.<hmac>".
     *
     * Stateless on purpose. Issuing writes nothing, so the token endpoint cannot be used to grow the
     * options table; only a token that passes verifyIssued() and full validation is ever claimed.
     *
     * @param int $form_id Form the token is valid for.
     * @return string Token for the fabricator_submission_token field.
     */
    public static function issue(int $form_id): string
    {
        $id     = wp_generate_uuid4();
        $issued = time();
        return $id . '.' . $issued . '.' . self::sign($id, $issued, $form_id);
    }

    /**
     * Checks that a token was issued by issue() for this form and has not expired.
     *
     * @param string $token   Submitted token.
     * @param int    $form_id Form being submitted.
     * @return bool True for a genuine, unexpired token.
     */
    public static function verifyIssued(string $token, int $form_id): bool
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return false;
        }
        [$id, $issued, $mac] = $parts;
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id)
            || !ctype_digit($issued)
            || !preg_match('/^[0-9a-f]{64}$/', $mac)
        ) {
            return false;
        }
        // Five minutes of forward slack for clock skew between web nodes.
        $age = time() - (int) $issued;
        if ($age < -300 || $age > self::ISSUED_MAX_AGE) {
            return false;
        }
        return hash_equals(self::sign($id, (int) $issued, $form_id), $mac);
    }

    /**
     * HMAC over everything the token binds.
     *
     * @param string $id      Token uuid.
     * @param int    $issued  Issue timestamp.
     * @param int    $form_id Form the token is valid for.
     * @return string Hex HMAC-SHA256.
     */
    private static function sign(string $id, int $issued, int $form_id): string
    {
        return hash_hmac('sha256', 'fabricator_submission|' . $form_id . '|' . $id . '|' . $issued, wp_salt('nonce'));
    }

    /**
     * Atomically attempts to claim $key. Returns true only for the first caller to claim it.
     *
     * @param string $key            Unique claim identifier, already hashed/sanitized by the caller.
     * @param int    $ttl_seconds    How long the claim is held before the sweep considers it expired.
     * @return bool True if this call created the claim, false if it was already held.
     */
    public static function claim(string $key, int $ttl_seconds): bool
    {
        global $wpdb;

        $opt    = 'fabricator_su_' . $key;
        $expiry = time() + $ttl_seconds;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic claim requires a direct query; option is never autoloaded/cached via get_option().
        $wpdb->query(
            $wpdb->prepare(
                "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
                $opt,
                (string) $expiry
            )
        );
        // Direct query bypasses WP's cache invalidation.
        wp_cache_delete($opt, 'options');

        return (int) $wpdb->rows_affected === 1;
    }

    /**
     * Releases a previously-claimed key so a legitimate retry isn't permanently blocked.
     *
     * @param string $key Same identifier passed to claim().
     * @return void
     */
    public static function release(string $key): void
    {
        global $wpdb;

        $opt = 'fabricator_su_' . $key;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- see claim() above; this option is never autoloaded/cached via get_option().
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s", $opt));
        wp_cache_delete($opt, 'options');
    }

    // WP-Cron callback (hourly): deletes any fabricator_su_* option row whose TTL has expired.
    public static function cronSweepExpired(): void
    {
        global $wpdb;

        $now = time();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- bulk sweep of own private rows; WP-Cron cleanup, not request-path.
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) <= %d",
                $wpdb->esc_like('fabricator_su_') . '%',
                $now
            )
        );
        wp_cache_delete('alloptions', 'options');
    }
}
