<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Cron\Commands;

use Tygh\Addons\Eurosite\Services\Container;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * mode `hotels` — getOwnHotelsRequest per target city → ?:eurosite_hotels.
 *
 * Target cities = the whitelist-allowed cities, and nothing else. It used to
 * add every own-offer city (is_own='Y') too, which is how a store with one
 * whitelisted country ended up with 1,202 hotels from 111 destinations: the
 * hotel list, the availability check and the products would all have
 * worked through destinations nobody chose. An empty whitelist syncs
 * nothing and says so.
 *
 * After a full run, hotels whose destination is no longer whitelisted are
 * hidden, not deleted (sync_status 'inactive', reason 'not_whitelisted'):
 * whitelist the destination again and the next run brings them back with
 * their product link intact.
 *
 * Each hotel row stores its own Touropcode ("LA" live) — the code
 * search/booking payloads must use. `&city=CODE` narrows to one whitelisted
 * city ad hoc.
 */
final class HotelsSyncCommand extends AbstractSyncCommand
{
    #[\Override]
    public static function getModes(): array
    {
        return ['hotels'];
    }

    #[\Override]
    public static function getDescription(): string
    {
        return 'Sync own hotels + rooms for the whitelisted destinations (getOwnHotelsRequest; &city=CODE for one)';
    }

    #[\Override]
    public function execute(array $params = []): array
    {
        return $this->runLogged('hotels', function () use ($params): array {
            $only = strtoupper(trim(TypeCoerce::toString($params['city'] ?? '')));
            $allowed = $this->allowedCityCodes();
            if ($allowed === []) {
                throw new \RuntimeException(Container::whitelist()->count() === 0
                    ? 'No destinations are whitelisted, so there are no hotels to sync. '
                        . 'Configure the whitelist first (Eurosite > Destination whitelist).'
                    : 'The whitelisted countries have no synced cities yet. Run the cities sync first.');
            }
            if ($only !== '' && !in_array($only, $allowed, true)) {
                throw new \RuntimeException("City {$only} is not whitelisted. Whitelist it first.");
            }
            $cities = $only !== '' ? [$only] : $allowed;

            $api = Container::getApi();
            $hotelRepo = Container::hotels();
            $cityRepo = Container::cities();
            $total = 0;
            $synced = 0;
            $errors = [];
            foreach ($cities as $cityCode) {
                $country = '';
                $cityName = '';
                $cityRow = $cityRepo->findByCode($cityCode);
                if ($cityRow !== null) {
                    $country = TypeCoerce::toString($cityRow['country_code'] ?? '');
                    $cityName = TypeCoerce::toString($cityRow['name'] ?? '');
                }
                $this->trySyncItem(function () use ($api, $hotelRepo, $cityCode, $cityName, $country, &$total, &$synced): void {
                    $hotels = $api->getOwnHotels($cityCode);
                    $total += count($hotels);
                    $synced += $hotelRepo->upsertBatch($hotels, $country);
                    if ($hotels !== []) {
                        $this->output(self::cityLine($cityCode, $cityName, count($hotels)));
                    }
                }, "city {$cityCode}", $errors);
            }

            // Only after a full run: a one-city run says nothing about the others.
            $hidden = $only === '' ? $hotelRepo->deactivateOutside($allowed) : 0;
            if ($hidden > 0) {
                $this->output("  {$hidden} hotels outside the whitelist hidden (kept; they return once their destination is whitelisted)");
            }

            return [
                'total' => $total,
                'synced' => $synced,
                'failed' => count($errors),
                'hidden_not_whitelisted' => $hidden,
                'error' => implode('; ', array_slice($errors, 0, 5)),
            ];
        });
    }

    /**
     * One line of the run report: "  Techirghiol RO0218: 1 hotels". The code
     * stays, since that is what the whitelist and the API use; the name is
     * what an operator recognises. A city not yet synced by `cities` has no
     * name, and prints its code alone.
     */
    public static function cityLine(string $cityCode, string $cityName, int $count): string
    {
        $cityName = trim($cityName);
        $label = $cityName === '' || strcasecmp($cityName, $cityCode) === 0 ? $cityCode : "{$cityName} {$cityCode}";

        return "  {$label}: {$count} hotels";
    }

    /**
     * Every whitelisted city: a country's specific rows, or all its synced
     * cities when the country is whitelisted whole.
     *
     * @return list<string>
     */
    private function allowedCityCodes(): array
    {
        $whitelist = Container::whitelist();
        $codes = [];
        foreach ($whitelist->getCountryCodes() as $country) {
            foreach ($whitelist->getAllowedCityCodes($country) as $code) {
                $codes[$code] = true;
            }
        }

        return array_map('strval', array_keys($codes));
    }
}
