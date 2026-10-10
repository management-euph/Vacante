<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Cron\Commands;

use Tygh\Addons\Eurosite\Services\Container;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * mode `product_info` — warm ?:eurosite_product_info_cache for synced hotels
 * (the spec mandates an own cache for getProductInfoRequest).
 *
 * Fetches only hotels whose cache entry is missing or stale (30 days;
 * `&full=1` refetches everything, `&limit=N` caps API calls per run,
 * default 100).
 */
final class ProductInfoCacheCommand extends AbstractSyncCommand
{
    private const DEFAULT_LIMIT = 100;

    private const FRESH_DAYS = 30;

    #[\Override]
    public static function getModes(): array
    {
        return ['product_info'];
    }

    #[\Override]
    public static function getDescription(): string
    {
        return 'Warm the product-details cache for synced hotels (getProductInfoRequest; &limit=N, &full=1)';
    }

    #[\Override]
    public function execute(array $params = []): array
    {
        return $this->runLogged('product_info', function () use ($params): array {
            $limit = max(1, TypeCoerce::toInt($params['limit'] ?? self::DEFAULT_LIMIT));
            $force = !empty($params['full']);

            $api = Container::getApi();
            $cache = Container::productInfoCache();
            $rows = db_get_array(
                "SELECT tourop_code, product_code, city_code, country_code
                 FROM ?:eurosite_hotels WHERE sync_status = 'active' ORDER BY last_synced_at DESC",
            );
            $rows = is_array($rows) ? $rows : [];

            $total = 0;
            $synced = 0;
            $skipped = 0;
            $errors = [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                if ($synced + count($errors) >= $limit) {
                    break;
                }
                $tourop = TypeCoerce::toString($row['tourop_code'] ?? '');
                $product = TypeCoerce::toString($row['product_code'] ?? '');
                $country = TypeCoerce::toString($row['country_code'] ?? '');
                $city = TypeCoerce::toString($row['city_code'] ?? '');
                $total++;
                if (!$force && $cache->isFresh($tourop, $product, self::FRESH_DAYS)) {
                    $skipped++;
                    continue;
                }
                $this->trySyncItem(function () use ($api, $cache, $tourop, $product, $country, $city, &$synced): void {
                    $info = $api->getProductInfo($country, $city, $product, 'hotel', $tourop);
                    $cache->put($tourop, $product, $country, $city, $info);
                    $synced++;
                }, "product {$product}", $errors);
            }

            return [
                'total' => $total,
                'synced' => $synced,
                'skipped' => $skipped,
                'failed' => count($errors),
                'error' => implode('; ', array_slice($errors, 0, 5)),
            ];
        });
    }
}
