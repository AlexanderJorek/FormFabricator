<?php

namespace FabricatorForms\Tests\Unit\Form;

use Brain\Monkey\Functions;
use FabricatorForms\Form\FormProcessor;
use FabricatorForms\Tests\Support\Reflect;
use FabricatorForms\Tests\Support\TestCase;

/**
 * The server must evaluate condition rules exactly as front.js does — same lowercasing, same idea of a number —
 * or a field the visitor saw hidden gets validated (or a shown one skipped). The reference below is front.js's logic
 * transcribed to PHP; the JS suite checks front.js itself against the same cases.
 */
final class ConditionsTest extends TestCase
{
    private const OPERATORS = ['equals', 'not_equals', 'contains', 'not_contains', 'empty', 'not_empty', 'greater', 'less'];

    private const VALUES = [
        '', ' ', 'Ja', 'ja', 'JA', 'Ärzte', 'ärzte', 'ÄRZTE', 'Straße', 'STRASSE', 'Grüße',
        '10', '10.5', '-3', '+7', '1e3', '.5', '12abc', 'abc', '0', 'İstanbul', 'istanbul',
        'ÅNGSTRÖM', 'ångström', 'ΣΊΣΥΦΟΣ', 'σίσυφος',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Functions\stubTranslationFunctions();
    }

    public function testScalarValuesDecideAsFrontJsDoes(): void
    {
        foreach (self::VALUES as $submitted) {
            foreach (self::VALUES as $ruleValue) {
                foreach (self::OPERATORS as $op) {
                    $rule = ['field_id' => 'f', 'operator' => $op, 'value' => $ruleValue];
                    $flat = ['f' => $submitted];
                    self::assertSame(self::frontJs($rule, $flat), self::evalRule($rule, $flat), "$op: '$submitted' vs '$ruleValue'");
                }
            }
        }
    }

    public function testCheckboxArraysDecideAsFrontJsDoes(): void
    {
        foreach ([[], ['Ja'], ['ärzte', 'B'], ['', ''], ['10'], ['3', '4'], ['0'], ['-2.5']] as $submitted) {
            foreach (['Ja', 'ÄRZTE', '', '10', '1', '5', '-3', '0'] as $ruleValue) {
                foreach (self::OPERATORS as $op) {
                    $rule = ['field_id' => 'f', 'operator' => $op, 'value' => $ruleValue];
                    $flat = ['f' => $submitted];
                    self::assertSame(self::frontJs($rule, $flat), self::evalRule($rule, $flat), "$op on " . json_encode($submitted) . " vs '$ruleValue'");
                }
            }
        }
    }

    public function testUmlautsCompareWithoutCase(): void
    {
        // The case byte-wise lowercasing got wrong.
        $rule = ['field_id' => 'f', 'operator' => 'equals', 'value' => 'Ä'];
        self::assertTrue(self::evalRule($rule, ['f' => 'ä']));
        self::assertTrue(self::evalRule($rule, ['f' => 'Ä']));
    }

    public function testATrailingTextIsNotANumber(): void
    {
        // parseFloat("12abc") is 12; the rule must not treat it as a number.
        self::assertFalse(self::evalRule(['field_id' => 'f', 'operator' => 'greater', 'value' => '1'], ['f' => '12abc']));
    }

    public function testSubmitButtonConditionsWithMatchAll(): void
    {
        $blocked = self::submitBlocked('all');
        self::assertFalse($blocked(['agree' => 'yes', 'amount' => '150']), 'both rules met: allowed');
        self::assertTrue($blocked(['agree' => 'no', 'amount' => '150']), 'first rule unmet: refused');
        self::assertTrue($blocked(['agree' => 'yes', 'amount' => '50']), 'second rule unmet: refused');
        self::assertTrue($blocked([]), 'nothing submitted: refused');
    }

    public function testSubmitButtonConditionsWithMatchAny(): void
    {
        $blocked = self::submitBlocked('any');
        self::assertFalse($blocked(['agree' => 'yes', 'amount' => '50']), 'one rule is enough');
        self::assertTrue($blocked(['agree' => 'no', 'amount' => '50']), 'no rule met: refused');
    }

    public function testAFormWithoutRulesIsNeverBlocked(): void
    {
        self::assertFalse(Reflect::call(FormProcessor::class, 'isHiddenByConditions', ['conditions' => ['action' => 'show', 'match' => 'all', 'rules' => []]], []));
    }

    private static function evalRule(array $rule, array $flat): bool
    {
        return Reflect::call(FormProcessor::class, 'evalConditionRule', $rule, $flat);
    }

    private static function submitBlocked(string $match): \Closure
    {
        $conditions = ['action' => 'show', 'enabled' => true, 'match' => $match, 'rules' => [
            ['field_id' => 'agree', 'operator' => 'equals', 'value' => 'yes'],
            ['field_id' => 'amount', 'operator' => 'greater', 'value' => '100'],
        ]];
        return static fn(array $flat): bool => Reflect::call(FormProcessor::class, 'isHiddenByConditions', ['conditions' => $conditions], $flat);
    }

    /**
     * front.js's rule evaluation: String.prototype.toLowerCase() and its is_numeric()-shaped number test.
     */
    private static function frontJs(array $rule, array $flat): bool
    {
        $lower  = static fn(string $s): string => mb_strtolower($s, 'UTF-8');
        $number = static fn(string $s): ?float => preg_match('/^[+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?$/', trim($s)) === 1 ? (float) trim($s) : null;

        $val   = $flat[$rule['field_id']] ?? '';
        $rv    = $lower((string) ($rule['value'] ?? ''));
        $isArr = is_array($val);
        $str   = $isArr ? '' : $lower((string) $val);
        $all   = $isArr ? array_map($lower, array_map('strval', $val)) : [];
        return match ($rule['operator']) {
            'equals'       => $isArr ? in_array($rv, $all, true) : $str === $rv,
            'not_equals'   => $isArr ? !in_array($rv, $all, true) : $str !== $rv,
            'contains'     => $rv !== '' && ($isArr ? (bool) array_filter($all, static fn($v) => str_contains($v, $rv)) : str_contains($str, $rv)),
            'not_contains' => $rv !== '' && ($isArr ? !array_filter($all, static fn($v) => str_contains($v, $rv)) : !str_contains($str, $rv)),
            'empty'        => $isArr ? $val === [] : $str === '',
            'not_empty'    => $isArr ? $val !== [] : $str !== '',
            'greater'      => $number($str) !== null && $number($rv) !== null && $number($str) > $number($rv),
            'less'         => $number($str) !== null && $number($rv) !== null && $number($str) < $number($rv),
            default        => false,
        };
    }
}
