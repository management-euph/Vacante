<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Repository;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Repository\DestinationRepository;
use Tygh\Addons\SphinxHolidays\Repository\HotelRepository;
use Tygh\Addons\SphinxHolidays\Tests\Support\DbStub;

/**
 * Guards the multi-row upsert rewrite (audit H1): upsertBatch() must issue ONE
 * INSERT ... ON DUPLICATE KEY UPDATE per chunk — not one statement per row.
 * At full-sync scale the per-row form was ~100k round-trips per country sync.
 */
#[CoversClass(HotelRepository::class)]
#[CoversClass(DestinationRepository::class)]
class UpsertBatchTest extends TestCase
{
    /** 28 tuple positions per hotel row, minus the literal 'active' sync_status = 27 bound params. */
    private const HOTEL_PARAMS_PER_ROW = 28;
    private const DESTINATION_PARAMS_PER_ROW = 11;

    /** @var list<array{query: string, params: list<mixed>}> */
    private array $calls = [];

    protected function setUp(): void
    {
        DbStub::reset();
        $this->calls = [];

        DbStub::$query = function (string $query, ...$params): int {
            $this->calls[] = ['query' => $query, 'params' => $params];
            return 1;
        };
    }

    protected function tearDown(): void
    {
        DbStub::reset();
    }

    /** @return array<string, mixed> */
    private function hotel(string $id): array
    {
        return ['hotel_id' => $id, 'name' => 'Hotel ' . $id, 'country_code' => 'GR'];
    }

    public function testHotelBatchIssuesOneStatementForManyRows(): void
    {
        $repo = new HotelRepository();

        $submitted = $repo->upsertBatch([$this->hotel('h1'), $this->hotel('h2'), $this->hotel('h3')]);

        self::assertSame(3, $submitted);
        self::assertCount(1, $this->calls, 'a batch must be ONE statement, not one per hotel');

        $query = $this->calls[0]['query'];
        self::assertSame(3, substr_count($query, '(?s, ?s, ?i,'), 'expected one VALUES tuple per hotel');
        self::assertStringContainsString('ON DUPLICATE KEY UPDATE', $query);
        // Portable VALUES(col) form — the MySQL-8-only "AS alias" syntax breaks MariaDB.
        self::assertStringContainsString('name = VALUES(name)', $query);
        self::assertStringNotContainsString('AS new_row', $query);
        // Per-hotel address city/country (grid City/Country columns) are inserted + kept fresh.
        self::assertStringContainsString('address_city, address_country', $query);
        self::assertStringContainsString('address_city = VALUES(address_city)', $query);
        self::assertStringContainsString('address_country = VALUES(address_country)', $query);
        // Provenance of the City/Region ladder travels with the row.
        self::assertStringContainsString('location_source = VALUES(location_source)', $query);

        $params = $this->calls[0]['params'];
        self::assertCount(3 * self::HOTEL_PARAMS_PER_ROW, $params);
        self::assertSame('h1', $params[0]);
        self::assertSame('h2', $params[self::HOTEL_PARAMS_PER_ROW]);
        self::assertSame('h3', $params[2 * self::HOTEL_PARAMS_PER_ROW]);
    }

    public function testHotelRowsWithoutIdAreSkippedWithinTheBatch(): void
    {
        $repo = new HotelRepository();

        $submitted = $repo->upsertBatch([$this->hotel('h1'), ['name' => 'no id'], $this->hotel('h2')]);

        self::assertSame(2, $submitted);
        self::assertCount(1, $this->calls);
        self::assertCount(2 * self::HOTEL_PARAMS_PER_ROW, $this->calls[0]['params']);
    }

    public function testHotelBatchChunksLargeInputs(): void
    {
        $repo = new HotelRepository();

        $hotels = [];
        for ($i = 1; $i <= 250; $i++) {
            $hotels[] = $this->hotel('h' . $i);
        }

        $submitted = $repo->upsertBatch($hotels);

        self::assertSame(250, $submitted);
        self::assertCount(3, $this->calls, '250 rows should chunk into 100 + 100 + 50');
        self::assertCount(100 * self::HOTEL_PARAMS_PER_ROW, $this->calls[0]['params']);
        self::assertCount(50 * self::HOTEL_PARAMS_PER_ROW, $this->calls[2]['params']);
    }

    public function testHotelBatchWithNoValidRowsIssuesNoQuery(): void
    {
        $repo = new HotelRepository();

        self::assertSame(0, $repo->upsertBatch([['name' => 'no id']]));
        self::assertSame(0, $repo->upsertBatch([]));
        self::assertCount(0, $this->calls);
    }

    public function testDestinationBatchIssuesOneStatementForManyRows(): void
    {
        $repo = new DestinationRepository();

        $submitted = $repo->upsertBatch([
            ['destination_id' => 10, 'name' => 'Athens', 'type' => 'city'],
            ['destination_id' => 0, 'name' => 'invalid'],
            ['destination_id' => 20, 'name' => 'Crete', 'type' => 'region'],
        ]);

        self::assertSame(2, $submitted);
        self::assertCount(1, $this->calls, 'a batch must be ONE statement, not one per destination');

        $query = $this->calls[0]['query'];
        self::assertSame(2, substr_count($query, '(?i, ?s, ?s,'), 'expected one VALUES tuple per valid destination');
        self::assertStringContainsString('ON DUPLICATE KEY UPDATE', $query);

        $params = $this->calls[0]['params'];
        self::assertCount(2 * self::DESTINATION_PARAMS_PER_ROW, $params);
        self::assertSame(10, $params[0]);
        self::assertSame(20, $params[self::DESTINATION_PARAMS_PER_ROW]);
    }

    /**
     * Coordinates/rating must be bound as pre-formatted decimal STRINGS via
     * ?s — Tygh's ?d placeholder reformats decimals to ~2 dp and truncated
     * DECIMAL(10,8) coordinates (36.887069 was stored as 36.89, putting the
     * storefront map pin ~1 km off). Same failure mode as the currency
     * coefficients (exchange_rates.php). Do NOT revert these to ?d.
     */
    public function testHotelCoordinatesAndRatingBindAsFullPrecisionStringsNotDd(): void
    {
        $repo = new HotelRepository();

        $repo->upsertBatch([[
            'hotel_id' => '3612',
            'name' => 'Rixos Downtown',
            'latitude' => '36.887069',
            'longitude' => '30.674622',
            'rating' => 8.5,
        ]]);

        self::assertCount(1, $this->calls);
        $query = $this->calls[0]['query'];
        $params = $this->calls[0]['params'];

        self::assertStringNotContainsString('?d', $query, 'lossy ?d must not bind any hotel column');
        // Tuple positions 11/12 (lat/lng) and 26 (rating) → param indices
        // 10/11/25 (rating shifted by one when location_source was added).
        self::assertSame('36.88706900', $params[10]);
        self::assertSame('30.67462200', $params[11]);
        self::assertSame('8.5', $params[25]);
    }

    public function testDestinationCoordinatesBindAsFullPrecisionStringsNotDd(): void
    {
        $repo = new DestinationRepository();

        $repo->upsertBatch([[
            'destination_id' => 168566,
            'name' => 'Antalya',
            'type' => 'city',
            'latitude' => '36.8841234',
            'longitude' => '30.7056789',
        ]]);

        self::assertCount(1, $this->calls);
        $query = $this->calls[0]['query'];
        $params = $this->calls[0]['params'];

        self::assertStringNotContainsString('?d', $query, 'lossy ?d must not bind any destination column');
        // Tuple positions 7/8 (lat/lng) → param indices 6/7.
        self::assertSame('36.88412340', $params[6]);
        self::assertSame('30.70567890', $params[7]);
    }
}
