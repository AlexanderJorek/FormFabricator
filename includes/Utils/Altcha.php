<?php

/**
 * Self-hosted CAPTCHA challenges for the ALTCHA widget.
 *
 * PHP Version 8.1
 *
 * @category  FormFabricator
 * @package   FormFabricator
 * @author    Alexander Jorek
 * @copyright 2026 Alexander Jorek
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 * @version   1.0.8
 * @link      https://github.com/AlexanderJorek/FormFabricator
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 3
 * of the License, or (at your option) any later version.
 */

namespace FabricatorForms\Utils;

defined('ABSPATH') || exit;

/**
 * The server side of the ALTCHA widget (vendor/altcha): a proof-of-work challenge the visitor's browser solves, checked
 * with this site's own secret. No third party sees the visitor, and nothing is stored about them.
 *
 * ALTCHA 3's "PBKDF2/SHA-256": the browser tries counters until PBKDF2-SHA-256(nonce ‖ counter as big-endian uint32,
 * salt, cost) starts with the key prefix. The server picks the counter itself, derives the key once and signs the
 * parameters and the key, so checking a solution takes two HMACs and fake solutions cost almost nothing. Each
 * challenge counts once (SingleUseToken).
 */
final class Altcha
{
    /**
     * The proof-of-work algorithm, as the widget names it.
     *
     * @var string
     */
    public const ALGORITHM = 'PBKDF2/SHA-256';

    /**
     * Highest counter a challenge's solution may need, by default: about 2,500 key derivations on average, a second or
     * two in a browser. The fabricator_altcha_difficulty filter changes it.
     *
     * @var int
     */
    public const DEFAULT_DIFFICULTY = 5000;

    // PBKDF2 iterations per key the browser derives, the derived key's length in bytes, and how long a challenge holds.
    private const COST       = 1000;
    private const KEY_LENGTH = 32;
    private const TTL        = 1200;

    /**
     * A new challenge, as the widget fetches it.
     *
     * @return array{parameters: array<string, int|string>, signature: string}
     */
    public static function createChallenge(): array
    {
        $difficulty = (int) apply_filters('fabricator_altcha_difficulty', self::DEFAULT_DIFFICULTY);
        return self::challengeFor(random_int(0, max(1, min(1000000, $difficulty))));
    }

    /**
     * A challenge whose solution is $counter. createChallenge() picks it at random; tests pick it to solve quickly.
     *
     * @param int $counter The counter the browser has to find.
     * @return array{parameters: array<string, int|string>, signature: string}
     */
    private static function challengeFor(int $counter): array
    {
        $nonce = random_bytes(16);
        $salt  = random_bytes(16);
        $key   = self::deriveKey($nonce, $salt, $counter);
        $parameters = [
            'algorithm'    => self::ALGORITHM,
            'cost'         => self::COST,
            'expiresAt'    => time() + self::TTL,
            'keyLength'    => self::KEY_LENGTH,
            // Half the key: the browser stops at the counter whose key starts with it, which is this one.
            'keyPrefix'    => bin2hex(substr($key, 0, intdiv(self::KEY_LENGTH, 2))),
            'keySignature' => hash_hmac('sha256', $key, self::secret('key')),
            'nonce'        => bin2hex($nonce),
            'salt'         => bin2hex($salt),
        ];
        return ['parameters' => $parameters, 'signature' => hash_hmac('sha256', self::canonical($parameters), self::secret('challenge'))];
    }

    /**
     * Whether a widget payload (the base64 JSON it posts) solves a challenge of this site, still valid and not yet used.
     * Claims the challenge when it does, so it counts once.
     *
     * @param string $payload As posted.
     * @return bool
     */
    public static function verify(string $payload): bool
    {
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- the widget posts its JSON payload base64-encoded. Not obfuscation.
        $json = base64_decode($payload, true);
        $data = is_string($json) && strlen($json) <= 8192 ? json_decode($json, true) : null;
        $parameters = $data['challenge']['parameters'] ?? null;
        $signature  = $data['challenge']['signature'] ?? null;
        $counter    = $data['solution']['counter'] ?? null;
        $key        = $data['solution']['derivedKey'] ?? null;
        if (!is_array($parameters) || !is_string($signature) || !is_int($counter) || !is_string($key)) {
            return false;
        }
        // Signed by this site, unchanged: everything below can then trust the parameters.
        if (!hash_equals(hash_hmac('sha256', self::canonical($parameters), self::secret('challenge')), $signature)) {
            return false;
        }
        if (($parameters['algorithm'] ?? '') !== self::ALGORITHM || (int) ($parameters['expiresAt'] ?? 0) < time()) {
            return false;
        }
        if ($counter < 0 || $counter > 4294967295 || preg_match('/^(?:[0-9a-f]{2})+$/', $key) !== 1) {
            return false;
        }
        $key_signature = (string) ($parameters['keySignature'] ?? '');
        if ($key_signature === '' || !hash_equals($key_signature, hash_hmac('sha256', (string) hex2bin($key), self::secret('key')))) {
            return false;
        }
        // Once only, for as long as the challenge would still be accepted.
        return SingleUseToken::claim('altcha_' . (string) $parameters['nonce'], max(60, (int) $parameters['expiresAt'] - time()));
    }

    /**
     * AJAX: a new challenge for the widget, which fetches one when the visitor starts the check. Never cached: a challenge
     * counts once, and a cached one would be the same for every visitor.
     *
     * @return void
     */
    public static function ajaxChallenge(): void
    {
        nocache_headers();
        wp_send_json(self::createChallenge());
    }

    /**
     * The key the browser derives for $counter (PBKDF2-SHA-256 over the nonce and the counter as a big-endian uint32).
     *
     * @param string $nonce   Raw nonce bytes.
     * @param string $salt    Raw salt bytes.
     * @param int    $counter The counter.
     * @return string Raw key bytes.
     */
    private static function deriveKey(string $nonce, string $salt, int $counter): string
    {
        return hash_pbkdf2('sha256', $nonce . pack('N', $counter), $salt, self::COST, self::KEY_LENGTH, true);
    }

    /**
     * The parameters as the widget signs and checks them: keys sorted, JSON without escaped slashes, which is what
     * JavaScript's JSON.stringify() writes.
     *
     * @param array<string, mixed> $parameters Challenge parameters.
     * @return string
     */
    private static function canonical(array $parameters): string
    {
        ksort($parameters);
        return (string) wp_json_encode($parameters, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * A secret for one purpose, derived from the auth salt and bound to the site: network sites share the salts but
     * each keeps its own record of used challenges.
     *
     * @param string $purpose 'challenge' or 'key'.
     * @return string
     */
    private static function secret(string $purpose): string
    {
        return hash_hmac('sha256', 'fabricator-altcha|' . $purpose . '|' . get_current_blog_id(), wp_salt('auth'));
    }
}
