<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Cron\Commands;

use Tygh\Addons\Eurosite\Services\Container;

/**
 * mode `own_cities` — getOwnCityRequest → ?:eurosite_cities with is_own='Y'
 * (116 rows live). One cross-country list; each row carries its country, so
 * this also seeds countries into the city table before the full cities sync.
 */
final class OwnCitiesSyncCommand extends AbstractSyncCommand
{
    #[\Override]
    public static function getModes(): array
    {
        return ['own_cities'];
    }

    #[\Override]
    public static function getDescription(): string
    {
        return "Sync cities with own offers (getOwnCityRequest; marks is_own='Y')";
    }

    #[\Override]
    public function execute(array $params = []): array
    {
        return $this->runLogged('own_cities', function (): array {
            $cities = Container::getApi()->getOwnCities();
            $synced = Container::cities()->upsertBatch($cities, '', true);

            return ['total' => count($cities), 'synced' => $synced, 'failed' => 0];
        });
    }
}
