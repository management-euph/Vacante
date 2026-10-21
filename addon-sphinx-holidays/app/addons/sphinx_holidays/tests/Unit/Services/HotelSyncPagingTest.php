<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Api\SphinxHttpClient;
use Tygh\Addons\SphinxHolidays\Repository\DestinationRepository;
use Tygh\Addons\SphinxHolidays\Repository\HotelRepository;
use Tygh\Addons\SphinxHolidays\Repository\HotelSkipRepository;
use Tygh\Addons\SphinxHolidays\Services\HotelAvailabilityGate;
use Tygh\Addons\SphinxHolidays\Services\HotelRowMapper;
use Tygh\Addons\SphinxHolidays\Services\HotelSyncService;
use Tygh\Addons\SphinxHolidays\SphinxApi;

/**
 * A full hotel sync pages each chunk of destination ids (200 per request)
 * by that chunk's own count, and marks hotels inactive only after a whole
 * list. It used to compare a country-wide count with each chunk's
 * meta.total, so the second chunk stopped early, and the hotels on its
 * missing pages were marked inactive.
 */
final class HotelSyncPagingTest extends TestCase
{
    private SphinxApi $api;
    private SphinxHttpClient $http;
    private HotelRepository $hotels;
    /** @var list<string> "chunk:page" for every getHotels call */
    private array $requests = [];
    /** @var list<string> */
    private array $output = [];

    protected function setUp(): void
    {
        $this->http = $this->createMock(SphinxHttpClient::class);
        $this->http->method('isCircuitOpen')->willReturn(false);
        $this->api = $this->createMock(SphinxApi::class);
        $this->api->method('getHttpClient')->willReturn($this->http);
        $this->hotels = $this->createMock(HotelRepository::class);
        $this->hotels->method('upsertBatch')->willReturnCallback(static fn (array $rows): int => count($rows));
        $this->hotels->method('findGeoLocations')->willReturn([]);
        $this->requests = [];
        $this->output = [];
    }

    /**
     * Two chunks (201 destinations). Each page is answered from $pages:
     * chunk index => page => [item count, meta.total, meta.last_page] or null for a failed request.
     *
     * @param array<int, array<int, array{int, int, int}|null>> $pages
     */
    private function listing(array $pages): void
    {
        $this->api->method('getHotels')->willReturnCallback(
            function (int $page, int $perPage, ?string $updatedSince, array $destIds) use ($pages): ?array {
                $chunk = $destIds[0] === 1 ? 0 : 1;
                $this->requests[] = "{$chunk}:{$page}";
                $answer = array_key_exists($page, $pages[$chunk]) ? $pages[$chunk][$page] : [0, 0, $page];
                if ($answer === null) {
                    return null;
                }
                [$count, $total, $lastPage] = $answer;
                $data = [];
                for ($i = 0; $i < $count; $i++) {
                    $data[] = ['id' => "{$chunk}-{$page}-{$i}"];
                }

                return ['data' => $data, 'meta' => ['current_page' => $page, 'last_page' => $lastPage, 'per_page' => $perPage, 'total' => $total]];
            },
        );
    }

    /** @return array<string, mixed> */
    private function syncCountry(?string $updatedSince = null): array
    {
        $mapper = $this->createMock(HotelRowMapper::class);
        $mapper->method('normalize')->willReturnCallback(
            static fn (array $raw): array => ['hotel_id' => (string) $raw['id'], 'country_code' => 'GR'],
        );
        $mapper->method('enrichFromHierarchy')->willReturnArgument(0);
        $gate = $this->createMock(HotelAvailabilityGate::class);
        $gate->method('apply')->willReturnArgument(2);

        $service = new HotelSyncService(
            $this->api,
            $this->hotels,
            $this->createMock(DestinationRepository::class),
            $this->createMock(HotelSkipRepository::class),
            null,
            $mapper,
            $gate,
        );
        $service->setOutputCallback(function (string $line): void {
            $this->output[] = $line;
        });

        $method = new \ReflectionMethod(HotelSyncService::class, 'syncCountry');
        $stats = $method->invoke($service, 'GR', range(1, 201), $updatedSince);
        self::assertIsArray($stats);

        return $stats;
    }

    private function outputContains(string $needle): bool
    {
        foreach ($this->output as $line) {
            if (str_contains($line, $needle)) {
                return true;
            }
        }

        return false;
    }

    public function testEachChunkIsPagedToItsOwnEnd(): void
    {
        $this->listing([
            0 => [1 => [1000, 1500, 2], 2 => [500, 1500, 2]],
            1 => [1 => [1000, 1200, 2], 2 => [200, 1200, 2]],
        ]);
        $this->hotels->expects($this->once())->method('markInactiveBefore')->willReturn(0);

        $stats = $this->syncCountry();

        // The second chunk used to stop after page 1: 1500 + 1000 fetched >= its total of 1200.
        self::assertSame(['0:1', '0:2', '1:1', '1:2'], $this->requests);
        self::assertSame(2700, $stats['total']);
    }

    public function testAPageErrorKeepsUnseenHotelsActive(): void
    {
        $this->listing([
            0 => [1 => [1000, 1500, 2], 2 => [500, 1500, 2]],
            1 => [1 => [1000, 1200, 2], 2 => null],
        ]);
        $this->hotels->expects($this->never())->method('markInactiveBefore');

        $this->syncCountry();

        self::assertTrue($this->outputContains('the hotel list did not come back whole'));
    }

    public function testAnEmptyPageBeforeTheAnnouncedTotalKeepsUnseenHotelsActive(): void
    {
        $this->listing([
            0 => [1 => [1000, 1500, 2], 2 => [500, 1500, 2]],
            1 => [1 => [1000, 2500, 3], 2 => [0, 2500, 3]],
        ]);
        $this->hotels->expects($this->never())->method('markInactiveBefore');

        $this->syncCountry();
    }

    public function testAnIncrementalSyncNeverMarksHotelsInactive(): void
    {
        $this->listing([
            0 => [1 => [10, 10, 1]],
            1 => [1 => [5, 5, 1]],
        ]);
        $this->hotels->expects($this->never())->method('markInactiveBefore');

        $this->syncCountry('2026-10-20 00:00:00');

        self::assertFalse($this->outputContains('did not come back whole'));
    }
}
