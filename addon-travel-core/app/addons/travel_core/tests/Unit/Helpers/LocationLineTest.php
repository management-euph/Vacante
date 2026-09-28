<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Helpers;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Helpers\LocationLine;

final class LocationLineTest extends TestCase
{
    public function testThePlaceIsFollowedByItsCountry(): void
    {
        self::assertSame('Mamaia, Romania', LocationLine::placeAndCountry('Mamaia', 'Romania'));
    }

    public function testACountryAlreadyNamedIsNotRepeated(): void
    {
        self::assertSame('Mamaia, ROMANIA', LocationLine::placeAndCountry('Mamaia, ROMANIA', 'Romania'));
    }

    public function testEitherPartAlone(): void
    {
        self::assertSame('Mamaia', LocationLine::placeAndCountry(' Mamaia ', ''));
        self::assertSame('Romania', LocationLine::placeAndCountry('', 'Romania'));
    }
}
