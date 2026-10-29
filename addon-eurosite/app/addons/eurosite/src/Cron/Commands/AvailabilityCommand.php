<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Cron\Commands;

use Tygh\Addons\Eurosite\Services\AvailabilityPlan;
use Tygh\Addons\Eurosite\Services\ConfigProvider;
use Tygh\Addons\Eurosite\Services\Container;
use Tygh\Addons\Eurosite\Services\EurositeAvailabilityRecorder;
use Tygh\Addons\Eurosite\Services\ProductGate;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Services\AvailabilityWindows;
use Tygh\Addons\TravelCore\Services\OfferTally;

/**
 * mode `availability` — which listed hotels have an Immediate offer.
 *
 * One getHotelPriceRequest per destination and date (near dates + the
 * store's peak-season dates, see AvailabilityWindows), not one per hotel: a
 * city search answers for every hotel in it. Each listed hotel then carries
 * its best answer (IM > OR > ST > none), the lowest price for it and the
 * date that proved it. Only Immediate hotels become products.
 *
 * Then, when "Hide a product when none of these dates has an Immediate
 * offer" is on, ProductGate hides the products that lost it and shows the
 * ones that got it back — only for destinations whose check answered.
 *
 * A destination's answer is whole only when every date answered. When
 * some did not, one of them may have held an Immediate offer: only the
 * answers better than what a hotel has are stored, nobody becomes "no
 * offer", and its products can be shown again but are never hidden.
 *
 * `&city=CODE` checks one destination (the hotel list's "Check availability
 * now" uses it for the selected hotels' destinations).
 */
final class AvailabilityCommand extends AbstractSyncCommand
{
    #[\Override]
    public static function getModes(): array
    {
        return ['availability'];
    }

    #[\Override]
    public static function getDescription(): string
    {
        return 'Check which whitelisted hotels have an Immediate offer (near and peak-season dates; &city=CODE for one); hides and shows products';
    }

    #[\Override]
    public function execute(array $params = []): array
    {
        return $this->runLogged('availability', function () use ($params): array {
            $only = strtoupper(trim(TypeCoerce::toString($params['city'] ?? '')));
            $windows = AvailabilityWindows::windows(
                new \DateTimeImmutable('today'),
                ConfigProvider::getAvailabilityNearDays(),
                ConfigProvider::getAvailabilitySeasonDates(),
                ConfigProvider::getAvailabilityNights(),
            );
            if ($windows === []) {
                throw new \RuntimeException('No dates to check: set the near days or the peak-season dates in the addon settings.');
            }

            $hotelRepo = Container::hotels();
            $groups = $hotelRepo->getAvailabilityTargets($only);
            if ($groups === []) {
                throw new \RuntimeException($only !== ''
                    ? "No listed hotels in {$only}."
                    : 'No hotels to check. Whitelist destinations, then run the hotels sync.');
            }

            $this->output('  Dates: ' . implode(', ', array_map(
                static fn (array $w): string => $w['check_in'] . ($w['kind'] === 'season' ? ' (season)' : ''),
                $windows,
            )) . ' · ' . ConfigProvider::getAvailabilityNights() . ' nights · DB, 2 adults');

            $api = Container::getApi();
            $cityRepo = Container::cities();
            $total = 0;
            $synced = 0;
            $errors = [];
            $answeredCities = [];
            $partlyAnsweredCities = [];
            $sum = ['IM' => 0, 'OR' => 0, 'ST' => 0, 'NONE' => 0, 'not_synced' => 0];
            $now = date('Y-m-d H:i:s');
            // Each city's offers, for Travel Core's availability records.
            $checks = [];

            foreach ($groups as $group) {
                $total += $group['hotels'];
                $best = [];
                $tally = new OfferTally();
                $answered = 0;
                foreach ($windows as $window) {
                    // A scheduled run spaces the searches (Api\RequestPacer).
                    $this->trySyncItem(function () use ($api, $group, $window, $tally, &$best, &$answered): void {
                        $offers = $api->searchHotels([
                            'country_code' => $group['country_code'],
                            'city_code' => $group['city_code'],
                            'tourop_code' => $group['tourop_code'],
                            'check_in' => $window['check_in'],
                            'check_out' => $window['check_out'],
                            'currency' => ConfigProvider::getDefaultCurrency(),
                            'language' => ConfigProvider::getDefaultLanguage(),
                            'rooms' => [AvailabilityPlan::ROOM],
                        ]);
                        $answered++;
                        foreach ($offers as $offer) {
                            $best[$offer->productCode] = AvailabilityPlan::merge($best[$offer->productCode] ?? null, $offer, $window);
                            EurositeAvailabilityRecorder::addOffer($tally, $offer, $window['check_in']);
                        }
                    }, "{$group['city_code']} {$window['check_in']}", $errors);
                }

                if ($answered === 0) {
                    // Nothing answered: keep what the hotels had, never mark them "no offer".
                    continue;
                }
                $complete = $answered === count($windows);
                $checks[] = ['tourop' => $group['tourop_code'], 'city' => $group['city_code'], 'offers' => $tally, 'complete' => $complete];
                $counts = $hotelRepo->applyAvailability($group['tourop_code'], $group['city_code'], $best, $now, $complete);
                foreach ($counts as $k => $n) {
                    $sum[$k] += $n;
                }
                if ($complete) {
                    $synced += $group['hotels'];
                    $answeredCities[] = $group['city_code'];
                } else {
                    $partlyAnsweredCities[] = $group['city_code'];
                }

                $cityRow = $cityRepo->findByCode($group['city_code']);
                $this->output(self::cityLine(
                    $group['city_code'],
                    $cityRow !== null ? TypeCoerce::toString($cityRow['name'] ?? '') : '',
                    $counts,
                ) . ($complete ? '' : sprintf(
                    ' (%d of %d dates answered: only better answers stored, nothing hidden)',
                    $answered,
                    count($windows),
                )));
            }

            // Travel Core's records: every hotel of a city that answered on
            // every date was checked; elsewhere only the offers found count.
            $recorded = (new EurositeAvailabilityRecorder())->runChecked($checks, $now);
            if ($checks !== [] && !$recorded['recorded']) {
                $this->output('  Availability records: no hotel had an offer anywhere, so no hotel is recorded as checked');
            }

            $gate = ['hidden' => 0, 'shown' => 0, 'released' => 0];
            if (ConfigProvider::hideUnavailableProducts()) {
                $gate = (new ProductGate())->apply($answeredCities, $partlyAnsweredCities);
                if ($gate['hidden'] + $gate['shown'] > 0) {
                    $this->output("  Products: {$gate['hidden']} hidden (no Immediate offer), {$gate['shown']} shown again");
                }
            }
            if ($sum['not_synced'] > 0) {
                $this->output("  {$sum['not_synced']} offered hotels are not in the hotel sync yet (run the hotels sync)");
            }

            return [
                'total' => $total,
                'synced' => $synced,
                'failed' => count($errors),
                'immediate' => $sum['IM'],
                'on_request' => $sum['OR'],
                'stop_sale' => $sum['ST'],
                'no_offer' => $sum['NONE'],
                'not_synced' => $sum['not_synced'],
                'partly_answered' => count($partlyAnsweredCities),
                'products_hidden' => $gate['hidden'],
                'products_shown' => $gate['shown'],
                'error' => implode('; ', array_slice($errors, 0, 5)),
            ];
        });
    }

    /**
     * "  Mamaia RO0363: 12 Immediate, 3 On request, 1 Stop sale, 80 no offer"
     *
     * @param array<string, int> $counts
     */
    public static function cityLine(string $cityCode, string $cityName, array $counts): string
    {
        $label = HotelsSyncCommand::cityLine($cityCode, $cityName, 0);
        $label = substr($label, 0, (int) strrpos($label, ':'));

        return sprintf(
            '%s: %d Immediate, %d On request, %d Stop sale, %d no offer',
            $label,
            $counts['IM'] ?? 0,
            $counts['OR'] ?? 0,
            $counts['ST'] ?? 0,
            $counts['NONE'] ?? 0,
        );
    }
}
