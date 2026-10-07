<?php
/**
 * Compiles a .po file to a binary .mo file. Build-time CLI tool only, not run by the plugin.
 */

// Build-time only. The release build strips this file from the package, but a repository checked out
// directly into wp-content/plugins would otherwise leave it reachable over HTTP.
if (PHP_SAPI !== 'cli') {
    exit(1);
}

if ($argc < 2) {
    fwrite(STDERR, "Usage: php compile-mo.php <file.po>\n");
    exit(1);
}

$poFile = $argv[1];
if (!file_exists($poFile)) {
    fwrite(STDERR, "File not found: $poFile\n");
    exit(1);
}

// Refused rather than derived: with no ".po" suffix to replace, the output path would equal the input, and the binary
// .mo would be written over the source file.
if (!str_ends_with($poFile, '.po')) {
    fwrite(STDERR, "Not a .po file: $poFile\n");
    exit(1);
}
$moFile = substr($poFile, 0, -3) . '.mo';

// Plural entries are stored as "singular\0plural" keys with NUL-joined forms, per the MO binary format.
$strings = [];
$lines    = file($poFile, FILE_IGNORE_NEW_LINES);

$msgid        = null;
$msgidPlural  = null;
$msgstr       = null;
$msgstrPlural = []; // index => string
// msgctxt keys an entry as "context\x04msgid" in the MO format; a fuzzy entry is a translator's draft and must not
// be compiled, or WordPress would show that draft as if it were finished.
$msgctxt      = null;
$fuzzy        = false;

// $target tracks which piece a bare continuation "..." line belongs to:
// 'id', 'id_plural', 'str', or an int (plural index) for 'str[N]'.
$target = null;

// One left-to-right pass, each escape consumed once: sequential str_replace() calls would turn an escaped
// backslash followed by "n" (\\n in the .po) into a backslash plus a real newline.
$unescape = static function (string $s): string {
    return (string) preg_replace_callback(
        '/\\\\(.)/s',
        static fn(array $m): string => match ($m[1]) {
            'n'     => "\n",
            't'     => "\t",
            'r'     => "\r",
            '"'     => '"',
            '\\'    => '\\',
            default => '\\' . $m[1],
        },
        $s
    );
};

$flush = static function () use (&$msgid, &$msgidPlural, &$msgstr, &$msgstrPlural, &$msgctxt, &$fuzzy, &$strings): void {
    // Only null means "no entry pending"; msgid "" is the PO header and must stay first, as it is in the .po.
    if ($msgid === null || $fuzzy) {
        return;
    }
    // The header (msgid "") carries the file's metadata and is always kept; every other entry needs a real
    // translation. Compiling an empty msgstr made WordPress show that string as blank instead of the original.
    $is_header = $msgid === '' && $msgctxt === null;
    $key       = $msgctxt === null ? $msgid : $msgctxt . "\x04" . $msgid;
    if ($msgidPlural !== null) {
        if (empty($msgstrPlural) || implode('', $msgstrPlural) === '') {
            return;
        }
        ksort($msgstrPlural);
        $strings[$key . "\0" . $msgidPlural] = implode("\0", $msgstrPlural);
        return;
    }
    if ($msgstr !== null && ($msgstr !== '' || $is_header)) {
        $strings[$key] = $msgstr;
    }
};

foreach ($lines as $line) {
    $line = trim($line);

    if ($line === '' || str_starts_with($line, '#')) {
        $flush();
        $msgid        = null;
        $msgidPlural  = null;
        $msgstr       = null;
        $msgstrPlural = [];
        $target       = null;
        // A blank line ends an entry; a comment line introduces the next one, so its flags have to survive into it.
        if ($line === '') {
            $msgctxt = null;
            $fuzzy   = false;
        } elseif (preg_match('/^#,.*\bfuzzy\b/', $line) === 1) {
            $fuzzy = true;
        }
        continue;
    }

    if (str_starts_with($line, 'msgctxt "')) {
        $msgctxt = $unescape(substr($line, 9, -1));
        $target  = 'ctxt';
    } elseif (str_starts_with($line, 'msgid_plural "')) {
        $msgidPlural = $unescape(substr($line, 14, -1));
        $target      = 'id_plural';
    } elseif (str_starts_with($line, 'msgid "')) {
        $msgid  = $unescape(substr($line, 7, -1));
        $target = 'id';
    } elseif (preg_match('/^msgstr\[(\d+)\]\s+"(.*)"$/s', $line, $m)) {
        $idx                = (int) $m[1];
        $msgstrPlural[$idx] = $unescape($m[2]);
        $target             = $idx;
    } elseif (str_starts_with($line, 'msgstr "')) {
        $msgstr = $unescape(substr($line, 8, -1));
        $target = 'str';
    } elseif (str_starts_with($line, '"') && str_ends_with($line, '"')) {
        $chunk = $unescape(substr($line, 1, -1));
        if ($target === 'ctxt') {
            $msgctxt .= $chunk;
        } elseif ($target === 'id') {
            $msgid .= $chunk;
        } elseif ($target === 'id_plural') {
            $msgidPlural .= $chunk;
        } elseif ($target === 'str') {
            $msgstr .= $chunk;
        } elseif (is_int($target)) {
            $msgstrPlural[$target] .= $chunk;
        }
    }
}
// Flush last entry.
$flush();

// GNU MO requires the originals table sorted by msgid; SORT_STRING avoids PHP casting numeric-looking keys to int.
ksort($strings, SORT_STRING);

// Build .mo binary (little-endian).
$magic    = 0x950412de;
$revision = 0;
$count    = count($strings);
$ofsOrig  = 28;            // offset of original strings table
$ofsTrans = $ofsOrig + $count * 8;
$ofsHash  = $ofsTrans + $count * 8;
$hashSize = 0;

// Two passes: collect every byte offset first, then build the tables that point at them.
$origOffsets  = [];
$transOffsets = [];
$origPos  = 0;
$transPos = 0;

$keys   = array_keys($strings);
$values = array_values($strings);

foreach ($keys as $i => $key) {
    $origOffsets[$i]  = $origPos;
    $origPos         += strlen($key) + 1;
}
foreach ($values as $i => $val) {
    $transOffsets[$i]  = $transPos;
    $transPos         += strlen($val) + 1;
}

$origDataOffset  = $ofsHash + $hashSize * 4;
$transDataOffset = $origDataOffset + $origPos;

// Build the tables.
$origTable  = '';
$transTable = '';
foreach ($keys as $i => $key) {
    $origTable  .= pack('VV', strlen($key), $origDataOffset + $origOffsets[$i]);
}
foreach ($values as $i => $val) {
    $transTable .= pack('VV', strlen($val), $transDataOffset + $transOffsets[$i]);
}

$origData  = implode("\0", $keys) . "\0";
$transData = implode("\0", $values) . "\0";

$header = pack(
    'VVVVVVV',
    $magic,
    $revision,
    $count,
    $ofsOrig,
    $ofsTrans,
    $hashSize,
    $ofsHash
);

file_put_contents($moFile, $header . $origTable . $transTable . $origData . $transData);
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI-only build tool (stripped from the shipped package by the release build); writes to stdout, never to a web response.
echo "Compiled $count strings → $moFile\n";
