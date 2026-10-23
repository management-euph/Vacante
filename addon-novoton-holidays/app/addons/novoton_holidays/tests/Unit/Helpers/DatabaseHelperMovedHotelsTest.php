<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Helpers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Helpers\DatabaseHelper;
use Tygh\Addons\NovotonHolidays\Tests\Support\DbStub;

/**
 * geocode_addresses only asks for hotels without a stamp or without a street.
 * A hotel whose coordinates the hotel_list sync moves had its street looked
 * up for the old spot: the sync clears its stamp, so it is asked again.
 */
#[CoversClass(DatabaseHelper::class)]
final class DatabaseHelperMovedHotelsTest extends TestCase
{
    /** @var list<array{string, list<mixed>}> */
    private array $queries = [];

    protected function setUp(): void
    {
        $this->queries = [];
        DbStub::$query = function (string $query, mixed ...$params): int {
            $this->queries[] = [$query, $params];

            return 2;
        };
    }

    protected function tearDown(): void
    {
        DbStub::reset();
    }

    /**
     * @return list<array{string, list<mixed>}>
     */
    private function stampResets(): array
    {
        return array_values(array_filter($this->queries, static fn (array $q): bool => str_contains($q[0], 'SET geocoded_at = NULL')));
    }

    public function testAHotelThatMovedIsGeocodedAgain(): void
    {
        $asked = [];
        DbStub::$getArray = static function (string $query, mixed ...$params) use (&$asked): array {
            $asked = $params;

            return [
                ['hotel_id' => '476', 'latitude' => '43.28000000', 'longitude' => '28.04000000'],
                ['hotel_id' => '477', 'latitude' => '42.00000000', 'longitude' => '27.00000000'],
            ];
        };

        (new DatabaseHelper())->upsertHotels([
            ['hotel_id' => '476', 'latitude' => '43.2900', 'longitude' => '28.0400'],    // about 1.1 km north
            ['hotel_id' => '477', 'latitude' => '42.00001', 'longitude' => '27.00002'], // a rounding difference
            ['hotel_id' => '478', 'latitude' => '41.5', 'longitude' => '26.5'],         // new: never geocoded
            ['hotel_id' => '479', 'latitude' => '', 'longitude' => ''],                // no coordinates sent
        ]);

        self::assertSame([['476', '477', '478']], $asked, 'only hotels that came with coordinates are compared');
        self::assertSame([['UPDATE ?:novoton_hotels SET geocoded_at = NULL WHERE hotel_id IN (?a)', [['476']]]], $this->stampResets());
    }

    public function testNothingIsResetWhenNoHotelMoved(): void
    {
        DbStub::$getArray = static fn (): array => [['hotel_id' => '476', 'latitude' => '43.28', 'longitude' => '28.04']];

        (new DatabaseHelper())->upsertHotels([['hotel_id' => '476', 'latitude' => '43.28', 'longitude' => '28.04']]);

        self::assertSame([], $this->stampResets());
    }
}
