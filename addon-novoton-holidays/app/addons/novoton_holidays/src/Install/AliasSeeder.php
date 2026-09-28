<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Install;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Services\FeatureMapper;

/**
 * Seeds Novoton's aliases into the shared ?:travel_api_alias table: each
 * Novoton API value → the travel_core mapping (canonical code) it means.
 *
 * Idempotent (FeatureMapper::addAlias is INSERT … ON DUPLICATE KEY UPDATE),
 * so it is safe to re-run. It runs at install, on the syncs that create
 * products, AND as an admin self-heal once per deployed version of the seed
 * data (init.php): Novoton's facility ids and star ratings are the same
 * strings "1"–"5", and while the alias key was (api_source, api_value) the
 * facility aliases took those values first — the star aliases were never
 * written, so Novoton hotels got no star rating. The key now includes
 * map_id; the self-heal is what writes the rows that were lost.
 */
final class AliasSeeder
{
    /** The seed runs again whenever one of these files changes. */
    public static function fingerprint(): string
    {
        return md5(
            (string) @md5_file(__FILE__)
            . (string) @md5_file(__DIR__ . '/NovotonAliasSeedData.php'),
        );
    }

    /**
     * @param string $tablePrefix The ?: table prefix, read at the init.php /
     *                            functions boundary (Registry access is banned in src/).
     */
    public static function seed(string $tablePrefix): void
    {
        if (!class_exists(FeatureMapper::class)) {
            return;
        }

        // travel_core not installed yet: nothing to seed into.
        $tableExists = db_get_field(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?s',
            $tablePrefix . 'travel_feature_map',
        );
        if (!$tableExists) {
            if (function_exists('fn_log_event')) {
                fn_log_event('general', 'runtime', [
                    'message' => 'Novoton: Skipping alias seeding — travel_feature_map table not found (travel_core not installed?)',
                ]);
            }

            return;
        }

        self::seedGroup('board', NovotonAliasSeedData::boardAliases());
        self::seedGroup('room_type', NovotonAliasSeedData::roomAliases());
        // Novoton sends star ratings as '1'–'5', the canonical codes themselves.
        self::seedGroup('stars', ['1' => '1', '2' => '2', '3' => '3', '4' => '4', '5' => '5']);
        self::seedGroup('property_type', NovotonAliasSeedData::propertyTypeAliases());
        self::seedGroup('hotel_facility', NovotonAliasSeedData::hotelFacilityAliases());
        self::seedGroup('room_facility', NovotonAliasSeedData::roomFacilityAliases());
        self::seedGroup('beach_access', NovotonAliasSeedData::beachAccessAliases());

        // Travel groups are derived from facilities at runtime
        // (TravelGroupResolver::derive); resorts are auto-registered by
        // FeatureMapper::handleUnmapped() — neither is seeded.

        FeatureMapper::clearCache();
    }

    /**
     * @param array<int|string, string> $aliases API value => canonical code
     */
    private static function seedGroup(string $featureType, array $aliases): void
    {
        // canonical_code → map_id for this feature type, one query per group.
        // toArrayMap, NOT toStringMap: numeric codes (the star ratings '1'–'5')
        // are int array keys in PHP and toStringMap drops them. That emptied
        // the stars group, so Novoton never got a star alias at all.
        $maps = [];
        foreach (TypeCoerce::toArrayMap(db_get_hash_single_array(
            'SELECT canonical_code, map_id FROM ?:travel_feature_map WHERE feature_type = ?s',
            ['canonical_code', 'map_id'],
            $featureType,
        )) as $code => $mapId) {
            $maps[(string) $code] = TypeCoerce::toInt($mapId);
        }
        foreach ($aliases as $apiValue => $canonicalCode) {
            $mapId = $maps[(string) $canonicalCode] ?? 0;
            if ($mapId > 0) {
                FeatureMapper::addAlias('novoton', (string) $apiValue, $mapId, 'exact');
            }
        }
    }
}
