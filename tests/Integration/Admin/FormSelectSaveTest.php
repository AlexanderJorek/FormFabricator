<?php

namespace FabricatorForms\Tests\Integration\Admin;

use FabricatorForms\Admin\FormSelectList;
use FabricatorForms\Form\FormSelectModel;
use FabricatorForms\Tests\Integration\AjaxTestCase;

/**
 * Saving a form selection through its AJAX action: the items arrive as a posted array (items[n][key]), each value
 * sanitized, and are stored as the model reads them.
 */
final class FormSelectSaveTest extends AjaxTestCase
{
    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- overrides WP_UnitTestCase's snake_case fixture method.
    public function set_up(): void
    {
        parent::set_up();
        // The plugin loads the admin classes only when is_admin(), which the test bootstrap is not.
        FormSelectList::init();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    public function testItemsPostedAsAnArrayAreSavedValueByValue(): void
    {
        $form = $this->createForm([['id' => 'name', 'type' => 'text', 'label' => 'Name']], [self::notification()]);

        $r = $this->ajax('fabricator_forms_fsel_save', [
            'nonce' => wp_create_nonce('fabricator_forms_fsel_save'),
            'id'    => '0',
            'title' => 'Pick <b>one</b>',
            'items' => [
                ['form_id' => (string) $form, 'label' => 'Contact <script>x</script>', 'description' => '50 % off, a < b', 'favorite' => '1'],
                ['form_id' => (string) $form, 'label' => 'Again', 'description' => '', 'favorite' => '0'],
            ],
        ]);

        self::assertTrue($r['success'], (string) wp_json_encode($r));
        $saved = FormSelectModel::get((int) $r['data']['id']);
        self::assertNotNull($saved);
        self::assertSame('Pick one', $saved->title);
        self::assertSame('Contact', $saved->items[0]['label'], 'markup removed');
        self::assertSame('50 % off, a &lt; b', $saved->items[0]['description'], 'text kept, "<" as WordPress stores it');
        self::assertTrue($saved->items[0]['favorite']);
        self::assertFalse($saved->items[1]['favorite'], '"0" is no favorite');
    }
}
