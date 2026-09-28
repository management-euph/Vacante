<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Services\ProviderRoomLimit;

/**
 * Eurosite books one room (its search, booking form and booking are
 * single-room), so its guest picker stops at 1; the others offer 12.
 */
final class ProviderRoomLimitTest extends TestCase
{
    public function testEurositeBooksOneRoomTheOthersTwelve(): void
    {
        self::assertSame(1, ProviderRoomLimit::maxRooms('eurosite'));
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
