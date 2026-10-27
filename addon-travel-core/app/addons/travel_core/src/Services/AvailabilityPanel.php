<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Services;

use Tygh\Addons\TravelCore\Enums\AvailabilityMode;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * What a hotel page says about a hotel that cannot be booked now
 * (docs/novoton-availability-gaps-plan.md §7–8), for every supplier, from
 * the availability records the weekly review classified: no supplier call.
 *
 *  - between seasons, next season not published: "prices for the next
 *    season are not published yet", last season's months, "notify me when
 *    prices appear", similar hotels;
 *  - between seasons, published from a later date: from when;
 *  - not offered: "online booking is not available at the moment", "notify
 *    me when it can be booked", similar hotels;
 *  - no offers for over a year: the same, "notify me if it returns";
 *  - left the supplier's list or closed: "no longer available", similar
 *    hotels.
 *
 * A bookable hotel, one without records yet, and every hotel of a supplier
 * whose availability mode is Off get no panel; so does every hotel while the
 * admin has not switched the panels on (they are off by default, until the
 * records have been checked on the live store). The page template draws it.
 */
final class AvailabilityPanel
{
    /** The consent text's version, stored with each "notify me" request (decided 2026-10-17, plan §8). */
    public const string CONSENT_VERSION = '2026-10-17';

    /** Similar hotels a panel shows at most. */
    public const int SIMILAR = 3;

    /** Bookable hotels of the destination looked at for them, the latest offers first. */
    private const int CANDIDATES = 12;

    /** Months back a past stay month counts as last season's. */
    private const int LAST_SEASON_MONTHS = 13;

    /** @var \Closure(int, string): ?int */
    private readonly \Closure $stars;

    /** @var \Closure(string): AvailabilityMode */
    private readonly \Closure $mode;

    /**
     * @param (\Closure(int, string): ?int)|null $stars (product id, product code) => the hotel's stars, from its supplier
     * @param (\Closure(string): AvailabilityMode)|null $mode a supplier's availability mode
     */
    public function __construct(?\Closure $stars = null, ?\Closure $mode = null)
    {
        $this->stars = $stars ?? static fn (int $productId, string $code): ?int => TravelProviderRegistry::resolveProductOwner($productId, $code)?->classification;
        $this->mode = $mode ?? static fn (string $provider): AvailabilityMode => TravelCoreConfig::getAvailabilityMode($provider);
    }

    /**
     * The panel for a product, or null when it gets none. Never throws: a
     * page shows without the panel rather than not at all.
     *
     * @return array{provider: string, hotel_id: string, hotel_name: string, state: string, message: string, notify: string, last_season: array{from: string, to: string}|null, published_from: string, similar: list<array{product_id: int, hotel_name: string, stars: int|null}>}|null
     */
    public function forProduct(int $productId, \DateTimeImmutable $today): ?array
    {
        if ($productId <= 0 || !TravelCoreConfig::availabilityPanelsShown()) {
            return null;
        }
        try {
            return $this->build($productId, $today->setTime(0, 0));
        } catch (\Throwable $e) {
            fn_log_event('general', 'runtime', ['message' => '[TravelAvailability] panel for product ' . $productId . ': ' . $e->getMessage()]);

            return null;
        }
    }

    /**
     * The panel, whatever the switch says (the admin's preview and the tests).
     *
     * @return array{provider: string, hotel_id: string, hotel_name: string, state: string, message: string, notify: string, last_season: array{from: string, to: string}|null, published_from: string, similar: list<array{product_id: int, hotel_name: string, stars: int|null}>}|null
     */
    public function build(int $productId, \DateTimeImmutable $today): ?array
    {
        $record = TypeCoerce::toStringMap(db_get_row(
            'SELECT provider, hotel_id, product_id, destination_key, hotel_name, state, season_published, contract_from, offer_months
             FROM ?:travel_hotel_availability WHERE product_id = ?i ORDER BY updated_at DESC LIMIT 1',
            $productId,
        ));
        if ($record === []) {
            return null;
        }
        $provider = TypeCoerce::toString($record['provider'] ?? '');
        if (($this->mode)($provider) === AvailabilityMode::Off) {
            return null;
        }

        $state = TypeCoerce::toString($record['state'] ?? '');
        $contractFrom = TypeCoerce::toString($record['contract_from'] ?? '');
        $published = TypeCoerce::toString($record['season_published'] ?? '') === 'Y';
        [$message, $notify] = match (true) {
            $state === 'between_seasons' && !$published => ['travel_core.availability_panel_between_seasons', 'prices'],
            $state === 'between_seasons' && $contractFrom > $today->format('Y-m-d') => ['travel_core.availability_panel_published_from', ''],
            $state === 'not_offered' => ['travel_core.availability_panel_not_offered', 'booking'],
            $state === 'dormant' => ['travel_core.availability_panel_dormant', 'return'],
            $state === 'left_feed', $state === 'closed' => ['travel_core.availability_panel_unavailable', ''],
            default => ['', ''],
        };
        if ($message === '') {
            return null;
        }

        return [
            'provider' => $provider,
            'hotel_id' => TypeCoerce::toString($record['hotel_id'] ?? ''),
            'hotel_name' => TypeCoerce::toString($record['hotel_name'] ?? ''),
            'state' => $state,
            'message' => $message,
            'notify' => $notify,
            'last_season' => $notify === 'prices' ? self::lastSeason(TypeCoerce::toString($record['offer_months'] ?? ''), $today) : null,
            'published_from' => $message === 'travel_core.availability_panel_published_from' ? $contractFrom : '',
            'similar' => $message === 'travel_core.availability_panel_published_from'
                ? []
                : $this->similar($provider, TypeCoerce::toString($record['destination_key'] ?? ''), $productId),
        ];
    }

    /**
     * Last season's first and last stay month (Y-m): the latest run of
     * months the records saw offers for, in the 13 months before this one.
     * One month without offers stays inside a season, two end it. Null when
     * there is none.
     *
     * @return array{from: string, to: string}|null
     */
    public static function lastSeason(string $offerMonths, \DateTimeImmutable $today): ?array
    {
        $months = json_decode($offerMonths, true);
        if (!is_array($months)) {
            return null;
        }
        $month = $today->modify('first day of this month')->setTime(0, 0);
        $thisMonth = $month->format('Y-m');
        $since = $month->modify('-' . self::LAST_SEASON_MONTHS . ' months')->format('Y-m');
        $past = array_flip(array_filter(
            array_map('strval', array_keys($months)),
            static fn (string $m): bool => preg_match('/^\d{4}-\d{2}$/', $m) === 1 && $m < $thisMonth && $m >= $since,
        ));
        if ($past === []) {
            return null;
        }
        $keys = array_map('strval', array_keys($past));
        $to = max($keys);
        $from = $to;
        $cursor = new \DateTimeImmutable($to . '-01');
        $gap = 0;
        while (($m = ($cursor = $cursor->modify('-1 month'))->format('Y-m')) >= $since) {
            if (isset($past[$m])) {
                $from = $m;
                $gap = 0;
            } elseif (++$gap > 1) {
                break;
            }
        }

        return ['from' => $from, 'to' => $to];
    }

    /**
     * Up to SIMILAR hotels of the same supplier and destination that can be
     * booked now, with an active product, at most a star apart when both
     * star ratings are known; the latest offers first.
     *
     * @return list<array{product_id: int, hotel_name: string, stars: int|null}>
     */
    private function similar(string $provider, string $destinationKey, int $productId): array
    {
        if ($destinationKey === '') {
            return [];
        }
        $candidates = TypeCoerce::toRowList(db_get_array(
            "SELECT a.product_id, a.hotel_name, p.product_code
             FROM ?:travel_hotel_availability a
             INNER JOIN ?:products p ON p.product_id = a.product_id AND p.status = 'A'
             WHERE a.provider = ?s AND a.destination_key = ?s AND a.state = 'bookable' AND a.product_id > 0 AND a.product_id <> ?i
             ORDER BY a.offer_seen_at DESC LIMIT ?i",
            $provider,
            $destinationKey,
            $productId,
            self::CANDIDATES,
        ));
        if ($candidates === []) {
            return [];
        }
        $code = TypeCoerce::toString(db_get_field('SELECT product_code FROM ?:products WHERE product_id = ?i', $productId));
        $stars = ($this->stars)($productId, $code);

        $near = [];
        $unrated = [];
        foreach ($candidates as $row) {
            $id = TypeCoerce::toInt($row['product_id'] ?? 0);
            $theirs = ($this->stars)($id, TypeCoerce::toString($row['product_code'] ?? ''));
            $hotel = ['product_id' => $id, 'hotel_name' => TypeCoerce::toString($row['hotel_name'] ?? ''), 'stars' => $theirs];
            if ($stars === null || $theirs === null) {
                $unrated[] = $hotel;
            } elseif (abs($theirs - $stars) <= 1) {
                $near[] = $hotel;
            }
        }

        return array_slice([...$near, ...$unrated], 0, self::SIMILAR);
    }
}
