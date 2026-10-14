<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphProfileTypes\Tests\Support;

/**
 * Rows of ?:euph_profile_types joined with their names, for FakeDatabase.
 */
final class Types
{
    /** The key of the Dealer type's registration links. */
    public const DEALER_KEY = '5f3a9c1e7b2d4086a1c3e5f7092b4d6e';

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function row(int $id, string $code, string $userType = 'C', array $overrides = []): array
    {
        return array_merge([
            'type_id' => $id,
            'code' => $code,
            'user_type' => $userType,
            'status' => 'A',
            'position' => $id * 10,
            'is_default' => 'N',
            'is_standard' => 'N',
            'activation' => 'S',
            'name' => 'Type ' . $code,
            'description' => '',
        ], $overrides);
    }

    /**
     * The standard customer type (U), Company (B, default), Private (P),
     * Dealer (D, hidden, storefront 2, with a link key), Old (O, disabled)
     * and the standard vendor type (S).
     *
     * @return list<array<string, mixed>>
     */
    public static function store(): array
    {
        return [
            self::row(1, 'U', 'C', ['is_standard' => 'Y', 'name' => 'Customer', 'position' => 0]),
            self::row(2, 'B', 'C', ['is_default' => 'Y', 'name' => 'Company']),
            self::row(3, 'P', 'C', ['name' => 'Private person']),
            self::row(4, 'D', 'C', ['status' => 'H', 'name' => 'Dealer', 'link_key' => self::DEALER_KEY]),
            self::row(5, 'O', 'C', ['status' => 'D', 'name' => 'Old']),
            self::row(6, 'S', 'V', ['is_standard' => 'Y', 'name' => 'Vendor', 'position' => 0]),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function links(): array
    {
        return [
            ['type_id' => 2, 'object_type' => 'G', 'object_id' => 7],
            ['type_id' => 4, 'object_type' => 'S', 'object_id' => 2],
            ['type_id' => 4, 'object_type' => 'G', 'object_id' => 8],
        ];
    }

    public static function database(): FakeDatabase
    {
        $db = new FakeDatabase();
        $db->rows['FROM ?:euph_profile_types AS t'] = self::store();
        $db->rows['FROM ?:euph_profile_type_links'] = self::links();

        return $db;
    }
}
