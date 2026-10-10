<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Cron\Commands;

use Tygh\Addons\Eurosite\Services\AvailabilityPlan;
use Tygh\Addons\Eurosite\Services\ConfigProvider;
use Tygh\Addons\Eurosite\Services\Container;
use Tygh\Addons\Eurosite\Services\ProductGate;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * mode `availability` — which listed hotels have an Immediate offer.
 *
 * One getHotelPriceRequest per destination and date (near dates + the
 * store's peak-season dates, see AvailabilityPlan), not one per hotel: a
 * city search answers for every hotel in it. Each listed hotel then carries
 * its best answer (IM > OR > ST > none), the lowest price for it and the
 * date that proved it. Only Immediate hotels become products.
 *
 * Then, when "Hide a product when none of these dates has an Immediate
 * offer" is on, ProductGate hides the products that lost it and shows the
 * ones that got it back — only for destinations whose check answered.
 *
 * `&city=CODE` checks one destination (the hotel list's "Check availability
 * now" uses it for the selected hotels' destinations).
 */
final class AvailabilityCommand extends AbstractSyncCommand
{
    /** Between two price searches: they are heavy for the operator's server. */
    private const PAUSE_US = 200_000;

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
            $windows = AvailabilityPlan::windows(
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
            $sum = ['IM' => 0, 'OR' => 0, 'ST' => 0, 'NONE' => 0, 'not_synced' => 0];
            $now = date('Y-m-d H:i:s');
            $first = true;

            foreach ($groups as $group) {
                $total += $group['hotels'];
                $best = [];
                $answered = 0;
                foreach ($windows as $window) {
                    if (!$first) {
                        usleep(self::PAUSE_US);
                    }
                    $first = false;
                    $this->trySyncItem(function () use ($api, $group, $window, &$best, &$answered): void {
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
                        }
                    }, "{$group['city_code']} {$window['check_in']}", $errors);
                }

                if ($answered === 0) {
                    // Nothing answered: keep what the hotels had, never mark them "no offer".
                    continue;
                }
                $counts = $hotelRepo->applyAvailability($group['tourop_code'], $group['city_code'], $best, $now);
                foreach ($counts as $k => $n) {
                    $sum[$k] += $n;
                }
                $synced += $group['hotels'];
                $answeredCities[] = $group['city_code'];

                $cityRow = $cityRepo->findByCode($group['city_code']);
                $this->output(self::cityLine(
                    $group['city_code'],
                    $cityRow !== null ? TypeCoerce::toString($cityRow['name'] ?? '') : '',
                    $counts,
                ));
            }

            $gate = ['hidden' => 0, 'shown' => 0, 'released' => 0];
            if (ConfigProvider::hideUnavailableProducts()) {
                $gate = (new ProductGate())->apply($answeredCities);
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
