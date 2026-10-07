<?php

/**
 * Helpers for the release build (tools/build.php) and its test database (tools/testdb.php): terminal output, running
 * tools without a shell, and file-tree operations. Plain functions with a fabbuild prefix, like languages/make-pot.php,
 * so the build needs nothing but PHP. Dev tooling, never shipped.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

/**
 * The code of the RuntimeException fabbuildFail() raises. The build's top level turns it into exit code 1 after its
 * finally blocks have run (a started test database is stopped); the reason has been printed already.
 */
const FABBUILD_FAILED = 70;

/**
 * Whether the terminal shows colours: a console, not a pipe or file, and on Windows only once VT processing is on.
 */
function fabbuildColours(): bool
{
    static $on = null;
    if ($on === null) {
        $on = stream_isatty(STDOUT) && getenv('NO_COLOR') === false
            && (PHP_OS_FAMILY !== 'Windows' || (function_exists('sapi_windows_vt100_support') && sapi_windows_vt100_support(STDOUT, true)));
    }
    return $on;
}

/**
 * Writes $text in a colour (cyan, green, yellow, red, grey or none), with a line break unless $newline is false.
 */
function fabbuildSay(string $text, string $colour = '', bool $newline = true): void
{
    $codes = ['cyan' => '36', 'green' => '32', 'yellow' => '33', 'red' => '31', 'grey' => '90'];
    if ($colour !== '' && isset($codes[$colour]) && fabbuildColours()) {
        $text = "\033[" . $codes[$colour] . 'm' . $text . "\033[0m";
    }
    fwrite(STDOUT, $text . ($newline ? PHP_EOL : ''));
}

/**
 * Prints why the build stops and stops it. Never returns.
 */
function fabbuildFail(string $reason): never
{
    fabbuildSay('');
    fabbuildSay($reason, 'red');
    throw new RuntimeException('Build failed.', (int) FABBUILD_FAILED);
}

/**
 * "12 s" under a minute, "1:51 min" above, as the build reports each gate's duration.
 */
function fabbuildDuration(float $started): string
{
    $seconds = microtime(true) - $started;
    if ($seconds < 60) {
        return max(1, (int) round($seconds)) . ' s';
    }
    return sprintf('%d:%02d min', intdiv((int) $seconds, 60), (int) $seconds % 60);
}

/**
 * The command line for a tool on PATH. On Windows, Composer and npm are batch files: run through cmd.exe by their full
 * path, since by bare name composer.bat looks for composer.phar in the working directory. Anything else starts directly.
 *
 * @param string   $tool Tool name as typed in a shell.
 * @param string[] $args Its arguments.
 * @return string[]
 */
function fabbuildTool(string $tool, array $args = []): array
{
    if (PHP_OS_FAMILY !== 'Windows' || !in_array($tool, ['composer', 'npm'], true)) {
        return [$tool, ...$args];
    }
    static $paths = [];
    if (!isset($paths[$tool])) {
        $paths[$tool] = $tool;
        $found = fabbuildRun(['where.exe', $tool]);
        foreach (preg_split('/\R/', $found['output']) ?: [] as $candidate) {
            if (preg_match('~\.(bat|cmd)$~i', trim($candidate))) {
                $paths[$tool] = trim($candidate);
                break;
            }
        }
    }
    return ['cmd', '/d', '/s', '/c', $paths[$tool], ...$args];
}

/**
 * Runs a command without a shell (an argument list, so nothing is re-parsed) and waits for it.
 *
 * @param string[]                  $command Program and arguments.
 * @param string|null               $cwd     Working directory; the current one when null.
 * @param array<string, string|null> $env     Variables to set, or with null to remove, on top of this process's own.
 * @param bool                      $capture True: the output is collected and returned; false: it goes to this terminal.
 * @return array{code: int, output: string}
 */
function fabbuildRun(array $command, ?string $cwd = null, array $env = [], bool $capture = true): array
{
    $environment = getenv();
    foreach ($env as $name => $value) {
        if ($value === null) {
            unset($environment[$name]);
        } else {
            $environment[$name] = $value;
        }
    }
    $log = null;
    if ($capture) {
        $log  = (string) tempnam(sys_get_temp_dir(), 'fabbuild');
        $pipes = [0 => ['pipe', 'r'], 1 => ['file', $log, 'w'], 2 => ['redirect', 1]];
    } else {
        $pipes = [0 => STDIN, 1 => STDOUT, 2 => STDERR];
    }
    $line = $command;
    $options = [];
    if (PHP_OS_FAMILY === 'Windows' && ($command[0] ?? '') === 'cmd') {
        // cmd.exe parses its own command line: /s /c "<command>" with each part quoted that needs it, so a batch file
        // under "C:\Program Files" stays one word. The arguments come from this script, never from input.
        $parts = array_map(
            static fn(string $part): string => preg_match('/[\s&()^%!,;=]/', $part) ? '"' . $part . '"' : $part,
            array_slice($command, 4)
        );
        $line    = 'cmd /d /s /c "' . implode(' ', $parts) . '"';
        $options = ['bypass_shell' => true];
    }
    // phpcs:ignore PHPCS_SecurityAudit.BadFunctions.SystemExecFunctions.WarnSystemExec -- dev build tool; an argument list from this script, never request input, run without a shell.
    $process = proc_open($line, $pipes, $handles, $cwd, $environment, $options);
    if (!is_resource($process)) {
        fabbuildFail('Could not start: ' . implode(' ', $command));
    }
    if (isset($handles[0])) {
        fclose($handles[0]);
    }
    $code   = proc_close($process);
    $output = '';
    if ($log !== null) {
        $output = (string) file_get_contents($log);
        unlink($log);
    }
    return ['code' => $code, 'output' => $output];
}

/**
 * Whether a tool answers on PATH.
 */
function fabbuildHasTool(string $tool): bool
{
    return fabbuildRun(fabbuildTool($tool, ['--version']))['code'] === 0;
}

/**
 * A path with forward slashes, so comparisons and archive names never depend on the platform's separator.
 */
function fabbuildSlashes(string $path): string
{
    return str_replace('\\', '/', $path);
}

/**
 * Every file below $dir, as paths relative to it with forward slashes, sorted. Links are not followed, and directories
 * named in $skip are not entered, at any depth.
 *
 * @param string[] $skip Directory names.
 * @return string[]
 */
function fabbuildFiles(string $dir, array $skip = []): array
{
    $files = [];
    $walk  = static function (string $base, string $rel) use (&$walk, &$files, $skip): void {
        foreach (scandir($base . ($rel === '' ? '' : '/' . $rel)) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $child = $rel === '' ? $name : $rel . '/' . $name;
            $path  = $base . '/' . $child;
            if (is_link($path)) {
                continue;
            }
            if (is_dir($path)) {
                if (!in_array($name, $skip, true)) {
                    $walk($base, $child);
                }
            } else {
                $files[] = $child;
            }
        }
    };
    $walk(fabbuildSlashes($dir), '');
    sort($files, SORT_STRING);
    return $files;
}

/**
 * Every directory below $dir that holds no file at any depth, relative, with forward slashes.
 *
 * @return string[]
 */
function fabbuildEmptyDirs(string $dir): array
{
    $dir   = fabbuildSlashes($dir);
    $empty = [];
    $walk  = static function (string $rel) use (&$walk, &$empty, $dir): bool {
        $holdsFile = false;
        foreach (scandir($dir . ($rel === '' ? '' : '/' . $rel)) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $child = $rel === '' ? $name : $rel . '/' . $name;
            $path  = $dir . '/' . $child;
            if (is_dir($path) && !is_link($path)) {
                $holdsFile = $walk($child) || $holdsFile;
            } else {
                $holdsFile = true;
            }
        }
        if (!$holdsFile && $rel !== '') {
            $empty[] = $rel;
        }
        return $holdsFile;
    };
    $walk('');
    sort($empty, SORT_STRING);
    return $empty;
}

/**
 * Copies a file or a whole directory. Links are skipped, so nothing outside the source reaches the copy.
 */
function fabbuildCopy(string $from, string $to): void
{
    if (is_link($from)) {
        return;
    }
    if (!is_dir($from)) {
        if (!is_dir(dirname($to))) {
            mkdir(dirname($to), 0777, true);
        }
        if (!copy($from, $to)) {
            fabbuildFail('Could not copy ' . $from . ' to ' . $to);
        }
        return;
    }
    if (!is_dir($to)) {
        mkdir($to, 0777, true);
    }
    foreach (scandir($from) ?: [] as $name) {
        if ($name !== '.' && $name !== '..') {
            fabbuildCopy($from . '/' . $name, $to . '/' . $name);
        }
    }
}

/**
 * Removes a file or a whole directory. A link is removed itself, never what it points to.
 */
function fabbuildRemove(string $path): void
{
    if (is_link($path) || is_file($path)) {
        // Windows refuses to delete a read-only file (git marks some of its objects so).
        if (PHP_OS_FAMILY === 'Windows') {
            @chmod($path, 0666);
        }
        if (!@unlink($path) && file_exists($path)) {
            fabbuildFail('Could not remove ' . $path);
        }
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $name) {
        if ($name !== '.' && $name !== '..') {
            fabbuildRemove($path . '/' . $name);
        }
    }
    if (!@rmdir($path) && is_dir($path)) {
        fabbuildFail('Could not remove ' . $path);
    }
}

/**
 * The total size of the given files below $base, in bytes.
 *
 * @param string[] $files Relative paths.
 */
function fabbuildBytes(string $base, array $files): int
{
    $sum = 0;
    foreach ($files as $rel) {
        $sum += (int) filesize($base . '/' . $rel);
    }
    return $sum;
}

/**
 * Bytes as megabytes with one decimal.
 */
function fabbuildMb(int $bytes): string
{
    return number_format($bytes / 1048576, 1) . ' MB';
}
