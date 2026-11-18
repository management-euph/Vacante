<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Helpers;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Constants;
use Tygh\Addons\NovotonHolidays\Helpers\PriceCheckCountries;

/**
 * The admin price checks print the countries they were asked for: only the
 * add-on's own countries get that far.
 */
final class PriceCheckCountriesTest extends TestCase
{
    public function testTheFormsCountriesAreKeptWhenTheAddOnKnowsThem(): void
    {
        [$first, $second] = Constants::COUNTRIES;

        self::assertSame([$first, $second], PriceCheckCountries::fromRequest(['countries' => [strtolower($first), " {$second} ", $first]]));
    }

    public function testTheOlderSingleCountryStillWorks(): void
    {
        $country = Constants::COUNTRIES[0];

        self::assertSame([$country], PriceCheckCountries::fromRequest(['country' => strtolower($country)]));
    }

    public function testACraftedCountryIsDropped(): void
    {
        self::assertSame([], PriceCheckCountries::fromRequest(['country' => '<IMG SRC=X ONERROR=&#97;LERT(1)>']));
        self::assertSame([], PriceCheckCountries::fromRequest(['countries' => ['<SCRIPT>', 'ZZ']]));
        self::assertSame([], PriceCheckCountries::fromRequest([]));
    }
}
