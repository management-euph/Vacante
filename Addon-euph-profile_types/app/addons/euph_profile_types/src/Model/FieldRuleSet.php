<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphProfileTypes\Model;

use Tygh\Addons\EuphProfileTypes\Enum\FieldArea;

/**
 * Every rule of the store, indexed by field.
 *
 * A field is "governed" by the types as soon as something is ticked for it
 * for any type; from then on each type sees it only where its own checkboxes
 * say so. A field with nothing ticked for any type works by its own general
 * checkboxes, which is how fields created before the add-on behave.
 */
final class FieldRuleSet
{
    /** @var array<int, array<int, FieldRule>> field id => type id => rule */
    private array $byField = [];

    /**
     * @param iterable<FieldRule> $rules
     */
    public function __construct(iterable $rules = [])
    {
        foreach ($rules as $rule) {
            $this->byField[$rule->fieldId][$rule->typeId] = $rule;
        }
    }

    public function isGoverned(int $fieldId): bool
    {
        foreach ($this->byField[$fieldId] ?? [] as $rule) {
            if (!$rule->isEmpty()) {
                return true;
            }
        }

        return false;
    }

    public function ruleFor(int $fieldId, int $typeId): ?FieldRule
    {
        return $this->byField[$fieldId][$typeId] ?? null;
    }

    /**
     * @return array<int, FieldRule> type id => rule
     */
    public function rulesForField(int $fieldId): array
    {
        return $this->byField[$fieldId] ?? [];
    }

    /**
     * @return list<FieldRule>
     */
    public function rulesOfType(int $typeId): array
    {
        $rules = [];
        foreach ($this->byField as $byType) {
            if (isset($byType[$typeId])) {
                $rules[] = $byType[$typeId];
            }
        }

        return $rules;
    }

    /**
     * Ids of the fields some type governs.
     *
     * @return list<int>
     */
    public function governedFieldIds(): array
    {
        $ids = [];
        foreach (array_keys($this->byField) as $fieldId) {
            if ($this->isGoverned($fieldId)) {
                $ids[] = $fieldId;
            }
        }

        return $ids;
    }

    /**
     * Ids of the fields some type governs that a type shows in none of the
     * areas: the type does not have them.
     *
     * @return list<int>
     */
    public function unseenBy(int $typeId, FieldArea ...$areas): array
    {
        $ids = [];
        foreach ($this->governedFieldIds() as $fieldId) {
            $rule = $this->ruleFor($fieldId, $typeId);
            $seen = false;
            foreach ($areas as $area) {
                $seen = $seen || ($rule !== null && $rule->shows($area));
            }
            if (!$seen) {
                $ids[] = $fieldId;
            }
        }

        return $ids;
    }
}
