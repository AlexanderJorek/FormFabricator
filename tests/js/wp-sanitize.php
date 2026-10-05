<?php

/**
 * Runs WordPress's real sanitize_text_field() and sanitize_textarea_field() on a list of strings, for build-fixture.php.
 *
 * What the server compares in a condition rule is the value after these functions, and front.js mirrors them
 * (sanitizeLikeWp()). The unit stubs only approximate them, so this loads the actual functions from
 * vendor/roots/wordpress-no-content, in a process of its own: the fixture's Brain Monkey stubs would clash with them.
 *
 * Reads a JSON list of strings on stdin; writes a JSON list of [text, textarea] results. Dev-only, never shipped.
 */

define('ABSPATH', dirname(__DIR__, 2) . '/vendor/roots/wordpress-no-content/');
define('WPINC', 'wp-includes');

// The only site settings these functions read: the charset, and whether it is UTF-8.
function get_option($name, $default = false)
{
    return $name === 'blog_charset' ? 'UTF-8' : $default;
}

function is_utf8_charset($charset = null)
{
    return true;
}

function _canonical_charset($charset)
{
    return 'UTF-8';
}

require ABSPATH . WPINC . '/plugin.php';
require ABSPATH . WPINC . '/compat-utf8.php';
require ABSPATH . WPINC . '/utf8.php';
require ABSPATH . WPINC . '/formatting.php';
require ABSPATH . WPINC . '/kses.php';

$input  = json_decode((string) stream_get_contents(STDIN), true);
$output = [];
foreach (is_array($input) ? $input : [] as $value) {
    $output[] = [sanitize_text_field((string) $value), sanitize_textarea_field((string) $value)];
}
echo json_encode($output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
