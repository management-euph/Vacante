<?php
declare(strict_types=1);
/**
 * Travel Core - Feature Mappings Controller
 *
 * Admin controller for managing the travel_feature_map + travel_api_alias tables.
 * Supports listing, editing, activating/deactivating, re-seeding,
 * auto-resolving variants, and managing aliases.
 *
 * @package TravelCore
 */

use Tygh\Tygh;
use Tygh\Registry;
use Tygh\Addons\TravelCore\Helpers\RequestCoerce;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Services\FeatureMapper;
use Tygh\Addons\TravelCore\Services\TravelProviderRegistry;
use Tygh\Addons\TravelCore\TravelConstants;
use Tygh\Addons\TravelCore\ViewModels\FeatureMappingsView;

/** @var \Tygh\Addons\TravelCore\Contracts\FeatureMapRepositoryInterface $repo */
$repo = FeatureMapper::getRepository();

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

if (fn_allowed_for('MULTIVENDOR') || (defined('RESTRICTED_ADMIN') && RESTRICTED_ADMIN)) {
    return [CONTROLLER_STATUS_DENIED];
}

/** @var \Smarty $view */
$view = Tygh::$app['view'];

// Valid feature types — derived from FeatureMapper to stay in sync automatically
$validFeatureTypes = array_merge(FeatureMapper::STRICT_FEATURE_TYPES, FeatureMapper::DYNAMIC_FEATURE_TYPES);

// ── POST actions ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Update mapping
    if ($mode === 'update') {
        $mapId = RequestCoerce::int($_REQUEST, 'map_id');
        if ($mapId > 0 && !empty($_REQUEST['mapping_data']) && is_array($_REQUEST['mapping_data'])) {
            $data = TypeCoerce::toStringMap($_REQUEST['mapping_data']);
            $updateData = [];

            // Allowed editable fields
            if (isset($data['display_name_en'])) {
                $updateData['display_name_en'] = TypeCoerce::toString($data['display_name_en']);
            }
            if (isset($data['display_name_ro'])) {
                $updateData['display_name_ro'] = TypeCoerce::toString($data['display_name_ro']);
            }
            if (isset($data['cscart_feature_id'])) {
                $updateData['cscart_feature_id'] = TypeCoerce::toInt($data['cscart_feature_id']) ?: null;
            }
            if (isset($data['cscart_variant_id'])) {
                $updateData['cscart_variant_id'] = TypeCoerce::toInt($data['cscart_variant_id']) ?: null;
                // "Keep this variant" (variant_lock) decides; older forms without it lock any picked variant
                if (isset($data['variant_lock'])) {
                    $updateData['variant_source'] = $data['variant_lock'] === 'Y' ? 'manual' : 'auto';
                } elseif (TypeCoerce::toInt($data['cscart_variant_id']) > 0) {
                    $updateData['variant_source'] = 'manual';
                }
            }
            if (isset($data['position'])) {
                $updateData['position'] = TypeCoerce::toInt($data['position']);
            }
            if (isset($data['status'])) {
                $updateData['status'] = TypeCoerce::toString($data['status']) === 'A' ? 'A' : 'D';
            }

            if (!empty($updateData)) {
                $repo->updateMapping($mapId, $updateData);
                FeatureMapper::clearCache();
                fn_set_notification('N', __('notice'), __('travel_core.fm_mapping_updated'));
            }
        }

        $redirectParams = !empty($_REQUEST['feature_type_filter']) ? '&feature_type=' . urlencode(TypeCoerce::toString($_REQUEST['feature_type_filter'])) : '';
        return [CONTROLLER_STATUS_REDIRECT, 'travel_feature_mappings.manage' . $redirectParams];
    }

    // Bulk update (toggle status, delete)
    if ($mode === 'bulk_update') {
        $action = RequestCoerce::string($_REQUEST, 'dispatch_extra');
        $ids = RequestCoerce::intList($_REQUEST, 'map_ids');

        if (!empty($ids)) {

            if ($action === 'activate') {
                $repo->bulkUpdateStatus($ids, 'A');
                fn_set_notification('N', __('notice'), __('travel_core.fm_mappings_activated', ['[count]' => count($ids)]));
            } elseif ($action === 'deactivate') {
                $repo->bulkUpdateStatus($ids, 'D');
                fn_set_notification('N', __('notice'), __('travel_core.fm_mappings_deactivated', ['[count]' => count($ids)]));
            } elseif ($action === 'delete') {
                $repo->deleteMappings($ids);
                fn_set_notification('N', __('notice'), __('travel_core.fm_mappings_deleted', ['[count]' => count($ids)]));
            }
            FeatureMapper::clearCache();
        }

        $redirectParams = !empty($_REQUEST['feature_type']) ? '&feature_type=' . urlencode(TypeCoerce::toString($_REQUEST['feature_type'])) : '';
        return [CONTROLLER_STATUS_REDIRECT, 'travel_feature_mappings.manage' . $redirectParams];
    }

    // Re-seed canonical codes
    if ($mode === 'reseed') {
        fn_travel_core_seed_feature_map();
        fn_set_notification('N', __('notice'), __('travel_core.fm_reseeded'));
        return [CONTROLLER_STATUS_REDIRECT, 'travel_feature_mappings.manage'];
    }

    // Auto-resolve unmapped variants by name-matching + auto-create
    if ($mode === 'resolve_variants') {

        // Step 1: Auto-populate cscart_feature_id from addon settings for entries that don't have one
        $featureTypes = $repo->getFeatureTypesWithoutFeatureId();
        foreach ($featureTypes as $ft) {
            $fid = FeatureMapper::getFeatureId($ft);
            if ($fid > 0) {
                $repo->bulkSetFeatureId($ft, $fid);
            }
        }

        // Step 2: Query entries that have a feature_id but no variant yet
        // Exclude manually locked mappings (variant_source='manual')
        $unmapped = $repo->getUnresolvedMappings();

        $resolved = 0;
        $created = 0;
        $failed = 0;

        // Group unmapped rows by cscart_feature_id for batch lookup
        $byFeature = [];
        foreach ($unmapped as $mapping) {
            $fid = TypeCoerce::toInt($mapping['cscart_feature_id'] ?? 0);
            if ($fid > 0) {
                $byFeature[$fid][] = $mapping;
            } else {
                $failed++;
            }
        }

        // Batch-load variant names per feature_id
        foreach ($byFeature as $featureId => $mappings) {
            $variantNameToIdRaw = db_get_hash_single_array(
                "SELECT vd.variant, v.variant_id
                 FROM ?:product_feature_variants v
                 JOIN ?:product_feature_variant_descriptions vd ON v.variant_id = vd.variant_id
                 WHERE v.feature_id = ?i AND vd.lang_code = 'en'",
                ['variant', 'variant_id'],
                $featureId
            );
            /** @var array<string, int> $variantNameToId */
            $variantNameToId = [];
            foreach (TypeCoerce::toStringMap($variantNameToIdRaw) as $vName => $vId) {
                $variantNameToId[$vName] = TypeCoerce::toInt($vId);
            }

            foreach ($mappings as $mapping) {
                $mappingMap = TypeCoerce::toStringMap($mapping);
                $nameEn = trim(TypeCoerce::toString($mappingMap['display_name_en'] ?? ''));
                if ($nameEn === '') {
                    $failed++;
                    continue;
                }

                $variantId = \Tygh\Addons\TravelCore\Services\VariantResolver::matchName($nameEn, $variantNameToId);

                if (!empty($variantId)) {
                    FeatureMapper::updateVariantId(TypeCoerce::toInt($mappingMap['map_id'] ?? 0), TypeCoerce::toInt($variantId), 'auto');
                    $resolved++;
                } else {
                    // Auto-create the variant
                    $nameRo = trim(TypeCoerce::toString($mappingMap['display_name_ro'] ?? '')) ?: $nameEn;
                    $languages = $repo->getActiveLanguageCodes();
                    if (empty($languages)) {
                        $languages = ['en'];
                    }

                    $newVariantId = $repo->createFeatureVariant($featureId);

                    if ($newVariantId > 0) {
                        $nameByLang = [];
                        foreach ($languages as $langCode) {
                            $nameByLang[$langCode] = ($langCode === 'ro') ? $nameRo : $nameEn;
                        }
                        $repo->insertFeatureVariantDescriptions($newVariantId, $nameByLang);
                        FeatureMapper::updateVariantId(TypeCoerce::toInt($mappingMap['map_id'] ?? 0), $newVariantId, 'auto');
                        // Add to local cache so subsequent mappings can match
                        $variantNameToId[$nameEn] = $newVariantId;
                        $created++;
                    } else {
                        $failed++;
                    }
                }
            }
        }

        FeatureMapper::clearCache();
        fn_set_notification('N', __('notice'), __('travel_core.fm_variants_resolved', [
            '[resolved]' => $resolved + $created,
            '[failed]' => $failed,
        ]));

        return [CONTROLLER_STATUS_REDIRECT, 'travel_feature_mappings.manage'];
    }

    // Add alias
    if ($mode === 'add_alias') {
        $mapId = RequestCoerce::int($_REQUEST, 'map_id');
        $apiSource = (string) preg_replace('/[^a-z0-9_]/', '', strtolower(RequestCoerce::string($_REQUEST, 'api_source')));
        $apiValue = RequestCoerce::string($_REQUEST, 'api_value');
        $validMatchTypes = ['exact', 'prefix', 'contains'];
        $matchType = RequestCoerce::string($_REQUEST, 'match_type', 'exact');
        if (!in_array($matchType, $validMatchTypes, true)) {
            $matchType = 'exact';
        }

        if ($mapId > 0 && $apiSource !== '' && $apiValue !== '') {
            FeatureMapper::addAlias($apiSource, $apiValue, $mapId, $matchType);
            FeatureMapper::clearCache();
            fn_set_notification('N', __('notice'), __('travel_core.fm_alias_added'));
        }
        return [CONTROLLER_STATUS_REDIRECT, 'travel_feature_mappings.edit&map_id=' . $mapId];
    }

    // Re-arm a provider's alias self-heal: its init.php re-seeds on the redirect
    if ($mode === 'reseed_aliases') {
        $provider = (string) preg_replace('/[^a-z0-9_]/', '', strtolower(RequestCoerce::string($_REQUEST, 'provider')));
        if ($provider !== '' && fn_travel_core_self_heal_known($provider . '_aliases')) {
            fn_travel_core_self_heal_reset($provider . '_aliases');
            fn_set_notification('N', __('notice'), __('travel_core.fm_aliases_reseeded', ['[provider]' => ucfirst($provider)]));
        }
        $featureType = RequestCoerce::string($_REQUEST, 'feature_type');
        return [CONTROLLER_STATUS_REDIRECT, 'travel_feature_mappings.manage' . ($featureType !== '' ? '&feature_type=' . urlencode($featureType) : '')];
    }

    // Delete alias
    if ($mode === 'delete_alias') {
        $aliasId = RequestCoerce::int($_REQUEST, 'alias_id');
        $mapId = RequestCoerce::int($_REQUEST, 'map_id');
        if ($aliasId > 0) {
            $repo->deleteAlias($aliasId);
            FeatureMapper::clearCache();
            fn_set_notification('N', __('notice'), __('travel_core.fm_alias_deleted'));
        }
        return [CONTROLLER_STATUS_REDIRECT, 'travel_feature_mappings.edit&map_id=' . $mapId];
    }

    // Promote unmapped value to a real mapping
    if ($mode === 'map_unmapped') {
        $unmappedId = RequestCoerce::int($_REQUEST, 'unmapped_id');
        if ($unmappedId > 0) {
            $row = $repo->getUnmappedById($unmappedId);
            if (!empty($row)) {
                $mapId = FeatureMapper::registerUnmapped(
                    TypeCoerce::toString($row['api_source'] ?? ''),
                    TypeCoerce::toString($row['feature_type'] ?? ''),
                    TypeCoerce::toString($row['api_value'] ?? ''),
                    TypeCoerce::toString($row['api_label'] ?? '')
                );
                if (!empty($mapId)) {
                    fn_set_notification('N', __('notice'), __('travel_core.fm_unmapped_promoted'));
                    return [CONTROLLER_STATUS_REDIRECT, 'travel_feature_mappings.edit&map_id=' . $mapId];
                }
            }
        }
        fn_set_notification('E', __('error'), __('travel_core.fm_unmapped_promote_failed'));
        return [CONTROLLER_STATUS_REDIRECT, 'travel_feature_mappings.unmapped'];
    }

    // Batch scan provider hotel facilities → populate travel_unmapped_values
    if ($mode === 'scan_facilities') {
        $provider = (string) preg_replace('/[^a-z0-9_]/', '', strtolower(RequestCoerce::string($_REQUEST, 'scan_provider')));
        $batchSize = min(max(RequestCoerce::int($_REQUEST, 'batch_size', TravelConstants::BATCH_SIZE_DEFAULT), TravelConstants::BATCH_SIZE_MIN), TravelConstants::BATCH_SIZE_MAX);
        $offset = max(0, RequestCoerce::int($_REQUEST, 'scan_offset'));

        if ($provider === '') {
            fn_set_notification('E', __('error'), 'No provider specified.');
            return [CONTROLLER_STATUS_REDIRECT, 'travel_feature_mappings.manage'];
        }

        // Provider-specific: determine source table and JSON column
        $scanConfig = _travel_fm_get_scan_config($provider);
        if (empty($scanConfig)) {
            fn_set_notification('E', __('error'), "Provider '" . htmlspecialchars($provider, ENT_QUOTES, 'UTF-8') . "' does not support facility scanning.");
            return [CONTROLLER_STATUS_REDIRECT, 'travel_feature_mappings.manage'];
        }

        $scanTable = TypeCoerce::toString($scanConfig['table'] ?? '');
        $scanIdCol = TypeCoerce::toString($scanConfig['id_col'] ?? '');
        $scanJsonCol = TypeCoerce::toString($scanConfig['json_col'] ?? '');

        // Count total hotels (only on first batch)
        $totalHotels = $repo->countHotelsWithJsonFacilities($scanTable, $scanJsonCol);

        // Fetch batch of hotels
        $hotels = $repo->findHotelsBatchForScan(
            $scanTable,
            $scanIdCol,
            $scanJsonCol,
            $offset,
            $batchSize
        );

        $newUnmapped = 0;
        $totalFacilities = 0;

        foreach ($hotels as $hotel) {
            $hotelMap = TypeCoerce::toStringMap($hotel);
            $facilities = json_decode(TypeCoerce::toString($hotelMap[$scanJsonCol] ?? ''), true);
            if (!is_array($facilities)) {
                continue;
            }

            foreach ($facilities as $facility) {
                $facilityMap = TypeCoerce::toStringMap($facility);
                $facilityId = TypeCoerce::toString($facilityMap['id'] ?? '');
                $facilityName = TypeCoerce::toString($facilityMap['name'] ?? '');
                if ($facilityId === '') {
                    continue;
                }
                $totalFacilities++;

                // Check if already mapped (facility could be hotel_facility, room_facility, or beach_access)
                $mapping = FeatureMapper::resolveFacility($provider, $facilityId);
                if (empty($mapping)) {
                    // Track as unmapped — default to hotel_facility
                    FeatureMapper::trackUnmapped($provider, 'hotel_facility', $facilityId, $facilityName);
                    $newUnmapped++;
                }
            }
        }

        // Clear resolve cache to free memory between batches
        FeatureMapper::clearCache();

        $processedSoFar = $offset + count($hotels);
        $isComplete = count($hotels) < $batchSize || $processedSoFar >= $totalHotels;

        if ($isComplete) {
            $condition = db_quote(" AND api_source = ?s AND feature_type IN (?a)", $provider, FeatureMapper::FACILITY_TYPES);
            $unmappedResult = $repo->getPaginatedUnmapped($condition, 0, 0);
            $unmappedTotal = $unmappedResult['total'];
            fn_set_notification('N', __('notice'), __('travel_core.fm_scan_complete', [
                '[provider]' => $provider,
                '[hotels]' => $processedSoFar,
                '[unmapped]' => $unmappedTotal,
            ]));
            return [CONTROLLER_STATUS_REDIRECT, 'travel_feature_mappings.unmapped&api_source=' . $provider . '&feature_type=facility'];
        }

        // Not complete — redirect back with progress to continue
        $redirectUrl = 'travel_feature_mappings.scan_progress'
            . '&scan_provider=' . $provider
            . '&scan_offset=' . $processedSoFar
            . '&scan_total=' . $totalHotels
            . '&batch_size=' . $batchSize;

        return [CONTROLLER_STATUS_REDIRECT, $redirectUrl];
    }
}

/**
 * Helper: provider-specific scan configuration (from TravelProviderRegistry)
 *
 * @return array<string, mixed>|null
 */
function _travel_fm_get_scan_config(string $provider): ?array
{
    return TravelProviderRegistry::getScanConfig($provider);
}

// ── GET: Manage (dashboard or paginated list) ──
if ($mode === 'manage') {
    $featureTypeFilter = RequestCoerce::string($_REQUEST, 'feature_type');
    $statusFilter = RequestCoerce::string($_REQUEST, 'status');
    $sourceFilter = RequestCoerce::string($_REQUEST, 'mapping_source');
    $searchQuery = trim(RequestCoerce::string($_REQUEST, 'q'));

    // ── Dashboard mode (no feature_type selected) ──
    if (empty($featureTypeFilter) || !in_array($featureTypeFilter, $validFeatureTypes, true)) {

        // Per-type stats for dashboard cards
        $typeStats = $repo->getTypeStats();

        // Enrich with configured feature IDs
        foreach ($typeStats as $ft => &$stat) {
            $stat['feature_id'] = FeatureMapper::getFeatureId($ft);
            $stat['total'] = TypeCoerce::toInt($stat['total'] ?? 0);
            $stat['active'] = TypeCoerce::toInt($stat['active'] ?? 0);
            $stat['unmapped'] = TypeCoerce::toInt($stat['unmapped'] ?? 0);
            $stat['auto_registered'] = TypeCoerce::toInt($stat['auto_registered'] ?? 0);
        }
        unset($stat);

        // Unmapped values count
        $unmappedCount = $repo->getUnmappedCount();

        // Global stats
        $stats = $repo->getGlobalStats();

        // Which providers' values reach each type (chips per row)
        $aliasCounts = $repo->getAliasCountsBySource();
        $providers = FeatureMappingsView::providers(array_map(static fn (array $p): string => $p['label'], TravelProviderRegistry::all()), $aliasCounts);
        $featureNames = $repo->getFeatureNames(array_values(array_filter(array_map(static fn (array $st): int => TypeCoerce::toInt($st['feature_id']), $typeStats))), TypeCoerce::toString(DESCR_SL));
        foreach ($typeStats as $ft => &$stat) {
            $stat['coverage'] = FeatureMappingsView::coverage($providers, $aliasCounts, (string) $ft);
            $stat['feature_name'] = $featureNames[TypeCoerce::toInt($stat['feature_id'])] ?? '';
        }
        unset($stat);

        // Providers with scan config (for "Scan Facilities" dropdown)
        $scanProviders = array_keys(TravelProviderRegistry::getAllScanConfigs());

        $view->assign('view_mode', 'dashboard');
        $view->assign('type_stats', $typeStats);
        $view->assign('type_labels', FeatureMappingsView::TYPE_LABELS);
        $view->assign('alias_providers', count(array_filter($aliasCounts, static fn (array $c): bool => array_sum($c) > 0)));
        $view->assign('unmapped_count', $unmappedCount);
        $view->assign('mapping_stats', $stats);
        $view->assign('feature_types', $validFeatureTypes);
        $view->assign('scan_providers', $scanProviders);
        $view->assign('alias_gaps', \Tygh\Addons\TravelCore\Services\AliasCoverage::gaps($aliasCounts));
    } else {
        // ── List mode (feature_type selected, paginated) ──

        // Pagination params (CS-Cart standard)
        $page = max(1, RequestCoerce::int($_REQUEST, 'page', 1));
        $itemsPerPage = RequestCoerce::int($_REQUEST, 'items_per_page');
        if ($itemsPerPage <= 0) {
            $itemsPerPage = TypeCoerce::toInt(Registry::get('settings.Appearance.admin_elements_per_page')) ?: 25;
        }
        $itemsPerPage = min($itemsPerPage, 250); // Cap at 250

        // Build WHERE condition using db_quote() (CS-Cart standard pattern)
        $condition = db_quote(" AND m.feature_type = ?s", $featureTypeFilter);

        if ($statusFilter === 'A' || $statusFilter === 'D') {
            $condition .= db_quote(" AND m.status = ?s", $statusFilter);
        }

        if ($sourceFilter !== '' && in_array($sourceFilter, ['seed', 'auto', 'manual'], true)) {
            $condition .= db_quote(" AND m.mapping_source = ?s", $sourceFilter);
        }

        if ($searchQuery !== '') {
            $like = '%' . addcslashes($searchQuery, '%_\\') . '%';
            $condition .= db_quote(
                " AND (m.canonical_code LIKE ?l OR m.display_name_en LIKE ?l OR m.display_name_ro LIKE ?l"
                . " OR EXISTS (SELECT 1 FROM ?:travel_api_alias sa WHERE sa.map_id = m.map_id AND sa.api_value LIKE ?l))",
                $like, $like, $like, $like
            );
        }

        // Provider filter: "novoton" = has a Novoton alias, "!novoton" = has none
        $providerFilter = FeatureMappingsView::parseProviderFilter(RequestCoerce::string($_REQUEST, 'provider'));
        if ($providerFilter !== null) {
            $condition .= db_quote(
                ' AND ' . ($providerFilter['negate'] ? 'NOT ' : '') . 'EXISTS (SELECT 1 FROM ?:travel_api_alias pa WHERE pa.map_id = m.map_id AND pa.api_source = ?s)',
                $providerFilter['provider']
            );
        }

        // Offset
        $offset = ($page - 1) * $itemsPerPage;

        // Fetch paginated data via repository
        $paginatedResult = $repo->getPaginatedMappings($condition, $offset, $itemsPerPage);
        $mappings = $paginatedResult['items'];
        $totalItems = $paginatedResult['total'];

        if ($offset >= $totalItems && $totalItems > 0) {
            $page = 1;
            $offset = 0;
            $paginatedResult = $repo->getPaginatedMappings($condition, $offset, $itemsPerPage);
            $mappings = $paginatedResult['items'];
        }

        // Variant + feature names, and each provider's values, for display
        $lang = TypeCoerce::toString(DESCR_SL);
        $idsOf = static fn (string $col): array => array_values(array_filter(array_unique(array_map(static fn ($v): int => TypeCoerce::toInt($v), array_column($mappings, $col)))));
        $variantNames = $repo->getVariantNames($idsOf('cscart_variant_id'), $lang);
        $featureNames = $repo->getFeatureNames($idsOf('cscart_feature_id'), $lang);
        $valuesByMap = FeatureMappingsView::groupAliases($repo->getAliasesForMappings($idsOf('map_id')));
        foreach ($mappings as &$m) {
            $m['variant_name'] = $variantNames[TypeCoerce::toInt($m['cscart_variant_id'] ?? 0)] ?? '';
            $m['feature_name'] = $featureNames[TypeCoerce::toInt($m['cscart_feature_id'] ?? 0)] ?? '';
            $m['provider_values'] = $valuesByMap[TypeCoerce::toInt($m['map_id'] ?? 0)] ?? [];
        }
        unset($m);

        // Type-level stats for header
        $typeStats = $repo->getTypeStatsSingle($featureTypeFilter);

        // Providers whose values never reach this type (banner + re-seed)
        $aliasCounts = $repo->getAliasCountsBySource();
        $providers = FeatureMappingsView::providers(array_map(static fn (array $p): string => $p['label'], TravelProviderRegistry::all()), $aliasCounts);
        $coverage = FeatureMappingsView::coverage($providers, $aliasCounts, $featureTypeFilter);
        $missing = FeatureMappingsView::missing($coverage);
        foreach ($missing as &$gap) {
            $gap['reseedable'] = fn_travel_core_self_heal_known($gap['provider'] . '_aliases');
        }
        unset($gap);

        // CS-Cart pagination: assign $search with standard keys
        $search = [
            'feature_type'   => $featureTypeFilter,
            'status'         => $statusFilter,
            'mapping_source' => $sourceFilter,
            'provider'       => RequestCoerce::string($_REQUEST, 'provider'),
            'q'              => $searchQuery,
            'page'           => $page,
            'items_per_page' => $itemsPerPage,
            'total_items'    => $totalItems,
        ];

        $configuredFeatureId = FeatureMapper::getFeatureId($featureTypeFilter);
        $view->assign('view_mode', 'list');
        $view->assign('mappings', $mappings);
        $view->assign('search', $search);
        $view->assign('type_stats', $typeStats);
        $view->assign('type_label', FeatureMappingsView::TYPE_LABELS[$featureTypeFilter]);
        $view->assign('configured_feature_id', $configuredFeatureId);
        $view->assign('configured_feature_name', $repo->getFeatureNames([$configuredFeatureId], $lang)[$configuredFeatureId] ?? '');
        $view->assign('providers', $providers);
        $view->assign('coverage', $coverage);
        $view->assign('missing_providers', $missing);
        $view->assign('feature_types', $validFeatureTypes);
    }
}

// ── GET: Unmapped values ──
if ($mode === 'unmapped') {
    $page = max(1, RequestCoerce::int($_REQUEST, 'page', 1));
    $itemsPerPage = RequestCoerce::int($_REQUEST, 'items_per_page');
    if ($itemsPerPage <= 0) {
        $itemsPerPage = TypeCoerce::toInt(Registry::get('settings.Appearance.admin_elements_per_page')) ?: 25;
    }

    $sourceFilter = RequestCoerce::string($_REQUEST, 'api_source');
    $typeFilter = RequestCoerce::string($_REQUEST, 'feature_type');

    // Build condition using db_quote() (CS-Cart standard pattern)
    $condition = '';
    if ($sourceFilter !== '') {
        $condition .= db_quote(" AND api_source = ?s", $sourceFilter);
    }
    if ($typeFilter !== '') {
        $condition .= db_quote(" AND feature_type = ?s", $typeFilter);
    }

    $offset = ($page - 1) * $itemsPerPage;

    $paginatedUnmapped = $repo->getPaginatedUnmapped($condition, $offset, $itemsPerPage);
    $unmapped = $paginatedUnmapped['items'];
    $totalItems = $paginatedUnmapped['total'];

    $search = [
        'api_source'     => $sourceFilter,
        'feature_type'   => $typeFilter,
        'page'           => $page,
        'items_per_page' => $itemsPerPage,
        'total_items'    => $totalItems,
    ];

    $view->assign('unmapped_values', $unmapped);
    $view->assign('search', $search);
}

// ── GET: Scan progress (intermediate page between batches) ──
if ($mode === 'scan_progress') {
    $provider = preg_replace('/[^a-z0-9_]/', '', strtolower(RequestCoerce::string($_REQUEST, 'scan_provider')));
    $scanOffset = max(0, RequestCoerce::int($_REQUEST, 'scan_offset'));
    $scanTotal = max(0, RequestCoerce::int($_REQUEST, 'scan_total'));
    $batchSize = min(max(RequestCoerce::int($_REQUEST, 'batch_size', TravelConstants::BATCH_SIZE_DEFAULT), TravelConstants::BATCH_SIZE_MIN), TravelConstants::BATCH_SIZE_MAX);

    $percent = $scanTotal > 0 ? round($scanOffset / $scanTotal * 100, 1) : 0;

    $view->assign('scan_provider', $provider);
    $view->assign('scan_offset', $scanOffset);
    $view->assign('scan_total', $scanTotal);
    $view->assign('scan_percent', $percent);
    $view->assign('batch_size', $batchSize);
}

// ── GET: Edit single mapping ──
if ($mode === 'edit') {
    $mapId = RequestCoerce::int($_REQUEST, 'map_id');

    if ($mapId <= 0) {
        fn_set_notification('E', __('error'), __('travel_core.fm_mapping_not_found'));
        return [CONTROLLER_STATUS_REDIRECT, 'travel_feature_mappings.manage'];
    }

    $mapping = $repo->getMappingById($mapId);
    if (empty($mapping)) {
        fn_set_notification('E', __('error'), __('travel_core.fm_mapping_not_found'));
        return [CONTROLLER_STATUS_REDIRECT, 'travel_feature_mappings.manage'];
    }

    // Load all CS-Cart product features for the feature dropdown
    $allFeatures = $repo->findAllCsCartFeatures(TypeCoerce::toString(DESCR_SL));

    // Load variants for the currently selected feature
    $featureVariants = [];
    if (!empty($mapping['cscart_feature_id'])) {
        $featureVariants = $repo->findVariantsForFeature(TypeCoerce::toInt($mapping['cscart_feature_id']), TypeCoerce::toString(DESCR_SL));
    }

    // Load aliases for this mapping, grouped into one card per provider
    $aliases = $repo->getAliasesForMapping($mapId);
    $aliasCounts = $repo->getAliasCountsBySource();
    $providers = FeatureMappingsView::providers(array_map(static fn (array $p): string => $p['label'], TravelProviderRegistry::all()), $aliasCounts);
    $mappingType = TypeCoerce::toString($mapping['feature_type'] ?? '');

    $view->assign('alias_cards', FeatureMappingsView::aliasCards($providers, $aliasCounts, $mappingType, $aliases));
    $view->assign('type_label', FeatureMappingsView::TYPE_LABELS[$mappingType] ?? $mappingType);
    $view->assign('created_by_key', FeatureMappingsView::createdByKey(TypeCoerce::toString($mapping['mapping_source'] ?? '')));
    $view->assign('mapping', $mapping);
    $view->assign('all_features', $allFeatures);
    $view->assign('feature_variants', $featureVariants);
    $view->assign('aliases', $aliases);
}

// ── GET: AJAX — load variants for a feature (used by edit page) ──
if ($mode === 'get_variants') {
    $featureId = RequestCoerce::int($_REQUEST, 'feature_id');
    $variants = [];

    if ($featureId > 0) {
        $variants = $repo->findVariantsForFeature($featureId, TypeCoerce::toString(DESCR_SL));
    }

    if (defined('AJAX_REQUEST')) {
        $ajax = Tygh::$app['ajax'];
        if (is_object($ajax) && method_exists($ajax, 'assign')) {
            $ajax->assign('variants', $variants);
        }
    } else {
        header('Content-Type: application/json');
        fn_echo(json_encode($variants));
        exit;
    }
}
