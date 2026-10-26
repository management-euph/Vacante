<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Addons\NovotonHolidays\Api\Contracts\NovotonApiKitInterface;
use Tygh\Addons\NovotonHolidays\Api\Refusal;
use Tygh\Addons\NovotonHolidays\Helpers\DatabaseHelperInterface;
use Tygh\Addons\NovotonHolidays\Helpers\PriceCheckFlags;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Services\OfferTally;

/**
 * The weekly availability check (docs/novoton-availability-gaps-plan.md
 * §4.3), run by the `room_price` job on its existing nightly line.
 *
 * Each run asks Novoton about its share of the units (ResortCheckQueue) on
 * every date of the check: one resort-level room_price call per resort and
 * date, which answers for every hotel of the resort that has a price; a
 * hotel no resort answer covers is asked on its own. Then, per unit:
 *
 *  - has_room_price: 'Y' for a hotel with an offer on any date, 'N' for its
 *    other hotels once every date answered. add_hotels_as_products reads it
 *    as before;
 *  - the availability records: a whole answer checked every hotel of the
 *    unit, offer or not; part of one counts only the offers it found.
 *
 * Only answers count: a call that failed, was refused or did not parse
 * changes nothing, and its unit comes first again next run. Units in a row
 * without a single answer end the run, since Novoton is then not answering.
 * The run also ends at its time budget (before add_hotels_as_products) and
 * at its call budget; the units it did not reach go first next time.
 *
 * Novoton's room_price says nothing of on-request rooms (hotel_quota does,
 * for customers' searches), so these offers never count as immediate.
 */
final class ResortCheckRunner
{
    /** Units in a row without one answer that end the run. */
    public const int SILENT_UNITS_STOP = 2;

    /** Simultaneous calls for a hotel asked on its own (one per date). */
    public const int HOTEL_CONCURRENCY = 2;

    private const int ADULTS = 2;

    /** The columns the check reads for a hotel found in an answer under another resort. */
    private const array HOTEL_FIELDS = ['hotel_id', 'product_id', 'hotel_name', 'city', 'country', 'created_at'];

    /** @var \Closure(string): void */
    private readonly \Closure $output;

    /** @var \Closure(): float */
    private readonly \Closure $clock;

    /** @var \Closure(int): void */
    private readonly \Closure $pause;

    private int $calls = 0;

    /**
     * @param (\Closure(string): void)|null $output one line of the run's log
     * @param (\Closure(): float)|null $clock seconds, for the time budget
     * @param (\Closure(int): void)|null $pause milliseconds between two calls
     */
    public function __construct(
        private readonly NovotonApiKitInterface $api,
        private readonly DatabaseHelperInterface $db,
        private readonly NovotonAvailabilityRecorder $recorder = new NovotonAvailabilityRecorder(),
        ?\Closure $output = null,
        ?\Closure $clock = null,
        ?\Closure $pause = null,
    ) {
        $this->output = $output ?? static function (string $line): void {
        };
        $this->clock = $clock ?? static fn (): float => microtime(true);
        $this->pause = $pause ?? static function (int $ms): void {
            usleep($ms * 1000);
        };
    }

    /**
     * One run. $scopeWhere picks the sold hotels (DestinationScope::syncWhere());
     * $country narrows the run to one country.
     *
     * @return array{stats: array{units: int, planned: int, checked: int, partial: int, unanswered: int, left: int, calls: int, with_prices: int, without_prices: int, invalid: int, by_country: array<string, int>, skipped_countries: list<string>, stopped: string}, priced: array<string, list<string>>}
     */
    public function run(ResortCheckSettings $settings, \DateTimeImmutable $now, string $scopeWhere, string $country = ''): array
    {
        $started = ($this->clock)();
        $this->calls = 0;
        $stamp = $now->format('Y-m-d H:i:s');
        $stats = [
            'units' => 0, 'planned' => 0, 'checked' => 0, 'partial' => 0, 'unanswered' => 0, 'left' => 0, 'calls' => 0,
            'with_prices' => 0, 'without_prices' => 0, 'invalid' => 0, 'by_country' => [], 'skipped_countries' => [], 'stopped' => '',
        ];
        $priced = [];

        $windows = $settings->windows($now);
        if ($windows === []) {
            $this->say('No dates to check: set the near days or the season dates (Settings -> Advanced -> Weekly availability check).');

            return ['stats' => $stats, 'priced' => $priced];
        }

        $hotels = [];
        foreach ($this->db->getHotelsForPriceCheck($country !== '' ? ['country' => $country] : [], 0, $scopeWhere) as $row) {
            $hotelId = TypeCoerce::toString($row['hotel_id'] ?? '');
            if ($hotelId !== '') {
                $hotels[$hotelId] = $row;
            }
        }

        $resorts = [];
        $countries = array_filter(array_unique(array_map(static fn (array $row): string => strtoupper(trim(TypeCoerce::toString($row['country'] ?? ''))), $hotels)));
        sort($countries);
        foreach ($countries as $name) {
            $list = $this->resortList($name);
            if ($list === null) {
                $stats['skipped_countries'][] = $name;
            } else {
                $resorts[$name] = $list;
            }
        }

        $units = ResortCheckQueue::units(array_values($hotels), $resorts);
        $stats['units'] = count($units);
        $stats['planned'] = $settings->units > 0
            ? min(count($units), $settings->units)
            : ResortCheckQueue::share(count($units), $settings->cycleDays, count($windows), $settings->maxCalls);
        $planned = array_slice($units, 0, $stats['planned']);
        $this->sayPlan($units, $planned, $windows, $settings);

        $unitHotelIds = [];
        foreach ($planned as $unit) {
            array_push($unitHotelIds, ...$unit['hotel_ids']);
        }
        $firstPublished = $this->recorder->awaitingFirstOffer($unitHotelIds, $now->format('Y-m-d'));

        $night = new OfferTally();
        $silent = 0;
        $done = 0;
        $asked = [];
        $decided = []; // hotel_id => an offer; an offer found later in the run wins
        $known = $hotels;
        foreach ($planned as $index => $unit) {
            $elapsed = ($this->clock)() - $started;
            if ($elapsed >= $settings->maxSeconds || ($done > 0 && $elapsed + $elapsed / $done > $settings->maxSeconds)) {
                $stats['stopped'] = 'time';
                $stats['left'] = count($planned) - $index;
                break;
            }
            $starts = [];
            foreach ($unit['hotel_ids'] as $hotelId) {
                if (isset($firstPublished[$hotelId])) {
                    $starts[] = $firstPublished[$hotelId];
                }
            }
            $dates = ResortCheckQueue::dates($windows, $starts, $settings->nights);
            if ($this->calls + count($dates) > $settings->maxCalls) {
                $stats['stopped'] = 'calls';
                $stats['left'] = count($planned) - $index;
                break;
            }

            $answer = $unit['kind'] === ResortCheckQueue::RESORT
                ? $this->askResort($unit['resort'], $dates)
                : $this->askHotel($unit['hotel_ids'][0], $dates);
            $done++;
            foreach ($answer['offers'] as $hotelId => $stays) {
                foreach ($stays as $stay) {
                    $night->add((string) $hotelId, $stay, false);
                }
            }
            $whole = $answer['answered'] === count($dates);
            $flags = $this->save($unit, $answer, $whole, $night, $hotels, $stamp);
            $stats[$whole ? 'checked' : ($answer['answered'] > 0 ? 'partial' : 'unanswered')]++;
            foreach ($unit['hotel_ids'] as $hotelId) {
                $asked[$hotelId] = true;
            }
            foreach ($flags['without'] as $hotelId) {
                $decided[$hotelId] ??= false;
            }
            foreach ($flags['with'] as $hotelId) {
                $decided[$hotelId] = true;
            }
            $known += $flags['rows'];
            $this->sayUnit($index + 1, count($planned), $unit, $answer, count($dates), $flags, $hotels);

            $silent = $answer['answered'] === 0 ? $silent + 1 : 0;
            if ($silent >= self::SILENT_UNITS_STOP) {
                $stats['stopped'] = 'silent';
                $stats['left'] = count($planned) - $index - 1;
                break;
            }
        }

        $stats['calls'] = $this->calls;
        foreach ($decided as $hotelId => $offered) {
            if (!$offered) {
                $stats['without_prices']++;
                continue;
            }
            $stats['with_prices']++;
            $row = $known[$hotelId] ?? [];
            $where = strtoupper(trim(TypeCoerce::toString($row['country'] ?? '')));
            $priced[$where !== '' ? $where : 'UNKNOWN'][] = 'NVT-' . $hotelId . ' | ' . TypeCoerce::toString($row['hotel_name'] ?? '');
        }
        $stats['invalid'] = count(array_diff_key($asked, $decided));
        foreach ($priced as $where => $list) {
            $stats['by_country'][$where] = count($list);
        }
        $this->saySummary($stats, ($this->clock)() - $started, $settings);

        return ['stats' => $stats, 'priced' => $priced];
    }

    /**
     * One resort, every date: the hotels each answer offered, and how many
     * dates answered.
     *
     * @param list<array{kind: string, check_in: string, check_out: string}> $dates
     *
     * @return array{answered: int, offers: array<array-key, list<string>>, errors: list<string>}
     */
    private function askResort(string $resort, array $dates): array
    {
        $answer = ['answered' => 0, 'offers' => [], 'errors' => []];
        foreach ($dates as $date) {
            $this->beforeCall();
            $reply = false;
            $error = '';
            try {
                $reply = $this->api->pricing()->getRoomPriceByResort([
                    'resort' => $resort,
                    'check_in' => $date['check_in'],
                    'check_out' => $date['check_out'],
                    'adults' => self::ADULTS,
                ]);
                $debug = $this->api->debugInfo();
                $hotels = PriceCheckFlags::resortOffers($reply, $reply === false ? $debug->lastResponseRaw : '');
                if ($hotels === null) {
                    $error = PriceCheckFlags::noAnswerReason($reply, $debug->lastError, $reply === false ? $debug->lastResponseRaw : '');
                }
            } catch (\Throwable $e) {
                $hotels = null;
                $error = $e->getMessage();
            }
            if ($hotels === null) {
                $answer['errors'][] = $date['check_in'] . ': ' . $error;
                continue;
            }
            $answer['answered']++;
            foreach ($hotels as $hotelId) {
                $answer['offers'][$hotelId][] = $date['check_in'];
            }
        }

        return $answer;
    }

    /**
     * A hotel no resort answer covers, asked on its own on every date.
     *
     * @param list<array{kind: string, check_in: string, check_out: string}> $dates
     *
     * @return array{answered: int, offers: array<array-key, list<string>>, errors: list<string>}
     */
    private function askHotel(string $hotelId, array $dates): array
    {
        $answer = ['answered' => 0, 'offers' => [], 'errors' => []];
        $requests = [];
        foreach ($dates as $date) {
            $requests[$date['check_in']] = [
                'hotel_id' => $hotelId,
                'check_in' => $date['check_in'],
                'check_out' => $date['check_out'],
                'adults' => self::ADULTS,
                'nocache' => true,
            ];
        }
        $this->beforeCall(count($requests));
        try {
            $replies = $this->api->pricing()->getRoomPriceBatch($requests, self::HOTEL_CONCURRENCY);
        } catch (\Throwable $e) {
            $answer['errors'][] = $e->getMessage();

            return $answer;
        }
        foreach ($dates as $date) {
            $reply = $replies[$date['check_in']] ?? null;
            $has = $reply === null ? null : PriceCheckFlags::hasPrices($reply['data'], $reply['unparsed'] ?? '');
            if ($has === null) {
                $answer['errors'][] = $date['check_in'] . ': no answer';
                continue;
            }
            $answer['answered']++;
            if ($has) {
                $answer['offers'][$hotelId][] = $date['check_in'];
            }
        }

        return $answer;
    }

    /**
     * Writes one unit's answer: has_room_price and the availability records,
     * for its hotels and for any hotel Novoton answered for under this resort
     * that the store files under another city.
     *
     * @param array{kind: string, country: string, resort: string, hotel_ids: list<string>, last_check: string} $unit
     * @param array{answered: int, offers: array<array-key, list<string>>, errors: list<string>} $answer
     * @param array<string, array<string, mixed>> $hotels the sold hotels by id
     *
     * @return array{with: list<string>, without: list<string>, rows: array<string, array<string, mixed>>}
     */
    private function save(array $unit, array $answer, bool $whole, OfferTally $night, array $hotels, string $stamp): array
    {
        $flags = ['with' => [], 'without' => [], 'rows' => []];
        if ($answer['answered'] === 0) {
            return $flags;
        }
        $offers = $night->offers();

        $rows = [];
        foreach ($unit['hotel_ids'] as $hotelId) {
            $rows[] = $hotels[$hotelId] ?? ['hotel_id' => $hotelId];
            if ($night->has($hotelId)) {
                $flags['with'][] = $hotelId;
            } elseif ($whole) {
                $flags['without'][] = $hotelId;
            }
        }
        $this->recorder->checkAnswered($rows, $offers, $whole, $stamp);

        $others = array_values(array_diff(array_map('strval', array_keys($answer['offers'])), $unit['hotel_ids']));
        if ($others !== []) {
            $flags['rows'] = $this->hotelRows($others, $hotels);
            $this->recorder->checkAnswered(array_values($flags['rows']), $offers, false, $stamp);
            array_push($flags['with'], ...array_map('strval', array_keys($flags['rows'])));
        }

        if ($flags['with'] !== [] || $flags['without'] !== []) {
            $this->db->batchUpdateHasRoomPriceFlag($flags['with'], $flags['without']);
        }

        return $flags;
    }

    /**
     * The store's rows for these hotels: from the sold ones, else read.
     * Hotels the store does not have are left out.
     *
     * @param list<string> $hotelIds
     * @param array<string, array<string, mixed>> $hotels
     *
     * @return array<string, array<string, mixed>>
     */
    private function hotelRows(array $hotelIds, array $hotels): array
    {
        $rows = [];
        $missing = [];
        foreach ($hotelIds as $hotelId) {
            if (isset($hotels[$hotelId])) {
                $rows[$hotelId] = $hotels[$hotelId];
            } else {
                $missing[] = $hotelId;
            }
        }
        if ($missing !== []) {
            foreach ($this->db->getHotelsForSync(['hotel_id' => $missing], 0, self::HOTEL_FIELDS) as $row) {
                $rows[TypeCoerce::toString($row['hotel_id'] ?? '')] = $row;
            }
        }
        unset($rows['']);

        return $rows;
    }

    /**
     * The resorts Novoton lists in a country, as it spells them (the
     * resort-level room_price matches them literally); null when it did not
     * answer, and then the country waits for the next run.
     *
     * @return list<string>|null
     */
    private function resortList(string $country): ?array
    {
        $this->beforeCall();
        try {
            $reply = $this->api->destinations()->getResortList($country);
        } catch (\Throwable $e) {
            $this->say("{$country}: no resort list ({$e->getMessage()}); its hotels wait for the next run.");

            return null;
        }
        $refused = Refusal::reason($reply);
        if ($refused !== null) {
            $this->say("{$country}: no resort list (Novoton: {$refused}); its hotels wait for the next run.");

            return null;
        }
        $names = [];
        foreach ($reply->xpath('//Resort') ?: [] as $node) {
            $name = trim((string) $node);
            if ($name !== '') {
                $names[] = $name;
            }
        }
        if ($names === []) {
            $this->say("{$country}: Novoton listed no resort; its hotels wait for the next run.");

            return null;
        }

        return $names;
    }

    /** Counts the calls about to be made, after a short pause between calls. */
    private function beforeCall(int $calls = 1): void
    {
        if ($this->calls > 0) {
            ($this->pause)(ConfigProvider::API_DELAY_MS);
        }
        $this->calls += $calls;
    }

    /**
     * @param list<array{kind: string, country: string, resort: string, hotel_ids: list<string>, last_check: string}> $units
     * @param list<array{kind: string, country: string, resort: string, hotel_ids: list<string>, last_check: string}> $planned
     * @param list<array{kind: string, check_in: string, check_out: string}> $windows
     */
    private function sayPlan(array $units, array $planned, array $windows, ResortCheckSettings $settings): void
    {
        if ($units === []) {
            $this->say('Nothing to check: no sold hotel in a country whose resorts Novoton listed.');

            return;
        }
        $alone = count(array_filter($units, static fn (array $u): bool => $u['kind'] === ResortCheckQueue::HOTEL));
        $this->say(sprintf(
            'Weekly availability check: %s%s, each asked once every %d days on %d dates (%s), 2 adults, %d nights.',
            self::plural(count($units) - $alone, 'resort'),
            $alone > 0 ? ' and ' . self::plural($alone, 'hotel') . ' asked alone (their city is not a Novoton resort)' : '',
            $settings->cycleDays,
            count($windows),
            implode(', ', array_column($windows, 'check_in')),
            $settings->nights,
        ));
        $this->say(sprintf(
            'This run: %d of them, least recently checked first; at most %d calls and %d minutes.',
            count($planned),
            $settings->maxCalls,
            intdiv($settings->maxSeconds, 60),
        ));
        $this->say('');
    }

    /**
     * @param array{kind: string, country: string, resort: string, hotel_ids: list<string>, last_check: string} $unit
     * @param array{answered: int, offers: array<array-key, list<string>>, errors: list<string>} $answer
     * @param array{with: list<string>, without: list<string>, rows: array<string, array<string, mixed>>} $flags
     * @param array<string, array<string, mixed>> $hotels
     */
    private function sayUnit(int $number, int $of, array $unit, array $answer, int $dates, array $flags, array $hotels): void
    {
        $name = $unit['kind'] === ResortCheckQueue::RESORT
            ? $unit['country'] . ' | ' . $unit['resort'] . ' (' . self::plural(count($unit['hotel_ids']), 'hotel') . ')'
            : $unit['country'] . ' | NVT-' . $unit['hotel_ids'][0] . ' ' . TypeCoerce::toString($hotels[$unit['hotel_ids'][0]]['hotel_name'] ?? '') . ' (asked alone)';
        $result = $answer['answered'] === 0
            ? 'no answer, nothing changed'
            : sprintf('%d with an offer, %d without', count($flags['with']), count($flags['without']));
        $this->say(sprintf('[%d/%d] %s: %d of %d dates answered; %s', $number, $of, $name, $answer['answered'], $dates, $result));
        foreach ($answer['errors'] as $error) {
            $this->say('    no answer: ' . $error);
        }
        if ($flags['rows'] !== []) {
            $this->say('    with an offer here, filed under another city in the store: ' . self::plural(count($flags['rows']), 'hotel'));
        }
    }

    /**
     * @param array{units: int, planned: int, checked: int, partial: int, unanswered: int, left: int, calls: int, with_prices: int, without_prices: int, invalid: int, by_country: array<string, int>, skipped_countries: list<string>, stopped: string} $stats
     */
    private function saySummary(array $stats, float $seconds, ResortCheckSettings $settings): void
    {
        $this->say('');
        $this->say(sprintf(
            'Checked %d units in full, %d in part, %d without an answer; %d calls in %d min %d s.',
            $stats['checked'],
            $stats['partial'],
            $stats['unanswered'],
            $stats['calls'],
            intdiv((int) $seconds, 60),
            (int) $seconds % 60,
        ));
        $this->say("Hotels WITH an offer: {$stats['with_prices']}");
        $this->say("Hotels WITHOUT an offer: {$stats['without_prices']}");
        $this->say("No answer (flag left unchanged, checked again first next run): {$stats['invalid']}");
        if ($stats['skipped_countries'] !== []) {
            $this->say('No resort list, so not checked this run: ' . implode(', ', $stats['skipped_countries']));
        }
        $reason = [
            'time' => 'Stopped at the time budget (' . intdiv($settings->maxSeconds, 60) . ' minutes)',
            'calls' => "Stopped at the call budget ({$settings->maxCalls} calls)",
            'silent' => 'Stopped: Novoton did not answer ' . self::SILENT_UNITS_STOP . ' units in a row',
        ][$stats['stopped']] ?? '';
        if ($reason !== '') {
            $this->say("{$reason}: {$stats['left']} units go first next run.");
        }
    }

    /** "1 hotel", "3 hotels". */
    private static function plural(int $n, string $noun): string
    {
        return $n . ' ' . $noun . ($n === 1 ? '' : 's');
    }

    private function say(string $line): void
    {
        ($this->output)($line);
    }
}
