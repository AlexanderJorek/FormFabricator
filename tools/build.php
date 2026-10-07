<?php

/**
 * The release build: runs every release gate, then builds a clean, WordPress.org-ready copy of the plugin into
 * build/formfabricator/, zips it to build/formfabricator.zip, verifies the archive and writes a CycloneDX SBOM next to
 * it. The working tree's vendor/ (with the dev tools) is never touched: production dependencies are installed into the
 * staged copy only. Runs on Windows, macOS and Linux; build.cmd and build.sh in the repository root start it.
 *
 * Usage:
 *   php tools/build.php                full release build
 *   php tools/build.php --skip-audit   offline: no composer audit, and no test database download (the integration
 *                                      suite is skipped with a warning when none is set up yet)
 *   php tools/build.php --setup        install the working tree's dev dependencies and the test database, then stop:
 *                                      the first thing to run on a fresh clone, and the way to catch vendor/ up after
 *                                      a pull that changed composer.lock
 *
 * The integration suite runs against the server named by WP_TESTS_DB_HOST (_NAME, _USER, _PASSWORD) when set, else, on
 * Windows, against a portable MariaDB (tools/testdb.php). Dev tooling, never shipped.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

// The archive is written and checked with ZipArchive. Many PHP installations ship the extension without loading it, so
// the build starts itself again with it loaded rather than asking for a php.ini change.
if (!extension_loaded('zip')) {
    if (getenv('FABBUILD_ZIP_RETRY') !== false) {
        fwrite(STDERR, 'The release build needs PHP\'s zip extension. Enable it in ' . (string) php_ini_loaded_file() . PHP_EOL);
        exit(1);
    }
    putenv('FABBUILD_ZIP_RETRY=1');
    // phpcs:ignore PHPCS_SecurityAudit.BadFunctions.SystemExecFunctions.WarnSystemExec -- restarts this script with the same arguments; no shell.
    $retry = proc_open([PHP_BINARY, '-d', 'extension=zip', __FILE__, ...array_slice($argv, 1)], [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes);
    $retryCode = is_resource($retry) ? proc_close($retry) : 1;
    exit((int) $retryCode);
}

require __DIR__ . '/build-lib.php';
require __DIR__ . '/testdb.php';

$fabbuildArgs = array_map('strtolower', array_slice($argv, 1));
$skipAudit    = (bool) array_intersect($fabbuildArgs, ['--skip-audit', '-skipaudit']);
$setupOnly    = (bool) array_intersect($fabbuildArgs, ['--setup', '-setup']);
$unknown      = array_diff($fabbuildArgs, ['--skip-audit', '-skipaudit', '--setup', '-setup']);
if ($unknown !== []) {
    fwrite(STDERR, 'Unknown option: ' . implode(' ', $unknown) . PHP_EOL . 'Usage: php tools/build.php [--skip-audit] [--setup]' . PHP_EOL);
    exit(2);
}

$root     = fabbuildSlashes(dirname(__DIR__));
$buildDir = $root . '/build';
$stageDir = $buildDir . '/formfabricator';
$zipPath  = $buildDir . '/formfabricator.zip';
$config   = require __DIR__ . '/build-config.php';

try {
    fabbuildMain($root, $buildDir, $stageDir, $zipPath, $config, $skipAudit, $setupOnly);
} catch (RuntimeException $e) {
    if ($e->getCode() !== FABBUILD_FAILED) {
        fwrite(STDERR, (string) $e . PHP_EOL);
    }
    exit(1);
}
exit(0);

/**
 * The whole build, in order: dev dependencies, release gates, staging, archive, verification, SBOM.
 *
 * @param array $config tools/build-config.php.
 */
function fabbuildMain(string $root, string $buildDir, string $stageDir, string $zipPath, array $config, bool $skipAudit, bool $setupOnly): void
{
    fabbuildRequireTools();
    if ($setupOnly) {
        fabbuildInstallDevDependencies($root);
        if (!fabtestdbExternal() && fabtestdbPortable()) {
            if (!fabtestdbInitialize(false)) {
                fabbuildFail('The test database could not be set up.');
            }
            fabbuildSay('  Test database ready in ' . fabtestdbHome(), 'green');
        } elseif (!fabtestdbExternal()) {
            fabbuildSay('  For the integration suite, set WP_TESTS_DB_HOST (and _NAME, _USER, _PASSWORD) to a MySQL or MariaDB server.', 'yellow');
        }
        fabbuildSay('');
        fabbuildSay('Dev environment ready. Run the build again to produce a release.', 'green');
        return;
    }
    fabbuildEnsureDevDependencies($root);
    fabbuildGates($root, $skipAudit);

    fabbuildSay('Cleaning previous build...', 'cyan');
    fabbuildRemove($buildDir);
    mkdir($stageDir, 0777, true);
    fabbuildStage($root, $stageDir, $config);
    $pinned = fabbuildHandVendored($root, $stageDir, $config['handVendored']);
    fabbuildTrimFonts($stageDir, $config['keepFonts']);
    fabbuildPruneVendor($stageDir, $config['vendorExclude']);
    fabbuildZip($stageDir, $zipPath);
    fabbuildVerify($stageDir, $zipPath, $config, $pinned);
    $sbomPath = fabbuildSbom($root, $buildDir, $config['handVendored']);

    fabbuildSay('');
    fabbuildSay('Done. Release build: ' . $zipPath, 'green');
    fabbuildSay('SBOM (not shipped): ' . $sbomPath, 'green');
    fabbuildSay('Unpacked copy for inspection: ' . $stageDir, 'green');
}

/**
 * Composer and npm on PATH, with a hint where to get them. PHP is the one running this.
 */
function fabbuildRequireTools(): void
{
    $tools = [
        'composer' => 'Install Composer from https://getcomposer.org/download/, then open a new shell so PATH is picked up.',
        'npm'      => 'Install Node.js 22.13 or newer (it includes npm) from https://nodejs.org/ -- the JS test suite (tests/js/) is a release gate.',
    ];
    foreach ($tools as $tool => $hint) {
        if (!fabbuildHasTool($tool)) {
            fabbuildSay('');
            fabbuildSay($tool . ' is not on PATH.', 'red');
            fabbuildFail('  ' . $hint);
        }
    }
}

function fabbuildInstallDevDependencies(string $root): void
{
    // A CLI php.ini may lack ext-gd/ext-fileinfo, which installing doesn't need; the phpunit gate checks for them.
    fabbuildSay('Installing dev dependencies (composer install)...', 'cyan');
    $result = fabbuildRun(
        fabbuildTool('composer', ['install', '--no-interaction', '--ignore-platform-req=ext-gd', '--ignore-platform-req=ext-fileinfo']),
        $root,
        [],
        false
    );
    if ($result['code'] !== 0) {
        fabbuildFail('composer install failed with exit code ' . $result['code'] . '.');
    }
}

/**
 * vendor/ is gitignored (except the hand-vendored packages), so a fresh clone has no dev tools; after a pull that
 * changed composer.lock it may be stale. Installs in either case: the versions in installed.json are compared with the
 * lock file, since timestamps can't tell.
 */
function fabbuildEnsureDevDependencies(string $root): void
{
    $installed = $root . '/vendor/composer/installed.json';
    if (!is_file($root . '/vendor/autoload.php') || !is_file($root . '/vendor/bin/phpcs') || !is_file($installed)) {
        fabbuildSay('No dev dependencies in vendor/ yet -- a clone starts without them.', 'yellow');
        fabbuildInstallDevDependencies($root);
        fabbuildSay('');
        return;
    }
    $lock = json_decode((string) file_get_contents($root . '/composer.lock'), true);
    $have = [];
    foreach ((array) (json_decode((string) file_get_contents($installed), true)['packages'] ?? []) as $package) {
        $have[$package['name']] = $package['version'];
    }
    $stale = [];
    foreach ([...($lock['packages'] ?? []), ...($lock['packages-dev'] ?? [])] as $package) {
        if (!isset($have[$package['name']])) {
            $stale[] = $package['name'] . ' ' . $package['version'] . ' is not installed';
        } elseif ($have[$package['name']] !== $package['version']) {
            $stale[] = $package['name'] . ' is ' . $have[$package['name']] . ', locked at ' . $package['version'];
        }
    }
    if ($stale !== []) {
        fabbuildSay('vendor/ no longer matches composer.lock:', 'yellow');
        foreach (array_slice($stale, 0, 5) as $line) {
            fabbuildSay('  ' . $line, 'yellow');
        }
        fabbuildInstallDevDependencies($root);
        fabbuildSay('');
    }
}

/**
 * Runs one release gate. Only the exit code counts; the tool's output is shown only when it fails.
 *
 * @param string[]              $command
 * @param array<string, string|null> $env
 */
function fabbuildGate(string $name, array $command, string $cwd, array $env = []): void
{
    fabbuildSay('  ' . $name . ' ... ', '', false);
    $started = microtime(true);
    $result  = fabbuildRun($command, $cwd, $env);
    if ($result['code'] !== 0) {
        fabbuildSay('FAILED', 'red');
        fabbuildSay(rtrim($result['output']), 'red');
        fabbuildFail('Release gate failed: ' . $name . ' (exit code ' . $result['code'] . ').');
    }
    fabbuildSay('ok (' . fabbuildDuration($started) . ')', 'green');
}

/**
 * The release gates (docs/CONTRIBUTING.md), before anything is staged.
 */
function fabbuildGates(string $root, bool $skipAudit): void
{
    fabbuildSay('Running release gates...', 'cyan');

    // Directory names inside the repository, never its absolute path: a repository can live under a directory called
    // .git (C:\.git\FormFabricator), which an absolute-path match would exclude whole.
    $sources = array_values(array_filter(
        fabbuildFiles($root, ['vendor', 'build', 'node_modules', '.git']),
        static fn(string $rel): bool => str_ends_with($rel, '.php')
    ));
    fabbuildSay('  php -l (' . count($sources) . ' files) ... ', '', false);
    $started = microtime(true);
    foreach ($sources as $rel) {
        $result = fabbuildRun([PHP_BINARY, '-l', $root . '/' . $rel]);
        if ($result['code'] !== 0) {
            fabbuildSay('FAILED', 'red');
            fabbuildSay(rtrim($result['output']), 'red');
            fabbuildFail('Release gate failed: php -l ' . $rel . '.');
        }
    }
    fabbuildSay('ok (' . fabbuildDuration($started) . ')', 'green');

    $phpcs = $root . '/vendor/bin/phpcs';
    // .phpcs.xml (picked up from the working directory) is the day-to-day PSR gate; errors fail, warnings don't.
    fabbuildGate('phpcs (.phpcs.xml)', [PHP_BINARY, $phpcs, '-q', '--runtime-set', 'ignore_warnings_on_exit', '1'], $root);
    // .phpcs-security.xml: its ERRORS fail the release, as WordPress.org's Plugin Check rejects the same ones. Warnings
    // (-n hides them) stay review material, since the security-audit heuristics flag any dynamic filesystem call.
    fabbuildGate('phpcs (.phpcs-security.xml, errors only)', [PHP_BINARY, $phpcs, '-q', '-n', '--standard=' . $root . '/.phpcs-security.xml'], $root);
    fabbuildGate('make-pot --check (languages/formfabricator.pot is current)', [PHP_BINARY, $root . '/languages/make-pot.php', '--check'], $root);

    // The unit and perf suites. Perf holds the pathological-PDF shapes ("Scanning untrusted PDF bytes"), so a scan that
    // turns quadratic stops the release.
    $missing = array_filter(['gd', 'fileinfo'], static fn(string $ext): bool => !extension_loaded($ext));
    if ($missing !== []) {
        fabbuildFail('Release gate failed: the tests need PHP\'s ' . implode(' and ', $missing) . ' extension(s), which every WordPress host has. Enable them in ' . (string) php_ini_loaded_file() . '.');
    }
    $phpunit = [PHP_BINARY, $root . '/vendor/bin/phpunit'];
    fabbuildGate('phpunit (unit + perf suites)', [...$phpunit, '-c', $root . '/tests/phpunit.xml.dist', '--testsuite', 'unit,perf', '--no-progress'], $root);

    // The JS suite (tests/js/). npm ci installs exactly what package-lock.json pins; --prefer-offline keeps a build
    // working from npm's cache without the network.
    fabbuildGate('npm ci (JS test dependencies)', fabbuildTool('npm', ['ci', '--prefer-offline', '--no-audit', '--no-fund']), $root);
    fabbuildGate('npm test (JS suite)', fabbuildTool('npm', ['test']), $root);

    fabbuildIntegrationGates($root, $phpunit, $skipAudit);

    if ($skipAudit) {
        fabbuildSay('  composer audit skipped (--skip-audit)', 'yellow');
    } else {
        // --no-dev: the gate is about what ships. Dev tooling advisories still show up in a plain `composer audit`.
        fabbuildGate('composer audit (known vulnerabilities in shipped packages)', fabbuildTool('composer', ['audit', '--locked', '--no-dev', '--no-interaction']), $root);
    }
}

/**
 * The WordPress integration suite, once as a single site and once as a multisite network, against the named server or
 * the portable one. Offline without a database set up yet, it is skipped rather than downloaded.
 *
 * @param string[] $phpunit
 */
function fabbuildIntegrationGates(string $root, array $phpunit, bool $offline): void
{
    $config = [...$phpunit, '-c', $root . '/tests/phpunit-integration.xml.dist', '--no-progress'];
    $iniDir = fabtestdbMysqliIniDir();
    // Through PHP_INI_SCAN_DIR, not -d: WordPress installs the test site in a child PHP process, which needs it too.
    $base = $iniDir !== null ? ['PHP_INI_SCAN_DIR' => $iniDir] : [];

    if (fabtestdbExternal()) {
        fabbuildGate('phpunit integration (single site, ' . getenv('WP_TESTS_DB_HOST') . ')', $config, $root, $base + ['WP_MULTISITE' => '0']);
        fabbuildGate('phpunit integration (multisite, ' . getenv('WP_TESTS_DB_HOST') . ')', $config, $root, $base + ['WP_MULTISITE' => '1']);
        return;
    }
    if (!fabtestdbPortable()) {
        $hint = 'Set WP_TESTS_DB_HOST (and _NAME, _USER, _PASSWORD) to a MySQL or MariaDB server whose database the tests may wipe, '
            . 'e.g. one started with: docker run -d -p 3306:3306 -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=wordpress_test mariadb:11.4';
        if ($offline) {
            fabbuildSay('  integration suite SKIPPED: no test database named. ' . $hint, 'yellow');
            return;
        }
        fabbuildFail('Release gate failed: the integration suite needs a database. ' . $hint);
    }
    if (!fabtestdbInitialize($offline)) {
        fabbuildSay('  integration suite SKIPPED: no test database yet, and --skip-audit (offline) does not download one.', 'yellow');
        fabbuildSay('  Run a full build or --setup once to set it up.', 'yellow');
        return;
    }
    $server = fabtestdbStart($iniDir);
    try {
        $env = $base + [
            'WP_TESTS_DB_HOST'     => '127.0.0.1:' . $server['port'],
            'WP_TESTS_DB_NAME'     => 'wordpress_test',
            'WP_TESTS_DB_USER'     => 'root',
            'WP_TESTS_DB_PASSWORD' => FABTESTDB_ROOT_PW,
        ];
        fabbuildGate('phpunit integration (single site)', $config, $root, $env + ['WP_MULTISITE' => '0']);
        fabbuildGate('phpunit integration (multisite)', $config, $root, $env + ['WP_MULTISITE' => '1']);
    } finally {
        fabtestdbStop($server);
    }
}

/**
 * Copies the plugin into the stage, leaves out what never ships, and installs the production dependencies there.
 *
 * @param array $config tools/build-config.php.
 */
function fabbuildStage(string $root, string $stageDir, array $config): void
{
    fabbuildSay('Copying plugin files...', 'cyan');
    foreach (scandir($root) ?: [] as $name) {
        if ($name !== '.' && $name !== '..' && !in_array($name, $config['exclude'], true)) {
            fabbuildCopy($root . '/' . $name, $stageDir . '/' . $name);
        }
    }
    foreach ($config['nestedExclude'] as $rel) {
        fabbuildRemove($stageDir . '/' . $rel);
    }
    // translate.wordpress.org manages translations, so .po/.mo files don't ship; the .pot source template does.
    foreach (fabbuildFiles($stageDir . '/languages') as $rel) {
        if (preg_match('~\.(po|mo)$~i', $rel)) {
            fabbuildRemove($stageDir . '/languages/' . $rel);
        }
    }

    fabbuildSay('Installing production-only dependencies (composer install --no-dev)...', 'cyan');
    // A local CLI may lack ext-gd/ext-fileinfo; WordPress hosts have them.
    $result = fabbuildRun(
        fabbuildTool('composer', ['install', '--no-dev', '--optimize-autoloader', '--no-interaction', '--ignore-platform-req=ext-gd', '--ignore-platform-req=ext-fileinfo']),
        $stageDir,
        [],
        false
    );
    if ($result['code'] !== 0) {
        fabbuildFail('composer install failed with exit code ' . $result['code'] . '.');
    }
}

/**
 * Checks each hand-vendored package against the SHA-256 list in its VERSION file (any edited, missing or extra file
 * stops the build), then copies it into the stage.
 *
 * @param array<int, array{dir: string, npm: string, license: string}> $packages
 * @return array<string, array<string, string>> Package dir => relative path => pinned SHA-256.
 */
function fabbuildHandVendored(string $root, string $stageDir, array $packages): array
{
    $all = [];
    foreach ($packages as $package) {
        fabbuildSay('Verifying manually-vendored ' . $package['npm'] . ' against its pinned hashes...', 'cyan');
        $dir    = $root . '/vendor/' . $package['dir'];
        $pinned = [];
        preg_match_all('/^\s*([0-9a-fA-F]{64})\s+(\S+)\s*$/m', (string) @file_get_contents($dir . '/VERSION'), $pins, PREG_SET_ORDER);
        foreach ($pins as $pin) {
            $pinned[$pin[2]] = strtolower($pin[1]);
        }
        $problems = [];
        foreach (fabbuildFiles($dir) as $rel) {
            if ($rel === 'VERSION') {
                continue;
            }
            if (!isset($pinned[$rel])) {
                $problems[] = $rel . ' has no pinned hash';
            } elseif (($actual = hash_file('sha256', $dir . '/' . $rel)) !== $pinned[$rel]) {
                $problems[] = $rel . ' is ' . $actual . ', pinned ' . $pinned[$rel];
            }
        }
        foreach (array_keys($pinned) as $rel) {
            if (!is_file($dir . '/' . $rel)) {
                $problems[] = $rel . ' is pinned but missing';
            }
        }
        if ($pinned === [] || $problems !== []) {
            fabbuildFail('vendor/' . $package['dir'] . ' does not match the SHA-256 list in its VERSION file: ' . ($pinned === [] ? 'no hashes pinned' : implode('; ', $problems)));
        }
        fabbuildSay('Copying manually-vendored ' . $package['npm'] . '...', 'cyan');
        fabbuildCopy($dir, $stageDir . '/vendor/' . $package['dir']);
        $all[$package['dir']] = $pinned;
    }
    return $all;
}

/**
 * Removes the mPDF fonts no PDF of this plugin selects (tools/build-config.php explains which stay). Only the staged
 * copy is trimmed.
 *
 * @param string[] $keep
 */
function fabbuildTrimFonts(string $stageDir, array $keep): void
{
    fabbuildSay('Trimming unused mPDF font files...', 'cyan');
    $fontsDir = $stageDir . '/vendor/mpdf/mpdf/ttfonts';
    if (!is_dir($fontsDir)) {
        // Fail here, where the reason is known, rather than at the size ceiling later.
        fabbuildFail('mPDF font directory not found at ' . $fontsDir . ' - the trim would silently ship the full font set.');
    }
    $files  = fabbuildFiles($fontsDir);
    $before = fabbuildBytes($fontsDir, $files);
    foreach ($files as $rel) {
        if (!in_array($rel, $keep, true)) {
            fabbuildRemove($fontsDir . '/' . $rel);
        }
    }
    $missing = array_filter($keep, static fn(string $font): bool => !is_file($fontsDir . '/' . $font));
    if ($missing !== []) {
        fabbuildFail('Fonts named in keepFonts are absent after the trim (upstream renamed them?): ' . implode(', ', $missing));
    }
    fabbuildSay('  ' . fabbuildMb($before) . ' -> ' . fabbuildMb(fabbuildBytes($fontsDir, fabbuildFiles($fontsDir))), 'cyan');
}

/**
 * Removes the dev tooling dependencies bundle in their own dist, and every hidden file in vendor/, which Plugin Check
 * rejects. In the plugin's own tree a hidden file fails verification instead.
 *
 * @param string[] $exclude
 */
function fabbuildPruneVendor(string $stageDir, array $exclude): void
{
    fabbuildSay('Removing dev-only tooling bundled inside dependencies...', 'cyan');
    foreach ($exclude as $rel) {
        fabbuildRemove($stageDir . '/' . $rel);
    }
    $hidden = static function (string $dir) use (&$hidden): void {
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $dir . '/' . $name;
            if ($name[0] === '.') {
                fabbuildRemove($path);
            } elseif (is_dir($path) && !is_link($path)) {
                $hidden($path);
            }
        }
    };
    $hidden($stageDir . '/vendor');
}

/**
 * Zips the stage under one formfabricator/ folder, with '/' separators (ZIP APPNOTE 4.4.17.1). Directory entries only
 * where a directory would otherwise vanish; WordPress's unzip_file() recreates every other one from the file paths.
 */
function fabbuildZip(string $stageDir, string $zipPath): void
{
    fabbuildSay('Creating zip...', 'cyan');
    fabbuildRemove($zipPath);
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
        fabbuildFail('Could not create ' . $zipPath);
    }
    foreach (fabbuildFiles($stageDir) as $rel) {
        $zip->addFile($stageDir . '/' . $rel, 'formfabricator/' . $rel);
    }
    foreach (fabbuildEmptyDirs($stageDir) as $rel) {
        $zip->addEmptyDir('formfabricator/' . $rel);
    }
    if (!$zip->close()) {
        fabbuildFail('Could not write ' . $zipPath);
    }
}

/**
 * Verifies the archive itself: the exclusion lists match exact names, so anything new would slip past them. A rejected
 * archive is deleted; the stage stays for inspection.
 *
 * @param array                                $config tools/build-config.php.
 * @param array<string, array<string, string>> $pinned From fabbuildHandVendored().
 */
function fabbuildVerify(string $stageDir, string $zipPath, array $config, array $pinned): void
{
    fabbuildSay('Verifying archive...', 'cyan');
    $violations = [];

    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
        fabbuildFail('Could not open ' . $zipPath . ' to verify it.');
    }
    $entries = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entries[] = (string) $zip->getNameIndex($i);
    }
    $zip->close();

    $backslashed = array_values(array_filter($entries, static fn(string $e): bool => str_contains($e, '\\')));
    if ($backslashed !== []) {
        $violations[] = count($backslashed) . " archive entries use a backslash separator (ZIP APPNOTE 4.4.17.1 requires '/'); first: " . $backslashed[0];
    }
    $roots = array_values(array_unique(array_map(static fn(string $e): string => explode('/', $e)[0], $entries)));
    if ($roots !== ['formfabricator']) {
        $violations[] = "archive must contain exactly one top-level folder 'formfabricator'; found: " . implode(', ', $roots);
    }

    // Every staged file in the archive under its own name, and nothing else (a skipped locked file would pass a count).
    $staged   = fabbuildFiles($stageDir);
    $ownFiles = array_values(array_filter($staged, static fn(string $rel): bool => !str_starts_with($rel, 'vendor/')));
    $expected = [];
    foreach ($staged as $rel) {
        $expected['formfabricator/' . $rel] = true;
    }
    foreach (fabbuildEmptyDirs($stageDir) as $rel) {
        $expected['formfabricator/' . $rel . '/'] = true;
    }
    $actual     = array_fill_keys($entries, true);
    $absent     = array_keys(array_diff_key($expected, $actual));
    $unexpected = array_keys(array_diff_key($actual, $expected));
    if ($absent !== []) {
        $violations[] = count($absent) . ' staged file(s) missing from the archive; first: ' . $absent[0];
    }
    if ($unexpected !== []) {
        $violations[] = count($unexpected) . ' archive entr(ies) match no staged file; first: ' . $unexpected[0];
    }

    // Dev/test material anywhere in the plugin's own tree; vendor/ is upstream's business and pruned separately.
    $dev  = '~(^|/)(tests?|testpdfs|fixtures|__tests__|\.github|node_modules|\.git|\.vscode|\.claude|tools|docs|phpunit(-[a-z]+)?\.xml(\.dist)?|\.phpunit\.cache|package(-lock)?\.json)(/|$)~i';
    $hits = array_values(array_filter($ownFiles, static fn(string $rel): bool => (bool) preg_match($dev, $rel)));
    if ($hits !== []) {
        $violations[] = 'dev/test material in package: ' . implode(', ', array_slice($hits, 0, 5));
    }
    // Hidden files, OS and editor debris, logs and backups, anywhere in the package.
    $junk = '~(^|/)(\.[^/]+|thumbs\.db|desktop\.ini|__macosx)(/|$)|\.(log|env|bak|swp|swo|tmp|orig|rej)$~i';
    $hits = array_values(array_filter($staged, static fn(string $rel): bool => (bool) preg_match($junk, $rel)));
    if ($hits !== []) {
        $violations[] = 'hidden, OS, editor, log or backup files in package: ' . implode(', ', array_slice($hits, 0, 5));
    }
    // Executables and scripts, which Plugin Check rejects.
    $scripts = '~\.(ps1|psm1|psd1|bat|cmd|sh|bash|exe|com|msi|scr|vbs)$~i';
    $hits    = array_values(array_filter($staged, static fn(string $rel): bool => (bool) preg_match($scripts, $rel)));
    if ($hits !== []) {
        $violations[] = 'executable or shell-script files in package: ' . implode(', ', array_slice($hits, 0, 5));
    }

    // The nested and translation exclusion lists are only believable if their targets are gone.
    foreach ($config['nestedExclude'] as $rel) {
        if (file_exists($stageDir . '/' . $rel)) {
            $violations[] = 'excluded file still present: ' . $rel;
        }
    }
    $translations = array_filter(fabbuildFiles($stageDir . '/languages'), static fn(string $rel): bool => (bool) preg_match('~\.(po|mo)$~i', $rel));
    if ($translations !== []) {
        $violations[] = count($translations) . ' .po/.mo files still in languages/ (translate.wordpress.org owns these)';
    }
    // The hand-vendored packages, which no dependency step would miss, against the same pinned lists.
    foreach ($pinned as $dir => $files) {
        foreach ($files as $rel => $sha) {
            $path = $stageDir . '/vendor/' . $dir . '/' . $rel;
            if (!is_file($path)) {
                $violations[] = 'vendor/' . $dir . '/' . $rel . ' is missing from the package';
            } elseif (hash_file('sha256', $path) !== $sha) {
                $violations[] = 'vendor/' . $dir . '/' . $rel . ' in the package differs from its pinned SHA-256';
            }
        }
    }

    // Size ceilings catch what nobody predicted: one for the plugin's own tree, one for the whole package.
    $ownBytes   = fabbuildBytes($stageDir, $ownFiles);
    $totalBytes = fabbuildBytes($stageDir, $staged);
    $zipBytes   = (int) filesize($zipPath);
    if ($ownBytes > 12 * 1048576) {
        $violations[] = 'plugin tree excluding vendor/ is ' . fabbuildMb($ownBytes) . ', over the 12 MB ceiling -- something large is shipping that probably should not be';
    }
    if ($totalBytes > 40 * 1048576) {
        $violations[] = 'staged package is ' . fabbuildMb($totalBytes) . ', over the 40 MB ceiling -- check that the mPDF font trim actually ran';
    }

    if ($violations !== []) {
        fabbuildRemove($zipPath);
        fabbuildSay('');
        fabbuildSay('Build REJECTED -- this package is not shippable:', 'red');
        foreach ($violations as $violation) {
            fabbuildSay('  - ' . $violation, 'red');
        }
        fabbuildFail('  (the archive has been deleted; ' . $stageDir . ' is left in place for inspection)');
    }

    // Not fatal: WordPress.org's plugin submission form has historically capped uploads around 10 MB, a limit this
    // script cannot verify.
    if ($zipBytes > 9 * 1048576) {
        fabbuildSay('  NOTE: archive is ' . fabbuildMb($zipBytes) . ', close to the ~10 MB WordPress.org submission limit', 'yellow');
    }
    fabbuildSay(sprintf("  %d entries, all reconciled against %d staged files, forward-slash separators, single 'formfabricator/' root", count($entries), count($staged)), 'grey');
    fabbuildSay('  ' . fabbuildMb($totalBytes) . ' staged (' . fabbuildMb($ownBytes) . ' excluding vendor/), archive ' . fabbuildMb($zipBytes), 'grey');
}

/**
 * Software bill of materials (NIST SSDF PS.3): CycloneDX 1.5 JSON next to the zip, never inside it. Lists what the
 * package ships: every Composer package in composer.lock's production set, the hand-vendored packages, and Font Awesome.
 *
 * @param array<int, array{dir: string, npm: string, license: string}> $packages
 * @return string The SBOM's path.
 */
function fabbuildSbom(string $root, string $buildDir, array $packages): string
{
    fabbuildSay('Writing SBOM...', 'cyan');
    $lock       = json_decode((string) file_get_contents($root . '/composer.lock'), true);
    $components = [];
    foreach ($lock['packages'] ?? [] as $package) {
        $component = [
            'type'     => 'library',
            'name'     => $package['name'],
            'version'  => $package['version'],
            'purl'     => 'pkg:composer/' . $package['name'] . '@' . $package['version'],
            'licenses' => array_map(static fn(string $id): array => ['license' => ['id' => $id]], (array) ($package['license'] ?? [])),
        ];
        if (!empty($package['dist']['url'])) {
            $component['externalReferences'] = [[
                'type'    => 'distribution',
                'url'     => $package['dist']['url'],
                'comment' => 'reference ' . ($package['dist']['reference'] ?? ''),
            ]];
        }
        $components[] = $component;
    }
    foreach ($packages as $package) {
        $text = (string) file_get_contents($root . '/vendor/' . $package['dir'] . '/VERSION');
        preg_match('/^' . preg_quote($package['npm'], '/') . '\s+([0-9.]+)/m', $text, $version);
        $version   = $version[1] ?? '';
        $component = [
            'type'     => 'library',
            'name'     => $package['npm'],
            'version'  => $version,
            'purl'     => 'pkg:npm/' . $package['npm'] . '@' . $version,
            'licenses' => [['license' => ['id' => $package['license']]]],
        ];
        // The npm tarball's integrity hash, recorded in VERSION; the shipped files were checked against its SHA-256 list.
        if (preg_match('~Tarball integrity:\s*sha512-([A-Za-z0-9+/=]+)~', $text, $integrity)) {
            $component['externalReferences'] = [[
                'type'   => 'distribution',
                'url'    => 'https://registry.npmjs.org/' . $package['npm'] . '/-/' . $package['npm'] . '-' . $version . '.tgz',
                'hashes' => [['alg' => 'SHA-512', 'content' => bin2hex((string) base64_decode($integrity[1], true))]],
            ]];
        }
        $components[] = $component;
    }
    preg_match('/Font Awesome Free ([0-9.]+)/', (string) file_get_contents($root . '/assets/vendor/fontawesome/css/all.min.css'), $fa);
    $components[] = [
        'type'     => 'library',
        'name'     => '@fortawesome/fontawesome-free',
        'version'  => $fa[1] ?? '',
        'purl'     => 'pkg:npm/%40fortawesome/fontawesome-free@' . ($fa[1] ?? ''),
        'licenses' => [['license' => ['id' => 'CC-BY-4.0']], ['license' => ['id' => 'OFL-1.1']], ['license' => ['id' => 'MIT']]],
    ];

    preg_match('/Version:\s*([0-9.]+)/', (string) file_get_contents($root . '/formfabricator.php'), $pluginVersion);
    $uuid    = bin2hex(random_bytes(16));
    $uuid[12] = '4';
    $uuid[16] = dechex(8 | (hexdec($uuid[16]) & 3));
    $sbom    = [
        'bomFormat'    => 'CycloneDX',
        'specVersion'  => '1.5',
        'serialNumber' => 'urn:uuid:' . implode('-', [substr($uuid, 0, 8), substr($uuid, 8, 4), substr($uuid, 12, 4), substr($uuid, 16, 4), substr($uuid, 20)]),
        'version'      => 1,
        'metadata'     => [
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'component' => [
                'type'     => 'application',
                'name'     => 'formfabricator',
                'version'  => $pluginVersion[1] ?? '',
                'licenses' => [['license' => ['id' => 'GPL-3.0-or-later']]],
            ],
        ],
        'components'   => $components,
    ];
    $path = $buildDir . '/formfabricator-sbom.cdx.json';
    file_put_contents($path, json_encode($sbom, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    return $path;
}
