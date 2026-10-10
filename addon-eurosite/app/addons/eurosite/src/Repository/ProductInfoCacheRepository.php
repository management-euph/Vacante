<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Repository;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Repository\RowNarrowingTrait;

/**
 * ?:eurosite_product_info_cache — the spec-mandated local cache for
 * getProductInfoRequest ("Pentru limitarea traficului recomandam existenta
 * unui cache dinamic propriu pentru aceste detalii").
 */
class ProductInfoCacheRepository
{
    use RowNarrowingTrait;

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $touropCode, string $productCode): ?array
    {
        $row = self::asRow(db_get_row(
            'SELECT * FROM ?:eurosite_product_info_cache WHERE tourop_code = ?s AND product_code = ?s',
            $touropCode,
            $productCode,
        ));

        return $row === [] ? null : $row;
    }

    /**
     * @param array<string, mixed> $info getProductInfo() result
     */
    public function put(string $touropCode, string $productCode, string $countryCode, string $cityCode, array $info): void
    {
        db_query(
            'INSERT INTO ?:eurosite_product_info_cache
                (tourop_code, product_code, country_code, city_code, payload_json, description, pictures_json, fetched_at)
             VALUES (?s, ?s, ?s, ?s, ?s, ?s, ?s, NOW())
             ON DUPLICATE KEY UPDATE
                country_code = VALUES(country_code), city_code = VALUES(city_code),
                payload_json = VALUES(payload_json), description = VALUES(description),
                pictures_json = VALUES(pictures_json), fetched_at = VALUES(fetched_at)',
            $touropCode,
            $productCode,
            $countryCode,
            $cityCode,
            (string) json_encode($info, JSON_UNESCAPED_UNICODE),
            TypeCoerce::toString($info['description'] ?? ''),
            (string) json_encode($info['pictures'] ?? [], JSON_UNESCAPED_UNICODE),
        );
    }

    public function isFresh(string $touropCode, string $productCode, int $maxAgeDays = 30): bool
    {
        $fetchedAt = TypeCoerce::toString(db_get_field(
            'SELECT fetched_at FROM ?:eurosite_product_info_cache WHERE tourop_code = ?s AND product_code = ?s',
            $touropCode,
            $productCode,
        ));
        if ($fetchedAt === '') {
            return false;
        }

        return strtotime($fetchedAt) > (time() - $maxAgeDays * 86400);
    }

    public function count(): int
    {
        return TypeCoerce::toInt(db_get_field('SELECT COUNT(*) FROM ?:eurosite_product_info_cache'));
    }
}
