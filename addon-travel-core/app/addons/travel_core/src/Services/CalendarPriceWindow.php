<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Services;

use Tygh\Addons\TravelCore\Enums\AvailabilityMode;
use Tygh\Addons\TravelCore\Repository\HotelAvailabilityRepository;

/**
 * The hotel page calendar's window (docs/novoton-availability-gaps-plan.md
 * §7.1): the stay dates the supplier has published prices for, from today
 * on. The calendar opens on its first date instead of this month and marks
 * the dates outside it; a search outside it stays possible.
 *
 * From the availability records, so no supplier call: only a hotel whose
 * prices were read (Novoton's calendar rebuild records the window), of a
 * supplier not set to Off, while the availability panels are switched on.
 */
final class CalendarPriceWindow
{
    /** @var \Closure(string, string): (array{from: ?string, to: ?string, next: ?string}|null) */
    private readonly \Closure $window;

    /** @var \Closure(string): AvailabilityMode */
    private readonly \Closure $mode;

    /**
     * @param (\Closure(string, string): (array{from: ?string, to: ?string, next: ?string}|null))|null $window (supplier, hotel id) => its price window in the records, null when none is known
     * @param (\Closure(string): AvailabilityMode)|null $mode a supplier's availability mode
     */
    public function __construct(?\Closure $window = null, ?\Closure $mode = null)
    {
        $this->window = $window ?? static fn (string $provider, string $hotelId): ?array => (new HotelAvailabilityRepository())->priceWindows($provider, [$hotelId])[$hotelId] ?? null;
        $this->mode = $mode ?? static fn (string $provider): AvailabilityMode => TravelCoreConfig::getAvailabilityMode($provider);
    }

    /**
     * The first and last stay date with a published price (Y-m-d), the first
     * no earlier than today; null when there is none to show. Never throws:
     * the calendar works without it.
     *
     * @return array{from: string, to: string}|null
     */
    public function forHotel(string $provider, string $hotelId, \DateTimeImmutable $today): ?array
    {
        if ($provider === '' || $hotelId === '' || !TravelCoreConfig::availabilityPanelsShown()) {
            return null;
        }
        try {
            if (($this->mode)($provider) === AvailabilityMode::Off) {
                return null;
            }
            $window = ($this->window)($provider, $hotelId);
        } catch (\Throwable) {
            return null;
        }
        $day = $today->format('Y-m-d');
        $to = $window['to'] ?? null;
        if ($to === null || $to < $day) {
            return null;
        }
        $from = max($window['from'] ?? $day, $day);

        return $from <= $to ? ['from' => $from, 'to' => $to] : null;
    }
}
