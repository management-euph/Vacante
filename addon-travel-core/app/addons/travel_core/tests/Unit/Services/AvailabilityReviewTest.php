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

    private static function source(): HotelAvailabilitySourceInterface
    {
        return new class () implements HotelAvailabilitySourceInterface {
            #[\Override]
            public function provider(): string
            {
                return 'novoton';
            }

            #[\Override]
            public function capabilities(): AvailabilityCapabilities
            {
                return new AvailabilityCapabilities(HotelListCoverage::PerCountry, true, 'resort', false, true, true);
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
