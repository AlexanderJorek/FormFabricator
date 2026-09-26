<?php

/**
 * pdfparser's raw reader, limited to the compression FormFabricator's PDFs use.
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

use Smalot\PdfParser\Config;
use Smalot\PdfParser\RawData\RawDataParser;

/**
 * Unpacks only what mPDF writes, within a total allowance, and records every stream it leaves packed.
 *
 * mPDF writes each stream as single FlateDecode, or as DCTDecode (JPEG), which pdfparser leaves as it is. pdfparser
 * would also unpack LZW (no size limit, and quadratic time), RunLength, ASCII85/ASCIIHex and chains of filters, which
 * expand far beyond the file. A stream like that can't come from this plugin, so it stays packed and is recorded, and
 * the check goes on without it. Flate streams are unpacked only within the allowance the check reserved memory for.
 */
class GuardedRawDataParser extends RawDataParser
{
    /**
     * Most refused streams recorded one by one; the rest are only counted.
     *
     * @var int
     */
    private const MAX_RECORDED = 50;

    /**
     * The Config the parent reads its per-stream decode limit from, shared with this class.
     *
     * @var Config
     */
    private Config $guardConfig;

    /**
     * Bytes still allowed to be unpacked.
     *
     * @var int
     */
    private int $allowance;

    /**
     * References of the objects being read, innermost last.
     *
     * @var string[]
     */
    private array $objectStack = [];

    /**
     * Streams left packed, as refused().
     *
     * @var array<int, array{object: string, filters: string[], reason: string, type: string, subtype: string, width: int, height: int}>
     */
    private array $refused = [];

    /**
     * Refused streams beyond MAX_RECORDED.
     *
     * @var int
     */
    private int $unrecorded = 0;

    /**
     * Constructor.
     *
     * @param array  $cfg       pdfparser's raw-reader options.
     * @param Config $config    pdfparser configuration, shared with the Parser.
     * @param int    $allowance Most bytes all streams together may unpack to.
     */
    public function __construct(array $cfg, Config $config, int $allowance)
    {
        parent::__construct($cfg, $config);
        $this->guardConfig = $config;
        $this->allowance   = max(0, $allowance);
    }

    /**
     * The streams left packed so far: which object, its filters, why ('filter' or 'size'), and what the object is.
     *
     * @return array<int, array{object: string, filters: string[], reason: string, type: string, subtype: string, width: int, height: int}>
     */
    public function refused(): array
    {
        return $this->refused;
    }

    /**
     * Refused streams not listed in refused().
     *
     * @return int
     */
    public function unrecordedCount(): int
    {
        return $this->unrecorded;
    }

    /**
     * Remembers which object is being read, so a refused stream can be named.
     *
     * @param string $pdfData  Raw PDF bytes.
     * @param array  $xref     Cross-reference data.
     * @param string $objRef   Object reference, as "number_generation".
     * @param int    $offset   Where the object starts.
     * @param bool   $decoding Whether streams are unpacked.
     * @return array
     */
    protected function getIndirectObject(string $pdfData, array $xref, string $objRef, int $offset = 0, bool $decoding = true): array
    {
        $this->objectStack[] = $objRef;
        try {
            return parent::getIndirectObject($pdfData, $xref, $objRef, $offset, $decoding);
        } finally {
            array_pop($this->objectStack);
        }
    }

    /**
     * Unpacks a stream only when it is packed the way mPDF packs, and only within the allowance.
     *
     * @param string $pdfData Raw PDF bytes.
     * @param array  $xref    Cross-reference data.
     * @param array  $sdic    The stream's dictionary, as pdfparser tokens.
     * @param string $stream  The packed stream bytes.
     * @return array{0: string, 1: array} Unpacked bytes, and the filters left to apply.
     */
    protected function decodeStream(string $pdfData, array $xref, array $sdic, string $stream): array
    {
        $object  = (string) end($this->objectStack);
        $filters = $this->filtersOf($pdfData, $xref, $sdic);
        if ($filters === [] || $filters === ['DCTDecode']) {
            return parent::decodeStream($pdfData, $xref, $sdic, $stream);
        }
        if ($filters !== ['FlateDecode']) {
            $this->refuse($object, $filters, 'filter', $sdic);
            return ['', []];
        }

        // Unpacked here within the limit, so a stream that is too large is recognised and named: pdfparser's own
        // limited unpacking gives up quietly and leaves such a stream packed.
        $limit     = min(PdfUtils::INFLATE_STREAM_CAP, $this->allowance);
        $unpacked  = PdfUtils::inflateWithin($stream, $limit);
        $remaining = [];
        if ($unpacked === false) {
            // Not plain zlib data: pdfparser's fallbacks, still within the limit (one byte above it tells a stream that
            // is too large from one that fits exactly).
            $this->guardConfig->setDecodeMemoryLimit($limit + 1);
            [$unpacked, $remaining] = parent::decodeStream($pdfData, $xref, $sdic, $stream);
        }
        if ($unpacked === null || strlen($unpacked) > $limit) {
            $this->refuse($object, $filters, 'size', $sdic);
            return ['', []];
        }
        $this->allowance -= strlen($unpacked);
        return [$unpacked, $remaining];
    }

    /**
     * The stream's filter names, as pdfparser reads them; anything in the filter entry that isn't a name becomes '?'.
     *
     * @param string $pdfData Raw PDF bytes.
     * @param array  $xref    Cross-reference data.
     * @param array  $sdic    The stream's dictionary tokens.
     * @return string[]
     */
    private function filtersOf(string $pdfData, array $xref, array $sdic): array
    {
        $filters = [];
        foreach ($sdic as $k => $token) {
            if (($token[0] ?? '') !== '/' || ($token[1] ?? '') !== 'Filter' || !isset($sdic[$k + 1])) {
                continue;
            }
            $value = $this->getObjectVal($pdfData, $xref, $sdic[$k + 1]);
            if (($value[0] ?? '') === '/') {
                $filters[] = (string) $value[1];
            } elseif (($value[0] ?? '') === '[') {
                foreach ((array) ($value[1] ?? []) as $item) {
                    $filters[] = ($item[0] ?? '') === '/' ? (string) $item[1] : '?';
                }
            } else {
                $filters[] = '?';
            }
        }
        return $filters;
    }

    /**
     * Records a stream left packed, with what its dictionary says the object is.
     *
     * @param string   $object  Object reference, as "number_generation".
     * @param string[] $filters Its filters.
     * @param string   $reason  'filter' for a packing mPDF never writes, 'size' for one beyond the allowance.
     * @param array    $sdic    The stream's dictionary tokens.
     * @return void
     */
    private function refuse(string $object, array $filters, string $reason, array $sdic): void
    {
        if (count($this->refused) >= self::MAX_RECORDED) {
            $this->unrecorded++;
            return;
        }
        $entry = [
            'object'  => (string) strtok($object, '_'),
            'filters' => $filters,
            'reason'  => $reason,
            'type'    => '',
            'subtype' => '',
            'width'   => 0,
            'height'  => 0,
        ];
        foreach ($sdic as $k => $token) {
            $next = $sdic[$k + 1] ?? null;
            if (($token[0] ?? '') !== '/' || !is_array($next)) {
                continue;
            }
            $key = strtolower((string) ($token[1] ?? ''));
            if (($key === 'type' || $key === 'subtype') && ($next[0] ?? '') === '/') {
                $entry[$key] = (string) $next[1];
            } elseif (($key === 'width' || $key === 'height') && ($next[0] ?? '') === 'numeric') {
                $entry[$key] = (int) $next[1];
            }
        }
        $this->refused[] = $entry;
    }
}
