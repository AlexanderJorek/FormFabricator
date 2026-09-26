<?php

/**
 * Registry of all available form field types.
 *
 * PHP Version 8.1
 *
 * @category  FormFabricator
 * @package   FormFabricator
 * @author    Alexander Jorek
 * @copyright 2026 Alexander Jorek
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 * @version   1.0.7
 * @link      https://github.com/AlexanderJorek/FormFabricator
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 3
 * of the License, or (at your option) any later version.
 */

namespace FabricatorForms\Fields;

defined('ABSPATH') || exit;

/**
 * Registry of all available form field types.
 */
class FieldRegistry
{
    /**
     * ClassName => 'group:slug' map. Also doubles as the Plugin.php load allowlist.
     *
     * @var array<string, string>
     */
    public const FIELD_MAP = [
        'TextField'       => 'input:text',
        'TextareaField'   => 'input:textarea',
        'EmailField'      => 'input:email',
        'PhoneField'      => 'input:phone',
        'NumberField'     => 'input:number',
        'WebsiteField'    => 'input:website',
        'SelectField'     => 'choice:select',
        'RadioField'      => 'choice:radio',
        'CheckboxField'   => 'choice:checkbox',
        'NameField'       => 'personal:name',
        'AddressField'    => 'personal:address',
        'DateField'       => 'personal:date',
        'TimeField'       => 'personal:time',
        'CurrencyField'   => 'advanced:currency',
        'RatingField'     => 'advanced:rating',
        'SliderField'     => 'advanced:slider',
        'UploadField'     => 'advanced:upload',
        'SignatureField'  => 'advanced:signature',
        'SepaField'       => 'advanced:sepa',
        'HtmlField'       => 'layout:html',
        'GroupField'      => 'layout:group',
        'PageBreakField'  => 'layout:pagebreak',
        'PageHeaderField' => 'layout:page-header',
        'ConsentField'    => 'system:consent',
        'GdprField'       => 'system:gdpr',
        'CaptchaField'    => 'system:captcha',
        'PostDataField'   => 'system:postdata',
    ];

    /**
     * Label/color per palette group (keyed by FIELD_MAP's group prefix).
     *
     * @var array<string, array{label: string, color: string}>
     */
    private const GROUP_META = [
        'input'    => ['label' => 'Input', 'color' => '#82CAFA'],
        'choice'   => ['label' => 'Choice', 'color' => '#A0D468'],
        'personal' => ['label' => 'Personal', 'color' => '#FFB347'],
        'advanced' => ['label' => 'Advanced', 'color' => '#CBA0E6'],
        'layout'   => ['label' => 'Layout', 'color' => '#6ED5C4'],
        'system'   => ['label' => 'System', 'color' => '#F28C8C'],
    ];

    /**
     * Map of field type slugs to their handler class names.
     *
     * @var array<string, class-string<BaseField>>
     */
    private static array $types = [];

    /**
     * Registers a field type with its handler class.
     *
     * @param string $type  Field type slug.
     * @param string $class Fully-qualified class name.
     */
    public static function register(string $type, string $class): void
    {
        if (isset(self::$types[$type]) && self::$types[$type] !== $class) {
            // Discovery order isn't guaranteed, so a slug collision would otherwise
            // silently pick a non-deterministic "winner". Fail loudly instead.
            \FabricatorForms\fabricator_log(
                'FabricatorForms FieldRegistry: duplicate field type "' . $type . '" registered by '
                . $class . ' (already registered by ' . self::$types[$type] . ') — keeping the first registration.'
            );
            return;
        }
        self::$types[$type] = $class;
    }

    /**
     * Returns a new instance of the handler for the given field type.
     *
     * @param string $type Field type slug.
     * @return BaseField|null Handler instance, or null if the type is unknown.
     */
    public static function get(string $type): ?BaseField
    {
        $class = self::$types[$type] ?? null;
        if (!$class || !class_exists($class)) {
            return null;
        }
        return new $class();
    }

    /**
     * Returns all registered field types as a slug-to-class map.
     *
     * @return array<string, class-string<BaseField>>
     */
    public static function all(): array
    {
        return self::$types;
    }

    /**
     * Returns true if the given field type slug is registered.
     *
     * @param string $type Field type slug.
     */
    public static function hasType(string $type): bool
    {
        return isset(self::$types[$type]);
    }

    /**
     * Registers all built-in field types.
     *
     * @return void
     */
    public static function registerDefaults(): void
    {
        // Auto-discover concrete BaseField subclasses already loaded by Plugin.php (per FIELD_MAP).
        foreach (get_declared_classes() as $class) {
            if (!is_subclass_of($class, BaseField::class)) {
                continue;
            }
            // Restrict to this namespace to avoid picking up third-party BaseField subclasses.
            if (!str_starts_with($class, __NAMESPACE__ . '\\')) {
                continue;
            }
            $handler   = new $class();
            $realSlug  = $handler->getType();
            $shortName = substr(strrchr($class, '\\') ?: $class, 1);

            // Typo guard: getType() is authoritative, FIELD_MAP's slug is just documentation.
            $mappedSlug = substr((string) strrchr(self::FIELD_MAP[$shortName] ?? '', ':'), 1);
            if ($mappedSlug !== '' && $mappedSlug !== $realSlug) {
                \FabricatorForms\fabricator_log(
                    'FabricatorForms FieldRegistry: ' . $shortName . '::getType() is "' . $realSlug
                    . '" but FIELD_MAP declares "' . $mappedSlug . '" — update FIELD_MAP to match.'
                );
            }

            self::register($realSlug, $class);
        }
    }

    /**
     * Returns palette data as an array of groups for the builder UI.
     *
     * @return array
     */
    public static function paletteGroups(): array
    {
        // Keyed by locale: avoids serving one request's translated strings to another under persistent-worker SAPIs.
        static $cache = [];
        $locale = determine_locale();
        if (isset($cache[$locale])) {
            return $cache[$locale];
        }

        // Grouping/order is derived from FIELD_MAP, not a separate list.
        $groupOrder = [];
        foreach (self::FIELD_MAP as $groupSlug) {
            [$group, $type] = explode(':', $groupSlug, 2);
            $groupOrder[$group][] = $type;
        }

        $groups = [];
        foreach ($groupOrder as $group => $types) {
            $meta = self::GROUP_META[$group] ?? ['label' => $group, 'color' => '#999999'];
            $items = [];
            foreach ($types as $type) {
                $class = self::$types[$type] ?? null;
                if (!$class) {
                    continue;
                }
                $obj     = new $class();
                $items[] = [
                    'type'           => $type,
                    'label'          => $obj->getLabel(),
                    'icon'           => $obj->getIcon(),
                    'defaults'       => $obj->getDefaultConfig(),
                    'generalSchema'  => $obj->getGeneralSchema(),
                    'advancedSchema' => $obj->getAdvancedSchema(),
                    'noPanel'        => !$obj->hasSettingsPanel(),
                    'noRequired'     => !$obj->hasRequired(),
                    // A page break separates pages, so it can neither sit inside a group nor be shown conditionally:
                    // FormRenderer calls renderBreak() for it and never emits a data-conditions attribute.
                    'noNesting'      => $obj->isPageBreak(),
                    'noConditions'   => $obj->isPageBreak(),
                ];
            }
            if ($items) {
                $groups[] = ['label' => self::translateGroupLabel($group), 'color' => $meta['color'], 'items' => $items];
            }
        }
        $cache[$locale] = $groups;
        return $cache[$locale];
    }

    /**
     * Translates a GROUP_META key. A literal switch (not a variable passed to __()) so i18n string extraction
     * can still find these.
     *
     * @param string $group Group key from GROUP_META.
     */
    private static function translateGroupLabel(string $group): string
    {
        return match ($group) {
            'input'    => __('Input', 'formfabricator'),
            'choice'   => __('Choice', 'formfabricator'),
            'personal' => __('Personal', 'formfabricator'),
            'advanced' => __('Advanced', 'formfabricator'),
            'layout'   => __('Layout', 'formfabricator'),
            'system'   => __('System', 'formfabricator'),
            default    => self::GROUP_META[$group]['label'] ?? $group,
        };
    }

    /**
     * Maps raw form submission values to a normalized array for PDF/email.
     *
     * @param array    $fields     Form field configuration array.
     * @param array    $raw_values Raw submitted POST values.
     * @param array    $files      Uploaded files ($_FILES).
     * @param string[] $skip_ids   Ids of fields hidden by their conditions (a hidden group's children included), from
     *                             FormProcessor::collectHiddenIds(). Passed in rather than removed from the result
     *                             afterwards, because mapping is what reads a field's value: a hidden upload skipped
     *                             here is never read from $_FILES or base64-encoded, and a hidden signature is never
     *                             decoded. They were never validated either, so nothing of theirs may reach the output.
     * @return array Normalized mapped values.
     */
    public static function mapSubmission(
        array $fields,
        array $raw_values,
        array $files = [],
        array $skip_ids = []
    ): array {
        $mapped  = [];
        // Skip hidden fields here, not after: unset afterward would let an upload be read/encoded before validate() ever runs.
        $skip    = array_flip($skip_ids);
        $context = ['files' => $files, 'raw_values' => $raw_values, 'skip_ids' => $skip];

        foreach ($fields as $field_cfg) {
            $field_id   = $field_cfg['id']   ?? '';
            $field_type = $field_cfg['type'] ?? '';
            $label      = $field_cfg['label'] ?? $field_id;

            if (!$field_id || !$field_type) {
                continue;
            }
            if (isset($skip[$field_id])) {
                continue;
            }

            $handler = self::get($field_type);
            if (!$handler) {
                continue;
            }

            $value   = $raw_values[$field_id] ?? null;
            $entries = $handler->mapNormalized(
                $field_id,
                $label,
                $value,
                $field_cfg,
                $context
            );
            foreach ($entries as $key => $entry) {
                $mapped[$key] = $entry;
            }
        }

        return $mapped;
    }
}
