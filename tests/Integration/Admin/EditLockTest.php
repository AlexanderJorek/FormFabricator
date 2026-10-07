<?php

namespace FabricatorForms\Tests\Integration\Admin;

use FabricatorForms\Admin\FormEditor;
use FabricatorForms\Admin\FormSettings;
use FabricatorForms\Admin\PDFLayoutEditor;
use FabricatorForms\Form\FormModel;
use FabricatorForms\Tests\Integration\AjaxTestCase;

/**
 * Two admins on the same screen (TESTING.md §2, §6): the second one is told who is editing, the notice clears once the
 * first releases the lock (the tab's sendBeacon on close), and two saves made from the same loaded state are not both
 * accepted. How promptly a real browser fires the beacon stays a manual check.
 */
final class EditLockTest extends AjaxTestCase
{
    private int $first  = 0;
    private int $second = 0;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- overrides WP_UnitTestCase's snake_case fixture method.
    public function set_up(): void
    {
        parent::set_up();
        // The plugin loads the admin classes only when is_admin(), which the test bootstrap is not; hook them as
        // admin-ajax.php would.
        FormEditor::init();
        PDFLayoutEditor::init();
        FormSettings::init();
        $this->first  = self::factory()->user->create(['role' => 'administrator', 'display_name' => 'Ada First']);
        $this->second = self::factory()->user->create(['role' => 'administrator', 'display_name' => 'Bob Second']);
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- overrides WP_UnitTestCase's snake_case fixture method.
    public function tear_down(): void
    {
        $_GET = [];
        parent::tear_down();
    }

    public function testTheSecondAdminInTheFormEditorIsToldWhoIsEditing(): void
    {
        $form = $this->createForm([['id' => 'name', 'type' => 'text', 'label' => 'Name']], [self::notification()]);

        wp_set_current_user($this->first);
        self::assertStringNotContainsString('Currently being edited by', $this->editor($form));

        wp_set_current_user($this->second);
        self::assertStringContainsString('Currently being edited by Ada First', $this->editor($form));
        $beat = FormEditor::heartbeatReceived([], ['fabricator_forms_lock' => $form]);
        self::assertSame('Ada First', $beat['fabricator_forms_lock_conflict'] ?? null, 'the open tab learns it too');
    }

    public function testTheLockIsReleasedWhenTheFirstTabClosesAndOnlyByItsOwner(): void
    {
        $form = $this->createForm([['id' => 'name', 'type' => 'text', 'label' => 'Name']], [self::notification()]);
        wp_set_current_user($this->first);
        $this->editor($form);

        wp_set_current_user($this->second);
        $this->ajax('fabricator_forms_unlock_form', ['form_id' => (string) $form, 'nonce' => wp_create_nonce('fabricator_forms_admin_nonce')]);
        self::assertSame($this->first, (int) wp_check_post_lock($form), 'a beacon from another user releases nothing');

        wp_set_current_user($this->first);
        $this->ajax('fabricator_forms_unlock_form', ['form_id' => (string) $form, 'nonce' => wp_create_nonce('fabricator_forms_admin_nonce')]);

        // The second admin's open tab: its next heartbeat carries no conflict (admin-editor-lock.js then hides the
        // notice), and the lock is now its own.
        wp_set_current_user($this->second);
        $beat = FormEditor::heartbeatReceived([], ['fabricator_forms_lock' => $form]);
        self::assertArrayNotHasKey('fabricator_forms_lock_conflict', $beat);
        $lock = explode(':', (string) get_post_meta($form, '_edit_lock', true));
        self::assertSame($this->second, (int) ($lock[1] ?? 0), 'the lock is now this admin\'s');
        self::assertStringNotContainsString('Currently being edited by', $this->editor($form), 'the notice is gone once the first tab closed');
    }

    public function testASaveWhileAnotherAdminHoldsTheLockIsRefused(): void
    {
        $form = $this->createForm([['id' => 'name', 'type' => 'text', 'label' => 'Name']], [self::notification()]);
        wp_set_current_user($this->first);
        $this->editor($form);

        wp_set_current_user($this->second);
        $r = $this->saveForm($form, 'Renamed by Bob', FormModel::snapshot($form));

        self::assertFalse($r['success']);
        self::assertStringContainsString('locked for editing by Ada First', $r['data']['message']);
        self::assertSame('Test form', FormModel::get($form)->title);
    }

    public function testTwoSavesFromTheSameLoadedFormAreNotBothAccepted(): void
    {
        // The notice dismissed, or two tabs of the same admin: both saves carry the snapshot their page loaded with.
        // Called on FormModel::save(), where the check lives: ajaxSave() wraps its success response in a catch-all,
        // which under the test's wp_die() handler would catch that response's own exit.
        $form = $this->createForm([['id' => 'name', 'type' => 'text', 'label' => 'Name']], [self::notification()]);
        wp_set_current_user($this->first);
        // The page was loaded a while after the form's last change.
        global $wpdb;
        $wpdb->update($wpdb->posts, ['post_modified_gmt' => gmdate('Y-m-d H:i:s', time() - 60)], ['ID' => $form]);
        clean_post_cache($form);
        $loaded = FormModel::snapshot($form);
        $model  = FormModel::get($form);
        $save   = static fn(string $title) => FormModel::save(
            ['title' => $title, 'fields' => $model->fields, 'notifications' => $model->notifications, 'settings' => $model->settings],
            $form,
            true,
            $loaded
        );

        self::assertSame($form, $save('First tab'));
        $second = $save('Second tab');

        self::assertWPError($second, 'the second save from the stale page is refused');
        self::assertSame('conflict', $second->get_error_code(), 'answered 409 by ajaxSave()');
        self::assertSame('First tab', FormModel::get($form)->title);
    }

    public function testTwoSavesInTheSameSecondAsTheLoadAreStillToldApart(): void
    {
        // post_modified_gmt has one-second resolution: a page loaded right after a change, and saved within that same
        // second, carried a snapshot that still matched after another save, which it then overwrote. The save counter in
        // the snapshot tells them apart without the timestamp moving (FormModel::snapshot()).
        global $wpdb;
        $form = $this->createForm([['id' => 'name', 'type' => 'text', 'label' => 'Name']], [self::notification()]);
        wp_set_current_user($this->first);
        // Every write lands in the same second: the timestamp is pinned back after each one.
        $same_second = static function () use ($wpdb, $form): void {
            $wpdb->update($wpdb->posts, ['post_modified_gmt' => '2026-01-01 00:00:00'], ['ID' => $form]);
            clean_post_cache($form);
        };
        $same_second();
        $loaded = FormModel::snapshot($form);
        $model  = FormModel::get($form);
        $save   = static fn(string $title, string $snapshot) => FormModel::save(
            ['title' => $title, 'fields' => $model->fields, 'notifications' => $model->notifications, 'settings' => $model->settings],
            $form,
            true,
            $snapshot
        );

        self::assertSame($form, $save('First tab', $loaded));
        $same_second();
        $second = $save('Second tab', $loaded);

        self::assertWPError($second, 'refused although the timestamp did not move');
        self::assertSame('conflict', $second->get_error_code());
        self::assertSame('First tab', FormModel::get($form)->title);
        self::assertSame($form, $save('Reloaded', FormModel::snapshot($form)), 'the page that took the new snapshot saves');
    }

    public function testTheSecondAdminInThePdfLayoutEditorIsToldWhoIsEditing(): void
    {
        wp_set_current_user($this->first);
        self::assertStringNotContainsString('Currently being edited by', $this->page([PDFLayoutEditor::class, 'render']));

        wp_set_current_user($this->second);
        self::assertStringContainsString('Currently being edited by Ada First', $this->page([PDFLayoutEditor::class, 'render']));

        wp_set_current_user($this->first);
        $this->ajax('fabricator_forms_unlock_pdf_layout', ['nonce' => wp_create_nonce('fabricator_forms_admin_nonce')]);
        wp_set_current_user($this->second);
        self::assertStringNotContainsString('Currently being edited by', $this->page([PDFLayoutEditor::class, 'render']));
    }

    public function testASecondPdfLayoutSaveFromTheSameLoadedPageIsRefused(): void
    {
        wp_set_current_user($this->first);
        $snapshot = md5((string) wp_json_encode(get_option('fabricator_forms_pdf_layout', [])));
        $save = fn(string $color): array => $this->ajax('fabricator_save_pdf_layout', [
            'fabricator_pdf_layout_nonce'    => wp_create_nonce('fabricator_pdf_layout'),
            'fabricator_pdf_layout_snapshot' => $snapshot,
            'accent_color'                   => $color,
        ]);

        self::assertTrue($save('#112233')['success']);
        $second = $save('#445566');
        self::assertFalse($second['success']);
        self::assertStringContainsString('changed elsewhere', $second['data']['message']);
    }

    public function testTheSecondAdminInSettingsIsToldWhoIsEditing(): void
    {
        update_option('fabricator_forms_seal_setup_done', true, false);
        wp_set_current_user($this->first);
        $this->page([FormSettings::class, 'renderSettingsPage']);

        wp_set_current_user($this->second);
        self::assertStringContainsString('Currently being edited by Ada First', $this->page([FormSettings::class, 'renderSettingsPage']));
    }

    private function editor(int $form): string
    {
        $_GET = ['form_id' => (string) $form];
        return $this->page([FormEditor::class, 'render']);
    }

    private function page(callable $render): string
    {
        ob_start();
        $render();
        return (string) ob_get_clean();
    }

    /**
     * Saves $form as the builder does, with the snapshot its page loaded with.
     *
     * @return array{success: bool, data: mixed}
     */
    private function saveForm(int $form, string $title, string $snapshot): array
    {
        $model = FormModel::get($form);
        $data  = [
            'id'            => $form,
            'title'         => $title,
            'fields'        => $model->fields,
            'notifications' => $model->notifications,
            'settings'      => $model->settings,
            'snapshot'      => $snapshot,
        ];
        return $this->ajax('fabricator_forms_save_form', [
            'nonce'     => wp_create_nonce('fabricator_forms_admin_nonce'),
            'form_data' => base64_encode((string) wp_json_encode($data)),
        ]);
    }
}
