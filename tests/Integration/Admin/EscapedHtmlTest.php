<?php

namespace FabricatorForms\Tests\Integration\Admin;

use FabricatorForms\Admin\FormList;
use FabricatorForms\Form\FormModel;
use FabricatorForms\Tests\Integration\AjaxTestCase;
use FabricatorForms\Tests\Support\Reflect;

/**
 * Markup printed through wp_kses() with the plugin's own allowlists comes out as it went in: an allowlist that misses
 * a tag or attribute the markup uses would silently drop it from the page.
 */
final class EscapedHtmlTest extends AjaxTestCase
{
    public function testAFormListRowKeepsEveryTagAndAttribute(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $id   = $this->createForm([['id' => 'name', 'type' => 'text', 'label' => 'Name']], [self::notification()]);
        $form = FormModel::get($id);
        self::assertNotNull($form);

        $row = (string) Reflect::call(FormList::class, 'renderRow', $form);

        self::assertStringContainsString('fabricator-row-check', $row);
        self::assertSame(self::dom($row), self::dom(wp_kses($row, Reflect::call(FormList::class, 'rowAllowedHtml'))));
    }

    public function testARefusedFileCardKeepsEveryTagAndAttribute(): void
    {
        $card = \FabricatorForms\Admin\Verificationpage::problemCardHtml('report.pdf', 'This file is no PDF.', false);

        self::assertSame(self::dom($card), self::dom(wp_kses($card, \FabricatorForms\Admin\fabricator_verifier_allowed_html())));
    }

    /**
     * $html as a DOM serializes it, so quoting and entity spelling don't count as differences.
     */
    private static function dom(string $html): string
    {
        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><body>' . $html . '</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        return (string) $doc->saveHTML();
    }
}
