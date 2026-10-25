<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Contracts\IndicativePriceSourceInterface;
use Tygh\Addons\TravelCore\Contracts\ProviderNormalizerInterface;
use Tygh\Addons\TravelCore\Services\IndicativeNightlyPrice;
use Tygh\Addons\TravelCore\Services\TravelProviderRegistry;

/**
 * The hotel page's indicative "from" price comes from the provider that owns
 * the hotel; a source may only speak for its own provider, and a missing,
 * empty or failing source leaves the page without a price — never an error.
 */
#[CoversClass(TravelProviderRegistry::class)]
#[CoversClass(IndicativeNightlyPrice::class)]
final class TravelProviderRegistryIndicativePriceTest extends TestCase
{
    protected function setUp(): void
    {
        TravelProviderRegistry::reset();
    }

    protected function tearDown(): void
    {
        TravelProviderRegistry::reset();
    }

    private function registerProvider(string $name): void
    {
        TravelProviderRegistry::register($name, ucfirst($name), $this->createStub(ProviderNormalizerInterface::class));
    }

    /** @param \Closure(string): ?float $price */
    private static function source(string $provider, \Closure $price): IndicativePriceSourceInterface
    {
        return new class ($provider, $price) implements IndicativePriceSourceInterface {
            /** @param \Closure(string): ?float $price */
            public function __construct(private readonly string $name, private readonly \Closure $price)
            {
            }

            #[\Override]
            public function provider(): string
            {
                return $this->name;
            }

            #[\Override]
            public function nightlyFromPrice(string $hotelId): ?float
            {
                return ($this->price)($hotelId);
            }

            #[\Override]
            public function nightlyCalendar(string $hotelId): array
            {
                $price = ($this->price)($hotelId);

                return $price === null ? [] : ['2026-11-02' => $price, '2026-11-03' => 0.0];
            }
        };
    }

    public function testTheOwningProvidersSourceAnswersForItsHotel(): void
    {
        $this->registerProvider('novoton');
        $this->registerProvider('eurosite');
        TravelProviderRegistry::setIndicativePriceSource('novoton', self::source('novoton', static fn (string $id): ?float => $id === '42' ? 92.4 : null));
        TravelProviderRegistry::setIndicativePriceSource('eurosite', self::source('eurosite', static fn (string $id): ?float => 61.0));

        self::assertSame(92.4, TravelProviderRegistry::indicativeNightlyPrice('novoton', '42'));
        self::assertNull(TravelProviderRegistry::indicativeNightlyPrice('novoton', '43'));
        self::assertSame(61.0, TravelProviderRegistry::indicativeNightlyPrice('eurosite', 'X1'));
    }

    public function testNoPriceWithoutARegisteredProviderOrSource(): void
    {
        TravelProviderRegistry::setIndicativePriceSource('sphinx', self::source('sphinx', static fn (string $id): ?float => 50.0));
        self::assertNull(TravelProviderRegistry::indicativeNightlyPrice('sphinx', '7'), 'provider not registered');

        $this->registerProvider('sphinx');
        self::assertNull(TravelProviderRegistry::indicativeNightlyPrice('sphinx', '7'), 'the early registration was ignored');
        self::assertNull(TravelProviderRegistry::indicativeNightlyPrice('novoton', '7'), 'unknown provider');
    }

    public function testASourceSpeaksOnlyForItsOwnProvider(): void
    {
        $this->registerProvider('novoton');
        TravelProviderRegistry::setIndicativePriceSource('novoton', self::source('sphinx', static fn (string $id): ?float => 50.0));

        self::assertNull(TravelProviderRegistry::indicativeNightlyPrice('novoton', '7'));
    }

    public function testFailingEmptyOrNonPositiveAnswersGiveNoPrice(): void
    {
        $this->registerProvider('novoton');
        TravelProviderRegistry::setIndicativePriceSource('novoton', self::source('novoton', static function (string $id): ?float {
            return match ($id) {
                'boom' => throw new \RuntimeException('db down'),
                'zero' => 0.0,
                default => 80.0,
            };
        }));

        self::assertNull(TravelProviderRegistry::indicativeNightlyPrice('novoton', 'boom'));
        self::assertNull(TravelProviderRegistry::indicativeNightlyPrice('novoton', 'zero'));
        self::assertNull(TravelProviderRegistry::indicativeNightlyPrice('novoton', ''), 'no hotel id, no lookup');
        self::assertSame(80.0, TravelProviderRegistry::indicativeNightlyPrice('novoton', 'ok'));
    }

    public function testTheCalendarComesFromTheOwningSourceWithoutEmptyDays(): void
    {
        $this->registerProvider('sphinx');
        TravelProviderRegistry::setIndicativePriceSource('sphinx', self::source('sphinx', static function (string $id): ?float {
            return $id === 'boom' ? throw new \RuntimeException('db down') : 88.0;
        }));

        self::assertSame(['2026-11-02' => 88.0], TravelProviderRegistry::indicativeNightlyCalendar('sphinx', '7'));
        self::assertSame([], TravelProviderRegistry::indicativeNightlyCalendar('sphinx', 'boom'));
        self::assertSame([], TravelProviderRegistry::indicativeNightlyCalendar('sphinx', ''));
        self::assertSame([], TravelProviderRegistry::indicativeNightlyCalendar('novoton', '7'));
    }

    public function testLowestTakesFutureDatesAndDividesAStayIntoNights(): void
    {
        $prices = [
            '2026-10-24' => 50,       // past
            '2026-10-25' => '700.0',  // today counts
            '2026-10-26' => 0,        // no price
            '2026-10-27' => 'n/a',
            '2026-11-02' => 630.0,
        ];

        self::assertSame(630.0, IndicativeNightlyPrice::lowest($prices, '2026-10-25'));
        self::assertSame(90.0, IndicativeNightlyPrice::lowest($prices, '2026-10-25', 7));
        self::assertNull(IndicativeNightlyPrice::lowest(['2026-10-01' => 40], '2026-10-25'));
        self::assertNull(IndicativeNightlyPrice::lowest([], '2026-10-25'));
        self::assertSame(630.0, IndicativeNightlyPrice::lowest($prices, '2026-10-25', 0), 'nights never below one');
        self::assertSame(
            ['2026-10-25' => 100.0, '2026-11-02' => 90.0],
            IndicativeNightlyPrice::perNight($prices, '2026-10-25', 7),
        );
    }
}
