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
     * Returns a pixel-count ceiling scaled to memory_limit (half reserved for decoding) — a per-decode guard distinct from MemoryBudget's host-wide accounting, both needed.
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
     * Cheaply checks an image's header dimensions against the safe-pixel cap before GD ever decodes it.
     *
     * @param string $binary Raw binary image data.
     * @return bool True if the image's declared dimensions are within the safe cap (or unknown), false
     *              if they exceed it.
     */
    public static function precheckDimensions(string $binary): bool
    {
        $size = \FabricatorForms\Utils\Cast::withoutWarnings(static fn() => getimagesizefromstring($binary));
        if ($size === false || !isset($size[0], $size[1])) {
            return true;
        }
        return ((int) $size[0] * (int) $size[1]) <= self::maxSafePixels();
    }

    /**
     * Lives here (not Generator/Verificationpage) so both derive identical seal-page text from identical bytes — a prior split caused false seal-mismatches on untouched PDFs.
     *
     * @param string $pdf_raw Raw PDF bytes.
     * @return string SHA-256 of the seal page's visible text, or '' when it cannot be resolved.
     */
    public static function sealPageTextFingerprint(string $pdf_raw): string
    {
        // Resolves the page tree from the catalog, following nested /Pages and each object's LAST definition, so a
        // decoy early copy or an intermediate node can't make this read content a viewer never shows.
        $objects = self::indexObjects($pdf_raw);
        $pages   = self::pageContents($pdf_raw, $objects);
        if ($pages === []) {
            return self::sealPageUnavailable('page tree could not be resolved from the catalog');
        }
        // /Contents may be a single ref or an array; handling only the single form let an array silently disable this check while verification still ran.
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
     * Every structure that indexes objects — indexObjects(), the verifier's object walk, pdfparser itself — costs a
     * fixed amount per object on top of the bytes, and an object can be twenty bytes long, so a file of tiny objects
     * cost many times its own size and ended in a memory fatal instead of a refusal. A document from this plugin holds
     * a few thousand objects at most (a few per page, a few per font and per image), so this ceiling refuses nothing
     * real and turns the per-object cost into a fixed bound.
     *
     * @var int
     */
    public const MAX_OBJECTS = 50000;

    /**
     * How many object headers raw PDF bytes declare, counted without building anything.
     *
     * Counts "obj" after each whitespace byte PCRE's \s knows, which is every way a header ("12 0 obj") can be written
     * for the object scans to match it; "endobj" never counts, since "obj" follows "d" there. Anything that merely
     * looks like a header inside a stream counts too, which only makes the ceiling stricter.
     *
     * @param string $raw Raw PDF bytes.
     * @return int
     */
    public static function declaredObjectCount(string $raw): int
    {
        $count = 0;
        foreach ([' ', "\n", "\r", "\t", "\x0B", "\x0C"] as $space) {
            $count += substr_count($raw, $space . 'obj');
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
        // One forward pass, never a preg_match_all() over the whole file: the offset-capture match array for every
        // object header measured about 16 times the file's own size, more than the check had reserved.
        $pos = 0;
        // Latched once no "endstream" remains anywhere ahead: without it, a file full of stream headers that never
        // close would re-scan the tail for each of them.
        $endstream_exhausted = false;
        $seen                = 0;
        while (preg_match('/(?:^|[^0-9])(\d+)\s+0\s+obj\b/', $pdf_raw, $m, PREG_OFFSET_CAPTURE, $pos) === 1) {
            // Backstop for MAX_OBJECTS: the verifier refuses such a file before reading it, but this index is also
            // what decides a verdict, so it never grows past the ceiling whoever calls it.
            if (++$seen > self::MAX_OBJECTS) {
                throw new \LengthException('Too many objects in one PDF.');
            }
            $start = $m[0][1] + strlen($m[0][0]);
            $pos   = $start;
            $end   = strpos($pdf_raw, 'endobj', $start);
            if ($end === false) {
                break; // Nothing closes this object, so nothing closes any later one either.
            }
            $body   = substr($pdf_raw, $start, $end - $start);
            $record = ['dict' => $body, 'stream' => null];
            // Stream bounds from the "stream" keyword to the next "endstream", not from the first "endobj":
            // compressed bytes can contain that word, and cutting there would hash a truncated stream. The keyword is
            // looked for inside this object's own bytes — searching the rest of the file for it cost every
            // stream-less object a scan to the end, which is quadratic on a file made of them.
            $has_marker = !$endstream_exhausted
                && preg_match('/>>\s*stream(\r\n|\n|\r)/', $body, $sm, PREG_OFFSET_CAPTURE) === 1;
            if ($has_marker) {
                $marker_at  = $start + $sm[0][1];
                $data_start = $marker_at + strlen($sm[0][0]);
                $data_end   = strpos($pdf_raw, 'endstream', $data_start);
                if ($data_end === false) {
                    $endstream_exhausted = true;
                } else {
                    $record['dict']   = substr($pdf_raw, $start, $sm[0][1] + 2);
                    $record['stream'] = substr($pdf_raw, $data_start, $data_end - $data_start);
                }
            }
            $objects[(int) $m[1][0]] = $record;
        }
        return $objects;
    }

    /**
     * strpos() for a walk whose offsets only move forward: the last answer is reused while it still lies ahead, and an
     * answer of "none" holds for every later offset, so the whole walk reads each byte about once.
     *
     * A plain strpos() per step searched to the end of the file whenever the needle was missing, which made every walk
     * quadratic on a file built without it (see CLAUDE.md, "Scanning untrusted PDF bytes"). The result is always exactly
     * strpos()'s: an offset that goes backwards simply searches afresh.
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
     * Every object's last definition, indexed in one forward pass, for lookups that would otherwise search the whole file
     * each time.
     *
     * The verifier resolves a reference (a /Width, a palette, a mask, a nested XObject) once per reference it meets, and
     * lastObjectDefinition() searches the whole file for each. The number of references is the uploader's choice, so the
     * lookups together cost that number times the file. This finds, for every object number, exactly what
     * lastObjectDefinition() would return — the last header with that number whose body closes — and definitionFromIndex()
     * then answers each lookup at once.
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
        // Its own cursor for the stream marker, found by regex rather than strpos() but with the same idea: the answer
        // for one object serves every later one until they pass it, and "none" holds for good.
        $marker_from  = -1;
        $marker_found = null;
        while (preg_match('/(?<![0-9])(\d+)\s+(\d+)\s+obj\b/', $pdf_raw, $m, PREG_OFFSET_CAPTURE, $pos) === 1) {
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
            if ($marker_from < 0 || ($marker_found !== false && $marker_found[0] < $start)) {
                $marker_found = preg_match('/>>\s*stream(\r\n|\n|\r)/', $pdf_raw, $sm, PREG_OFFSET_CAPTURE, $start) === 1
                    ? [$sm[0][1], $sm[0][1] + strlen($sm[0][0])]
                    : false;
                $marker_from  = $start;
            }
            if ($marker_found !== false && $marker_found[0] < $end) {
                $data_end = self::nextAt($pdf_raw, 'endstream', $marker_found[1], $cache);
                $after    = $data_end === false ? false : self::nextAt($pdf_raw, 'endobj', $data_end + 9, $after_cache);
                if ($after !== false) {
                    $end = $after;
                }
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
     * Same rules as indexObjects(): the last definition is the one a viewer resolves after an incremental update, and
     * the number is digit-bounded, so object 12 never resolves to "112 0 obj". Leading zeros are allowed, as a PDF
     * reader parses the number as an integer. Unlike indexObjects(), nothing else in the file is copied.
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
        if (!preg_match_all($pattern, $pdf_raw, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        for ($i = count($m[0]) - 1; $i >= 0; $i--) {
            $start = $m[0][$i][1] + strlen($m[0][$i][0]);
            $end   = self::objectBodyEnd($pdf_raw, $start);
            if ($end !== null) {
                return ['header' => $m[0][$i][0], 'body' => substr($pdf_raw, $start, $end - $start)];
            }
        }
        return null;
    }

    /**
     * Reads one text entry (such as /Title) from a dictionary and decodes it: a literal string with its escapes
     * resolved and nested parentheses balanced, or a hex string. UTF-16BE with a byte-order mark becomes UTF-8; other
     * bytes are returned as they are.
     *
     * mPDF writes /Title, /Author and /Creator as UTF-16BE literal strings in which "(", ")", "\" and CR are escaped,
     * so reading up to the first ")" garbled any value containing one of those bytes. Strings are skipped while
     * looking for the key, so a "/Title" inside another value never matches; for a repeated key the last one wins.
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
     * Every "[FABRICATOR_PDF_FIELD_<id>]…[FABRICATOR_PDF_FIELD_END]" span in extracted PDF text, in the shape that
     * preg_match_all('/\[FABRICATOR_PDF_FIELD_([^\]]+)\](.*?)\[FABRICATOR_PDF_FIELD_END\]/s', …, PREG_SET_ORDER) returned.
     *
     * Found with strpos(): once no end marker followed, that lazy regex rescanned the rest of the text from every later
     * start marker, which a crafted PDF could turn into a quadratic comparison.
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
     * Every "<<dictionary>> stream … endstream" block in raw PDF bytes, in the shape that
     * preg_match_all('/<<([^>]*)>>\s*stream\r?\n([\s\S]*?)\nendstream/m', …, PREG_SET_ORDER) returned: [1] the dictionary,
     * [2] the stream bytes ([0] stays empty, nothing reads it).
     *
     * Found with strpos(): under the verifier's raised backtrack limit, a file without a closing "endstream" made that lazy
     * regex rescan the rest of the file from every later dictionary.
     *
     * Yielded one block at a time, not returned as a list: a file of many small blocks held one entry per block, several
     * times the file's own size, more than the memory the check had reserved.
     *
     * @param string $raw Raw PDF bytes.
     * @return \Generator<int, array{0: string, 1: string, 2: string}>
     */
    public static function rawStreamBlocks(string $raw): \Generator
    {
        $len     = strlen($raw);
        $resume  = 0; // the regex resumed after the previous match, so no block may start before this
        $keyword = 0;
        // The first "<<" at or after $open_from, or false when there is none. The positions asked for only ever grow, so
        // one answer serves every later request until the request passes it: searching afresh for each keyword re-scanned
        // the same stretch — all the way to the end of the file when no "<<" followed — which was quadratic.
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
     * Most bytes one compressed stream is inflated to. The seal scan and the verifier's PDF parser both stop there, and
     * inflatedStreamBytes() counts with it, so the memory reserved for a check covers what the parser can decode.
     *
     * @var int
     */
    public const INFLATE_STREAM_CAP = 67108864;

    /**
     * Total bytes the zlib-compressed stream bodies in raw PDF bytes inflate to, each counted up to INFLATE_STREAM_CAP.
     *
     * Every body counts, whatever its dictionary says (nested dictionaries, as mPDF writes for PNG images, are easy to
     * misread), so the total is an upper bound of what the verifier's PDF reader unpacks. That reader only unpacks
     * single Flate streams, within this total (GuardedRawDataParser). Inflated in small steps whose output is thrown
     * away, so a decompression bomb costs time here, not memory.
     *
     * @param string $raw   Raw PDF bytes.
     * @param int    $limit Counting stops once the total passes this; the caller refuses the file then anyway.
     * @return int
     */
    public static function inflatedStreamBytes(string $raw, int $limit): int
    {
        // The same bodies streamBodies() hands the hashing passes, delimited the same way: counting them with a
        // different delimiter meant the reserved allowance described streams other than the ones actually unpacked.
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
     * found them: each is [offset, length, enclosed text].
     *
     * Found with strpos(): once no end marker followed, that lazy regex rescanned the rest of the text from every later
     * start marker, quadratic on a crafted PDF full of them.
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
     * $text without its seal blocks, as preg_replace('/---BEGIN-SEAL---.*?---END-SEAL---/s', '', …) returned it.
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
     * Offset of the "endobj" closing an object whose body starts at $start. Past a stream it is the first "endobj"
     * after "endstream", since compressed bytes can contain that word (the same reasoning as in indexObjects()).
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
        if (preg_match('/>>\s*stream(\r\n|\n|\r)/', $pdf_raw, $sm, PREG_OFFSET_CAPTURE, $start) === 1 && $sm[0][1] < $end) {
            $data_end = strpos($pdf_raw, 'endstream', $sm[0][1] + strlen($sm[0][0]));
            $after    = $data_end === false ? false : strpos($pdf_raw, 'endobj', $data_end + 9);
            if ($after !== false) {
                return $after;
            }
        }
        return $end;
    }

    /**
     * Lists each page's /Contents object numbers in display order, walking the page tree from the document catalog.
     *
     * @param string $pdf_raw Raw PDF bytes.
     * @param array  $objects indexObjects() output.
     * @return array Page object number => list of /Contents object numbers, in page order.
     */
    public static function pageContents(string $pdf_raw, array $objects): array
    {
        // The last /Root wins, as with the last object definition: an incremental update appends a new trailer. Only the
        // last is kept while walking forward; preg_match_all() held every one, and a file can repeat it at will.
        $root    = null;
        $root_at = 0;
        while (preg_match('#/Root\s+(\d+)\s+0\s+R#', $pdf_raw, $rm, PREG_OFFSET_CAPTURE, $root_at) === 1) {
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
     * never takes more than $cap bytes: unlimited, gzuncompress() inflated a small crafted stream to gigabytes before any
     * caller could check the size. No warning on input that isn't such data; most streams a scan meets aren't, so false
     * is the normal outcome and the caller handles it.
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
        // PHP's own gzuncompress()/gzinflate() hand zlib one NUL byte more than the data (the string's terminator), which
        // now and then completes a stream. The same byte here keeps every result as it was, down to the hashes sealed by
        // earlier versions. Still unfinished after it, the data is incomplete, which those functions reject as well.
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
        // gzinflate() also gives up on some incomplete bare-DEFLATE data this accepts: in the round where its output
        // buffer fills just as the input runs out. Now that the output is known to fit $cap, its own answer decides,
        // so that raw fallback results stay what they were. (zlib data ends before that NUL, so it never gets there.)
        return $raw ? \FabricatorForms\Utils\Cast::withoutWarnings(static fn() => gzinflate($data)) : $out;
    }

    /**
     * A stream body inflated as zlib data, or else as bare DEFLATE from $raw_offset on, both within the stream cap.
     *
     * @param string $body         Raw stream bytes.
     * @param int    $raw_offset   Where the bare-DEFLATE attempt starts (2 skips a zlib header whose checksum failed).
     * @param bool   $retry_empty  Whether an empty or "0" zlib result also gets the bare-DEFLATE attempt, as the
     *                             `gzuncompress() ?: gzinflate()` idiom this replaces did.
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
     * Whether the PDF step can show an image of this type: JPEG, PNG, GIF and BMP, and WEBP where GD supports it (mPDF
     * converts WEBP with GD). Any other image, TIFF above all, is handled like a document: named in the PDF and attached.
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
     * Hashes every embedded font program (the /FontFile, /FontFile2 or /FontFile3 stream of each FontDescriptor),
     * sorted so object order doesn't matter. Generator seals these hashes and the verifier recomputes them, so both
     * call this one implementation.
     *
     * Returns exactly what these lazy regexes returned: every match of
     * '/\d+\s+\d+\s+obj\s*<<([\s\S]*?\/Type\s*\/FontDescriptor[\s\S]*?)>>\s*endobj/', then for each referenced
     * object N the first match of '/N\s+\d+\s+obj[\s\S]*?stream\r?\n([\s\S]*?)\r?\nendstream/'. The first rescanned
     * the rest of the file from every later object header once no FontDescriptor followed, and the second rescanned
     * the file once per font: quadratic on a crafted PDF, under a 256 MB backtrack limit. Here every scan only moves
     * forward, and the per-font lookups use indexes built in one pass each.
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
        // Walked in file order with one cursor instead of indexing every keyword in the file: the index cost several
        // times the file's size in memory. Each font's stream follows its header, so ascending order needs one pass.
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
        while (preg_match(self::OBJECT_HEADER, $pdf_raw, $m, PREG_OFFSET_CAPTURE, $pos)) {
            $pos        = $m[0][1] + strlen($m[0][0]);
            $body_start = $pos + strspn($pdf_raw, self::PCRE_SPACE, $pos);
            if (substr($pdf_raw, $body_start, 2) !== '<<') {
                continue;
            }
            $body_start += 2;
            $descriptor  = self::fontDescriptorEnd($pdf_raw, $body_start);
            $object_end  = $descriptor === null ? null : self::descriptorObjectEnd($pdf_raw, $descriptor);
            if ($object_end === null) {
                // No FontDescriptor, or no ">> endobj" after it, so none can follow a later header either.
                break;
            }
            [$body_end, $pos] = $object_end;

            // Only the first reference inside the body counts. A search result still ahead of this body is the leftmost
            // one from here too, so it is reused rather than rescanning the file for every descriptor.
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
     * @param string $pdf_raw Raw PDF bytes.
     * @param int    $from    Earliest start offset.
     * @return int|null
     */
    private static function fontDescriptorEnd(string $pdf_raw, int $from): ?int
    {
        $at = $from;
        while (($name = strpos($pdf_raw, '/FontDescriptor', $at)) !== false) {
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
     * digits. The replaced regex had no boundary before N, so "112 0 obj" matched N = 12 as well.
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
        while (count($found) < count($wanted) && preg_match(self::OBJECT_HEADER, $pdf_raw, $m, PREG_OFFSET_CAPTURE, $pos)) {
            $pos    = $m[0][1] + strlen($m[0][0]);
            $digits = $m[1][0];
            // A PHP int prints as at most 19 digits, so a longer suffix is never wanted. A suffix with a leading zero
            // stays a string key and so never matches an int, just as "012" never equalled the "12" in the regex.
            for ($len = min(strlen($digits), 20); $len >= 1; $len--) {
                $suffix = substr($digits, -$len);
                if (isset($wanted[$suffix]) && !isset($found[$suffix])) {
                    $found[$suffix] = $pos;
                }
            }
        }
        return $found;
    }

    /* streamKeywords(), positionsOf() and firstIndexAtOrAfter() are gone: indexing every keyword in the file cost
       several times the file's own size in memory, more than the check had reserved. nextStreamBody() walks the
       file with a cursor instead, in the same single pass and without the arrays. */

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
     * Generator seals these hashes and the verifier recomputes them, so both call this one implementation (each used
     * to keep its own copy, where a fix to only one would turn into false "tampered" or "verified" results). Content
     * streams are left out because they change between PASS 1 and PASS 2; verifyContentStreams() checks those.
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
     * Every stream body in the file, as [start, end] byte offsets, in one forward pass.
     *
     * Walking the file with a fresh strpos() per stream re-scanned to the end of the file on every round: once for
     * the line-ending form the file does not use, and again for each "endstream" it could not find. A crafted file of
     * a few hundred kilobytes took tens of seconds that way.
     *
     * Yielded one at a time rather than returned as a list: a file of many small streams holds one array entry per
     * stream, which measured several times the file's own size — more than the memory the check reserved for the
     * whole job, and enough for a crafted upload to end the request in a memory fatal instead of a refusal.
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
     * The first stream body at or after $from, as [start, end] byte offsets; null when the file holds no more.
     *
     * Scanning forward from a cursor rather than indexing every keyword first: a list of offsets costs several times
     * the file's own size in memory, which a crafted upload could turn into a fatal before the check ever reserved
     * the memory it planned to use. Each call resumes where the last one ended, so a full walk stays linear.
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
     * Generator seals these and the verifier recomputes them, so both use this one implementation.
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
     * Reports whether decoded bytes look like drawing operators. The Generator uses this same function when sealing,
     * so every stream it hashed is recognised identically here.
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
     * A stream is checked when it is a page's /Contents, a Form XObject or a tiling pattern (all drawn, whatever the
     * heuristic says about their first bytes), or when it looks like drawing operators. Exactly one stream may carry
     * the seal, and it must be one of the last page's /Contents. That stream is rebuilt into its PASS-1 form, and the
     * live streams must then equal the sealed hashes as a multiset: none unrecognised, none left over.
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
        $contents = $pages === [] ? [] : array_merge(...array_values($pages));
        $last     = $pages === [] ? [] : end($pages);

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
            $required = in_array($num, $contents, true)
                || preg_match('#/Subtype\s*/Form\b#', $obj['dict']) === 1
                || preg_match('#/Type\s*/Pattern\b#', $obj['dict']) === 1;
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
            if ($result['seal_obj'] === null && in_array($num, $last, true)) {
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

        // A seal page that failed to rebuild leaves its one PASS-1 hash unmatched, already reported as seal_page_mismatch
        // above; any other sealed stream still unmatched is missing from the PDF.
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
     * PASS 2 differs from PASS 1 only by the seal text lines and the state-only lines mPDF wrote around them, so
     * those are removed and the result must hash to a sealed value exactly. Nothing visible can hide in what is
     * removed: only lines that paint nothing qualify, and the removed text must be exactly this document's seal.
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
     * Tries every way of dropping 0..$before state-only lines ahead of the seal text and 0..$after after it, in the same
     * order as the nested loop it replaces, and returns the first sealed hash one of them produces.
     *
     * That loop re-joined and re-hashed the whole page for each of up to 17 x 17 combinations, so one crafted 64 MB
     * stream could hold a worker for minutes. Here each head is a hash context extended from the next-shorter head,
     * only the tails are hashed again per combination, and those tail bytes are bounded before any work starts.
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
        // Tail j is every line after the seal text minus its first j lines, i.e. a suffix of one joined string. Its size
        // is worked out from the line lengths, so an oversized tail is refused before it is ever joined or copied.
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
     * Records why the seal-page fingerprint could not be produced — without it, bail-outs are silent and indistinguishable from a legitimate pre-feature seal.
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
     * Not a general PDF text extractor — must only be deterministic and identical between Generator and Verificationpage, so it ignores anything the two could disagree about (cmaps, kerning, encoding).
     *
     * @param string $content Decompressed page content stream.
     * @return string SHA-256 of the normalized visible text.
     */
    public static function pageTextFingerprint(string $content): string
    {
        // Hand-rolled literal scan, not regex: PDF literals nest parens/escapes badly for regex, and this must match Verificationpage exactly.
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
                // Decode the escape rather than skipping it: skipping made differently-escaped literals fingerprint identically, narrowing detection on this page.
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

        // Drop the seal itself (verifier sees it, Generator's PASS 1 never did); NULs stripped first since mPDF writes UTF-16BE.
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
        // Backstop for formats getimagesizefromstring() couldn't pre-check.
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
        // Masking the low 3 bits coarsens channels so GD re-encoding jitter between the seal's two passes doesn't flip the hash.
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
