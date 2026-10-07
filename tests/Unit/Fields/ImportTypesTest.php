<?php

namespace FabricatorForms\Tests\Unit\Fields;

use Brain\Monkey\Functions;
use FabricatorForms\Admin\FormEditor;
use FabricatorForms\Form\FormProcessor;
use FabricatorForms\Tests\Support\FieldStubs;
use FabricatorForms\Tests\Support\TestCase;

/**
 * An imported form can hold any JSON. sanitizeFields() must give every declared config key back the kind of value its
 * default has, so no field's render() receives a list where it expects a string and throws. (TESTING.md §3, import.)
 */
final class ImportTypesTest extends TestCase
{
    private const STRUCTURAL = ['conditions', 'children', 'options'];

    protected function setUp(): void
    {
        parent::setUp();
        FieldStubs::install();
    }

    public function testEveryFieldTypeSurvivesHostileKindsAndStillRenders(): void
    {
        $types = FieldStubs::registry();
        self::assertGreaterThanOrEqual(27, count($types));

        foreach ($types as $type => $class) {
            $handler  = new $class();
            $defaults = $handler->getDefaultConfig();

            // Every key flipped to the wrong kind, plus undeclared keys holding lists.
            $hostile = ['id' => 'f-' . $type, 'type' => $type, 'label' => 'Question'];
            foreach ($defaults as $key => $default) {
                if (!in_array($key, ['id', 'type', 'label'], true)) {
                    $hostile[$key] = is_array($default) ? 'not a list' : ['not', ['a', 'single'], 'value'];
                }
            }
            $hostile['made_up_key']  = ['x' => ['y']];
            $hostile['placeholder']  = ['array', 'label'];
            $hostile['custom_class'] = ['a', 'b'];

            $clean = FormEditor::sanitizeFields([$hostile]);
            self::assertCount(1, $clean, "$type: the field itself survives sanitizing");
            $cfg = $clean[0];

            foreach ($defaults as $key => $default) {
                if (array_key_exists($key, $cfg)) { // a dropped key falls back to the field's own default
                    self::assertSame(is_array($default), is_array($cfg[$key]), "$type: '$key' holds a " . gettype($cfg[$key]) . ', its default a ' . gettype($default));
                }
            }
            foreach ($cfg as $key => $value) {
                if (is_array($value) && !array_key_exists($key, $defaults)) {
                    self::assertContains($key, self::STRUCTURAL, "$type: undeclared key '$key' kept a list");
                }
            }

            try {
                self::assertIsString($handler->render(array_merge($defaults, $cfg), 'f-' . $type));
            } catch (\TypeError $e) {
                self::fail("$type: render() threw a TypeError — " . $e->getMessage());
            }
        }
    }

    public function testEveryFieldTypeTurnsHostileValuesIntoTextWithoutAWarning(): void
    {
        // A crafted POST can give any field any shape, and an import a list of lists where a list belongs: neither may
        // print "Array" into the PDF or a warning into the AJAX answer.
        $warnings = [];
        set_error_handler(static function (int $no, string $msg, string $file, int $line) use (&$warnings): bool {
            $warnings[] = basename($file) . ":$line $msg";
            return true;
        }, E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE);

        // Posted, then read back through each field's own extractValue(), so every field sees the shapes a crafted
        // POST really gives it rather than ones its extraction already rules out.
        $posted   = ['', 'text', ['first' => 'x'], ['a', 'b'], [['nested']], ['first' => ['x']]];
        // As WordPress defines them: map_deep() applies the callback to every scalar at any depth.
        $mapDeep = static function ($v, callable $cb) use (&$mapDeep) {
            return is_array($v) ? array_map(static fn($x) => $mapDeep($x, $cb), $v) : $cb($v);
        };
        Functions\when('wp_unslash')->returnArg(1);
        Functions\when('map_deep')->alias($mapDeep);
        Functions\when('is_email')->alias(static fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL) ?: false);
        $verified = new \ReflectionProperty(FormProcessor::class, 'nonceVerified');
        $verified->setValue(null, true);
        $calls    = 0;
        try {
            foreach (FieldStubs::registry() as $type => $class) {
                $handler  = new $class();
                $defaults = $handler->getDefaultConfig();
                $hostile  = ['id' => 'f-' . $type, 'type' => $type, 'label' => 'Question'];
                foreach ($defaults as $key => $default) {
                    if (is_array($default) && !in_array($key, self::STRUCTURAL, true)) {
                        $hostile[$key] = [['a'], ['b' => ['c']]];
                    }
                }
                $cfg = array_merge($defaults, FormEditor::sanitizeFields([$hostile])[0]);
                $cfg['expanded'] = false;

                foreach ($posted as $post) {
                    $_POST = ['f-' . $type => $post];
                    $label = "$type posted " . json_encode($post);
                    try {
                        $value = $handler->extractValue('f-' . $type);
                        $handler->validate($value, $cfg);
                        foreach ($handler->mapNormalized('f-' . $type, 'Question', $value, $cfg, ['files' => [], 'raw_values' => [], 'skip_ids' => []]) as $entry) {
                            if (is_string($entry['value'] ?? null)) {
                                self::assertStringNotContainsString('Array', $entry['value'], "$label: the record printed an array");
                            }
                            $handler->pdfData($entry + ['config' => $cfg]);
                        }
                        $calls++;
                    } catch (\TypeError | \ValueError $e) {
                        self::fail("$label: " . get_class($e) . ' — ' . $e->getMessage());
                    }
                }
            }
        } finally {
            restore_error_handler();
            $verified->setValue(null, false);
            $_POST = [];
        }

        self::assertGreaterThan(100, $calls);
        self::assertSame([], $warnings);
    }

    public function testOptionValuesAreNeverEmptyAndNeverShared(): void
    {
        // An option valued "" could not satisfy "required"; two options sharing a value became one in conditions,
        // routing and the email.
        FieldStubs::registry();
        $select = FormEditor::sanitizeFields([[
            'id'      => 's1',
            'type'    => 'select',
            'label'   => 'Pick',
            'options' => [
                ['label' => '中文', 'value' => ''],
                ['label' => 'Yes please', 'value' => ''],
                ['label' => 'A', 'value' => 'a'],
                ['label' => 'B', 'value' => 'a'],
            ],
        ]])[0];

        self::assertSame(['option-1', 'yes-please', 'a', 'a-2'], array_column($select['options'], 'value'));
    }

    public function testAFieldTypeThatIsNoStringDoesNotEndTheImport(): void
    {
        // An imported "type" holding a list or a number reached FieldRegistry::get(string): a TypeError, HTTP 500.
        FieldStubs::registry();
        $fields = [['id' => 'a', 'type' => ['text']], ['id' => 'b', 'type' => 5], 'not a field', ['id' => 'c', 'type' => 'text']];

        $restored = \FabricatorForms\Tests\Support\Reflect::call(\FabricatorForms\Admin\FormList::class, 'restoreFieldDefaults', $fields);
        $stripped = \FabricatorForms\Tests\Support\Reflect::call(\FabricatorForms\Admin\FormList::class, 'stripFieldDefaults', $fields);

        self::assertSame(array_slice($fields, 0, 3), array_slice($restored, 0, 3), 'kept as they came, for sanitizeFields() to drop');
        self::assertSame(array_slice($fields, 0, 3), array_slice($stripped, 0, 3));
        self::assertArrayHasKey('placeholder', $restored[3], 'a real field still gets its defaults');
    }

    public function testLegitimateValuesKeepTheirKind(): void
    {
        FieldStubs::registry();
        $select = FormEditor::sanitizeFields([[
            'id'         => 's1',
            'type'       => 'select',
            'label'      => 'Pick',
            'options'    => [['value' => 'a', 'label' => 'A']],
            'required'   => true,
            'conditions' => ['action' => 'show', 'match' => 'all', 'rules' => []],
        ]])[0];

        self::assertSame([['value' => 'a', 'label' => 'A', 'default' => false]], $select['options']);
        self::assertTrue($select['required']);
        self::assertIsArray($select['conditions'], 'conditions stay a list on a type that does not declare them');
    }
}
