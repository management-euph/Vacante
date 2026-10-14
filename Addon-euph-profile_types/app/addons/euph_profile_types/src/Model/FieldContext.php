<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphProfileTypes\Model;

use Tygh\Addons\EuphProfileTypes\Cast;
use Tygh\Addons\EuphProfileTypes\Enum\FieldArea;
use Tygh\Addons\EuphProfileTypes\Enum\UserKind;

/**
 * What a profile field list is read for: whose fields (the type), where
 * (the area), and whether the type choice is drawn first in it.
 */
final readonly class FieldContext
{
    /**
     * @param array<string, string>|null $choice code => name of the choice drawn first in the
     *                                           contact information; null: no choice. One option
     *                                           only shows the type (a fixed type, an order).
     */
    public function __construct(
        public UserKind $kind,
        public ProfileType $type,
        public FieldArea $area,
        public ?array $choice = null,
    ) {
    }

    /**
     * The form carried in the fn_get_profile_fields() params from the
     * "get_profile_fields" hook to the "get_profile_fields_post" hook.
     *
     * @return array{kind: string, type_id: int, code: string, area: string, choice: array<string, string>|null, vendor_plan_ids: list<int>}
     */
    public function toParams(): array
    {
        return [
            'kind' => $this->kind->value,
            'type_id' => $this->type->id,
            'code' => $this->type->code,
            'area' => $this->area->value,
            'choice' => $this->choice,
            'vendor_plan_ids' => $this->type->vendorPlanIds,
        ];
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return array<string, string>|null
     */
    public static function choiceFromParams(array $params): ?array
    {
        $choice = $params['choice'] ?? null;
        if (!is_array($choice)) {
            return null;
        }
        $options = [];
        foreach ($choice as $code => $name) {
            $options[(string) $code] = Cast::string($name);
        }

        return $options !== [] ? $options : null;
    }
}
