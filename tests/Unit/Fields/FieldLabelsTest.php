<?php

namespace FabricatorForms\Tests\Unit\Fields;

use FabricatorForms\Fields\RatingField;
use FabricatorForms\Tests\Support\FieldStubs;
use FabricatorForms\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * BaseField::wrap()'s accessibility rule: a field rendering one control gets <label for>, and a field rendering a set
 * of controls names the set (role="group" + aria-labelledby) instead of pointing a label at an id that isn't there.
 * This is the markup a screen reader relies on (TESTING.md §2 and §4); listening to it stays manual.
 */
final class FieldLabelsTest extends TestCase
{
    private const FID = 'question1';

    protected function setUp(): void
    {
        parent::setUp();
        FieldStubs::install();
    }

    /**
     * @return array<string, array{string, array<string, mixed>, bool}>
     */
    public static function fields(): array
    {
        $two = [['value' => 'a', 'label' => 'A'], ['value' => 'b', 'label' => 'B']];
        return [
            'text'                   => ['TextField', [], true],
            'email'                  => ['EmailField', [], true],
            'textarea'               => ['TextareaField', [], true],
            'select'                 => ['SelectField', ['options' => [['value' => 'a', 'label' => 'A']]], true],
            'checkbox set'           => ['CheckboxField', ['options' => $two], false],
            'radio set'              => ['RadioField', ['options' => $two], false],
            'rating'                 => ['RatingField', [], false],
            'signature'              => ['SignatureField', [], false],
            'name (simple)'          => ['NameField', ['expanded' => false], true],
            'name (sub-fields on)'   => ['NameField', ['expanded' => true], false],
            'address (simple)'       => ['AddressField', ['expanded' => false], true],
            'address (sub-fields on)' => ['AddressField', ['expanded' => true], false],
        ];
    }

    #[DataProvider('fields')]
    public function testLabelOrGroupName(string $short, array $extra, bool $ownsControl): void
    {
        $class   = 'FabricatorForms\\Fields\\' . $short;
        $handler = new $class();
        $config  = array_merge($handler->getDefaultConfig(), ['label' => 'Your answer'], $extra);
        $html    = $handler->render($config, self::FID);
        $fid     = self::FID;

        self::assertSame($ownsControl, $handler->labelsOwnControl($config));
        if ($ownsControl) {
            self::assertStringContainsString('for="' . $fid . '"', $html);
            self::assertMatchesRegularExpression('/\sid="' . $fid . '"/', $html, 'the labelled element exists');
            self::assertStringNotContainsString('role="group"', $html);
            return;
        }
        self::assertStringNotContainsString('for="' . $fid . '"', $html, 'no label pointing at a missing id');
        self::assertSame(1, substr_count($html, 'role="group"'), 'exactly one group: nested groups announce the name twice');
        self::assertStringContainsString('aria-labelledby="' . $fid . '-label"', $html);
        self::assertStringContainsString('id="' . $fid . '-label"', $html);
    }

    public function testAHiddenOrEmptyLabelLeavesNoDanglingReference(): void
    {
        $rating = new RatingField();
        $hidden = $rating->render(array_merge($rating->getDefaultConfig(), ['label' => 'Rating', 'hide_label' => true]), self::FID);
        self::assertStringNotContainsString('aria-labelledby', $hidden);
        self::assertStringNotContainsString('role="group"', $hidden, 'no unnamed group');

        $empty = $rating->render(array_merge($rating->getDefaultConfig(), ['label' => '']), self::FID);
        self::assertStringNotContainsString('aria-labelledby', $empty);
    }
}
