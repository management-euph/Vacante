<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Cron\Commands;

use Tygh\Addons\Eurosite\Services\Container;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * mode `cities` — getCityRequest per target country → ?:eurosite_cities.
 *
 * The full catalog is ~21,700 cities across 86 countries; syncing is scoped
 * to the whitelist's countries (plus countries carrying own-offer cities) so
 * the table stays proportional to what the store actually sells.
 * `&country=RO` narrows to one country ad hoc.
 */
final class CitiesSyncCommand extends AbstractSyncCommand
{
    #[\Override]
    public static function getModes(): array
    {
        return ['cities'];
    }

    #[\Override]
    public static function getDescription(): string
    {
        return 'Sync city catalogs for whitelisted/own-offer countries (getCityRequest; &country=XX for one)';
    }

    #[\Override]
    public function execute(array $params = []): array
    {
        return $this->runLogged('cities', function () use ($params): array {
            $only = strtoupper(trim(TypeCoerce::toString($params['country'] ?? '')));
            $countries = $only !== '' ? [$only] : $this->targetCountryCodes();

            $api = Container::getApi();
            $repo = Container::cities();
            $total = 0;
            $synced = 0;
            $errors = [];
            foreach ($countries as $country) {
                $ok = $this->trySyncItem(function () use ($api, $repo, $country, &$total, &$synced): void {
                    $cities = $api->getCities($country);
                    $total += count($cities);
                    $synced += $repo->upsertBatch($cities, $country);
                    $this->output("  {$country}: " . count($cities) . ' cities');
                }, "country {$country}", $errors);
                unset($ok);
            }

            return [
                'total' => $total,
                'synced' => $synced,
                'failed' => count($errors),
                'error' => implode('; ', array_slice($errors, 0, 5)),
            ];
        });
    }
}
