<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Services;

use Tygh\Addons\Eurosite\Dto\HotelOffer;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Repository\HotelAvailabilityRepository;
use Tygh\Addons\TravelCore\Services\ListCompleteness;
use Tygh\Addons\TravelCore\Services\OfferTally;

/**
 * Writes what Eurosite's existing jobs see into Travel Core's hotel
 * availability records (docs/novoton-availability-gaps-plan.md §15.3): no
 * extra API call, only what the hotel list, the nightly availability check
 * and customers' city searches already fetched.
 *
 * A hotel's id in the records is its Eurosite hotel code, as in the bookings
 * Eurosite mirrors to Travel Core; its destination is its city, the unit one
 * check answers for. Recording must never break the job it rides on: a
 * failed write is logged and the job carries on.
 */
final class EurositeAvailabilityRecorder
{
    private const string PROVIDER = EurositeAvailabilitySource::PROVIDER;

    /** Hotel codes per IN (...) list. */
    private const int CHUNK = 1000;

    public function __construct(
        private readonly HotelAvailabilityRepository $records = new HotelAvailabilityRepository(),
    ) {
    }

    /**
     * Folds one offer into a tally, when it counts as availability:
     * Immediate can be booked at once, On request counts too (plan §15.6,
     * decision 1), a Stop sale does not (§15.3, point 4). An offer without a
     * code is read as On request, as AvailabilityPlan::merge() reads it.
     */
    public static function addOffer(OfferTally $tally, HotelOffer $offer, string $checkIn): void
    {
        $code = $offer->availabilityCode !== '' ? $offer->availabilityCode : 'OR';
        if ($code === 'IM' || $code === 'OR') {
            $tally->add(trim($offer->productCode), $checkIn, $code === 'IM');
        }
    }

    /**
     * One city's own-hotels answer, after its hotels were saved.
     *
     * Its hotels were seen. The city's other hotels missed one more list,
     * but only when the answer is complete (ListCompleteness: not empty, at
     * least 90% of the city's hotels, or one fewer): Eurosite answers a city
     * it lists nothing for with an empty list, and that, or a short list, may
     * be an outage, which must never count as hotels gone.
     *
     * @param list<array<string, mixed>> $answer the getOwnHotels() rows (code)
     *
     * @return array{complete: bool, seen: int, missing: int}
     */
    public function hotelList(string $cityCode, array $answer, string $now): array
    {
        $result = ['complete' => false, 'seen' => 0, 'missing' => 0];

        return $this->guard('hotels ' . $cityCode, $result, function () use ($cityCode, $answer, $now): array {
            $answerCodes = [];
            foreach ($answer as $hotel) {
                $code = trim(TypeCoerce::toString($hotel['code'] ?? ''));
                if ($code !== '') {
                    $answerCodes[$code] = true;
                }
            }

            // Every hotel this city ever listed, the answer's included.
            $group = $this->cityHotels($cityCode, '', false);

            $previous = $this->records->listedCount(self::PROVIDER, array_column($group, 'hotel_id'), $now);
            $complete = ListCompleteness::complete(count($answerCodes), $previous);

            $seen = [];
            $missing = [];
            foreach ($group as $hotel) {
                if (isset($answerCodes[$hotel['hotel_id']])) {
                    // Eurosite says nothing of when it first listed a hotel:
                    // the record's start stands in for it.
                    $seen[] = $hotel + ['known_since' => null];
                } else {
                    $missing[] = $hotel['hotel_id'];
                }
            }
            $this->records->recordList(self::PROVIDER, $seen, $complete ? $missing : [], $now);

            return ['complete' => $complete, 'seen' => count($seen), 'missing' => $complete ? count($missing) : 0];
        });
    }

    /**
     * The nightly availability check's answers, city by city.
     *
     * A city where every date answered checked all its listed hotels: those
     * without an offer were checked too. Where only some dates answered,
     * only the offers found are recorded, since a date that did not answer
     * may have held one (§15.3, point 5). And a run in which no hotel
     * anywhere had an offer counts as no answer at all: an account with no
     * contracts connected answers every search with nothing, and that must
     * not read as every hotel checked without an offer.
     *
     * @param list<array{tourop: string, city: string, offers: OfferTally, complete: bool}> $checks
     *
     * @return array{recorded: bool, cities: int, hotels: int}
     */
    public function runChecked(array $checks, string $now): array
    {
        $result = ['recorded' => false, 'cities' => 0, 'hotels' => 0];
        $offered = 0;
        foreach ($checks as $check) {
            $offered += $check['offers']->count();
        }
        if ($offered === 0) {
            return $result;
        }

        return $this->guard('availability', $result, function () use ($checks, $now): array {
            $result = ['recorded' => true, 'cities' => 0, 'hotels' => 0];
            foreach ($checks as $check) {
                $hotels = $this->cityHotels($check['city'], $check['tourop'], true);
                $offers = $check['offers']->offers();
                if ($check['complete']) {
                    $this->records->recordCheck(self::PROVIDER, $hotels, $offers, $now);
                } else {
                    $this->records->recordOffersSeen(self::PROVIDER, $hotels, $offers, $now);
                }
                $result['cities']++;
                $result['hotels'] += count($hotels);
            }

            return $result;
        });
    }

    /**
     * A customer's search for a city found these offers. Positives only: an
     * empty answer for one stay and occupancy proves nothing about a hotel.
     * Only hotels this store lists are recorded.
     *
     * @param list<HotelOffer> $offers
     */
    public function searchFound(array $offers, string $checkIn, string $now): void
    {
        if ($offers === [] || preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkIn) !== 1) {
            return;
        }
        $this->guard('search', null, function () use ($offers, $checkIn, $now): null {
            $tally = new OfferTally();
            foreach ($offers as $offer) {
                self::addOffer($tally, $offer, $checkIn);
            }
            $found = $tally->offers();
            if ($found === []) {
                return null;
            }
            $hotels = [];
            foreach (array_chunk(array_map('strval', array_keys($found)), self::CHUNK) as $codes) {
                $hotels = [...$hotels, ...$this->hotelsByCode($codes)];
            }
            $this->records->recordOffersSeen(self::PROVIDER, $hotels, $found, $now);

            return null;
        });
    }

    /**
     * "RO|Mamaia (RO0363)": the city a hotel's check answers for, readable,
     * and unique by its code; the code alone while the city's name is unknown.
     */
    public static function destinationKey(string $countryCode, string $cityName, string $cityCode): string
    {
        $country = strtoupper(trim($countryCode)) . '|';
        $cityCode = trim($cityCode);
        $cityName = trim($cityName);
        if ($cityName === '' || strcasecmp($cityName, $cityCode) === 0) {
            return mb_substr($country . $cityCode, 0, 128);
        }
        $room = max(0, 128 - mb_strlen($country) - mb_strlen($cityCode) - 3);

        return $country . mb_substr($cityName, 0, $room) . ' (' . $cityCode . ')';
    }

    /**
     * A city's hotels as the records know them: every one, or only those the
     * availability check covers (listed now, of one tour operator).
     *
     * @return list<array{hotel_id: string, product_id: int, destination_key: string, hotel_name: string}>
     */
    private function cityHotels(string $cityCode, string $touropCode, bool $listedOnly): array
    {
        $condition = db_quote('city_code = ?s', $cityCode);
        if ($touropCode !== '') {
            $condition .= db_quote(' AND tourop_code = ?s', $touropCode);
        }
        if ($listedOnly) {
            $condition .= " AND sync_status = 'active'";
        }

        return $this->identities(TypeCoerce::toRowList(db_get_array(
            'SELECT product_code, product_id, name, country_code, city_code FROM ?:eurosite_hotels WHERE ?p',
            $condition,
        )));
    }

    /**
     * @param list<string> $codes
     *
     * @return list<array{hotel_id: string, product_id: int, destination_key: string, hotel_name: string}>
     */
    private function hotelsByCode(array $codes): array
    {
        return $this->identities(TypeCoerce::toRowList(db_get_array(
            'SELECT product_code, product_id, name, country_code, city_code FROM ?:eurosite_hotels WHERE product_code IN (?a)',
            $codes,
        )));
    }

    /**
     * Who each hotel is in the records: its code, its product, its city
     * (the peer group) and its name. The city names are read once per city.
     *
     * @param list<array<string, mixed>> $rows ?:eurosite_hotels rows
     *
     * @return list<array{hotel_id: string, product_id: int, destination_key: string, hotel_name: string}>
     */
    private function identities(array $rows): array
    {
        $cityNames = [];
        $out = [];
        foreach ($rows as $row) {
            $code = trim(TypeCoerce::toString($row['product_code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $city = TypeCoerce::toString($row['city_code'] ?? '');
            $cityNames[$city] ??= TypeCoerce::toString(db_get_field('SELECT name FROM ?:eurosite_cities WHERE city_code = ?s', $city));
            $out[] = [
                'hotel_id' => $code,
                'product_id' => max(0, TypeCoerce::toInt($row['product_id'] ?? 0)),
                'destination_key' => self::destinationKey(TypeCoerce::toString($row['country_code'] ?? ''), $cityNames[$city], $city),
                'hotel_name' => mb_substr(trim(TypeCoerce::toString($row['name'] ?? '')), 0, 255),
            ];
        }

        return $out;
    }

    /**
     * Runs one recording step; a failure is logged and $fallback returned,
     * so the job it rides on carries on.
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
            fn_log_event('general', 'runtime', [
                'message' => '[EurositeAvailability] ' . $what . ': ' . $e->getMessage(),
            ]);

            return $fallback;
        }
    }
}
