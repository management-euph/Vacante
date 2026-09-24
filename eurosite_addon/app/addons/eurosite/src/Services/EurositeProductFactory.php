<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Services;

use Tygh\Addons\Eurosite\Repository\HotelRepository;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Makes CS-Cart products from Eurosite hotels, and keeps them current.
 *
 * Product code: EUS-<tour operator>-<hotel code>, e.g. EUS-LA-RO0363. The
 * prefix cannot collide with Sphinx (<ISO2><id>, e.g. HR59843 — and a bare
 * "RO0363" would read as a Sphinx Romanian hotel) or Novoton (NVT<id>), and
 * the tour operator is part of it because a Eurosite hotel code is unique
 * only per operator. One product per hotel, even when the price search lists
 * the hotel under two destinations.
 *
 * Category: [root category setting] › country › destination, created on
 * demand. Name, description and pictures come from the hotel details cache
 * (getProductInfoRequest), the price from the lowest Immediate offer the
 * availability check found, converted to the store's primary currency.
 *
 * Only Immediate hotels become products (skipReason()); the static parts are
 * pure and unit-tested, create() / refresh() are the CS-Cart boundary.
 */
final class EurositeProductFactory
{
    public const CODE_PREFIX = 'EUS-';

    /** Skip reasons, as stored in eurosite_hotels.product_skip_reason and shown in the list. */
    public const SKIP_REASONS = [
        'already_product', 'not_whitelisted', 'not_checked', 'on_request', 'stop_sale', 'no_offer',
        'no_images', 'no_root_category', 'category_failed', 'creation_failed',
    ];

    /** @var array<string, int> "root/country/city" => category_id */
    private array $categoryCache = [];

    /** @var array<string, float> currency => coefficient to the primary currency */
    private array $coefficients = [];

    public function __construct(private readonly HotelRepository $hotels)
    {
    }

    public static function productCode(string $tourop, string $hotelCode): string
    {
        return self::CODE_PREFIX . strtoupper(trim($tourop)) . '-' . strtoupper(trim($hotelCode));
    }

    /**
     * "EUS-LA-RO0363" → ['LA', 'RO0363']; null for anything that is not ours.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function parseProductCode(string $productCode): ?array
    {
        if (preg_match('/^EUS-([A-Z0-9]+)-([A-Z0-9_]+)$/', strtoupper(trim($productCode)), $m) !== 1) {
            return null;
        }

        return [$m[1], $m[2]];
    }

    /**
     * The store saves some names HTML-escaped ("EAGLE&#039;S NEST"); a
     * product name must read as the hotel does.
     */
    public static function displayName(string $name): string
    {
        return trim(html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * The hotel's pictures: the details cache first, the price search's
     * cover image when the details have none.
     *
     * @param array<string, mixed> $hotel
     *
     * @return list<string>
     */
    public static function pictures(array $hotel): array
    {
        $decoded = json_decode(TypeCoerce::toString($hotel['pictures_json'] ?? ''), true);
        $urls = [];
        foreach (is_array($decoded) ? $decoded : [] as $url) {
            $url = trim(TypeCoerce::toString($url));
            if ($url !== '' && preg_match('#^https?://#i', $url) === 1) {
                $urls[$url] = true;
            }
        }
        $cover = trim(TypeCoerce::toString($hotel['first_image'] ?? ''));
        if ($urls === [] && $cover !== '' && preg_match('#^https?://#i', $cover) === 1) {
            $urls[$cover] = true;
        }

        return array_map('strval', array_keys($urls));
    }

    /**
     * The image state the hotel list shows and sorts by.
     *
     * @param array<string, mixed> $hotel
     *
     * @return array{state: string, count: int} state: pictures | cover | none | not_fetched
     */
    public static function imageState(array $hotel): array
    {
        $decoded = json_decode(TypeCoerce::toString($hotel['pictures_json'] ?? ''), true);
        $count = is_array($decoded) ? count($decoded) : 0;
        $hasCover = trim(TypeCoerce::toString($hotel['first_image'] ?? '')) !== '';
        $fetched = TypeCoerce::toString($hotel['info_fetched_at'] ?? '') !== '';

        return match (true) {
            $count > 0 => ['state' => 'pictures', 'count' => $count],
            $hasCover => ['state' => 'cover', 'count' => 1],
            $fetched => ['state' => 'none', 'count' => 0],
            default => ['state' => 'not_fetched', 'count' => 0],
        };
    }

    /**
     * Why this hotel must not become a product; '' when it may.
     *
     * Order matters: the first reason is the one the list shows. "No images"
     * is decided only once the details were fetched — before that, the
     * product step fetches them first.
     *
     * @param array<string, mixed> $hotel
     */
    public static function skipReason(array $hotel, bool $allowWithoutImages): string
    {
        if (TypeCoerce::toInt($hotel['product_id'] ?? 0) > 0) {
            return 'already_product';
        }
        if (TypeCoerce::toString($hotel['sync_status'] ?? 'active') !== 'active') {
            return 'not_whitelisted';
        }
        $reason = match (TypeCoerce::toString($hotel['availability'] ?? '')) {
            'IM' => '',
            'OR' => 'on_request',
            'ST' => 'stop_sale',
            'NONE' => 'no_offer',
            default => 'not_checked',
        };
        if ($reason !== '') {
            return $reason;
        }
        if (!$allowWithoutImages && self::pictures($hotel) === []) {
            return 'no_images';
        }

        return '';
    }

    /** Offer price → store price. A currency the store does not know is taken as is. */
    public static function toStorePrice(float $price, float $coefficient): float
    {
        return $coefficient > 0 ? round($price * $coefficient, 2) : round($price, 2);
    }

    /**
     * SEO template placeholders ({{name}}, {{city}}, …).
     *
     * @param array<string, mixed> $hotel
     *
     * @return array<string, string>
     */
    public static function placeholders(array $hotel): array
    {
        $stars = TypeCoerce::toInt($hotel['category'] ?? 0);

        return [
            'name' => self::displayName(TypeCoerce::toString($hotel['name'] ?? '')),
            'classification' => $stars > 0 ? (string) $stars : '',
            'stars_emoji' => $stars > 0 ? str_repeat('★', min(5, $stars)) : '',
            'city' => TypeCoerce::toString($hotel['city_name'] ?? '') ?: TypeCoerce::toString($hotel['city_code'] ?? ''),
            'country' => TypeCoerce::toString($hotel['country_name'] ?? '') ?: TypeCoerce::toString($hotel['country_code'] ?? ''),
            'property_type' => 'hotel',
            'description' => TypeCoerce::toString($hotel['description'] ?? ''),
            'image_url' => self::pictures($hotel)[0] ?? '',
            'year' => date('Y'),
        ];
    }

    /**
     * Create the product for one hotel (the caller has checked skipReason()).
     *
     * @param array<string, mixed> $hotel a HotelRepository details row
     *
     * @return array{status: string, product_id: int, reason: string} status: added | linked | skipped | failed
     */
    public function create(array $hotel): array
    {
        $tourop = TypeCoerce::toString($hotel['tourop_code'] ?? '');
        $code = TypeCoerce::toString($hotel['product_code'] ?? '');
        $productCode = self::productCode($tourop, $code);

        // A product with this code already exists (a re-run after a partial
        // failure, or a product restored by hand): link it, never duplicate.
        $existing = TypeCoerce::toInt(db_get_field('SELECT product_id FROM ?:products WHERE product_code = ?s', $productCode));
        if ($existing > 0) {
            $this->hotels->linkProduct($tourop, $code, $existing);

            return ['status' => 'linked', 'product_id' => $existing, 'reason' => 'existing'];
        }

        $root = ConfigProvider::getHotelsCategoryId();
        if ($root <= 0) {
            $this->hotels->setSkipReason($tourop, $code, 'no_root_category');

            return ['status' => 'skipped', 'product_id' => 0, 'reason' => 'no_root_category'];
        }
        $placeholders = self::placeholders($hotel);
        $categoryId = $this->category($root, $placeholders['country'], $placeholders['city']);
        if ($categoryId <= 0) {
            $this->hotels->setSkipReason($tourop, $code, 'category_failed');

            return ['status' => 'failed', 'product_id' => 0, 'reason' => 'category_failed'];
        }

        $languages = self::languages();
        $primary = $languages[0];
        $seo = fn_travel_core_apply_seo_fields('eurosite', $placeholders, 0, $productCode, $primary);
        $name = TypeCoerce::toString($seo['product'] ?? '');
        $data = array_merge([
            'product_code' => $productCode,
            'price' => $this->storePrice($hotel),
            // A hotel is not stock: rooms are booked through the API, and a
            // count of 0 would make CS-Cart show it as out of stock.
            'amount' => 999,
            'status' => 'A',
            'company_id' => ConfigProvider::getCompanyId(),
            'main_category' => $categoryId,
            'category_ids' => [$categoryId],
            'full_description' => $placeholders['description'],
        ], $seo);
        $data['product'] = trim($name) !== '' ? $name : $placeholders['name'];

        $productId = TypeCoerce::toInt(fn_update_product($data, 0, $primary));
        if ($productId <= 0) {
            fn_log_event('general', 'runtime', ['message' => "Eurosite: product creation failed for {$productCode}"]);
            $this->hotels->setSkipReason($tourop, $code, 'creation_failed');

            return ['status' => 'failed', 'product_id' => 0, 'reason' => 'creation_failed'];
        }

        // The other storefront languages start from the same name and text,
        // then get their own SEO render.
        $others = array_values(array_diff($languages, [$primary]));
        foreach ($others as $lang) {
            db_query(
                'INSERT INTO ?:product_descriptions (product_id, lang_code, product, full_description)
                 VALUES (?i, ?s, ?s, ?s)
                 ON DUPLICATE KEY UPDATE product = VALUES(product), full_description = VALUES(full_description)',
                $productId,
                $lang,
                $data['product'],
                TypeCoerce::toString($data['full_description']),
            );
        }
        if ($others !== []) {
            fn_travel_core_seo_localize('eurosite', $placeholders, $productId, $productCode, $others);
        }

        $this->hotels->linkProduct($tourop, $code, $productId);

        $pictures = self::pictures($hotel);
        if ($pictures !== []) {
            try {
                fn_travel_core_attach_images_from_urls($productId, $pictures);
            } catch (\Throwable $e) {
                fn_log_event('general', 'runtime', ['message' => "Eurosite: images for {$productCode}: " . $e->getMessage()]);
            }
        }

        return ['status' => 'added', 'product_id' => $productId, 'reason' => ''];
    }

    /**
     * Bring an existing product up to date: price, description (only while
     * the product still has none), and pictures (only while it has none).
     * Name, category and anything else the admin may have edited stay.
     *
     * @param array<string, mixed> $hotel
     *
     * @return list<string> what changed: price | description | images
     */
    public function refresh(array $hotel): array
    {
        $productId = TypeCoerce::toInt($hotel['product_id'] ?? 0);
        $changes = [];
        $product = db_get_row('SELECT product_id FROM ?:products WHERE product_id = ?i', $productId);
        if (!is_array($product) || $product === []) {
            return $changes; // deleted by hand; the link is stale but harmless
        }

        $price = $this->storePrice($hotel);
        if ($price > 0) {
            $current = TypeCoerce::toFloat(db_get_field(
                'SELECT price FROM ?:product_prices WHERE product_id = ?i AND lower_limit = 1 AND usergroup_id = 0',
                $productId,
            ));
            if (abs($current - $price) >= 0.01) {
                // The base price row only: a full fn_update_product() would
                // re-save fields the admin may have edited.
                db_query(
                    'UPDATE ?:product_prices SET price = ?d WHERE product_id = ?i AND lower_limit = 1 AND usergroup_id = 0',
                    $price,
                    $productId,
                );
                $changes[] = 'price';
            }
        }

        $description = TypeCoerce::toString($hotel['description'] ?? '');
        if ($description !== '') {
            $n = TypeCoerce::toInt(db_query(
                "UPDATE ?:product_descriptions SET full_description = ?s
                 WHERE product_id = ?i AND (full_description IS NULL OR full_description = '')",
                $description,
                $productId,
            ));
            if ($n > 0) {
                $changes[] = 'description';
            }
        }

        $images = TypeCoerce::toInt(db_get_field(
            "SELECT COUNT(*) FROM ?:images_links WHERE object_id = ?i AND object_type = 'product'",
            $productId,
        ));
        $pictures = self::pictures($hotel);
        if ($images === 0 && $pictures !== []) {
            if (fn_travel_core_attach_images_from_urls($productId, $pictures) > 0) {
                $changes[] = 'images';
            }
        }

        $this->hotels->touchProductUpdated(
            TypeCoerce::toString($hotel['tourop_code'] ?? ''),
            TypeCoerce::toString($hotel['product_code'] ?? ''),
        );

        return $changes;
    }

    /** @param array<string, mixed> $hotel */
    private function storePrice(array $hotel): float
    {
        $price = TypeCoerce::toFloat($hotel['min_price'] ?? 0);
        if ($price <= 0) {
            return 0.0;
        }
        $currency = TypeCoerce::toString($hotel['price_currency'] ?? '') ?: ConfigProvider::getDefaultCurrency();
        $this->coefficients[$currency] ??= TypeCoerce::toFloat(db_get_field(
            'SELECT coefficient FROM ?:currencies WHERE currency_code = ?s',
            $currency,
        ));

        return self::toStorePrice($price, $this->coefficients[$currency]);
    }

    private function category(int $root, string $country, string $city): int
    {
        $key = $root . '/' . $country . '/' . $city;
        if (!isset($this->categoryCache[$key])) {
            $countryId = fn_travel_core_get_or_create_child_category($root, $country);
            $this->categoryCache[$key] = $countryId > 0 && $city !== ''
                ? fn_travel_core_get_or_create_child_category($countryId, $city)
                : $countryId;
        }

        return $this->categoryCache[$key];
    }

    /**
     * The storefront languages, CART_LANGUAGE first.
     *
     * @return non-empty-list<string>
     */
    private static function languages(): array
    {
        $primary = defined('CART_LANGUAGE') ? TypeCoerce::toString(constant('CART_LANGUAGE')) : 'en';
        $all = TypeCoerce::toStringList(db_get_fields("SELECT lang_code FROM ?:languages WHERE status = 'A'"));

        return [$primary, ...array_values(array_diff($all, [$primary]))];
    }
}
