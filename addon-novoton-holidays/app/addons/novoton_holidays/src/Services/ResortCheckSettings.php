<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Services\AvailabilityWindows;

/**
 * How the weekly availability check (`room_price`) asks Novoton: which
 * dates, how often each resort, and how much one run may spend
 * (docs/novoton-availability-gaps-plan.md §4.3).
 *
 * Advanced settings, so Novoton's answer on how often it may be called
 * changes a setting, not code. A run's URL can set the budgets for that run
 * only (max_calls, max_time, resorts, nights).
 */
final class ResortCheckSettings
{
    public const string DEFAULT_NEAR_DAYS = '14, 30';

    public const string DEFAULT_SEASON_DATES = '06-20, 07-15, 08-15, 09-05';

    public const int DEFAULT_CYCLE_DAYS = 7;

    /** About twice what a weekly pass of the sold resorts needs (§4.3: about 60 a night). */
    public const int DEFAULT_MAX_CALLS = 120;

    /** Ends a run started at 04:00 before add_hotels_as_products at 04:30. */
    public const int DEFAULT_MAX_MINUTES = 20;

    public const int NIGHTS = 7;

    /**
     * @param list<int> $nearDays
     * @param int $units how many units this run takes; 0 = its share of the cycle
     */
    public function __construct(
        public readonly array $nearDays,
        public readonly string $seasonDates,
        public readonly int $nights = self::NIGHTS,
        public readonly int $cycleDays = self::DEFAULT_CYCLE_DAYS,
        public readonly int $maxCalls = self::DEFAULT_MAX_CALLS,
        public readonly int $maxSeconds = self::DEFAULT_MAX_MINUTES * 60,
        public readonly int $units = 0,
    ) {
    }

    /**
     * An empty number setting takes its default; empty dates mean none.
     *
     * @param array<string, mixed> $settings the add-on's settings
     * @param array<string, mixed> $params the run's URL parameters
     */
    public static function from(array $settings, array $params = []): self
    {
        $setting = static fn (string $name, int $default): int => TypeCoerce::toString($settings[$name] ?? '') !== ''
            ? TypeCoerce::toInt($settings[$name]) : $default;
        $param = static fn (string $name, int $fallback): int => TypeCoerce::toString($params[$name] ?? '') !== ''
            ? TypeCoerce::toInt($params[$name]) : $fallback;
        $resorts = TypeCoerce::toString($params['resorts'] ?? '');

        return new self(
            nearDays: AvailabilityWindows::parseDays(TypeCoerce::toString($settings['availability_check_near_days'] ?? self::DEFAULT_NEAR_DAYS)),
            seasonDates: TypeCoerce::toString($settings['availability_check_season_dates'] ?? self::DEFAULT_SEASON_DATES),
            nights: max(1, min(21, $param('nights', self::NIGHTS))),
            cycleDays: max(1, min(31, $setting('availability_check_cycle_days', self::DEFAULT_CYCLE_DAYS))),
            maxCalls: max(1, min(5000, $param('max_calls', $setting('availability_check_max_calls', self::DEFAULT_MAX_CALLS)))),
            maxSeconds: 60 * max(1, min(240, $param('max_time', $setting('availability_check_max_minutes', self::DEFAULT_MAX_MINUTES)))),
            units: $resorts === 'all' ? PHP_INT_MAX : max(0, TypeCoerce::toInt($resorts)),
        );
    }

    /**
     * The stays every unit is asked about.
     *
     * @return list<array{kind: string, check_in: string, check_out: string}>
     */
    public function windows(\DateTimeImmutable $today): array
    {
        return AvailabilityWindows::windows($today, $this->nearDays, $this->seasonDates, $this->nights);
    }
}
