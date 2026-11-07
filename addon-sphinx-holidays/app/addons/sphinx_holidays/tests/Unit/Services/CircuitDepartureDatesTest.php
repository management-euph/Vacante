<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Services\CircuitDepartureDates;
use Tygh\Addons\SphinxHolidays\SphinxApi;

/**
 * The circuit bar's "Data plecării" (board 16.3): a circuit's departures
 * from one city over the next twelve months, each with its price for 2
 * adults, commission included, from one circuits/rates answer.
 */
#[CoversClass(CircuitDepartureDates::class)]
final class CircuitDepartureDatesTest extends TestCase
{
    /** @var list<array<string, mixed>> the rates params asked, in order */
    private static array $asked = [];

    protected function setUp(): void
    {
        $_SESSION = [];
        self::$asked = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    /**
     * @param list<array<string, mixed>|null> $pages the rates answers, page after page
     */
    private static function dates(array $pages): CircuitDepartureDates
    {
        $api = new class ($pages) extends SphinxApi {
            /** @param list<array<string, mixed>|null> $pages the rates answers, page after page */
            public function __construct(private array $pages)
            {
            }

            public function getCircuitRates(array $params): ?array
            {
                CircuitDepartureDatesTest::record($params);

                return array_shift($this->pages);
            }
        };

        // A 10 % commission, as CartService would add it.
        return new CircuitDepartureDates($api, static fn (float $price): float => $price * 1.1);
    }

    /** @param array<string, mixed> $params */
    public static function record(array $params): void
    {
        self::$asked[] = $params;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, mixed> a decoded rates answer
     */
    private static function page(array $rows, int $lastPage = 1): array
    {
        return ['results' => $rows, 'meta' => ['current_page' => 1, 'last_page' => $lastPage]];
    }

    /** @return array<string, mixed> one rate */
    private static function rate(string $date, float $price, int $circuit = 17): array
    {
        return ['circuit_id' => $circuit, 'departure_date' => $date, 'pricing' => ['selling_price' => $price, 'currency' => 'EUR']];
    }

    public function testTheDatesComeByDateWithTheStoresPrice(): void
    {
        $answer = self::dates([self::page([
            self::rate('2027-07-12', 1140.0),
            self::rate('2027-07-05', 1092.0),
            self::rate('2027-07-05', 1100.0),   // the same date again: its lowest price
            self::rate('2027-07-19', 990.0, 18), // another circuit
            self::rate('2026-11-01', 800.0),     // already gone
            self::rate('2027-07-26', 0.0),       // no price
        ])])->forDeparture(17, 222, new \DateTimeImmutable('2026-11-07'));

        self::assertFalse($answer['error']);
        self::assertSame([
            ['date' => '2027-07-05', 'price' => 1201.2, 'currency' => 'EUR'],
            ['date' => '2027-07-12', 'price' => 1254.0, 'currency' => 'EUR'],
        ], $answer['departures']);
    }

    public function testOneAskCoversTheCircuitFromThatCityForTwelveMonths(): void
    {
        self::dates([self::page([])])->forDeparture(17, 222, new \DateTimeImmutable('2026-11-07'));

        self::assertCount(1, self::$asked);
        self::assertSame([17], self::$asked[0]['circuit_ids']);
        self::assertSame([222], self::$asked[0]['departures']);
        self::assertSame('2026-11', self::$asked[0]['months'][0]);
        self::assertSame('2027-10', self::$asked[0]['months'][11]);
        self::assertCount(12, self::$asked[0]['months']);
        self::assertSame(['page' => 1, 'per_page' => 100], self::$asked[0]['pagination']);
    }

    public function testEveryPageIsRead(): void
    {
        $answer = self::dates([
            self::page([self::rate('2027-07-05', 1000.0)], 2),
            self::page([self::rate('2027-08-02', 1000.0)], 2),
        ])->forDeparture(17, 0, new \DateTimeImmutable('2026-11-07'));

        self::assertSame(['2027-07-05', '2027-08-02'], array_column($answer['departures'], 'date'));
        self::assertSame([1, 2], array_map(static fn (array $p): int => $p['pagination']['page'], self::$asked));
        self::assertArrayNotHasKey('departures', self::$asked[0], 'any city: no departure filter');
    }

    /** The visitor moving between months, or coming back, asks Sphinx once a quarter of an hour. */
    public function testAnAnswerIsKeptForTheVisitor(): void
    {
        $dates = self::dates([self::page([self::rate('2027-07-05', 1000.0)])]);
        $today = new \DateTimeImmutable('2026-11-07');

        $first = $dates->forDeparture(17, 222, $today);
        $again = $dates->forDeparture(17, 222, $today);

        self::assertSame($first, $again);
        self::assertCount(1, self::$asked);
    }

    public function testNoAnswerFromSphinxIsAnError(): void
    {
        $answer = self::dates([null])->forDeparture(17, 222, new \DateTimeImmutable('2026-11-07'));

        self::assertSame(['departures' => [], 'error' => true], $answer);
        self::assertSame([], $_SESSION[CircuitDepartureDates::SESSION_KEY] ?? [], 'an error is not kept');
    }
}
