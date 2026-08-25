<?php

/**
 * Static image and storage helpers used internally by Generator and PdfDescriptor.
 *
 * PHP Version 8.1
 *
 * @category  FormFabricator
 * @package   FormFabricator
 * @author    Alexander Jorek
 * @copyright 2026 Alexander Jorek
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 * @version   1.0.4
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
 * Static image and storage helpers used internally by Generator and PdfDescriptor.
 */
class PdfUtils
{
    /**
     * Absolute pixel-count ceiling regardless of available memory — keeps a host with memory_limit = -1
     * (unlimited) from being asked to decode a multi-gigapixel image. Comfortably above any real
     * camera/scanner output (a 100 MP medium-format scan is still 2x under this).
     *
     * @var int
     */
    private const HARD_PIXEL_CEILING = 200_000_000;

    /**
     * Floor for the adaptive cap — never refuse to even attempt a modest ~1 MP image (e.g. a phone-camera
     * signature photo) even under a very constrained memory_limit.
     *
     * @var int
     */
    private const MIN_SAFE_PIXELS = 1_000_000;

    /**
     * Returns a safe pixel-count ceiling for image decoding, sized to the PHP memory_limit actually available
     * to this request. A single fixed constant is either too small to allow legitimate high-resolution
     * scans/uploads on a generously-provisioned host, or — if raised to accommodate those — too large to
     * guard against a decompression-bomb image on a constrained one. Scaling the cap to what the process can
     * actually afford solves both: a decoded truecolor bitmap costs ~4 bytes/pixel in GD, plus roughly
     * another 1.5x for working buffers during resample/copy operations, so at most half of the configured
     * memory_limit is reserved for that allocation.
     *
     * @return int Maximum total pixel count (width * height) considered safe to decode.
     */
    public static function maxSafePixels(): int
    {
        $limit = self::phpMemoryLimitBytes();
        if ($limit <= 0) {
            return self::HARD_PIXEL_CEILING;
        }
        $budget          = $limit * 0.5;
        $bytes_per_pixel = 4 * 1.5;
        $cap             = (int) ($budget / $bytes_per_pixel);
        return max(self::MIN_SAFE_PIXELS, min($cap, self::HARD_PIXEL_CEILING));
    }

    /**
     * Parses PHP's memory_limit ini setting into a byte count.
     *
     * @return int Byte count, or -1 when unlimited/unset.
     */
    public static function phpMemoryLimitBytes(): int
    {
        $val = trim((string) ini_get('memory_limit'));
        if ($val === '' || $val === '-1') {
            return -1;
        }
        $unit = strtolower(substr($val, -1));
        $num  = (int) $val;
        return match ($unit) {
            'g'     => $num * 1024 * 1024 * 1024,
            'm'     => $num * 1024 * 1024,
            'k'     => $num * 1024,
            default => (int) $val,
        };
    }

    /**
     * Cheaply checks an image's pixel dimensions from its header — without fully decoding it — against
     * the adaptive safe-pixel cap. This is the primary defense against decompression bombs:
     * getimagesizefromstring() reads only the format header (a few dozen bytes for JPEG/PNG/GIF/WEBP/BMP), so
     * a crafted file that would decode to a huge bitmap is rejected before GD ever allocates memory for it.
     * Returns true (safe to attempt decode) when the header can't be parsed — the post-decode check in
     * thumbnailHash()/attachImage() is the backstop for formats getimagesizefromstring() doesn't understand
     * but GD does.
     *
     * @param string $binary Raw binary image data.
     * @return bool True if the image's declared dimensions are within the safe cap (or unknown), false
     *              if they exceed it.
     */
    public static function precheckDimensions(string $binary): bool
    {
        $size = @getimagesizefromstring($binary);
        if ($size === false || !isset($size[0], $size[1])) {
            return true;
        }
        return ((int) $size[0] * (int) $size[1]) <= self::maxSafePixels();
    }

    /**
     * Computes a perceptual thumbnail hash of an image for stable cross-pass comparison. Downscales the image
     * to an 8×8 pixel grid and hashes the quantised RGB values, producing a hash that is stable across PDF
     * rendering passes. Returns null when the GD extension is unavailable, the image cannot be decoded, or
     * its pixel dimensions exceed the safe-decode cap.
     *
     * @param string $binary Raw binary image data.
     * @return string|null SHA-256 hash of the thumbnail pixels, or null on failure.
     */
    public static function thumbnailHash(string $binary): ?string
    {
        if (!function_exists('imagecreatefromstring')) {
            \FabricatorForms\fabricator_log(
                'FabricatorForms: GD extension unavailable — '
                . 'image verification uses raw hash (degraded mode).'
            );
            return null;
        }
        if (!self::precheckDimensions($binary)) {
            return null;
        }
        $gd = @imagecreatefromstring($binary);
        if ($gd === false) {
            return null;
        }
        // Backstop for formats getimagesizefromstring() couldn't pre-check.
        if (imagesx($gd) * imagesy($gd) > self::maxSafePixels()) {
            imagedestroy($gd);
            return null;
        }
        $thumb = imagecreatetruecolor(8, 8);
        imagealphablending($thumb, false);
        imagecopyresampled(
            $thumb,
            $gd,
            0,
            0,
            0,
            0,
            8,
            8,
            imagesx($gd),
            imagesy($gd)
        );
        $pixels = '';
        // Masking off the low 3 bits (& ~7) coarsens each channel to 32 levels, so tiny
        // pixel-value jitter from GD re-encoding the same image between the seal's two
        // render passes doesn't flip the hash — only a real visual change should
        for ($ty = 0; $ty < 8; $ty++) {
            for ($tx = 0; $tx < 8; $tx++) {
                $c       = imagecolorat($thumb, $tx, $ty);
                $pixels .= chr((($c >> 16) & 0xFF) & ~7)
                         . chr((($c >> 8)  & 0xFF) & ~7)
                         . chr(($c         & 0xFF) & ~7);
            }
        }
        return hash('sha256', $pixels);
    }
}
