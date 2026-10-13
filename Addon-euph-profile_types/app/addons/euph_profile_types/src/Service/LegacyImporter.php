<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphProfileTypes\Service;

use Tygh\Addons\EuphProfileTypes\Cast;
use Tygh\Addons\EuphProfileTypes\Db\Database;
use Tygh\Addons\EuphProfileTypes\Enum\LinkKind;
use Tygh\Addons\EuphProfileTypes\Enum\TypeStatus;
use Tygh\Addons\EuphProfileTypes\Enum\UserKind;
use Tygh\Addons\EuphProfileTypes\Model\FieldRule;
use Tygh\Addons\EuphProfileTypes\Repository\FieldRuleRepository;
use Tygh\Addons\EuphProfileTypes\Repository\ProfileTypeRepository;

/**
 * Carries types and fields over from the 1.x add-on (cp_profile_types).
 *
 * 1.x kept a full copy of the profile fields for every type, under the type's
 * code in ?:profile_fields.profile_type. Here the fields are one common list
 * with checkboxes per type, so each copy is matched to the common field of the
 * same section and code and becomes that type's checkboxes. A field that
 * existed only in a type's copy joins the common list. The values customers
 * typed into a copy are copied to the common field (never moved), and the 1.x
 * tables and copies are left in place: nothing is deleted.
 *
 * Safe to run again: existing types are matched by code, rules are replaced,
 * values are copied only where the common field has none yet.
 */
final class LegacyImporter
{
    private const OLD_TYPES = '?:cp_profile_types';
    private const OLD_DESCRIPTIONS = '?:cp_profile_type_descriptions';
    /** Profile field types whose values are ids of ?:profile_field_values. */
    private const CHOICE_FIELD_TYPES = ['R', 'S'];

    public function __construct(
        private readonly Database $db,
        private readonly ProfileTypeRepository $types,
        private readonly FieldRuleRepository $rules,
        private readonly bool $multiVendor,
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->db->tableExists(self::OLD_TYPES);
    }

    /**
     * @param list<string> $langCodes
     *
     * @return array{types: int, users: int, orders: int, companies: int, rules: int, adopted_fields: int, values: int}
     */
    public function import(string $langCode, array $langCodes): array
    {
        $stats = ['types' => 0, 'users' => 0, 'orders' => 0, 'companies' => 0, 'rules' => 0, 'adopted_fields' => 0, 'values' => 0];
        if (!$this->isAvailable()) {
            return $stats;
        }

        $customTypes = [];
        foreach ($this->db->rows('SELECT * FROM ?:cp_profile_types ORDER BY user_type ASC, position ASC') as $old) {
            $kind = UserKind::tryFrom(Cast::string($old['user_type'] ?? '')) ?? UserKind::Customer;
            if ($kind === UserKind::Vendor && !$this->multiVendor) {
                continue;
            }
            $code = CodeValidator::normalize(Cast::string($old['code'] ?? ''));
            if (!CodeValidator::isWellFormed($code)) {
                continue;
            }
            $typeId = $this->importType($old, $kind, $code, $langCode, $langCodes, $stats);
            if ($typeId > 0 && $code !== $kind->standardCode()) {
                $customTypes[] = ['type_id' => $typeId, 'code' => $code, 'kind' => $kind];
            }
        }

        $stats['users'] = $this->copyColumn('?:users');
        $stats['orders'] = $this->copyColumn('?:orders');
        if ($this->multiVendor) {
            $stats['companies'] = $this->copyColumn('?:companies');
        }

        foreach ([UserKind::Customer, UserKind::Vendor] as $kind) {
            $ofKind = array_values(array_filter($customTypes, static fn (array $type): bool => $type['kind'] === $kind));
            if ($ofKind !== []) {
                $this->importFields($kind, $ofKind, $langCode, $stats);
            }
        }
        $this->types->forget();
        $this->rules->forget();

        return $stats;
    }

    /**
     * @param array<string, mixed> $old
     * @param list<string> $langCodes
     * @param array{types: int, users: int, orders: int, companies: int, rules: int, adopted_fields: int, values: int} $stats
     */
    private function importType(array $old, UserKind $kind, string $code, string $langCode, array $langCodes, array &$stats): int
    {
        $descriptions = $this->db->tableExists(self::OLD_DESCRIPTIONS)
            ? $this->db->rows('SELECT * FROM ?:cp_profile_type_descriptions WHERE type_id = ?i', Cast::int($old['type_id'] ?? 0))
            : [];
        $names = [];
        foreach ($descriptions as $description) {
            $names[Cast::string($description['lang_code'] ?? '')] = [
                'name' => Cast::string($description['name'] ?? ''),
                'description' => Cast::string($description['description'] ?? ''),
            ];
        }
        $existing = $this->types->findByCode($code, $langCode);
        if ($existing !== null && $existing->kind !== $kind) {
            return 0;
        }
        // A type 1.x left without a name keeps the one it has here.
        $name = $names[$langCode]['name'] ?? (array_values($names)[0]['name'] ?? ($existing !== null ? $existing->displayName() : $code));
        $links = [LinkKind::Usergroup->value => Cast::idList($old['usergroup_ids'] ?? '')];
        if ($kind === UserKind::Vendor) {
            $links[LinkKind::VendorPlan->value] = Cast::idList($old['id_plans'] ?? '');
        }
        $status = Cast::string($old['status'] ?? '') === TypeStatus::Disabled->value ? TypeStatus::Disabled : TypeStatus::Active;
        $fields = [
            'code' => $code,
            'position' => Cast::int($old['position'] ?? 0),
            'status' => $existing !== null && $existing->isStandard ? TypeStatus::Active->value : $status->value,
        ];
        if ($existing === null) {
            $fields['user_type'] = $kind->value;
            $fields['is_standard'] = 'N';
            $fields['activation'] = 'S';
            ++$stats['types'];
        }
        $typeId = $this->types->save(
            $existing !== null ? $existing->id : 0,
            $fields,
            $name !== '' ? $name : $code,
            $names[$langCode]['description'] ?? '',
            $langCode,
            $langCodes,
            $links,
        );
        foreach ($names as $lang => $text) {
            if ($lang !== '' && $text['name'] !== '') {
                $this->types->saveDescription($typeId, $lang, $text['name'], $text['description']);
            }
        }
        if (Cast::int($old['is_default'] ?? 0) > 0) {
            $this->types->setDefault($typeId, $kind);
        }

        return $typeId;
    }

    /** users/orders/companies.cp_profile_type -> euph_profile_type. */
    private function copyColumn(string $table): int
    {
        if (!$this->db->columnExists($table, 'cp_profile_type') || !$this->db->columnExists($table, 'euph_profile_type')) {
            return 0;
        }

        return $this->db->execute(
            'UPDATE ?p SET euph_profile_type = UPPER(cp_profile_type) WHERE cp_profile_type != ?s',
            $table,
            '',
        );
    }

    /**
     * @param list<array{type_id: int, code: string, kind: UserKind}> $customTypes
     * @param array{types: int, users: int, orders: int, companies: int, rules: int, adopted_fields: int, values: int} $stats
     */
    private function importFields(UserKind $kind, array $customTypes, string $langCode, array &$stats): void
    {
        $profileType = $kind->fieldProfileType();
        $common = [];
        foreach ($this->db->rows('SELECT * FROM ?:profile_fields WHERE profile_type = ?s', $profileType) as $field) {
            $common[self::key($field)] = $field;
        }
        $commonBefore = $common;

        foreach ($customTypes as $type) {
            $copies = $this->db->rows(
                'SELECT * FROM ?:profile_fields WHERE profile_type = ?s ORDER BY field_id ASC',
                $type['code'],
            );
            foreach ($copies as $copy) {
                $copyId = Cast::int($copy['field_id'] ?? 0);
                $key = self::key($copy);
                if ($copyId <= 0) {
                    continue;
                }
                if (Cast::string($copy['field_name'] ?? '') !== '' && isset($common[$key])) {
                    $targetId = Cast::int($common[$key]['field_id'] ?? 0);
                    if ($targetId !== $copyId) {
                        $stats['values'] += $this->copyValues($copy, $common[$key]);
                    }
                } else {
                    // Only this type had the field: it joins the common list.
                    $this->db->execute(
                        'UPDATE ?:profile_fields SET profile_type = ?s WHERE field_id = ?i',
                        $profileType,
                        $copyId,
                    );
                    $copy['profile_type'] = $profileType;
                    $common[$key] = $copy;
                    $targetId = $copyId;
                    ++$stats['adopted_fields'];
                }
                if ($targetId > 0) {
                    $this->rules->put(FieldRule::fromGeneralFlags($type['type_id'], $targetId, $copy));
                    ++$stats['rules'];
                }
            }
        }

        // In 1.x the standard type saw the common fields by their general
        // checkboxes, and a type saw only its own copy. The standard type gets
        // that as its checkboxes, which makes every visible common field
        // governed: a type without a copy of it keeps not seeing it.
        $standard = $this->types->standard($kind, $langCode);
        if ($standard === null) {
            return;
        }
        foreach ($commonBefore as $field) {
            $fieldId = Cast::int($field['field_id'] ?? 0);
            if ($fieldId > 0) {
                $this->rules->put(FieldRule::fromGeneralFlags($standard->id, $fieldId, $field));
                ++$stats['rules'];
            }
        }
    }

    /**
     * Copies what customers typed into a type's copy of a field to the common
     * field, where the common field has no value for them yet. Choice fields
     * store value ids: those are translated by position.
     *
     * @param array<string, mixed> $copy
     * @param array<string, mixed> $target
     */
    private function copyValues(array $copy, array $target): int
    {
        if (Cast::flag($copy['is_default'] ?? 'N')) {
            // A default field is a column of the user or the order, not a stored value.
            return 0;
        }
        $copyId = Cast::int($copy['field_id'] ?? 0);
        $targetId = Cast::int($target['field_id'] ?? 0);
        $translate = null;
        if (in_array(Cast::string($copy['field_type'] ?? ''), self::CHOICE_FIELD_TYPES, true)) {
            $from = Cast::idList($this->db->column('SELECT value_id FROM ?:profile_field_values WHERE field_id = ?i ORDER BY position ASC, value_id ASC', $copyId));
            $to = Cast::idList($this->db->column('SELECT value_id FROM ?:profile_field_values WHERE field_id = ?i ORDER BY position ASC, value_id ASC', $targetId));
            if ($from === [] || count($from) !== count($to)) {
                return 0;
            }
            $translate = array_combine($from, $to);
        }

        $copied = 0;
        foreach ($this->db->rows('SELECT * FROM ?:profile_fields_data WHERE field_id = ?i', $copyId) as $row) {
            $value = Cast::string($row['value'] ?? '');
            if ($translate !== null) {
                $value = (string) ($translate[Cast::int($value)] ?? '');
            }
            if ($value === '') {
                continue;
            }
            $copied += $this->db->execute(
                'INSERT IGNORE INTO ?:profile_fields_data (object_id, object_type, field_id, value) VALUES (?i, ?s, ?i, ?s)',
                Cast::int($row['object_id'] ?? 0),
                Cast::string($row['object_type'] ?? ''),
                $targetId,
                $value,
            ) > 0 ? 1 : 0;
        }

        return $copied;
    }

    /**
     * @param array<string, mixed> $field
     */
    private static function key(array $field): string
    {
        $name = Cast::string($field['field_name'] ?? '');

        return Cast::string($field['section'] ?? '') . '|' . ($name !== '' ? $name : '#' . Cast::int($field['field_id'] ?? 0));
    }
}
