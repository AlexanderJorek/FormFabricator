<?php

/**
 * Condition and routing rules: whether one rule holds for a submitted answer.
 *
 * PHP Version 8.1
 *
 * @category  FormFabricator
 * @package   FormFabricator
 * @author    Alexander Jorek
 * @copyright 2026 Alexander Jorek
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 * @version   1.1.1
 * @link      https://github.com/AlexanderJorek/FormFabricator
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 3
 * of the License, or (at your option) any later version.
 */

namespace FabricatorForms\Form;

defined('ABSPATH') || exit;

/**
 * Whether one condition or routing rule holds for a submitted answer.
 *
 * The two kinds of rule share their operators but read different values, so each has its own evaluator here:
 *
 * - holds(): a field's or the submit button's condition. It reads the answer as posted and must decide as front.js
 *   does (condition-parity.test.js): case folded as JavaScript folds it, a list compared item by item, a number only
 *   when the answer itself is one.
 * - routes(): a notification's routing rule. It reads the answer as the email shows it (an option's label, an amount
 *   in the site's number format), and the answer as posted where the shown one can mislead (an empty field reads
 *   "[No entry]").
 *
 * Both are fail-safe: a rule with an operator they don't know never holds, and "contains" or "does not contain" with
 * an empty value is a half-configured rule that never holds either.
 */
final class ConditionRule
{
    /**
     * Lowercases as front.js's String.prototype.toLowerCase() does, on every supported PHP version.
     *
     * Before PHP 8.3, mb_strtolower() lowercases a word-final "Σ" to "σ" where JavaScript gives "ς".
     *
     * @param string $s        Text to lowercase.
     * @param bool   $emulated Apply the final-sigma rule by hand; tests set it to check the rule on PHP 8.3+.
     * @return string
     */
    private static function lowerLikeJs(string $s, bool $emulated = PHP_VERSION_ID < 80300): string
    {
        if ($emulated && str_contains($s, 'Σ')) {
            // Final_Sigma: after a letter (skipping case-ignorable marks such as the acute accent) and before none.
            $s = (string) preg_replace('/(?<=\p{L})([\p{Mn}\p{Me}\p{Lm}\p{Sk}\x{0027}\x{2019}]*)Σ(?![\p{Mn}\p{Me}]*\p{L})/u', '$1ς', $s);
        }
        return mb_strtolower($s);
    }

    /**
     * Whether a condition rule holds for the submitted answers.
     *
     * @param array $rule Rule: field_id, operator, value.
     * @param array $flat Flat field_id → value map.
     * @return bool
     */
    public static function holds(array $rule, array $flat): bool
    {
        $fid   = $rule['field_id'] ?? '';
        $op    = $rule['operator'] ?? 'equals';
        // Multibyte-aware, as front.js lowercases "Ä" too.
        $rv    = self::lowerLikeJs((string)($rule['value'] ?? ''));
        $val   = $flat[$fid] ?? '';
        // Strip the "Other" free-text key so it can't accidentally satisfy an equals/contains condition rule.
        if (is_array($val)) {
            unset($val['__other_text__']);
        }
        $isArr = is_array($val);
        // Coerces to strings first: a crafted nested-array POST (e.g. checkboxfield[0][0]=x) would otherwise TypeError strtolower().
        // phpcs:ignore PHPCS_SecurityAudit.BadFunctions.CallbackFunctions.WarnCallbackFunctions -- a fixed callback (a closure or a function name), never input.
        $scalars = $isArr ? array_map(static fn($v) => is_scalar($v) ? (string)$v : '', $val) : [];
        $str     = $isArr ? self::lowerLikeJs(implode(',', $scalars)) : self::lowerLikeJs((string)$val);
        // phpcs:ignore PHPCS_SecurityAudit.BadFunctions.CallbackFunctions.WarnCallbackFunctions -- a fixed callback (a closure or a function name), never input.
        $lower   = $isArr ? array_map(static fn(string $v): string => self::lowerLikeJs($v), $scalars) : [];

        return match ($op) {
            'equals'       => $isArr ? in_array($rv, $lower, strict: true) : $str === $rv,
            'not_equals'   => $isArr ? !in_array($rv, $lower, strict: true) : $str !== $rv,
            'contains'     => $rv !== '' && ($isArr
                // phpcs:ignore PHPCS_SecurityAudit.BadFunctions.CallbackFunctions.WarnCallbackFunctions -- a fixed callback (a closure or a function name), never input.
                ? (bool) array_filter($lower, static fn($v) => str_contains($v, $rv))
                : str_contains($str, $rv)),
            // An empty value is a half-configured rule, not "contains nothing": unsatisfied, like 'contains' above.
            'not_contains' => $rv !== '' && ($isArr
                // phpcs:ignore PHPCS_SecurityAudit.BadFunctions.CallbackFunctions.WarnCallbackFunctions -- a fixed callback (a closure or a function name), never input.
                ? !array_filter($lower, static fn($v) => str_contains($v, $rv))
                : !str_contains($str, $rv)),
            'empty'        => $isArr ? empty($val) : $str === '',
            'not_empty'    => $isArr ? !empty($val) : $str !== '',
            // Never for a list, as in front.js: a ticked checkbox worth "10" is no number.
            'greater'      => !$isArr && is_numeric($str) && is_numeric($rv) && (float)$str > (float)$rv,
            'less'         => !$isArr && is_numeric($str) && is_numeric($rv) && (float)$str < (float)$rv,
            // Fail safe on an unrecognized operator: treat as unsatisfied rather than silently hiding/showing.
            default        => false,
        };
    }

    /**
     * Whether a routing rule matches a submitted answer.
     *
     * @param string $actual   The field's display value, as it appears in the email and PDF.
     * @param string $operator One of the supported comparison operators.
     * @param string $expected The rule's comparison value.
     * @param mixed  $raw      The value as submitted, or null when it isn't available.
     * @param string $option   The rule's value as stored (an option value, before its label replaced it in $expected).
     * @return bool Whether the rule matches.
     */
    public static function routes(
        string $actual,
        string $operator,
        string $expected,
        mixed $raw = null,
        string $option = ''
    ): bool {
        // A multi-value answer equals an option when it is among those chosen, as in holds().
        if (is_array($raw) && ($operator === 'equals' || $operator === 'not_equals')) {
            unset($raw['__other_text__']);
            // phpcs:ignore PHPCS_SecurityAudit.BadFunctions.CallbackFunctions.WarnCallbackFunctions -- a fixed callback (a closure or a function name), never input.
            $chosen = array_map(static fn($v) => is_scalar($v) ? mb_strtolower((string) $v) : '', $raw);
            $hit    = in_array(mb_strtolower($option !== '' ? $option : $expected), $chosen, true);
            return $operator === 'equals' ? $hit : !$hit;
        }
        switch ($operator) {
            case 'not_equals':
                return mb_strtolower($actual) !== mb_strtolower($expected);
            case 'contains':
                return $expected !== '' && mb_stripos($actual, $expected) !== false;
            case 'not_contains':
                // Empty value = half-configured routing rule: unsatisfied, or it would route every submission.
                return $expected !== '' && mb_stripos($actual, $expected) === false;
            case 'empty':
                return self::valueIsEmpty($actual, $raw);
            case 'not_empty':
                return !self::valueIsEmpty($actual, $raw);
            case 'greater':
                $number = self::numericValue($actual, $raw);
                return $number !== null && is_numeric($expected) && $number > (float) $expected;
            case 'less':
                $number = self::numericValue($actual, $raw);
                return $number !== null && is_numeric($expected) && $number < (float) $expected;
            case 'equals':
                return mb_strtolower($actual) === mb_strtolower($expected);
            default:
                return false;
        }
    }

    /**
     * Whether a field was left blank, judged on the value as submitted.
     *
     * A visitor can type "[No entry]", so the display value decides only when the submitted one is unavailable.
     *
     * @param string $display The field's display value.
     * @param mixed  $raw     The value as submitted, or null when it isn't available.
     * @return bool
     */
    private static function valueIsEmpty(string $display, mixed $raw): bool
    {
        if ($raw === null) {
            return trim($display) === '';
        }
        if (is_array($raw)) {
            foreach ($raw as $item) {
                if (is_scalar($item) && trim((string) $item) !== '') {
                    return false;
                }
            }
            return true;
        }
        return trim((string) $raw) === '';
    }

    /**
     * The number behind a field value, for the greater/less operators.
     *
     * Prefers the value as submitted; a display value ("1.234,56 €") is read back through the site's number format.
     *
     * @param string $display The field's display value.
     * @param mixed  $raw     The value as submitted, or null when it isn't available.
     * @return float|null Null when neither holds a number.
     */
    private static function numericValue(string $display, mixed $raw): ?float
    {
        if (is_numeric($raw)) {
            return (float) $raw;
        }
        $display = trim($display);
        if (is_numeric($display)) {
            return (float) $display;
        }
        global $wp_locale;
        $decimal   = is_object($wp_locale) ? (string) ($wp_locale->number_format['decimal_point'] ?? '.') : '.';
        $thousands = is_object($wp_locale) ? (string) ($wp_locale->number_format['thousands_sep'] ?? ',') : ',';
        $plain     = str_replace([$thousands, ' ', "\xc2\xa0"], '', $display);
        if ($decimal !== '') {
            $plain = str_replace($decimal, '.', $plain);
        }
        return preg_match('/-?\d+(?:\.\d+)?/', $plain, $m) === 1 ? (float) $m[0] : null;
    }
}
