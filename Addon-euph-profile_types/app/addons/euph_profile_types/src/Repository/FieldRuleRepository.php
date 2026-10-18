<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphProfileTypes\Repository;

use Tygh\Addons\EuphProfileTypes\Cast;
use Tygh\Addons\EuphProfileTypes\Db\Database;
use Tygh\Addons\EuphProfileTypes\Model\FieldRule;
use Tygh\Addons\EuphProfileTypes\Model\FieldRuleSet;

/**
 * The per-type checkboxes of the profile fields.
 *
 * A rule with nothing ticked is never stored: "nothing ticked for any type"
 * and "no rows" must mean the same thing, the field's general checkboxes.
 */
final class FieldRuleRepository
{
    public const TABLE = '?:euph_profile_type_fields';

    private ?FieldRuleSet $cache = null;

    public function __construct(private readonly Database $db)
    {
    }

    public function all(): FieldRuleSet
    {
        if ($this->cache === null) {
            $rules = [];
            foreach ($this->db->rows('SELECT * FROM ?:euph_profile_type_fields') as $row) {
                try {
                    $rules[] = FieldRule::fromRow($row);
                } catch (\InvalidArgumentException) {
                    // A row with no type or field id is not a rule.
                }
            }
            $this->cache = new FieldRuleSet($rules);
        }

        return $this->cache;
    }

    /**
     * The field card and the list of profile fields: replaces every rule of
     * the fields (one field, or the two a new billing/shipping field is) by
     * the posted ones.
     *
     * @param list<int> $fieldIds
     * @param array<int, FieldRule> $rules type id => rule; its field id is ignored
     */
    public function replaceForFields(array $fieldIds, array $rules): void
    {
        foreach ($fieldIds as $fieldId) {
            $this->db->execute('DELETE FROM ?:euph_profile_type_fields WHERE field_id = ?i', $fieldId);
            foreach ($rules as $rule) {
                if (!$rule->isEmpty()) {
                    $this->store($rule->withFieldId($fieldId));
                }
            }
        }
        $this->forget();
    }

    /** A new type starts with the form of another one (the standard type). */
    public function copyType(int $fromTypeId, int $toTypeId): void
    {
        foreach ($this->all()->rulesOfType($fromTypeId) as $rule) {
            $this->store($rule->withTypeId($toTypeId));
        }
        $this->forget();
    }

    public function deleteForType(int $typeId): void
    {
        $this->db->execute('DELETE FROM ?:euph_profile_type_fields WHERE type_id = ?i', $typeId);
        $this->forget();
    }

    /**
     * Drops the rules of profile fields that no longer exist.
     */
    public function deleteOrphans(): int
    {
        $orphans = array_map(
            static fn (mixed $id): int => Cast::int($id),
            $this->db->column(
                'SELECT DISTINCT r.field_id FROM ?:euph_profile_type_fields AS r'
                . ' LEFT JOIN ?:profile_fields AS f ON f.field_id = r.field_id WHERE f.field_id IS NULL',
            ),
        );
        if ($orphans === []) {
            return 0;
        }
        $this->db->execute('DELETE FROM ?:euph_profile_type_fields WHERE field_id IN (?n)', $orphans);
        $this->forget();

        return count($orphans);
    }

    /** Stores one rule as it is (an import). An empty rule removes the row. */
    public function put(FieldRule $rule): void
    {
        if ($rule->isEmpty()) {
            $this->db->execute(
                'DELETE FROM ?:euph_profile_type_fields WHERE type_id = ?i AND field_id = ?i',
                $rule->typeId,
                $rule->fieldId,
            );
        } else {
            $this->store($rule);
        }
        $this->forget();
    }

    public function forget(): void
    {
        $this->cache = null;
    }

    private function store(FieldRule $rule): void
    {
        $this->db->execute('REPLACE INTO ?:euph_profile_type_fields ?e', $rule->toRow());
    }
}
