<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Addons\NovotonHolidays\Repository\HotelPackageRepository;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Repository\HotelAvailabilityRepository;
use Tygh\Addons\TravelCore\Services\ListCompleteness;

/**
 * Writes what Novoton's existing jobs see into Travel Core's hotel
 * availability records (docs/novoton-availability-gaps-plan.md §4.2): no
 * extra API call, only what the hotel list, the calendar rebuild and
 * customers' searches already fetched.
 *
 * Recording must never break the job it rides on: a failed write is logged
 * and the job carries on.
 */
final class NovotonAvailabilityRecorder
{
    private const string PROVIDER = NovotonAvailabilitySource::PROVIDER;

    /** Hotel ids per IN (...) list. */
    private const int CHUNK = 1000;

    /** storage_data key of the backfill's cursor: the last hotel_id done, or BACKFILL_DONE. */
    public const string BACKFILL_KEY = 'novoton_availability_backfill';

    public const string BACKFILL_DONE = '~done';

    public function __construct(
        private readonly HotelAvailabilityRepository $records = new HotelAvailabilityRepository(),
    ) {
    }

    /**
     * One country's hotel_list answer, after its hotels were saved.
     *
     * Its hotels were seen. The country's other hotels missed one more list,
     * but only when the answer is complete (ListCompleteness): a short list
     * may be an outage, and an outage must never count as hotels gone.
     *
     * @param list<array<string, mixed>> $answer the answer's hotels as saved (hotel_id, country)
     *
     * @return array{complete: bool, seen: int, missing: int}
     */
    public function hotelList(string $country, array $answer, string $now): array
    {
        $result = ['complete' => false, 'seen' => 0, 'missing' => 0];

        return $this->guard('hotel_list ' . $country, $result, function () use ($country, $answer, $now): array {
            $answerIds = [];
            $countries = [$country => true];
            foreach ($answer as $hotel) {
                $id = TypeCoerce::toString($hotel['hotel_id'] ?? '');
                if ($id !== '') {
                    $answerIds[$id] = true;
                }
                $stored = TypeCoerce::toString($hotel['country'] ?? '');
                if ($stored !== '') {
                    $countries[$stored] = true;
                }
            }

            // Every hotel Novoton has listed in this country, the answer's included.
            $group = TypeCoerce::toRowList(db_get_array(
                'SELECT hotel_id, product_id, city, country, created_at FROM ?:novoton_hotels WHERE country IN (?a)',
                array_keys($countries),
            ));

            HotelAvailabilityRepository::ensureTables();
            $previous = 0;
            foreach (array_chunk(array_column($group, 'hotel_id'), self::CHUNK) as $ids) {
                $previous += TypeCoerce::toInt(db_get_field(
                    'SELECT COUNT(*) FROM ?:travel_hotel_availability
                     WHERE provider = ?s AND hotel_id IN (?a) AND listed_missing_runs = 0 AND listed_last_seen_at IS NOT NULL',
                    self::PROVIDER,
                    $ids,
                ));
            }
            $complete = ListCompleteness::complete(count($answerIds), $previous);

            $seen = [];
            $missing = [];
            foreach ($group as $row) {
                $id = TypeCoerce::toString($row['hotel_id'] ?? '');
                if (isset($answerIds[$id])) {
                    $seen[] = self::identity($row);
                } elseif ($id !== '') {
                    $missing[] = $id;
                }
            }
            $this->records->recordList(self::PROVIDER, $seen, $complete ? $missing : [], $now);

            return ['complete' => $complete, 'seen' => count($seen), 'missing' => $complete ? count($missing) : 0];
        });
    }

    /**
     * The calendar was rebuilt: its first and last priced date are the
     * published price window (both null when nothing is priced any more).
     *
     * @param array<string, mixed> $rawPrices date (Y-m-d) => price
     */
    public function calendarComputed(string $hotelId, array $rawPrices): void
    {
        $this->guard('calendar ' . $hotelId, null, function () use ($hotelId, $rawPrices): null {
            $dates = array_filter(array_map('strval', array_keys($rawPrices)), static fn (string $d): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1);
            $this->records->recordPriceWindow(
                self::PROVIDER,
                $hotelId,
                $dates === [] ? null : min($dates),
                $dates === [] ? null : max($dates),
            );

            return null;
        });
    }

    /**
     * A customer's search found offers for this hotel and stay. Positives
     * only: an empty answer for one occupancy proves nothing about the hotel.
     *
     * @param list<array<string, mixed>> $results the search's room offers
     */
    public function searchFound(string $hotelId, string $checkIn, array $results, string $now): void
    {
        if ($results === [] || $hotelId === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkIn) !== 1) {
            return;
        }
        $this->guard('search ' . $hotelId, null, function () use ($hotelId, $checkIn, $results, $now): null {
            $immediate = false;
            foreach ($results as $offer) {
                if (array_key_exists('is_on_request', $offer) && !TypeCoerce::toBool($offer['is_on_request'])) {
                    $immediate = true;
                    break;
                }
            }
            $this->records->recordOfferSeen(self::PROVIDER, $hotelId, $checkIn, $immediate, $now);

            return null;
        });
    }

    /**
     * Fills in past seasons once (plan §4.4), so "no availability for N
     * months" can be judged from the first run: the latest date any stored
     * season priced (past seasons included: package rows are never pruned),
     * the latest confirmed stay, the last hotel_list that had the hotel, and
     * when Novoton first listed it. Stored data only, no API call.
     *
     * One batch of hotels per call, in hotel_id order from where the last
     * call stopped (a cursor in storage_data), until every hotel is done.
     *
     * @return array{hotels: int, done: bool}
     */
    public function backfill(int $batch, string $now): array
    {
        $cursor = self::stored(self::BACKFILL_KEY);
        if ($cursor === self::BACKFILL_DONE) {
            return ['hotels' => 0, 'done' => true];
        }

        return $this->guard('backfill', ['hotels' => 0, 'done' => false], function () use ($cursor, $batch, $now): array {
            $hotels = TypeCoerce::toRowList(db_get_array(
                'SELECT hotel_id, product_id, city, country, created_at, hotel_list_synced_at FROM ?:novoton_hotels
                 WHERE hotel_id > ?s ORDER BY hotel_id LIMIT ?i',
                $cursor,
                max(1, $batch),
            ));
            if ($hotels === []) {
                self::store(self::BACKFILL_KEY, self::BACKFILL_DONE);

                return ['hotels' => 0, 'done' => true];
            }
            $ids = array_map(static fn (array $h): string => TypeCoerce::toString($h['hotel_id'] ?? ''), $hotels);

            $lastPriced = [];
            $builder = new CalendarPriceBuilder(new HotelPackageRepository());
            foreach (TypeCoerce::toRowList(db_get_array(
                'SELECT hotel_id, priceinfo_data FROM ?:novoton_hotel_packages WHERE hotel_id IN (?a)',
                $ids,
            )) as $package) {
                $priceinfo = json_decode(TypeCoerce::toString($package['priceinfo_data'] ?? ''), true);
                if (!is_array($priceinfo)) {
                    continue;
                }
                // Every priced date of every season, past ones too: the
                // calendar's today-clamp does not apply here.
                $dates = array_map('strval', array_keys($builder->buildRawDateMap($priceinfo, 2, '2000-01-01', '2100-12-31')));
                if ($dates !== []) {
                    $id = TypeCoerce::toString($package['hotel_id'] ?? '');
                    $lastPriced[$id] = max($lastPriced[$id] ?? '', max($dates));
                }
            }

            // Keyed by hotel id; numeric ids become int keys, which
            // toStringMap() would drop, so the map is rebuilt by hand.
            $stays = db_get_hash_single_array(
                "SELECT hotel_id, MAX(check_in) AS last_stay FROM ?:novoton_bookings
                 WHERE hotel_id IN (?a) AND status IN ('confirmed', 'completed') GROUP BY hotel_id",
                ['hotel_id', 'last_stay'],
                $ids,
            );
            $lastStay = [];
            foreach (is_array($stays) ? $stays : [] as $hotelId => $stay) {
                $lastStay[(string) $hotelId] = TypeCoerce::toString($stay);
            }

            $rows = [];
            foreach ($hotels as $hotel) {
                $identity = self::identity($hotel);
                $listed = TypeCoerce::toString($hotel['hotel_list_synced_at'] ?? '');
                $rows[] = $identity + [
                    'listed_last_seen_at' => preg_match('/^[1-9]\d{3}-/', $listed) === 1 ? $listed : null,
                    'contract_last_date' => $lastPriced[$identity['hotel_id']] ?? null,
                    'last_booking_check_in' => ($lastStay[$identity['hotel_id']] ?? '') !== '' ? $lastStay[$identity['hotel_id']] : null,
                ];
            }
            $this->records->recordBackfill(self::PROVIDER, $rows, $now);
            self::store(self::BACKFILL_KEY, (string) end($ids));

            return ['hotels' => count($rows), 'done' => false];
        });
    }

    /**
     * Who the hotel is in the records: its product, its resort (the peer
     * group) and when Novoton first listed it.
     *
     * @param array<string, mixed> $row a ?:novoton_hotels row
     *
     * @return array{hotel_id: string, product_id: int, destination_key: string, known_since: ?string}
     */
    public static function identity(array $row): array
    {
        $created = TypeCoerce::toString($row['created_at'] ?? '');

        return [
            'hotel_id' => TypeCoerce::toString($row['hotel_id'] ?? ''),
            'product_id' => max(0, TypeCoerce::toInt($row['product_id'] ?? 0)),
            'destination_key' => self::destinationKey(TypeCoerce::toString($row['country'] ?? ''), TypeCoerce::toString($row['city'] ?? '')),
            'known_since' => preg_match('/^\d{4}-\d{2}-\d{2}/', $created) === 1 && !str_starts_with($created, '0000') ? substr($created, 0, 10) : null,
        ];
    }

    /** "BULGARIA|Sunny Beach": the resort the live check asks about, within its country. */
    public static function destinationKey(string $country, string $city): string
    {
        $city = trim($city);

        return $city === '' ? '' : mb_substr(strtoupper(trim($country)) . '|' . $city, 0, 128);
    }

    private static function stored(string $key): string
    {
        return function_exists('fn_get_storage_data') ? TypeCoerce::toString(fn_get_storage_data($key)) : '';
    }

    private static function store(string $key, string $value): void
    {
        if (function_exists('fn_set_storage_data')) {
            fn_set_storage_data($key, $value);
        }
    }

    /**
     * Runs one recording step; a failure is logged and $fallback returned.
     *
     * @template T
     *
     * @param T $fallback
     * @param \Closure(): T $step
     *
     * @return T
     */
    private function guard(string $what, mixed $fallback, \Closure $step): mixed
    {
        try {
            return $step();
        } catch (\Throwable $e) {
            if (function_exists('fn_log_event')) {
                fn_log_event('general', 'runtime', ['message' => 'Novoton: availability record (' . $what . ') failed: ' . $e->getMessage()]);
            }

            return $fallback;
        }
    }
}
