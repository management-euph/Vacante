<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Cron\Commands;

use Tygh\Addons\Eurosite\Services\Container;

/**
 * mode `countries` — getCountryRequest → ?:eurosite_countries (86 rows live).
 */
final class CountriesSyncCommand extends AbstractSyncCommand
{
    #[\Override]
    public static function getModes(): array
    {
        return ['countries'];
    }

    #[\Override]
    public static function getDescription(): string
    {
        return 'Sync the country catalog (getCountryRequest)';
    }

    #[\Override]
    public function execute(array $params = []): array
    {
        return $this->runLogged('countries', function (): array {
            $countries = Container::getApi()->getCountries();
            $synced = Container::countries()->upsertBatch($countries);

            return ['total' => count($countries), 'synced' => $synced, 'failed' => 0];
        });
    }
}
