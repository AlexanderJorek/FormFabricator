<?php

namespace FabricatorForms\Tests\Unit\Form;

use Brain\Monkey\Functions;
use FabricatorForms\Form\FormModel;
use FabricatorForms\Form\MailSender;
use FabricatorForms\Tests\Support\Reflect;
use FabricatorForms\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Email routing rules: "is empty" judges the submitted value (not the "[No entry]" display text), "greater"/"less" read
 * the number out of a formatted amount, text comparisons ignore case, and option labels are found inside groups.
 * (TESTING.md §3, routing rules — 1.0.7.)
 */
final class RoutingRulesTest extends TestCase
{
    private const NO_ENTRY = '[No entry]';

    private mixed $previousLocale = null;

    protected function setUp(): void
    {
        parent::setUp();
        Functions\stubTranslationFunctions();
        Functions\stubEscapeFunctions();
        // A German site: decimal comma, thousands point.
        $this->previousLocale  = $GLOBALS['wp_locale'] ?? null;
        $GLOBALS['wp_locale'] = (object) ['number_format' => ['decimal_point' => ',', 'thousands_sep' => '.']];
    }

    protected function tearDown(): void
    {
        $GLOBALS['wp_locale'] = $this->previousLocale;
        parent::tearDown();
    }

    /**
     * @return array<string, array{string, string, string, mixed, bool}>
     */
    public static function rules(): array
    {
        $none = self::NO_ENTRY;
        return [
            'blank field: is empty'                   => [$none, 'empty', '', '', true],
            'blank field: is not empty'               => [$none, 'not_empty', '', '', false],
            'visitor typed "[No entry]": not empty'   => [$none, 'empty', '', $none, false],
            'visitor typed "[No entry]": not_empty'   => [$none, 'not_empty', '', $none, true],
            'filled field: not_empty'                 => ['Berlin', 'not_empty', '', 'Berlin', true],
            'filled field: empty'                     => ['Berlin', 'empty', '', 'Berlin', false],
            'empty checkbox array is empty'           => [$none, 'empty', '', [], true],
            'ticked checkboxes are not empty'         => ['A, B', 'empty', '', ['A', 'B'], false],
            'array of blanks is empty'                => [$none, 'empty', '', ['', '   '], true],
            'whitespace only is empty'                => [$none, 'empty', '', '   ', true],
            'amount greater than 10'                  => ['12,50 €', 'greater', '10', '12.50', true],
            'amount not greater than 20'              => ['12,50 €', 'greater', '20', '12.50', false],
            'amount less than 20'                     => ['12,50 €', 'less', '20', '12.50', true],
            '20,00 € greater than 10 (TESTING.md)'    => ['20,00 €', 'greater', '10', '20.00', true],
            'thousands separator'                     => ['1.234,56 €', 'greater', '1000', '1234.56', true],
            'non-numeric never greater'               => ['abc', 'greater', '1', 'abc', false],
            'non-numeric rule value never matches'    => ['12,50 €', 'greater', 'abc', '12.50', false],
            'equals on the label'                     => ['Ja', 'equals', 'Ja', 'yes', true],
            'equals ignores case'                     => ['JA', 'equals', 'ja', 'yes', true],
            'equals ignores case on umlauts'          => ['Ärzte', 'equals', 'ärzte', 'x', true],
            'contains'                                => ['Hauptstr. 1', 'contains', 'haupt', 'Hauptstr. 1', true],
            'contains with an empty rule value never' => ['Berlin', 'contains', '', 'Berlin', false],
        ];
    }

    #[DataProvider('rules')]
    public function testRuleMatches(string $display, string $operator, string $value, mixed $raw, bool $expected): void
    {
        self::assertSame($expected, Reflect::call(MailSender::class, 'ruleMatches', $display, $operator, $value, $raw));
    }

    public function testWithoutASubmittedValueTheDisplayTextDecides(): void
    {
        // A third party firing the submission action with three arguments passes no raw values.
        self::assertTrue(Reflect::call(MailSender::class, 'valueIsEmpty', '', null));
        self::assertFalse(Reflect::call(MailSender::class, 'valueIsEmpty', self::NO_ENTRY, null));
    }

    public function testNumbersAreReadOutOfFormattedAmounts(): void
    {
        self::assertSame(1234.56, Reflect::call(MailSender::class, 'numericValue', '1.234,56 €', null));
        self::assertSame(12.5, Reflect::call(MailSender::class, 'numericValue', '12,50 €', '12.50'), 'submitted value preferred');
        self::assertNull(Reflect::call(MailSender::class, 'numericValue', 'not a number', null));
    }

    public function testOptionLabelsAreFoundInsideGroups(): void
    {
        $form         = new FormModel();
        $form->fields = [
            ['id' => 'topic', 'type' => 'select', 'options' => [['value' => 'sales', 'label' => 'Vertrieb']]],
            ['id' => 'grp', 'type' => 'group', 'children' => [
                ['id' => 'inner', 'type' => 'radio', 'options' => [['value' => 'yes', 'label' => 'Ja']]],
            ]],
        ];
        $label = static fn(string $field, string $value) => Reflect::call(MailSender::class, 'resolveOptionLabel', $form, $field, $value);

        self::assertSame('Vertrieb', $label('topic', 'sales'));
        self::assertSame('Ja', $label('inner', 'yes'), 'a field inside a group');
        self::assertSame('no', $label('inner', 'no'), 'unknown option falls back to the stored value');
        self::assertSame('x', $label('nope', 'x'), 'unknown field falls back to the stored value');
    }
}
