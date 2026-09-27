<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Cron\Commands;

use Tygh\Addons\NovotonHolidays\Constants;
use Tygh\Addons\NovotonHolidays\Cron\AbstractCronCommand;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
use Tygh\Addons\NovotonHolidays\Services\Container;
use Tygh\Addons\NovotonHolidays\Services\DestinationScope;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

class OffersUpdateCommand extends AbstractCronCommand
{
    /**
     * @return list<string>
     */
    #[\Override]
    public static function getModes(): array
    {
        return ['offers_update'];
    }

    public static function getDescription(): string
    {
        return 'Check offers_update API for new/changed hotels and add as products';
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(): array
    {
        $this->output('Checking for new/updated offers (offers_update API)...');
        $this->output('');

        $country = strtoupper(TypeCoerce::toString($this->getParam('country', Constants::DEFAULT_COUNTRY)));

        $syncRepo = Container::getInstance()->syncLogRepository();
        $last_import = $syncRepo->getLastSyncDate('product_import');

        if (empty($last_import)) {
            $this->output('ERROR: No previous product import found!');
            $this->output("Run 'Add Hotels as Products' first to establish the baseline timestamp.");
            return ['success' => false, 'error' => 'No baseline import'];
        }

        $this->output("Country: {$country}");
        $this->output("Last product import: {$last_import}");
        $this->output('Checking offers added/modified after this time...');
        $this->output('');

        $response = $this->api->destinations()->getOffersUpdate($last_import, $country);

        if (!(bool) $response || !isset($response->Offer)) {
            $this->output('No new offers found.');
            return ['success' => true, 'stats' => ['new_hotels' => 0, 'added_to_cart' => 0]];
        }

        // $response->Offer is the node set of every <Offer>: iterate it.
        // Wrapping it as [$response->Offer] kept only the first offer.
        $offers = [];
        foreach ($response->Offer as $offerNode) {
            $offers[] = $offerNode;
        }
        $scope = DestinationScope::current();
        $this->output('Found ' . count($offers) . ' offers to check.');
        $this->output('');

        $hotelRepo = Container::getInstance()->hotelRepository();
        $new_hotels = 0;
        $added_to_cart = 0;
        $image_base_url = \Tygh\Addons\NovotonHolidays\Constants::IMAGE_BASE_URL;

        foreach ($offers as $offer) {
            $hotel_id = (string)($offer->IdHotel ?? '');
            $hotel_name = (string)($offer->PackageName ?? $offer->Hotel ?? '');
            if (empty($hotel_id)) {
                continue;
            }

            $this->output("[{$hotel_id}] {$hotel_name} ... ", false);

            $existingDto = $hotelRepo->findByIdAsDto($hotel_id);
            // Legacy array view for downstream `array_merge` + string-key access.
            // Hotel::toArray() returns a typed `array{...}` shape; widen to
            // array<string, mixed> here so the mutations that add/override
            // keys (hotel_data_for_seo below, etc.) don't fight the shape.
            $existing = $existingDto !== null ? (array) $existingDto->toArray() : [];

            if ($existingDto === null) {
                $this->output('NEW HOTEL - ', false);
                $hotel_info = $this->api->hotels()->getHotelInfo($hotel_id);
                if ((bool) $hotel_info) {
                    $hotel_data = [
                        'hotel_id' => $hotel_id,
                        'hotel_name' => (string)($hotel_info->Hotel ?? $hotel_name),
                        'city' => (string)($hotel_info->City ?? ''),
                        'region' => (string)($hotel_info->Region ?? ''),
                        'country' => (string)($hotel_info->Country ?? $country),
                        'hotel_type' => (string)($hotel_info->HotelType ?? $hotel_info->Stars ?? ''),
                        'has_room_price' => 'N',
                        'hotel_list_synced_at' => date('Y-m-d H:i:s'),
                    ];
                    $hotelRepo->upsert($hotel_data);
                    $new_hotels++;
                    $existing = $hotel_data;
                    $existingDto = \Tygh\Addons\TravelCore\Dto\Hotel\Hotel::fromDbRow($hotel_data);
                    $this->output('synced - ', false);
                }
            }

            if ($existingDto === null) {
                $this->output('skip');
                continue;
            }

            // Same rule as add_hotels_as_products: only destinations we sell
            // (the whitelist, or not an excluded/hidden resort until one is
            // saved). The hotel row above is still kept current.
            if (!$scope->allowsProduct($existingDto->country ?? $country, $existingDto->city)) {
                $this->output('not in the destinations we sell');
                continue;
            }

            // Check if should add to CS-Cart
            if (!$existingDto->hasRoomPrice) {
                $this->output('no prices');
                continue;
            }

            $product_code = 'NVT' . $hotel_id;
            // Check CS-Cart core products table
            $existing_product = db_get_field('SELECT product_id FROM ?:products WHERE product_code = ?s', $product_code);
            if ($existing_product) {
                $hotelRepo->linkToProduct($hotel_id, TypeCoerce::toInt($existing_product));
                $this->output('linked');
                continue;
            }

            $category_id = \Tygh\Addons\NovotonHolidays\Services\ConfigProvider::getCategoryForCountry($country);
            if ($category_id === 0) {
                $category_path = str_replace('{country}', $country, \Tygh\Addons\NovotonHolidays\Constants::PRODUCT_CATEGORY_TEMPLATE);
                $category_id = fn_novoton_holidays_get_or_create_category($category_path);
            }
            $raw_name = $existing['hotel_name'] ?? $hotel_name;
            $display_name = fn_novoton_holidays_format_hotel_display_name($raw_name);

            $description = '';
            try {
                $desc = $this->api->hotels()->getHotelDescription($hotel_id, 'UK');
                if ((bool) $desc && isset($desc->Description)) {
                    $description = (string)$desc->Description;
                }
            } catch (\Exception $e) {
                fn_log_event('general', 'runtime', ['message' => "Novoton: Failed to get description for hotel {$hotel_id}", 'error' => $e->getMessage()]);
            }

            // Build placeholder map for SEO templates
            $hotel_data_for_seo = array_merge($existing, [
                'hotel_name' => $raw_name,
                'country' => $existing['country'] ?? $country,
            ]);
            $placeholders = $this->buildPlaceholders($hotel_data_for_seo, $display_name, $description);

            // SEO fields through the shared engine, like AddProductsCommand:
            // the per-language templates, the "Apply" ticks and the overwrite
            // mode all apply (the old direct render ignored all three).
            $seoFields = fn_travel_core_apply_seo_fields('novoton_holidays', $placeholders, 0, $hotel_id);

            $product_data = array_merge([
                'product_code' => $product_code,
                'price' => 0,
                'amount' => ConfigProvider::getDefaultProductQuantity(),
                'status' => 'D',
                'company_id' => ConfigProvider::getCompanyId(),
                'main_category' => $category_id,
                'category_ids' => [$category_id],
            ], $seoFields);
            // fn_update_product() needs a name even with the name field unticked.
            $productName = $product_data['product'] ?? '';
            if (!is_string($productName) || trim($productName) === '') {
                $product_data['product'] = $display_name;
            }

            $product_id = fn_update_product($product_data, 0, CART_LANGUAGE);
            if ($product_id) {
                $productId = TypeCoerce::toInt($product_id);
                // The create wrote CART_LANGUAGE; the other languages get their own templates.
                fn_travel_core_seo_localize(
                    'novoton_holidays',
                    $placeholders,
                    $productId,
                    $hotel_id,
                    \Tygh\Addons\NovotonHolidays\Helpers\ProductFactory::otherStorefrontLanguages(TypeCoerce::toString(CART_LANGUAGE)),
                );
                $hotelRepo->linkToProduct($hotel_id, $productId);
                $this->attachImages($hotel_id, $productId, $image_base_url);
                $added_to_cart++;
                $this->output("ADDED (ID: {$productId})");
            } else {
                $this->output('FAILED');
            }

            usleep(Constants::API_DELAY_NORMAL);
        }

        $this->output('');
        $this->output("New hotels synced: {$new_hotels}");
        $this->output("Added to CS-Cart: {$added_to_cart}");

        $this->logToSyncTable('offers_update', $added_to_cart);

        // Save sync timestamp
        $syncRepo->create('product_import', [
            'updated' => $added_to_cart,
            'status' => 'completed',
        ]);

        $stats = ['new_hotels' => $new_hotels, 'added_to_cart' => $added_to_cart];
        $this->sendReport('offers_update', [
            'added' => $added_to_cart, 'updated' => $new_hotels, 'duration' => $this->getDuration() . 's',
        ], $country);

        return ['success' => true, 'stats' => $stats];
    }

    private function attachImages(string $hotelId, int $productId, string $baseUrl): void
    {
        try {
            $images = $this->api->hotels()->getHotelImages($hotelId);
            if ((bool) $images && isset($images->url)) {
                $count = 0;
                foreach ($images->url as $url) {
                    $image_url = $baseUrl . str_replace(' ', '%20', (string)$url);
                    fn_novoton_holidays_add_product_image($productId, $image_url, $count === 0);
                    if (++$count >= 10) {
                        break;
                    }
                }
            }
        } catch (\Exception $e) {
            fn_log_event('general', 'runtime', ['message' => "Novoton: Failed to import images for hotel {$hotelId}", 'error' => $e->getMessage()]);
        }

        try {
            fn_novoton_holidays_sync_hotel_facilities($hotelId);
        } catch (\Exception $e) {
            fn_log_event('general', 'runtime', ['message' => "Novoton: Failed to sync facilities for hotel {$hotelId}", 'error' => $e->getMessage()]);
        }
    }

    /**
     * Build the placeholder map for SEO template rendering.
     * @param array<string, mixed> $hotel
     * @return array<string, mixed>
     */
    private function buildPlaceholders(array $hotel, string $displayName, string $description = ''): array
    {
        $facilities = [];
        if (!empty($hotel['hotel_id'])) {
            $facilities = db_get_fields(
                "SELECT f.facility_name_en FROM ?:novoton_hotel_facilities hf
                 JOIN ?:novoton_facilities f ON f.facility_id = hf.facility_id
                 WHERE hf.hotel_id = ?s AND f.facility_name_en != ''
                 LIMIT 5",
                $hotel['hotel_id'],
            ) ?: [];
        }

        // Min price from packages table
        $min_price = '';
        if (!empty($hotel['hotel_id'])) {
            $min_price = db_get_field(
                'SELECT MIN(min_price) FROM ?:novoton_hotel_packages WHERE hotel_id = ?s AND min_price > 0',
                $hotel['hotel_id'],
            ) ?: '';
        }

        return [
            'name' => $displayName,
            'raw_name' => $hotel['hotel_name'] ?? '',
            'city' => $hotel['city'] ?? '',
            'country' => $hotel['country'] ?? '',
            'region' => $hotel['region'] ?? '',
            'star_rating' => $hotel['star_rating'] ?? '',
            'stars_emoji' => fn_travel_core_build_star_emoji(TypeCoerce::toInt($hotel['star_rating'] ?? 0)),
            'hotel_type' => $hotel['hotel_type'] ?? '',
            'property_type' => $hotel['property_type'] ?? 'hotel',
            'year' => date('Y'),
            'description' => $description,
            'facilities' => $facilities,
            'latitude' => $hotel['latitude'] ?? '',
            'longitude' => $hotel['longitude'] ?? '',
            'min_price' => $min_price,
        ];
    }
}
