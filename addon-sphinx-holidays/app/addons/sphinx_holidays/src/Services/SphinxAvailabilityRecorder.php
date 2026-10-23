<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Services;

use Tygh\Addons\SphinxHolidays\Helpers\OfferAvailability;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Repository\HotelAvailabilityRepository;
use Tygh\Addons\TravelCore\Services\ListCompleteness;
use Tygh\Addons\TravelCore\Services\OfferTally;

/**
 * Writes what Sphinx's existing jobs see into Travel Core's hotel
 * availability records (docs/novoton-availability-gaps-plan.md §15.3): no
 * extra API call, only what the hotel sync, its availability gate and
 * customers' searches already fetched.
 *
 * A hotel's id in the records is its Sphinx hotel id, as in the bookings
 * Sphinx mirrors to Travel Core; its destination is the destination its
 * searches answer for. Recording must never break the job it rides on: a
 * failed write is logged and the job carries on.
 */
final class SphinxAvailabilityRecorder
{
    private const string PROVIDER = SphinxAvailabilitySource::PROVIDER;

    /** Hotel ids per IN (...) list. */
    private const int CHUNK = 1000;

    public function __construct(
        private readonly HotelAvailabilityRepository $records = new HotelAvailabilityRepository(),
    ) {
    }

    /**
     * Folds a search's offers into a tally. Every offer counts, on request
     * too (plan §15.6, decision 1); it could be booked at once when its
     * confirmation says immediate.
     *
     * @param list<array<string, mixed>> $results the search's offers, one per row
     */
    public static function addOffers(OfferTally $tally, array $results, string $checkIn): void
    {
        foreach ($results as $offer) {
            $tally->add(TypeCoerce::toString($offer['hotel_id'] ?? $offer['id'] ?? ''), $checkIn, OfferAvailability::isImmediate($offer));
        }
    }

    /**
     * One country's hotel sync, after its hotels were saved.
     *
     * The hotels it brought were seen: even the daily sync, which brings
     * only the hotels changed since the last one, proves those are listed.
     * The country's other hotels in the destinations it asked for missed one
     * more list only after a full sync that came back whole (every chunk
     * paged to its end), nearly as long as the last (ListCompleteness):
     * Sphinx lists every hotel only then (§15.1).
     *
     * @param list<int> $destinationIds the destinations the sync asked for
     * @param list<string> $seenIds the hotels it saved
     *
     * @return array{complete: bool, seen: int, missing: int}
     */
    public function hotelsListed(string $countryCode, array $destinationIds, array $seenIds, bool $wholeFullList, string $now): array
    {
        $result = ['complete' => false, 'seen' => 0, 'missing' => 0];
        $seenIds = array_values(array_unique($seenIds));
        if ($seenIds === []) {
            return $result;
        }

        return $this->guard('hotels ' . $countryCode, $result, function () use ($countryCode, $destinationIds, $seenIds, $wholeFullList, $now): array {
            $seen = array_map(
                static fn (array $hotel): array => $hotel['identity'] + ['known_since' => $hotel['known_since']],
                $this->hotelsById($seenIds),
            );

            $complete = false;
            $missing = [];
            if ($wholeFullList && $destinationIds !== []) {
                $group = array_map('strval', TypeCoerce::toStringList(db_get_fields(
                    'SELECT hotel_id FROM ?:sphinx_hotels WHERE country_code = ?s AND destination_id IN (?n)',
                    $countryCode,
                    $destinationIds,
                )));
                $previous = $this->records->listedCount(self::PROVIDER, $group, $now);
                $complete = ListCompleteness::complete(count($seenIds), $previous);
                if ($complete) {
                    $missing = array_values(array_diff($group, $seenIds));
                }
            }
            $this->records->recordList(self::PROVIDER, $seen, $missing, $now);

            return ['complete' => $complete, 'seen' => count($seen), 'missing' => count($missing)];
        });
    }

    /**
     * The availability gate's searches for one country, destination by
     * destination.
     *
     * A destination whose every search was polled to its end checked all the
     * hotels the gate decides there: those without an offer were checked too.
     * Its other hotels, and every hotel of a destination that did not answer
     * in full, count only for the offers found (§15.3, point 5). And a country
     * in which no hotel anywhere had an offer counts as no answer at all: the
     * Sphinx account with no supplier connected answered every search with
     * nothing (docs/sphinx-no-availability-report.md).
     *
     * @param array<int, array<array-key, true>> $candidatesByDest destination => the hotels the gate decides there
     * @param array<int, true> $answered destinations whose every search was polled to its end
     * @param array<int, OfferTally> $offersByDest destination => the offers its searches found
     *
     * @return array{recorded: bool, destinations: int, hotels: int}
     */
    public function gateChecked(array $candidatesByDest, array $answered, array $offersByDest, string $now): array
    {
        $result = ['recorded' => false, 'destinations' => 0, 'hotels' => 0];
        $offered = 0;
        foreach ($offersByDest as $tally) {
            $offered += $tally->count();
        }
        if ($offered === 0) {
            return $result;
        }

        return $this->guard('gate', $result, function () use ($candidatesByDest, $answered, $offersByDest, $now): array {
            $result = ['recorded' => true, 'destinations' => 0, 'hotels' => 0];
            foreach ($offersByDest as $destId => $tally) {
                $offers = $tally->offers();
                $candidates = $candidatesByDest[$destId] ?? [];
                $ids = array_map('strval', [...array_keys($candidates), ...array_keys($offers)]);
                $checked = [];
                $others = [];
                foreach ($this->hotelsById($ids) as $hotel) {
                    if (isset($answered[$destId], $candidates[$hotel['identity']['hotel_id']])) {
                        $checked[] = $hotel['identity'];
                    } else {
                        $others[] = $hotel['identity'];
                    }
                }
                if ($checked !== []) {
                    $this->records->recordCheck(self::PROVIDER, $checked, $offers, $now);
                }
                $this->records->recordOffersSeen(self::PROVIDER, $others, $offers, $now);
                $result['destinations']++;
                $result['hotels'] += count($checked);
            }

            return $result;
        });
    }

    /**
     * A customer's search found these offers: the whole destination's, as
     * the API answers a hotel page's search. Positives only: an empty answer
     * for one stay and occupancy proves nothing about a hotel. Only hotels
     * this store lists are recorded.
     *
     * @param list<array<string, mixed>> $results the raw offers, before they are narrowed to the page's hotel
     */
    public function searchFound(array $results, string $checkIn, string $now): void
    {
        if ($results === [] || preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkIn) !== 1) {
            return;
        }
        $this->guard('search', null, function () use ($results, $checkIn, $now): null {
            $tally = new OfferTally();
            self::addOffers($tally, $results, $checkIn);
            $offers = $tally->offers();
            if ($offers === []) {
                return null;
            }
            $hotels = array_column($this->hotelsById(array_map('strval', array_keys($offers))), 'identity');
            $this->records->recordOffersSeen(self::PROVIDER, $hotels, $offers, $now);

            return null;
        });
    }

    /**
     * "GR|Halkidiki (1234)": the destination a hotel's searches answer for,
     * readable, and unique by its id; the id alone while its name is unknown.
     * Some hotels hang off a country node, which is then their destination.
     */
    public static function destinationKey(string $countryCode, string $destinationName, int $destinationId): string
    {
        $country = strtoupper(trim($countryCode)) . '|';
        $name = trim($destinationName);
        if ($destinationId <= 0) {
            return mb_substr($country . $name, 0, 128);
        }
        if ($name === '') {
            return $country . $destinationId;
        }
        $room = max(0, 128 - mb_strlen($country) - strlen((string) $destinationId) - 3);

        return $country . mb_substr($name, 0, $room) . ' (' . $destinationId . ')';
    }

    /**
     * The hotels with these ids, as the records know them, and when the
     * sync first saved each (Sphinx says nothing of when it first listed
     * one, so that stands in for it).
     *
     * @param list<string> $ids
     *
     * @return list<array{identity: array{hotel_id: string, product_id: int, destination_key: string, hotel_name: string}, known_since: ?string}>
     */
    private function hotelsById(array $ids): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique(array_filter($ids, static fn (string $id): bool => $id !== ''))), self::CHUNK) as $chunk) {
            foreach (TypeCoerce::toRowList(db_get_array(
                'SELECT hotel_id, product_id, name, country_code, destination_id, destination_name, created_at
                 FROM ?:sphinx_hotels WHERE hotel_id IN (?a)',
                $chunk,
            )) as $row) {
                $created = TypeCoerce::toString($row['created_at'] ?? '');
                $out[] = [
                    'identity' => [
                        'hotel_id' => TypeCoerce::toString($row['hotel_id'] ?? ''),
                        'product_id' => max(0, TypeCoerce::toInt($row['product_id'] ?? 0)),
                        'destination_key' => self::destinationKey(
                            TypeCoerce::toString($row['country_code'] ?? ''),
                            TypeCoerce::toString($row['destination_name'] ?? ''),
                            TypeCoerce::toInt($row['destination_id'] ?? 0),
                        ),
                        'hotel_name' => mb_substr(trim(TypeCoerce::toString($row['name'] ?? '')), 0, 255),
                    ],
                    'known_since' => preg_match('/^\d{4}-\d{2}-\d{2}/', $created) === 1 && !str_starts_with($created, '0000') ? substr($created, 0, 10) : null,
                ];
            }
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
                'message' => '[SphinxAvailability] ' . $what . ': ' . $e->getMessage(),
            ]);

            return $fallback;
        }
    }
}
