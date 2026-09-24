<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Services;

use Tygh\Addons\Eurosite\Api\EurositeApiClient;
use Tygh\Addons\Eurosite\Repository\HotelRepository;
use Tygh\Addons\Eurosite\Repository\ProductInfoCacheRepository;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Turns a set of hotels into products: the `add_products` job and the hotel
 * list's "Create products" both come through here, so they skip the same
 * hotels for the same reasons.
 *
 * A hotel whose details (pictures, description) were never fetched gets
 * them fetched first — only for the hotels about to become products, which
 * is cheaper than raising the `product_info` job's daily cap for all of
 * them. Only then is "no images" decided.
 */
final class HotelProductService
{
    public function __construct(
        private readonly HotelRepository $hotels,
        private readonly ProductInfoCacheRepository $cache,
        private readonly EurositeApiClient $api,
        private readonly EurositeProductFactory $factory,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $hotels HotelRepository details rows
     * @param int $limit stop after this many products were created (0 = no cap)
     * @param callable(string): void|null $output
     *
     * @return array{added: int, linked: int, would_create: int, failed: int, skipped: array<string, int>, details_fetched: int, errors: list<string>}
     */
    public function createFor(array $hotels, bool $dryRun = false, int $limit = 0, ?callable $output = null): array
    {
        $allowWithoutImages = ConfigProvider::allowProductsWithoutImages();
        $result = ['added' => 0, 'linked' => 0, 'would_create' => 0, 'failed' => 0, 'skipped' => [], 'details_fetched' => 0, 'errors' => []];

        foreach ($hotels as $hotel) {
            if ($limit > 0 && $result['added'] + $result['linked'] + $result['would_create'] >= $limit) {
                break;
            }
            $tourop = TypeCoerce::toString($hotel['tourop_code'] ?? '');
            $code = TypeCoerce::toString($hotel['product_code'] ?? '');

            // Everything but the pictures first: no point fetching details
            // for a hotel that cannot become a product anyway.
            $reason = EurositeProductFactory::skipReason($hotel, true);
            if ($reason === '' && !$dryRun && TypeCoerce::toString($hotel['info_fetched_at'] ?? '') === '') {
                try {
                    $info = $this->api->getProductInfo(
                        TypeCoerce::toString($hotel['country_code'] ?? ''),
                        TypeCoerce::toString($hotel['city_code'] ?? ''),
                        $code,
                        'hotel',
                        $tourop,
                    );
                    $this->cache->put($tourop, $code, TypeCoerce::toString($hotel['country_code'] ?? ''), TypeCoerce::toString($hotel['city_code'] ?? ''), $info);
                    $result['details_fetched']++;
                    $hotel = $this->hotels->findWithDetails($tourop, $code) ?? $hotel;
                } catch (\Throwable $e) {
                    // Not fatal: the cover image may still be enough.
                    $result['errors'][] = "{$code} details: " . $e->getMessage();
                }
            }
            if ($reason === '') {
                $reason = EurositeProductFactory::skipReason($hotel, $allowWithoutImages);
                if ($reason === 'no_images' && !$dryRun) {
                    $this->hotels->setSkipReason($tourop, $code, 'no_images');
                }
            }
            if ($reason !== '') {
                $result['skipped'][$reason] = ($result['skipped'][$reason] ?? 0) + 1;
                continue;
            }

            if ($dryRun) {
                $result['would_create']++;
                if ($output !== null) {
                    $output('  would create ' . EurositeProductFactory::productCode($tourop, $code) . ' ' . EurositeProductFactory::displayName(TypeCoerce::toString($hotel['name'] ?? '')));
                }
                continue;
            }

            try {
                $made = $this->factory->create($hotel);
            } catch (\Throwable $e) {
                $made = ['status' => 'failed', 'product_id' => 0, 'reason' => $e->getMessage()];
            }
            match ($made['status']) {
                'added' => $result['added']++,
                'linked' => $result['linked']++,
                'skipped' => $result['skipped'][$made['reason']] = ($result['skipped'][$made['reason']] ?? 0) + 1,
                default => $result['failed']++,
            };
            if ($made['status'] === 'failed') {
                $result['errors'][] = "{$code}: {$made['reason']}";
            }
            if ($output !== null && in_array($made['status'], ['added', 'linked'], true)) {
                $output(sprintf(
                    '  %s %s → product #%d',
                    $made['status'] === 'added' ? 'created' : 'linked',
                    EurositeProductFactory::productCode($tourop, $code),
                    $made['product_id'],
                ));
            }
            if (isset($result['skipped']['no_root_category'])) {
                break; // every other hotel would stop at the same missing setting
            }
        }

        return $result;
    }
}
