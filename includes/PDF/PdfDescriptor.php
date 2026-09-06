<?php

/**
 * Fluent builder that field classes use to describe their PDF output.
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

// Fluent builder for field PDF output: BaseField::pdf($field)->attachImage(...)->build(), consumed by Generator.
class PdfDescriptor
{
    /**
     * Cell HTML content.
     *
     * @var string
     */
    private $cellHtml;

    /**
     * Whether to render with a label row.
     *
     * @var bool
     */
    private $labeled = true;

    /**
     * Keyed binary image data for mPDF imageVars.
     *
     * @var array
     */
    private $imageVars = [];

    /**
     * File descriptors for the HMAC seal.
     *
     * @var array
     */
    private $sealedUploads = [];

    /**
     * Whether rawHtml() was called with $trusted = true, so Generator.php skips re-narrowing to the default allowlist.
     *
     * @var bool
     */
    private $trustedRichHtml = false;

    /**
     * @param string $defaultCellHtml Escaped value text — the starting cell content.
     */
    public function __construct(string $defaultCellHtml)
    {
        $this->cellHtml = $defaultCellHtml;
    }

    /**
     * Replaces the cell text with an already-escaped string.
     *
     * @param string $escaped Pre-escaped HTML or empty string.
     */
    public function text(string $escaped): static
    {
        $this->cellHtml = $escaped;
        return $this;
    }

    /**
     * Sets the cell content to raw HTML; callers must opt in via $trusted = true to skip wp_kses_post().
     *
     * @param string $html    HTML string.
     * @param bool   $trusted Pass true only when $html is already known-safe (e.g. pre-sanitized by the
     *                        field's own kses pass).
     */
    public function rawHtml(string $html, bool $trusted = false): static
    {
        $this->cellHtml = $trusted ? $html : wp_kses_post($html);
        $this->trustedRichHtml = $trusted;
        return $this;
    }

    /**
     * Renders without a label row — just a plain block.
     *
     * @return static
     */
    public function unlabeled(): static
    {
        $this->labeled = false;
        return $this;
    }

    /**
     * Embeds an image in the PDF and records its fingerprint in the seal. TIFF images are converted to PNG
     * first — mPDF does not support TIFF natively.
     *
     * @param string $binary   Raw binary image data.
     * @param string $filename Display name used in the seal.
     * @param string $mime     MIME type (default 'image/png').
     * @return static
     */
    public function attachImage(
        string $binary,
        string $filename,
        string $mime = 'image/png'
    ): static {
        if ($mime === 'image/tiff' && PdfUtils::precheckDimensions($binary)) {
            $gd = @imagecreatefromstring($binary);
            if ($gd !== false) {
                // Backstop for the rare case getimagesizefromstring() couldn't parse
                // the TIFF header but GD could still decode it.
                if (imagesx($gd) * imagesy($gd) > PdfUtils::maxSafePixels()) {
                    imagedestroy($gd);
                } else {
                    ob_start();
                    imagepng($gd);
                    $binary   = (string) ob_get_clean();
                    $mime     = 'image/png';
                    $filename = preg_replace('/\.tiff?$/i', '.png', $filename);
                }
            }
        }

        $key = 'img' . bin2hex(random_bytes(8));
        $this->imageVars[$key] = $binary;
        $this->sealedUploads[] = [
            'name'   => $filename,
            'mime'   => $mime,
            'sha256' => PdfUtils::thumbnailHash($binary) ?? hash('sha256', $binary),
        ];
        return $this;
    }

    /**
     * Returns the array that Generator::generate() consumes.
     *
     * @return array PDF render descriptor.
     */
    public function build(): array
    {
        return [
            'cell_html'         => $this->cellHtml,
            'labeled'           => $this->labeled,
            'image_vars'        => $this->imageVars,
            'sealed_uploads'    => $this->sealedUploads,
            'trusted_rich_html' => $this->trustedRichHtml,
        ];
    }
}
