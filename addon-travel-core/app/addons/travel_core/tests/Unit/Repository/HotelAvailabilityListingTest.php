<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Repository;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Repository\HotelAvailabilityListing;
use Tygh\Addons\TravelCore\Tests\Support\DbStub;

/**
 * The Hotel availability page's reads: filters, paging, the counts per
 * supplier and state, and the CSV. (The statements were also run on MariaDB
 * 10.11 when written.)
 */
final class HotelAvailabilityListingTest extends TestCase
{
    /** @var list<string> */
    private array $sql = [];

    protected function setUp(): void
    {
        DbStub::reset();
        $this->sql = [];
        $log = function (string $query, mixed ...$params): void {
            // The page's own reads only, not the tables heal.
            if (preg_match('/^\s*(CREATE|SHOW|ALTER) /', $query) !== 1) {
                $this->sql[] = db_quote($query, ...$params);
            }
        };
        DbStub::$query = static function (string $query, mixed ...$params) use ($log): int {
            $log($query, ...$params);

            return 1;
        };
        DbStub::$getField = static function (string $query, mixed ...$params) use ($log): string {
            $log($query, ...$params);

            return '120';
        };
        DbStub::$getArray = static function (string $query, mixed ...$params) use ($log): array {
            $log($query, ...$params);

            return [['provider' => 'novoton', 'hotel_id' => '7', 'state' => 'dormant']];
        };
    }

    protected function tearDown(): void
    {
        DbStub::reset();
    }

    public function testUnknownFiltersAreDropped(): void
    {
        $filters = HotelAvailabilityListing::normalize([
            'page' => '-3',
            'items_per_page' => '100000',
            'provider' => "novoton' OR 1=1",
            'state' => 'gone',
            'no_offer_months' => '7',
            'sort_by' => 'price',
            'q' => '  Sol  ',
        ]);

        self::assertSame(1, $filters['page']);
        self::assertSame(500, $filters['items_per_page']);
        self::assertSame('novoton11', $filters['provider'], 'a registry name: lowercase letters, digits, _');
        self::assertSame('', $filters['state']);
        self::assertSame(0, $filters['no_offer_months'], 'only the offered month counts');
        self::assertSame('hotel', $filters['sort_by']);
        self::assertSame('Sol', $filters['q']);
    }

    public function testTheFiltersBecomeOneCondition(): void
    {
        $condition = HotelAvailabilityListing::condition(
            ['provider' => 'novoton', 'state' => 'not_offered', 'no_offer_months' => 6, 'q' => 'Sol'],
            new \DateTimeImmutable('2026-10-30 14:05:00'),
        );

        self::assertStringContainsString("a.provider = 'novoton'", $condition);
        self::assertStringContainsString("a.state = 'not_offered'", $condition);
        self::assertStringContainsString("(a.offer_seen_at IS NULL OR a.offer_seen_at < '2026-04-30 14:05:00')", $condition, 'never offered counts as no offer');
        self::assertStringContainsString("a.hotel_name LIKE '%Sol%' OR a.destination_key LIKE '%Sol%' OR a.hotel_id = 'Sol'", $condition);
    }

    public function testAPageCountsAllAndReadsItsSlice(): void
    {
        [$rows, $search] = (new HotelAvailabilityListing())->getListing(
            ['page' => '3', 'items_per_page' => '50', 'sort_by' => 'offer'],
            new \DateTimeImmutable('2026-10-30'),
        );

        self::assertSame(120, $search['total_items'], 'pagination reads the total from $search');
        self::assertSame('7', $rows[0]['hotel_id']);
        self::assertStringContainsString('SELECT COUNT(*) FROM ?:travel_hotel_availability a WHERE 1 ', $this->sql[0]);
        self::assertStringContainsString('ORDER BY a.offer_seen_at IS NOT NULL, a.offer_seen_at, a.hotel_name LIMIT 100, 50', $this->sql[1]);
    }

    public function testCountsHaveEveryStateForEverySupplier(): void
    {
        DbStub::$getArray = static fn (): array => [
            ['provider' => 'novoton', 'state' => 'bookable', 'hotels' => '12'],
            ['provider' => 'novoton', 'state' => 'between_seasons', 'hotels' => '300'],
            ['provider' => 'eurosite', 'state' => 'no_data', 'hotels' => '4'],
            ['provider' => 'eurosite', 'state' => 'retired', 'hotels' => '9'],
        ];

        $counts = (new HotelAvailabilityListing())->counts();

        self::assertSame(['novoton', 'eurosite'], array_keys($counts));
        self::assertSame(12, $counts['novoton']['bookable']);
        self::assertSame(300, $counts['novoton']['between_seasons']);
        self::assertSame(0, $counts['novoton']['dormant']);
        self::assertSame(4, $counts['eurosite']['no_data']);
        self::assertArrayNotHasKey('retired', $counts['eurosite'], 'an unknown state is not counted');
        self::assertCount(7, $counts['eurosite']);
    }

    public function testTheExportKeepsTheFiltersWithoutPaging(): void
    {
        (new HotelAvailabilityListing())->exportRows(['state' => 'dormant', 'page' => '4'], new \DateTimeImmutable('2026-10-30'));

        self::assertStringContainsString("a.state = 'dormant'", $this->sql[0]);
        self::assertStringEndsWith('LIMIT ' . HotelAvailabilityListing::EXPORT_LIMIT, $this->sql[0]);
    }

    public function testTheCsvOpensInExcelColumnsAndRunsNoFormula(): void
    {
        $csv = HotelAvailabilityListing::csv([
            ['provider' => 'novoton', 'hotel_id' => '7', 'hotel_name' => 'Hotel "Marea"; Neagră', 'product_id' => '15', 'state' => 'dormant'],
            ['provider' => 'novoton', 'hotel_id' => '8', 'hotel_name' => '=HYPERLINK("x")', 'product_id' => '0', 'state' => 'no_data'],
        ]);

        self::assertStringStartsWith("\u{FEFF}provider;hotel_id;hotel_name;product_id;destination;state;", $csv, 'BOM, then the header');
        $lines = explode("\n", trim($csv));
        self::assertCount(3, $lines);
        self::assertStringStartsWith('novoton;7;"Hotel ""Marea""; Neagră";15;;dormant;', $lines[1]);
        self::assertStringContainsString(";\"'=HYPERLINK(\"\"x\"\")\";", $lines[2]);
        self::assertSame(count(HotelAvailabilityListing::CSV_COLUMNS) - 1, substr_count($lines[2], ';'), 'one cell per column');
    }
}
