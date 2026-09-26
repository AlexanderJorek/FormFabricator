<?php

namespace FabricatorForms\Tests\Unit\Fields;

use FabricatorForms\Admin\FormEditor;
use FabricatorForms\Tests\Support\FieldStubs;
use FabricatorForms\Tests\Support\TestCase;

/**
 * An imported form can hold any JSON. sanitizeFields() must give every declared config key back the kind of value its
 * default has, so that no field's render() receives a list where it expects a string. Before, one such key threw a
 * TypeError and took down every page showing the form. (TESTING.md §3, import — 1.0.7.)
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
