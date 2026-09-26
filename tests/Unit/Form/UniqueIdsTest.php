<?php

namespace FabricatorForms\Tests\Unit\Form;

use FabricatorForms\Form\FormRenderer;
use FabricatorForms\Tests\Support\Reflect;
use FabricatorForms\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The same form twice on one page: FormRenderer::uniqueIds() suffixes every id and every reference to one (for,
 * aria-describedby, #hash links), and nothing else — names, values and data attributes keep what the server and
 * front.js read. (TESTING.md §2, "same form twice" — 1.0.7.)
 */
final class UniqueIdsTest extends TestCase
{
    private const HTML = '<div class="fabricator-form-wrap" id="fabricator-form-7">'
        . '<form id="fabricator-form-inner-7" data-conditions="{&quot;field_id&quot;:&quot;email&quot;}">'
        . '<label for="email">E-Mail</label>'
        . '<input type="email" id="email" name="email" aria-describedby="email-error email-hint" value="id=&quot;x&quot;">'
        . '<div class="fabricator-field-error" id=\'email-error\' role="alert"></div>'
        . '<p id="email-hint">hint</p><a href="#email">jump</a><a href="#elsewhere">other</a>'
        . '<label for="not-an-id">x</label><span data-id="email">kept</span>'
        . '</form></div>';

    /**
     * @return array<string, array{string}>
     */
    public static function expected(): array
    {
        return [
            'wrap id'              => ['id="fabricator-form-7--2"'],
            'form id'              => ['id="fabricator-form-inner-7--2"'],
            'label for'            => ['for="email--2"'],
            'input id'             => ['id="email--2"'],
            'name untouched'       => ['name="email"'],
            'describedby list'     => ['aria-describedby="email-error--2 email-hint--2"'],
            'single-quoted id'     => ["id='email-error--2'"],
            'hash link'            => ['href="#email--2"'],
            'foreign hash link'    => ['href="#elsewhere"'],
            'for without target'   => ['for="not-an-id"'],
            'data-id untouched'    => ['data-id="email"'],
            'conditions untouched' => ['data-conditions="{&quot;field_id&quot;:&quot;email&quot;}"'],
            'value untouched'      => ['value="id=&quot;x&quot;"'],
        ];
    }

    #[DataProvider('expected')]
    public function testSuffixesIdsAndTheirReferencesOnly(string $needle): void
    {
        self::assertStringContainsString($needle, Reflect::call(FormRenderer::class, 'uniqueIds', self::HTML, '--2'));
    }
}
