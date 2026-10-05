<?php

namespace FabricatorForms\Tests\Unit\Fields;

use FabricatorForms\Fields\AddressField;
use FabricatorForms\Fields\EmailField;
use FabricatorForms\Fields\RatingField;
use FabricatorForms\Fields\SliderField;
use FabricatorForms\Tests\Support\FieldStubs;
use FabricatorForms\Tests\Support\TestCase;

/**
 * Values the browser never sends, posted directly, and settings that happen to equal an attribute's name: each field
 * reads them as the page would, and records nothing it did not show.
 */
final class PostedShapesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FieldStubs::install();
    }

    public function testARatingOfZeroIsNoRating(): void
    {
        // The stars start at one, so 0 only arrives by direct POST: it satisfied a required rating as "0 / 5".
        $rating = new RatingField();
        self::assertIsString($rating->validate('0', ['required' => true, 'label' => 'Rating', 'max' => 5]));
        self::assertTrue($rating->validate('0', ['max' => 5]));
        self::assertSame('[No entry]', $rating->map('0', ['max' => 5]));
        self::assertTrue($rating->validate('3', ['required' => true, 'max' => 5]));
    }

    public function testAPlainStringInAnExpandedAddressIsNoAddress(): void
    {
        // It passed validation as empty sub-fields, past every per-sub-field cap, and was then mailed as the address.
        $cfg = ['expanded' => true, 'street_enabled' => true, 'city_enabled' => true];
        self::assertSame('[No entry]', (new AddressField())->map(str_repeat('x', 20000), $cfg));
        self::assertSame('Main St 1', (new AddressField())->map('Main St 1', []), 'one input: the text is the address');
    }

    public function testAnUntouchedRangeSliderIsNoEntry(): void
    {
        $slider = new SliderField();
        self::assertSame('[No entry]', $slider->map(['from' => '', 'to' => ''], ['ranged' => true]));
        self::assertSame('10 – 20', $slider->map(['from' => '10', 'to' => '20'], ['ranged' => true]));
    }

    public function testAPlaceholderSpelledLikeItsAttributeKeepsItsText(): void
    {
        // A value equal to its attribute's name was printed as a bare attribute, so the placeholder "placeholder" vanished.
        $html = (new EmailField())->render(['placeholder' => 'placeholder', 'required' => true], 'e');
        self::assertStringContainsString('placeholder="placeholder"', $html);
        self::assertMatchesRegularExpression('/\srequired[\s>]/', $html, 'required stays a bare attribute');
    }
}
