<?php

namespace FabricatorForms\Tests\Integration\Form;

use FabricatorForms\Form\FormModel;
use FabricatorForms\Tests\Integration\TestCase;
use FabricatorForms\Utils\Assets;

/**
 * A form placed anywhere a shortcode runs brings its scripts and styles along (TESTING.md §2): a post, a text
 * widget, a block template (the Shortcode block). Page builders stay a manual check. A broken shortcode leaves the page
 * rendering.
 */
final class EmbedTest extends TestCase
{
    private int $form = 0;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- overrides WP_UnitTestCase's snake_case fixture method.
    public function set_up(): void
    {
        parent::set_up();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $form = FormModel::save([
            'title'         => 'Contact',
            'fields'        => [['id' => 'name', 'type' => 'text', 'label' => 'Name'], ['id' => 'when', 'type' => 'date', 'label' => 'When']],
            'notifications' => [],
            'settings'      => [],
        ], 0, true);
        self::assertIsInt($form);
        $this->form = $form;
        wp_set_current_user(0);
        self::resetAssets();
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- overrides WP_UnitTestCase's snake_case fixture method.
    public function tear_down(): void
    {
        self::resetAssets();
        parent::tear_down();
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function brokenShortcodes(): iterable
    {
        yield 'no id' => ['[fabricator_form]'];
        yield 'id 0' => ['[fabricator_form id="0"]'];
        yield 'not a number' => ['[fabricator_form id="abc"]'];
        yield 'negative' => ['[fabricator_form id="-3"]'];
        yield 'no such post' => ['[fabricator_form id="987654"]'];
        yield 'form selection without id' => ['[fabricator_form_select]'];
        yield 'no such form selection' => ['[fabricator_form_select id="987654"]'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('brokenShortcodes')]
    public function testAMissingOrInvalidFormIdRendersNothingAndNoFatal(string $shortcode): void
    {
        self::assertSame('<p>before</p><p>after</p>', do_shortcode('<p>before</p>' . $shortcode . '<p>after</p>'));
        self::assertFalse(wp_script_is('fabricator-forms-front', 'enqueued'), 'nothing loaded for nothing shown');
    }

    public function testAnotherPostTypesIdIsNotRenderedAsAForm(): void
    {
        $post = self::factory()->post->create(['post_title' => 'Just a post']);

        self::assertSame('', do_shortcode('[fabricator_form id="' . $post . '"]'));
    }

    public function testAFormInAPostLoadsItsAssetsOnWpEnqueueScripts(): void
    {
        $post = self::factory()->post->create(['post_content' => '[fabricator_form id="' . $this->form . '"]']);
        $this->go_to(get_permalink($post));

        do_action('wp_enqueue_scripts');

        $this->assertFrontAssetsReady();
    }

    public function testAFormInATextWidgetLoadsItsAssets(): void
    {
        $widget = new \WP_Widget_Text();
        ob_start();
        $widget->widget(
            ['before_widget' => '<section>', 'after_widget' => '</section>', 'before_title' => '<h2>', 'after_title' => '</h2>'],
            ['title' => 'Contact', 'text' => '[fabricator_form id="' . $this->form . '"]', 'filter' => true, 'visual' => true]
        );
        $html = (string) ob_get_clean();

        self::assertStringContainsString('<form', $html);
        $this->assertFrontAssetsReady();
    }

    public function testAFormInABlockTemplateLoadsItsAssets(): void
    {
        // A block theme's template, rendered as WordPress renders one (get_the_block_template_html()), after wp_head:
        // the enqueue fast path saw no shortcode in any post_content.
        $GLOBALS['_wp_current_template_content'] = '<!-- wp:group --><div class="wp-block-group"><!-- wp:shortcode -->'
            . '[fabricator_form id="' . $this->form . '"]<!-- /wp:shortcode --></div><!-- /wp:group -->';
        $html = get_the_block_template_html();
        unset($GLOBALS['_wp_current_template_content']);

        self::assertStringContainsString('<form', $html);
        $this->assertFrontAssetsReady();
    }

    public function testTheSameFormTwiceLoadsItsAssetsOnce(): void
    {
        do_shortcode('[fabricator_form id="' . $this->form . '"][fabricator_form id="' . $this->form . '"]');

        self::assertSame(1, substr_count($this->printedScripts(), 'window.FabricatorFieldInits='));
    }

    public function testTwoDifferentFormsInASelectionSharingAFieldIdGetDistinctIdsAndLabels(): void
    {
        // TESTING.md §2: clicking a question focused the answer in the other form. Every id on the page is unique,
        // and each label points at an input inside its own form. Clicking and submitting stay browser checks.
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $other = FormModel::save([
            'title'         => 'Callback',
            'fields'        => [['id' => 'name', 'type' => 'text', 'label' => 'Your name'], ['id' => 'when', 'type' => 'date', 'label' => 'Call me on']],
            'notifications' => [],
            'settings'      => [],
        ], 0, true);
        $selection = \FabricatorForms\Form\FormSelectModel::save(['title' => 'Pick one', 'items' => [
            ['form_id' => $this->form, 'label' => 'Contact', 'favorite' => true],
            ['form_id' => $other, 'label' => 'Callback'],
        ]], 0, true);
        wp_set_current_user(0);

        $html = do_shortcode('[fabricator_form_select id="' . $selection . '"]');

        $doc = new \DOMDocument();
        @$doc->loadHTML('<?xml encoding="utf-8"?><body>' . $html . '</body>');
        $xpath = new \DOMXPath($doc);
        $ids   = array_map(static fn(\DOMAttr $a): string => $a->value, iterator_to_array($xpath->query('//@id')));
        self::assertSame(array_unique($ids), $ids, 'no id twice on the page');
        $forms = $xpath->query('//form');
        self::assertSame(2, $forms->length);
        foreach ($xpath->query('//label[@for]') as $label) {
            $target = $xpath->query('//*[@id="' . $label->getAttribute('for') . '"]')->item(0);
            self::assertNotNull($target, 'label for="' . $label->getAttribute('for') . '" points at something');
            $formOf = static function (\DOMNode $n): ?\DOMNode {
                while ($n !== null && $n->nodeName !== 'form') {
                    $n = $n->parentNode;
                }
                return $n;
            };
            self::assertSame($formOf($label), $formOf($target), 'a label and its input are in the same form');
        }
    }

    /**
     * front.js is enqueued with its localization and every field global, and front.css with the field styles: what a
     * page needs for the form to work, whichever way it was placed.
     */
    private function assertFrontAssetsReady(): void
    {
        self::assertTrue(wp_script_is('fabricator-forms-front', 'enqueued'), 'front.js enqueued');
        self::assertTrue(wp_style_is('fabricator-forms-front', 'enqueued'), 'front.css enqueued');
        $printed = $this->printedScripts();
        self::assertStringContainsString('assets/js/front.js', $printed);
        self::assertStringContainsString('var FabricatorForms =', $printed, 'the localization');
        foreach (array_keys(Assets::frontFieldAssets()['globals']) as $global) {
            self::assertStringContainsString('window.' . $global . '=', $printed, "$global before front.js");
        }
        self::assertNotEmpty(wp_styles()->get_data('fabricator-forms-front', 'after'), 'the field styles are inlined');
    }

    private function printedScripts(): string
    {
        ob_start();
        wp_scripts()->do_items(['fabricator-forms-front']);
        return (string) ob_get_clean();
    }

    /**
     * A page view starts with nothing enqueued: fresh script and style queues, and Assets' once-per-request flags down.
     */
    private static function resetAssets(): void
    {
        $GLOBALS['wp_scripts'] = null;
        $GLOBALS['wp_styles']  = null;
        foreach (['front_assets_done', 'select_assets_done'] as $flag) {
            $property = new \ReflectionProperty(Assets::class, $flag);
            $property->setValue(null, false);
        }
    }
}
