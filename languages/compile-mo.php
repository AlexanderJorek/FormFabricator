<?php
/**
 * Compiles a .po file to a binary .mo file. Build-time CLI tool only, not run by the plugin.
 */

if ($argc < 2) {
    fwrite(STDERR, "Usage: php compile-mo.php <file.po>\n");
    exit(1);
}

$poFile = $argv[1];
if (!file_exists($poFile)) {
    fwrite(STDERR, "File not found: $poFile\n");
    exit(1);
}

$moFile = preg_replace('/\.po$/', '.mo', $poFile);

// Plural entries are stored as "singular\0plural" keys with NUL-joined forms, per the MO binary format.
$strings = [];
$lines    = file($poFile, FILE_IGNORE_NEW_LINES);

$msgid        = null;
$msgidPlural  = null;
$msgstr       = null;
$msgstrPlural = []; // index => string

// $target tracks which piece a bare continuation "..." line belongs to:
// 'id', 'id_plural', 'str', or an int (plural index) for 'str[N]'.
$target = null;

$unescape = static function (string $s): string {
    return str_replace(['\\n', '\\t', '\\"', '\\\\'], ["\n", "\t", '"', '\\'], $s);
};

$flush = static function () use (&$msgid, &$msgidPlural, &$msgstr, &$msgstrPlural, &$strings): void {
    if ($msgid === null || $msgid === '') {
        return;
    }
    if ($msgidPlural !== null) {
        if (empty($msgstrPlural)) {
            return;
        }
        ksort($msgstrPlural);
        $strings[$msgid . "\0" . $msgidPlural] = implode("\0", $msgstrPlural);
        return;
    }
    if ($msgstr !== null) {
        $strings[$msgid] = $msgstr;
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
        continue;
    }

    if (str_starts_with($line, 'msgid_plural "')) {
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
        if ($target === 'id') {
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

// Build .mo binary (little-endian).
$magic    = 0x950412de;
$revision = 0;
$count    = count($strings);
$ofsOrig  = 28;            // offset of original strings table
$ofsTrans = $ofsOrig + $count * 8;
$ofsHash  = $ofsTrans + $count * 8;
$hashSize = 0;

$origTable  = '';
$transTable = '';
$origData   = '';
$transData  = '';
$origOffset  = $ofsHash + $hashSize * 4;
$transOffset = $origOffset;

// We need two passes: first collect all byte offsets.
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
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI-only build tool (stripped from the shipped package by build.ps1); writes to stdout, never to a web response.
echo "Compiled $count strings → $moFile\n";
