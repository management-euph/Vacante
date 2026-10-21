<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Api\SphinxHttpClient;
use Tygh\Addons\SphinxHolidays\Repository\HotelSkipRepository;
use Tygh\Addons\SphinxHolidays\Services\HotelAvailabilityGate;
use Tygh\Addons\SphinxHolidays\SphinxApi;

/**
 * Characterization coverage for HotelAvailabilityGate — the immediate-availability
 * gate extracted from HotelSyncService. The API, HTTP client and skip repository
 * are mocked; the gate is built with zero delays so the tests run without real
 * sleeps. They pin the delete/clear decision logic, the probed-destination
 * accounting, the circuit-breaker short-circuit, error handling, and the cursor
 * poll path.
 */
#[CoversClass(HotelAvailabilityGate::class)]
class HotelAvailabilityGateTest extends TestCase
{
    private SphinxApi $api;
    private SphinxHttpClient $httpClient;
    private HotelSkipRepository $skip;
    /** @var list<string> */
    private array $output = [];
    /** @var list<list<string>> hotel-id lists passed to markSkippedBatch, in call order */
    private array $flagged = [];

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(SphinxHttpClient::class);
        $this->httpClient->method('isCircuitOpen')->willReturn(false);
        $this->api = $this->createMock(SphinxApi::class);
        $this->api->method('getHttpClient')->willReturn($this->httpClient);
        $this->skip = $this->createMock(HotelSkipRepository::class);
        $this->output = [];
        $this->flagged = [];
    }

    /** markSkippedBatch is called for unlinked hotels first, then for linked ones. */
    private function recordFlags(): void
    {
        $this->skip->method('markSkippedBatch')->willReturnCallback(function (array $ids, string $reason): int {
            $this->assertSame('no_availability', $reason);
            $this->flagged[] = array_values(array_map('strval', $ids));

            return count($ids);
        });
    }

    private function gate(): HotelAvailabilityGate
    {
        // destDelayUs = 0, pollIntervalSecs = 0 → no real sleeps in tests.
        return new HotelAvailabilityGate($this->api, $this->skip, 0, 0);
    }

    private function sink(): callable
    {
        return function (string $m): void {
            $this->output[] = $m;
        };
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

    public function testReturnsStatsForNoDestinations(): void
    {
        $this->skip->expects($this->never())->method('findAvailabilityGateCandidates');
        $stats = ['synced' => 3];

        $this->assertSame($stats, $this->gate()->apply('GR', [], $stats, $this->sink()));
    }

    public function testNoCandidatesShortCircuits(): void
    {
        $this->skip->method('findAvailabilityGateCandidates')->willReturn([]);
        $this->api->expects($this->never())->method('searchHotels');

        $stats = $this->gate()->apply('GR', [5], ['synced' => 1], $this->sink());

        $this->assertSame(['synced' => 1], $stats);
        $this->assertTrue($this->outputContains('no hotels to check'));
    }

    /**
     * A hotel without a product and without an immediate offer is kept and
     * flagged, never deleted: incremental hotel syncs never bring a deleted
     * row back, so a seaside hotel removed in October could not become a
     * product next summer. The flag keeps it from becoming a product meanwhile.
     */
    public function testUnavailableHotelWithoutProductWaitsAndAvailableIsCleared(): void
    {
        $this->skip->method('findAvailabilityGateCandidates')->willReturn([
            ['hotel_id' => 'H1', 'destination_id' => 5, 'product_skip_reason' => ''],
            ['hotel_id' => 'H2', 'destination_id' => 5, 'product_skip_reason' => 'no_availability'],
        ]);
        // Only H2 has an immediate offer → H2 becomes bookable, H1 stays unavailable.
        $this->api->method('searchHotels')->willReturn([
            'results' => [['confirmation' => 'immediate', 'hotel_id' => 'H2']],
        ]);
        $this->recordFlags();
        $this->skip->expects($this->once())->method('clearSkipReasonBatch')
            ->with(['H2'], 'no_availability')->willReturn(1);

        $stats = $this->gate()->apply('GR', [5], [], $this->sink());

        $this->assertSame([['H1'], []], $this->flagged, 'H1 waits for an immediate offer; no linked hotel to flag');
        $this->assertSame(1, $stats['availability_probed']);
        $this->assertSame(1, $stats['availability_waiting']);
        $this->assertSame(1, $stats['availability_cleared']);
        $this->assertSame(0, $stats['availability_errors']);
    }

    /**
     * A LINKED hotel that lost its immediate offer is NOT deleted: its
     * CS-Cart product goes Hidden (direct link only, absent from listings
     * and searches) and the row is flagged — so a seasonal hotel keeps its
     * page for the day availability returns. Product first, flag second:
     * the flag is what marks the product as gate-hidden.
     */
    public function testLinkedUnavailableHotelGetsProductHiddenAndRowFlagged(): void
    {
        $this->skip->method('findAvailabilityGateCandidates')->willReturn([
            ['hotel_id' => 'H1', 'destination_id' => 5, 'product_id' => 42, 'product_skip_reason' => ''],
        ]);
        $this->api->method('searchHotels')->willReturn(['results' => []]);

        $this->skip->expects($this->once())->method('hideProductsBatch')
            ->with([42])->willReturn(1);
        $this->recordFlags();

        $stats = $this->gate()->apply('GR', [5], [], $this->sink());

        $this->assertSame([[], ['H1']], $this->flagged, 'the linked row is flagged after its product is hidden');
        $this->assertSame(0, $stats['availability_waiting']);
        $this->assertSame(1, $stats['availability_products_hidden']);
        $this->assertSame(0, $stats['availability_products_restored']);
    }

    /**
     * The way back: a flagged linked hotel whose immediate offer returned
     * gets the flag cleared AND the gate-hidden product reactivated.
     */
    public function testFlaggedLinkedHotelBackInAvailabilityIsClearedAndReactivated(): void
    {
        $this->skip->method('findAvailabilityGateCandidates')->willReturn([
            ['hotel_id' => 'H1', 'destination_id' => 5, 'product_id' => 42, 'product_skip_reason' => 'no_availability'],
        ]);
        $this->api->method('searchHotels')->willReturn([
            'results' => [['confirmation' => 'immediate', 'hotel_id' => 'H1']],
        ]);

        $this->skip->expects($this->once())->method('showProductsBatch')
            ->with([42])->willReturn(1);
        $this->skip->expects($this->once())->method('clearSkipReasonBatch')
            ->with(['H1'], 'no_availability')->willReturn(1);
        $this->skip->expects($this->once())->method('hideProductsBatch')
            ->with([])->willReturn(0);

        $stats = $this->gate()->apply('GR', [5], [], $this->sink());

        $this->assertSame(1, $stats['availability_cleared']);
        $this->assertSame(1, $stats['availability_products_restored']);
        $this->assertSame(0, $stats['availability_products_hidden']);
    }

    /**
     * The probe is multi-window: a hotel with inventory only in a later
     * window (e.g. summer-only availability missing from the +14d probe)
     * must NOT be flagged.
     */
    public function testHotelAvailableOnlyInLaterWindowIsNotFlagged(): void
    {
        $this->skip->method('findAvailabilityGateCandidates')->willReturn([
            ['hotel_id' => 'H1', 'destination_id' => 5, 'product_skip_reason' => ''],
        ]);
        $calls = 0;
        $this->api->method('searchHotels')->willReturnCallback(function () use (&$calls): array {
            $calls++;
            // Window 1: nothing. Window 2: H1 has an immediate offer.
            return $calls === 1
                ? ['results' => []]
                : ['results' => [['confirmation' => 'immediate', 'hotel_id' => 'H1']]];
        });

        $this->recordFlags();
        $this->skip->expects($this->once())->method('clearSkipReasonBatch')
            ->with([], 'no_availability')->willReturn(0);

        $stats = $this->gate()->apply('GR', [5], [], $this->sink());

        $this->assertGreaterThanOrEqual(2, $calls, 'later windows must be probed');
        $this->assertSame([[], []], $this->flagged);
        $this->assertSame(0, $stats['availability_waiting']);
    }

    public function testStopsProbingFurtherWindowsOnceAllCandidatesAvailable(): void
    {
        $this->skip->method('findAvailabilityGateCandidates')->willReturn([
            ['hotel_id' => 'H1', 'destination_id' => 5, 'product_skip_reason' => ''],
        ]);
        $calls = 0;
        $this->api->method('searchHotels')->willReturnCallback(function () use (&$calls): array {
            $calls++;
            return ['results' => [['confirmation' => 'immediate', 'hotel_id' => 'H1']]];
        });
        $this->skip->method('clearSkipReasonBatch')->willReturn(0);

        $this->gate()->apply('GR', [5], [], $this->sink());

        $this->assertSame(1, $calls, 'all candidates available after window 1 — remaining windows skipped');
    }

    public function testSearchRequestCarriesDestinationAndCurrency(): void
    {
        $this->skip->method('findAvailabilityGateCandidates')->willReturn([
            ['hotel_id' => 'H1', 'destination_id' => 5, 'product_skip_reason' => ''],
        ]);
        $captured = null;
        $this->api->method('searchHotels')->willReturnCallback(function (array $params) use (&$captured): array {
            $captured = $params;
            return ['results' => []];
        });
        $this->skip->method('clearSkipReasonBatch')->willReturn(0);

        $this->gate()->apply('GR', [5], [], $this->sink());

        $this->assertIsArray($captured);
        $this->assertSame(5, $captured['destination_id']);
        $this->assertSame('EUR', $captured['currency']);
        $this->assertSame([['adults' => 2, 'children_ages' => []]], $captured['occupancy']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $captured['check_in']);
    }

    public function testCircuitBreakerStopsProbeAndFlagsNothing(): void
    {
        $httpClient = $this->createMock(SphinxHttpClient::class);
        $httpClient->method('isCircuitOpen')->willReturn(true);
        $api = $this->createMock(SphinxApi::class);
        $api->method('getHttpClient')->willReturn($httpClient);
        $api->expects($this->never())->method('searchHotels');

        $this->skip->method('findAvailabilityGateCandidates')->willReturn([
            ['hotel_id' => 'H1', 'destination_id' => 5, 'product_skip_reason' => ''],
        ]);
        // Nothing probed → nothing flagged or cleared (called with empty lists).
        $this->recordFlags();
        $this->skip->expects($this->once())->method('clearSkipReasonBatch')->with([], 'no_availability')->willReturn(0);

        $gate = new HotelAvailabilityGate($api, $this->skip, 0, 0);
        $stats = $gate->apply('GR', [5], [], $this->sink());

        $this->assertSame([[], []], $this->flagged);
        $this->assertSame(0, $stats['availability_probed']);
        $this->assertTrue($this->outputContains('Circuit breaker open'));
    }

    public function testSearchErrorIsCountedAndNotProbed(): void
    {
        $this->skip->method('findAvailabilityGateCandidates')->willReturn([
            ['hotel_id' => 'H1', 'destination_id' => 5, 'product_skip_reason' => ''],
        ]);
        $this->api->method('searchHotels')->willReturn(null); // request failed

        // Destination not probed → H1 is left as it was; nothing to clear.
        $this->recordFlags();
        $this->skip->method('clearSkipReasonBatch')->willReturn(0);

        $stats = $this->gate()->apply('GR', [5], [], $this->sink());

        $this->assertSame([[], []], $this->flagged);
        $this->assertSame(0, $stats['availability_probed']);
        // One error per probe window (the destination is retried for each).
        $this->assertSame(3, $stats['availability_errors']);
    }

    public function testWaitingHotelStillUnavailableStaysWaiting(): void
    {
        // A flagged hotel without a product is checked again on every run;
        // still no immediate offer → it stays flagged (markSkippedBatch only
        // writes NULL reasons, so the row is unchanged) and is never removed.
        $this->skip->method('findAvailabilityGateCandidates')->willReturn([
            ['hotel_id' => 'H9', 'destination_id' => 5, 'product_skip_reason' => 'no_availability'],
        ]);
        $this->api->method('searchHotels')->willReturn(['results' => []]);

        $this->recordFlags();
        $this->skip->expects($this->once())->method('clearSkipReasonBatch')
            ->with([], 'no_availability')->willReturn(0);

        $this->gate()->apply('GR', [5], [], $this->sink());

        $this->assertSame([['H9'], []], $this->flagged);
    }

    public function testPollPathCollectsFromCursor(): void
    {
        $this->skip->method('findAvailabilityGateCandidates')->willReturn([
            ['hotel_id' => 'H1', 'destination_id' => 5, 'product_skip_reason' => 'no_availability'],
        ]);
        // Inline results empty, but a cursor is returned → poll picks up H1.
        $this->api->method('searchHotels')->willReturn(['results' => [], 'cursor' => 'CUR-1']);
        $this->api->expects($this->once())->method('getHotelResults')->with('', 'CUR-1')->willReturn([
            'results' => [['confirmation' => 'immediate', 'hotel_id' => 'H1']],
            'cursor' => '', // terminal page
        ]);

        $this->skip->expects($this->once())->method('clearSkipReasonBatch')
            ->with(['H1'], 'no_availability')->willReturn(1);

        $stats = $this->gate()->apply('GR', [5], [], $this->sink());

        $this->assertSame(1, $stats['availability_cleared']);
        $this->assertSame(1, $stats['availability_probed']);
    }
}
