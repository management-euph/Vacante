<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Services\ProviderRoomLimit;

/**
 * The guest picker offers up to 12 rooms for every provider (Eurosite since
 * its booking flow went multi-room), and the limit reaches both mounts.
 */
final class ProviderRoomLimitTest extends TestCase
{
    public function testEveryProviderBooksUpToTwelveRooms(): void
    {
        self::assertSame(12, ProviderRoomLimit::maxRooms('eurosite'));
        self::assertSame(12, ProviderRoomLimit::maxRooms('novoton'));
        self::assertSame(12, ProviderRoomLimit::maxRooms('sphinx'));
        self::assertSame(12, ProviderRoomLimit::maxRooms(''));
    }

    public function testTheLimitReachesTheGuestPickerOnBothMounts(): void
    {
        $root = dirname(__DIR__, 3);

        self::assertStringContainsString(
            'data-max-rooms="{$maxRooms}"',
            (string) file_get_contents($root . '/functions/hotels.php'),
        );
        self::assertStringContainsString(
            "'maxRooms' => ProviderRoomLimit::maxRooms(\$providerName)",
            (string) file_get_contents($root . '/controllers/frontend/travel_booking.php'),
        );
        self::assertStringContainsString(
            'parseInt(serverConfig.maxRooms, 10) || 12',
            (string) file_get_contents(dirname($root, 3) . '/react-src/src/index.jsx'),
        );
    }
}
