<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Cron\Commands;

use Tygh\Addons\Eurosite\Services\Container;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * mode `update_products` — keeps the Eurosite products current: the price
 * follows the latest availability check, a product still missing its
 * description or pictures gets them once the hotel details have them, and
 * its features (Feature Mappings: stars, board, facilities…) are assigned.
 * Details older than 30 days are fetched again first. What an admin edited
 * (name, category, a description they wrote) is left alone.
 *
 * Least recently refreshed first, so a capped run still reaches every
 * product in turn. `&city=CODE` · `&limit=N` (default 200).
 */
final class UpdateProductsCommand extends AbstractSyncCommand
{
    private const DEFAULT_LIMIT = 200;

    private const FRESH_DAYS = 30;

    #[\Override]
    public static function getModes(): array
    {
        return ['update_products'];
    }

    #[\Override]
    public static function getDescription(): string
    {
        return 'Refresh the Eurosite products: price, description, pictures and features (&city=CODE, &limit=N)';
    }

    #[\Override]
    public function execute(array $params = []): array
    {
        return $this->runLogged('update_products', function () use ($params): array {
            $only = strtoupper(trim(TypeCoerce::toString($params['city'] ?? '')));
            $limit = max(1, TypeCoerce::toInt($params['limit'] ?? self::DEFAULT_LIMIT));

            $hotelRepo = Container::hotels();
            $cache = Container::productInfoCache();
            $api = Container::getApi();
            $factory = Container::productFactory();

            $rows = $hotelRepo->getLinked($only, $limit);
            $changed = ['price' => 0, 'description' => 0, 'images' => 0, 'features' => 0];
            $synced = 0;
            $errors = [];
            foreach ($rows as $hotel) {
                $tourop = TypeCoerce::toString($hotel['tourop_code'] ?? '');
                $code = TypeCoerce::toString($hotel['product_code'] ?? '');
                $this->trySyncItem(function () use ($hotel, $tourop, $code, $hotelRepo, $cache, $api, $factory, &$changed, &$synced): void {
                    if (!$cache->isFresh($tourop, $code, self::FRESH_DAYS)) {
                        $country = TypeCoerce::toString($hotel['country_code'] ?? '');
                        $city = TypeCoerce::toString($hotel['city_code'] ?? '');
                        $cache->put($tourop, $code, $country, $city, $api->getProductInfo($country, $city, $code, 'hotel', $tourop));
                        $hotel = $hotelRepo->findWithDetails($tourop, $code) ?? $hotel;
                    }
                    foreach ($factory->refresh($hotel) as $what) {
                        $changed[$what] = ($changed[$what] ?? 0) + 1;
                    }
                    $synced++;
                }, "product {$code}", $errors);
            }

            $this->output(sprintf(
                '  %d products checked: %d new prices, %d descriptions, %d with pictures added, %d with features',
                $synced,
                $changed['price'],
                $changed['description'],
                $changed['images'],
                $changed['features'],
            ));

            return [
                'total' => count($rows),
                'synced' => $synced,
                'failed' => count($errors),
                'prices' => $changed['price'],
                'descriptions' => $changed['description'],
                'images' => $changed['images'],
                'features' => $changed['features'],
                'error' => implode('; ', array_slice($errors, 0, 5)),
            ];
        });
    }
}
