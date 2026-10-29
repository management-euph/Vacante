<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Cron\Commands;

use Tygh\Addons\NovotonHolidays\Constants;
use Tygh\Addons\NovotonHolidays\Cron\AbstractCronCommand;
use Tygh\Addons\NovotonHolidays\Exceptions\ApiException;
use Tygh\Addons\NovotonHolidays\Helpers\OffersUpdateBaseline;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
use Tygh\Addons\NovotonHolidays\Services\Container;
use Tygh\Addons\NovotonHolidays\Services\DestinationScope;
use Tygh\Addons\NovotonHolidays\Services\HotelImageSync;
use Tygh\Addons\NovotonHolidays\Services\OfferChangeMarker;
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
        return 'Check offers_update for changed offers in every country sold: mark them for priceinfo and hotelinfo, add new hotels as products';
    }

    /**
     * Novoton asked (28 Oct 2026) for offers_update every 12 hours, then
     * priceinfo only for what it returned. Each country we sell is asked
     * since its own baseline (OffersUpdateBaseline); &country= asks one.
     * A country Novoton does not answer for keeps its baseline, so the next
     * run asks for the same changes again.
     *
     * @return array<string, mixed>
     */
    public function execute(): array
    {
        $this->output('Checking for new/updated offers (offers_update API)...');
        $this->output('');

        $asked = strtoupper(trim(TypeCoerce::toString($this->getParam('country', ''))));
        $countries = $asked !== ''
            ? [$asked]
            : array_values(array_unique(array_map('strtoupper', ConfigProvider::getSelectedCountries())));
        if ($countries === []) {
            $countries = [Constants::DEFAULT_COUNTRY];
        }

        $totals = ['new_hotels' => 0, 'added_to_cart' => 0, 'packages_marked' => 0, 'hotels_marked' => 0];
        $failed = [];
        foreach ($countries as $country) {
            try {
                $stats = $this->checkCountry($country);
            } catch (ApiException $e) {
                // No answer, or a refusal: this country's baseline stays put.
                $this->output("{$country}: Novoton did not answer ({$e->getMessage()}). The next run asks again.");
                $this->output('');
                $failed[] = $country;
                continue;
            }
            foreach ($stats as $key => $value) {
                $totals[$key] += $value;
            }
        }

        $this->output("New hotels synced: {$totals['new_hotels']}");
        $this->output("Added to CS-Cart: {$totals['added_to_cart']}");
        $this->output("Packages to ask priceinfo for again: {$totals['packages_marked']}");
        $this->output("Hotels to ask hotelinfo for again (a package they do not list yet): {$totals['hotels_marked']}");

        $this->sendReport('offers_update', [
            'added' => $totals['added_to_cart'], 'updated' => $totals['new_hotels'], 'duration' => $this->getDuration() . 's',
        ], implode(', ', $countries));

        if ($failed !== []) {
            return ['success' => false, 'error' => 'Novoton did not answer offers_update for ' . implode(', ', $failed), 'stats' => $totals];
        }

        return ['success' => true, 'stats' => $totals];
    }

    /**
     * One country: ask, mark what changed, add new hotels as products.
     *
     * @return array{new_hotels: int, added_to_cart: int, packages_marked: int, hotels_marked: int}
     *
     * @throws ApiException when Novoton does not answer or refuses
     */
    private function checkCountry(string $country): array
    {
        $syncRepo = Container::getInstance()->syncLogRepository();
        // A durable baseline per country (OffersUpdateBaseline): the
        // product_import log row it used to need was trimmed away by the
        // nightly cleanup, after which the job never ran again.
        $since = OffersUpdateBaseline::resolve(
            OffersUpdateBaseline::get($country),
            $syncRepo->getLastSyncDate('product_import'),
            time(),
        );
        $askedAt = date('Y-m-d H:i:s');

        $this->output("Country: {$country}");
        $this->output("Changes since: {$since}");
        $this->output('');

        // Novoton's DateTime is Y-m-dTH:i:s; the baseline is stored with a space.
        $sinceTime = strtotime($since);
        $response = $this->api->destinations()->getOffersUpdate(
            $sinceTime === false ? $since : date('Y-m-d\TH:i:s', $sinceTime),
            $country,
        );

        $stats = ['new_hotels' => 0, 'added_to_cart' => 0, 'packages_marked' => 0, 'hotels_marked' => 0];
        if (!isset($response->Offer)) {
            OffersUpdateBaseline::advance($country, $askedAt);
            $this->output('No new offers found.');
            $this->output('');
            return $stats;
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
        $marker = new OfferChangeMarker();
        $new_hotels = 0;
        $added_to_cart = 0;

        foreach ($offers as $offer) {
            $hotel_id = (string)($offer->IdHotel ?? '');
            // One <Offer> per package: PackageName names the package, Hotel the hotel.
            $package_name = trim((string)($offer->PackageName ?? ''));
            $hotel_name = (string)($offer->Hotel ?? $package_name);
            if (empty($hotel_id)) {
                continue;
            }

            $this->output("[{$hotel_id}] {$hotel_name} / {$package_name} ... ", false);

            $existingDto = $hotelRepo->findByIdAsDto($hotel_id);
            // Legacy array view for downstream `array_merge` + string-key access.
            // Hotel::toArray() returns a typed `array{...}` shape; widen to
            // array<string, mixed> here so the mutations that add/override
            // keys (hotel_data_for_seo below, etc.) don't fight the shape.
            $existing = $existingDto !== null ? (array) $existingDto->toArray() : [];

            if ($existingDto !== null) {
                // priceinfo asks for the package again; hotelinfo for a hotel
                // whose stored details do not list the package yet.
                if ($marker->mark($hotel_id, $package_name, $askedAt) === OfferChangeMarker::PACKAGE) {
                    $stats['packages_marked']++;
                    $this->output('prices to refresh - ', false);
                } else {
                    $stats['hotels_marked']++;
                    $this->output('details to refresh - ', false);
                }
            } else {
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
                $description = \Tygh\Addons\NovotonHolidays\Helpers\HotelDescription::fromResponse($desc);
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
                $this->attachImages($hotel_id, $productId);
                $added_to_cart++;
                $this->output("ADDED (ID: {$productId})");
            } else {
                $this->output('FAILED');
            }

            usleep(Constants::API_DELAY_NORMAL);
        }

        $this->output('');

        $this->logToSyncTable('offers_update', $added_to_cart);
        OffersUpdateBaseline::advance($country, $askedAt);

        // The log row stays for the dashboard; the baseline no longer reads it.
        $syncRepo->create('product_import', [
            'updated' => $added_to_cart,
            'status' => 'completed',
        ]);

        $stats['new_hotels'] = $new_hotels;
        $stats['added_to_cart'] = $added_to_cart;

        return $stats;
    }

    private function attachImages(string $hotelId, int $productId): void
    {
        // Its photos, remembered (HotelImageSync), so the weekly
        // refresh_images never adds one twice.
        try {
            (new HotelImageSync())->sync(
                $hotelId,
                $productId,
                HotelImageSync::paths($this->api->hotels()->getHotelImages($hotelId)),
                date('Y-m-d H:i:s'),
            );
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
