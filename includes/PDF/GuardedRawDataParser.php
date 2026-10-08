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

use Smalot\PdfParser\Config;
use Smalot\PdfParser\RawData\RawDataParser;

/**
 * Unpacks only what mPDF writes, within a total allowance, records every stream it leaves packed, and holds the
 * cross-reference data pdfparser reads to the object ceiling.
 *
 * mPDF writes each stream as single FlateDecode or DCTDecode (JPEG). Any other filter (LZW, RunLength, ASCII85,
 * chains) can't come from this plugin and may expand far beyond the file, so it stays packed and is recorded. Flate
 * streams are unpacked only within the allowance the check reserved memory for.
 *
 * pdfparser builds one object per cross-reference entry, and a few bytes of table can name an object thousands of
 * times. Entries are counted as pdfparser reads them, and past PdfUtils::MAX_OBJECTS the file is refused.
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
        self::assertGuardedMethodsExist();
        parent::__construct($cfg, $config);
        $this->guardConfig = $config;
        $this->allowance   = max(0, $allowance);
    }

    /**
     * pdfparser's protected methods this class overrides. A pdfparser update that renamed one would silently switch
     * the guard off; a changed signature already fails when the class loads.
     *
     * @var string[]
     */
    public const GUARDED_METHODS = ['getIndirectObject', 'decodeStream', 'getXrefData', 'decodeXref'];

    /**
     * Most cross-reference sections followed. This plugin writes one; pdfparser searches the file for "startxref" at
     * every section, so a long chain costs its length times the file.
     *
     * @var int
     */
    public const MAX_XREF_SECTIONS = 64;

    /**
     * Sections entered so far (getXrefData() calls, the /Prev chain included).
     *
     * @var int
     */
    private int $xrefSections = 0;

    /**
     * Cross-reference entries counted so far, across every section.
     *
     * @var int
     */
    private int $xrefEntries = 0;

    /**
     * Whether pdfparser is reading the cross-reference chain: an xref stream decoded then adds its rows as entries.
     *
     * @var bool
     */
    private bool $readingXref = false;

    /**
     * Refuses to parse unguarded when pdfparser no longer has the methods the guard hooks.
     *
     * @return void
     * @throws \RuntimeException When one is gone.
     */
    private static function assertGuardedMethodsExist(): void
    {
        foreach (self::GUARDED_METHODS as $method) {
            if (!method_exists(RawDataParser::class, $method)) {
                \FabricatorForms\fabricator_log('FabricatorForms GuardedRawDataParser: pdfparser has no ' . $method . '() any more; the decompression guard would be off, so nothing is parsed.');
                throw new \RuntimeException('FabricatorForms GuardedRawDataParser: the PDF parser changed; verification is unavailable.');
            }
        }
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
     * Counts each cross-reference section pdfparser enters and refuses a chain longer than MAX_XREF_SECTIONS.
     *
     * @param string     $pdfData        Raw PDF bytes.
     * @param int        $offset         Where the section is looked for (0: from the last startxref).
     * @param array      $xref           Entries read so far.
     * @param array<int> $visitedOffsets Sections already read.
     * @return array
     * @throws \LengthException Past the section ceiling or the object ceiling.
     */
    protected function getXrefData(string $pdfData, int $offset = 0, array $xref = [], array $visitedOffsets = []): array
    {
        if (++$this->xrefSections > self::MAX_XREF_SECTIONS) {
            \FabricatorForms\fabricator_log('FabricatorForms GuardedRawDataParser: more than ' . self::MAX_XREF_SECTIONS . ' cross-reference sections; refused.');
            throw new \LengthException('Too many objects: cross-reference sections.');
        }
        $outer             = !$this->readingXref;
        $this->readingXref = true;
        try {
            return parent::getXrefData($pdfData, $offset, $xref, $visitedOffsets);
        } finally {
            if ($outer) {
                $this->readingXref = false;
            }
        }
    }

    /**
     * Counts a cross-reference table's lines before pdfparser reads them, the same lines its loop reads (subsection
     * headers included), so the count is never below the entries it builds.
     *
     * @param string     $pdfData        Raw PDF bytes.
     * @param int        $startxref      Where the table's "xref" keyword is.
     * @param array      $xref           Entries read so far.
     * @param array<int> $visitedOffsets Sections already read.
     * @return array
     * @throws \LengthException Past the object ceiling.
     */
    protected function decodeXref(string $pdfData, int $startxref, array $xref = [], array $visitedOffsets = []): array
    {
        $offset = $startxref + 4 + strspn($pdfData, $this->guardConfig->getPdfWhitespaces(), $startxref + 4);
        while (preg_match('/([0-9]+)[\x20]([0-9]+)[\x20]?([nf]?)(\r\n|[\x20]?[\r\n])/A', $pdfData, $m, 0, $offset) === 1) {
            $offset += strlen($m[0]);
            $this->countXrefEntries(1);
        }
        return parent::decodeXref($pdfData, $startxref, $xref, $visitedOffsets);
    }

    /**
     * Adds entries to the running count, refusing the file once it passes PdfUtils::MAX_OBJECTS.
     *
     * @param int $entries Entries just read.
     * @return void
     * @throws \LengthException Past the object ceiling.
     */
    private function countXrefEntries(int $entries): void
    {
        $this->xrefEntries += $entries;
        if ($this->xrefEntries > PdfUtils::MAX_OBJECTS) {
            \FabricatorForms\fabricator_log('FabricatorForms GuardedRawDataParser: more than ' . PdfUtils::MAX_OBJECTS . ' cross-reference entries; refused.');
            throw new \LengthException('Too many objects: cross-reference entries.');
        }
    }

    /**
     * The rows pdfparser makes of an unpacked cross-reference stream, one entry each: rows of /W bytes, or of
     * /Columns + 1 bytes under a predictor, the last one possibly short; none when the stream is no /Type /XRef.
     *
     * @param array $sdic     The stream's dictionary tokens.
     * @param int   $unpacked Unpacked length in bytes.
     * @return int
     */
    private static function xrefStreamRows(array $sdic, int $unpacked): int
    {
        $is_xref   = false;
        $width     = 0;
        $columns   = 0;
        $predictor = false;
        foreach ($sdic as $k => $token) {
            $next = $sdic[$k + 1] ?? null;
            if (($token[0] ?? '') !== '/' || !is_array($next)) {
                continue;
            }
            $key = (string) ($token[1] ?? '');
            if ($key === 'Type' && ($next[0] ?? '') === '/' && ($next[1] ?? '') === 'XRef') {
                $is_xref = true;
            } elseif ($key === 'W' && is_array($next[1] ?? null)) {
                // Summed as pdfparser sums them, signs included: a row is as short as it reads it.
                $width = 0;
                foreach (array_slice($next[1], 0, 3) as $w) {
                    $width += (int) ($w[1] ?? 0);
                }
            } elseif ($key === 'DecodeParms' && is_array($next[1] ?? null)) {
                foreach ($next[1] as $j => $param) {
                    $value = $next[1][$j + 1] ?? null;
                    if (($param[0] ?? '') !== '/' || ($value[0] ?? '') !== 'numeric') {
                        continue;
                    }
                    if (($param[1] ?? '') === 'Columns') {
                        $columns = (int) $value[1];
                    } elseif (($param[1] ?? '') === 'Predictor') {
                        $predictor = true;
                    }
                }
            }
        }
        $row = $predictor ? $columns + 1 : $width;
        return $is_xref && $row > 0 ? intdiv($unpacked + $row - 1, $row) : 0;
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
            $decoded = parent::decodeStream($pdfData, $xref, $sdic, $stream);
            $this->countXrefStream($sdic, $decoded[0] ?? '');
            return $decoded;
        }
        if ($filters !== ['FlateDecode']) {
            $this->refuse($object, $filters, 'filter', $sdic);
            return ['', []];
        }

        // Unpacked here, so a stream that is too large is named; pdfparser's own limit gives up quietly.
        $limit     = min(PdfUtils::INFLATE_STREAM_CAP, $this->allowance);
        $unpacked  = PdfUtils::inflateWithin($stream, $limit);
        $remaining = [];
        if ($unpacked === false) {
            // Not plain zlib: pdfparser's fallbacks, within the limit (+1 tells "too large" from "fits exactly").
            $this->guardConfig->setDecodeMemoryLimit($limit + 1);
            [$unpacked, $remaining] = parent::decodeStream($pdfData, $xref, $sdic, $stream);
        }
        if ($unpacked === null || strlen($unpacked) > $limit) {
            $this->refuse($object, $filters, 'size', $sdic);
            return ['', []];
        }
        $this->allowance -= strlen($unpacked);
        $this->countXrefStream($sdic, $unpacked);
        return [$unpacked, $remaining];
    }

    /**
     * While pdfparser reads the cross-reference chain, counts an xref stream's rows as entries before it builds them.
     *
     * @param array $sdic     The stream's dictionary tokens.
     * @param mixed $unpacked The unpacked bytes.
     * @return void
     * @throws \LengthException Past the object ceiling.
     */
    private function countXrefStream(array $sdic, mixed $unpacked): void
    {
        if ($this->readingXref && is_string($unpacked)) {
            $this->countXrefEntries(self::xrefStreamRows($sdic, strlen($unpacked)));
        }
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
