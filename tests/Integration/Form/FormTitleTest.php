<?php

namespace FabricatorForms\Tests\Integration\Form;

use FabricatorForms\Form\FormModel;
use FabricatorForms\Tests\Integration\TestCase;

/**
 * A form title is plain text, escaped wherever it is shown. A user without unfiltered_html (every site admin of a network)
 * has WordPress's kses filters on post titles, which stored "Q&A" as "Q&amp;A", so the builder, the list and the import
 * preview showed "&amp;".
 */
final class FormTitleTest extends TestCase
{
    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- overrides WP_UnitTestCase's snake_case fixture method.
    public function set_up(): void
    {
        parent::set_up();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        // What kses_init() does for a user without unfiltered_html, whatever the test user may do.
        kses_init_filters();
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- overrides WP_UnitTestCase's snake_case fixture method.
    public function tear_down(): void
    {
        kses_remove_filters();
        parent::tear_down();
    }

    public function testATitleWithAnAmpersandIsStoredAsTyped(): void
    {
        $form = FormModel::save(['title' => 'Q&A <b>night</b>', 'fields' => [], 'notifications' => [], 'settings' => []], 0, true);

        self::assertIsInt($form);
        self::assertSame('Q&A night', get_post($form)->post_title, 'tags gone as before, "&" kept');
        self::assertSame('Q&A night', FormModel::get($form)->title);
        self::assertNotFalse(has_filter('title_save_pre', 'wp_filter_kses'), 'kses is back for every other post');

        $again = FormModel::save(['title' => 'Q&A again', 'fields' => [], 'notifications' => [], 'settings' => []], $form, true);
        self::assertSame($form, $again);
        self::assertSame('Q&A again', get_post($form)->post_title, 'on an update too');
    }
}
