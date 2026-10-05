<?php

namespace FabricatorForms\Tests\Unit\Fields;

use FabricatorForms\Fields\ConsentField;
use FabricatorForms\Fields\DirectDebitField;
use FabricatorForms\Tests\Support\FieldStubs;
use FabricatorForms\Tests\Support\TestCase;

/**
 * The mail, the PDF and the seal must name the terms the visitor saw, not only record that something was agreed or
 * signed: the form's wording can change after the submission.
 */
final class RecordedTermsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FieldStubs::install();
        FieldStubs::registry();
    }

    public function testASepaMandateRecordsItsTermsBetweenAStartAndAnEnd(): void
    {
        $config = [
            'mandate_title' => 'Lastschriftmandat',
            'mandate_text'  => '<p>I authorise Club e.V.</p><p>My bank shall pay.</p>',
            'mandate_note'  => 'Refunds within 8 weeks.',
            'creditor_id'   => 'DE98ZZZ09999999999',
            'mandate_ref'   => 'M-0042',
        ];
        $value   = ['iban' => 'DE89370400440532013000', 'bic' => 'COBADEFFXXX', 'holder' => 'Ada Lovelace', 'sig' => ''];
        $entries = (new DirectDebitField())->mapNormalized('sepa', 'SEPA', $value, $config, []);

        self::assertSame(
            ['sepa_begin', 'sepa_creditor', 'sepa_ref', 'sepa_iban', 'sepa_bic', 'sepa_holder', 'sepa_date', 'sepa_sig', 'sepa_end'],
            array_keys($entries)
        );
        self::assertSame(['Date of signing', date('Y-m-d')], [$entries['sepa_date']['label'], $entries['sepa_date']['value']], 'always recorded');
        self::assertSame('Lastschriftmandat', $entries['sepa_begin']['label']);
        self::assertSame("I authorise Club e.V.\nMy bank shall pay.\n\nRefunds within 8 weeks.", $entries['sepa_begin']['value']);
        self::assertSame('DE98ZZZ09999999999', $entries['sepa_creditor']['value']);
        self::assertSame('M-0042', $entries['sepa_ref']['value'], 'own text: as typed');
        self::assertSame('End of Lastschriftmandat', $entries['sepa_end']['label']);
    }

    public function testTheCreditorsLinesThePaymentTypeAndTheDebtorDetailsAreRecordedWhenSet(): void
    {
        $config = [
            'creditor_id'      => 'DE98ZZZ09999999999',
            'creditor_name'    => 'Club e.V.',
            'creditor_address' => 'Hauptstr. 1, 10115 Berlin',
            'payment_type'     => 'recurrent',
            'debtor_address'   => true,
            'signing_place'    => true,
        ];
        $value   = [
            'iban' => 'DE89370400440532013000', 'bic' => 'COBADEFFXXX', 'holder' => 'Ada Lovelace', 'sig' => '',
            'street' => 'Main St 1', 'postcode' => '10115', 'city' => 'Berlin', 'country' => 'Germany', 'place' => 'Berlin',
        ];
        $entries = (new DirectDebitField())->mapNormalized('dd', 'Mandate', $value, $config, []);

        self::assertSame(
            [
                'dd_begin', 'dd_creditor_name', 'dd_creditor_address', 'dd_creditor', 'dd_payment_type',
                'dd_iban', 'dd_bic', 'dd_holder', 'dd_street', 'dd_postcode', 'dd_city', 'dd_country', 'dd_place',
                'dd_date', 'dd_sig', 'dd_end',
            ],
            array_keys($entries)
        );
        self::assertSame(['Creditor', 'Club e.V.'], [$entries['dd_creditor_name']['label'], $entries['dd_creditor_name']['value']]);
        self::assertSame('Recurrent payment', $entries['dd_payment_type']['value']);
        self::assertSame('Street and number:', $entries['dd_street']['label']);
        self::assertSame(date('Y-m-d'), $entries['dd_date']['value'], "the site's date format (stubbed)");

        // The page shows the same creditor lines.
        $html = (new DirectDebitField())->render($config, 'dd');
        self::assertStringContainsString('<p>Creditor: Club e.V.</p><p>Creditor address: Hauptstr. 1, 10115 Berlin</p>', $html);
        self::assertStringContainsString('<p>Type of payment: Recurrent payment</p>', $html);

        // Not set, not recorded: an unknown payment type states none. The date of signing is always there.
        $plain = (new DirectDebitField())->mapNormalized('dd', 'Mandate', $value, ['payment_type' => 'weekly'], []);
        self::assertSame(['dd_begin', 'dd_iban', 'dd_bic', 'dd_holder', 'dd_date', 'dd_sig', 'dd_end'], array_keys($plain));
    }

    public function testEveryMandateGetsItsOwnReference(): void
    {
        // One static reference per form named every visitor's mandate alike; the EPC rulebook wants one per mandate.
        $field = new DirectDebitField();
        $value = ['iban' => 'DE89370400440532013000', 'bic' => 'COBADEFFXXX', 'holder' => 'Ada Lovelace', 'sig' => ''];
        $ref   = static fn(array $config): string => $field->mapNormalized('dd', 'Mandate', $value, ['ref_mode' => 'generated'] + $config, [])['dd_ref']['value'];

        self::assertMatchesRegularExpression('/^ACME-\d{8}-[0-9A-F]{10}$/', $ref(['mandate_ref_prefix' => 'ACME']));
        self::assertNotSame($ref(['mandate_ref_prefix' => 'ACME']), $ref(['mandate_ref_prefix' => 'ACME']));
        self::assertMatchesRegularExpression('/^\d{8}-[0-9A-F]{10}$/', $ref([]), 'no prefix');
        // Only the SEPA character set, and 35 characters at most whatever the prefix.
        $long = $ref(['mandate_ref_prefix' => 'Ümlaut & <b>Club</b> e.V. with a very long name']);
        self::assertLessThanOrEqual(35, strlen($long));
        self::assertMatchesRegularExpression("#^[A-Za-z0-9/?:().,'+ -]+$#", $long);
    }

    public function testTheReferenceAndTheMandatesFixedTextsAreTheAdminsToWrite(): void
    {
        // A reference such as "Your membership number" could not be written once references were generated; and the
        // creditor and reference labels, the account types and the signature texts were fixed wording.
        $field  = new DirectDebitField();
        $config = [
            'scheme'           => 'ach',
            'ach_text'         => '<p>I authorize the club.</p>',
            'ach_creditor_id'  => 'CLUB-1',
            'mandate_ref'      => 'Your membership number',
            'ach_creditor_label'   => 'Club ID',
            'ach_reference_label'  => 'Mandate reference',
            'account_type_placeholder' => 'Choose one',
            'checking_label'   => 'Current account',
            'savings_label'    => 'Savings account',
            'sig_hint'         => 'Please sign inside the box',
            'clear_label'      => 'Start again',
        ];

        $html = $field->render($config, 'dd');
        self::assertStringContainsString('Mandate reference: Your membership number', $html);
        self::assertStringContainsString('Club ID: CLUB-1', $html);
        self::assertStringContainsString('>Choose one</option>', $html);
        self::assertStringContainsString('>Current account</option>', $html);
        self::assertStringContainsString('Please sign inside the box', $html);
        self::assertStringContainsString('aria-label="Start again"', $html);

        $value   = ['routing' => '011000015', 'account' => '123456789', 'account_type' => 'checking', 'holder' => 'Ada', 'sig' => ''];
        $entries = $field->mapNormalized('dd', 'Mandate', $value, $config, []);
        self::assertSame(['Mandate reference', 'Your membership number'], [$entries['dd_ref']['label'], $entries['dd_ref']['value']]);
        self::assertSame('Club ID', $entries['dd_creditor']['label']);
        self::assertSame('Current account', $entries['dd_account_type']['value']);

        // Generated: the page says when it is made, the record holds the one made.
        $generated = ['ref_mode' => 'generated', 'mandate_ref_prefix' => 'CLUB', 'ref_pending_text' => 'follows by email'] + $config;
        self::assertStringContainsString('Mandate reference: follows by email', $field->render($generated, 'dd'));
        self::assertMatchesRegularExpression('/^CLUB-\d{8}-[0-9A-F]{10}$/', $field->mapNormalized('dd', 'Mandate', $value, $generated, [])['dd_ref']['value']);

        // No text and not generated: no reference line, nothing recorded.
        $none = ['mandate_ref' => ''] + $config;
        self::assertStringNotContainsString('Mandate reference:', $field->render($none, 'dd'));
        self::assertArrayNotHasKey('dd_ref', $field->mapNormalized('dd', 'Mandate', $value, $none, []));
    }

    public function testAnUntouchedOptionalMandateIsRecordedAsNoEntryWithoutItsTerms(): void
    {
        // Declined direct debit: the terms around "[No entry]" account data read like a mandate granted without details.
        $entries = (new DirectDebitField())->mapNormalized('sepa', 'SEPA', ['iban' => '', 'bic' => '', 'holder' => ' ', 'sig' => ''], ['mandate_title' => 'Mandate'], []);

        self::assertSame(['sepa'], array_keys($entries));
        self::assertSame('[No entry]', $entries['sepa']['value']);
    }

    public function testThePdfBoxesTheMandateAndEachCellHoldsExactlyItsSealedValue(): void
    {
        $field   = new DirectDebitField();
        $entries = $field->mapNormalized('sepa', 'SEPA', ['iban' => 'DE89370400440532013000', 'bic' => '', 'holder' => 'Ada', 'sig' => ''], ['mandate_title' => 'Mandate'], []);

        $begin = $field->pdfData($entries['sepa_begin']);
        $end   = $field->pdfData($entries['sepa_end']);
        self::assertSame(['open', 'Mandate'], $begin['frame'], 'the title is the box heading, outside the markers');
        self::assertSame(['close', ''], $end['frame']);
        self::assertFalse($begin['labeled']);
        self::assertFalse($end['labeled']);

        // The verifier compares the text between a field's markers with its sealed value: nothing else may be in the cell.
        // Found by the verifier: the title in the start cell, and "End of …" in a cell sealed as empty, both mismatched.
        $text = static fn(string $html): string => trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html))));
        self::assertSame($text($entries['sepa_begin']['value']), $text($begin['cell_html']));
        self::assertSame('', $text($end['cell_html']));
        self::assertTrue($field->pdfData($entries['sepa_iban'])['labeled'], 'the account details stay ordinary fields');
        self::assertNull($field->pdfData($entries['sepa_iban'])['frame']);
    }

    public function testAnAchMandateRecordsItsOwnTermsAndNoSepaWording(): void
    {
        // Each scheme keeps its own wording: SEPA's, still in the config from before the scheme was switched, must not
        // end up in an ACH authorization.
        $config = [
            'scheme'          => 'ach',
            'mandate_text'    => '<p>SEPA wording</p>',
            'creditor_id'     => 'DE98ZZZ09999999999',
            'ach_title'       => 'ACH Authorization',
            'ach_text'        => '<p>I authorize Acme Inc.</p>',
            'ach_creditor_id' => '1234567890',
            'mandate_ref'     => 'A-7',
        ];
        $value   = ['routing' => '011000015', 'account' => '0001-2345-6789', 'account_type' => 'checking', 'holder' => 'Ada', 'iban' => 'DE89370400440532013000', 'sig' => ''];
        $entries = (new DirectDebitField())->mapNormalized('dd', 'Mandate', $value, $config, []);

        self::assertSame(
            ['dd_begin', 'dd_creditor', 'dd_ref', 'dd_routing', 'dd_account', 'dd_account_type', 'dd_holder', 'dd_date', 'dd_sig', 'dd_end'],
            array_keys($entries),
            'the ACH details only: a posted IBAN is not part of an ACH mandate'
        );
        self::assertSame('ACH Authorization', $entries['dd_begin']['label']);
        self::assertSame('I authorize Acme Inc.', $entries['dd_begin']['value']);
        self::assertSame(['Company ID', '1234567890'], [$entries['dd_creditor']['label'], $entries['dd_creditor']['value']]);
        self::assertSame('Authorization reference', $entries['dd_ref']['label']);
        self::assertSame('000123456789', $entries['dd_account']['value']);
        self::assertSame('Checking', $entries['dd_account_type']['value']);
    }

    public function testABacsMandateShipsNoWordingOfItsOwn(): void
    {
        $entries = (new DirectDebitField())->mapNormalized('dd', 'Mandate', ['sort_code' => '123456', 'account' => '12345678', 'holder' => 'Ada', 'sig' => ''], ['scheme' => 'bacs', 'mandate_ref' => 'Your membership number'], []);

        self::assertSame('Direct Debit Instruction', $entries['dd_begin']['label'], 'a name, not wording');
        self::assertSame('', $entries['dd_begin']['value'], 'no SEPA text stands in for the missing Bacs one');
        self::assertSame('12-34-56', $entries['dd_sort_code']['value']);
        self::assertSame('Reference', $entries['dd_ref']['label']);
    }

    public function testCheckboxLimitMessagesUseTheRightPluralAndRepeatedValuesCountOnce(): void
    {
        $field = new \FabricatorForms\Fields\CheckboxField();
        $opts  = ['options' => [['label' => 'A', 'value' => 'a'], ['label' => 'B', 'value' => 'b']]];

        self::assertStringContainsString('data-min-message="Please select at least 1 option."', $field->render($opts + ['min_selections' => 1], 'c'));
        self::assertStringContainsString('data-min-message="Please select at least 2 options."', $field->render($opts + ['min_selections' => 2], 'c'));

        // x[]=a&x[]=a: one choice, however often it is posted.
        $verified = new \ReflectionProperty(\FabricatorForms\Form\FormProcessor::class, 'nonceVerified');
        $verified->setValue(null, true);
        \Brain\Monkey\Functions\when('wp_unslash')->returnArg(1);
        \Brain\Monkey\Functions\when('map_deep')->alias(static fn($v, $cb) => array_map($cb, $v));
        $_POST = ['c' => ['a', 'a', 'a']];
        try {
            $value = $field->extractValue('c');
        } finally {
            $verified->setValue(null, false);
            $_POST = [];
        }
        self::assertSame(['a'], $value);
        self::assertIsString($field->validate($value, $opts + ['min_selections' => 2]));
    }

    public function testAMissingConsentTextIsRecordedAsTheTextThePageShows(): void
    {
        $field  = new ConsentField();
        $config = ['label' => 'Consent']; // no consent_text: a hand-built or imported config

        $shown = strip_tags($field->render($config, 'c'));
        preg_match('/"(.*)"/', $field->map('1', $config), $recorded);
        self::assertStringContainsString($recorded[1], $shown);
    }
}
