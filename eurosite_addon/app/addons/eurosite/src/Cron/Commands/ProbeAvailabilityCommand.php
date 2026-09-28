<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Cron\Commands;

use Tygh\Addons\Eurosite\Services\AvailabilityPlan;
use Tygh\Addons\Eurosite\Services\ConfigProvider;
use Tygh\Addons\Eurosite\Services\Container;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * mode `probe_availability`: which destinations and dates Eurosite really
 * has offers for. READ-ONLY: nothing is stored, no product is shown or hidden.
 *
 * The availability check asks only the dates in the add-on settings (near
 * days + peak-season dates). When those find nothing in a country, this asks
 * the same price search (getHotelPriceRequest, one call per city and date)
 * across the next months and prints, per city, the dates that answered with
 * offers, how many were Immediate / On request / Stop sale, and the first
 * Immediate hotels found.
 *
 *   &country=GR               the country (default GR)
 *   &city=GR0041,GR0102       destinations (default: every synced destination of the country)
 *   &from=2026-10-15          first check-in (default: in 7 days)
 *   &months=12  &step=14      how far to look, days between check-ins
 *   &nights=7                 stay length (default: the add-on setting)
 *   &max_calls=80             API call budget (the searches are heavy for the operator)
 */
final class ProbeAvailabilityCommand extends AbstractSyncCommand
{
    private const PAUSE_US = 300_000;

    private const EXAMPLES = 5;

    #[\Override]
    public static function getModes(): array
    {
        return ['probe_availability'];
    }

    #[\Override]
    public static function getDescription(): string
    {
        return 'Read-only: search the next months for dates and destinations with offers (&country=GR, &city=A,B, &from, &months, &step, &nights, &max_calls)';
    }

    /**
     * The check-in dates to probe.
     *
     * @return list<array{check_in: string, check_out: string}>
     */
    public static function dates(\DateTimeImmutable $from, int $months, int $step, int $nights): array
    {
        $until = $from->modify('+' . max(1, $months) . ' months');
        $dates = [];
        for ($d = $from; $d <= $until; $d = $d->modify('+' . max(1, $step) . ' days')) {
            $dates[] = [
                'check_in' => $d->format('Y-m-d'),
                'check_out' => $d->modify('+' . max(1, $nights) . ' days')->format('Y-m-d'),
            ];
        }

        return $dates;
    }

    #[\Override]
    public function execute(array $params = []): array
    {
        $country = strtoupper(trim(TypeCoerce::toString($params['country'] ?? 'GR'))) ?: 'GR';
        $nights = max(1, TypeCoerce::toInt($params['nights'] ?? 0) ?: ConfigProvider::getAvailabilityNights());
        $maxCalls = max(1, TypeCoerce::toInt($params['max_calls'] ?? 80));
        $fromParam = trim(TypeCoerce::toString($params['from'] ?? ''));
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromParam) === 1
            ? new \DateTimeImmutable($fromParam)
            : new \DateTimeImmutable('today +7 days');
        $dates = self::dates($from, TypeCoerce::toInt($params['months'] ?? 12), TypeCoerce::toInt($params['step'] ?? 14), $nights);

        // The destinations: the ones asked for, else every synced one of the country.
        $wanted = array_values(array_filter(array_map(
            static fn (string $c): string => strtoupper(trim($c)),
            explode(',', TypeCoerce::toString($params['city'] ?? '')),
        )));
        $targets = [];
        foreach (Container::hotels()->getAvailabilityTargets() as $group) {
            if ($group['country_code'] === $country && ($wanted === [] || in_array($group['city_code'], $wanted, true))) {
                $targets[$group['city_code']] = $group;
            }
        }
        foreach ($wanted as $city) {
            // A destination with no synced hotels: asked with the default tour operator.
            $targets[$city] ??= ['tourop_code' => '', 'country_code' => $country, 'city_code' => $city, 'hotels' => 0];
        }
        if ($targets === []) {
            $this->output("No synced {$country} destinations. Pass &city=CODE1,CODE2 (the city codes from the Eurosite whitelist).");

            return ['success' => false, 'error' => 'no destinations'];
        }

        $calls = count($targets) * count($dates);
        if ($calls > $maxCalls) {
            // Keep every city, thin the dates evenly to fit the budget.
            $keep = max(1, intdiv($maxCalls, count($targets)));
            $stride = (int) ceil(count($dates) / $keep);
            $dates = array_values(array_filter($dates, static fn (int $i): bool => $i % $stride === 0, ARRAY_FILTER_USE_KEY));
        }

        $this->output(sprintf(
            '=== probe_availability %s · %d destinations · %d check-ins %s … %s · %d nights · %s, 2 adults (read-only) ===',
            $country,
            count($targets),
            count($dates),
            $dates[0]['check_in'] ?? '',
            $dates[count($dates) - 1]['check_in'] ?? '',
            $nights,
            AvailabilityPlan::ROOM['code'],
        ));

        $api = Container::getApi();
        $cities = Container::cities();
        $immediate = [];
        $first = true;
        $errors = 0;
        foreach ($targets as $city => $group) {
            $cityRow = $cities->findByCode($city);
            $cityName = $cityRow !== null ? TypeCoerce::toString($cityRow['name'] ?? '') : '';
            $this->output('');
            $this->output("{$city} {$cityName}" . ($group['hotels'] > 0 ? " ({$group['hotels']} synced hotels)" : ' (no synced hotels)'));
            $withOffers = 0;
            foreach ($dates as $date) {
                if (!$first) {
                    usleep(self::PAUSE_US);
                }
                $first = false;
                try {
                    $offers = $api->searchHotels([
                        'country_code' => $country,
                        'city_code' => $city,
                        'tourop_code' => $group['tourop_code'],
                        'check_in' => $date['check_in'],
                        'check_out' => $date['check_out'],
                        'currency' => ConfigProvider::getDefaultCurrency(),
                        'language' => ConfigProvider::getDefaultLanguage(),
                        'rooms' => [AvailabilityPlan::ROOM],
                    ]);
                } catch (\Throwable $e) {
                    $errors++;
                    $this->output("  {$date['check_in']}: ERROR " . $e->getMessage());
                    continue;
                }
                if ($offers === []) {
                    continue;
                }
                $withOffers++;
                $byCode = ['IM' => [], 'OR' => [], 'ST' => []];
                foreach ($offers as $offer) {
                    $code = isset($byCode[$offer->availabilityCode]) ? $offer->availabilityCode : 'OR';
                    $byCode[$code][$offer->productCode] = true;
                    if ($code === 'IM' && count($immediate) < self::EXAMPLES && !isset($immediate[$offer->productCode])) {
                        $immediate[$offer->productCode] = sprintf(
                            '%s (%s) · %s, %s · %s–%s · %.2f %s',
                            $offer->productName,
                            $offer->productCode,
                            $offer->cityName !== '' ? $offer->cityName : $city,
                            $offer->category > 0 ? $offer->category . '*' : 'no stars',
                            $date['check_in'],
                            $date['check_out'],
                            $offer->price,
                            $offer->currency,
                        );
                    }
                }
                $this->output(sprintf(
                    '  %s: %d hotels · Immediate %d · On request %d · Stop sale %d',
                    $date['check_in'],
                    count(array_unique(array_merge(array_keys($byCode['IM']), array_keys($byCode['OR']), array_keys($byCode['ST'])))),
                    count($byCode['IM']),
                    count($byCode['OR']),
                    count($byCode['ST']),
                ));
            }
            if ($withOffers === 0) {
                $this->output('  no offers on any probed date');
            }
        }

        $this->output('');
        $this->output('=== First ' . self::EXAMPLES . ' Immediate hotels ===');
        if ($immediate === []) {
            $this->output('  none: no probed date had an Immediate offer. Try other destinations, &nights=, or &from= in the season.');
        }
        foreach (array_values($immediate) as $i => $line) {
            $this->output('  ' . ($i + 1) . '. ' . $line);
        }
        $this->output('');
        $this->output('To use dates that answered: Eurosite settings → availability peak-season dates (then run availability).');

        return ['success' => true, 'immediate' => count($immediate), 'errors' => $errors, 'calls' => count($targets) * count($dates)];
    }
}
