<?php

namespace FabricatorForms\Tests\Integration\Fields;

use FabricatorForms\Fields\FieldRegistry;
use FabricatorForms\Tests\Integration\TestCase;

/**
 * A generated mandate reference keeps to the SEPA character set: the prefix's accented letters are written as their base
 * letters, as WordPress writes them for the site's language, and anything else outside the set is left out.
 */
final class MandateReferenceTest extends TestCase
{
    public function testThePrefixWritesAccentedLettersWithoutAccentsAndDropsTheRest(): void
    {
        $field  = ['id' => 'dd', 'type' => 'directdebit', 'label' => 'Direct debit', 'ref_mode' => 'generated', 'mandate_ref_prefix' => 'PREFIX/\\Ä é'];
        $mapped = FieldRegistry::mapSubmission([$field], ['dd' => ['iban' => 'DE89370400440532013000', 'holder' => 'Ada', 'sig' => '']], [], []);

        self::assertMatchesRegularExpression('/^PREFIX\/A e-\d{8}-[0-9A-F]{10}$/', $mapped['dd_ref']['value']);
    }
}
