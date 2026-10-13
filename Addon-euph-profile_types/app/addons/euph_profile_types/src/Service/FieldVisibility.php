<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphProfileTypes\Service;

use Tygh\Addons\EuphProfileTypes\Cast;
use Tygh\Addons\EuphProfileTypes\Enum\FieldArea;
use Tygh\Addons\EuphProfileTypes\Model\FieldRuleSet;

/**
 * Applies a type's checkboxes to a profile field list as fn_get_profile_fields()
 * returns it: section => field id => field.
 *
 * A field some type governs is kept only where the type's own "show"
 * checkbox for the area is ticked, and is required as the type says. A field
 * no type governs keeps its general checkboxes.
 */
final class FieldVisibility
{
    /** Section of the fields CS-Cart adds itself (the e-mail): never touched. */
    private const ESSENTIALS = 'E';

    public function __construct(private readonly FieldRuleSet $rules)
    {
    }

    /**
     * @param array<array-key, mixed> $fields
     * @param bool $filterUngoverned the list was read without CS-Cart's own "show"
     *                               restriction, so the general checkbox of the
     *                               fields no type governs is applied here
     *
     * @return array<array-key, mixed>
     */
    public function apply(array $fields, int $typeId, FieldArea $area, bool $filterUngoverned): array
    {
        foreach ($fields as $section => $sectionFields) {
            if ($section === self::ESSENTIALS || !is_array($sectionFields)) {
                continue;
            }
            $kept = [];
            foreach ($sectionFields as $key => $field) {
                if (!is_array($field)) {
                    continue;
                }
                $fieldId = Cast::int($field['field_id'] ?? 0);
                if ($fieldId <= 0) {
                    // Not a stored field: the type choice the add-on draws itself.
                    $kept[$key] = $field;
                    continue;
                }
                $decision = $this->decide($field, $fieldId, $typeId, $area, $filterUngoverned);
                if ($decision !== null) {
                    $kept[$key] = self::mark($field, $area, $decision);
                }
            }
            if ($kept === []) {
                unset($fields[$section]);
            } else {
                $fields[$section] = $kept;
            }
        }

        return $fields;
    }

    /**
     * @param array<array-key, mixed> $field
     *
     * @return bool|null whether the field is required, null when it is hidden
     */
    private function decide(array $field, int $fieldId, int $typeId, FieldArea $area, bool $filterUngoverned): ?bool
    {
        if ($this->rules->isGoverned($fieldId)) {
            $rule = $this->rules->ruleFor($fieldId, $typeId);
            if ($rule === null || !$rule->shows($area)) {
                return null;
            }

            return $rule->requires($area);
        }

        if ($filterUngoverned && !Cast::flag($field[$area->generalShowColumn()] ?? 'N')) {
            return null;
        }
        $requiredColumn = $area->generalRequiredColumn();

        return $requiredColumn !== null && Cast::flag($field[$requiredColumn] ?? 'N');
    }

    /**
     * Writes the decision where CS-Cart's templates read it: "required", and
     * the area's own show/required columns.
     *
     * @param array<array-key, mixed> $field
     *
     * @return array<array-key, mixed>
     */
    private static function mark(array $field, FieldArea $area, bool $required): array
    {
        $flag = Cast::yn($required);
        $field['required'] = $flag;
        $field[$area->generalShowColumn()] = 'Y';
        $requiredColumn = $area->generalRequiredColumn();
        if ($requiredColumn !== null) {
            $field[$requiredColumn] = $flag;
        }

        return $field;
    }
}
