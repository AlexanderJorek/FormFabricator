<?php

/**
 * Installs the E2E site from scratch: a fresh content folder, the run's tables (FABRICATOR_E2E_TABLE_PREFIX, e2e_ by
 * default) dropped and WordPress installed again,
 * the plugin linked in and activated, its seal key set up in Standard mode, and two administrators.
 *
 * Run by the Playwright web server before `php -S` starts. Reads WP_TESTS_DB_*, FABRICATOR_E2E_CONTENT (the content
 * folder) and FABRICATOR_E2E_PLUGIN (the folder holding formfabricator.php; the repository by default).
 */

const FABRICATOR_E2E_PASSWORD = 'e2e-password';

$root    = dirname(__DIR__, 3);
$content = rtrim((string) getenv('FABRICATOR_E2E_CONTENT'), '/\\');
$plugin  = rtrim((string) (getenv('FABRICATOR_E2E_PLUGIN') ?: $root), '/\\');
if ($content === '' || !is_file($plugin . '/formfabricator.php')) {
    fwrite(STDERR, "install.php: set FABRICATOR_E2E_CONTENT, and FABRICATOR_E2E_PLUGIN to a folder holding formfabricator.php\n");
    exit(1);
}

/**
 * Removes $path. A link (a symlink, or a junction on Windows) is removed itself, never followed: the plugin's folder in
 * the content folder is a link to the repository or the unpacked zip.
 */
$remove = static function (string $path) use (&$remove, &$same): void {
    clearstatcache();
    // PHP on Windows reports a junction as neither a link nor a directory; readlink() names its target there, and the
    // path itself for anything else.
    $target = @readlink($path);
    if (is_link($path) || ($target !== false && !$same($target, $path))) {
        // rmdir() takes a junction or a directory symlink away without touching what it points to.
        @rmdir($path) || @unlink($path);
        if (file_exists($path) || is_link($path)) {
            fwrite(STDERR, "install.php: could not remove the link $path\n");
            exit(1);
        }
        return;
    }
    if (is_dir($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $remove($path . DIRECTORY_SEPARATOR . $entry);
            }
        }
        rmdir($path);
    } elseif (file_exists($path)) {
        unlink($path);
    }
};

/**
 * Whether two spellings name the same place, as the file system compares them (case-insensitively on Windows).
 */
$same = static function (string $a, string $b): bool {
    $norm = static fn(string $p): string => rtrim(str_replace('\\', '/', $p), '/');
    return PHP_OS_FAMILY === 'Windows' ? strcasecmp($norm($a), $norm($b)) === 0 : $norm($a) === $norm($b);
};

$copy = static function (string $from, string $to) use (&$copy): void {
    mkdir($to, 0777, true);
    foreach (scandir($from) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        is_dir("$from/$entry") ? $copy("$from/$entry", "$to/$entry") : copy("$from/$entry", "$to/$entry");
    }
};

$link = static function (string $target, string $at): void {
    if (PHP_OS_FAMILY === 'Windows') {
        // A junction needs no administrator rights, a symlink does.
        exec('cmd /c mklink /J ' . escapeshellarg(str_replace('/', '\\', $at)) . ' ' . escapeshellarg(str_replace('/', '\\', $target)), $out, $code);
    } else {
        $code = symlink($target, $at) ? 0 : 1;
    }
    if ($code !== 0 || !is_file($at . '/formfabricator.php')) {
        fwrite(STDERR, "install.php: could not link $at to $target\n");
        exit(1);
    }
};

// ---- The content folder: theme, test mu-plugin, the plugin, nothing left from an earlier run ----
$remove($content);
mkdir($content . '/plugins', 0777, true);
$copy(__DIR__ . '/theme', $content . '/themes/fabricator-e2e');
$copy(__DIR__ . '/mu-plugin', $content . '/mu-plugins');
$link($plugin, $content . '/plugins/formfabricator');

// ---- WordPress finds its wp-config.php in the folder above, which holds no wp-settings.php ----
$wordpress = $root . '/vendor/roots/wordpress-no-content';
file_put_contents(
    dirname($wordpress) . '/wp-config.php',
    "<?php\n// Written by tests/e2e/site/install.php for the E2E site; the next install writes it again.\nrequire " . var_export(__DIR__ . '/wp-config.php', true) . ";\n"
);

// ---- A fresh install: the run's own tables dropped, the integration suite's left alone ----
$db = mysqli_init();
[$host, $port] = array_pad(explode(':', (string) (getenv('WP_TESTS_DB_HOST') ?: '127.0.0.1')), 2, null);
if (!@$db->real_connect($host, getenv('WP_TESTS_DB_USER') ?: 'root', (string) (getenv('WP_TESTS_DB_PASSWORD') ?: 'root'), '', (int) ($port ?: 3306))) {
    fwrite(STDERR, 'install.php: no database at ' . (getenv('WP_TESTS_DB_HOST') ?: '127.0.0.1') . "\n");
    exit(1);
}
$name = getenv('WP_TESTS_DB_NAME') ?: 'wordpress_test';
$db->query('CREATE DATABASE IF NOT EXISTS `' . $db->real_escape_string($name) . '`');
$db->select_db($name);
// Only this run's tables: LIKE's own wildcard "_" in the prefix is escaped, so e2e_ never reaches another run's.
$prefix = (string) (getenv('FABRICATOR_E2E_TABLE_PREFIX') ?: 'e2e_');
$tables = $db->query("SHOW TABLES LIKE '" . $db->real_escape_string(str_replace('_', '\\_', $prefix)) . "%'");
foreach ($tables ? $tables->fetch_all() : [] as [$table]) {
    $db->query('DROP TABLE `' . $db->real_escape_string($table) . '`');
}
$db->close();

$_SERVER['HTTP_HOST']   = (string) parse_url((string) (getenv('FABRICATOR_E2E_URL') ?: 'http://127.0.0.1:8899'), PHP_URL_HOST);
$_SERVER['REQUEST_URI'] = '/';
define('WP_INSTALLING', true);
require $wordpress . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

$installed = wp_install('FormFabricator E2E', 'admin', 'admin@example.org', false, '', FABRICATOR_E2E_PASSWORD);
wp_create_user('admin2', FABRICATOR_E2E_PASSWORD, 'admin2@example.org');
(new WP_User((int) get_user_by('login', 'admin2')->ID))->set_role('administrator');
wp_update_user(['ID' => (int) $installed['user_id'], 'display_name' => 'Ada Admin']);
wp_update_user(['ID' => (int) get_user_by('login', 'admin2')->ID, 'display_name' => 'Bob Admin']);
switch_theme('fabricator-e2e');
update_option('blogdescription', '');

$activated = activate_plugin('formfabricator/formfabricator.php');
if (is_wp_error($activated)) {
    fwrite(STDERR, 'install.php: activation failed: ' . $activated->get_error_message() . "\n");
    exit(1);
}

// The seal key, in a process of its own: this one ran as the installer, with WordPress's plugins not loaded.
// An argument list, not a command line: no shell rewrites the JSON's quotes.
$setup = proc_open([PHP_BINARY, __DIR__ . '/wp.php', '{"do":"setup"}'], [1 => ['pipe', 'w'], 2 => STDERR], $pipes);
$answer = is_resource($setup) ? (string) stream_get_contents($pipes[1]) : '';
if (!is_resource($setup) || proc_close($setup) !== 0 || json_decode($answer, true) !== ['problem' => '']) {
    fwrite(STDERR, 'install.php: the seal key setup failed: ' . $answer . "\n");
    exit(1);
}
echo "install.php: the E2E site is installed\n";
