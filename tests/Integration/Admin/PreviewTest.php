<?php

namespace FabricatorForms\Tests\Integration\Admin;

use FabricatorForms\Admin\FormEditor;
use FabricatorForms\Tests\Integration\AjaxTestCase;
use FabricatorForms\Utils\Assets;

/**
 * The builder preview runs the same field scripts and front-end strings as a real page with the form. It used to build
 * its own copy, which had drifted: two of about fifty strings, so validation messages showed in English on translated
 * sites. (CONTRIBUTING.md: Assets::frontFieldAssets() is the one assembly.)
 */
final class PreviewTest extends AjaxTestCase
{
    public function testThePreviewCarriesTheProductionAssemblyAndStrings(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        // The plugin loads the admin classes only when is_admin(), which the test bootstrap is not; hook them as
        // admin-ajax.php would.
        FormEditor::init();
        $form = $this->createForm([['id' => 'name', 'type' => 'text', 'label' => 'Name', 'required' => true]], [self::notification()]);

        $response = $this->ajax('fabricator_forms_preview', ['form_id' => (string) $form, 'nonce' => wp_create_nonce('fabricator_forms_admin_nonce')]);
        self::assertTrue($response['success'], 'response: ' . $response['raw'] . ' / died: ' . $response['died']);
        $page = (string) $response['data']['html'];

        foreach (Assets::frontFieldAssets()['globals'] as $name => $literal) {
            self::assertStringContainsString('window.' . $name . '=' . $literal . ';', $page, "$name as production assembles it");
        }
        // The whole localization, not two strings of it: one the old copy left out.
        self::assertStringContainsString('"field_required"', $page);
        self::assertStringContainsString('"ajaxUrl":""', $page, 'the preview never submits');
    }
}
