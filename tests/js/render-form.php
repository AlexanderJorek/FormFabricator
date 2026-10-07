<?php

/**
 * Renders a form as the builder saved it, for tests/js/builder-conditions.test.js: the fields go through the save's own
 * FormEditor::sanitizeFields(), then FormRenderer::render() prints them as a page would, and
 * FormProcessor::resolveVisibility() names the fields the server hides for each set of posted values.
 *
 * Reads {"fields": [...], "posted": [{field_id: value, ...}, ...]} on stdin; writes {"html": "...", "hidden": [[ids], ...]}.
 * WordPress is stubbed as for build-fixture.php. Dev-only, never shipped.
 */

use Brain\Monkey;
use FabricatorForms\Admin\FormEditor;
use FabricatorForms\Form\FormProcessor;
use FabricatorForms\Form\FormRenderer;
use FabricatorForms\Tests\Support\FieldStubs;
use FabricatorForms\Tests\Support\Reflect;
use FabricatorForms\Utils\Assets;

require dirname(__DIR__) . '/bootstrap.php';

Monkey\setUp();
FieldStubs::install();
FieldStubs::registry();
// As in build-fixture.php: the page's enqueue is not this script's business; the suite hands front.js its globals.
Monkey\Functions\when('wp_localize_script')->justReturn(true);
Monkey\Functions\when('wp_add_inline_style')->justReturn(true);
Monkey\Functions\when('wp_add_inline_script')->justReturn(true);
(new ReflectionProperty(Assets::class, 'front_assets_done'))->setValue(null, true);

// Each field with its type's defaults under what the builder sent, as a field added from the palette carries them.
$withDefaults = static function (array $fields) use (&$withDefaults): array {
    return array_map(static function (array $f) use ($withDefaults): array {
        $f = array_merge(\FabricatorForms\Fields\FieldRegistry::get((string) $f['type'])->getDefaultConfig(), $f);
        if (isset($f['children'])) {
            $f['children'] = $withDefaults($f['children']);
        }
        return $f;
    }, $fields);
};
$input  = json_decode((string) stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$fields = FormEditor::sanitizeFields($withDefaults((array) ($input['fields'] ?? [])));
$hidden = [];
foreach ((array) ($input['posted'] ?? []) as $posted) {
    [$ids]    = Reflect::call(FormProcessor::class, 'resolveVisibility', $fields, (array) $posted, []);
    $hidden[] = array_map('strval', array_keys($ids));
}
$html = FormRenderer::render(0, [], $fields);

Monkey\tearDown();
echo json_encode(['html' => $html, 'hidden' => $hidden], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
