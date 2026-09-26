<?php

/**
 * Writes tests/js/.generated/fixture.json: what the PHP side hands front.js, for the Node test suite.
 *
 * - localization: Assets::frontLocalization(), the window.FabricatorForms object.
 * - globals:      Assets::frontFieldAssets()['globals'], every field's validators, empty checks and inits.
 * - conditionFixtures: real FormRenderer markup, one field per type conditioned directly and through a group.
 * - parity:       FormProcessor::evalConditionRule()'s answer for every value × rule value × operator, so the JS
 *                 suite can check front.js decides each one the same way the server does.
 *
 * WordPress is stubbed as in the PHP unit suite (tests/Support/FieldStubs.php). Run by `npm test`; the output is
 * generated, not committed.
 */

use Brain\Monkey;
use FabricatorForms\Form\FormProcessor;
use FabricatorForms\Form\FormRenderer;
use FabricatorForms\Tests\Support\FieldStubs;
use FabricatorForms\Tests\Support\Reflect;
use FabricatorForms\Utils\Assets;

require dirname(__DIR__) . '/bootstrap.php';

Monkey\setUp();
FieldStubs::install();
FieldStubs::registry();

// Values chosen to hit case folding (German, Turkish, Greek, Scandinavian) and the edges of the number shape.
$values = [
    '', ' ', 'Ja', 'ja', 'JA', 'Ärzte', 'ärzte', 'ÄRZTE', 'Straße', 'STRASSE', 'Grüße',
    '10', '10.5', '-3', '+7', '1e3', '.5', '12abc', 'abc', '0', 'İstanbul', 'istanbul',
    'ÅNGSTRÖM', 'ångström', 'ΣΊΣΥΦΟΣ', 'σίσυφος', ' 10 ', '1,5',
];
$arrays     = [[], ['Ja'], ['ärzte', 'B'], ['', ''], ['10'], ['3', '4'], ['0'], ['-2.5']];
$arrayRules = ['Ja', 'ÄRZTE', '', '10', '1', '5', '-3', '0'];
$operators  = ['equals', 'not_equals', 'contains', 'not_contains', 'empty', 'not_empty', 'greater', 'less'];

$decide = static fn(string $op, string $ruleValue, mixed $submitted): bool => Reflect::call(
    FormProcessor::class,
    'evalConditionRule',
    ['field_id' => 'f', 'operator' => $op, 'value' => $ruleValue],
    ['f' => $submitted]
);

$scalar = [];
foreach ($values as $submitted) {
    foreach ($values as $ruleValue) {
        foreach ($operators as $op) {
            $scalar[] = [$submitted, $ruleValue, $op, $decide($op, $ruleValue, $submitted)];
        }
    }
}
$array = [];
foreach ($arrays as $submitted) {
    foreach ($arrayRules as $ruleValue) {
        foreach ($operators as $op) {
            $array[] = [$submitted, $ruleValue, $op, $decide($op, $ruleValue, $submitted)];
        }
    }
}

// One field of each specially handled type, shown only when "country" equals DE — directly and via a group.
$cond    = ['action' => 'show', 'match' => 'all', 'rules' => [['field_id' => 'country', 'operator' => 'equals', 'value' => 'DE']]];
$control = ['id' => 'country', 'type' => 'text', 'label' => 'Country'];
// GDPR has no 'required' here, as the builder saves it: it is mandatory by type, not by config.
$fields = [
    'text'    => ['id' => 'f_text', 'type' => 'text', 'label' => 'Text', 'required' => true],
    'consent' => ['id' => 'f_consent', 'type' => 'consent', 'label' => 'Consent', 'required' => true, 'consent_text' => 'I agree.'],
    'gdpr'    => ['id' => 'f_gdpr', 'type' => 'gdpr', 'label' => 'Privacy', 'privacy_policy_url' => 'https://example.com/privacy'],
    'captcha' => ['id' => 'f_captcha', 'type' => 'captcha', 'label' => 'CAPTCHA', 'required' => true],
];
$conditionFixtures = [];
foreach ($fields as $type => $field) {
    $group = ['id' => 'g_' . $type, 'type' => 'group', 'label' => 'Group', 'conditions' => $cond, 'children' => [$field]];
    $conditionFixtures[$type] = [
        'direct' => Reflect::call(FormRenderer::class, 'renderFields', [$control, $field + ['conditions' => $cond]]),
        'group'  => Reflect::call(FormRenderer::class, 'renderFields', [$control, $group]),
    ];
}

$fixture = [
    'localization'      => Assets::frontLocalization(),
    'globals'           => Assets::frontFieldAssets()['globals'],
    'conditionFixtures' => $conditionFixtures,
    'parity'            => ['scalar' => $scalar, 'array' => $array],
];

Monkey\tearDown();

$dir = __DIR__ . '/.generated';
if (!is_dir($dir) && !mkdir($dir, 0777, true)) {
    fwrite(STDERR, "build-fixture: cannot create $dir\n");
    exit(1);
}
$json = json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
file_put_contents($dir . '/fixture.json', $json . "\n");
echo 'build-fixture: ' . count($fixture['globals']) . ' globals, ' . count($conditionFixtures) . ' condition fixtures, '
    . (count($scalar) + count($array)) . " parity cases\n";
