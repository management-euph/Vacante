<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Services;

/**
 * How many rooms the guest picker offers for a provider's hotels.
 *
 * Every provider books up to 12 rooms at once (Eurosite since its search,
 * booking form and booking went multi-room — RoomOccupancy). A provider that
 * books fewer gets an entry in limits().
 */
final class ProviderRoomLimit
{
    public const int DEFAULT = 12;

    public static function maxRooms(string $provider): int
    {
        return self::limits()[$provider] ?? self::DEFAULT;
    }

    /**
     * Providers that book fewer rooms than DEFAULT — none today.
     *
     * @return array<string, int> provider name => max rooms
     */
    private static function limits(): array
    {
        return [];
    }
}
