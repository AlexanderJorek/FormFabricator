<?php

/**
 * Router for `php -S` serving the E2E site: WordPress's own files from the document root (vendor/roots/wordpress-no-content),
 * and /wp-content/ from the run's content folder (FABRICATOR_E2E_CONTENT), which lies outside it.
 *
 * Content is static only: no PHP runs from there, as on a hardened host. The plugin's protected PDF folder answers 403,
 * as the server rule the plugin's notice asks for would. Dev-only, never shipped.
 */

$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

if (!str_starts_with($path, '/wp-content/')) {
    return false; // WordPress's own files, PHP included, as the built-in server serves them
}

$content  = rtrim((string) getenv('FABRICATOR_E2E_CONTENT'), '/\\');
$relative = rawurldecode(substr($path, strlen('/wp-content/')));
$file     = realpath($content . '/' . $relative);
$allowed  = [realpath($content), realpath($content . '/plugins/formfabricator')];

$inside = false;
foreach (array_filter($allowed) as $root) {
    $inside = $inside || ($file !== false && str_starts_with($file, $root . DIRECTORY_SEPARATOR));
}
if (str_starts_with($relative, 'uploads/formfabricator/')) {
    http_response_code(403);
    return true;
}
$types = [
    'css' => 'text/css', 'js' => 'text/javascript', 'mjs' => 'text/javascript', 'json' => 'application/json',
    'map' => 'application/json', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif',
    'webp' => 'image/webp', 'svg' => 'image/svg+xml', 'ico' => 'image/x-icon', 'woff' => 'font/woff', 'woff2' => 'font/woff2',
    'ttf' => 'font/ttf', 'pdf' => 'application/pdf', 'txt' => 'text/plain', 'wasm' => 'application/wasm',
];
$extension = strtolower(pathinfo((string) $file, PATHINFO_EXTENSION));
if (!$inside || !is_file((string) $file) || !isset($types[$extension])) {
    http_response_code(404);
    return true;
}
header('Content-Type: ' . $types[$extension]);
header('Content-Length: ' . filesize($file));
readfile($file);
return true;
