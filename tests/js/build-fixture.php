<?php

/**
 * Writes tests/js/.generated/fixture.json: what the PHP side hands front.js, for the Node test suite.
 *
 * - localization: Assets::frontLocalization(), the window.FabricatorForms object.
 * - globals:      Assets::frontFieldAssets()['globals'], every field's validators, empty checks and inits.
 * - conditionFixtures: real FormRenderer markup, one field per type conditioned directly and through a group.
 * - parity:       FormProcessor::evalConditionRule()'s answer for every value × rule value × operator, so the JS
 *                 suite can check front.js decides each one the same way the server does.
 * - cascades:     chained conditions as real FormRenderer markup, with the values posted and the ids
 *                 FormProcessor::resolveVisibility() hides, so the JS suite can check the whole cascade.
 * - altcha:       a Utils\Altcha challenge, its answer and the server's verdicts on it, for ALTCHA's own solver and
 *                 verifier to check against.
 * - debit:        each direct debit scheme's real markup, with typed account details and whether
 *                 DirectDebitField::validate() accepts each one, so the JS suite can check the browser's rules agree.
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

/**
 * WordPress's real sanitize_text_field() / sanitize_textarea_field() on each string (tests/js/wp-sanitize.php, run in a
 * process of its own), as [text, textarea] pairs.
 *
 * @param string[] $strings
 * @return array<int, array{0: string, 1: string}>
 */
$wpSanitize = static function (array $strings): array {
    $proc = proc_open([PHP_BINARY, __DIR__ . '/wp-sanitize.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) {
        fwrite(STDERR, "build-fixture: cannot run wp-sanitize.php\n");
        exit(1);
    }
    fwrite($pipes[0], json_encode(array_values($strings), JSON_THROW_ON_ERROR));
    fclose($pipes[0]);
    $out = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    if (proc_close($proc) !== 0) {
        fwrite(STDERR, "build-fixture: wp-sanitize.php failed\n");
        exit(1);
    }
    return json_decode($out, true, 512, JSON_THROW_ON_ERROR);
};

// What the server's rule sees is the submitted text after sanitize_text_field() (extractValue()); front.js mirrors that.
$sanitizedValues = array_combine($values, array_column($wpSanitize($values), 0));
$decide = static fn(string $op, string $ruleValue, mixed $submitted): bool => Reflect::call(
    FormProcessor::class,
    'evalConditionRule',
    ['field_id' => 'f', 'operator' => $op, 'value' => $ruleValue],
    ['f' => is_array($submitted) ? $submitted : $sanitizedValues[$submitted]]
);

// front.js's sanitizeLikeWp() against the real functions, on the inputs where they do most: tags, a lone "<", %xx
// octets, entities, tabs and runs of spaces, non-breaking spaces, and line breaks (kept only by the textarea version).
$sanitizeCorpus = [
    '  Ada   Lovelace ', "tab\there", 'a<b>bold</b>c', 'x < y', 'a < b > c', '1 <2', '<script>alert(1)</script>ok',
    '<!-- note -->text', '<style>p{}</style>styled', '100%20off', '%41%42', '50% of 10%ab', 'fish &amp; chips',
    'Tom & Jerry', 'quote "x" \'y\'', "a\u{00A0}b\u{00A0}", 'ünïcödé  ΣΊΣΥΦΟΣ', "line1\nline2", "a <\nb", "  lead\n\n  trail  ",
    '<p>para</p><p>two</p>', 'a<br>b', '<a href="x">link</a> & more', '5 < 6 and 7 > 3',
];
$sanitizePairs = [];
foreach ($wpSanitize($sanitizeCorpus) as $i => [$asText, $asTextarea]) {
    $sanitizePairs[] = [$sanitizeCorpus[$i], $asText, $asTextarea];
}

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
    'captcha' => ['id' => 'f_captcha', 'type' => 'captcha', 'label' => 'CAPTCHA', 'required' => true, 'provider' => 'recaptcha'],
];
$conditionFixtures = [];
foreach ($fields as $type => $field) {
    $group = ['id' => 'g_' . $type, 'type' => 'group', 'label' => 'Group', 'conditions' => $cond, 'children' => [$field]];
    $conditionFixtures[$type] = [
        'direct' => Reflect::call(FormRenderer::class, 'renderFields', [$control, $field + ['conditions' => $cond]]),
        'group'  => Reflect::call(FormRenderer::class, 'renderFields', [$control, $group]),
    ];
}

// Chained conditions: a rule on a field that is itself hidden. The server's answer comes from resolveVisibility() on
// the values as they would be posted (a hidden field is still in the DOM, so it is still posted).
$rule    = static fn(string $field, string $op, string $value = ''): array => ['field_id' => $field, 'operator' => $op, 'value' => $value];
$show    = static fn(array ...$rules): array => ['action' => 'show', 'match' => 'all', 'rules' => $rules];
$text    = static fn(string $id, ?array $cond = null): array => ['id' => $id, 'type' => 'text', 'label' => $id] + ($cond ? ['conditions' => $cond] : []);
$box     = static fn(string $id, ?array $cond = null): array => [
    'id' => $id, 'type' => 'checkbox', 'label' => $id,
    'options' => [['label' => 'X', 'value' => 'x'], ['label' => 'Y', 'value' => 'y']],
] + ($cond ? ['conditions' => $cond] : []);
$gateOn  = $show($rule('gate', 'equals', 'on'));
$cascadeCases = [
    // The reported case: b depends on a, which is hidden but still holds a value.
    'shown because the field it tests is hidden' => [
        [$text('gate'), $text('a', $gateOn), $text('b', $show($rule('a', 'empty')))],
        ['gate' => 'off', 'a' => 'filled earlier', 'b' => 'PLEASE-CALL-ME-BACK'],
    ],
    'hidden because the field it tests is hidden' => [
        [$text('gate'), $text('a', $gateOn), $text('b', $show($rule('a', 'equals', 'x')))],
        ['gate' => 'off', 'a' => 'x', 'b' => ''],
    ],
    'the dependent field comes first on the page' => [
        [$text('gate'), $text('b', $show($rule('a', 'empty'))), $text('a', $gateOn)],
        ['gate' => 'off', 'a' => 'filled earlier', 'b' => 'typed'],
    ],
    'three levels deep' => [
        [$text('gate'), $text('a', $gateOn), $text('b', $show($rule('a', 'not_empty'))), $text('c', $show($rule('b', 'not_empty')))],
        ['gate' => 'off', 'a' => '1', 'b' => '2', 'c' => '3'],
    ],
    'a field inside a hidden group' => [
        [$text('gate'), ['id' => 'g', 'type' => 'group', 'label' => 'G', 'conditions' => $gateOn, 'children' => [$text('a')]], $text('b', $show($rule('a', 'not_empty')))],
        ['gate' => 'off', 'a' => 'filled earlier', 'b' => 'typed'],
    ],
    'a hidden checkbox reads as nothing ticked' => [
        [$text('gate'), $box('boxes', $gateOn), $text('b', $show($rule('boxes', 'equals', 'x')))],
        ['gate' => 'off', 'boxes' => ['x'], 'b' => 'typed'],
    ],
    'a hidden checkbox tested for an empty value' => [
        [$text('gate'), $box('boxes', $gateOn), $text('b', $show($rule('boxes', 'equals', '')))],
        ['gate' => 'off', 'boxes' => ['x'], 'b' => 'typed'],
    ],
    'a hidden consent reads as unticked' => [
        [$text('gate'), ['id' => 'ok', 'type' => 'consent', 'label' => 'OK', 'consent_text' => 'I agree.', 'conditions' => $gateOn], $text('b', $show($rule('ok', 'not_empty')))],
        ['gate' => 'off', 'ok' => '1', 'b' => 'typed'],
    ],
    'rules that never settle' => [
        [$text('a', $show($rule('b', 'empty'))), $text('b', $show($rule('a', 'empty')))],
        ['a' => 'one', 'b' => 'two'],
    ],
    'visible chain, nothing hidden' => [
        [$text('gate'), $text('a', $gateOn), $text('b', $show($rule('a', 'equals', 'x')))],
        ['gate' => 'on', 'a' => 'x', 'b' => 'typed'],
    ],
];
$cascades = [];
foreach ($cascadeCases as $name => [$caseFields, $posted]) {
    [$hidden] = Reflect::call(FormProcessor::class, 'resolveVisibility', $caseFields, $posted, []);
    $cascades[] = [
        'name'   => $name,
        'html'   => Reflect::call(FormRenderer::class, 'renderFields', $caseFields),
        'values' => $posted,
        'hidden' => array_map('strval', array_keys($hidden)),
    ];
}

// Fields without an input named after them, used as a rule's source: real markup, the inputs a visitor fills in ('dom',
// input name => value; a number for a file input's file count), and the server's reading of the same submission through
// the field's own conditionValue() ('raw' is what its extractValue() returns for it).
$compositeCases = [
    'name, expanded' => [
        ['id' => 'n', 'type' => 'name', 'label' => 'Name', 'expanded' => true, 'prefix_enabled' => true, 'fname_enabled' => true, 'lname_enabled' => true],
        ['n[prefix]' => 'mr', 'n[fname]' => 'Ada', 'n[lname]' => 'Lovelace'],
        ['prefix' => 'mr', 'fname' => 'Ada', 'lname' => 'Lovelace'],
    ],
    'name, expanded, first name left empty' => [
        ['id' => 'n', 'type' => 'name', 'label' => 'Name', 'expanded' => true, 'fname_enabled' => true, 'lname_enabled' => true],
        ['n[fname]' => '', 'n[lname]' => 'Lovelace'],
        ['fname' => '', 'lname' => 'Lovelace'],
    ],
    'name, one input' => [
        ['id' => 'n', 'type' => 'name', 'label' => 'Name'],
        ['n' => 'Ada Lovelace'],
        'Ada Lovelace',
    ],
    'address, expanded' => [
        ['id' => 'a', 'type' => 'address', 'label' => 'Address', 'expanded' => true, 'street_enabled' => true, 'zip_enabled' => true, 'city_enabled' => true],
        ['a[street]' => 'Main St 1', 'a[city]' => 'Berlin', 'a[zip]' => '10115'],
        ['street' => 'Main St 1', 'city' => 'Berlin', 'zip' => '10115'],
    ],
    'address, expanded, nothing entered' => [
        ['id' => 'a', 'type' => 'address', 'label' => 'Address', 'expanded' => true, 'street_enabled' => true, 'city_enabled' => true],
        ['a[street]' => '', 'a[city]' => ''],
        ['street' => '', 'city' => ''],
    ],
    // extractValue() returns every scheme's keys; only the chosen scheme's are on the page.
    'direct debit, SEPA' => [
        ['id' => 's', 'type' => 'directdebit', 'label' => 'Mandate'],
        ['s[iban]' => 'DE89 3704 0044 0532 0130 00', 's[bic]' => 'COBADEFFXXX', 's[holder]' => 'Ada Lovelace'],
        ['iban' => 'DE89 3704 0044 0532 0130 00', 'bic' => 'COBADEFFXXX', 'sort_code' => '', 'routing' => '', 'account' => '', 'account_type' => '', 'holder' => 'Ada Lovelace', 'sig' => 'data:image/png;base64,AAAA'],
    ],
    'direct debit, Bacs' => [
        ['id' => 's', 'type' => 'directdebit', 'label' => 'Mandate', 'scheme' => 'bacs', 'bacs_text' => '<p>Pay Acme Ltd.</p>'],
        ['s[sort_code]' => '12-34-56', 's[account]' => '12345678', 's[holder]' => 'Ada Lovelace'],
        ['iban' => '', 'bic' => '', 'sort_code' => '12-34-56', 'routing' => '', 'account' => '12345678', 'account_type' => '', 'holder' => 'Ada Lovelace', 'sig' => ''],
    ],
    'direct debit, ACH, account type chosen' => [
        ['id' => 's', 'type' => 'directdebit', 'label' => 'Mandate', 'scheme' => 'ach', 'ach_text' => '<p>I authorize Acme Inc.</p>'],
        ['s[routing]' => '011000015', 's[account]' => '123456789', 's[account_type]' => 'savings', 's[holder]' => 'Ada Lovelace'],
        ['iban' => '', 'bic' => '', 'sort_code' => '', 'routing' => '011000015', 'account' => '123456789', 'account_type' => 'savings', 'holder' => 'Ada Lovelace', 'sig' => ''],
    ],
    'direct debit, ACH, account type not chosen' => [
        ['id' => 's', 'type' => 'directdebit', 'label' => 'Mandate', 'scheme' => 'ach', 'ach_text' => '<p>I authorize Acme Inc.</p>'],
        ['s[routing]' => '011000015', 's[account]' => '', 's[holder]' => 'Ada'],
        ['iban' => '', 'bic' => '', 'sort_code' => '', 'routing' => '011000015', 'account' => '', 'account_type' => '', 'holder' => 'Ada', 'sig' => ''],
    ],
    'direct debit, SEPA, with the debtor details switched on' => [
        ['id' => 's', 'type' => 'directdebit', 'label' => 'Mandate', 'debtor_address' => true, 'signing_place' => true],
        [
            's[iban]' => 'DE89 3704 0044 0532 0130 00', 's[bic]' => 'COBADEFFXXX', 's[holder]' => 'Ada Lovelace',
            's[street]' => 'Main St 1', 's[postcode]' => '10115', 's[city]' => 'Berlin', 's[country]' => '', 's[place]' => 'Berlin',
        ],
        [
            'iban' => 'DE89 3704 0044 0532 0130 00', 'bic' => 'COBADEFFXXX', 'sort_code' => '', 'routing' => '', 'account' => '',
            'account_type' => '', 'holder' => 'Ada Lovelace', 'street' => 'Main St 1', 'postcode' => '10115', 'city' => 'Berlin',
            'country' => '', 'place' => 'Berlin', 'sig' => '',
        ],
    ],
    'range slider' => [
        ['id' => 'r', 'type' => 'slider', 'label' => 'Range', 'ranged' => true],
        ['r[from]' => '10', 'r[to]' => '20'],
        ['from' => '10', 'to' => '20'],
    ],
    'post data' => [
        ['id' => 'p', 'type' => 'postdata', 'label' => 'Post', 'post_field' => ['post_title', 'post_url']],
        ['p[post_title]' => 'Hello', 'p[post_url]' => 'https://example.test/hello/'],
        ['post_title' => 'Hello', 'post_url' => 'https://example.test/hello/', 'post_id' => '5', 'post_author' => 'Ada'],
    ],
    'upload, two files' => [
        ['id' => 'u', 'type' => 'upload', 'label' => 'Files', 'multiple' => true],
        ['u[]' => 2],
        ['name' => ['a.pdf', 'b.pdf'], 'type' => ['', ''], 'tmp_name' => ['', ''], 'error' => [0, 0], 'size' => [1, 1]],
    ],
    'upload, none' => [
        ['id' => 'u', 'type' => 'upload', 'label' => 'Files'],
        ['u' => 0],
        null,
    ],
    'CAPTCHA, solved' => [
        ['id' => 'c', 'type' => 'captcha', 'label' => 'CAPTCHA', 'provider' => 'recaptcha'],
        ['g-recaptcha-response' => 'token-123'],
        'token-123',
    ],
    // ALTCHA's widget posts its payload in a hidden input named after the field.
    'ALTCHA, solved' => [
        ['id' => 'c', 'type' => 'captcha', 'label' => 'CAPTCHA', 'provider' => 'altcha'],
        ['c' => 'eyJjaGFsbGVuZ2UiOnt9fQ=='],
        'eyJjaGFsbGVuZ2UiOnt9fQ==',
    ],
    // A single checkbox named after the field: a list in the browser, so the server reads it as one too.
    'consent, ticked' => [
        ['id' => 'k', 'type' => 'consent', 'label' => 'Consent', 'consent_text' => 'I agree.'],
        ['k' => true],
        '1',
    ],
    'GDPR, not ticked' => [
        ['id' => 'g', 'type' => 'gdpr', 'label' => 'Privacy', 'privacy_policy_url' => 'https://example.com/privacy'],
        ['g' => false],
        '',
    ],
];
Brain\Monkey\Functions\when('get_option')->alias(
    static fn($key, $default = false) => $key === 'fabricator_forms_recaptcha_site_key' ? 'site-key' : $default
);
$composites = [];
foreach ($compositeCases as $name => [$field, $dom, $posted]) {
    $handler      = \FabricatorForms\Fields\FieldRegistry::get($field['type']);
    $cfg          = array_merge($handler->getDefaultConfig(), $field);
    $read = $handler->conditionValue($posted, $cfg);
    // The server's decisions on a few rules over this field: "equals" its own reading (the first item of a list),
    // "empty", and the two numeric operators, where a list and a number are read differently.
    $equalsValue = is_array($read) ? (string) ($read[0] ?? '') : (string) $read;
    $decisions   = [];
    foreach ([['equals', $equalsValue], ['empty', ''], ['greater', '0'], ['less', '1']] as [$op, $ruleValue]) {
        $decisions[] = [$op, $ruleValue, Reflect::call(
            FormProcessor::class,
            'evalConditionRule',
            ['field_id' => $field['id'], 'operator' => $op, 'value' => $ruleValue],
            [$field['id'] => $read]
        )];
    }
    $composites[] = [
        'name'      => $name,
        'id'        => $field['id'],
        'html'      => Reflect::call(FormRenderer::class, 'renderFields', [$cfg]),
        'dom'       => $dom,
        'server'    => $read,
        'decisions' => $decisions,
    ];
}

// Account details as a visitor might type them: DirectDebitField::validate()'s verdict on each, in an otherwise complete
// and signed mandate, so only that detail decides. The IBAN's own rule reads flags its input handler sets, so it is
// typed as a whole in direct-debit.test.js instead; an IBAN from outside SEPA is refused there.
$debitSig = (static function (): string {
    $im = imagecreatetruecolor(400, 100);
    ob_start();
    imagepng($im);
    return 'data:image/png;base64,' . base64_encode((string) ob_get_clean());
})();
$debitSchemes = [
    'sepa' => [[], ['iban' => 'DE89 3704 0044 0532 0130 00', 'bic' => 'COBADEFFXXX']],
    'bacs' => [['bacs_text' => '<p>Pay Acme Ltd.</p>'], ['sort_code' => '12-34-56', 'account' => '12345678']],
    'ach'  => [['ach_text' => '<p>I authorize Acme Inc.</p>'], ['routing' => '011000015', 'account' => '123456789', 'account_type' => 'checking']],
];
$debitTyped = [
    'sepa' => ['bic' => ['COBADEFF', 'COBADEFFXXX', 'cobadeff', 'TOO', 'COBADEFFXX', '1OBADEFF', 'COBA DEFF']],
    'bacs' => [
        'sort_code' => ['123456', '12-34-56', '12 34 56', '12345', '1234567', '12-34-5a', 'abcdef'],
        'account'   => ['12345678', '1234 5678', '1234-5678', '1234567', '123456789', '1234567a'],
    ],
    'ach'  => [
        // 800000006, 610000005: prefixes 80 and 61; 130000006, 330000000: check digit right, no Federal Reserve prefix.
        'routing' => ['011000015', '021000021', '800000006', '610000005', '021000022', '130000006', '330000000', '000000000', '01100001', '0110000150', '011-000-015', '01100001a'],
        'account' => ['1234', '12345678901234567', '12-34', '123', '123456789012345678', 'abcd', '1234 5678'],
    ],
];
// What counts as "untouched" for an optional mandate: one detail holding only this, after WordPress's real
// sanitize_text_field() as a submission would pass it. A hyphen or a non-breaking space is something typed; a space or
// a tab is nothing.
$untouchedTyped     = ['', ' ', "\t", '-', ' - ', "\u{00A0}", 'x'];
$untouchedSanitized = array_combine($untouchedTyped, array_column($wpSanitize($untouchedTyped), 0));
$debit = [];
$debitField = \FabricatorForms\Fields\FieldRegistry::get('directdebit');
foreach ($debitTyped as $scheme => $parts) {
    [$wording, $details] = $debitSchemes[$scheme];
    $cfg      = array_merge($debitField->getDefaultConfig(), $wording, ['id' => 'dd', 'type' => 'directdebit', 'label' => 'Mandate', 'scheme' => $scheme, 'required' => false]);
    $complete = $details + ['holder' => 'Ada Lovelace'];
    $cases    = [];
    foreach ($parts as $part => $typedValues) {
        foreach ($typedValues as $typed) {
            $cases[] = [$part, $typed, $debitField->validate([$part => $typed] + $complete + ['sig' => $debitSig], $cfg) === true];
        }
    }
    $untouched = [];
    foreach (array_keys($complete) as $part) {
        if ($part === 'account_type') {
            continue; // a select: nothing but its options can be chosen
        }
        foreach ($untouchedSanitized as $typed => $posted) {
            $untouched[] = [$part, (string) $typed, $debitField->validate([$part => $posted, 'sig' => ''], $cfg) === true];
        }
    }
    $debit[] = [
        'scheme'   => $scheme,
        'untouched' => $untouched,
        'html'     => Reflect::call(FormRenderer::class, 'renderFields', [$cfg]),
        'complete' => $complete,
        'sig'      => $debitSig,
        'cases'    => $cases,
    ];
}

// A SEPA mandate with the debtor details switched on: the server's verdict on the complete mandate, and its message when
// one debtor detail is left empty (named by its label, here a changed one).
$extrasCfg      = array_merge(
    $debitField->getDefaultConfig(),
    ['id' => 'dd', 'type' => 'directdebit', 'label' => 'Mandate', 'required' => false, 'debtor_address' => true, 'signing_place' => true, 'city_label' => 'Town:']
);
$extrasComplete = [
    'iban' => 'DE89370400440532013000', 'bic' => 'COBADEFFXXX', 'holder' => 'Ada Lovelace',
    'street' => 'Main St 1', 'postcode' => '10115', 'city' => 'Berlin', 'country' => 'Germany', 'place' => 'Berlin',
];
$extrasMissing = [];
foreach (['street', 'postcode', 'city', 'country', 'place'] as $part) {
    $verdict         = $debitField->validate([$part => ''] + $extrasComplete + ['sig' => $debitSig], $extrasCfg);
    $extrasMissing[] = [$part, $verdict === true ? null : $verdict];
}
// The BIC left empty under a German and a Swiss IBAN: needed only for the SEPA countries outside the EEA.
$bicCases = [];
foreach (['DE89370400440532013000', 'CH9300762011623852957'] as $bicIban) {
    $verdict    = $debitField->validate(
        ['iban' => $bicIban, 'bic' => '', 'holder' => 'Ada Lovelace', 'sig' => $debitSig],
        array_merge($debitField->getDefaultConfig(), ['required' => true])
    );
    $bicCases[] = [$bicIban, $verdict === true ? null : $verdict];
}
// IBANs whose check digits pass mod-97 but lie outside ISO 13616's 02-98, next to a valid one: the server's verdict on
// each in an otherwise complete mandate.
$ibanCases = [];
foreach (['DE89370400440532013000', 'DE01370400440000000042', 'DE00370400440000000060', 'DE99370400440000000024'] as $caseIban) {
    $ibanCases[] = [$caseIban, $debitField->validate(['iban' => $caseIban, 'bic' => '', 'holder' => 'Ada Lovelace', 'sig' => $debitSig], $debitField->getDefaultConfig()) === true];
}
$debitExtras = [
    'html'        => Reflect::call(FormRenderer::class, 'renderFields', [$extrasCfg]),
    'complete'    => $extrasComplete,
    'sig'         => $debitSig,
    'complete_ok' => $debitField->validate($extrasComplete + ['sig' => $debitSig], $extrasCfg) === true,
    'missing'     => $extrasMissing,
    'bic'         => $bicCases,
    'iban'        => $ibanCases,
    // A complete mandate signed by typing the name instead of drawing: what the server says to it.
    'typed_sig_ok' => $debitField->validate($extrasComplete + ['sig' => 'Ada Lovelace'], $extrasCfg) === true,
];

// A required Signature field as the page prints it, for the typed-name alternative to drawing.
$signatureField = \FabricatorForms\Fields\FieldRegistry::get('signature');
$signature      = [
    'html'       => Reflect::call(FormRenderer::class, 'renderFields', [array_merge($signatureField->getDefaultConfig(), ['id' => 'sg', 'type' => 'signature', 'label' => 'Signature', 'required' => true])]),
    'typed_ok'   => $signatureField->validate('Ada Lovelace', ['required' => true]) === true,
    'spaces_ok'  => $signatureField->validate('', ['required' => true]) === true,
];

// Whole forms as FormRenderer::render() prints them, for the flows that need the <form>, its footer and its pages:
// submit-button conditions, page navigation, submitting, server errors, two forms on one page.
Brain\Monkey\Functions\when('wp_localize_script')->justReturn(true);
Brain\Monkey\Functions\when('wp_add_inline_style')->justReturn(true);
Brain\Monkey\Functions\when('wp_add_inline_script')->justReturn(true);
// The enqueue render() backs up is the page's business; the suite hands front.js its globals itself (support/page.js).
(new ReflectionProperty(Assets::class, 'front_assets_done'))->setValue(null, true);
$openSesame = ['match' => 'all', 'rules' => [['field_id' => 'code', 'operator' => 'equals', 'value' => 'open']]];
$formCases  = [
    'submit condition' => [[$text('code'), $text('other')], ['submit_conditions' => $openSesame]],
    'submit condition, three pages' => [[
        $text('code'),
        ['id' => 'pb1', 'type' => 'pagebreak', 'label' => 'Page break'],
        $text('middle'),
        ['id' => 'pb2', 'type' => 'pagebreak', 'label' => 'Page break'],
        $text('last'),
    ], ['submit_conditions' => $openSesame]],
    'contact' => [[
        ['id' => 'name', 'type' => 'text', 'label' => 'Name', 'required' => true],
        ['id' => 'email', 'type' => 'email', 'label' => 'Email'],
    ], []],
    'callback' => [[
        ['id' => 'email', 'type' => 'email', 'label' => 'Email', 'required' => true],
        ['id' => 'phone', 'type' => 'text', 'label' => 'Phone'],
    ], []],
    'with captcha' => [[
        ['id' => 'name', 'type' => 'text', 'label' => 'Name'],
        ['id' => 'cap', 'type' => 'captcha', 'label' => 'CAPTCHA', 'provider' => 'recaptcha'],
    ], []],
    'with altcha' => [[
        ['id' => 'name', 'type' => 'text', 'label' => 'Name'],
        ['id' => 'cap', 'type' => 'captcha', 'label' => 'CAPTCHA', 'provider' => 'altcha', 'required' => true],
    ], []],
    'rating' => [[['id' => 'stars', 'type' => 'rating', 'label' => 'Stars', 'required' => true]], []],
    'dropdown' => [[[
        'id' => 'size', 'type' => 'select', 'label' => 'Size', 'required' => true,
        'options' => [['label' => 'Small', 'value' => 's'], ['label' => 'Large', 'value' => 'l']],
    ]], []],
];
$forms = [];
foreach ($formCases as $name => [$formFields, $settings]) {
    $withDefaults = array_map(
        static fn(array $f): array => array_merge(\FabricatorForms\Fields\FieldRegistry::get($f['type'])->getDefaultConfig(), $f),
        $formFields
    );
    // Each rendered alone, as the first form on its page; a second copy below carries the id suffix.
    (new ReflectionProperty(FormRenderer::class, 'render_count'))->setValue(null, 0);
    $forms[$name] = FormRenderer::render(0, $settings, $withDefaults);
}
// The same form twice on one page, and two different forms sharing a field id (a form selection), rendered in order.
(new ReflectionProperty(FormRenderer::class, 'render_count'))->setValue(null, 0);
$contactFields     = array_map(static fn(array $f): array => array_merge(\FabricatorForms\Fields\FieldRegistry::get($f['type'])->getDefaultConfig(), $f), $formCases['contact'][0]);
$callbackFields    = array_map(static fn(array $f): array => array_merge(\FabricatorForms\Fields\FieldRegistry::get($f['type'])->getDefaultConfig(), $f), $formCases['callback'][0]);
$forms['contact twice'] = FormRenderer::render(0, [], $contactFields) . FormRenderer::render(0, [], $contactFields);
(new ReflectionProperty(FormRenderer::class, 'render_count'))->setValue(null, 0);
$forms['contact and callback'] = FormRenderer::render(0, [], $contactFields) . FormRenderer::render(0, [], $callbackFields);

// The builder page as an administrator opens it for a new form (FormEditor::render()): its markup, the field palette in
// data-palette, and the strings admin-builder.js reads from window.FabricatorBuilderI18n. Tests put their own form into
// data-form. Last, since it makes the visitor an administrator.
Brain\Monkey\Functions\when('get_current_user_id')->justReturn(1);
Brain\Monkey\Functions\when('user_can')->justReturn(true);
Brain\Monkey\Functions\when('current_user_can')->justReturn(true);
Brain\Monkey\Functions\when('esc_html_e')->alias(static function (string $s): void {
    echo htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
});
Brain\Monkey\Functions\when('esc_attr_e')->alias(static function (string $s): void {
    echo htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
});
ob_start();
\FabricatorForms\Admin\FormEditor::render();
$admin = [
    'editor'      => (string) ob_get_clean(),
    'builderI18n' => Reflect::call(\FabricatorForms\Admin\FormEditor::class, 'builderI18n'),
];

// The form list (FormList::render()) with four saved forms, and the object it localizes for admin-formlist.js.
$localized = [];
Brain\Monkey\Functions\when('wp_localize_script')->alias(static function ($handle, $name, $data) use (&$localized): bool {
    $localized[$name] = $data;
    return true;
});
Brain\Monkey\Functions\when('get_posts')->alias(static fn(array $args): array => $args['offset'] > 0 ? [] : array_map(
    static fn(int $id, string $title): object => (object) ['ID' => $id, 'post_title' => $title],
    [11, 12, 13, 14],
    ['Contact', 'Callback request', 'Newsletter', 'Contact (archive)']
));
Brain\Monkey\Functions\when('update_meta_cache')->justReturn(true);
Brain\Monkey\Functions\when('wp_list_pluck')->alias(static fn(array $list, string $field): array => array_column(array_map('get_object_vars', $list), $field));
Brain\Monkey\Functions\when('get_post_meta')->justReturn('');
ob_start();
\FabricatorForms\Admin\FormList::render();
$admin['formList']     = (string) ob_get_clean();
$admin['formListPage'] = $localized['FabricatorFormListPage'] ?? null;

// FormFabricator → Settings (FormSettings::renderSettingsPage()) after the seal key setup, and its localized object.
if (!defined('WP_LANG_DIR')) {
    define('WP_LANG_DIR', sys_get_temp_dir() . '/formfabricator-no-languages');
}
Brain\Monkey\Functions\when('get_option')->alias(
    static fn($key, $default = false) => $key === 'fabricator_forms_seal_setup_done' ? true : $default
);
foreach (['delete_transient', 'update_option', 'switch_to_locale', 'restore_previous_locale'] as $noop) {
    Brain\Monkey\Functions\when($noop)->justReturn(true);
}
Brain\Monkey\Functions\when('wp_nonce_field')->alias(static function (string $action, string $name): void {
    echo '<input type="hidden" name="' . htmlspecialchars($name, ENT_QUOTES) . '" value="nonce-' . htmlspecialchars($action, ENT_QUOTES) . '">';
});
$GLOBALS['wp_roles'] = (object) ['roles' => [
    'administrator' => ['name' => 'Administrator'],
    'editor'        => ['name' => 'Editor'],
]];
Brain\Monkey\Functions\when('wp_roles')->justReturn($GLOBALS['wp_roles']);
Brain\Monkey\Functions\when('translate_user_role')->returnArg(1);
ob_start();
\FabricatorForms\Admin\FormSettings::renderSettingsPage();
$admin['settings']     = (string) ob_get_clean();
$admin['settingsPage'] = $localized['FabricatorSettingsPage'] ?? null;

// ALTCHA: a challenge with a small answer, that answer, and the server's verdicts on it (accepted, refused as used,
// refused when tampered). The JS suite checks ALTCHA's own solver and verifier agree.
\FabricatorForms\Tests\Support\FakeWordPress::install();
Brain\Monkey\Functions\when('wp_salt')->justReturn('altcha-fixture-salt');
Brain\Monkey\Functions\when('get_current_blog_id')->justReturn(1);
$altchaChallenge = Reflect::call(\FabricatorForms\Utils\Altcha::class, 'challengeFor', 37);
$altchaKey       = Reflect::call(
    \FabricatorForms\Utils\Altcha::class,
    'deriveKey',
    (string) hex2bin($altchaChallenge['parameters']['nonce']),
    (string) hex2bin($altchaChallenge['parameters']['salt']),
    37
);
$altchaPayload = static fn(array $challenge): string => base64_encode(json_encode(
    ['challenge' => $challenge, 'solution' => ['counter' => 37, 'derivedKey' => bin2hex($altchaKey), 'time' => 12.5]],
    JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
));
$altchaChanged = $altchaChallenge;
$altchaChanged['parameters']['expiresAt'] += 3600;
$altcha = [
    'challenge' => $altchaChallenge,
    'secrets'   => [
        'challenge' => Reflect::call(\FabricatorForms\Utils\Altcha::class, 'secret', 'challenge'),
        'key'       => Reflect::call(\FabricatorForms\Utils\Altcha::class, 'secret', 'key'),
    ],
    'solution'  => ['counter' => 37, 'derivedKey' => bin2hex($altchaKey)],
    'verdicts'  => [
        \FabricatorForms\Utils\Altcha::verify($altchaPayload($altchaChallenge)),
        \FabricatorForms\Utils\Altcha::verify($altchaPayload($altchaChallenge)),
        \FabricatorForms\Utils\Altcha::verify($altchaPayload($altchaChanged)),
    ],
];

$fixture = [
    'admin'             => $admin,
    'altcha'            => $altcha,
    'forms'             => $forms,
    'localization'      => Assets::frontLocalization(),
    'globals'           => Assets::frontFieldAssets()['globals'],
    'conditionFixtures' => $conditionFixtures,
    'parity'            => ['scalar' => $scalar, 'array' => $array],
    'cascades'          => $cascades,
    'composites'        => $composites,
    'sanitize'          => $sanitizePairs,
    'debit'             => $debit,
    'debitExtras'       => $debitExtras,
    'signature'         => $signature,
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
