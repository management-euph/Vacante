<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphProfileTypes\Model;

use Tygh\Addons\EuphProfileTypes\Cast;
use Tygh\Addons\EuphProfileTypes\Enum\Activation;
use Tygh\Addons\EuphProfileTypes\Enum\InvoiceAs;
use Tygh\Addons\EuphProfileTypes\Enum\LinkKind;
use Tygh\Addons\EuphProfileTypes\Enum\TypeStatus;
use Tygh\Addons\EuphProfileTypes\Enum\UserKind;

/**
 * A profile type with its name in one language and its links.
 */
final readonly class ProfileType
{
    /**
     * @param list<int> $usergroupIds user groups the type grants
     * @param list<int> $storefrontIds storefronts the type works on; empty: all of them
     * @param list<int> $vendorPlanIds vendor plans offered to the type; empty: all of them
     * @param string $linkKey the secret of the type's registration links (never sent to the storefront)
     * @param InvoiceAs $invoiceAs how the orders of the type are invoiced (company, private person, as their data says)
     */
    public function __construct(
        public int $id,
        public string $code,
        public UserKind $kind,
        public TypeStatus $status,
        public int $position,
        public bool $isDefault,
        public bool $isStandard,
        public Activation $activation,
        public string $name,
        public string $description,
        public array $usergroupIds = [],
        public array $storefrontIds = [],
        public array $vendorPlanIds = [],
        public string $linkKey = '',
        public InvoiceAs $invoiceAs = InvoiceAs::OrderData,
    ) {
        if ($this->id <= 0) {
            throw new \InvalidArgumentException('id must be positive');
        }
        if ($this->code === '') {
            throw new \InvalidArgumentException('code must not be empty');
        }
    }

    /**
     * @param array<array-key, mixed> $row ?:euph_profile_types joined with its description
     * @param array<string, list<int>> $links object_type => ids
     */
    public static function fromRow(array $row, array $links = []): self
    {
        return new self(
            id: Cast::int($row['type_id'] ?? 0),
            code: Cast::string($row['code'] ?? ''),
            kind: UserKind::tryFrom(Cast::string($row['user_type'] ?? '')) ?? UserKind::Customer,
            status: TypeStatus::fromValue(Cast::string($row['status'] ?? '')),
            position: Cast::int($row['position'] ?? 0),
            isDefault: Cast::flag($row['is_default'] ?? 'N'),
            isStandard: Cast::flag($row['is_standard'] ?? 'N'),
            activation: Activation::fromValue(Cast::string($row['activation'] ?? '')),
            name: Cast::string($row['name'] ?? ''),
            description: Cast::string($row['description'] ?? ''),
            usergroupIds: $links[LinkKind::Usergroup->value] ?? [],
            storefrontIds: $links[LinkKind::Storefront->value] ?? [],
            vendorPlanIds: $links[LinkKind::VendorPlan->value] ?? [],
            linkKey: Cast::string($row['link_key'] ?? ''),
            invoiceAs: InvoiceAs::fromValue(Cast::string($row['invoice_as'] ?? '')),
        );
    }

    /**
     * Whether the type works on a storefront. 0 is "no storefront known"
     * (an admin request, a store with one storefront): every type works there.
     */
    public function worksOn(int $storefrontId): bool
    {
        return $this->storefrontIds === [] || $storefrontId <= 0 || in_array($storefrontId, $this->storefrontIds, true);
    }

    /** Offered in the type choice. */
    public function isSelectable(): bool
    {
        return $this->status === TypeStatus::Active;
    }

    /** Can be given to a user: active, or hidden and given by a link or the administrator. */
    public function isAssignable(): bool
    {
        return $this->status !== TypeStatus::Disabled;
    }

    /**
     * Whether a key is the one of the type's registration links. A type
     * without a key has no link that opens it.
     */
    public function opensWith(string $key): bool
    {
        return $this->linkKey !== '' && hash_equals($this->linkKey, $key);
    }

    public function displayName(): string
    {
        return $this->name !== '' ? $this->name : $this->code;
    }

    /**
     * The shape templates and the add-on's public functions receive.
     *
     * @return array{type_id: int, code: string, user_type: string, status: string, position: int, is_default: string, is_standard: string, activation: string, invoice_as: string, name: string, description: string, usergroup_ids: list<int>, storefront_ids: list<int>, vendor_plan_ids: list<int>}
     */
    public function toArray(): array
    {
        return [
            'type_id' => $this->id,
            'code' => $this->code,
            'user_type' => $this->kind->value,
            'status' => $this->status->value,
            'position' => $this->position,
            'is_default' => Cast::yn($this->isDefault),
            'is_standard' => Cast::yn($this->isStandard),
            'activation' => $this->activation->value,
            'invoice_as' => $this->invoiceAs->value,
            'name' => $this->displayName(),
            'description' => $this->description,
            'usergroup_ids' => $this->usergroupIds,
            'storefront_ids' => $this->storefrontIds,
            'vendor_plan_ids' => $this->vendorPlanIds,
        ];
    }
}
