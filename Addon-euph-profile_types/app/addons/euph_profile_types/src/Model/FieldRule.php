<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphProfileTypes\Model;

use Tygh\Addons\EuphProfileTypes\Cast;
use Tygh\Addons\EuphProfileTypes\Enum\FieldArea;

/**
 * The checkboxes of one profile field for one profile type.
 */
final readonly class FieldRule
{
    /** Columns of ?:euph_profile_type_fields holding the checkboxes, in display order. */
    public const FLAGS = [
        'profile_show',
        'profile_required',
        'registration_show',
        'checkout_show',
        'checkout_required',
        'storefront_show',
    ];

    public function __construct(
        public int $typeId,
        public int $fieldId,
        public bool $profileShow = false,
        public bool $profileRequired = false,
        public bool $registrationShow = false,
        public bool $checkoutShow = false,
        public bool $checkoutRequired = false,
        public bool $storefrontShow = false,
    ) {
        if ($this->typeId <= 0) {
            throw new \InvalidArgumentException('typeId must be positive');
        }
        if ($this->fieldId <= 0) {
            throw new \InvalidArgumentException('fieldId must be positive');
        }
    }

    /**
     * @param array<array-key, mixed> $flags column => Y/N (a missing column is N)
     */
    public static function fromFlags(int $typeId, int $fieldId, array $flags): self
    {
        return new self(
            typeId: $typeId,
            fieldId: $fieldId,
            profileShow: Cast::flag($flags['profile_show'] ?? null),
            profileRequired: Cast::flag($flags['profile_required'] ?? null),
            registrationShow: Cast::flag($flags['registration_show'] ?? null),
            checkoutShow: Cast::flag($flags['checkout_show'] ?? null),
            checkoutRequired: Cast::flag($flags['checkout_required'] ?? null),
            storefrontShow: Cast::flag($flags['storefront_show'] ?? null),
        );
    }

    /**
     * @param array<array-key, mixed> $row a ?:euph_profile_type_fields row
     */
    public static function fromRow(array $row): self
    {
        return self::fromFlags(Cast::int($row['type_id'] ?? 0), Cast::int($row['field_id'] ?? 0), $row);
    }

    /**
     * The checkboxes a field had before any type governed it, taken from its
     * general checkboxes (a ?:profile_fields row). Registration had no checkbox
     * of its own then: the profile's decided.
     *
     * @param array<array-key, mixed> $field
     */
    public static function fromGeneralFlags(int $typeId, int $fieldId, array $field): self
    {
        return new self(
            typeId: $typeId,
            fieldId: $fieldId,
            profileShow: Cast::flag($field['profile_show'] ?? null),
            profileRequired: Cast::flag($field['profile_required'] ?? null),
            registrationShow: Cast::flag($field['profile_show'] ?? null),
            checkoutShow: Cast::flag($field['checkout_show'] ?? null),
            checkoutRequired: Cast::flag($field['checkout_required'] ?? null),
            storefrontShow: Cast::flag($field['storefront_show'] ?? null),
        );
    }

    /** Nothing ticked. */
    public function isEmpty(): bool
    {
        return !$this->profileShow && !$this->profileRequired && !$this->registrationShow
            && !$this->checkoutShow && !$this->checkoutRequired && !$this->storefrontShow;
    }

    public function shows(FieldArea $area): bool
    {
        return match ($area) {
            FieldArea::Profile => $this->profileShow,
            FieldArea::Registration => $this->registrationShow,
            FieldArea::Checkout => $this->checkoutShow,
            FieldArea::Storefront => $this->storefrontShow,
        };
    }

    /** Required where it is shown: a hidden field is never required. */
    public function requires(FieldArea $area): bool
    {
        return $this->shows($area) && match ($area) {
            FieldArea::Profile, FieldArea::Registration => $this->profileRequired,
            FieldArea::Checkout => $this->checkoutRequired,
            FieldArea::Storefront => false,
        };
    }

    /**
     * The checkboxes as a string of Y/N in FLAGS order ("YNYNNN"): what a form
     * renders as its baseline, to tell an untouched row from a changed one.
     */
    public function signature(): string
    {
        $row = $this->toRow();

        return implode('', array_map(static fn (string $flag): string => $row[$flag], self::FLAGS));
    }

    /**
     * @return array{type_id: int, field_id: int, profile_show: string, profile_required: string, registration_show: string, checkout_show: string, checkout_required: string, storefront_show: string}
     */
    public function toRow(): array
    {
        return [
            'type_id' => $this->typeId,
            'field_id' => $this->fieldId,
            'profile_show' => Cast::yn($this->profileShow),
            'profile_required' => Cast::yn($this->profileRequired),
            'registration_show' => Cast::yn($this->registrationShow),
            'checkout_show' => Cast::yn($this->checkoutShow),
            'checkout_required' => Cast::yn($this->checkoutRequired),
            'storefront_show' => Cast::yn($this->storefrontShow),
        ];
    }

    public function withTypeId(int $typeId): self
    {
        return new self(
            $typeId,
            $this->fieldId,
            $this->profileShow,
            $this->profileRequired,
            $this->registrationShow,
            $this->checkoutShow,
            $this->checkoutRequired,
            $this->storefrontShow,
        );
    }

    public function withFieldId(int $fieldId): self
    {
        return new self(
            $this->typeId,
            $fieldId,
            $this->profileShow,
            $this->profileRequired,
            $this->registrationShow,
            $this->checkoutShow,
            $this->checkoutRequired,
            $this->storefrontShow,
        );
    }
}
