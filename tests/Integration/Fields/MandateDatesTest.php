<?php

namespace FabricatorForms\Tests\Integration\Fields;

use FabricatorForms\Fields\FieldRegistry;
use FabricatorForms\Tests\Integration\TestCase;

/**
 * A mandate's generated reference and its date of signing name the same day: both in the site's timezone. In UTC the
 * reference would read another day than the date of signing for part of every day outside Greenwich's.
 */
final class MandateDatesTest extends TestCase
{
    public function testTheGeneratedReferenceAndTheDateOfSigningNameTheSiteDay(): void
    {
        update_option('date_format', 'Ymd');
        $field = ['id' => 'dd', 'type' => 'directdebit', 'label' => 'Direct debit', 'ref_mode' => 'generated', 'mandate_ref_prefix' => 'ACME'];
        $value = ['iban' => 'DE89370400440532013000', 'bic' => '', 'holder' => 'Ada', 'sig' => ''];

        // UTC+14 is a day ahead of UTC from 10:00 UTC on, UTC-12 ("Etc/GMT+12", POSIX signs) a day behind until 12:00 UTC:
        // at any hour one of them names another day than UTC, so the test catches a UTC date whenever it runs.
        foreach (['Pacific/Kiritimati', 'Etc/GMT+12'] as $zone) {
            update_option('timezone_string', $zone);
            $mapped   = FieldRegistry::mapSubmission([$field], ['dd' => $value], [], []);
            $site_day = wp_date('Ymd');
            self::assertSame($site_day, $mapped['dd_date']['value'], $zone);
            self::assertMatchesRegularExpression('/^ACME-' . $site_day . '-[0-9A-F]{10}$/', $mapped['dd_ref']['value'], $zone);
        }
    }
}
