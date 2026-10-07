<?php

/**
 * The E2E specs' way into the site, as WP-CLI would be: loads the installed WordPress, runs one command as the
 * administrator, and prints its result as JSON. Data is set up here, never through an HTTP endpoint of the site.
 *
 * Usage: php tests/e2e/site/wp.php '<json command>'
 *   {"do": "setup"}                                      seal key in Standard mode, as the first-run screen leaves it
 *   {"do": "form", "title": …, "fields": […], "notifications": […], "settings": {…}}   → {"id": …}
 *   {"do": "page", "title": …, "content": "…"}           → {"id": …, "url": …}
 *   {"do": "option", "name": …, "value": …}               update_option(); a null value deletes it
 *   {"do": "get-form", "id": …}                           → the form as saved
 *   {"do": "get-option", "name": …}                      → {"value": …}, null when unset
 *
 * Dev-only, never shipped.
 */

$command = json_decode((string) ($argv[1] ?? ''), true);
if (!is_array($command) || !isset($command['do'])) {
    fwrite(STDERR, "wp.php: pass one JSON command, e.g. '{\"do\":\"setup\"}'\n");
    exit(1);
}
$_SERVER['HTTP_HOST']   = (string) parse_url((string) (getenv('FABRICATOR_E2E_URL') ?: 'http://127.0.0.1:8899'), PHP_URL_HOST);
$_SERVER['REQUEST_URI'] = '/';
require dirname(__DIR__, 3) . '/vendor/roots/wordpress-no-content/wp-load.php';
wp_set_current_user((int) get_user_by('login', 'admin')->ID);

$fail = static function (string $message): void {
    fwrite(STDERR, 'wp.php: ' . $message . "\n");
    exit(1);
};

switch ($command['do']) {
    case 'setup':
        update_option('fabricator_forms_seal_encryption', 'disabled', false);
        update_option('fabricator_forms_seal_setup_done', true, false);
        \FabricatorForms\PDF\HashSeal::createInitialKey();
        // The backup download confirmed, as the first-run screen ends.
        delete_transient('fabricator_forms_seal_key_pending_download');
        $result = ['problem' => \FabricatorForms\PDF\HashSeal::activeKeyProblem()];
        break;
    case 'form':
        // Each field with its type's defaults under what the spec gives, as a field added from the builder's palette.
        $with_defaults = static function (array $fields) use (&$with_defaults): array {
            return array_map(static function (array $f) use ($with_defaults): array {
                $handler = \FabricatorForms\Fields\FieldRegistry::get((string) ($f['type'] ?? ''));
                $f       = array_merge($handler ? $handler->getDefaultConfig() : [], $f);
                if (isset($f['children'])) {
                    $f['children'] = $with_defaults((array) $f['children']);
                }
                return $f;
            }, $fields);
        };
        $id = \FabricatorForms\Form\FormModel::save([
            'title'         => (string) ($command['title'] ?? 'E2E form'),
            'fields'        => $with_defaults((array) ($command['fields'] ?? [])),
            'notifications' => (array) ($command['notifications'] ?? []),
            'settings'      => (array) ($command['settings'] ?? []),
        ], 0, true);
        is_wp_error($id) && $fail($id->get_error_message());
        $result = ['id' => $id];
        break;
    case 'page':
        $id = wp_insert_post([
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_title'   => (string) ($command['title'] ?? 'E2E page'),
            'post_content' => (string) ($command['content'] ?? ''),
        ], true);
        is_wp_error($id) && $fail($id->get_error_message());
        $result = ['id' => $id, 'url' => get_permalink($id)];
        break;
    case 'option':
        $command['value'] === null ? delete_option((string) $command['name']) : update_option((string) $command['name'], $command['value']);
        $result = ['ok' => true];
        break;
    case 'get-option':
        wp_cache_delete((string) $command['name'], 'options');
        $result = ['value' => get_option((string) $command['name'], null)];
        break;
    case 'get-form':
        $form = \FabricatorForms\Form\FormModel::get((int) $command['id']);
        $form || $fail('no form ' . (int) $command['id']);
        $result = ['id' => $form->id, 'title' => $form->title, 'fields' => $form->fields, 'notifications' => $form->notifications, 'settings' => $form->settings];
        break;
    default:
        $fail('unknown command ' . $command['do']);
}
echo wp_json_encode($result);
