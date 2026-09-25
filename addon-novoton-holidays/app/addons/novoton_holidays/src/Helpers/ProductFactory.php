<?php

declare(strict_types=1);

/**
 * Novoton Holidays - Product Factory
 *
 * Creates CS-Cart products from Novoton hotel data.
 * Single Responsibility: hotel-to-product conversion, image attachment, title building.
 *
 * @package NovotonHolidays
 * @since 3.3.0
 */

namespace Tygh\Addons\NovotonHolidays\Helpers;

use Tygh\Addons\NovotonHolidays\Api\Contracts\HotelApiClientInterface;
use Tygh\Addons\NovotonHolidays\Constants;
use Tygh\Addons\NovotonHolidays\Exceptions\ApiException;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

class ProductFactory implements ProductFactoryInterface
{
    private DatabaseHelperInterface $dbHelper;

    public function __construct(DatabaseHelperInterface $dbHelper)
    {
        $this->dbHelper = $dbHelper;
    }

    /**
     * Create CS-Cart product from hotel data
     *
     * @param array<string, mixed> $hotel Hotel data array
     * @param HotelApiClientInterface $api Narrow hotel sub-client
     * @param int $categoryId Category to assign product to
     * @return int|null Product ID or null on failure
     */
    public function createFromHotel(array $hotel, HotelApiClientInterface $api, int $categoryId): ?int
    {
        $hotelId = TypeCoerce::toString($hotel['hotel_id'] ?? '');
        $hotelName = TypeCoerce::toString($hotel['hotel_name'] ?? '');

        $productCode = $this->dbHelper->getProductCode($hotelId);

        // Check if product already exists
        $existingProductId = db_get_field(
            'SELECT product_id FROM ?:products WHERE product_code = ?s',
            $productCode,
        );

        if ($existingProductId) {
            db_query(
                'UPDATE ?:novoton_hotels SET product_id = ?i WHERE hotel_id = ?s',
                $existingProductId,
                $hotelId,
            );
            return TypeCoerce::toInt($existingProductId);
        }

        // Format display name (Title Case + append property type for short names)
        $displayName = TypeCoerce::toString(
            function_exists('fn_novoton_holidays_format_hotel_display_name')
                ? fn_novoton_holidays_format_hotel_display_name($hotelName)
                : $hotelName,
        );

        // Fetch description
        $description = '';
        try {
            $descResponse = $api->getHotelDescription($hotelId, 'UK');
            if (isset($descResponse->Description)) {
                $description = (string)$descResponse->Description;
            }
        } catch (ApiException) {
            // Ignore description fetch errors
        }

        // Build placeholder map and apply SEO templates (respects overwrite mode + field toggles)
        $placeholders = self::buildNovotonPlaceholders($hotel, $displayName, $description);
        $seoFields = fn_travel_core_apply_seo_fields('novoton_holidays', $placeholders, 0, $hotelId);

        $productData = array_merge([
            'product_code' => $productCode,
            'price' => 0,
            'amount' => ConfigProvider::getDefaultProductQuantity(),
            'status' => 'D',
            'company_id' => ConfigProvider::getCompanyId(),
            'main_category' => $categoryId,
            'category_ids' => [$categoryId],
        ], $seoFields);

        // Fall back to the formatted display name when no SEO product-name
        // template is configured. Without a name, fn_update_product() fails.
        $productName = $productData['product'] ?? '';
        if (!is_string($productName) || trim($productName) === '') {
            $productData['product'] = $displayName;
        }

        $productId = TypeCoerce::toInt(fn_update_product($productData, 0, CART_LANGUAGE));

        if ($productId === 0) {
            return null;
        }

        // CS-Cart replicated the CART_LANGUAGE render into every language row
        // on create — re-render the OTHER storefront languages with their own
        // template sets so RO/EN carry their own SEO copy from day one.
        fn_travel_core_seo_localize(
            'novoton_holidays',
            $placeholders,
            $productId,
            $hotelId,
            self::otherStorefrontLanguages(TypeCoerce::toString(CART_LANGUAGE)),
        );

        // Link product to hotel
        db_query(
            'UPDATE ?:novoton_hotels SET product_id = ?i WHERE hotel_id = ?s',
            $productId,
            $hotelId,
        );

        // Attach images
        $this->attachHotelImages($productId, $hotelId, $api);

        // Sync facilities
        try {
            if (function_exists('fn_novoton_holidays_sync_hotel_facilities')) {
                fn_novoton_holidays_sync_hotel_facilities($hotelId);
            }
        } catch (\Exception) {
            // Ignore facility sync errors
        }

        return $productId;
    }

    /**
     * Attach images to product from API
     *
     * @return int Number of images attached
     */
    public function attachHotelImages(int $productId, string $hotelId, HotelApiClientInterface $api): int
    {
        try {
            $imagesResponse = $api->getHotelImages($hotelId);

            if (!isset($imagesResponse->url)) {
                return 0;
            }

            $imgCount = 0;
            $maxImages = ConfigProvider::MAX_IMAGES_PER_HOTEL;

            foreach ($imagesResponse->url as $url) {
                $imageUrl = Constants::IMAGE_BASE_URL . str_replace(' ', '%20', (string)$url);

                if (function_exists('fn_novoton_holidays_add_product_image')) {
                    fn_novoton_holidays_add_product_image($productId, $imageUrl, $imgCount === 0);
                }

                $imgCount++;
                if ($imgCount >= $maxImages) {
                    break;
                }
            }

            return $imgCount;
        } catch (ApiException) {
            return 0;
        }
    }

    /**
     * Build hotel title for SEO
     */
    public static function buildHotelTitle(string $hotelName, string $city, string $country, string $year): string
    {
        if (function_exists('fn_novoton_holidays_build_hotel_title')) {
            return fn_novoton_holidays_build_hotel_title($hotelName, $city, $country, $year);
        }

        $parts = array_filter([$hotelName, $city, $country, $year]);
        return implode(' - ', $parts);
    }

    /**
     * Get or create category by path
     */
    public static function getOrCreateCategory(string $categoryPath): int
    {
        if (function_exists('fn_novoton_holidays_get_or_create_category')) {
            return fn_novoton_holidays_get_or_create_category($categoryPath);
        }

        return 1;
    }

    /**
     * Build the placeholder map for SEO template rendering from Novoton hotel data.
     *
     * @param array<string, mixed> $hotel Hotel row from novoton_hotels table
     * @param string $displayName Formatted display name (Title Case, with property type)
     * @param string $description API description text
     * @return array<string, string|list<string>> Key => value map (keys without braces)
     */
    public static function buildNovotonPlaceholders(array $hotel, string $displayName, string $description = ''): array
    {
        // Load facility names from DB
        $facilities = [];
        if (!empty($hotel['hotel_id'])) {
            $facilities = TypeCoerce::toStringList(db_get_fields(
                "SELECT f.facility_name_en FROM ?:novoton_hotel_facilities hf
                 JOIN ?:novoton_facilities f ON f.facility_id = hf.facility_id
                 WHERE hf.hotel_id = ?s AND f.facility_name_en != ''
                 LIMIT 5",
                TypeCoerce::toString($hotel['hotel_id']),
            ));
        }

        return [
            'name' => $displayName,
            'raw_name' => TypeCoerce::toString($hotel['hotel_name'] ?? ''),
            'city' => TypeCoerce::toString($hotel['city'] ?? ''),
            'country' => TypeCoerce::toString($hotel['country'] ?? ''),
            'region' => TypeCoerce::toString($hotel['region'] ?? ''),
            'star_rating' => TypeCoerce::toString($hotel['star_rating'] ?? ''),
            'stars_emoji' => fn_travel_core_build_star_emoji(TypeCoerce::toInt($hotel['star_rating'] ?? 0)),
            'hotel_type' => TypeCoerce::toString($hotel['hotel_type'] ?? ''),
            'property_type' => TypeCoerce::toString($hotel['property_type'] ?? 'hotel'),
            'year' => date('Y'),
            'description' => $description,
            'facilities' => $facilities,
            'latitude' => TypeCoerce::toString($hotel['latitude'] ?? ''),
            'longitude' => TypeCoerce::toString($hotel['longitude'] ?? ''),
        ];
    }

    /**
     * Every storefront language except the one a product was just created in
     * — the set whose SEO copy still needs its own per-language render.
     *
     * @return list<string>
     */
    public static function otherStorefrontLanguages(string $primaryLang): array
    {
        $languages = [];
        if (function_exists('fn_get_translation_languages')) {
            $all = fn_get_translation_languages();
            if (is_array($all)) {
                $languages = array_map(strval(...), array_keys($all));
            }
        }

        return array_values(array_diff($languages, [$primaryLang]));
    }
}
