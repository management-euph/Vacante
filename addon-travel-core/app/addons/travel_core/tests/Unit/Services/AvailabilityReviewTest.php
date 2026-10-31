<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Contracts\CronDispatcherInterface;
use Tygh\Addons\TravelCore\Contracts\HotelAvailabilitySourceInterface;
use Tygh\Addons\TravelCore\Cron\CronRunLog;
use Tygh\Addons\TravelCore\Enums\AvailabilityMode;
use Tygh\Addons\TravelCore\Enums\HotelListCoverage;
use Tygh\Addons\TravelCore\Services\AvailabilityReview;
use Tygh\Addons\TravelCore\Services\TravelProviderRegistry;
use Tygh\Addons\TravelCore\Tests\Support\DbStub;
use Tygh\Addons\TravelCore\ValueObjects\AvailabilityCapabilities;
use Tygh\Addons\TravelCore\ValueObjects\AvailabilityThresholds;

/**
 * The weekly review: when it runs, when it waits, how it counts a
 * destination's peers, and what it writes. (The statements were also run on
 * MariaDB 10.11 when written.)
 */
final class AvailabilityReviewTest extends TestCase
{
    private const int NOW = 1_792_900_000;

    /** @var list<string> */
    private array $sql = [];

    protected function setUp(): void
    {
        DbStub::reset();
        TravelProviderRegistry::reset();
        $this->sql = [];
        DbStub::$query = function (string $query, mixed ...$params): int {
            // Not the tables heal, which runs once per request (process).
            if (preg_match('/^\s*(CREATE|ALTER) TABLE/', $query) !== 1) {
                $this->sql[] = db_quote($query, ...$params);
            }

            return 1;
        };
    }

    protected function tearDown(): void
    {
        DbStub::reset();
        TravelProviderRegistry::reset();
        CronRunLog::useStorage(null, null);
    }

    private static function source(bool $sharedGate = false): HotelAvailabilitySourceInterface
    {
        return new class ($sharedGate) implements HotelAvailabilitySourceInterface {
            public function __construct(private readonly bool $sharedGate)
            {
            }

            #[\Override]
            public function provider(): string
            {
                return 'novoton';
            }

            #[\Override]
            public function capabilities(): AvailabilityCapabilities
            {
                return new AvailabilityCapabilities(HotelListCoverage::PerCountry, true, 'resort', false, true, true, $this->sharedGate);
            }

            #[\Override]
            public function recordingModes(): array
            {
                return ['hotel_list', 'room_price'];
            }
        };
    }

    /** @param array<string, array<string, int|bool|string>> $runs storage key suffix => record */
    private static function runLog(array $runs): void
    {
        CronRunLog::useStorage(
            static fn (string $key): ?string => isset($runs[substr($key, strlen('travel_cron_run.'))])
                ? (string) json_encode($runs[substr($key, strlen('travel_cron_run.'))])
                : null,
            static function (): void {
            },
            static fn (): int => self::NOW,
        );
    }

    public function testTheReviewIsDueOnceAWeekAndAgainAfterAFailure(): void
    {
        self::assertTrue(AvailabilityReview::isDue([], self::NOW), 'never ran');
        self::assertFalse(AvailabilityReview::isDue(['finished' => self::NOW - 6 * 86400, 'ok' => true], self::NOW));
        self::assertTrue(AvailabilityReview::isDue(['finished' => self::NOW - 7 * 86400, 'ok' => true], self::NOW));
        self::assertTrue(AvailabilityReview::isDue(['finished' => self::NOW - 3600, 'ok' => false], self::NOW), 'failed: try again');
    }

    public function testItWaitsWhileARecordingJobRunsButNotForADeadOne(): void
    {
        $cron = ['novoton' => ['addon' => 'novoton_holidays', 'label' => 'Novoton']];
        $sources = ['novoton' => self::source()];

        self::runLog(['novoton_holidays.hotel_list' => ['started' => self::NOW - 600]]);
        self::assertSame(['Novoton: hotel_list'], AvailabilityReview::busyJobs($sources, $cron, self::NOW));

        self::runLog(['novoton_holidays.hotel_list' => ['started' => self::NOW - 7 * 3600]]);
        self::assertSame([], AvailabilityReview::busyJobs($sources, $cron, self::NOW), 'open for 7 hours: dead, not running');

        self::runLog(['novoton_holidays.room_price' => ['started' => self::NOW - 600, 'finished' => self::NOW - 60, 'ok' => true]]);
        self::assertSame([], AvailabilityReview::busyJobs($sources, $cron, self::NOW));
    }

    public function testADestinationsPeersCountTwoWeeksOfChecks(): void
    {
        $rows = [
            ['destination_key' => 'BG|Sunny Beach', 'offer_checked_at' => '2027-07-18 04:00:00', 'offer_seen_at' => '2027-07-18 04:00:00'],
            ['destination_key' => 'BG|Sunny Beach', 'offer_checked_at' => '2027-07-12 04:00:00', 'offer_seen_at' => null],
            ['destination_key' => 'BG|Sunny Beach', 'offer_checked_at' => '2027-06-01 04:00:00', 'offer_seen_at' => '2027-06-01 04:00:00'],
            ['destination_key' => 'BG|Albena', 'offer_checked_at' => '2027-07-10 04:00:00', 'offer_seen_at' => '2027-07-10 04:00:00'],
            ['destination_key' => '', 'offer_checked_at' => '2027-07-18 04:00:00'],
        ];

        $peers = AvailabilityReview::peers($rows, new AvailabilityThresholds(), new \DateTimeImmutable('2027-07-20'));

        self::assertSame(['BG|Sunny Beach', 'BG|Albena'], array_keys($peers));
        self::assertSame(2, $peers['BG|Sunny Beach']->checkedHotels, 'the June check is too old');
        self::assertSame(1, $peers['BG|Sunny Beach']->hotelsWithOffers);
        self::assertTrue($peers['BG|Sunny Beach']->lastCheckAnswered);
        self::assertFalse($peers['BG|Albena']->lastCheckAnswered, 'checked 10 days ago');
    }

    public function testTheReviewWritesWhatChangedAndLogsIt(): void
    {
        DbStub::$getHashSingleArray = static fn (): array => [4 => '2025-11-10'];
        DbStub::$getArray = static fn (): array => [
            // First classification: logged, not news.
            ['provider' => 'novoton', 'hotel_id' => '3', 'product_id' => '0', 'listed_missing_runs' => '3', 'hotel_name' => 'Gone', 'state' => 'no_data', 'state_since' => null, 'state_reason' => '', 'season_published' => 'N', 'created_at' => '2026-10-01 00:00:00', 'known_since' => '2023-01-01', 'backfilled_at' => '2026-10-01 00:00:00'],
            // Already between seasons and still so: nothing written.
            ['provider' => 'novoton', 'hotel_id' => '5', 'product_id' => '5', 'contract_last_date' => '2026-09-30', 'hotel_name' => 'Same', 'state' => 'between_seasons', 'state_since' => '2026-10-16 14:05:00', 'state_reason' => 'season_memory', 'season_published' => 'N', 'created_at' => '2026-10-01 00:00:00'],
            // An offer came back: news.
            ['provider' => 'novoton', 'hotel_id' => '4', 'product_id' => '0', 'offer_seen_at' => '2026-10-29 10:00:00', 'hotel_name' => 'Back', 'state' => 'between_seasons', 'state_since' => '2026-10-23 14:05:00', 'state_reason' => 'season_memory', 'season_published' => 'N', 'created_at' => '2026-10-01 00:00:00'],
        ];

        $result = (new AvailabilityReview())->reviewProvider('novoton', new AvailabilityThresholds(), new \DateTimeImmutable('2026-10-30 14:05:00'));

        self::assertSame(1, $result['counts']['left_feed']);
        self::assertSame(1, $result['counts']['between_seasons']);
        self::assertSame(1, $result['counts']['bookable']);
        self::assertSame(1, $result['first']);
        self::assertSame(1, $result['changed']);
        self::assertSame([['hotel_id' => '4', 'hotel_name' => 'Back', 'product_id' => 0, 'from' => 'between_seasons', 'to' => 'bookable', 'reason' => 'offer_seen']], $result['changes']);

        $sql = implode("\n", $this->sql);
        self::assertStringContainsString("SET last_booking_check_in = '2025-11-10'", $sql, 'the booked stay is carried over');
        self::assertStringContainsString("hotel_id = '4'", $sql);
        self::assertStringContainsString("state = 'left_feed', state_reason = 'left_feed'", $sql);
        self::assertStringContainsString("state = 'bookable', state_reason = 'offer_seen'", $sql);
        self::assertStringNotContainsString("hotel_id IN ('5')", $sql, 'an unchanged hotel is not rewritten');
        self::assertStringContainsString("('novoton', '3', 0, 'no_data', 'left_feed', 'left_feed', '2026-10-30 14:05:00')", $sql);
        self::assertStringContainsString("('novoton', '4', 0, 'between_seasons', 'bookable', 'offer_seen', '2026-10-30 14:05:00')", $sql);
    }

    public function testASupplierSwitchedOffIsNotReviewed(): void
    {
        $summary = (new AvailabilityReview())->review(
            ['novoton' => self::source()],
            ['novoton' => AvailabilityMode::Off],
            new AvailabilityThresholds(),
            new \DateTimeImmutable('2026-10-30 14:05:00'),
        );

        self::assertSame([], $summary['providers']);
        self::assertSame([], $this->sql);
    }

    /** @return list<string> the product status writes so far */
    private function productWrites(): array
    {
        return array_values(array_filter($this->sql, static fn (string $q): bool => str_starts_with(trim($q), 'UPDATE ?:products')));
    }

    /** The gate changes products only in Automatic mode, and only for a supplier it acts for. */
    public function testTheGateActsInAutomaticModeAndCountsInReportOnly(): void
    {
        DbStub::$getArray = static fn (): array => [];
        DbStub::$getFields = static fn (string $query): array => str_contains($query, "p.status = 'A'") ? [15, 16] : [];
        DbStub::$getField = static fn (string $query): string => str_contains($query, 'COUNT(DISTINCT') ? '400' : '';
        $review = new AvailabilityReview();
        $at = new \DateTimeImmutable('2027-02-15 06:00:00');

        $report = $review->review(['novoton' => self::source(true)], ['novoton' => AvailabilityMode::Report], new AvailabilityThresholds(), $at);
        self::assertSame('report', $report['providers']['novoton']['mode']);
        self::assertSame(['hidden' => 0, 'shown' => 0, 'released' => 0, 'would_hide' => 2, 'braked' => 0], $report['providers']['novoton']['gate']);
        self::assertSame([], $this->productWrites());

        $automatic = $review->review(['novoton' => self::source(true)], ['novoton' => AvailabilityMode::Automatic], new AvailabilityThresholds(), $at);
        self::assertSame('automatic', $automatic['providers']['novoton']['mode']);
        self::assertSame(["UPDATE ?:products SET status = 'H' WHERE product_id IN (15,16) AND status = 'A'"], $this->productWrites());

        $this->sql = [];
        $ownGate = $review->review(['novoton' => self::source(false)], ['novoton' => AvailabilityMode::Automatic], new AvailabilityThresholds(), $at);
        self::assertSame('report', $ownGate['providers']['novoton']['mode'], 'a supplier with a gate of its own stays Report only');
        self::assertSame([], $this->productWrites());
    }

    /** On the days between reviews, a hotel the gate hid whose offers are back is shown the next morning; nothing is hidden. */
    public function testTheDailyRunShowsHiddenHotelsWhoseOffersAreBack(): void
    {
        TravelProviderRegistry::register('novoton', 'Novoton', $this->createStub(\Tygh\Addons\TravelCore\Contracts\ProviderNormalizerInterface::class));
        TravelProviderRegistry::setAvailabilitySource('novoton', self::source(true));
        \Tygh\Registry::set('addons.travel_core.availability_mode_novoton', 'automatic');
        self::runLog(['travel_core.hotel_availability' => ['started' => self::NOW - 86400, 'finished' => self::NOW - 86000, 'ok' => true]]);
        DbStub::$getArray = static fn (string $query): array => str_contains($query, 'SELECT * FROM ?:travel_hotel_availability') ? [
            ['provider' => 'novoton', 'hotel_id' => '8', 'product_id' => '8', 'offer_seen_at' => '2027-02-15 10:00:00', 'state' => 'dormant', 'state_since' => '2027-02-01 06:00:00', 'state_reason' => 'no_availability', 'season_published' => 'N', 'gate_hidden' => 'Y', 'created_at' => '2025-01-01 00:00:00'],
            ['provider' => 'novoton', 'hotel_id' => '9', 'product_id' => '9', 'state' => 'dormant', 'state_since' => '2027-02-01 06:00:00', 'state_reason' => 'no_availability', 'season_published' => 'N', 'gate_hidden' => 'N', 'created_at' => '2025-01-01 00:00:00'],
        ] : [];
        $asked = [];
        DbStub::$getFields = static function (string $query) use (&$asked): array {
            $asked[] = $query;

            return str_contains($query, "p.status = 'H'") ? [8] : [];
        };

        try {
            $line = AvailabilityReview::runDaily((new \DateTimeImmutable())->setTimestamp(self::NOW));
        } finally {
            \Tygh\Registry::set('addons.travel_core.availability_mode_novoton', null);
        }

        self::assertStringContainsString('not due yet (weekly). 1 hidden hotels shown again', $line);
        $sql = implode("\n", $this->sql);
        self::assertStringContainsString("state = 'bookable', state_reason = 'offer_seen'", $sql, 'the hidden hotel is classified again');
        self::assertStringNotContainsString("hotel_id IN ('9')", $sql, 'the others wait for the weekly review');
        self::assertSame(["UPDATE ?:products SET status = 'A' WHERE product_id IN (8) AND status = 'H'"], $this->productWrites());
        self::assertSame([], array_filter($asked, static fn (string $q): bool => str_contains($q, "p.status = 'A'")), 'nothing is hidden between reviews');
    }

    public function testTheDailyRunSaysWhyItDidNotReview(): void
    {
        self::assertStringContainsString('no supplier records availability', AvailabilityReview::runDaily());

        TravelProviderRegistry::register('novoton', 'Novoton', $this->createStub(\Tygh\Addons\TravelCore\Contracts\ProviderNormalizerInterface::class));
        TravelProviderRegistry::setAvailabilitySource('novoton', self::source());
        self::runLog(['travel_core.hotel_availability' => ['started' => self::NOW - 86400, 'finished' => self::NOW - 86000, 'ok' => true]]);

        $now = (new \DateTimeImmutable())->setTimestamp(self::NOW);
        self::assertStringContainsString('not due yet', AvailabilityReview::runDaily($now));

        // Review now: due or not, but not while Novoton's hotel list runs; the
        // run log above has no review in it, so the daily run is due and waits too.
        $dispatcher = new class () implements CronDispatcherInterface {
            #[\Override]
            public function hasMode(string $mode): bool
            {
                return true;
            }

            #[\Override]
            public function dispatch(string $mode, array $params = []): array
            {
                return [];
            }

            #[\Override]
            public static function getAvailableModes(): array
            {
                return [];
            }
        };
        TravelProviderRegistry::setCron('novoton', 'novoton_holidays', $dispatcher::class, 'novoton_holidays.manage');
        self::runLog(['novoton_holidays.hotel_list' => ['started' => self::NOW - 600]]);
        $outcome = AvailabilityReview::run(true, $now);
        self::assertSame('busy', $outcome['status']);
        self::assertSame(['Novoton: hotel_list'], $outcome['busy']);
        self::assertStringContainsString('waits for Novoton: hotel_list', AvailabilityReview::runDaily($now));
    }
}
