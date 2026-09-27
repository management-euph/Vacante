<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Cron\Commands;

use Tygh\Addons\NovotonHolidays\Api\PropertyTypeDetector;
use Tygh\Addons\NovotonHolidays\Constants;
use Tygh\Addons\NovotonHolidays\Cron\AbstractCronCommand;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
use Tygh\Addons\NovotonHolidays\Services\Container;
use Tygh\Addons\NovotonHolidays\Services\PriceInfoFormatter;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Services\TravelGroupResolver;

class AddProductsCommand extends AbstractCronCommand
{
    /**
     * @return list<string>
     */
    #[\Override]
    public static function getModes(): array
    {
        return ['add_hotels_as_products'];
    }

    public static function getDescription(): string
    {
        return 'Add hotels with prices as CS-Cart products';
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(): array
    {
        $limit = PriceInfoFormatter::toInt($this->getParam('limit', 0));
        $exclude_resorts = $this->getExcludedResorts();

        // Determine countries: explicit &country= param, or all selected in addon settings
        $countryParam = PriceInfoFormatter::toScalar($this->getParam('country', ''));
        if (!empty($countryParam)) {
            $countries = [strtoupper($countryParam)];
        } else {
            $countries = ConfigProvider::getSelectedCountries();
        }

        // Re-seed feature mappings idempotently. Previously called
        // fn_novoton_holidays_ensure_feature_mappings() and checked its return
        // for unconfigured/seeded counts, but that function had been a
        // stub-returning-empty since the travel_core migration, so both
        // branches were unreachable. Call the seeders directly.
        if (function_exists('fn_travel_core_seed_feature_map')) {
            fn_travel_core_seed_feature_map();
        }
        if (function_exists('fn_novoton_holidays_seed_travel_aliases')) {
            fn_novoton_holidays_seed_travel_aliases();
        }

        $this->output('Adding hotels as products...');
        $this->output('Countries: ' . implode(', ', $countries));
        $this->output('Limit per country: ' . ($limit > 0 ? $limit : 'No limit'));
        if (!empty($exclude_resorts)) {
            $this->output('Excluding resorts (' . count($exclude_resorts) . '): ' . implode(', ', $exclude_resorts));
        }
        // Make the hard prerequisite + available knobs explicit in the output, so
        // an empty run is never mistaken for "nothing to do". Only priced
        // (has_room_price='Y'), not-yet-linked hotels are added.
        $this->output('Prerequisite: run mode=room_price first — it sets has_room_price=Y (the flag this mode requires).');
        $this->output('Options: &country=XX  &limit=N  &exclude_resorts=a,b (added to the dashboard list)   |   already-linked hotels are skipped (no force re-add).');
        $this->output('');

        $hotelRepo = Container::getInstance()->hotelRepository();
        $image_base_url = \Tygh\Addons\NovotonHolidays\Constants::IMAGE_BASE_URL;
        $grand_total = 0;
        $grand_added = 0;

        foreach ($countries as $country) {
            $this->output("=== {$country} ===");

            $hotels = $hotelRepo->findUnlinkedWithPrices($country, $exclude_resorts, $limit);
            $this->output('Found ' . count($hotels) . ' hotels to add.');

            if (empty($hotels)) {
                // 0 results is ambiguous ("no hotels" vs "no prices vs" all added").
                // Classify from cheap counts so the operator sees the real reason and
                // the next action — most often: mode=room_price was never run, so no
                // hotel has has_room_price='Y' (the flag findUnlinkedWithPrices needs).
                $total = $hotelRepo->count(['country' => $country]);
                $priced = $hotelRepo->count(['country' => $country, 'has_room_price' => 'Y']);

                if ($total === 0) {
                    $this->output("  → No hotels are synced for {$country}. Run mode=hotel_list first, then mode=room_price.");
                } elseif ($priced === 0) {
                    $this->output("  → 0 of {$total} synced hotels have prices yet — mode=room_price has not run for {$country}.");
                    $this->output('    Run mode=room_price FIRST (it sets has_room_price=Y), then re-run mode=add_hotels_as_products.');
                    $this->output("    e.g. index.php?dispatch=novoton_cron.run&access_key=...&mode=room_price&country={$country}");
                } else {
                    $this->output("  → All {$priced} priced hotel(s) in {$country} are already linked to products (or excluded) — nothing new to add.");
                    $this->output('    There is no force re-add. To refresh prices/features, run mode=room_price then re-run this mode.');
                }
                $this->output('');
                continue;
            }

            $category_id = ConfigProvider::getCategoryForCountry($country);
            if ($category_id === 0) {
                $category_path = str_replace('{country}', $country, \Tygh\Addons\NovotonHolidays\Constants::PRODUCT_CATEGORY_TEMPLATE);
                $category_id = fn_novoton_holidays_get_or_create_category($category_path);
            }

            if ($category_id === 0) {
                $this->output("ERROR: No category mapping for '{$country}' and auto-creation failed. Skipping.");
                $this->output('');
                $grand_total += count($hotels);
                continue;
            }

            $added = 0;

            foreach ($hotels as $hotel) {
                $hotel_id = PriceInfoFormatter::toScalar($hotel['hotel_id'] ?? '');
                $hotel_name = PriceInfoFormatter::toScalar($hotel['hotel_name'] ?? '');
                $hotel_city = PriceInfoFormatter::toScalar($hotel['city'] ?? '');
                $product_code = 'NVT' . $hotel_id;

                $this->output("[{$hotel_id}] {$hotel_name} ({$hotel_city}) ... ", false);

                // Check if CS-Cart product already exists (core products table)
                $existing = db_get_field('SELECT product_id FROM ?:products WHERE product_code = ?s', $product_code);
                if ($existing) {
                    $existingProductId = PriceInfoFormatter::toInt($existing);
                    $hotelRepo->linkToProduct($hotel_id, $existingProductId);

                    // Apply facilities and features to the pre-existing product.
                    // The ADDED path does this inline; LINKED must do it here so
                    // that hotels linked on re-run don't stay featureless.
                    try {
                        fn_novoton_holidays_sync_hotel_facilities($hotel_id);
                    } catch (\Exception $e) {
                        fn_log_event('general', 'runtime', ['message' => "Novoton: Failed to sync facilities for hotel {$hotel_id}", 'error' => $e->getMessage()]);
                    }

                    try {
                        $this->assignProductFeatures($existingProductId, $hotel_id, $hotel);
                    } catch (\Exception $e) {
                        fn_log_event('general', 'runtime', ['message' => "Novoton: Failed to assign features for hotel {$hotel_id}", 'error' => $e->getMessage()]);
                    }

                    $this->output('LINKED');
                    continue;
                }

                // Detect property type for this hotel
                $propertyDetector = new PropertyTypeDetector();
                $hotelData = fn_novoton_holidays_get_hotel_data($hotel_id);
                /** @var array<string, mixed> $hotelData */
                $hotelData = is_array($hotelData) ? $hotelData : [];
                $packageNames = [];
                $roomNames = [];
                $pkgs = is_array($hotelData['packages'] ?? null) ? $hotelData['packages'] : [];
                foreach ($pkgs as $pkg) {
                    $packageNames[] = is_array($pkg) ? PriceInfoFormatter::toScalar($pkg['PackageName'] ?? '') : PriceInfoFormatter::toScalar($pkg);
                }
                $rms = is_array($hotelData['rooms'] ?? null) ? $hotelData['rooms'] : [];
                foreach ($rms as $rm) {
                    $roomNames[] = is_array($rm) ? PriceInfoFormatter::toScalar($rm['Type'] ?? $rm['IdRoom'] ?? '') : PriceInfoFormatter::toScalar($rm);
                }
                $detectedType = $propertyDetector->detect($hotel_name, $packageNames, $roomNames);

                // Format hotel display name: Title Case + append property type for short names
                $display_name = fn_novoton_holidays_format_hotel_display_name($hotel_name, $detectedType);

                $description = '';
                try {
                    $desc = $this->api->hotels()->getHotelDescription($hotel_id, 'UK');
                    if (isset($desc->Description)) {
                        $description = (string) $desc->Description;
                    }
                } catch (\Exception $e) {
                    fn_log_event('general', 'runtime', ['message' => "Novoton: Failed to get description for hotel {$hotel_id}", 'error' => $e->getMessage()]);
                }

                // Apply SEO templates respecting overwrite mode + field toggles
                $placeholders = \Tygh\Addons\NovotonHolidays\Helpers\ProductFactory::buildNovotonPlaceholders($hotel, $display_name, $description);
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

                // Fall back to the formatted display name when no SEO product-name
                // template is configured. Without a name, fn_update_product() fails.
                $productName = $product_data['product'] ?? '';
                if (!is_string($productName) || trim($productName) === '') {
                    $product_data['product'] = $display_name;
                }

                $product_id = PriceInfoFormatter::toInt(fn_update_product($product_data, 0, CART_LANGUAGE));

                if ($product_id > 0) {
                    // Re-render the other storefront languages with their own
                    // template sets (create replicated the CART_LANGUAGE copy).
                    fn_travel_core_seo_localize(
                        'novoton_holidays',
                        $placeholders,
                        $product_id,
                        $hotel_id,
                        \Tygh\Addons\NovotonHolidays\Helpers\ProductFactory::otherStorefrontLanguages(
                            \Tygh\Addons\TravelCore\Helpers\TypeCoerce::toString(CART_LANGUAGE),
                        ),
                    );
                    $hotelRepo->linkToProduct($hotel_id, $product_id);

                    try {
                        $images = $this->api->hotels()->getHotelImages($hotel_id);
                        if (isset($images->url)) {
                            $urls = [];
                            foreach ($images->url as $url) {
                                $urls[] = $image_base_url . str_replace(' ', '%20', (string) $url);
                                if (count($urls) >= 10) {
                                    break;
                                }
                            }
                            if (!empty($urls)) {
                                fn_travel_core_attach_images_from_urls($product_id, $urls, true);
                            }
                        }
                    } catch (\Exception $e) {
                        fn_log_event('general', 'runtime', ['message' => "Novoton: Failed to import images for hotel {$hotel_id}", 'error' => $e->getMessage()]);
                    }

                    try {
                        fn_novoton_holidays_sync_hotel_facilities($hotel_id);
                    } catch (\Exception $e) {
                        fn_log_event('general', 'runtime', ['message' => "Novoton: Failed to sync facilities for hotel {$hotel_id}", 'error' => $e->getMessage()]);
                    }

                    // Assign CS-Cart product features via FeatureMapper
                    try {
                        $this->assignProductFeatures($product_id, $hotel_id, $hotel);
                    } catch (\Exception $e) {
                        fn_log_event('general', 'runtime', ['message' => "Novoton: Failed to assign features for hotel {$hotel_id}", 'error' => $e->getMessage()]);
                    }

                    $added++;
                    $this->output("ADDED (ID: {$product_id})");
                } else {
                    fn_log_event('general', 'runtime', [
                        'message' => 'Novoton: fn_update_product() returned 0 — product not created',
                        'hotel_id' => $hotel_id,
                        'product_code' => $product_code,
                        'product_name' => $product_data['product'] ?? '',
                        'category_id' => $category_id,
                        'company_id' => ConfigProvider::getCompanyId(),
                    ]);
                    $this->output("FAILED (category_id={$category_id}, company_id=" . ConfigProvider::getCompanyId() . ')');
                }

                usleep(Constants::API_DELAY_NORMAL);
            }

            $this->output("{$country}: Added {$added} of " . count($hotels));
            $this->output('');
            $grand_total += count($hotels);
            $grand_added += $added;
        }

        // Clear FeatureMapper cache after batch import to free memory
        \Tygh\Addons\TravelCore\Services\FeatureMapper::clearCache();

        $this->output('=== TOTAL ===');
        $this->output("Added: {$grand_added} of {$grand_total}");

        $stats = ['added' => $grand_added, 'total' => $grand_total, 'countries' => count($countries)];
        $this->sendReport('add_products', [
            'added' => $grand_added, 'total' => $grand_total, 'countries' => implode(', ', $countries), 'duration' => $this->getDuration() . 's',
        ]);

        return ['success' => true, 'stats' => $stats];
    }

    /**
     * Assign all mappable features to a newly created product.
     * @param array<string, mixed> $hotel
     */
    private function assignProductFeatures(int $productId, string $hotelId, array $hotel): void
    {
        $container = Container::getInstance();
        $featureMapper = $container->featureMapper();
        $normalizer = $container->novotonNormalizer();
        $facilityRepo = $container->facilityRepository();

        // Star rating — uses shared travel_core mapping (travel_feature_map + travel_api_alias)
        $starRating = PriceInfoFormatter::toInt($hotel['star_rating'] ?? 0);
        if ($starRating >= 1) {
            $code = $normalizer->normalizeStarRating((string) $starRating);
            if ($code !== null) {
                $featureMapper->assignFeatureViaCore($productId, 'stars', $code);
            }
        }

        // Board types — uses shared travel_core mapping (travel_feature_map + travel_api_alias)
        $hotelData = fn_novoton_holidays_get_hotel_data($hotelId);
        /** @var array<string, mixed> $hotelData */
        $hotelData = is_array($hotelData) ? $hotelData : [];
        $boards = is_array($hotelData['boards'] ?? null) ? $hotelData['boards'] : [];
        if (!empty($boards)) {
            $boardCodes = [];
            foreach ($boards as $board) {
                $raw = is_array($board) ? PriceInfoFormatter::toScalar($board['IdBoard'] ?? $board['Board'] ?? '') : PriceInfoFormatter::toScalar($board);
                $code = $normalizer->normalizeBoardCode($raw);
                if ($code !== null) {
                    $boardCodes[] = $code;
                }
            }
            if (!empty($boardCodes)) {
                $featureMapper->assignMultipleViaCore($productId, 'board', array_unique($boardCodes));
            }
        }

        // Facilities — resolved via travel_core canonical mapping (travel_feature_map + travel_api_alias)
        $allFacilityIds = $facilityRepo->getIdsForHotel($hotelId);
        if (!empty($allFacilityIds)) {
            $facilityCodes = [];
            foreach ($allFacilityIds as $fid) {
                $code = $normalizer->normalizeFacilityCode($fid);
                if ($code !== null) {
                    $facilityCodes[] = $code;
                }
            }
            if (!empty($facilityCodes)) {
                $featureMapper->assignFacilitiesViaCore($productId, array_unique($facilityCodes));
            }
        }

        // Travel Group — derived from facility codes + hotel flags (not from API)
        $resolvedFacilityCodes = [];
        foreach ($allFacilityIds as $fid) {
            $code = $normalizer->normalizeFacilityCode($fid);
            if ($code !== null) {
                $mapping = \Tygh\Addons\TravelCore\Services\FeatureMapper::resolveFacility('novoton', $code);
                if ($mapping !== null && !empty($mapping['canonical_code'])) {
                    $resolvedFacilityCodes[] = PriceInfoFormatter::toScalar($mapping['canonical_code']);
                }
            }
        }

        $travelGroups = TravelGroupResolver::derive(
            $resolvedFacilityCodes,
            ($hotel['is_adults_only'] ?? 'N') === 'Y',
        );
        if (!empty($travelGroups)) {
            $featureMapper->assignMultipleViaCore($productId, 'travel_group', $travelGroups);
        }

        // Resort / City — via travel_core mapping (dynamic: auto-registers unknown values)
        if (!empty($hotel['city'])) {
            $resortCode = $normalizer->normalizeResort($hotel['city']);
            if ($resortCode !== null) {
                $featureMapper->assignFeatureViaCore($productId, 'resort', $resortCode);
            }
        }

        // City / Region — plain name-based variants on the shared location
        // features (sphinx parity); independent of the resort mapping above.
        $featureMapper->assignLocationByName($productId, 'city', PriceInfoFormatter::toScalar($hotel['city'] ?? ''));
        $featureMapper->assignLocationByName($productId, 'region', PriceInfoFormatter::toScalar($hotel['region'] ?? ''));

        // Property type — uses shared travel_core mapping
        if (!empty($hotel['property_type'])) {
            $code = $normalizer->normalizePropertyType($hotel['property_type']);
            if ($code !== null) {
                $featureMapper->assignFeatureViaCore($productId, 'property_type', $code);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function getExcludedResorts(): array
    {
        // &exclude_resorts=a,b adds to the dashboard's list; it used to
        // replace it, so a one-off run could make products the dashboard excludes.
        $paramVal = $this->getParam('exclude_resorts');
        $extra = [];
        if (!empty($paramVal)) {
            $extra = is_array($paramVal)
                ? TypeCoerce::toStringList($paramVal)
                : explode(',', PriceInfoFormatter::toScalar($paramVal));
        }

        return ConfigProvider::getProductExclusions(array_values($extra));
    }
}
