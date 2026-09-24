<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Cron\Commands;

use Tygh\Addons\NovotonHolidays\Cron\AbstractCronCommand;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
use Tygh\Addons\NovotonHolidays\Services\Container;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

class RoomPriceCheckCommand extends AbstractCronCommand
{
    /** Hotels per curl_multi batch (and per has_room_price DB update). */
    public const int BATCH_SIZE = 25;

    /** Simultaneous room_price requests; the API is shared, so modest. */
    public const int DEFAULT_CONCURRENCY = 5;

    public const int MAX_CONCURRENCY = 10;

    /**
     * @return list<string>
     */
    #[\Override]
    public static function getModes(): array
    {
        return ['room_price'];
    }

    public static function getDescription(): string
    {
        return 'Check which hotels have active room_price data';
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(): array
    {
        $dbHelper = Container::getInstance()->databaseHelper();
        // Remember whether check_in was supplied so we can warn the operator: a
        // defaulted date can land out of season and return 0 priced hotels, which
        // is easily mistaken for "no hotel has prices".
        $check_in_param = $this->getParam('check_in', '');
        $check_in = is_string($check_in_param) ? $check_in_param : '';
        $datesDefaulted = $check_in === '';
        if ($datesDefaulted) {
            $check_in = date('Y-m-d', (int) strtotime('+30 days'));
        }
        $nights = TypeCoerce::toInt($this->getParam('nights', 7));
        $limit = TypeCoerce::toInt($this->getParam('limit', 500));
        $concurrency = max(1, min(self::MAX_CONCURRENCY, TypeCoerce::toInt($this->getParam('concurrency', self::DEFAULT_CONCURRENCY))));
        $country = strtoupper(TypeCoerce::toString($this->getParam('country', '')));
        $check_out = date('Y-m-d', (int) strtotime($check_in . ' + ' . $nights . ' days'));

        $this->output('Checking hotels with active prices...');
        $this->output("Check-in: {$check_in}, Check-out: {$check_out}, Nights: {$nights}, Limit: {$limit}");
        $this->output("Least recently checked hotels first, {$concurrency} requests at a time (&concurrency=N, max " . self::MAX_CONCURRENCY . ').');
        if ($datesDefaulted) {
            $this->output('  NOTE: no &check_in supplied — using default (+30 days). Out-of-season');
            $this->output('        dates can return 0 priced hotels. Pass &check_in=YYYY-MM-DD to');
            $this->output('        test the dates customers actually search.');
        }
        if ($country !== '' && $country !== '0') {
            $this->output("Country: {$country}");
        }
        $this->output('');

        $conditions = ($country !== '' && $country !== '0') ? ['country' => $country] : [];
        $hotels = $dbHelper->getHotelsForPriceCheck($conditions, $limit);

        $withPricesIds = [];
        $withoutPricesIds = [];
        $withPricesCount = 0;
        $withoutPricesCount = 0;
        $invalidCount = 0;

        // Accumulate priced hotels by country for the grouped summary printed at
        // the end. Reuses the country already fetched above.
        /** @var array<string, list<string>> $pricedByCountry */
        $pricedByCountry = [];

        // One API call per hotel is unavoidable (room_price is per hotel), but
        // they no longer wait on each other: each batch of 25 is sent through
        // curl_multi, $concurrency at a time. The old loop sent them one by one
        // with a pause after each — ~1.5 s per hotel, 12+ minutes for 500.
        foreach (array_chunk($hotels, self::BATCH_SIZE) as $batch) {
            // Mirror the admin "Check Prices" call (novoton_prices.php): bypass the
            // price cache so we always hit the live API, and do NOT pass
            // 'children' => 0 (an int lands in the cache-key params as 0 instead of
            // [], diverging from the admin's key and reading stale/empty entries).
            $requests = [];
            foreach ($batch as $hotel) {
                $requests[TypeCoerce::toString($hotel['hotel_id'])] = [
                    'hotel_id' => $hotel['hotel_id'],
                    'check_in' => $check_in,
                    'check_out' => $check_out,
                    'adults' => 2,
                    'nocache' => true,
                ];
            }

            try {
                $responses = $this->api->pricing()->getRoomPriceBatch($requests, $concurrency);
            } catch (\Exception) {
                // The whole batch failed (e.g. the API circuit breaker is open):
                // treat every hotel in it as an invalid response, as before.
                $responses = [];
            }

            foreach ($batch as $hotel) {
                $hotelId = TypeCoerce::toString($hotel['hotel_id']);
                $has_prices = self::hasPrices($responses[$hotelId]['data'] ?? false);

                if ($has_prices === true) {
                    $withPricesIds[] = $hotelId;
                    $hotelName = TypeCoerce::toString($hotel['hotel_name']);
                    $hotelCountry = strtoupper(TypeCoerce::toString($hotel['country'] ?? ''));
                    if ($hotelCountry === '') {
                        $hotelCountry = 'UNKNOWN';
                    }
                    $this->output("NVT-{$hotelId} | {$hotelName} | {$hotelCountry} - has prices");
                    $pricedByCountry[$hotelCountry][] = "NVT-{$hotelId} | {$hotelName}";
                } else {
                    $withoutPricesIds[] = $hotelId;
                    if ($has_prices === null) {
                        $invalidCount++;
                    }
                }
            }

            $dbHelper->batchUpdateHasRoomPriceFlag($withPricesIds, $withoutPricesIds);
            $withPricesCount += count($withPricesIds);
            $withoutPricesCount += count($withoutPricesIds);
            $withPricesIds = [];
            $withoutPricesIds = [];

            // A short pause between batches keeps the API rate polite.
            usleep(ConfigProvider::API_DELAY_MS * 1000);
        }

        $this->output('');
        $this->output("Hotels WITH prices: {$withPricesCount}");
        $this->output("Hotels WITHOUT prices: {$withoutPricesCount}");
        $this->output("  of which invalid API response: {$invalidCount}");
        $this->output('Total checked: ' . ($withPricesCount + $withoutPricesCount));

        // Grouped-by-country summary of the priced hotels (alphabetical).
        foreach (self::formatCountryGroups($pricedByCountry) as $line) {
            $this->output($line);
        }

        $stats = [
            'with_prices' => $withPricesCount,
            'without_prices' => $withoutPricesCount,
            'invalid' => $invalidCount,
            'by_country' => array_map(static fn (array $hotels): int => count($hotels), $pricedByCountry),
        ];
        $this->logComplete('room_price', $stats);
        return ['success' => true, 'stats' => $stats];
    }

    /**
     * Whether a room_price response carries prices.
     *
     * TRUE when it has at least one <Price>, FALSE for a valid response with
     * none, NULL when there is no usable response (API or XML error). A
     * presence check only — the cron sets has_room_price Y/N; it stores no
     * amount.
     */
    public static function hasPrices(mixed $response): ?bool
    {
        if (!$response instanceof \SimpleXMLElement) {
            return null;
        }

        return !empty($response->xpath('//Price'));
    }

    /**
     * Build the "grouped by country" output lines for hotels that have prices.
     *
     * Pure (no I/O) so it is unit-testable without driving the live API loop.
     * Countries are listed alphabetically; each carries its hotel count.
     *
     * @param array<string, list<string>> $pricedByCountry country => ["NVT-{id} | {name}", …]
     * @return list<string> Output lines (empty when there are no priced hotels).
     */
    public static function formatCountryGroups(array $pricedByCountry): array
    {
        if ($pricedByCountry === []) {
            return [];
        }

        ksort($pricedByCountry);

        $lines = ['', '=== Hotels WITH prices — grouped by country ==='];
        foreach ($pricedByCountry as $country => $hotels) {
            $lines[] = "{$country} (" . count($hotels) . '):';
            foreach ($hotels as $hotel) {
                $lines[] = "  {$hotel}";
            }
        }

        return $lines;
    }
}
