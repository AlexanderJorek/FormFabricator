<?php

/**
 * The database for the WordPress integration suite during a release build. Dev tooling, never shipped.
 *
 * - A server you name with WP_TESTS_DB_HOST (and _NAME, _USER, _PASSWORD) is used as it is, on any system: a local
 *   MySQL or MariaDB, or one in Docker. Every table in that database is dropped and recreated.
 * - Otherwise, on Windows, a portable MariaDB: downloaded once into %LOCALAPPDATA%\FormFabricator\test-db, outside the
 *   repository and shared by every clone, checked against the SHA-256 pinned below before anything is extracted,
 *   unpacked without debug symbols, link libraries and tools (about 40 MB instead of 290 MB), and run only during a
 *   build, on 127.0.0.1 and a free port, with --no-defaults.
 * - On macOS and Linux MariaDB publishes no portable build to pin, so a server has to be named.
 */

const FABTESTDB_VERSION = '11.4.13';
const FABTESTDB_SHA256  = 'd62986d433eeebfde218560b276103831604a61e929e87f1a17f5aebd80257e2';
// A localhost-only server that exists for the length of one build.
const FABTESTDB_ROOT_PW = 'root';

/**
 * Whether WP_TESTS_DB_HOST names a server to use instead of the portable one.
 */
function fabtestdbExternal(): bool
{
    return (string) getenv('WP_TESTS_DB_HOST') !== '';
}

/**
 * Whether this system gets the portable server.
 */
function fabtestdbPortable(): bool
{
    return PHP_OS_FAMILY === 'Windows';
}

function fabtestdbHome(): string
{
    return fabbuildSlashes((string) getenv('LOCALAPPDATA')) . '/FormFabricator/test-db';
}

function fabtestdbServerDir(): string
{
    return fabtestdbHome() . '/mariadb-' . FABTESTDB_VERSION;
}

function fabtestdbDataDir(): string
{
    return fabtestdbHome() . '/data';
}

function fabtestdbServerComplete(): bool
{
    foreach (['mariadbd.exe', 'mysqld.exe', 'mariadb-install-db.exe', 'server.dll'] as $file) {
        if (!is_file(fabtestdbServerDir() . '/bin/' . $file)) {
            return false;
        }
    }
    return true;
}

function fabtestdbReady(): bool
{
    return fabtestdbServerComplete() && is_dir(fabtestdbDataDir() . '/mysql');
}

/**
 * Downloads, verifies, unpacks and initializes the portable server if it is not there yet. True when it is ready;
 * false when it is missing and $offline forbids the download.
 */
function fabtestdbInitialize(bool $offline): bool
{
    if (fabtestdbReady()) {
        return true;
    }
    if ($offline) {
        return false;
    }
    if (!is_dir(fabtestdbHome())) {
        mkdir(fabtestdbHome(), 0777, true);
    }

    // The verified zip stays until the database is initialized, so an interrupted or failed setup resumes without
    // downloading 91 MB again; a zip whose hash does not match is never used.
    $zip = fabtestdbHome() . '/mariadb-' . FABTESTDB_VERSION . '-winx64.zip';
    if (!fabtestdbServerComplete()) {
        if (!is_file($zip) || hash_file('sha256', $zip) !== FABTESTDB_SHA256) {
            fabbuildSay('  Downloading MariaDB ' . FABTESTDB_VERSION . ' for the integration suite (about 91 MB, once)...', 'cyan');
            $url     = 'https://downloads.mariadb.org/rest-api/mariadb/' . FABTESTDB_VERSION . '/mariadb-' . FABTESTDB_VERSION . '-winx64.zip';
            $context = stream_context_create(['http' => ['user_agent' => 'FormFabricator-build', 'follow_location' => 1]]);
            if (!@copy($url, $zip, $context)) {
                fabbuildFail('The MariaDB download failed (' . $url . '). PHP needs the openssl extension for it.');
            }
            if (hash_file('sha256', $zip) !== FABTESTDB_SHA256) {
                unlink($zip);
                fabbuildFail('The MariaDB download does not match its pinned SHA-256. Nothing was extracted.');
            }
        }
        // A server folder from an interrupted unpack may be missing files; unpack it whole again.
        fabbuildRemove(fabtestdbServerDir());
        fabtestdbExpand($zip, fabtestdbServerDir());
    }

    if (!is_dir(fabtestdbDataDir() . '/mysql')) {
        // A data folder left half-made by an interrupted run would make the installer refuse; start it over.
        fabbuildRemove(fabtestdbDataDir());
        fabbuildSay('  Initializing the test database...', 'cyan');
        $result = fabbuildRun([
            fabtestdbServerDir() . '/bin/mariadb-install-db.exe',
            '--datadir=' . fabtestdbDataDir(),
            '--password=' . FABTESTDB_ROOT_PW,
        ]);
        if ($result['code'] !== 0) {
            fabbuildSay($result['output'], 'red');
            fabbuildFail('mariadb-install-db failed with exit code ' . $result['code'] . '.');
        }
    }

    $ready = fabtestdbReady();
    if ($ready && is_file($zip)) {
        unlink($zip);
    }
    return $ready;
}

/**
 * Unpacks the server without its debug symbols, link libraries, headers and tools, refusing any entry that would land
 * outside $target.
 */
function fabtestdbExpand(string $zipPath, string $target): void
{
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        fabbuildFail('Could not open ' . $zipPath);
    }
    try {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (str_ends_with($name, '/') || preg_match('~\.(pdb|lib)$|/include/~i', $name)) {
                continue;
            }
            // From bin/ only the server (mariadbd.exe, and mysqld.exe for the initializer), the initializer and the DLLs.
            if (preg_match('~/bin/~i', $name) && !preg_match('~/(mariadbd|mysqld|mariadb-install-db)\.exe$|\.dll$~i', $name)) {
                continue;
            }
            // Drop the archive's own top folder (mariadb-<version>-winx64/).
            $relative = substr($name, (int) strpos($name, '/') + 1);
            if ($relative === '' || preg_match('~(^|/)\.\.(/|$)|^/|^[a-z]:~i', $relative)) {
                fabbuildFail('MariaDB archive entry escapes the target folder: ' . $name);
            }
            $dest = $target . '/' . $relative;
            if (!is_dir(dirname($dest))) {
                mkdir(dirname($dest), 0777, true);
            }
            $in = $zip->getStream($name);
            if ($in === false || file_put_contents($dest, $in) === false) {
                fabbuildFail('Could not extract ' . $name);
            }
            fclose($in);
        }
    } finally {
        $zip->close();
    }
}

/**
 * A folder for PHP_INI_SCAN_DIR, so every PHP process of the suite loads mysqli: including the child process in which
 * the WordPress test library installs the site, which a -d flag would not reach. Null when this PHP already loads it
 * (loading it twice prints a warning into the test output).
 */
function fabtestdbMysqliIniDir(): ?string
{
    if (extension_loaded('mysqli')) {
        return null;
    }
    $dir = fabbuildSlashes(sys_get_temp_dir()) . '/formfabricator-php-ini';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    file_put_contents($dir . '/mysqli.ini', 'extension=mysqli' . PHP_EOL);
    return $dir;
}

function fabtestdbFreePort(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if ($socket === false) {
        fabbuildFail('No free local port for the test database: ' . $error);
    }
    $name = (string) stream_socket_get_name($socket, false);
    fclose($socket);
    return (int) substr($name, (int) strrpos($name, ':') + 1);
}

/**
 * Runs a PHP snippet with mysqli available, for the statements the build sends the server itself.
 */
function fabtestdbPhp(string $code, ?string $iniDir): int
{
    return fabbuildRun([PHP_BINARY, '-r', $code], null, $iniDir !== null ? ['PHP_INI_SCAN_DIR' => $iniDir] : [])['code'];
}

/**
 * Starts the portable server on a free localhost port, waits until it answers, and creates the test database.
 *
 * @return array{process: resource, port: int, iniDir: ?string}
 */
function fabtestdbStart(?string $iniDir): array
{
    fabbuildSay('  starting the test database ... ', '', false);
    $started = microtime(true);
    $port    = fabtestdbFreePort();
    $log     = fabtestdbHome() . '/server.log';
    $command = [
        fabtestdbServerDir() . '/bin/mariadbd.exe',
        // Must come first: no my.ini on this machine may change this server.
        '--no-defaults',
        '--datadir=' . fabtestdbDataDir(),
        '--port=' . $port,
        '--bind-address=127.0.0.1',
        // The default 100 MB redo log would be most of the data folder's size.
        '--innodb-log-file-size=8M',
        '--innodb-buffer-pool-size=64M',
        '--console',
    ];
    // phpcs:ignore PHPCS_SecurityAudit.BadFunctions.SystemExecFunctions.WarnSystemExec -- dev build tool; a fixed argument list, no shell.
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $log, 'w'], 2 => ['redirect', 1]], $pipes);
    if (!is_resource($process)) {
        fabbuildFail('Could not start the test database.');
    }
    fclose($pipes[0]);
    $server = ['process' => $process, 'port' => $port, 'iniDir' => $iniDir];

    $connect  = '$m = @mysqli_connect("127.0.0.1", "root", "' . FABTESTDB_ROOT_PW . '", "", ' . $port . '); exit($m ? 0 : 1);';
    $deadline = microtime(true) + 60;
    $ready    = false;
    while (microtime(true) < $deadline && proc_get_status($process)['running']) {
        if (fabtestdbPhp($connect, $iniDir) === 0) {
            $ready = true;
            break;
        }
        usleep(500000);
    }
    if (!$ready) {
        proc_terminate($process, 9);
        fabbuildSay('FAILED', 'red');
        $lines = file($log, FILE_IGNORE_NEW_LINES) ?: [];
        fabbuildSay('  ' . implode(PHP_EOL . '  ', array_slice($lines, -20)), 'red');
        fabbuildFail('The test database did not start (log: ' . $log . ').');
    }

    $create = '$m = mysqli_connect("127.0.0.1", "root", "' . FABTESTDB_ROOT_PW . '", "", ' . $port . '); exit(mysqli_query($m, "CREATE DATABASE IF NOT EXISTS wordpress_test") ? 0 : 1);';
    if (fabtestdbPhp($create, $iniDir) !== 0) {
        fabbuildSay('FAILED', 'red');
        fabtestdbStop($server);
        fabbuildFail('Could not create the wordpress_test database.');
    }

    fabbuildSay('ok (port ' . $port . ', ' . fabbuildDuration($started) . ')', 'green');
    return $server;
}

/**
 * Shuts the portable server down cleanly (SHUTDOWN, so InnoDB needs no crash recovery next time), killing it only if it
 * does not stop within 30 seconds.
 *
 * @param array{process: resource, port: int, iniDir: ?string} $server
 */
function fabtestdbStop(array $server): void
{
    if (!is_resource($server['process']) || !proc_get_status($server['process'])['running']) {
        return;
    }
    fabbuildSay('  stopping the test database ... ', '', false);
    fabtestdbPhp('$m = @mysqli_connect("127.0.0.1", "root", "' . FABTESTDB_ROOT_PW . '", "", ' . $server['port'] . '); if ($m) { @mysqli_query($m, "SHUTDOWN"); }', $server['iniDir']);
    $deadline = microtime(true) + 30;
    while (microtime(true) < $deadline && proc_get_status($server['process'])['running']) {
        usleep(200000);
    }
    if (proc_get_status($server['process'])['running']) {
        proc_terminate($server['process'], 9);
        fabbuildSay('killed (it did not shut down within 30 s)', 'yellow');
    } else {
        fabbuildSay('ok', 'green');
    }
    proc_close($server['process']);
}
