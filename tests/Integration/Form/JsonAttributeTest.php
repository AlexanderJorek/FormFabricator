<?php

namespace FabricatorForms\Tests\Integration\Form;

use FabricatorForms\Form\FormRenderer;
use FabricatorForms\Tests\Integration\TestCase;
use FabricatorForms\Utils\Cast;

/**
 * JSON in an HTML attribute must come back exactly as it went in. esc_attr() does not double-encode, so an entity in
 * the JSON ("&lt;b&gt;" in a consent text or a rule value) was decoded by the browser, and the next save of the form
 * stored real markup. Real esc_attr() here: the unit suite's stub double-encodes and would hide it.
 */
final class JsonAttributeTest extends TestCase
{
    public function testAnEntityInsideJsonSurvivesTheAttribute(): void
    {
        $value = ['text' => 'Tick &lt;b&gt;here&lt;/b&gt; & "quote" \'apostrophe\' <i>'];
        $html  = '<div data-x="' . esc_attr(Cast::jsonForAttribute($value)) . '"></div>';

        self::assertSame($value, json_decode(self::attribute($html, 'data-x'), true));
    }

    public function testAConditionRuleValueWithAnEntityRendersUnchanged(): void
    {
        $rule   = ['field_id' => 'a', 'operator' => 'equals', 'value' => '&lt;b&gt;'];
        $fields = [
            ['id' => 'a', 'type' => 'text', 'label' => 'A'],
            ['id' => 'b', 'type' => 'text', 'label' => 'B', 'conditions' => ['action' => 'show', 'match' => 'all', 'rules' => [$rule]]],
        ];
        $html = (string) (new \ReflectionMethod(FormRenderer::class, 'renderFields'))->invoke(null, $fields);

        $conditions = json_decode(self::attribute($html, 'data-conditions'), true);
        self::assertSame('&lt;b&gt;', $conditions['rules'][0]['value']);
    }

    /**
     * The first value of $name in $html, decoded as a browser decodes it (entities resolved once).
     */
    private static function attribute(string $html, string $name): string
    {
        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><body>' . $html . '</body>');
        libxml_clear_errors();
        $node = (new \DOMXPath($doc))->query('//*[@' . $name . ']')->item(0);
        self::assertNotNull($node, "an element with $name");
        return (string) $node->getAttribute($name);
    }
}
