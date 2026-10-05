<?php

namespace FabricatorForms\Tests\Unit\Fields;

use FabricatorForms\Fields\DirectDebitField;
use FabricatorForms\Tests\Support\FieldStubs;
use FabricatorForms\Tests\Support\TestCase;

/**
 * Direct Debit schemes (SEPA, Bacs, ACH): each checks and shows only its own details and wording. A mandate is all or
 * nothing, SEPA-countries only under SEPA, and never under empty terms.
 */
final class DirectDebitSchemesTest extends TestCase
{
    private const BACS = ['scheme' => 'bacs', 'bacs_text' => '<p>Please pay Acme Ltd Direct Debits from my account.</p>'];
    private const ACH  = ['scheme' => 'ach', 'ach_text' => '<p>I authorize Acme Inc. to debit my account.</p>'];

    private static string $sig = '';

    protected function setUp(): void
    {
        parent::setUp();
        FieldStubs::install();
        if (self::$sig === '') {
            $im = imagecreatetruecolor(400, 100);
            ob_start();
            imagepng($im);
            self::$sig = 'data:image/png;base64,' . base64_encode((string) ob_get_clean());
        }
    }

    /**
     * A complete mandate for the scheme, signed, with $override on top.
     *
     * @param array<string, string> $override
     * @return array<string, string>
     */
    private static function signed(string $scheme, array $override = []): array
    {
        $details = [
            'sepa' => ['iban' => 'DE89370400440532013000', 'bic' => 'COBADEFFXXX'],
            'bacs' => ['sort_code' => '12-34-56', 'account' => '1234 5678'],
            'ach'  => ['routing' => '011000015', 'account' => '123456789', 'account_type' => 'checking'],
        ][$scheme];
        return $override + $details + ['holder' => 'Ada', 'sig' => self::$sig];
    }

    public function testBacsChecksASixDigitSortCodeAndAnEightDigitAccount(): void
    {
        $field = new DirectDebitField();

        self::assertTrue($field->validate(self::signed('bacs'), self::BACS));
        self::assertSame('Please enter a valid sort code (6 digits).', $field->validate(self::signed('bacs', ['sort_code' => '12-34-5']), self::BACS));
        self::assertSame('Please enter a valid account number (8 digits).', $field->validate(self::signed('bacs', ['account' => '1234567']), self::BACS));
        self::assertSame('Sort code is a required field.', $field->validate(self::signed('bacs', ['sort_code' => '']), self::BACS + ['required' => true]));
    }

    public function testAchChecksTheRoutingNumberTheAccountAndItsType(): void
    {
        $field = new DirectDebitField();
        $ach   = static fn(array $override): bool|string => $field->validate(self::signed('ach', $override), self::ACH);

        self::assertTrue($ach([]));
        self::assertTrue($ach(['routing' => '021000021', 'account_type' => 'savings']));
        self::assertSame('Please enter a valid routing number.', $ach(['routing' => '021000022']), 'wrong check digit');
        self::assertSame('Please enter a valid routing number.', $ach(['routing' => '130000006']), 'check digit right, no Federal Reserve prefix');
        self::assertSame('Please enter a valid routing number.', $ach(['routing' => '000000000']));
        self::assertSame('Please enter a valid account number (4 to 17 digits).', $ach(['account' => '123']));
        self::assertSame('Please enter a valid account number (4 to 17 digits).', $ach(['account' => str_repeat('1', 18)]));
        self::assertSame('Please choose the account type.', $ach(['account_type' => 'business']));
        self::assertSame('Please choose the account type.', $ach(['account_type' => '']));
    }

    public function testSepaAcceptsOnlyIbansFromSepaCountries(): void
    {
        $field = new DirectDebitField();
        $sepa  = static fn(string $iban, array $config = []): bool|string => $field->validate(self::signed('sepa', ['iban' => $iban]), $config);

        // Valid IBANs from the registry's examples, outside SEPA: a SEPA mandate cannot debit them.
        foreach (['BR1800360305000010009795493C1', 'PK36SCBL0000001123456702', 'SA0380000000608010167519', 'FO6264600001631634'] as $iban) {
            self::assertSame('IBANs from country "' . substr($iban, 0, 2) . '" are not allowed.', $sepa($iban), $iban);
        }
        // Inside SEPA, including the non-EEA members and a territory with its own IBANs.
        foreach (['CH9300762011623852957', 'GB29NWBK60161331926819', 'GI75NWBK000000007099453', 'RS35260005601001611379'] as $iban) {
            self::assertTrue($sepa($iban), $iban);
        }
        // The field's own filter narrows SEPA and cannot widen it.
        self::assertIsString($sepa('CH9300762011623852957', ['country_filter_mode' => 'allow', 'country_filter_list' => ['DE']]));
        self::assertIsString($sepa('BR1800360305000010009795493C1', ['country_filter_mode' => 'allow', 'country_filter_list' => ['BR']]));
        self::assertSame(count(DirectDebitField::SEPA_COUNTRIES), count(array_intersect(DirectDebitField::SEPA_COUNTRIES, array_keys(DirectDebitField::IBAN_LEN))), 'every SEPA country has an IBAN length');
    }

    public function testAnOptionalMandateIsGivenCompletelyOrNotAtAll(): void
    {
        $field = new DirectDebitField();

        self::assertTrue($field->validate(['iban' => '', 'bic' => '', 'holder' => '', 'sig' => ''], []), 'untouched');
        // A signature and a holder over no account: recorded as a signed mandate with "[No entry]" for the account.
        self::assertSame('IBAN is a required field.', $field->validate(['iban' => '', 'bic' => '', 'holder' => 'Ada', 'sig' => self::$sig], []));
        self::assertSame('Account number is a required field.', $field->validate(self::signed('bacs', ['account' => '']), self::BACS));
        self::assertSame('Signature is a required field.', $field->validate(self::signed('ach', ['sig' => '']), self::ACH));
    }

    public function testAMandateWithoutWordingTakesNoDetails(): void
    {
        $field = new DirectDebitField();
        $bare  = ['scheme' => 'bacs'];

        self::assertTrue(DirectDebitField::lacksWording($bare));
        self::assertFalse(DirectDebitField::lacksWording(self::BACS));
        self::assertFalse(DirectDebitField::lacksWording([]), 'SEPA brings its default wording');
        self::assertTrue(DirectDebitField::lacksWording(['mandate_text' => '<p> </p>', 'mandate_note' => '']), 'SEPA wording cleared');
        self::assertTrue(DirectDebitField::lacksWording(['scheme' => 'ach', 'mandate_text' => '<p>SEPA words</p>']), 'another scheme\'s wording does not count');

        $html = $field->render($bare, 'dd');
        self::assertStringContainsString('Direct debits cannot be set up with this form at the moment.', $html);
        self::assertStringNotContainsString('<input', $html);

        self::assertTrue($field->validate(['sort_code' => '', 'account' => '', 'holder' => '', 'sig' => ''], $bare), 'optional and untouched');
        self::assertSame('Direct debits cannot be set up with this form at the moment.', $field->validate(self::signed('bacs'), $bare));
        self::assertSame('Direct debits cannot be set up with this form at the moment.', $field->validate(['sort_code' => ''], $bare + ['required' => true]));
    }

    public function testEachSchemeChecksOnlyItsOwnDetails(): void
    {
        $field = new DirectDebitField();

        // Valid ACH details posted to a SEPA field are no SEPA mandate; an unknown scheme is SEPA.
        $ach = self::signed('ach');
        self::assertSame('IBAN is a required field.', $field->validate($ach, ['required' => true]));
        self::assertSame('IBAN is a required field.', $field->validate($ach, ['required' => true, 'scheme' => 'swift']));
        // A broken IBAN posted to an ACH field is not the ACH mandate's business.
        self::assertTrue($field->validate(['iban' => 'nonsense'] + $ach, self::ACH));
    }

    public function testARuleReadsTheSchemesDetailsInPageOrder(): void
    {
        $field = new DirectDebitField();
        $raw   = ['iban' => 'DE89', 'routing' => '011000015', 'account' => '123456789', 'account_type' => 'checking', 'holder' => 'Ada', 'sig' => 'x'];

        self::assertSame('011000015 123456789 checking Ada', $field->conditionValue($raw, ['scheme' => 'ach']));
        self::assertSame('DE89 Ada', $field->conditionValue($raw, []));
    }

    public function testThePageShowsOnlyTheSchemesInputsAndWording(): void
    {
        $field    = new DirectDebitField();
        $defaults = $field->getDefaultConfig();

        $html = $field->render(self::BACS + $defaults, 'dd');
        self::assertStringContainsString('name="dd[sort_code]"', $html);
        self::assertStringContainsString('name="dd[account]"', $html);
        self::assertStringContainsString('maxlength="8"', $html);
        self::assertStringNotContainsString('dd[iban]', $html);
        self::assertStringContainsString('data-validate="[&quot;debit-sort-code&quot;,&quot;debit-account&quot;,&quot;debit-required&quot;]"', $html);
        self::assertStringNotContainsString('I hereby authorize', $html, 'SEPA wording stays out of a Bacs mandate');
        self::assertStringContainsString('Direct Debit Instruction', $html);

        $html = $field->render(self::ACH + $defaults, 'dd');
        self::assertMatchesRegularExpression('#<select[^>]*name="dd\[account_type\]"#', $html);
        self::assertSame(3, substr_count($html, '<option'));
        self::assertStringContainsString('name="dd[routing]"', $html);

        self::assertStringContainsString('I hereby authorize', $field->render($defaults, 'dd'));
    }

    public function testIbanCheckDigitsOutsideIso13616AreRefusedThoughMod97Passes(): void
    {
        $field = new DirectDebitField();
        foreach (['DE01370400440000000042', 'DE00370400440000000060', 'DE99370400440000000024'] as $iban) {
            self::assertSame('Please enter a valid IBAN.', $field->validate(self::signed('sepa', ['iban' => $iban]), []), $iban);
        }
        self::assertTrue($field->validate(self::signed('sepa'), []), 'DE89 itself');
    }

    public function testAMandateMissingWhoCollectsOrItsReferenceIsNamedForTheBuilder(): void
    {
        self::assertSame(
            ['Creditor name', 'Creditor identification number', 'Mandate reference'],
            DirectDebitField::missingMandateElements([])
        );
        self::assertSame([], DirectDebitField::missingMandateElements(['creditor_name' => 'Club e.V.', 'creditor_id' => 'DE98ZZZ09999999999', 'mandate_ref' => 'M-1']));
        self::assertSame([], DirectDebitField::missingMandateElements(['creditor_name' => 'Club e.V.', 'creditor_id' => 'DE98ZZZ09999999999', 'ref_mode' => 'generated']), 'a generated reference is always there');
        self::assertSame(['Service user number', 'Reference'], DirectDebitField::missingMandateElements(['scheme' => 'bacs', 'creditor_name' => 'Acme Ltd']));
        self::assertSame(['Company ID'], DirectDebitField::missingMandateElements(['scheme' => 'ach', 'creditor_name' => 'Acme Inc.']), 'ACH has no reference of its own');
    }

    public function testTheBicIsNeededOnlyForAnIbanFromOutsideTheEea(): void
    {
        $field = new DirectDebitField();

        self::assertTrue($field->validate(self::signed('sepa', ['bic' => '']), ['required' => true]), 'a German IBAN alone');
        self::assertSame(
            'The BIC is needed for IBANs from CH.',
            $field->validate(self::signed('sepa', ['iban' => 'CH93 0076 2011 6238 5295 7', 'bic' => '']), ['required' => true])
        );
        self::assertTrue($field->validate(self::signed('sepa', ['iban' => 'CH9300762011623852957']), []), 'with its BIC');
        self::assertSame('Please enter a valid BIC.', $field->validate(self::signed('sepa', ['bic' => 'NOPE']), []), 'one typed is still checked');

        // Its required mark starts hidden: DirectDebitField.js shows it for an IBAN that needs a BIC.
        $html = $field->render(['required' => true] + $field->getDefaultConfig(), 'dd');
        self::assertMatchesRegularExpression('#for="dd-bic">BIC: <span class="fabricator-required fabricator-debit-bic-mark" aria-hidden="true" style="display:none">\*</span></label>#', $html);
        self::assertMatchesRegularExpression('#<label class="fabricator-label" for="dd-iban">IBAN:\s*<span class="fabricator-required"#', $html);
        $optional = $field->render(['required' => false] + $field->getDefaultConfig(), 'dd');
        self::assertStringNotContainsString('fabricator-debit-bic-mark', $optional, 'an optional mandate marks nothing');
        self::assertSame([], array_diff(DirectDebitField::SEPA_NON_EEA, DirectDebitField::SEPA_COUNTRIES), 'all of them SEPA countries');
    }

    public function testDebtorDetailsAreAskedForOnlyWhenSwitchedOnAndThenBelongToTheMandate(): void
    {
        // A form usually asks for the debtor's address in fields of its own; the mandate asks only when told to.
        $field    = new DirectDebitField();
        $defaults = $field->getDefaultConfig();
        $off      = $field->render($defaults, 'dd');
        self::assertStringNotContainsString('dd[street]', $off);
        self::assertStringNotContainsString('dd[place]', $off);

        $on   = ['debtor_address' => true, 'signing_place' => true, 'city_label' => 'Town:'] + $defaults;
        $html = $field->render($on, 'dd');
        foreach (['street', 'postcode', 'city', 'country', 'place'] as $part) {
            self::assertStringContainsString('name="dd[' . $part . ']"', $html, $part);
        }
        self::assertMatchesRegularExpression('/<input type="text"[^>]*name="dd\[city\]"[^>]*data-required-message="Town is a required field\."/', $html, 'the browser names it as the server does');
        self::assertLessThan(strpos($html, 'dd[street]'), strpos($html, 'dd[holder]'), 'after the account details');

        // All or nothing, the debtor details included; their message names the label as set.
        $address = ['street' => 'Main St 1', 'postcode' => '10115', 'city' => 'Berlin', 'country' => 'Germany', 'place' => 'Berlin'];
        self::assertTrue($field->validate(self::signed('sepa', $address), $on));
        self::assertSame('Town is a required field.', $field->validate(self::signed('sepa', ['city' => ''] + $address), $on));
        self::assertTrue($field->validate(['iban' => '', 'bic' => '', 'holder' => '', 'sig' => '', 'street' => '', 'city' => ''], ['required' => false] + $on), 'untouched');
        self::assertTrue($field->validate(self::signed('sepa', ['street' => '']), $defaults), 'switched off: not asked for, not checked');

        // A rule reads them too, in page order after the holder.
        self::assertSame('DE89 Ada Main St 1 10115 Berlin Germany Berlin', $field->conditionValue(['iban' => 'DE89', 'holder' => 'Ada'] + $address, $on));
    }
}
