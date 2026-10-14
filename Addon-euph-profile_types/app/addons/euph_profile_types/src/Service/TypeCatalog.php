<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphProfileTypes\Service;

use Tygh\Addons\EuphProfileTypes\Enum\UserKind;
use Tygh\Addons\EuphProfileTypes\Model\ProfileType;
use Tygh\Addons\EuphProfileTypes\Repository\ProfileTypeRepository;
use Tygh\Addons\EuphProfileTypes\Settings;

/**
 * Which types a customer or a vendor may have, on which storefront, and
 * which one they get when nobody chose.
 */
final class TypeCatalog
{
    public function __construct(
        private readonly ProfileTypeRepository $types,
        private readonly Settings $settings,
        private readonly bool $multiVendor,
    ) {
    }

    /** "Use profile types for", and vendor types only exist in Multi-Vendor. */
    public function isEnabled(UserKind $kind): bool
    {
        return $this->settings->typesFor->covers($kind) && ($kind === UserKind::Customer || $this->multiVendor);
    }

    /**
     * The types offered in the choice on a storefront. With "Hide standard
     * types" on, the standard type drops out as long as a type of the store's
     * own works there.
     *
     * @return list<ProfileType>
     */
    public function selectable(UserKind $kind, int $storefrontId, string $langCode): array
    {
        $types = array_values(array_filter(
            $this->types->ofKind($kind, $langCode),
            static fn (ProfileType $type): bool => $type->isSelectable() && $type->worksOn($storefrontId),
        ));
        if ($this->settings->hideStandardTypes) {
            $own = array_values(array_filter($types, static fn (ProfileType $type): bool => !$type->isStandard));
            if ($own !== []) {
                return $own;
            }
        }

        return $types;
    }

    /**
     * The type of a guest, and of an account nobody chose a type for: the one
     * marked as default if it is offered here, else the first one offered,
     * else the standard type.
     */
    public function defaultType(UserKind $kind, int $storefrontId, string $langCode): ?ProfileType
    {
        $selectable = $this->selectable($kind, $storefrontId, $langCode);
        foreach ($selectable as $type) {
            if ($type->isDefault) {
                return $type;
            }
        }

        return $selectable[0] ?? $this->types->standard($kind, $langCode);
    }

    /**
     * A type an account may get or switch to on the storefront: one offered in
     * the choice, or a hidden one the visitor has unlocked. A hidden type
     * stays unknown to whoever only has its code, which is no secret.
     *
     * @param list<string> $unlocked codes of the hidden types the visitor may take:
     *                               the ones whose registration link (with its key) was opened,
     *                               and the type the signed-in customer already has
     */
    public function assignableOnStorefront(UserKind $kind, string $code, int $storefrontId, string $langCode, array $unlocked = []): ?ProfileType
    {
        $type = $this->types->findByCode($code, $langCode);
        if ($type === null || $type->kind !== $kind || !$type->isAssignable() || !$type->worksOn($storefrontId)) {
            return null;
        }
        if (!$type->isSelectable() && !in_array($type->code, $unlocked, true)) {
            return null;
        }

        return $type;
    }

    /**
     * The type whose registration link a key opens, if the key is right.
     */
    public function openedByLink(string $code, string $key, string $langCode): ?ProfileType
    {
        $type = $key !== '' ? $this->types->findByCode($code, $langCode) : null;

        return $type !== null && $type->isAssignable() && $type->opensWith($key) ? $type : null;
    }

    /** A type the administrator may give: any type of the kind but a disabled one. */
    public function assignableByAdmin(UserKind $kind, string $code, string $langCode): ?ProfileType
    {
        $type = $this->types->findByCode($code, $langCode);

        return $type !== null && $type->kind === $kind && $type->isAssignable() ? $type : null;
    }

    public function byCode(string $code, string $langCode): ?ProfileType
    {
        return $this->types->findByCode($code, $langCode);
    }

    /**
     * Whether a type works on a storefront: the storefront login rule and the
     * "works on" checkboxes of the type card.
     */
    public function isCodeOnStorefront(string $code, int $storefrontId, string $langCode): bool
    {
        $type = $this->types->findByCode($code, $langCode);

        return $type !== null && $type->worksOn($storefrontId);
    }

    /**
     * @param list<ProfileType> $types
     *
     * @return array<string, string> code => name
     */
    public static function options(array $types): array
    {
        $options = [];
        foreach ($types as $type) {
            $options[$type->code] = $type->displayName();
        }

        return $options;
    }
}
