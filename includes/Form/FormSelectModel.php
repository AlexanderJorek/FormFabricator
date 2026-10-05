<?php

/**
 * Model for managing reusable select-field option lists.
 *
 * PHP Version 8.1
 *
 * @category  FormFabricator
 * @package   FormFabricator
 * @author    Alexander Jorek
 * @copyright 2026 Alexander Jorek
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 * @version   1.0.8
 * @link      https://github.com/AlexanderJorek/FormFabricator
 */

namespace FabricatorForms\Form;

defined('ABSPATH') || exit;

/**
 * Model for reusable form-select option lists stored as a WordPress option.
 */
class FormSelectModel
{
    private static string $option = 'fabricator_form_selects';

    public int    $id    = 0;
    public string $title = '';
    /**
     * Ordered list of form-select items.
     *
     * @var array<int,array{form_id:int,label:string,description:string,favorite:bool}>
     */
    public array $items = [];

    /**
     * Returns all form-select records as model objects.
     *
     * @return self[] Array of FormSelectModel instances.
     */
    public static function getAll(): array
    {
        // Defense-in-depth: all current call sites are already gated on
        // Plugin::userCan('edit_forms') before reaching here, same as save()/delete().
        if (!\FabricatorForms\Plugin::userCan('edit_forms')) {
            return [];
        }
        return array_map([self::class, 'fromArray'], self::getRaw());
    }

    /**
     * Returns a single form-select model by ID.
     *
     * @param int $id The record ID.
     * @return self|null The model instance, or null if not found.
     */
    public static function get(int $id): ?self
    {
        // Intentionally NOT capability-gated: the public shortcode calls this for logged-out visitors.
        foreach (self::getRaw() as $record) {
            if ((int) ($record['id'] ?? 0) === $id) {
                return self::fromArray($record);
            }
        }
        return null;
    }

    /**
     * Creates or updates a form-select record, returns its ID.
     *
     * @param array $data           Form-select data array.
     * @param int   $id             Existing record ID, or 0 to create new.
     * @param bool  $nonce_verified Whether the caller already verified its own
     *                              (action-specific) nonce before calling this
     *                              method. When false (the default), a generic
     *                              internal nonce check is performed as a
     *                              backstop — see {@see self::nonceVerifiedOrCheck()}.
     * @return int The saved record ID.
     */
    public static function save(array $data, int $id = 0, bool $nonce_verified = false): int
    {
        // Defense-in-depth: don't rely solely on callers remembering to gate on edit_forms.
        if (!\FabricatorForms\Plugin::userCan('edit_forms')) {
            return 0;
        }
        if (!self::nonceVerifiedOrCheck($nonce_verified)) {
            return 0;
        }
        $title = sanitize_text_field(\FabricatorForms\Utils\Cast::stringOrDefault($data['title'] ?? null));
        if ($title === '') {
            $title = __('Form Selection', 'formfabricator');
        }

        $items = [];
        foreach ((array) ($data['items'] ?? []) as $item) {
            $form_id = (int) ($item['form_id'] ?? 0);
            if ($form_id <= 0) {
                continue;
            }
            $items[] = [
                'form_id'     => $form_id,
                'label'       => sanitize_text_field(\FabricatorForms\Utils\Cast::stringOrDefault($item['label'] ?? null)),
                'description' => sanitize_text_field(\FabricatorForms\Utils\Cast::stringOrDefault($item['description'] ?? null)),
                'favorite'    => !empty($item['favorite']),
            ];
        }

        // Read and write under one lock, as FormModel and FormEditor do for their shared options: two saves landing
        // together each rewrote the whole list from what they had read, so the later one dropped the earlier change.
        return \FabricatorForms\Utils\OptionMutex::run(
            self::$option,
            static function () use ($id, $title, $items): int {
                $all = self::getRaw();
                if ($id > 0) {
                    foreach ($all as &$record) {
                        if ((int) ($record['id'] ?? 0) === $id) {
                            $record['title'] = $title;
                            $record['items'] = $items;
                            update_option(self::$option, $all, false);
                            return $id;
                        }
                    }
                    unset($record);
                }

                $new_id = self::nextId($all);
                $all[]  = ['id' => $new_id, 'title' => $title, 'items' => $items];
                update_option(self::$option, $all, false);
                return $new_id;
            }
        );
    }

    /**
     * Removes all items referencing a given form ID from every select list. Hooked to before_delete_post so deleted forms disappear from selects.
     *
     * @param int $post_id The WordPress post ID being deleted.
     */
    public static function removeFormId(int $post_id): void
    {
        if (get_post_type($post_id) !== 'fabricator_form') {
            return;
        }
        // Under the same lock as save(): this runs from before_delete_post, which can land while a save is running.
        \FabricatorForms\Utils\OptionMutex::run(
            self::$option,
            static function () use ($post_id): void {
                $all     = self::getRaw();
                $changed = false;
                foreach ($all as &$record) {
                    $before = count($record['items'] ?? []);
                    $record['items'] = array_values(
                        array_filter(
                            $record['items'] ?? [],
                            static fn($item) => (int) ($item['form_id'] ?? 0) !== $post_id
                        )
                    );
                    if (count($record['items']) !== $before) {
                        $changed = true;
                    }
                }
                unset($record);
                if ($changed) {
                    update_option(self::$option, $all, false);
                }
            }
        );
    }

    /**
     * Deletes a form-select record by ID.
     *
     * @param int  $id             The record ID to delete.
     * @param bool $nonce_verified Whether the caller already verified its own
     *                             (action-specific) nonce before calling this
     *                             method. Forwarded the same way as save() —
     *                             see its docblock.
     * @return bool Whether the selection existed and is now deleted.
     */
    public static function delete(int $id, bool $nonce_verified = false): bool
    {
        if (!\FabricatorForms\Plugin::userCan('edit_forms')) {
            return false;
        }
        if (!self::nonceVerifiedOrCheck($nonce_verified)) {
            return false;
        }
        // Whether a selection with this ID was there and is now gone, for the caller to report.
        return (bool) \FabricatorForms\Utils\OptionMutex::run(
            self::$option,
            static function () use ($id): bool {
                $before = self::getRaw();
                $all    = array_values(array_filter($before, static fn($r) => (int) ($r['id'] ?? 0) !== $id));
                if (count($all) === count($before)) {
                    return false;
                }
                update_option(self::$option, $all, false);
                return true;
            }
        );
    }

    /**
     * Returns the raw stored array from the WordPress option.
     *
     * @return array Raw option data.
     */
    private static function getRaw(): array
    {
        $raw = get_option(self::$option, []);
        return is_array($raw) ? $raw : [];
    }

    /**
     * Constructs a FormSelectModel instance from a raw data array.
     *
     * @param array $data Raw data array.
     * @return self New FormSelectModel instance.
     */
    private static function fromArray(array $data): self
    {
        $obj              = new self();
        $obj->id          = (int) ($data['id'] ?? 0);
        $obj->title       = (string) ($data['title'] ?? '');
        $obj->items       = array_map(
            static fn($item) => [
                'form_id'     => (int) ($item['form_id'] ?? 0),
                'label'       => (string) ($item['label'] ?? ''),
                'description' => (string) ($item['description'] ?? ''),
                'favorite'    => (bool) ($item['favorite'] ?? false),
            ],
            (array) ($data['items'] ?? [])
        );
        return $obj;
    }

    /**
     * CSRF backstop for save()/delete(), as FormModel::nonceVerifiedOrCheck().
     *
     * @param bool $nonce_verified Whether the caller already verified its own nonce.
     * @return bool True if the request may proceed.
     */
    private static function nonceVerifiedOrCheck(bool $nonce_verified): bool
    {
        if ($nonce_verified) {
            return true;
        }
        return (bool) check_ajax_referer('fabricator_forms_admin_nonce', 'nonce', false);
    }

    /**
     * The highest selection ID ever handed out, so none is handed out twice.
     *
     * @var string
     */
    private const LAST_ID_OPTION = 'fabricator_form_selects_last_id';

    /**
     * Returns the next ID for a new record, above every ID handed out before, so a shortcode naming a deleted
     * selection never shows a new one. Called inside save()'s lock.
     *
     * @param array $all Existing records array.
     * @return int Next available ID.
     */
    private static function nextId(array $all): int
    {
        $max = (int) get_option(self::LAST_ID_OPTION, 0);
        foreach ($all as $r) {
            $id = (int) ($r['id'] ?? 0);
            if ($id > $max) {
                $max = $id;
            }
        }
        update_option(self::LAST_ID_OPTION, $max + 1, false);
        return $max + 1;
    }
}
