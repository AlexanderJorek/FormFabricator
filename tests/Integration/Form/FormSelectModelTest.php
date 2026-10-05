<?php

namespace FabricatorForms\Tests\Integration\Form;

use FabricatorForms\Form\FormSelectModel;
use FabricatorForms\Tests\Integration\TestCase;

/**
 * A form selection's ID is what a shortcode on a page names, so it must never be handed out twice; and a delete reports
 * what happened.
 */
final class FormSelectModelTest extends TestCase
{
    public function set_up(): void // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WP_UnitTestCase fixture method.
    {
        parent::set_up();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    public function testADeletedSelectionsIdIsNeverReused(): void
    {
        // "Highest ID + 1" gave the deleted newest selection's ID to the next one, and an old shortcode showed it.
        $first  = FormSelectModel::save(['title' => 'First', 'items' => []], 0, true);
        $second = FormSelectModel::save(['title' => 'Second', 'items' => []], 0, true);
        self::assertTrue(FormSelectModel::delete($second, true));

        $third = FormSelectModel::save(['title' => 'Third', 'items' => []], 0, true);
        self::assertGreaterThan($second, $third);
        self::assertNotSame($first, $third);
    }

    public function testATrashedFavoriteFallsBackToAFormThatShows(): void
    {
        // A favorite (or first) form in the trash is not rendered, and data-fav pointing at it showed no form at all.
        $form = static fn(string $title): int => (int) \FabricatorForms\Form\FormModel::save(['title' => $title, 'fields' => [], 'notifications' => [], 'settings' => []], 0, true);
        $gone = $form('Trashed favorite');
        $kept = $form('Still published');
        $id   = FormSelectModel::save(['title' => 'Pick one', 'items' => [
            ['form_id' => $gone, 'label' => 'Gone', 'favorite' => true],
            ['form_id' => $kept, 'label' => 'Kept'],
        ]], 0, true);
        wp_trash_post($gone);

        $html = do_shortcode('[fabricator_form_select id="' . $id . '"]');
        self::assertStringContainsString('data-fav="1"', $html);
        self::assertStringNotContainsString('Gone', $html);
    }

    public function testADeleteReportsWhatHappened(): void
    {
        self::assertFalse(FormSelectModel::delete(9999, true), 'no such selection');

        $id = FormSelectModel::save(['title' => 'Kept', 'items' => []], 0, true);
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        self::assertFalse(FormSelectModel::delete($id, true), 'refused without edit_forms');
    }
}
