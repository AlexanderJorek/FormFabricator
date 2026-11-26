<?php

/**
 * Publishes the last release build on WordPress.org: copies build/formfabricator/ into the plugin's Subversion (SVN)
 * repository as trunk/ and tags/<version>/, and .wordpress-org/ (banner, icon, screenshots) as assets/. A step of its
 * own, after a full build has passed; it builds and tests nothing itself. Dev tooling, never shipped.
 *
 * WordPress.org hosts every plugin in SVN, not git. The plugin's repository holds:
 *   trunk/            the plugin's files as the newest release has them
 *   tags/<version>/   a frozen copy of each release; readme.txt's "Stable tag" names the one sites download
 *   assets/           the plugin page's banner, icon and screenshots (never part of the plugin itself)
 *
 * What it does, in order:
 *   1. Checks the package: build/formfabricator/ exists, is newer than the last git commit, the working tree has no
 *      uncommitted changes, and the version in formfabricator.php matches readme.txt's Stable tag.
 *   2. Checks out the SVN repository into a working copy outside this repository (or updates the one it made
 *      before): trunk/ and assets/ in full, tags/ only as a list.
 *   3. Stops if tags/<version> exists already: a released version is never changed.
 *   4. Replaces trunk/ with the package, and assets/ with .wordpress-org/ when that folder exists, and shows what
 *      changed.
 *   5. Asks for the version to be typed as confirmation, then commits trunk/ and assets/ and copies trunk/ to
 *      tags/<version>/ on the server. SVN asks for the password the first time (the SVN password from the
 *      wordpress.org profile, "Account & Security"), and remembers it.
 *
 * Usage:
 *   php tools/svn-release.php            prepare, show the changes, ask, then publish
 *   php tools/svn-release.php --dry-run  prepare and show the changes only; nothing is sent
 *
 * Needs the `svn` command: TortoiseSVN with its "command line client tools" on Windows, `brew install subversion` on
 * macOS, `apt install subversion` on Linux.
 */

// A dev-only command-line tool: file and process access is its job, without WordPress's APIs. The security linter's
// warning-only sniffs for that are off here; its error-level checks still apply.
// phpcs:disable PHPCS_SecurityAudit.BadFunctions.CallbackFunctions.WarnCallbackFunctions
// phpcs:disable PHPCS_SecurityAudit.BadFunctions.FilesystemFunctions.WarnFilesystem
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open
// phpcs:disable WordPress.WP.AlternativeFunctions

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require __DIR__ . '/build-lib.php';

$fabsvnArgs = array_map('strtolower', array_slice($argv, 1));
$fabsvnDry  = in_array('--dry-run', $fabsvnArgs, true);
if (array_diff($fabsvnArgs, ['--dry-run']) !== []) {
    fwrite(STDERR, 'Usage: php tools/svn-release.php [--dry-run]' . PHP_EOL);
    exit(2);
}

$fabsvnRoot   = fabbuildSlashes(dirname(__DIR__));
$fabsvnConfig = (require __DIR__ . '/build-config.php')['svn'];

try {
    fabsvnMain($fabsvnRoot, $fabsvnConfig['url'], $fabsvnConfig['username'], $fabsvnDry);
} catch (RuntimeException $e) {
    if ($e->getCode() !== FABBUILD_FAILED) {
        fwrite(STDERR, (string) $e . PHP_EOL);
    }
    exit(1);
}
exit(0);

/**
 * The whole release, in the order the file's docblock lists.
 */
function fabsvnMain(string $root, string $url, string $username, bool $dryRun): void
{
    $package = $root . '/build/formfabricator';
    $version = fabsvnCheckPackage($root, $package);
    fabbuildSay('Package: FormFabricator ' . $version . ' (' . $package . ')', 'cyan');

    if (!fabbuildHasTool('svn')) {
        fabbuildFail(
            'The svn command is not on PATH. Install a Subversion client first:' . PHP_EOL
            . '  Windows: TortoiseSVN (tortoisesvn.net), and in its installer switch on "command line client tools".' . PHP_EOL
            . '  macOS:   brew install subversion' . PHP_EOL
            . '  Linux:   apt install subversion (or your system\'s package manager)' . PHP_EOL
            . 'Then open a new terminal, so it finds svn.'
        );
    }

    $wc = fabsvnHome() . '/formfabricator';
    fabsvnWorkingCopy($url, $wc);

    $tags = fabsvnRun(['svn', 'list', $url . '/tags'], null);
    if (in_array($version . '/', preg_split('/\R/', trim($tags)) ?: [], true)) {
        fabbuildFail(
            'tags/' . $version . ' exists on WordPress.org already. A released version is never changed: raise the version'
            . ' (formfabricator.php, readme.txt\'s Stable tag and changelog), run the full build, then this again.'
        );
    }

    fabbuildSay('Copying the package into trunk/ ...', 'cyan');
    fabsvnMirror($package, $wc . '/trunk', $wc);
    $areas  = ['trunk'];
    $assets = $root . '/.wordpress-org';
    if (is_dir($assets)) {
        fabbuildSay('Copying .wordpress-org/ into assets/ ...', 'cyan');
        if (!is_dir($wc . '/assets')) {
            fabsvnRun(['svn', 'mkdir', 'assets'], $wc);
        }
        fabsvnMirror($assets, $wc . '/assets', $wc);
        $areas[] = 'assets';
    }

    $status = trim(fabsvnRun(['svn', 'status', ...$areas], $wc));
    fabbuildSay('');
    if ($status === '') {
        fabbuildSay('trunk/ and assets/ already hold exactly this package; only the tag is missing.', 'yellow');
    } else {
        fabbuildSay('Changes for WordPress.org (A added, D deleted, M modified):', 'cyan');
        fabbuildSay($status, 'grey');
    }
    fabbuildSay('');
    if ($dryRun) {
        fabbuildSay('Dry run: nothing was sent. The prepared working copy is ' . $wc, 'green');
        return;
    }

    fabbuildSay('This publishes FormFabricator ' . $version . ' to every site that installs or updates it.', 'yellow');
    $typed = fabsvnAsk('Type the version (' . $version . ') to publish it, or anything else to stop: ');
    if ($typed !== $version) {
        fabbuildSay('Stopped; nothing was sent. The prepared working copy is ' . $wc, 'yellow');
        return;
    }

    fabbuildSay('Committing ' . implode('/ and ', $areas) . '/ ...', 'cyan');
    if ($status !== '') {
        fabsvnInteractive(['svn', 'commit', ...$areas, '-m', 'Release ' . $version, '--username', $username], $wc);
    }
    fabbuildSay('Tagging ' . $version . ' ...', 'cyan');
    fabsvnInteractive(
        ['svn', 'copy', $url . '/trunk', $url . '/tags/' . $version, '-m', 'Tag ' . $version, '--username', $username],
        $wc
    );

    fabbuildSay('');
    fabbuildSay('Published FormFabricator ' . $version . '.', 'green');
    fabbuildSay('  https://wordpress.org/plugins/formfabricator/ (the page and the download can take a few minutes to update)', 'green');
}

/**
 * The package's version, after checking it is the release build of the committed code and names one version
 * throughout.
 */
function fabsvnCheckPackage(string $root, string $package): string
{
    if (!is_file($package . '/formfabricator.php') || !is_file($root . '/build/formfabricator.zip')) {
        fabbuildFail('No release build in build/: run the full build first (build -y, or menu item 1).');
    }
    $dirty = rtrim(fabbuildRun(['git', 'status', '--porcelain'], $root)['output']);
    if ($dirty !== '') {
        fabbuildFail(
            'The working tree has uncommitted changes. Commit them, run the full build, then this again, so the release'
            . ' is exactly a commit:' . PHP_EOL . $dirty
        );
    }
    $committed = (int) trim(fabbuildRun(['git', 'log', '-1', '--format=%ct'], $root)['output']);
    if ((int) filemtime($root . '/build/formfabricator.zip') < $committed) {
        fabbuildFail('The release build is older than the last commit: run the full build again, then this.');
    }

    $header  = (string) file_get_contents($package . '/formfabricator.php');
    $readme  = (string) file_get_contents($package . '/readme.txt');
    $version = preg_match('/^\s*\*\s*Version:\s*(\S+)/m', $header, $m) ? $m[1] : '';
    $stable  = preg_match('/^Stable tag:\s*(\S+)/mi', $readme, $m) ? $m[1] : '';
    if ($version === '' || $version !== $stable) {
        fabbuildFail(
            'formfabricator.php says version "' . $version . '", readme.txt\'s Stable tag "' . $stable . '": they must'
            . ' be the same. Sites download the version the Stable tag names.'
        );
    }
    if (!preg_match('/^= ' . preg_quote($version, '/') . ' =$/m', $readme)) {
        fabbuildFail('readme.txt has no changelog entry "= ' . $version . ' =".');
    }
    return $version;
}

/**
 * Where the SVN working copy lives: beside the test database and Plugin Check, outside this repository.
 */
function fabsvnHome(): string
{
    if (PHP_OS_FAMILY === 'Windows') {
        return fabbuildSlashes((string) getenv('LOCALAPPDATA')) . '/FormFabricator/svn';
    }
    $cache = (string) (getenv('XDG_CACHE_HOME') ?: rtrim((string) getenv('HOME'), '/') . '/.cache');
    return $cache . '/formfabricator/svn';
}

/**
 * Checks out the repository into $wc, or brings the one there up to date with nothing of its own left in it: trunk/
 * and assets/ in full, tags/ as a list only (every release's full copy is not needed here).
 */
function fabsvnWorkingCopy(string $url, string $wc): void
{
    if (!is_dir($wc . '/.svn')) {
        fabbuildSay('Checking out ' . $url . ' into ' . $wc . ' ...', 'cyan');
        if (!is_dir(dirname($wc))) {
            mkdir(dirname($wc), 0777, true);
        }
        fabsvnRun(['svn', 'checkout', '--depth', 'immediates', $url, $wc], null);
        // assets/ may not exist yet; svn then skips it.
        fabsvnRun(['svn', 'update', '--set-depth', 'infinity', 'trunk', 'assets'], $wc);
        if (!is_dir($wc . '/trunk')) {
            fabbuildFail('The repository has no trunk/ folder: ' . $url . ' may not be this plugin\'s repository.');
        }
        return;
    }
    fabbuildSay('Updating the working copy in ' . $wc . ' ...', 'cyan');
    // A stopped or dry run left its changes here: start from what the server holds.
    fabsvnRun(['svn', 'revert', '--recursive', '.'], $wc);
    fabsvnRun(['svn', 'cleanup', '--remove-unversioned'], $wc);
    fabsvnRun(['svn', 'update'], $wc);
}

/**
 * Makes the versioned folder $to hold exactly the files of $from: every file copied, then each new path added and each
 * vanished one deleted in SVN.
 */
function fabsvnMirror(string $from, string $to, string $wc): void
{
    if (!is_dir($to)) {
        fabbuildFail($to . ' is not in the working copy. The repository should have trunk/ and assets/; check ' . $wc);
    }
    foreach (scandir($to) ?: [] as $name) {
        if ($name !== '.' && $name !== '..' && $name !== '.svn') {
            fabbuildRemove($to . '/' . $name);
        }
    }
    foreach (scandir($from) ?: [] as $name) {
        if ($name !== '.' && $name !== '..') {
            fabbuildCopy($from . '/' . $name, $to . '/' . $name);
        }
    }
    $relative = substr($to, strlen($wc) + 1);
    fabsvnRun(['svn', 'add', '--force', '--depth', 'infinity', $relative], $wc);
    $deleted = [];
    foreach (preg_split('/\R/', fabsvnRun(['svn', 'status', $relative], $wc)) ?: [] as $line) {
        // "!": versioned, gone from disk. A folder's files follow it in the list and go with it.
        if (!str_starts_with($line, '!')) {
            continue;
        }
        $path = fabbuildSlashes(trim(substr($line, 8)));
        foreach ($deleted as $folder) {
            if (str_starts_with($path, $folder . '/')) {
                continue 2;
            }
        }
        fabsvnRun(['svn', 'delete', '--force', $path], $wc);
        $deleted[] = $path;
    }
}

/**
 * Runs an svn command that needs no answer from the user, and returns its output; stops the release when it fails.
 *
 * @param string[] $command
 */
function fabsvnRun(array $command, ?string $cwd): string
{
    $result = fabbuildRun([...$command, '--non-interactive'], $cwd);
    if ($result['code'] !== 0) {
        fabbuildFail(implode(' ', $command) . ' failed:' . PHP_EOL . rtrim($result['output']));
    }
    return $result['output'];
}

/**
 * Runs an svn command on this terminal, so it can ask for the password itself.
 *
 * @param string[] $command
 */
function fabsvnInteractive(array $command, string $cwd): void
{
    // phpcs:ignore PHPCS_SecurityAudit.BadFunctions.SystemExecFunctions.WarnSystemExec -- dev release tool; an argument list from this script, run without a shell.
    $process = proc_open($command, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, $cwd);
    $code    = is_resource($process) ? proc_close($process) : 1;
    if ($code !== 0) {
        fabbuildFail(implode(' ', array_slice($command, 0, 2)) . ' failed (exit code ' . $code . '). Nothing after it was sent.');
    }
}

/**
 * One line typed on this terminal, trimmed.
 */
function fabsvnAsk(string $question): string
{
    fabbuildSay($question, '', false);
    $line = fgets(STDIN);
    return $line === false ? '' : trim($line);
}
