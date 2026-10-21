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
 * sleeps. They pin the wait/clear decision for hotels without a product, the
 * per-product hide/show/release decision for linked ones, the probed-destination
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

    /** markSkippedBatch is called once, for the hotels without a product. */
    private function recordFlags(): void
    {
        $this->skip->method('markSkippedBatch')->willReturnCallback(function (array $ids, string $reason): int {
            $this->assertSame('no_availability', $reason);
            $this->flagged[] = array_values(array_map('strval', $ids));

            return count($ids);
        });
    }

    /** @return array{status: string, hidden: bool, released: bool} */
    private static function state(string $status, bool $hidden = false, bool $released = false): array
    {
        return ['status' => $status, 'hidden' => $hidden, 'released' => $released];
    }

    /** One linked candidate: hotel H1 in destination 5, product 42. */
    private function linkedCandidate(string $reason = ''): void
    {
        $this->skip->method('findAvailabilityGateCandidates')->willReturn([
            ['hotel_id' => 'H1', 'destination_id' => 5, 'product_id' => 42, 'product_skip_reason' => $reason],
        ]);
    }

    /** Every probe of destination 5 answers: with an immediate offer for H1, or with none. */
    private function searchFinds(bool $immediateOffer): void
    {
        $this->api->method('searchHotels')->willReturn([
            'results' => $immediateOffer ? [['confirmation' => 'immediate', 'hotel_id' => 'H1']] : [],
        ]);
    }

    /**
     * @param list<int> $hide
     * @param list<int> $show
     * @param list<int> $release
     * @param list<int> $endRelease
     */
    private function expectProductWrites(array $hide, array $show, array $release, array $endRelease): void
    {
        $this->skip->expects($this->once())->method('hideProductsBatch')->with($hide)->willReturn(count($hide));
        $this->skip->expects($this->once())->method('showProductsBatch')->with($show)->willReturn(count($show));
        $this->skip->expects($this->once())->method('releaseProductsBatch')->with($release)->willReturn(count($release));
        $this->skip->expects($this->once())->method('clearReleasedBatch')->with($endRelease);
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

        $this->assertSame([['H1']], $this->flagged, 'H1 waits for an immediate offer');
        $this->assertSame(1, $stats['availability_probed']);
        $this->assertSame(1, $stats['availability_waiting']);
        $this->assertSame(1, $stats['availability_cleared']);
        $this->assertSame(0, $stats['availability_errors']);
    }

    /**
     * A LINKED hotel that lost its immediate offer is NOT deleted: its Active
     * CS-Cart product goes Hidden (direct link only, absent from listings
     * and searches) and gate_hidden — so a seasonal hotel keeps its page for
     * the day availability returns. The row gets no skip reason: the
     * product's state lives in the gate's own columns.
     */
    public function testLinkedUnavailableHotelGetsProductHidden(): void
    {
        $this->linkedCandidate();
        $this->searchFinds(false);
        $this->skip->expects($this->once())->method('findGateStates')->with([42])
            ->willReturn([42 => self::state('A')]);
        $this->expectProductWrites([42], [], [], []);
        $this->recordFlags();

        $stats = $this->gate()->apply('GR', [5], [], $this->sink());

        $this->assertSame([[]], $this->flagged, 'a linked hotel is never flagged no_availability');
        $this->assertSame(0, $stats['availability_waiting']);
        $this->assertSame(1, $stats['availability_products_hidden']);
        $this->assertSame(0, $stats['availability_products_restored']);
    }

    /**
     * The way back: a product the gate hid is reactivated when its hotel's
     * immediate offer returns.
     */
    public function testGateHiddenProductBackInAvailabilityIsReactivated(): void
    {
        $this->linkedCandidate();
        $this->searchFinds(true);
        $this->skip->method('findGateStates')->willReturn([42 => self::state('H', hidden: true)]);
        $this->expectProductWrites([], [42], [], []);
        $this->skip->expects($this->once())->method('clearSkipReasonBatch')
            ->with([], 'no_availability')->willReturn(0);

        $stats = $this->gate()->apply('GR', [5], [], $this->sink());

        $this->assertSame(0, $stats['availability_cleared']);
        $this->assertSame(1, $stats['availability_products_restored']);
        $this->assertSame(0, $stats['availability_products_hidden']);
    }

    /**
     * An admin re-activated a product the gate hid, and the hotel still has
     * no immediate offer: the gate lets go of it instead of hiding it again.
     */
    public function testAdminReactivatedProductIsReleasedNotHiddenAgain(): void
    {
        $this->linkedCandidate();
        $this->searchFinds(false);
        $this->skip->method('findGateStates')->willReturn([42 => self::state('A', hidden: true)]);
        $this->expectProductWrites([], [], [42], []);

        $stats = $this->gate()->apply('GR', [5], [], $this->sink());

        $this->assertSame(1, $stats['availability_products_released']);
        $this->assertSame(0, $stats['availability_products_hidden']);
        $this->assertTrue($this->outputContains('1 product(s) changed by an admin and left alone'));
    }

    /** A released product stays as the admin set it on every run without an immediate offer… */
    public function testReleasedProductIsLeftAloneWhileUnavailable(): void
    {
        $this->linkedCandidate();
        $this->searchFinds(false);
        $this->skip->method('findGateStates')->willReturn([42 => self::state('A', released: true)]);
        $this->expectProductWrites([], [], [], []);

        $this->gate()->apply('GR', [5], [], $this->sink());
    }

    /** …and the release ends when an immediate offer returns, so the next loss hides it again. */
    public function testReleaseEndsWhenAnImmediateOfferReturns(): void
    {
        $this->linkedCandidate();
        $this->searchFinds(true);
        $this->skip->method('findGateStates')->willReturn([42 => self::state('A', released: true)]);
        $this->expectProductWrites([], [], [], [42]);

        $this->gate()->apply('GR', [5], [], $this->sink());
    }

    /** A product the admin hid by hand is the admin's, offer or not. */
    public function testProductHiddenByTheAdminIsNeverShown(): void
    {
        $this->linkedCandidate();
        $this->searchFinds(true);
        $this->skip->method('findGateStates')->willReturn([42 => self::state('H')]);
        $this->expectProductWrites([], [], [], []);

        $this->gate()->apply('GR', [5], [], $this->sink());
    }

    /**
     * Sibling hotel ids linked to one product share it: one immediate offer
     * among them keeps the product visible.
     */
    public function testSiblingWithAnImmediateOfferKeepsTheProductVisible(): void
    {
        $this->skip->method('findAvailabilityGateCandidates')->willReturn([
            ['hotel_id' => 'H1', 'destination_id' => 5, 'product_id' => 42, 'product_skip_reason' => ''],
            ['hotel_id' => 'H2', 'destination_id' => 5, 'product_id' => 42, 'product_skip_reason' => ''],
        ]);
        $this->api->method('searchHotels')->willReturn([
            'results' => [['confirmation' => 'immediate', 'hotel_id' => 'H2']],
        ]);
        $this->skip->expects($this->once())->method('findGateStates')->with([42])
            ->willReturn([42 => self::state('H', hidden: true)]);
        $this->expectProductWrites([], [42], [], []);

        $this->gate()->apply('GR', [5], [], $this->sink());
    }

    /** No offer, but one sibling's destination never answered: the product is left as it is. */
    public function testProductWithAnUnansweredSiblingIsNotHidden(): void
    {
        $this->skip->method('findAvailabilityGateCandidates')->willReturn([
            ['hotel_id' => 'H1', 'destination_id' => 5, 'product_id' => 42, 'product_skip_reason' => ''],
            ['hotel_id' => 'H2', 'destination_id' => 6, 'product_id' => 42, 'product_skip_reason' => ''],
        ]);
        $this->api->method('searchHotels')->willReturnCallback(
            static fn (array $params): ?array => $params['destination_id'] === 5 ? ['results' => []] : null,
        );
        $this->skip->method('findGateStates')->willReturn([42 => self::state('A')]);
        $this->expectProductWrites([], [], [], []);

        $stats = $this->gate()->apply('GR', [5, 6], [], $this->sink());

        $this->assertSame(1, $stats['availability_probed']);
    }

    /** A no_availability left on a linked hotel (a waiting hotel that got linked) is cleared. */
    public function testLinkedHotelLosesALeftoverNoAvailabilityFlag(): void
    {
        $this->linkedCandidate('no_availability');
        $this->searchFinds(false);
        $this->skip->method('findGateStates')->willReturn([42 => self::state('A')]);
        $this->expectProductWrites([42], [], [], []);
        $this->skip->expects($this->once())->method('clearSkipReasonBatch')
            ->with(['H1'], 'no_availability')->willReturn(1);

        $this->gate()->apply('GR', [5], [], $this->sink());
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
        $this->assertSame([[]], $this->flagged);
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

        $this->assertSame([[]], $this->flagged);
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

        $this->assertSame([[]], $this->flagged);
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

        $this->assertSame([['H9']], $this->flagged);
    }

    public function testDestinationsWithoutCandidatesAreNotSearched(): void
    {
        $this->skip->method('findAvailabilityGateCandidates')->willReturn([
            ['hotel_id' => 'H1', 'destination_id' => 5, 'product_skip_reason' => ''],
        ]);
        $searched = [];
        $this->api->method('searchHotels')->willReturnCallback(function (array $params) use (&$searched): array {
            $searched[] = $params['destination_id'];

            return ['results' => []];
        });

        $this->gate()->apply('GR', [5, 6, 7], [], $this->sink());

        $this->assertSame([5, 5, 5], $searched, 'one search per window, for the destination with candidates only');
    }

    /** A search that was accepted but whose poll failed is no answer: its silence proves nothing. */
    public function testPollThatFailsLeavesTheDestinationUnanswered(): void
    {
        $this->skip->method('findAvailabilityGateCandidates')->willReturn([
            ['hotel_id' => 'H1', 'destination_id' => 5, 'product_skip_reason' => ''],
        ]);
        $this->api->method('searchHotels')->willReturn(['results' => [], 'cursor' => 'CUR-1']);
        $this->api->method('getHotelResults')->willReturn(null);
        $this->recordFlags();

        $stats = $this->gate()->apply('GR', [5], [], $this->sink());

        $this->assertSame([[]], $this->flagged, 'H1 is not flagged on half an answer');
        $this->assertSame(0, $stats['availability_probed']);
        $this->assertSame(3, $stats['availability_errors']);
        $this->assertTrue($this->outputContains('did not finish'));
    }

    public function testPollCutShortByTheDeadlineLeavesTheDestinationUnanswered(): void
    {
        $this->skip->method('findAvailabilityGateCandidates')->willReturn([
            ['hotel_id' => 'H1', 'destination_id' => 5, 'product_skip_reason' => ''],
        ]);
        $this->api->method('searchHotels')->willReturn(['results' => [], 'cursor' => 'CUR-1']);
        $this->api->expects($this->never())->method('getHotelResults');
        $this->recordFlags();

        // pollDeadlineSecs = 0: the deadline has passed before the first poll.
        $gate = new HotelAvailabilityGate($this->api, $this->skip, 0, 0, 0);
        $stats = $gate->apply('GR', [5], [], $this->sink());

        $this->assertSame([[]], $this->flagged);
        $this->assertSame(0, $stats['availability_probed']);
    }

    /** Offers seen before a poll was cut short still count: they are evidence of availability. */
    public function testOffersSeenBeforeAPollIsCutStillCount(): void
    {
        $this->skip->method('findAvailabilityGateCandidates')->willReturn([
            ['hotel_id' => 'H1', 'destination_id' => 5, 'product_skip_reason' => ''],
            ['hotel_id' => 'H2', 'destination_id' => 5, 'product_skip_reason' => 'no_availability'],
        ]);
        $this->api->method('searchHotels')->willReturn(['results' => [], 'cursor' => 'CUR-1']);
        $polls = 0;
        $this->api->method('getHotelResults')->willReturnCallback(function () use (&$polls): ?array {
            $polls++;

            // First page of each search: H2's offer and a live cursor; then the poll fails.
            return $polls % 2 === 1
                ? ['results' => [['confirmation' => 'immediate', 'hotel_id' => 'H2']], 'cursor' => 'CUR-2']
                : null;
        });
        $this->recordFlags();
        $this->skip->expects($this->once())->method('clearSkipReasonBatch')
            ->with(['H2'], 'no_availability')->willReturn(1);

        $stats = $this->gate()->apply('GR', [5], [], $this->sink());

        $this->assertSame([[]], $this->flagged, 'H1 is left as it is: the destination never answered in full');
        $this->assertSame(1, $stats['availability_cleared']);
        $this->assertSame(0, $stats['availability_probed']);
    }

    public function testOneFailedWindowLeavesTheDestinationUnanswered(): void
    {
        $this->skip->method('findAvailabilityGateCandidates')->willReturn([
            ['hotel_id' => 'H1', 'destination_id' => 5, 'product_skip_reason' => ''],
        ]);
        $calls = 0;
        $this->api->method('searchHotels')->willReturnCallback(function () use (&$calls): ?array {
            $calls++;

            return $calls === 2 ? null : ['results' => []]; // the +30d window fails
        });
        $this->recordFlags();

        $stats = $this->gate()->apply('GR', [5], [], $this->sink());

        $this->assertSame(3, $calls);
        $this->assertSame([[]], $this->flagged, 'no offer in the windows that answered is not enough');
        $this->assertSame(0, $stats['availability_probed']);
        $this->assertSame(1, $stats['availability_errors']);
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
