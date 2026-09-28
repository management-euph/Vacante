<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Services;

/**
 * How many rooms the guest picker offers for a provider's hotels.
 *
 * Novoton and Sphinx book up to 12 rooms at once. Eurosite's search,
 * booking form and booking are built for ONE room (a multi-room search used
 * to be sent as one room holding every adult), so its picker stops at 1
 * until multi-room is built there.
 */
final class ProviderRoomLimit
{
    public const int DEFAULT = 12;

    /** @var array<string, int> provider name => max rooms */
    private const array LIMITS = [
        'eurosite' => 1,
    ];

    public static function maxRooms(string $provider): int
    {
        return self::LIMITS[$provider] ?? self::DEFAULT;
    }
}
