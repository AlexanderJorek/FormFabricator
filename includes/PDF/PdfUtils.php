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
 * @version   1.0.8
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
     * Absolute pixel-count ceiling regardless of memory_limit, guarding an unlimited (-1) host.
     *
     * @var int
     */
    private const HARD_PIXEL_CEILING = 200_000_000;

    /**
     * Floor for the adaptive cap, so a modest ~1 MP image is never refused even under a constrained memory_limit.
     *
     * @var int
     */
    private const MIN_SAFE_PIXELS = 1_000_000;

    /**
     * A per-decode pixel-count ceiling scaled to memory_limit (half reserved for decoding).
     *
     * @return int Maximum total pixel count (width * height) considered safe to decode.
     */
    public static function maxSafePixels(): int
    {
        return self::safePixelsFor(\FabricatorForms\Utils\MemoryBudget::phpMemoryLimitBytes());
    }

    /**
     * The memory limit under which maxSafePixels() admits an image of $pixels; the inverse of safePixelsFor().
     *
     * @param int $pixels Pixel count.
     * @return int Bytes.
     */
    public static function memoryLimitFor(int $pixels): int
    {
        return max(0, $pixels) * 2 * self::DECODE_BYTES_PER_PIXEL;
    }

    /**
     * Bytes decoding one pixel is assumed to take: four channels plus half again for working copies.
     *
     * @var int
     */
    public const DECODE_BYTES_PER_PIXEL = 6;

    /**
     * maxSafePixels() under a given memory limit, so a caller can predict it for a limit not yet in place.
     *
     * @param int $limit Memory limit in bytes; -1 or 0 when there is none.
     * @return int Maximum total pixel count (width * height) considered safe to decode.
     */
    public static function safePixelsFor(int $limit): int
    {
        if ($limit <= 0) {
            return self::HARD_PIXEL_CEILING;
        }
        // Half of the limit is left for decoding.
        $cap = intdiv($limit, 2 * self::DECODE_BYTES_PER_PIXEL);
        return max(self::MIN_SAFE_PIXELS, min($cap, self::HARD_PIXEL_CEILING));
    }

    /**
     * The largest image, in pixels, a submission's PDF takes whatever else is uploaded: within the PDF step's own memory
     * limit, and within the budget next to 1 MB of files.
     *
     * @return int Pixels.
     */
    public static function imagePixelLimit(): int
    {
        $own    = \FabricatorForms\Utils\MemoryBudget::canRaiseLimit()
            ? self::safePixelsFor(-1)
            : self::maxSafePixels();
        $budget = \FabricatorForms\Utils\MemoryBudget::budgetBytes();
        $files  = \FabricatorForms\Utils\MemoryBudget::estimateBytes(1024 * 1024);
        return min(
            $own,
            intdiv($budget, self::memoryLimitFor(1)),
            intdiv(max(0, $budget - $files), self::DECODE_BYTES_PER_PIXEL)
        );
    }

    /**
     * An image file's pixel count, read from its header without decoding it; 0 when the size can't be read.
     *
     * @param string $path Image file.
     * @return int Pixels.
     */
    public static function filePixels(string $path): int
    {
        if ($path === '') {
            return 0;
        }
        $size = \FabricatorForms\Utils\Cast::withoutWarnings(static fn() => wp_getimagesize($path));
        if (!is_array($size) || (int) ($size[0] ?? 0) <= 0 || (int) ($size[1] ?? 0) <= 0) {
            return 0;
        }
        return (int) $size[0] * (int) $size[1];
    }

    /**
     * Whether a PDF layout image is at most $limit pixels by its header. Fails closed on an unreadable raster size; SVG
     * always fits.
     *
     * @param string $path  Image file.
     * @param int    $limit Pixels.
     * @return bool
     */
    public static function layoutImageFits(string $path, int $limit): bool
    {
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'svg') {
            return true;
        }
        $pixels = self::filePixels($path);
        return $pixels > 0 && $pixels <= $limit;
    }

    /**
     * The files of the PDF layout's images (logo, header images): Media Library attachments only, never a URL.
     *
     * @param array $layout The fabricator_forms_pdf_layout option.
     * @return string[] File paths.
     */
    public static function layoutImagePaths(array $layout): array
    {
        $urls = [is_string($layout['logo_url'] ?? null) ? $layout['logo_url'] : ''];
        $elements = $layout['header_layout']['elements'] ?? [];
        foreach (is_array($elements) ? $elements : [] as $el) {
            if (is_array($el) && ($el['type'] ?? '') === 'image' && is_string($el['src'] ?? null)) {
                $urls[] = $el['src'];
            }
        }
        $paths = [];
        foreach ($urls as $url) {
            $id   = $url !== '' ? attachment_url_to_postid($url) : 0;
            $path = $id ? (string) (get_attached_file($id) ?: '') : '';
            if ($path !== '') {
                $paths[] = $path;
            }
        }
        return array_values(array_unique($paths));
    }

    /**
     * Cheaply checks an image's header dimensions against the safe-pixel cap before GD ever decodes it.
     *
     * Fails closed: this is the last guard before a decompression bomb is decoded. An unreadable image is not embedded.
     *
     * @param string $binary Raw binary image data.
     * @return bool True only if the image's declared dimensions are known and within the safe cap.
     */
    public static function precheckDimensions(string $binary): bool
    {
        $size = \FabricatorForms\Utils\Cast::withoutWarnings(static fn() => getimagesizefromstring($binary));
        if ($size === false || !isset($size[0], $size[1]) || (int) $size[0] <= 0 || (int) $size[1] <= 0) {
            return false;
        }
        return ((int) $size[0] * (int) $size[1]) <= self::maxSafePixels();
    }

    /**
     * A fingerprint of the seal page's text. Shared by generator and verifier, so both derive it from the same bytes alike.
     *
     * @param string $pdf_raw Raw PDF bytes.
     * @return string SHA-256 of the seal page's visible text, or '' when it cannot be resolved.
     */
    public static function sealPageTextFingerprint(string $pdf_raw): string
    {
        // From the catalog, by each object's LAST definition, so a decoy copy can't supply content a viewer never shows.
        $objects = self::indexObjects($pdf_raw);
        $pages   = self::pageContents($pdf_raw, $objects);
        if ($pages === []) {
            return self::sealPageUnavailable('page tree could not be resolved from the catalog');
        }
        // /Contents may be a single ref or an array.
        $contents_objs = end($pages);
        if ($contents_objs === []) {
            return self::sealPageUnavailable('last page has no resolvable /Contents');
        }

        $combined = '';
        foreach ($contents_objs as $contents_obj) {
            $stream = $objects[$contents_obj]['stream'] ?? null;
            if ($stream === null) {
                return self::sealPageUnavailable('page content object not found or has no stream');
            }
            // Same 64MB ceiling as the other stream walkers, applied while inflating.
            $dec = self::inflateWithin($stream);
            if (!is_string($dec)) {
                return self::sealPageUnavailable('page content stream could not be inflated');
            }
            // Newline join, matching how a viewer concatenates multiple /Contents streams.
            $combined .= ($combined === '' ? '' : chr(10)) . $dec;
        }

        return self::pageTextFingerprint($combined);
    }

    // The seal marker as plain text, and the seal-opening glyph run as mPDF writes it (two-byte codes, high byte zero).
    private const SEAL_BEGIN = '---BEGIN-SEAL---';
    private const SEAL_END   = '---END-SEAL---';

    // One line of mPDF's seal text: colour, optional render mode, a single positioned Tj. Nothing else fits.
    private const SEAL_LINE_RE = '/^q\s+(?:[0-9.]+\s+){1,4}(?:rg|g|k)\s+(?:[0-9]+\s+Tr\s+)?BT\s+[-0-9.]+\s+[-0-9.]+\s+Td\s+\((.*)\)\s*Tj\s+ET\s+Q\s*$/s';

    // Operators that change graphics or text state but paint nothing: font selection without text, ExtGState,
    // colour, line width, render mode, dash pattern. The only lines allowed to surround the seal text.
    private const STATE_LINE_RE = '#^(?:BT\s+/[A-Za-z0-9_.+-]+\s+[0-9.]+\s+Tf\s+ET|/[A-Za-z0-9_.+-]+\s+gs|[0-9.]+\s+(?:g|G|w|Tr)|(?:[0-9.]+\s+){3}(?:rg|RG)|(?:[0-9.]+\s+){4}(?:k|K)|\[[0-9.\s]*\]\s+[0-9.]+\s+d)?$#';

    /**
     * Most objects a PDF may declare for the verifier to read it.
     *
     * Every object index costs a fixed amount per object, so a file of tiny objects costs many times its size and ends
     * in a memory fatal instead of a refusal. A PDF from this plugin holds a few thousand objects at most.
     *
     * @var int
     */
    public const MAX_OBJECTS = 50000;

    /**
     * How many object headers raw PDF bytes declare outside image data, counted without building anything.
     *
     * Counts "obj" after each byte PCRE's \s matches, so every header the object scans match counts and "endobj" never
     * does. Image data is skipped, so an uploaded image can't get a genuine PDF refused. pdfparser's objects are
     * capped separately, by GuardedRawDataParser.
     *
     * @param string $raw Raw PDF bytes.
     * @return int
     */
    public static function declaredObjectCount(string $raw): int
    {
        $needles = [' obj', "\nobj", "\robj", "\tobj", "\x0Bobj", "\x0Cobj"];
        $count   = 0;
        $cursor  = 0;
        $between = static function (int $from, int $to) use ($raw, $needles, &$count): void {
            foreach ($needles as $needle) {
                $count += substr_count($raw, $needle, $from, $to - $from);
            }
        };
        // One walk over the image spans, in file order; each stretch between them is counted once.
        foreach (self::imageStreamSpans($raw) as [$start, $end]) {
            if ($start > $cursor) {
                $between($cursor, $start);
            }
            $cursor = max($cursor, $end);
        }
        if ($cursor < strlen($raw)) {
            $between($cursor, strlen($raw));
        }
        return $count;
    }

    /**
     * Indexes every object by number, keeping the LAST definition of each: the one a viewer resolves after an
     * incremental update, so a decoy earlier copy is never what a check reads.
     *
     * @param string $pdf_raw Raw PDF bytes.
     * @return array Object number => ['dict' => dictionary text, 'stream' => raw stream bytes or null].
     */
    public static function indexObjects(string $pdf_raw): array
    {
        $objects = [];
        // One forward pass, never a preg_match_all() over the whole file, whose match array would outgrow the budget.
        $pos = 0;
        // Latched once no "endstream" remains ahead, so unclosed streams don't each re-scan the tail.
        $endstream_exhausted = false;
        $seen                = 0;
        $header              = '/(?:^|[^0-9])(\d+)\s+0\s+obj\b/';
        $cache               = [];
        // Headers inside image data are no objects (finderOutsideImageData()).
        $find_header         = self::finderOutsideImageData($pdf_raw, $header);
        $find_start          = self::finderOutsideImageData($pdf_raw, self::OBJECT_START);
        $has                 = $find_header($pos, $m);
        while ($has) {
            // Backstop for MAX_OBJECTS, whoever calls this.
            if (++$seen > self::MAX_OBJECTS) {
                throw new \LengthException('Too many objects in one PDF.');
            }
            $start = $m[0][1] + strlen($m[0][0]);
            // The object ends at its "endobj" or the next header, whichever comes first, so copied spans never overlap
            // (nested headers sharing one "endobj" would otherwise be quadratic in time and memory).
            $has_next = $find_header($start, $next);
            $end      = self::nextAt($pdf_raw, 'endobj', $start, $cache);
            if ($end === false) {
                break; // Nothing closes this object, so nothing closes any later one either.
            }
            // Any generation, as objectBodyEnd(). The stream is read from the whole file below, so it isn't cut short.
            if ($find_start($start, $cut) && $cut[0][1] < $end) {
                $end = $cut[0][1];
            }
            $body   = substr($pdf_raw, $start, $end - $start);
            $record = ['dict' => $body, 'stream' => null];
            // From "stream" to the next "endstream", not the first "endobj", which compressed bytes can contain. The
            // keyword is looked for in this object's bytes only, or every stream-less object would scan to the end.
            $has_marker = !$endstream_exhausted
                && preg_match('/>>\s*stream(\r\n|\n|\r)/', $body, $sm, PREG_OFFSET_CAPTURE) === 1;
            if ($has_marker) {
                $marker_at  = $start + $sm[0][1];
                $data_start = $marker_at + strlen($sm[0][0]);
                $data_end   = self::nextAt($pdf_raw, 'endstream', $data_start, $cache);
                if ($data_end === false) {
                    $endstream_exhausted = true;
                } else {
                    $record['dict']   = substr($pdf_raw, $start, $sm[0][1] + 2);
                    $record['stream'] = substr($pdf_raw, $data_start, $data_end - $data_start);
                }
            }
            $objects[(int) $m[1][0]] = $record;
            $has = $has_next;
            $m   = $has_next ? $next : $m;
        }
        return $objects;
    }

    /**
     * strpos() for a forward walk: the last answer is reused while it lies ahead, and "none" holds for every later
     * offset, so the walk reads each byte about once instead of being quadratic. Always returns exactly what strpos()
     * would; a backward offset searches afresh.
     *
     * @param string              $haystack Bytes to search.
     * @param string              $needle   Non-empty needle.
     * @param int                 $offset   Where to start.
     * @param array<string,array> $cache    The walk's own cache, one per haystack; start with [].
     * @return int|false
     */
    public static function nextAt(string $haystack, string $needle, int $offset, array &$cache): int|false
    {
        if (isset($cache[$needle])) {
            [$from, $found] = $cache[$needle];
            if ($offset >= $from && ($found === false || $found >= $offset)) {
                return $found;
            }
        }
        $found           = strpos($haystack, $needle, max(0, $offset));
        $cache[$needle] = [$offset, $found];
        return $found;
    }

    /**
     * Every object's last definition, indexed in one forward pass: exactly what lastObjectDefinition() would return,
     * which definitionFromIndex() then answers in O(1). A whole-file search per reference costs "references × file
     * size", and the uploader picks the number of references.
     *
     * @param string $pdf_raw Raw PDF bytes.
     * @return array{by_ref: array<string, array{0: string, 1: int, 2: int}>, by_num: array<string, array{0: string, 1: int, 2: int}>}
     *               Keyed "number generation" and "number", both without leading zeros: header text, body start, body end.
     */
    public static function objectDefinitionIndex(string $pdf_raw): array
    {
        $by_ref      = [];
        $by_num      = [];
        $cache       = [];
        $after_cache = []; // "endobj" after a stream: these offsets follow the stream ends, not the headers
        $pos         = 0;
        $seen        = 0;
        // The stream marker's own forward cursor, as nextAt() but by regex.
        $marker_from  = -1;
        $marker_found = null;
        // Headers inside image data are no objects (finderOutsideImageData()).
        $find_header  = self::finderOutsideImageData($pdf_raw, '/(?<![0-9])(\d+)\s+(\d+)\s+obj\b/');
        $find_start   = self::finderOutsideImageData($pdf_raw, self::OBJECT_START);
        while ($find_header($pos, $m)) {
            if (++$seen > self::MAX_OBJECTS) {
                throw new \LengthException('Too many objects in one PDF.');
            }
            $start = $m[0][1] + strlen($m[0][0]);
            $pos   = $start;

            // objectBodyEnd(), step for step, with each search taken from a forward cursor.
            $end = self::nextAt($pdf_raw, 'endobj', $start, $cache);
            if ($end === false) {
                continue; // no body closes here; objectBodyEnd() returned null, so this header defines nothing
            }
            $next = $find_start($start, $nm) ? $nm[0][1] : null;
            if ($marker_from < 0 || ($marker_found !== false && $marker_found[0] < $start)) {
                $marker_found = preg_match('/>>\s*stream(\r\n|\n|\r)/', $pdf_raw, $sm, PREG_OFFSET_CAPTURE, $start) === 1
                    ? [$sm[0][1], $sm[0][1] + strlen($sm[0][0])]
                    : false;
                $marker_from  = $start;
            }
            $past_stream = false;
            if ($marker_found !== false && $marker_found[0] < $end && ($next === null || $marker_found[0] < $next)) {
                $data_end = self::nextAt($pdf_raw, 'endstream', $marker_found[1], $cache);
                $after    = $data_end === false ? false : self::nextAt($pdf_raw, 'endobj', $data_end + 9, $after_cache);
                if ($after !== false) {
                    $end         = $after;
                    $past_stream = true;
                }
            }
            if (!$past_stream && $next !== null && $next < $end) {
                $end = $next;
            }

            $num   = ltrim($m[1][0], '0') ?: '0';
            $gen   = ltrim($m[2][0], '0') ?: '0';
            $entry = [$m[0][0], $start, $end];
            $by_ref[$num . ' ' . $gen] = $entry;
            $by_num[$num]             = $entry;
        }
        return ['by_ref' => $by_ref, 'by_num' => $by_num];
    }

    /**
     * lastObjectDefinition()'s answer, read from objectDefinitionIndex() instead of searching the file again.
     *
     * @param array       $index   From objectDefinitionIndex() over these same bytes.
     * @param string      $pdf_raw The bytes the index was built from.
     * @param string      $num     Object number (digits).
     * @param string|null $gen     Generation number (digits), or null for any.
     * @return array{header: string, body: string}|null
     */
    public static function definitionFromIndex(array $index, string $pdf_raw, string $num, ?string $gen = null): ?array
    {
        if (!ctype_digit($num) || ($gen !== null && !ctype_digit($gen))) {
            return null;
        }
        $num   = ltrim($num, '0') ?: '0';
        $entry = $gen === null
            ? ($index['by_num'][$num] ?? null)
            : ($index['by_ref'][$num . ' ' . (ltrim($gen, '0') ?: '0')] ?? null);
        if ($entry === null) {
            return null;
        }
        return ['header' => $entry[0], 'body' => substr($pdf_raw, $entry[1], $entry[2] - $entry[1])];
    }

    /**
     * Finds the LAST definition of one object, for the verifier's single-object lookups.
     *
     * The number is digit-bounded (12 never matches "112 0 obj"); leading zeros are allowed, as a reader parses an int.
     *
     * @param string      $pdf_raw Bytes to search.
     * @param string      $num     Object number (digits).
     * @param string|null $gen     Generation number (digits), or null for any.
     * @return array{header: string, body: string}|null Header text ("N G obj") and everything after it up to, not
     *                                                  including, the closing "endobj".
     */
    public static function lastObjectDefinition(string $pdf_raw, string $num, ?string $gen = null): ?array
    {
        if (!ctype_digit($num) || ($gen !== null && !ctype_digit($gen))) {
            return null;
        }
        $gen_re  = $gen === null ? '\d+' : '0*' . (ltrim($gen, '0') ?: '0');
        $pattern = '/(?<![0-9])0*' . (ltrim($num, '0') ?: '0') . '\s+' . $gen_re . '\s+obj\b/';
        // Every header of this object outside image data (finderOutsideImageData()), in file order; the last that closes wins.
        $find    = self::finderOutsideImageData($pdf_raw, $pattern);
        $headers = [];
        $from    = 0;
        while ($find($from, $hm)) {
            $headers[] = $hm[0];
            $from      = $hm[0][1] + strlen($hm[0][0]);
        }
        for ($i = count($headers) - 1; $i >= 0; $i--) {
            $start = $headers[$i][1] + strlen($headers[$i][0]);
            $end   = self::objectBodyEnd($pdf_raw, $start);
            if ($end !== null) {
                return ['header' => $headers[$i][0], 'body' => substr($pdf_raw, $start, $end - $start)];
            }
        }
        return null;
    }

    /**
     * Reads one text entry (such as /Title) from a dictionary and decodes it: a literal string (escapes resolved,
     * nested parentheses balanced) or a hex string. UTF-16BE with a byte-order mark becomes UTF-8.
     *
     * Strings are skipped while looking for the key, so a "/Title" inside another value never matches; for a repeated
     * key the last one wins.
     *
     * @param string $dict_source Text starting with the dictionary ("<<", optionally after whitespace).
     * @param string $key         Key name without the slash.
     * @return string|null Null when the dictionary has no such string entry.
     */
    public static function dictTextEntry(string $dict_source, string $key): ?string
    {
        $len = strlen($dict_source);
        $i   = strspn($dict_source, self::PCRE_SPACE);
        if (substr($dict_source, $i, 2) !== '<<') {
            return null;
        }
        $i    += 2;
        $depth = 1;
        $found = null;
        while ($i < $len && $depth > 0) {
            $i += strcspn($dict_source, '()<>[]/%', $i);
            if ($i >= $len) {
                break;
            }
            $c = $dict_source[$i];
            if ($c === '(') {
                $i = self::skipLiteralString($dict_source, $i)[1];
            } elseif ($c === '<' && ($dict_source[$i + 1] ?? '') === '<') {
                $depth++;
                $i += 2;
            } elseif ($c === '<') {
                $close = strpos($dict_source, '>', $i);
                $i     = $close === false ? $len : $close + 1;
            } elseif ($c === '>') {
                $depth -= ($dict_source[$i + 1] ?? '') === '>' ? 1 : 0;
                $i     += ($dict_source[$i + 1] ?? '') === '>' ? 2 : 1;
            } elseif ($c === '[') {
                $depth++;
                $i++;
            } elseif ($c === ']') {
                $depth--;
                $i++;
            } elseif ($c === ')') {
                // Stray closing parenthesis outside any string: not valid, and nothing to read.
                $i++;
            } elseif ($c === '%') {
                // A comment runs to the end of the line.
                $i += strcspn($dict_source, "\r\n", $i);
            } else {
                $name_end = $i + 1 + strcspn($dict_source, self::PCRE_SPACE . '()<>[]{}/%', $i + 1);
                $name     = substr($dict_source, $i + 1, $name_end - $i - 1);
                $i        = $name_end;
                if ($depth !== 1 || $name !== $key) {
                    continue;
                }
                // In a valid dictionary a name followed by a string is a key, since a string can never be one.
                $v = $i + strspn($dict_source, self::PCRE_SPACE, $i);
                if (($dict_source[$v] ?? '') === '(') {
                    [$bytes, $i] = self::skipLiteralString($dict_source, $v);
                    $found       = self::decodeTextString($bytes);
                } elseif (($dict_source[$v] ?? '') === '<' && ($dict_source[$v + 1] ?? '') !== '<') {
                    $close = strpos($dict_source, '>', $v);
                    $hex   = preg_replace('/[^0-9A-Fa-f]/', '', substr($dict_source, $v + 1, ($close === false ? $len : $close) - $v - 1));
                    $hex  .= strlen($hex) % 2 === 1 ? '0' : '';
                    $found = self::decodeTextString((string) hex2bin($hex));
                    $i     = $close === false ? $len : $close + 1;
                }
            }
        }
        return $found;
    }

    /**
     * Parses a PDF literal string starting at the "(" at $start.
     *
     * @param string $source Bytes containing the string.
     * @param int    $start  Offset of the opening parenthesis.
     * @return array{0: string, 1: int} The string's bytes, and the offset just past its closing parenthesis.
     */
    private static function skipLiteralString(string $source, int $start): array
    {
        $len   = strlen($source);
        $depth = 1;
        $out   = '';
        $i     = $start + 1;
        while ($i < $len) {
            $run  = strcspn($source, "()\\\r", $i);
            $out .= substr($source, $i, $run);
            $i   += $run;
            if ($i >= $len) {
                break;
            }
            $c = $source[$i];
            if ($c === '(') {
                $depth++;
                $out .= '(';
                $i++;
            } elseif ($c === ')') {
                $i++;
                if (--$depth === 0) {
                    return [$out, $i];
                }
                $out .= ')';
            } elseif ($c === "\r") {
                // An unescaped end-of-line (CR or CR LF) reads as a single LF.
                $out .= "\n";
                $i   += ($source[$i + 1] ?? '') === "\n" ? 2 : 1;
            } else {
                $next = $source[$i + 1] ?? '';
                $i   += 2;
                if ($next >= '0' && $next <= '7' && $next !== '') {
                    $octal = $next;
                    while (strlen($octal) < 3 && isset($source[$i]) && $source[$i] >= '0' && $source[$i] <= '7') {
                        $octal .= $source[$i++];
                    }
                    $out .= chr(octdec($octal) & 0xFF);
                } elseif ($next === "\r") {
                    // Backslash before an end-of-line continues the string on the next line.
                    $i += ($source[$i] ?? '') === "\n" ? 1 : 0;
                } elseif ($next !== "\n") {
                    $out .= ['n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\f"][$next] ?? $next;
                }
            }
        }
        return [$out, $len];
    }

    /**
     * Turns PDF text-string bytes into UTF-8 when they carry a UTF-16BE byte-order mark.
     *
     * @param string $bytes Raw string bytes.
     * @return string
     */
    private static function decodeTextString(string $bytes): string
    {
        if (str_starts_with($bytes, "\xFE\xFF")) {
            $decoded = mb_convert_encoding(substr($bytes, 2), 'UTF-8', 'UTF-16BE');
            return $decoded !== false ? $decoded : $bytes;
        }
        return $bytes;
    }

    /**
     * Extracted PDF text as the verifier compares it (NFKC, entities decoded, ligatures expanded, whitespace folded).
     * reservedMarker() checks submitted text after this step too, so typed text can't become a marker here.
     *
     * @param string $s Text.
     * @return string
     */
    public static function normalizeText(string $s): string
    {
        if (class_exists('Normalizer')) {
            $n = \Normalizer::normalize($s, \Normalizer::NFKC);
            if ($n !== false) {
                $s = $n;
            }
        }
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Expand common OpenType ligatures substituted by mPDF (U+FB00–FB06)
        $lig_from = ["\xEF\xAC\x80", "\xEF\xAC\x81", "\xEF\xAC\x82", "\xEF\xAC\x83", "\xEF\xAC\x84", "\xEF\xAC\x85", "\xEF\xAC\x86"];
        $lig_to   = ['ff', 'fi', 'fl', 'ffi', 'ffl', 'st', 'st'];
        $s = str_replace($lig_from, $lig_to, $s);
        $s = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $s); // remove control chars
        $s = str_replace(["\xC2\xA0", "\xAD"], ' ', $s);        // NBSP + soft hyphen
        $s = (string) preg_replace('/\s+/u', ' ', $s);            // normalize whitespace
        $s = (string) preg_replace('/([,;])\s*/u', '$1 ', $s);    // one space after , and ;
        return trim($s);
    }

    /**
     * $s without the explicit bidi controls: the embeddings and overrides U+202A–U+202E and the isolates U+2066–U+2069.
     *
     * In mPDF's left-to-right paragraphs no bidi reordering can assemble a marker from Latin text, except through these
     * controls: after U+202E, a typed "[DNE_DLEIF_FDP_ROTACIRBAF]" renders as a field marker. The marks U+200E, U+200F
     * and U+061C stay (each acts as one strong character). Invalid UTF-8 is scrubbed first so no control hides in it.
     *
     * @param string $s Submitted text.
     * @return string
     */
    public static function stripBidiControls(string $s): string
    {
        if (!mb_check_encoding($s, 'UTF-8')) {
            $s = mb_scrub($s, 'UTF-8');
        }
        return (string) preg_replace('/[\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $s);
    }

    /**
     * The marker text in $text that the verifier reads as structure: a field marker ("[FABRICATOR_PDF_…") or a seal
     * delimiter. Typed into an answer, it would let a submitter make their own genuine PDF report "Modified". Checked
     * as typed and after normalizeText().
     *
     * @param string $text Submitted text.
     * @return string|null The marker found, or null.
     */
    public static function reservedMarker(string $text): ?string
    {
        foreach ([$text, self::normalizeText($text)] as $candidate) {
            foreach (['[FABRICATOR_PDF_', self::SEAL_BEGIN, self::SEAL_END] as $marker) {
                if (str_contains($candidate, $marker)) {
                    return $marker;
                }
            }
        }
        return null;
    }

    /**
     * The byte spans [start, end) of image stream data in raw PDF bytes, in file order.
     *
     * Image data is the uploader's own bytes and can hold "%%EOF", a seal delimiter or "/Type /…", so structure counts
     * skip these spans.
     *
     * A stream ends at its direct /Length when "endstream" follows there, else at the next "endstream". One forward
     * walk: each window read lies after the previous stream.
     *
     * @param string $raw Raw PDF bytes.
     * @return \Generator<int, array{0: int, 1: int}>
     */
    public static function imageStreamSpans(string $raw): \Generator
    {
        $len    = strlen($raw);
        $resume = 0;
        $cursor = 0;
        $cache  = [];
        while (($kw = self::nextAt($raw, 'stream', $cursor, $cache)) !== false) {
            $cursor = $kw + 6;
            // Only "stream" right after a dictionary's ">>" and before a line break opens a body.
            $before = rtrim(substr($raw, max($resume, $kw - 64), $kw - max($resume, $kw - 64)));
            if (!str_ends_with($before, '>>')) {
                continue;
            }
            $body_start = $kw + 6;
            if (($raw[$body_start] ?? '') === "\r") {
                $body_start++;
            }
            if (($raw[$body_start] ?? '') !== "\n") {
                continue;
            }
            $body_start++;

            // The dictionary: from the last "obj" after the previous stream up to this keyword.
            $window   = substr($raw, $resume, $kw - $resume);
            $obj_at   = strrpos($window, 'obj');
            $dict     = $obj_at === false ? $window : substr($window, $obj_at + 3);
            unset($window);
            $is_image = preg_match('#/Subtype\s*/Image\b#', $dict) === 1;

            $body_end = null;
            if (preg_match('#/Length\s+(\d{1,12}+)(?!\s+\d+\s+R)#', $dict, $m) === 1) {
                $candidate = $body_start + (int) $m[1];
                $after     = ltrim(substr($raw, $candidate, 16), "\r\n \t");
                if ($candidate <= $len && str_starts_with($after, 'endstream')) {
                    $body_end = $candidate;
                }
            }
            if ($body_end === null) {
                $found = self::nextAt($raw, 'endstream', $body_start, $cache);
                if ($found === false) {
                    if ($is_image) {
                        yield [$body_start, $len]; // unterminated: the rest of the file is its data
                    }
                    return;
                }
                $body_end = $found;
            }
            if ($is_image) {
                yield [$body_start, $body_end];
            }
            $resume = $body_end;
            $cursor = $body_end;
        }
    }

    /**
     * A preg_match() over raw PDF bytes that skips matches inside image stream data (imageStreamSpans()). Every
     * whole-file search for structure goes through it: an uploaded image may hold its own "12 0 obj … endobj" or
     * "/Annots [3 0 R]", which would take over the page tree or invent annotations.
     *
     * Returns a function ($from, &$m): bool for one forward walk ($from never decreases). A match is inside image data
     * when its last byte is.
     *
     * @param string $raw   Raw PDF bytes.
     * @param string $regex The pattern.
     * @return \Closure(int, mixed): bool
     */
    public static function finderOutsideImageData(string $raw, string $regex): \Closure
    {
        $spans = self::imageStreamSpans($raw);
        return static function (int $from, &$m) use ($raw, $regex, $spans): bool {
            while (preg_match($regex, $raw, $m, PREG_OFFSET_CAPTURE, $from) === 1) {
                $at = $m[0][1] + strlen($m[0][0]) - 1;
                while ($spans->valid() && $spans->current()[1] <= $at) {
                    $spans->next();
                }
                if ($spans->valid() && $spans->current()[0] <= $at) {
                    $from = $spans->current()[1]; // inside image data: look again after it
                    continue;
                }
                return true;
            }
            return false;
        };
    }

    /**
     * The number of "/Type /Page" entries outside image data: the page count generator and verifier both use.
     *
     * @param string $raw Raw PDF bytes.
     * @return int
     */
    public static function countPageObjects(string $raw): int
    {
        $find  = self::finderOutsideImageData($raw, '/\/Type\s*\/Page\b/');
        $count = 0;
        $at    = 0;
        while ($find($at, $m)) {
            $count++;
            $at = $m[0][1] + strlen($m[0][0]);
        }
        return $count;
    }

    /**
     * How often $needle occurs in raw PDF bytes outside image stream data (imageStreamSpans()).
     *
     * @param string $raw    Raw PDF bytes.
     * @param string $needle What to count.
     * @return int
     */
    public static function countOutsideImageData(string $raw, string $needle): int
    {
        $count  = 0;
        $cursor = 0;
        foreach (self::imageStreamSpans($raw) as [$start, $end]) {
            if ($start > $cursor) {
                $count += substr_count($raw, $needle, $cursor, $start - $cursor);
            }
            $cursor = max($cursor, $end);
        }
        return $cursor < strlen($raw) ? $count + substr_count($raw, $needle, $cursor) : $count;
    }

    /**
     * Every "[FABRICATOR_PDF_FIELD_<id>]…[FABRICATOR_PDF_FIELD_END]" span in extracted PDF text, as
     * preg_match_all('/\[FABRICATOR_PDF_FIELD_([^\]]+)\](.*?)\[FABRICATOR_PDF_FIELD_END\]/s', …, PREG_SET_ORDER) finds
     * them, but linear: that lazy regex is quadratic on unclosed markers.
     *
     * @param string $text Normalized PDF text.
     * @return array<int, array{0: string, 1: string, 2: string}> Full span, field id, enclosed text.
     */
    public static function fieldMarkerMatches(string $text): array
    {
        $open = '[FABRICATOR_PDF_FIELD_';
        $end  = '[FABRICATOR_PDF_FIELD_END]';
        $out  = [];
        $at   = 0;
        while (($start = strpos($text, $open, $at)) !== false) {
            $name_start = $start + strlen($open);
            $close      = strpos($text, ']', $name_start);
            if ($close === false) {
                break; // no "]" anywhere after this start, so no later start can complete either
            }
            if ($close === $name_start) {
                $at = $start + 1; // "[FABRICATOR_PDF_FIELD_]": an empty id matches nothing
                continue;
            }
            $body_start = $close + 1;
            $body_end   = strpos($text, $end, $body_start);
            if ($body_end === false) {
                break;
            }
            $out[] = [
                substr($text, $start, $body_end + strlen($end) - $start),
                substr($text, $name_start, $close - $name_start),
                substr($text, $body_start, $body_end - $body_start),
            ];
            $at = $body_end + strlen($end);
        }
        return $out;
    }

    /**
     * Every "<<dictionary>> stream … endstream" block in raw PDF bytes, as
     * preg_match_all('/<<([^>]*)>>\s*stream\r?\n([\s\S]*?)\nendstream/m', …, PREG_SET_ORDER) finds them: [1] the
     * dictionary, [2] the stream bytes ([0] stays empty). Linear, and yielded one at a time so a file of many small
     * blocks can't outgrow the memory reserved.
     *
     * @param string $raw Raw PDF bytes.
     * @return \Generator<int, array{0: string, 1: string, 2: string}>
     */
    public static function rawStreamBlocks(string $raw): \Generator
    {
        $len     = strlen($raw);
        $resume  = 0; // the end of the previous block: no block may start before it
        $keyword = 0;
        // The first "<<" at or after $open_from, or false: a forward cursor, as nextAt().
        $open_from  = -1;
        $open_found = false;
        while (($keyword = strpos($raw, 'stream', $keyword)) !== false) {
            $kw       = $keyword;
            $keyword += 6;
            // "stream", then an optional CR and a LF.
            $body_start = $kw + 6;
            if (($raw[$body_start] ?? '') === "\r") {
                $body_start++;
            }
            if (($raw[$body_start] ?? '') !== "\n") {
                continue;
            }
            $body_start++;
            // Before it: optional whitespace and the ">>" of a dictionary with no ">" inside, opened by the first "<<" after
            // the last ">" (the leftmost start the regex could take).
            $close = self::spaceRunStart($raw, $kw) - 2;
            if ($close < $resume || $close < 2 || substr($raw, $close, 2) !== '>>') {
                continue;
            }
            $last_gt = strrpos($raw, '>', $close - $len - 1);
            $from    = max($resume, $last_gt === false ? 0 : $last_gt + 1);
            if ($open_from < 0 || ($open_found !== false && $open_found < $from)) {
                $open_found = strpos($raw, '<<', $from);
                $open_from  = $from;
            }
            // A cached "no <<" answer holds for every later position too.
            $open = $open_found;
            if ($open === false || $open + 2 > $close) {
                continue;
            }
            $body_end = strpos($raw, "\nendstream", $body_start);
            if ($body_end === false) {
                return; // no later block can close either
            }
            yield ['', substr($raw, $open + 2, $close - $open - 2), substr($raw, $body_start, $body_end - $body_start)];
            $resume  = $body_end + 10;
            $keyword = $resume;
        }
    }

    /**
     * Most bytes one compressed stream is inflated to, by the seal scan, the verifier's parser and inflatedStreamBytes()
     * alike, so the memory reserved covers what the parser can decode.
     *
     * @var int
     */
    public const INFLATE_STREAM_CAP = 67108864;

    /**
     * Total bytes the zlib-compressed stream bodies in raw PDF bytes inflate to, each counted up to INFLATE_STREAM_CAP.
     *
     * Every body counts, whatever its dictionary says, so the total bounds what the verifier's parser unpacks. Output is
     * thrown away in small steps, so a decompression bomb costs time here, not memory.
     *
     * @param string $raw   Raw PDF bytes.
     * @param int    $limit Counting stops once the total passes this; the caller refuses the file then anyway.
     * @return int
     */
    public static function inflatedStreamBytes(string $raw, int $limit): int
    {
        // The same bodies, with the same delimiter, that streamBodies() hands the hashing passes.
        $total  = 0;
        $resume = 0;
        while (($body = self::nextStreamBody($raw, $resume, 'endstream')) !== null) {
            $total += self::inflatedLength(substr($raw, $body[0], $body[1] - $body[0]));
            if ($total > $limit) {
                break;
            }
            $resume = $body[1] + 9;
        }
        return $total;
    }

    /**
     * Length zlib data inflates to, up to INFLATE_STREAM_CAP; 0 for data that isn't zlib.
     *
     * @param string $data Compressed stream bytes.
     * @return int
     */
    private static function inflatedLength(string $data): int
    {
        $ctx = \FabricatorForms\Utils\Cast::withoutWarnings(static fn() => inflate_init(ZLIB_ENCODING_DEFLATE));
        if ($ctx === false) {
            return 0;
        }
        $length = 0;
        $size   = strlen($data);
        // 4 KB of input inflate to about 4 MB at most, zlib's ratio tops out near 1:1032.
        for ($at = 0; $at < $size && $length < self::INFLATE_STREAM_CAP; $at += 4096) {
            $chunk = substr($data, $at, 4096);
            $out   = \FabricatorForms\Utils\Cast::withoutWarnings(static fn() => inflate_add($ctx, $chunk, ZLIB_SYNC_FLUSH));
            if ($out === false) {
                break; // damaged data; what came out so far still counts
            }
            $length += strlen($out);
            if (inflate_get_status($ctx) === ZLIB_STREAM_END) {
                break;
            }
        }
        return min($length, self::INFLATE_STREAM_CAP);
    }

    /**
     * The "---BEGIN-SEAL---…---END-SEAL---" blocks in text, as preg_match_all('/---BEGIN-SEAL---(.*?)---END-SEAL---/s')
     * finds them, but linear: [offset, length, enclosed text].
     *
     * @param string $text Text that may contain seal blocks.
     * @return array<int, array{0: int, 1: int, 2: string}>
     */
    public static function sealBlocks(string $text): array
    {
        $out = [];
        $at  = 0;
        while (($start = strpos($text, self::SEAL_BEGIN, $at)) !== false) {
            $body_start = $start + strlen(self::SEAL_BEGIN);
            $body_end   = strpos($text, self::SEAL_END, $body_start);
            if ($body_end === false) {
                break; // no later start marker can be closed either
            }
            $at    = $body_end + strlen(self::SEAL_END);
            $out[] = [$start, $at - $start, substr($text, $body_start, $body_end - $body_start)];
        }
        return $out;
    }

    /**
     * $text without its seal blocks: what preg_replace('/---BEGIN-SEAL---.*?---END-SEAL---/s', '', …) returns, found
     * by a linear scan.
     *
     * @param string $text Text that may contain seal blocks.
     * @return string
     */
    public static function withoutSealBlocks(string $text): string
    {
        $out = '';
        $at  = 0;
        foreach (self::sealBlocks($text) as [$start, $length]) {
            $out .= substr($text, $at, $start - $at);
            $at   = $start + $length;
        }
        return $out . substr($text, $at);
    }

    /**
     * Where an object whose body starts at $start ends; the one rule every object reader here follows.
     *
     * At its "endobj", or at the next object header if that comes first, so one header can't swallow the objects after
     * it. With a stream, at the first "endobj" after "endstream", since compressed bytes can contain either word.
     *
     * @param string $pdf_raw Raw PDF bytes.
     * @param int    $start   Offset just past the object header.
     * @return int|null
     */
    private static function objectBodyEnd(string $pdf_raw, int $start): ?int
    {
        $end = strpos($pdf_raw, 'endobj', $start);
        if ($end === false) {
            return null;
        }
        $next = self::finderOutsideImageData($pdf_raw, self::OBJECT_START)($start, $nm) ? $nm[0][1] : null;
        if (preg_match('/>>\s*stream(\r\n|\n|\r)/', $pdf_raw, $sm, PREG_OFFSET_CAPTURE, $start) === 1 && $sm[0][1] < $end
            && ($next === null || $sm[0][1] < $next)
        ) {
            $data_end = strpos($pdf_raw, 'endstream', $sm[0][1] + strlen($sm[0][0]));
            $after    = $data_end === false ? false : strpos($pdf_raw, 'endobj', $data_end + 9);
            if ($after !== false) {
                return $after;
            }
        }
        return ($next !== null && $next < $end) ? $next : $end;
    }

    /**
     * An object header of any generation, digit-bounded: what ends the object before it (objectBodyEnd()).
     *
     * @var string
     */
    private const OBJECT_START  = '/(?<![0-9])\d+\s+\d+\s+obj\b/';

    /**
     * Lists each page's /Contents object numbers in display order, walking the page tree from the document catalog.
     *
     * @param string $pdf_raw Raw PDF bytes.
     * @param array  $objects indexObjects() output.
     * @return array Page object number => list of /Contents object numbers, in page order.
     */
    public static function pageContents(string $pdf_raw, array $objects): array
    {
        // The last /Root outside image data wins: an incremental update appends a new trailer.
        $root      = null;
        $root_at   = 0;
        $find_root = self::finderOutsideImageData($pdf_raw, '#/Root\s+(\d+)\s+0\s+R#');
        while ($find_root($root_at, $rm)) {
            $root    = $rm[1][0];
            $root_at = $rm[0][1] + strlen($rm[0][0]);
        }
        if ($root === null) {
            return [];
        }
        $catalog = $objects[(int) $root]['dict'] ?? '';
        if (!preg_match('#/Pages\s+(\d+)\s+0\s+R#', $catalog, $pm)) {
            return [];
        }

        $pages = [];
        $seen  = [];
        $walk  = static function (int $num, int $depth) use (&$walk, &$pages, &$seen, $objects): void {
            if ($depth > 32 || isset($seen[$num]) || !isset($objects[$num])) {
                return;
            }
            $seen[$num] = true;
            $dict       = $objects[$num]['dict'];
            if (preg_match('#/Type\s*/Pages\b#', $dict)) {
                if (preg_match('#/Kids\s*\[(.*?)\]#s', $dict, $km) && preg_match_all('/(\d+)\s+0\s+R/', $km[1], $kids)) {
                    foreach ($kids[1] as $kid) {
                        $walk((int) $kid, $depth + 1);
                    }
                }
                return;
            }
            if (!preg_match('#/Type\s*/Page\b#', $dict)) {
                return;
            }
            $contents = [];
            if (preg_match('#/Contents\s+(\d+)\s+0\s+R#', $dict, $cm)) {
                $contents = [(int) $cm[1]];
            } elseif (preg_match('#/Contents\s*\[(.*?)\]#s', $dict, $ca) && preg_match_all('/(\d+)\s+0\s+R/', $ca[1], $cl)) {
                $contents = array_map('intval', $cl[1]);
            }
            $pages[$num] = $contents;
        };
        $walk((int) $pm[1], 0);
        return $pages;
    }

    /**
     * Returns a stream's content bytes: inflated when Flate-compressed, otherwise as stored.
     *
     * @param string $raw_stream Raw bytes between "stream" and "endstream".
     * @return string|null Content bytes, or null when the stream or its inflated form exceeds the 64 MB ceiling.
     */
    public static function streamContent(string $raw_stream): ?string
    {
        if (strlen($raw_stream) > self::INFLATE_STREAM_CAP) {
            return null;
        }
        $dec = self::inflateEither($raw_stream, 2, false);
        if ($dec === null) {
            return null;
        }
        return $dec !== false ? $dec : $raw_stream;
    }

    /**
     * gzuncompress() (or, with $raw, gzinflate()) that stops as soon as the output passes $cap, so a decompression bomb
     * never takes more. No warning on input that isn't such data; false is a normal outcome.
     *
     * @param string $data Candidate zlib bytes, or bare DEFLATE bytes with $raw.
     * @param int    $cap  Most output bytes allowed.
     * @param bool   $raw  Whether $data is bare DEFLATE without the zlib wrapper.
     * @return string|false|null Inflated bytes; false when $data isn't complete such data; null when it inflates past $cap.
     */
    public static function inflateWithin(string $data, int $cap = self::INFLATE_STREAM_CAP, bool $raw = false): string|false|null
    {
        $ctx = $data === '' ? false : \FabricatorForms\Utils\Cast::withoutWarnings(static fn() => inflate_init($raw ? ZLIB_ENCODING_RAW : ZLIB_ENCODING_DEFLATE));
        if ($ctx === false) {
            return false;
        }
        $out  = '';
        $size = strlen($data);
        for ($at = 0; $at < $size; $at += 4096) {
            $chunk = substr($data, $at, 4096);
            $part  = \FabricatorForms\Utils\Cast::withoutWarnings(static fn() => inflate_add($ctx, $chunk, ZLIB_SYNC_FLUSH));
            if ($part === false) {
                return false;
            }
            $out .= $part;
            if (strlen($out) > $cap) {
                return null;
            }
            if (inflate_get_status($ctx) === ZLIB_STREAM_END) {
                return $out;
            }
        }
        // gzuncompress()/gzinflate() hand zlib the string's NUL terminator too, which can complete a stream; the same
        // byte here keeps results (and sealed hashes) identical to theirs.
        $part = \FabricatorForms\Utils\Cast::withoutWarnings(static fn() => inflate_add($ctx, "\0", ZLIB_SYNC_FLUSH));
        if ($part === false) {
            return false;
        }
        $out .= $part;
        if (strlen($out) > $cap) {
            return null;
        }
        if (inflate_get_status($ctx) !== ZLIB_STREAM_END) {
            return false;
        }
        // gzinflate() rejects some incomplete bare-DEFLATE data this accepts; with the output known to fit $cap, its
        // own answer decides.
        return $raw ? \FabricatorForms\Utils\Cast::withoutWarnings(static fn() => gzinflate($data)) : $out;
    }

    /**
     * A stream body inflated as zlib data, or else as bare DEFLATE from $raw_offset on, both within the stream cap.
     *
     * @param string $body         Raw stream bytes.
     * @param int    $raw_offset   Where the bare-DEFLATE attempt starts (2 skips a zlib header whose checksum failed).
     * @param bool   $retry_empty  Whether an empty or "0" zlib result also gets the bare-DEFLATE attempt, as the
     *                             `gzuncompress() ?: gzinflate()` idiom does.
     * @return string|false|null As inflateWithin().
     */
    public static function inflateEither(string $body, int $raw_offset = 0, bool $retry_empty = true): string|false|null
    {
        $dec = self::inflateWithin($body);
        if ($dec === false || ($retry_empty && ($dec === '' || $dec === '0'))) {
            return self::inflateWithin(substr($body, $raw_offset), self::INFLATE_STREAM_CAP, true);
        }
        return $dec;
    }

    /**
     * Whether the PDF can show an image of this type (WEBP only where GD supports it). Any other image is attached like
     * a document.
     *
     * @param string $mime MIME type.
     * @return bool
     */
    public static function embeddableImageMime(string $mime): bool
    {
        return match (strtolower($mime)) {
            'image/jpeg', 'image/pjpeg', 'image/png', 'image/gif', 'image/bmp', 'image/x-ms-bmp' => true,
            'image/webp' => function_exists('imagecreatefromwebp') && function_exists('imagewebp'),
            default      => false,
        };
    }

    /**
     * PCRE's \s without the u flag: space, HT, LF, VT, FF, CR.
     *
     * @var string
     */
    private const PCRE_SPACE = " \t\n\x0B\f\r";

    /**
     * An object header "N G obj", matched from the start of N's digits only, so a long digit run is walked once.
     *
     * @var string
     */
    private const OBJECT_HEADER = '/(?<![0-9])([0-9]+)\s+[0-9]+\s+obj/';

    /**
     * A FontDescriptor's reference to its embedded font program.
     *
     * @var string
     */
    private const FONT_FILE_REF = '/\/FontFile[23]?\s+(\d+)\s+\d+\s+R/';

    /**
     * Hashes every embedded font program (each FontDescriptor's /FontFile, /FontFile2 or /FontFile3 stream), sorted so
     * object order doesn't matter. Generator seals these hashes and the verifier recomputes them with this same code.
     *
     * Same result as every match of '/\d+\s+\d+\s+obj\s*<<([\s\S]*?\/Type\s*\/FontDescriptor[\s\S]*?)>>\s*endobj/', then
     * for each referenced object N the first match of '/N\s+\d+\s+obj[\s\S]*?stream\r?\n([\s\S]*?)\r?\nendstream/',
     * but in forward-only scans instead of those quadratic lazy regexes.
     *
     * @param string $pdf_raw Raw PDF bytes.
     * @return string[] Sorted SHA-256 hex digests.
     */
    public static function hashFontProgramStreams(string $pdf_raw): array
    {
        $font_objects = self::fontFileObjectNumbers($pdf_raw);
        if ($font_objects === []) {
            return [];
        }
        $header_ends = self::firstHeaderEndBySuffix($pdf_raw, $font_objects);
        // One cursor in file order: each font's stream follows its header, so ascending order needs one pass.
        asort($header_ends);
        $cursor = 0;

        // firstHeaderEndBySuffix() only ever returns the font objects it was asked for, so every entry here is one.
        $hashes = [];
        foreach ($header_ends as $header_end) {
            $bounds = self::nextStreamBody($pdf_raw, max($cursor, $header_end), "\nendstream");
            if ($bounds === null) {
                break;
            }
            [$body_start, $body_end] = $bounds;
            $cursor = $body_end + 10;
            // "\r?\n" ahead of endstream: a CR directly before the newline belongs to the delimiter, not the body.
            if ($body_end > $body_start && $pdf_raw[$body_end - 1] === "\r") {
                $body_end--;
            }
            if ($body_end - $body_start > 67108864) {
                continue;
            }
            $body = substr($pdf_raw, $body_start, $body_end - $body_start);
            $dec  = self::inflateEither($body);
            if (!is_string($dec)) {
                $dec = $body;
            }
            $hashes[] = hash('sha256', $dec);
        }

        sort($hashes);
        return $hashes;
    }

    /**
     * Object numbers that FontDescriptor objects reference as their font program, each once.
     *
     * @param string $pdf_raw Raw PDF bytes.
     * @return int[]
     */
    private static function fontFileObjectNumbers(string $pdf_raw): array
    {
        $numbers = [];
        $ref     = null; // [start, end, number] of the leftmost font-program reference at or after the last search start
        $pos     = 0;
        // Headers and descriptors outside image data only, each finder walking forward.
        $find_header     = self::finderOutsideImageData($pdf_raw, self::OBJECT_HEADER);
        $find_descriptor = self::finderOutsideImageData($pdf_raw, '#/FontDescriptor#');
        while ($find_header($pos, $m)) {
            $pos        = $m[0][1] + strlen($m[0][0]);
            $body_start = $pos + strspn($pdf_raw, self::PCRE_SPACE, $pos);
            if (substr($pdf_raw, $body_start, 2) !== '<<') {
                continue;
            }
            $body_start += 2;
            $descriptor  = self::fontDescriptorEnd($pdf_raw, $body_start, $find_descriptor);
            $object_end  = $descriptor === null ? null : self::descriptorObjectEnd($pdf_raw, $descriptor);
            if ($object_end === null) {
                // No FontDescriptor, or no ">> endobj" after it, so none can follow a later header either.
                break;
            }
            [$body_end, $pos] = $object_end;

            // Only the first reference inside the body counts; a result still ahead of this body is reused.
            if ($ref === null || $ref[0] < $body_start) {
                $ref = preg_match(self::FONT_FILE_REF, $pdf_raw, $r, PREG_OFFSET_CAPTURE, $body_start)
                    ? [$r[0][1], $r[0][1] + strlen($r[0][0]), (int) $r[1][0]]
                    : [PHP_INT_MAX, PHP_INT_MAX, 0];
            }
            if ($ref[1] <= $body_end) {
                $numbers[$ref[2]] = true;
            }
        }
        return array_keys($numbers);
    }

    /**
     * Position just past the first "/Type /FontDescriptor" (any \s run between the names) starting at or after $from.
     *
     * @param string   $pdf_raw Raw PDF bytes.
     * @param int      $from    Earliest start offset.
     * @param \Closure $find    finderOutsideImageData() for "/FontDescriptor", walked forward across calls.
     * @return int|null
     */
    private static function fontDescriptorEnd(string $pdf_raw, int $from, \Closure $find): ?int
    {
        $at = $from;
        while ($find($at, $dm)) {
            $name = $dm[0][1];
            $type = self::spaceRunStart($pdf_raw, $name) - 5;
            if ($type >= $from && substr($pdf_raw, $type, 5) === '/Type') {
                return $name + 15;
            }
            $at = $name + 1;
        }
        return null;
    }

    /**
     * Locates the first ">>" at or after $from that is followed by optional \s and "endobj".
     *
     * @param string $pdf_raw Raw PDF bytes.
     * @param int    $from    Earliest offset of the ">>".
     * @return array{0: int, 1: int}|null Offset of the ">>", and the offset just past "endobj".
     */
    private static function descriptorObjectEnd(string $pdf_raw, int $from): ?array
    {
        $at = $from;
        while (($endobj = strpos($pdf_raw, 'endobj', $at)) !== false) {
            $close = self::spaceRunStart($pdf_raw, $endobj) - 2;
            if ($close >= $from && substr($pdf_raw, $close, 2) === '>>') {
                return [$close, $endobj + 6];
            }
            $at = $endobj + 1;
        }
        return null;
    }

    /**
     * For each wanted object number, the offset just past "obj" in the first header whose first number ends in its
     * digits. Without a boundary before N, "112 0 obj" would match N = 12 as well.
     *
     * @param string $pdf_raw Raw PDF bytes.
     * @param int[]  $numbers Wanted object numbers.
     * @return array<int, int>
     */
    private static function firstHeaderEndBySuffix(string $pdf_raw, array $numbers): array
    {
        $wanted = array_fill_keys($numbers, true);
        $found  = [];
        $pos    = 0;
        $find   = self::finderOutsideImageData($pdf_raw, self::OBJECT_HEADER); // headers outside image data only
        while (count($found) < count($wanted) && $find($pos, $m)) {
            $pos    = $m[0][1] + strlen($m[0][0]);
            $digits = $m[1][0];
            // An int has at most 19 digits. A suffix with a leading zero stays a string key and never matches an int.
            for ($len = min(strlen($digits), 20); $len >= 1; $len--) {
                $suffix = substr($digits, -$len);
                if (isset($wanted[$suffix]) && !isset($found[$suffix])) {
                    $found[$suffix] = $pos;
                }
            }
        }
        return $found;
    }

    /**
     * Start of the run of PCRE \s bytes that ends just before $pos ($pos itself when there is none).
     *
     * @param string $bytes Bytes to walk.
     * @param int    $pos   Offset the run ends at.
     * @return int
     */
    private static function spaceRunStart(string $bytes, int $pos): int
    {
        while ($pos > 0 && strpos(self::PCRE_SPACE, $bytes[$pos - 1]) !== false) {
            $pos--;
        }
        return $pos;
    }

    /**
     * Hashes every compressed stream that is not a content stream, for catch-all stream-injection detection.
     *
     * Generator seals these hashes and the verifier recomputes them with this same code. Content streams change between
     * PASS 1 and PASS 2, so verifyContentStreams() checks those instead.
     *
     * @param string $pdf_raw Raw PDF bytes.
     * @return string[] Sorted SHA-256 hex digests.
     */
    public static function hashAllCompressedStreams(string $pdf_raw): array
    {
        $hashes = [];
        foreach (self::streamBodies($pdf_raw) as [$start, $end]) {
            $body = substr($pdf_raw, $start, $end - $start);
            if (strlen($body) > self::INFLATE_STREAM_CAP) {
                continue;
            }
            $dec = self::inflateEither($body);
            if (is_string($dec) && !self::looksLikeContentStream($dec)) {
                $hashes[] = hash('sha256', $dec);
            }
        }
        sort($hashes);
        return $hashes;
    }

    /**
     * Every stream body in the file, as [start, end] byte offsets, in one forward pass. Yielded one at a time, so a
     * file of many small streams can't outgrow the memory reserved.
     *
     * @param string $pdf_raw Raw PDF bytes.
     * @return \Generator<int, array{0: int, 1: int}> Body start, and the offset of the "endstream" that closes it.
     */
    private static function streamBodies(string $pdf_raw): \Generator
    {
        $resume = 0;
        while (($body = self::nextStreamBody($pdf_raw, $resume, 'endstream')) !== null) {
            yield $body;
            $resume = $body[1] + 9;
        }
    }

    /**
     * The first stream body at or after $from, as [start, end] byte offsets; null when the file holds no more. Each
     * call resumes where the last one ended, so a full walk stays linear.
     *
     * @param string $pdf_raw    Raw PDF bytes.
     * @param int    $from       Offset to resume at.
     * @param string $end_needle What closes a body: 'endstream' for the hashing passes, "\nendstream" where the
     *                           delimiter's newline must stay out of the body.
     * @return array{0: int, 1: int}|null
     */
    private static function nextStreamBody(string $pdf_raw, int $from, string $end_needle): ?array
    {
        $at = max(0, $from);
        while (($keyword = strpos($pdf_raw, 'stream', $at)) !== false) {
            $at = $keyword + 6;
            // The "stream" inside "endstream" opens nothing.
            if (substr($pdf_raw, max(0, $keyword - 3), 3) === 'end') {
                continue;
            }
            $eol = substr($pdf_raw, $at, 2);
            if ($eol !== '' && $eol[0] === "\n") {
                $body_start = $at + 1;
            } elseif ($eol === "\r\n") {
                $body_start = $at + 2;
            } else {
                continue;
            }
            $end = strpos($pdf_raw, $end_needle, $body_start);
            return $end === false ? null : [$body_start, $end];
        }
        return null;
    }

    /**
     * Hashes every decoded page content stream: the counterpart of hashAllCompressedStreams().
     *
     * @param string $pdf_raw    Raw PDF bytes.
     * @param int    $raw_offset Offset handed to inflateEither(); the Generator reads its own PASS-1 bytes with 2.
     * @return string[] Sorted SHA-256 hex digests.
     */
    public static function hashPageContentStreams(string $pdf_raw, int $raw_offset = 0): array
    {
        $hashes = [];
        foreach (self::streamBodies($pdf_raw) as [$start, $end]) {
            $body = substr($pdf_raw, $start, $end - $start);
            if (strlen($body) > self::INFLATE_STREAM_CAP) {
                continue;
            }
            $dec = self::inflateEither($body, $raw_offset);
            if (is_string($dec) && self::looksLikeContentStream($dec)) {
                $hashes[] = hash('sha256', $dec);
            }
        }
        sort($hashes);
        return $hashes;
    }

    /**
     * Reports whether decoded bytes look like drawing operators. Shared by generator and verifier.
     *
     * @param string $decoded Decoded stream bytes.
     * @return bool True when the bytes look like a content stream.
     */
    public static function looksLikeContentStream(string $decoded): bool
    {
        $head = substr($decoded, 0, 16);
        $len  = strlen($head);
        for ($i = 0; $i < $len; $i++) {
            $b = ord($head[$i]);
            if ($b < 9 || ($b > 13 && $b < 32 && $b !== 27)) {
                return false;
            }
        }
        return (bool) preg_match('/\bBT\b|\bq\b|\bQ\b|\bcm\b|\bTf\b|\bTj\b|\bTd\b/', $decoded);
    }

    /**
     * Checks every displayed content stream against the PASS-1 hashes sealed in the document.
     *
     * Checked: every page /Contents, Form XObject and tiling pattern, plus any stream that looks like drawing operators.
     * Exactly one stream, among the last page's /Contents, may carry the seal; it is rebuilt into its PASS-1 form, and
     * the streams must then equal the sealed hashes as a multiset.
     *
     * @param string $pdf_raw       Raw PDF bytes.
     * @param array  $sealed_hashes content_streams from the seal.
     * @param string $seal_text     "---BEGIN-SEAL---" . base64 . "---END-SEAL---" as extracted from the PDF.
     * @return array ['rows' => [['obj', 'hash', 'status']], 'problems' => [['code', ...]], 'mismatch' => bool, 'seal_obj' => ?int, 'rebuilt' => bool].
     */
    public static function verifyContentStreams(string $pdf_raw, array $sealed_hashes, string $seal_text): array
    {
        $result   = ['rows' => [], 'problems' => [], 'mismatch' => false, 'seal_obj' => null, 'rebuilt' => false];
        $objects  = self::indexObjects($pdf_raw);
        $pages    = self::pageContents($pdf_raw, $objects);
        // As sets: the uploader decides how long a /Contents list is, so in_array() per stream would be quadratic.
        $contents = $pages === [] ? [] : array_flip(array_merge(...array_values($pages)));
        $last     = $pages === [] ? [] : array_flip(end($pages));

        $pool = [];
        foreach ($sealed_hashes as $hash) {
            $pool[(string) $hash] = ($pool[(string) $hash] ?? 0) + 1;
        }

        $marker_objs = [];
        $candidates  = [];
        foreach ($objects as $num => $obj) {
            if ($obj['stream'] === null) {
                continue;
            }
            $required = isset($contents[$num])
                || preg_match('#/Subtype\s*/Form\b#', $obj['dict']) === 1
                || preg_match('#/Type\s*/Pattern\b#', $obj['dict']) === 1;
            // Image data is the uploader's own bytes and may read like a seal or operators; the image hashes cover it.
            // A page's /Contents is checked whatever its dictionary claims.
            if (!$required && preg_match('#/Subtype\s*/Image\b#', $obj['dict']) === 1) {
                continue;
            }
            $bytes = self::streamContent($obj['stream']);
            if ($bytes === null) {
                if ($required) {
                    $result['problems'][] = ['code' => 'oversized', 'obj' => $num];
                    $result['mismatch']   = true;
                }
                continue;
            }
            if (self::sealMarkerIn($bytes)) {
                $marker_objs[] = $num;
            }
            if ($required || self::looksLikeContentStream($bytes)) {
                $candidates[$num] = $bytes;
            }
        }

        // Exactly one stream may carry the seal, and only one of the last page's own /Contents.
        foreach ($marker_objs as $num) {
            if ($result['seal_obj'] === null && isset($last[$num])) {
                $result['seal_obj'] = $num;
                continue;
            }
            $result['problems'][] = ['code' => 'marker_outside_seal_page', 'obj' => $num];
            $result['mismatch']   = true;
        }
        if ($result['seal_obj'] === null) {
            $result['problems'][] = ['code' => 'no_seal_stream'];
            $result['mismatch']   = true;
        } else {
            $rebuilt = self::rebuildSealPage($candidates[$result['seal_obj']] ?? '', $seal_text, $pool);
            if ($rebuilt !== null) {
                $pool[$rebuilt]--;
                $result['rebuilt'] = true;
                $result['rows'][]  = ['obj' => $result['seal_obj'], 'hash' => $rebuilt, 'status' => 'seal-page'];
            } else {
                $result['problems'][] = ['code' => 'seal_page_mismatch', 'obj' => $result['seal_obj']];
                $result['mismatch']   = true;
            }
        }

        foreach ($candidates as $num => $bytes) {
            if ($num === $result['seal_obj']) {
                continue;
            }
            $hash = hash('sha256', $bytes);
            if (($pool[$hash] ?? 0) > 0) {
                $pool[$hash]--;
                $result['rows'][] = ['obj' => $num, 'hash' => $hash, 'status' => 'match'];
                continue;
            }
            $result['rows'][]   = ['obj' => $num, 'hash' => $hash, 'status' => 'unrecognised'];
            $result['mismatch'] = true;
        }

        // A seal page that failed to rebuild leaves one hash unmatched (already reported); any other is missing.
        $allowed = ($result['seal_obj'] !== null && !$result['rebuilt']) ? 1 : 0;
        $left    = array_sum($pool);
        if ($left > $allowed) {
            $result['problems'][] = ['code' => 'sealed_missing', 'count' => $left - $allowed];
            $result['mismatch']   = true;
        }

        return $result;
    }

    /**
     * Rebuilds the seal page's PASS-1 stream and returns the sealed hash it matches.
     *
     * PASS 2 adds only the seal text lines and the state-only lines around them. Those are removed, so nothing
     * visible can hide in what is removed, and the rest must hash to a sealed value exactly.
     *
     * @param string $stream    Decoded seal-page content stream.
     * @param string $seal_text The seal text extracted from the PDF, markers included.
     * @param array  $pool      Remaining sealed hashes, hash => unconsumed count.
     * @return string|null The matching sealed hash, or null when no rebuild matches.
     */
    private static function rebuildSealPage(string $stream, string $seal_text, array $pool): ?string
    {
        $lines = explode(chr(10), $stream);
        $first = null;
        $last  = null;
        $text  = '';
        foreach ($lines as $i => $line) {
            if (preg_match(self::SEAL_LINE_RE, $line, $lm) !== 1) {
                if ($first !== null) {
                    break;
                }
                continue;
            }
            $glyphs = str_replace(chr(0), '', self::unescapeLiteral($lm[1]));
            if ($first === null) {
                if (!str_starts_with($glyphs, self::SEAL_BEGIN)) {
                    continue;
                }
                $first = $i;
            }
            $text .= $glyphs;
            $last  = $i;
            if (str_contains($glyphs, self::SEAL_END)) {
                break;
            }
        }
        if ($first === null || $text !== $seal_text) {
            return null;
        }

        $count  = count($lines);
        $before = 0;
        while ($before < 16 && $first - $before - 1 >= 0 && self::isStateOnlyLine($lines[$first - $before - 1])) {
            $before++;
        }
        $after = 0;
        while ($after < 16 && $last + $after + 1 < $count && self::isStateOnlyLine($lines[$last + $after + 1])) {
            $after++;
        }
        return self::firstTrimmedMatch($lines, $first, $last, $before, $after, $pool);
    }

    /**
     * Tail bytes firstTrimmedMatch() may hash in total (256 MB, about a second of SHA-256).
     *
     * @var int
     */
    private const SEAL_REBUILD_MAX_BYTES = 268435456;

    /**
     * Tries every way of dropping 0..$before state-only lines ahead of the seal text and 0..$after after it, and
     * returns the first sealed hash one of them produces.
     *
     * Each head's hash context extends the next-shorter head's, so only the tails are re-hashed per combination, and
     * their total is bounded before any work starts.
     *
     * @param string[] $lines  Stream split on LF.
     * @param int      $first  Index of the first seal text line.
     * @param int      $last   Index of the last seal text line.
     * @param int      $before State-only lines available before $first.
     * @param int      $after  State-only lines available after $last.
     * @param array    $pool   Remaining sealed hashes, hash => unconsumed count.
     * @return string|null The matching sealed hash, or null.
     */
    private static function firstTrimmedMatch(array $lines, int $first, int $last, int $before, int $after, array $pool): ?string
    {
        // Tail j: the lines after the seal text minus the first j, a suffix of one joined string. Sized from the line
        // lengths, so an oversized tail is refused before it is joined.
        $tail_lines = array_slice($lines, $last + 1);
        $tail_count = count($tail_lines);
        $tail_len   = $tail_count === 0 ? 0 : array_sum(array_map('strlen', $tail_lines)) + $tail_count - 1;
        $tail_at    = [];
        $tail_bytes = 0;
        $offset     = 0;
        for ($j = 0; $j <= $after; $j++) {
            $tail_at[$j] = $j < $tail_count ? $offset : null;
            $tail_bytes += $tail_at[$j] === null ? 0 : $tail_len - $offset;
            $offset     += strlen($tail_lines[$j] ?? '') + 1;
        }
        if ($tail_bytes * ($before + 1) > self::SEAL_REBUILD_MAX_BYTES) {
            \FabricatorForms\fabricator_log(
                'FabricatorForms PdfUtils: seal page not rebuilt, the text after the seal is too large to try every trim ('
                . $tail_bytes . ' bytes x ' . ($before + 1) . ').'
            );
            return null;
        }
        $tail_text = implode(chr(10), $tail_lines);

        // Head k is the lines before the seal text minus its last k lines, so head k = head k+1 plus one more line.
        $heads = [];
        $ctx   = hash_init('sha256');
        $size  = $first - $before;
        if ($size > 0) {
            hash_update($ctx, implode(chr(10), array_slice($lines, 0, $size)));
        }
        $heads[$before] = [$ctx, $size > 0];
        for ($k = $before - 1; $k >= 0; $k--) {
            $ctx = hash_copy($heads[$k + 1][0]);
            hash_update($ctx, ($heads[$k + 1][1] ? chr(10) : '') . $lines[$first - $k - 1]);
            $heads[$k] = [$ctx, true];
        }

        for ($k = 0; $k <= $before; $k++) {
            for ($j = 0; $j <= $after; $j++) {
                $ctx = hash_copy($heads[$k][0]);
                if ($tail_at[$j] !== null) {
                    hash_update($ctx, ($heads[$k][1] ? chr(10) : '') . substr($tail_text, $tail_at[$j]));
                }
                $hash = hash_final($ctx);
                if (($pool[$hash] ?? 0) > 0) {
                    return $hash;
                }
            }
        }
        return null;
    }

    /**
     * Reports whether bytes contain the seal-opening marker, as plain text or as mPDF's two-byte glyph codes.
     *
     * @param string $bytes Decoded stream bytes.
     * @return bool True when a marker is present.
     */
    private static function sealMarkerIn(string $bytes): bool
    {
        if (str_contains($bytes, '---BEGIN-SEAL')) {
            return true;
        }
        $wide = '';
        foreach (str_split('---BEGIN') as $ch) {
            $wide .= chr(0) . $ch;
        }
        return str_contains($bytes, $wide);
    }

    /**
     * Decodes the escapes inside one PDF string literal (without its parentheses).
     *
     * @param string $inner Literal body.
     * @return string Decoded bytes.
     */
    private static function unescapeLiteral(string $inner): string
    {
        $backslash = chr(92);
        $out       = '';
        $len       = strlen($inner);
        for ($i = 0; $i < $len; $i++) {
            $ch = $inner[$i];
            if ($ch !== $backslash) {
                $out .= $ch;
                continue;
            }
            $next = $inner[$i + 1] ?? '';
            if ($next === '') {
                break;
            }
            if ($next >= '0' && $next <= '7') {
                $oct = $next;
                for ($k = 2; $k <= 3; $k++) {
                    $d = $inner[$i + $k] ?? '';
                    if ($d === '' || $d < '0' || $d > '7') {
                        break;
                    }
                    $oct .= $d;
                }
                $out .= chr(octdec($oct) & 0xFF);
                $i   += strlen($oct);
                continue;
            }
            // A backslash before a newline is a line continuation: it contributes nothing.
            if ($next === chr(10) || $next === chr(13)) {
                $i++;
                continue;
            }
            $map  = ['n' => chr(10), 'r' => chr(13), 't' => chr(9), 'b' => chr(8), 'f' => chr(12)];
            $out .= $map[$next] ?? $next;
            $i++;
        }
        return $out;
    }

    /**
     * Reports whether a content-stream line only changes state and paints nothing.
     *
     * @param string $line One line of a decoded content stream.
     * @return bool True for a state-only (or blank) line.
     */
    private static function isStateOnlyLine(string $line): bool
    {
        return preg_match(self::STATE_LINE_RE, trim($line)) === 1;
    }

    /**
     * Logs why the seal-page fingerprint could not be produced.
     *
     * @param string $reason Short description of which structural lookup failed.
     * @return string Always '' — the "not recorded" marker the verifier understands.
     */
    private static function sealPageUnavailable(string $reason): string
    {
        \FabricatorForms\fabricator_log(
            'FabricatorForms PdfUtils: seal-page text fingerprint unavailable (' . $reason
            . ') — the seal page will not be content-verified for this PDF.'
        );
        return '';
    }

    /**
     * Fingerprints a page's literal text. Not a general text extractor: it only has to be deterministic, so it ignores
     * cmaps, kerning and encoding.
     *
     * @param string $content Decompressed page content stream.
     * @return string SHA-256 of the normalized visible text.
     */
    public static function pageTextFingerprint(string $content): string
    {
        // Hand-rolled: PDF literals nest parentheses and escapes, which a regex handles badly.
        $backslash = chr(92);
        $pieces = [];
        $depth  = 0;
        $buf    = '';
        $len    = strlen($content);
        for ($i = 0; $i < $len; $i++) {
            $ch = $content[$i];
            if ($depth === 0) {
                if ($ch === '(') {
                    $depth = 1;
                    $buf   = '';
                }
                continue;
            }
            if ($ch === $backslash) {
                // Decoded, not skipped, so differently-escaped literals fingerprint differently.
                $next = $content[$i + 1] ?? '';
                if ($next === '') {
                    break;
                }
                if ($next >= '0' && $next <= '7') {
                    // Octal escape: up to three digits, per the PDF string-literal grammar.
                    $oct = $next;
                    for ($k = 2; $k <= 3; $k++) {
                        $d = $content[$i + $k] ?? '';
                        if ($d < '0' || $d > '7') {
                            break;
                        }
                        $oct .= $d;
                    }
                    $buf .= chr(octdec($oct) & 0xFF);
                    $i   += strlen($oct);
                    continue;
                }
                $map  = ['n' => chr(10), 'r' => chr(13), 't' => chr(9), 'b' => chr(8), 'f' => chr(12)];
                // A backslash before a newline is a line continuation: it contributes nothing.
                if ($next === chr(10) || $next === chr(13)) {
                    $i++;
                    continue;
                }
                $buf .= $map[$next] ?? $next;   // \( \) \ and any other escape are literal
                $i++;
                continue;
            }
            if ($ch === '(') {
                $depth++;
                $buf .= $ch;
                continue;
            }
            if ($ch === ')') {
                $depth--;
                if ($depth === 0) {
                    $pieces[] = $buf;
                } else {
                    $buf .= $ch;
                }
                continue;
            }
            $buf .= $ch;
        }
        $text = implode(' ', $pieces);

        // Drop the seal, which PASS 1 never had; NULs first, as mPDF writes UTF-16BE.
        $text = str_replace(chr(0), '', $text);
        $text = self::withoutSealBlocks($text);
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);

        return hash('sha256', $text);
    }

    /**
     * Computes a perceptual thumbnail hash (8x8 quantised RGB grid) stable across PDF rendering passes.
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
        $gd = \FabricatorForms\Utils\Cast::withoutWarnings(static fn() => imagecreatefromstring($binary));
        if ($gd === false) {
            return null;
        }
        // Backstop: the decoded size must agree with the header precheckDimensions() read.
        if (imagesx($gd) * imagesy($gd) > self::maxSafePixels()) {
            // No imagedestroy(): a no-op since PHP 8.0 and deprecated in 8.5; $gd is freed on return.
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
        // Masking the low 3 bits absorbs GD re-encoding jitter between the two passes.
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
