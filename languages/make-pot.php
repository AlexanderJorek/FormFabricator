<?php
/**
 * Regenerates languages/formfabricator.pot from the plugin source. Build-time CLI tool, stands in for
 * `wp i18n make-pot` (not installed in this dev environment); stripped from the release package by build.ps1.
 *
 * Usage:
 *   php languages/make-pot.php            rewrite languages/formfabricator.pot
 *   php languages/make-pot.php --check    exit 1 if the file on disk is out of date, write nothing
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

const FABPOT_DOMAIN = 'formfabricator';

// Argument shape of every gettext function, by index: which argument holds the singular text,
// the plural, the context, and the text domain.
const FABPOT_SPEC = [
    '__'         => ['text' => 0, 'domain' => 1],
    '_e'         => ['text' => 0, 'domain' => 1],
    'esc_html__' => ['text' => 0, 'domain' => 1],
    'esc_html_e' => ['text' => 0, 'domain' => 1],
    'esc_attr__' => ['text' => 0, 'domain' => 1],
    'esc_attr_e' => ['text' => 0, 'domain' => 1],
    '_x'         => ['text' => 0, 'context' => 1, 'domain' => 2],
    '_ex'        => ['text' => 0, 'context' => 1, 'domain' => 2],
    'esc_html_x' => ['text' => 0, 'context' => 1, 'domain' => 2],
    'esc_attr_x' => ['text' => 0, 'context' => 1, 'domain' => 2],
    '_n'         => ['text' => 0, 'plural' => 1, 'domain' => 3],
    '_nx'        => ['text' => 0, 'plural' => 1, 'context' => 3, 'domain' => 4],
    '_n_noop'    => ['text' => 0, 'plural' => 1, 'domain' => 2],
    '_nx_noop'   => ['text' => 0, 'plural' => 1, 'context' => 2, 'domain' => 3],
];

// Header fields WordPress itself makes translatable, with the comment WP-CLI writes for each.
const FABPOT_HEADER_FIELDS = [
    'Plugin Name' => 'Plugin Name of the plugin',
    'Plugin URI'  => 'Plugin URI of the plugin',
    'Description' => 'Description of the plugin',
    'Author'      => 'Author of the plugin',
    'Author URI'  => 'Author URI of the plugin',
];

/**
 * Returns the index of the nearest token in direction $dir that is neither whitespace nor a comment.
 *
 * @param array $tokens token_get_all() output.
 * @param int   $i      Index to start from.
 * @param int   $dir    +1 to scan forward, -1 to scan back.
 * @return int Token index, or -1 when the scan runs off the end.
 */
function fabpotSignificant(array $tokens, int $i, int $dir): int
{
    $count = count($tokens);
    while ($i >= 0 && $i < $count) {
        $token = $tokens[$i];
        if (!is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            return $i;
        }
        $i += $dir;
    }
    return -1;
}

/**
 * Decodes a PHP string-literal token to the value it would have at runtime.
 *
 * @param string $raw The literal including its surrounding quotes.
 * @return string Decoded value.
 */
function fabpotLiteral(string $raw): string
{
    $bs    = chr(92);
    $inner = substr($raw, 1, -1);
    if ($raw[0] === "'") {
        // Single quotes recognise exactly two escapes.
        return str_replace([$bs . "'", $bs . $bs], ["'", $bs], $inner);
    }
    // A double-quoted T_CONSTANT_ENCAPSED_STRING never contains interpolation; if it did, the
    // tokenizer would have split it into T_ENCAPSED_AND_WHITESPACE plus T_VARIABLE instead.
    return stripcslashes($inner);
}

/**
 * Splits a call's argument list into one token slice per argument.
 *
 * @param array $tokens token_get_all() output.
 * @param int   $paren  Index of the call's opening parenthesis.
 * @return array The arguments, and the index of the matching closing parenthesis.
 */
function fabpotArgs(array $tokens, int $paren): array
{
    $args    = [];
    $current = [];
    $depth   = 0;
    $count   = count($tokens);
    for ($i = $paren; $i < $count; $i++) {
        $token = $tokens[$i];
        $text  = is_array($token) ? $token[1] : $token;
        if ($text === '(' || $text === '[' || $text === '{') {
            $depth++;
            if ($depth === 1) {
                continue;
            }
        } elseif ($text === ')' || $text === ']' || $text === '}') {
            $depth--;
            if ($depth === 0) {
                if ($current !== []) {
                    $args[] = $current;
                }
                return [$args, $i];
            }
        } elseif ($text === ',' && $depth === 1) {
            $args[]  = $current;
            $current = [];
            continue;
        }
        $current[] = $token;
    }
    return [$args, $count - 1];
}

/**
 * Returns an argument's value when it is a plain string literal, or a concatenation of them.
 *
 * @param array $arg Token slice for one argument.
 * @return string|null Decoded value, or null when the argument is anything else.
 */
function fabpotArgValue(array $arg): ?string
{
    $parts  = [];
    $expect = 'string';
    foreach ($arg as $token) {
        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        if ($expect === 'string') {
            if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                return null;
            }
            $parts[] = fabpotLiteral($token[1]);
            $expect  = 'dot';
            continue;
        }
        if ($token !== '.') {
            return null;
        }
        $expect = 'string';
    }
    if ($parts === [] || $expect === 'string') {
        return null;
    }
    return implode('', $parts);
}

/**
 * Escapes a value for the inside of a PO double-quoted string.
 *
 * @param string $value Raw value.
 * @return string Escaped value.
 */
function fabpotEscape(string $value): string
{
    $bs = chr(92);
    return str_replace(
        [$bs, '"', "\n", "\t", "\r"],
        [$bs . $bs, $bs . '"', $bs . 'n', $bs . 't', $bs . 'r'],
        $value
    );
}

/**
 * Reports whether a string carries printf placeholders, which earns it a #, php-format flag.
 *
 * @param string $value Message text.
 * @return bool True when at least one real placeholder is present.
 */
function fabpotIsPhpFormat(string $value): bool
{
    // %% is an escaped percent sign, not a placeholder.
    $stripped = str_replace('%%', '', $value);
    return (bool) preg_match('~%(?:[0-9]+[$])?[-+ 0#]*[0-9]*(?:[.][0-9]+)?[bcdeEfFgGosuxX]~', $stripped);
}

/**
 * Collects every "translators:" comment in a file, keyed by the line the annotated call sits on.
 *
 * @param array $tokens token_get_all() output.
 * @return array Line number => comment text.
 */
function fabpotTranslatorComments(array $tokens): array
{
    $found = [];
    foreach ($tokens as $token) {
        if (!is_array($token) || !in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $text = preg_replace('~^(/[*]+|/{2,}|[#]+)~', '', trim($token[1]));
        $text = preg_replace('~[*]+/$~', '', (string) $text);
        $text = trim((string) $text);
        if (stripos($text, 'translators:') !== 0) {
            continue;
        }
        // A "//" comment token carries its own trailing newline, a block comment carries any it
        // spans; either way this lands on the line the following statement starts at.
        $found[$token[2] + substr_count($token[1], "\n")] = $text;
    }
    return $found;
}

/**
 * Reads every msgid from a PO catalogue, still escaped, joining wrapped continuation lines.
 *
 * @param string $path Absolute path to a .po file.
 * @return array Escaped msgid values; the empty header msgid is omitted.
 */
function fabpotPoMsgids(string $path): array
{
    $ids     = [];
    $current = null;
    $flush   = static function () use (&$ids, &$current): void {
        if ($current !== null && $current !== '') {
            $ids[] = $current;
        }
        $current = null;
    };

    foreach (explode("\n", (string) file_get_contents($path)) as $line) {
        $line = trim(rtrim($line, "\r"));
        if (strncmp($line, 'msgid "', 7) === 0) {
            $flush();
            $current = substr($line, 7, -1);
            continue;
        }
        // A bare quoted line continues whatever is being accumulated. Once msgstr (or anything
        // else) has flushed the msgid, $current is null and the msgstr's own continuations are
        // correctly ignored.
        if ($current !== null && strlen($line) >= 2 && $line[0] === '"' && substr($line, -1) === '"') {
            $current .= substr($line, 1, -1);
            continue;
        }
        $flush();
    }
    $flush();
    return $ids;
}

/**
 * Lists the plugin's own PHP files, in a stable order.
 *
 * @param string $root       Plugin root directory.
 * @param array  $skipDirs   Directory names to prune anywhere in the tree.
 * @param array  $notShipped Relative paths excluded from the package.
 * @return array Relative paths, separator-normalised and sorted.
 */
function fabpotSourceFiles(string $root, array $skipDirs, array $notShipped): array
{
    $bs   = chr(92);
    $walk = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            static function ($entry) use ($skipDirs): bool {
                if ($entry->isDir()) {
                    return !in_array($entry->getFilename(), $skipDirs, true);
                }
                return strtolower($entry->getExtension()) === 'php';
            }
        )
    );

    $files = [];
    foreach ($walk as $entry) {
        $path = str_replace($bs, '/', $entry->getPathname());
        $rel  = ltrim(substr($path, strlen(str_replace($bs, '/', $root))), '/');
        if (in_array($rel, $notShipped, true)) {
            continue;
        }
        $files[] = $rel;
    }
    sort($files, SORT_STRING);
    return $files;
}

$root      = str_replace(chr(92), '/', dirname(__DIR__));
$checkOnly = in_array('--check', array_slice($argv, 1), true);
$potPath   = $root . '/languages/formfabricator.pot';

// Mirrors build.ps1's $nestedExclude: these files are stripped from the release package, so
// their strings can never reach translate.wordpress.org and must not appear here either.
$notShipped = [
    'includes/Admin/FieldTestPage.php',
    'includes/Fields/_ExampleField.php',
    'languages/compile-mo.php',
    'languages/make-pot.php',
];
// '.git' is pruned by name; note this repository itself lives under a directory called .git, so
// the match is against the relative path only, never the absolute one.
$skipDirs = ['vendor', 'build', 'node_modules', '.git', '.claude', '.vscode'];

$entries  = [];
$warnings = ['nonliteral' => [], 'domain' => [], 'comment' => [], 'catalogue' => []];

foreach (fabpotSourceFiles($root, $skipDirs, $notShipped) as $rel) {
    $tokens   = token_get_all((string) file_get_contents($root . '/' . $rel));
    $notes = fabpotTranslatorComments($tokens);
    $count    = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        if (!is_array($token) || $token[0] !== T_STRING || !isset(FABPOT_SPEC[$token[1]])) {
            continue;
        }
        $prev = fabpotSignificant($tokens, $i - 1, -1);
        if ($prev >= 0 && is_array($tokens[$prev])) {
            $reject = [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW];
            if (in_array($tokens[$prev][0], $reject, true)) {
                continue;
            }
        }
        $open = fabpotSignificant($tokens, $i + 1, 1);
        if ($open < 0 || $tokens[$open] !== '(') {
            continue;
        }

        $spec = FABPOT_SPEC[$token[1]];
        $line = $token[2];
        $call = fabpotArgs($tokens, $open);
        $callArgs = $call[0];

        $text = fabpotArgValue($callArgs[$spec['text']] ?? []);
        if ($text === null || $text === '') {
            $warnings['nonliteral'][] = $rel . ':' . $line . ' - ' . $token[1]
                . '() text is not a plain string literal, so it cannot be extracted';
            continue;
        }

        $textDomain = array_key_exists($spec['domain'], $callArgs)
            ? fabpotArgValue($callArgs[$spec['domain']])
            : null;
        if ($textDomain !== null && $textDomain !== FABPOT_DOMAIN) {
            // Another package's text domain; not ours to translate.
            continue;
        }
        if ($textDomain === null) {
            $warnings['domain'][] = $rel . ':' . $line . ' - ' . $token[1]
                . '() has no literal text domain';
        }

        $context = isset($spec['context']) ? fabpotArgValue($callArgs[$spec['context']] ?? []) : null;
        $plural  = isset($spec['plural']) ? fabpotArgValue($callArgs[$spec['plural']] ?? []) : null;

        $key = ($context ?? '') . "\x04" . $text . "\x00" . ($plural ?? '');
        if (!isset($entries[$key])) {
            $entries[$key] = [
                'context'  => $context,
                'id'       => $text,
                'plural'   => $plural,
                'refs'     => [],
                'comments' => [],
                'group'    => 1,
                'headerAt' => 0,
            ];
        }
        $entries[$key]['refs'][] = [$rel, $line];
        // A translators: comment annotates exactly one call; consumed here so the next line's string doesn't inherit it too.
        foreach ([$line, $line - 1] as $probe) {
            if (isset($notes[$probe])) {
                $entries[$key]['comments'][$notes[$probe]] = true;
                unset($notes[$probe]);
                break;
            }
        }
    }
}

// ---- Plugin header fields, which WordPress translates through the same domain.
$mainSource    = (string) file_get_contents($root . '/formfabricator.php');
$headerBlock   = preg_match('~/[*][*].*?[*]/~s', $mainSource, $blockMatch) ? $blockMatch[0] : '';
$pluginVersion = 'unknown';
if (preg_match('~^[ \t*#@]*Version:[ \t]*(.+)$~mi', $headerBlock, $versionMatch)) {
    $pluginVersion = trim($versionMatch[1]);
}
$pluginLicense = 'GPL-3.0-or-later';
if (preg_match('~^[ \t*#@]*License:[ \t]*(.+)$~mi', $headerBlock, $licenseMatch)) {
    $pluginLicense = trim($licenseMatch[1]);
}
$author = 'Alexander Jorek';
if (preg_match('~^[ \t*#@]*Author:[ \t]*(.+)$~mi', $headerBlock, $authorMatch)) {
    $author = trim($authorMatch[1]);
}

$headerIndex = 0;
foreach (FABPOT_HEADER_FIELDS as $field => $label) {
    $headerIndex++;
    if (!preg_match('~^[ \t*#@]*' . preg_quote($field, '~') . ':[ \t]*(.+)$~mi', $headerBlock, $match)) {
        continue;
    }
    $value = trim($match[1]);
    if ($value === '') {
        continue;
    }
    $key = "\x04" . $value . "\x00";
    if (!isset($entries[$key])) {
        $entries[$key] = [
            'context'  => null,
            'id'       => $value,
            'plural'   => null,
            'refs'     => [],
            'comments' => [],
            'group'    => 0,
            'headerAt' => $headerIndex,
        ];
    }
    // Header entries sort first and keep their WP-CLI-style comment, even when the same text
    // also appears in the source (the plugin name does).
    $entries[$key]['group']    = 0;
    $entries[$key]['headerAt'] = $headerIndex;
    $entries[$key]['comments'] = [$label => true] + $entries[$key]['comments'];
    array_unshift($entries[$key]['refs'], ['formfabricator.php', 0]);
}

// ---- Normalise, then order deterministically so future diffs mean something.
foreach ($entries as $key => $entry) {
    $refs = [];
    foreach ($entry['refs'] as $ref) {
        $refs[$ref[0] . ':' . $ref[1]] = $ref;
    }
    $refs = array_values($refs);
    usort($refs, static function (array $a, array $b): int {
        return $a[0] === $b[0] ? $a[1] <=> $b[1] : strcmp($a[0], $b[0]);
    });
    $entries[$key]['refs'] = $refs;

    if ($entry['group'] === 1 && fabpotIsPhpFormat($entry['id']) && $entry['comments'] === []) {
        $shown = strlen($entry['id']) > 60 ? substr($entry['id'], 0, 57) . '...' : $entry['id'];
        $warnings['comment'][] = $refs[0][0] . ':' . $refs[0][1] . ' - "' . $shown
            . '" has placeholders but no translators: comment';
    }
}

uasort($entries, static function (array $a, array $b): int {
    if ($a['group'] !== $b['group']) {
        return $a['group'] <=> $b['group'];
    }
    if ($a['group'] === 0) {
        return $a['headerAt'] <=> $b['headerAt'];
    }
    if ($a['refs'][0][0] !== $b['refs'][0][0]) {
        return strcmp($a['refs'][0][0], $b['refs'][0][0]);
    }
    return $a['refs'][0][1] <=> $b['refs'][0][1];
});

// ---- Render.
$datePlaceholder = '@@POT_CREATION_DATE@@';

$lines   = [];
$lines[] = '# Copyright (C) ' . gmdate('Y') . ' ' . $author;
$lines[] = '# This file is distributed under the ' . $pluginLicense . '.';
$lines[] = 'msgid ""';
$lines[] = 'msgstr ""';
$lines[] = '"Project-Id-Version: FormFabricator ' . $pluginVersion . '\n"';
$lines[] = '"Report-Msgid-Bugs-To: https://wordpress.org/support/plugin/formfabricator\n"';
$lines[] = '"Last-Translator: FULL NAME <EMAIL@ADDRESS>\n"';
$lines[] = '"Language-Team: LANGUAGE <LL@li.org>\n"';
$lines[] = '"MIME-Version: 1.0\n"';
$lines[] = '"Content-Type: text/plain; charset=UTF-8\n"';
$lines[] = '"Content-Transfer-Encoding: 8bit\n"';
$lines[] = '"POT-Creation-Date: ' . $datePlaceholder . '\n"';
$lines[] = '"PO-Revision-Date: YEAR-MO-DA HO:MI+ZONE\n"';
$lines[] = '"X-Generator: FormFabricator languages/make-pot.php\n"';
$lines[] = '"X-Domain: ' . FABPOT_DOMAIN . '\n"';
$lines[] = '';

$refCount    = 0;
$renderedIds = [];
foreach ($entries as $entry) {
    foreach (array_keys($entry['comments']) as $note) {
        $lines[] = '#. ' . $note;
    }
    foreach ($entry['refs'] as $ref) {
        $lines[] = '#: ' . $ref[0] . ($ref[1] > 0 ? ':' . $ref[1] : '');
        $refCount++;
    }
    if (fabpotIsPhpFormat($entry['id'])) {
        $lines[] = '#, php-format';
    }
    if ($entry['context'] !== null) {
        $lines[] = 'msgctxt "' . fabpotEscape($entry['context']) . '"';
    }
    $escapedId               = fabpotEscape($entry['id']);
    $renderedIds[$escapedId] = true;
    $lines[]                 = 'msgid "' . $escapedId . '"';
    if ($entry['plural'] !== null) {
        $lines[] = 'msgid_plural "' . fabpotEscape($entry['plural']) . '"';
        $lines[] = 'msgstr[0] ""';
        $lines[] = 'msgstr[1] ""';
    } else {
        $lines[] = 'msgstr ""';
    }
    $lines[] = '';
}

$rendered = implode("\n", $lines);

// Keep the existing creation date whenever nothing else changed, so re-running the tool is a
// no-op and --check can never fail on a timestamp alone.
$existing = is_file($potPath) ? (string) file_get_contents($potPath) : '';
// Line-wise, not by regex: the timestamp line ends in a literal backslash-n, easy to mismatch and silently never match.
$existingLines = $existing === '' ? [] : explode("
", $existing);
foreach ($existingLines as $index => $existingLine) {
    if (strncmp($existingLine, '"POT-Creation-Date: ', 20) === 0) {
        $existingLines[$index] = '"POT-Creation-Date: ' . $datePlaceholder . chr(92) . 'n"';
        break;
    }
}
$existingNormalised = implode("
", $existingLines);
$upToDate = $existing !== '' && $existingNormalised === $rendered;
$final    = $upToDate ? $existing : str_replace($datePlaceholder, gmdate('Y-m-d') . 'T' . gmdate('H:i:sP'), $rendered);

// A .po carrying a msgid the .pot no longer has is dead weight compile-mo.php would bake into the .mo.
foreach (glob($root . '/languages/*.po') ?: [] as $poPath) {
    $poName = basename($poPath);
    foreach (fabpotPoMsgids($poPath) as $poId) {
        if (isset($renderedIds[$poId])) {
            continue;
        }
        $shown = strlen($poId) > 64 ? substr($poId, 0, 61) . '...' : $poId;
        $warnings['catalogue'][] = $poName . ' - msgid "' . $shown . '" is not in the .pot';
    }
}

$stringCount = count($entries);
foreach (['nonliteral', 'domain', 'comment', 'catalogue'] as $kind) {
    $list = $warnings[$kind];
    if ($list === []) {
        continue;
    }
    fwrite(STDERR, count($list) . ' warning(s) [' . $kind . ']:' . "\n");
    foreach (array_slice($list, 0, 10) as $one) {
        fwrite(STDERR, '  ' . $one . "\n");
    }
    if (count($list) > 10) {
        fwrite(STDERR, '  ... and ' . (count($list) - 10) . " more\n");
    }
}

$summary = $stringCount . ' strings, ' . $refCount . ' references';

if ($checkOnly) {
    if (!$upToDate) {
        fwrite(STDERR, "formfabricator.pot is OUT OF DATE - run: php languages/make-pot.php\n");
        exit(1);
    }
    if ($warnings['catalogue'] !== []) {
        fwrite(STDERR, count($warnings['catalogue']) . " stale .po entr(ies) - remove them, then recompile the .mo\n");
        exit(1);
    }
    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI-only build tool (stripped from the shipped package by build.ps1); writes to stdout, never to a web response.
    echo 'languages/ is up to date (' . $summary . ")\n";
    exit(0);
}

if ($upToDate) {
    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI-only build tool (stripped from the shipped package by build.ps1); writes to stdout, never to a web response.
    echo 'Unchanged: ' . $summary . "\n";
    exit(0);
}

file_put_contents($potPath, $final);
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI-only build tool (stripped from the shipped package by build.ps1); writes to stdout, never to a web response.
echo 'Wrote ' . $summary . ' -> ' . $potPath . "\n";
