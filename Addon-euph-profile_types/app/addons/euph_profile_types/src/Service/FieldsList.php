<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphProfileTypes\Service;

use Tygh\Addons\EuphProfileTypes\Cast;
use Tygh\Addons\EuphProfileTypes\Enum\FieldArea;
use Tygh\Addons\EuphProfileTypes\Enum\UserKind;
use Tygh\Addons\EuphProfileTypes\Model\FieldRule;
use Tygh\Addons\EuphProfileTypes\Model\FieldRuleSet;
use Tygh\Addons\EuphProfileTypes\Model\ProfileType;

/**
 * CS-Cart's own list of profile fields with the per-type checkboxes: the
 * administrator looks at all types side by side or at one type at a time.
 *
 * Each field of the list is drawn one of four ways:
 *  - types:   some type governs it, a row of checkboxes per type;
 *  - general: no type governs it, so it works by CS-Cart's general
 *             checkboxes: one line for all types, until "Set per type"
 *             opens the rows (filled from those checkboxes);
 *  - locked:  CS-Cart keeps it shown and required (the e-mail, a vendor's
 *             company name), there is nothing to set per type;
 *  - twin:    a shipping field that works like its billing twin, whose rows
 *             decide for both.
 */
final class FieldsList
{
    public const MODE_TYPES = 'types';
    public const MODE_GENERAL = 'general';
    public const MODE_LOCKED = 'locked';
    public const MODE_TWIN = 'twin';

    /** Colours told apart in the list; a type takes one by its position. */
    private const TONES = 8;

    /**
     * The checkboxes the list draws, by the area cell of CS-Cart's list they
     * are drawn in. The flags not drawn keep their value on a save.
     *
     * @return array<string, list<string>> area => flags
     */
    public static function areas(UserKind $kind): array
    {
        return $kind === UserKind::Vendor
            ? ['profile' => ['profile_show', 'profile_required', 'registration_show', 'storefront_show']]
            : ['profile' => ['profile_show', 'profile_required', 'registration_show'], 'checkout' => ['checkout_show', 'checkout_required']];
    }

    /**
     * CS-Cart's schema of profile types (schemas/profiles/profile_types.php)
     * with the Checkout area for the customers' fields. CS-Cart 4.18 and
     * later leave it out, as the checkout takes its fields from the blocks
     * of the checkout layout; a customer type still says which fields its
     * checkout shows and requires (the "By profile type" filling of those
     * blocks). With the area back, the list of profile fields and the field
     * card draw the Checkout column with CS-Cart's own checkboxes, and the
     * list draws the rows of the types in it, as the 1.x add-on did.
     *
     * @param array<array-key, mixed> $schema
     *
     * @return array<array-key, mixed>
     */
    public static function withCheckoutArea(array $schema): array
    {
        $code = UserKind::Customer->fieldProfileType();
        $type = $schema[$code] ?? null;
        $areas = is_array($type) ? ($type['allowed_areas'] ?? null) : null;
        if (!is_array($type) || !is_array($areas) || in_array(FieldArea::Checkout->value, $areas, true)) {
            return $schema;
        }
        $areas[] = FieldArea::Checkout->value;
        $type['allowed_areas'] = $areas;
        $schema[$code] = $type;

        return $schema;
    }

    /**
     * A field CS-Cart keeps shown and required whatever the checkboxes say.
     *
     * @param array<array-key, mixed> $field a ?:profile_fields row
     */
    public static function isLocked(array $field, UserKind $kind): bool
    {
        $name = Cast::string($field['field_name'] ?? '');

        return ($name === 'email' && Cast::flag($field['is_default'] ?? null))
            || ($kind === UserKind::Vendor && $name === 'company');
    }

    /**
     * What the list draws for its fields.
     *
     * @param list<ProfileType> $types the types of the kind, in their order
     * @param list<array<array-key, mixed>> $fields the ?:profile_fields rows of the list
     *
     * @return array{
     *     types: list<array{id: int, code: string, name: string, status: string, tone: int}>,
     *     fields: array<int, array{mode: string, governed: bool, twin_of: int, rows: array<int, array{flags: array<string, string>, baseline: string}>}>
     * }
     */
    public static function build(UserKind $kind, array $types, FieldRuleSet $rules, array $fields): array
    {
        $billing = [];
        foreach ($fields as $field) {
            if (Cast::string($field['section'] ?? '') === 'B') {
                $billing[Cast::int($field['field_id'] ?? 0)] = true;
            }
        }

        $typeRows = [];
        foreach ($types as $position => $type) {
            $typeRows[] = [
                'id' => $type->id,
                'code' => $type->code,
                'name' => $type->displayName(),
                'status' => $type->status->value,
                'tone' => $position % self::TONES + 1,
            ];
        }

        $drawn = [];
        foreach ($fields as $field) {
            $fieldId = Cast::int($field['field_id'] ?? 0);
            if ($fieldId <= 0) {
                continue;
            }
            $matchingId = Cast::int($field['matching_id'] ?? 0);
            $twinOf = Cast::string($field['section'] ?? '') === 'S' && isset($billing[$matchingId]) ? $matchingId : 0;
            $governed = $rules->isGoverned($fieldId);
            $mode = match (true) {
                self::isLocked($field, $kind) => self::MODE_LOCKED,
                $twinOf > 0 => self::MODE_TWIN,
                $governed => self::MODE_TYPES,
                default => self::MODE_GENERAL,
            };

            $rows = [];
            foreach ($types as $type) {
                $rule = self::current($rules, $governed, $type->id, $fieldId, $field);
                $row = $rule->toRow();
                $flags = [];
                foreach (FieldRule::FLAGS as $flag) {
                    $flags[$flag] = $row[$flag];
                }
                $rows[$type->id] = ['flags' => $flags, 'baseline' => $rule->signature()];
            }
            $drawn[$fieldId] = ['mode' => $mode, 'governed' => $governed, 'twin_of' => $twinOf, 'rows' => $rows];
        }

        return ['types' => $typeRows, 'fields' => $drawn];
    }

    /**
     * What a save of the list changes: field id => type id => rule, for the
     * fields whose rows differ from what was drawn.
     *
     * A field some type governs changes when a row differs from the row it
     * was drawn with. A field no type governs changes only when "Set per
     * type" was used on it (split) and a row then differs from its general
     * checkboxes as just saved; the rows nobody touched keep exactly what
     * those checkboxes gave, so the other types keep what they saw.
     *
     * @param list<ProfileType> $types the types of the kind
     * @param array<int, array<array-key, mixed>> $fields ?:profile_fields rows by id, as saved
     * @param array<array-key, mixed> $posted field id => [split => Y/N, type id => checkboxes + baseline]
     *
     * @return array<int, array<int, FieldRule>>
     */
    public static function changes(UserKind $kind, array $types, FieldRuleSet $rules, array $fields, array $posted): array
    {
        $changes = [];
        foreach ($posted as $fieldId => $entry) {
            $fieldId = Cast::int($fieldId);
            $field = $fields[$fieldId] ?? null;
            if ($field === null
                || UserKind::fromFieldProfileType(Cast::string($field['profile_type'] ?? '')) !== $kind
                || self::isLocked($field, $kind)
            ) {
                continue;
            }
            $entry = Cast::array($entry);
            $governed = $rules->isGoverned($fieldId);
            if (!$governed && !Cast::flag($entry['split'] ?? null)) {
                continue;
            }

            $changed = false;
            $next = [];
            foreach ($types as $type) {
                $current = self::current($rules, $governed, $type->id, $fieldId, $field);
                $row = $entry[$type->id] ?? null;
                if (!is_array($row)) {
                    // A type the page did not draw (created meanwhile) keeps its rule.
                    $next[$type->id] = $current;
                    continue;
                }
                $baseline = $governed ? Cast::string($row['baseline'] ?? '') : '';
                $baseline = $baseline !== '' ? $baseline : $current->signature();
                $rule = FieldRule::fromFlags($type->id, $fieldId, $row + FieldRule::flagsFromSignature($baseline));
                $changed = $changed || $rule->signature() !== $baseline;
                $next[$type->id] = $rule;
            }
            if ($changed) {
                $changes[$fieldId] = $next;
            }
        }

        return $changes;
    }

    /**
     * What a type sees of a field now: its own rule when a type governs the
     * field, else the general checkboxes.
     *
     * @param array<array-key, mixed> $field
     */
    private static function current(FieldRuleSet $rules, bool $governed, int $typeId, int $fieldId, array $field): FieldRule
    {
        if (!$governed) {
            return FieldRule::fromGeneralFlags($typeId, $fieldId, $field);
        }

        return $rules->ruleFor($fieldId, $typeId) ?? new FieldRule($typeId, $fieldId);
    }
}
