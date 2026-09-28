<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Install;

use Tygh\Addons\Eurosite\Services\EurositeFeatureAssigner;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Services\FeatureMapper;

/**
 * Seeds Eurosite's aliases into the shared ?:travel_api_alias table, so its
 * hotels resolve into the same features as Novoton's and Sphinx's.
 *
 * Stars, property types, meal plans and room types reach FeatureMapper as
 * the canonical codes EurositeNormalizer already produced, so those aliases
 * are identities. Facilities are the operator's own words from the hotel
 * text ("aer conditionat", "Wi-fi", "parcare gratuita", FacilityTextParser),
 * keyed as EurositeFeatureAssigner::facilityKey() writes them; the ones not
 * listed here land on the Unmapped values page to be linked there.
 *
 * Idempotent (FeatureMapper::addAlias is INSERT … ON DUPLICATE KEY UPDATE);
 * init.php re-runs it as an admin self-heal whenever this file changes.
 */
final class EurositeAliasSeeder
{
    /** Canonical codes EurositeNormalizer returns, per type (aliased to themselves). */
    public const array IDENTITY = [
        'stars' => ['1', '2', '3', '4', '5'],
        'property_type' => ['hotel', 'villa', 'apartment', 'guest_house', 'hostel', 'resort'],
        'board' => ['UAI', 'AIL', 'AI', 'FB', 'HB', 'BB', 'RO', 'SC'],
        'room_type' => ['SGL', 'DBL', 'TWIN', 'TRP', 'QUAD', 'APT', 'STUDIO', 'SUITE'],
    ];

    /**
     * Facility label (facilityKey form: lower case, no diacritics) => canonical code.
     * The facility type is found by FeatureMapper across hotel_facility,
     * room_facility and beach_access.
     */
    public const array FACILITIES = [
        'aer conditionat' => 'air_conditioning',
        'aer conditionat in camera' => 'air_conditioning',
        'climatizare' => 'air_conditioning',
        'air conditioning' => 'air_conditioning',
        'incalzire' => 'heating',
        'centrala termica' => 'heating',
        'incalzire centrala' => 'heating',
        'wi-fi' => 'free_wifi',
        'wifi' => 'free_wifi',
        'wi fi' => 'free_wifi',
        'wi-fi gratuit' => 'free_wifi',
        'wifi gratuit' => 'free_wifi',
        'internet wireless' => 'free_wifi',
        'wireless' => 'free_wifi',
        'internet' => 'internet',
        'tv' => 'tv',
        'televizor' => 'tv',
        'tv satelit' => 'tv',
        'tv prin satelit' => 'tv',
        'cablu tv' => 'cable_channels',
        'tv cablu' => 'cable_channels',
        'tv prin cablu' => 'cable_channels',
        'tv lcd' => 'flat_screen_tv',
        'lcd tv' => 'flat_screen_tv',
        'tv plasma' => 'flat_screen_tv',
        'telefon' => 'telephone',
        'minibar' => 'minibar',
        'frigider' => 'fridge',
        'minifrigider' => 'fridge',
        'mini frigider' => 'fridge',
        'seif' => 'safe',
        'seif in camera' => 'safe',
        'seif la receptie' => 'safety_deposit_box',
        'uscator de par' => 'hair_dryer',
        'uscator par' => 'hair_dryer',
        'grup sanitar propriu' => 'private_bathroom',
        'baie proprie' => 'private_bathroom',
        'baie privata' => 'private_bathroom',
        'dus' => 'shower',
        'cabina de dus' => 'shower',
        'cada' => 'bathtub',
        'birou' => 'desk',
        'bucatarie' => 'kitchenette',
        'bucatarie complet utilata' => 'kitchenette',
        'chicineta' => 'kitchenette',
        'kitchenette' => 'kitchenette',
        'restaurant' => 'restaurant',
        'restaurant a la carte' => 'restaurant_alacarte',
        'bar' => 'bar',
        'lobby bar' => 'bar',
        'bar la piscina' => 'bar',
        'pool bar' => 'bar',
        'cafenea' => 'cafe',
        'room service' => 'room_service',
        'room-service' => 'room_service',
        'parcare' => 'parking',
        'parcare privata' => 'parking',
        'parcare gratuita' => 'free_parking',
        'parcare gratuit' => 'free_parking',
        'parcare pazita' => 'secured_parking',
        'parcare supravegheata' => 'secured_parking',
        'piscina' => 'pool',
        'piscina exterioara' => 'pool',
        'piscina interioara' => 'pool',
        'piscina acoperita' => 'pool',
        'piscina in aer liber' => 'pool',
        'piscina pentru copii' => 'kids_pool',
        'piscina copii' => 'kids_pool',
        'spa' => 'spa',
        'centru spa' => 'spa',
        'sauna' => 'sauna',
        'masaj' => 'massage',
        'fitness' => 'fitness',
        'sala de fitness' => 'fitness',
        'sala fitness' => 'fitness',
        'centru fitness' => 'fitness',
        'terasa' => 'terrace',
        'gradina' => 'garden',
        'receptie 24/24' => 'front_desk_24h',
        'receptie 24h' => 'front_desk_24h',
        'receptie non-stop' => 'front_desk_24h',
        'receptie nonstop' => 'front_desk_24h',
        'loc de joaca' => 'playground',
        'loc de joaca pentru copii' => 'playground',
        'miniclub' => 'kids_club',
        'mini club' => 'kids_club',
        'club pentru copii' => 'kids_club',
        'animale de companie acceptate' => 'pets_allowed',
        'se accepta animale de companie' => 'pets_allowed',
        'pet friendly' => 'pets_allowed',
        'sala de conferinte' => 'conference_rooms',
        'sala conferinte' => 'conference_rooms',
        'spalatorie' => 'laundry',
        'transfer aeroport' => 'airport_transfer',
        'acces persoane cu dizabilitati' => 'disabled_access',
        'acces pentru persoane cu dizabilitati' => 'disabled_access',
        'camere pentru nefumatori' => 'non_smoking_rooms',
        'camere nefumatori' => 'non_smoking_rooms',
        'camere de familie' => 'family_rooms',
        'teren de tenis' => 'tennis',
        'tenis' => 'tennis',
        'schimb valutar' => 'currency_exchange',
        'pastrare bagaje' => 'luggage_storage',
        'depozitare bagaje' => 'luggage_storage',
        'sezlonguri si umbrele gratuite' => 'free_beach_equipment',
        'bar pe plaja' => 'beach_bar',
    ];

    /** The seed runs again whenever this file (the data) changes. */
    public static function fingerprint(): string
    {
        return (string) @md5_file(__FILE__);
    }

    /**
     * @param string $tablePrefix The ?: table prefix (read at the init.php boundary).
     */
    public static function seed(string $tablePrefix): void
    {
        if (!class_exists(FeatureMapper::class)) {
            return;
        }
        $tableExists = db_get_field(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?s',
            $tablePrefix . 'travel_feature_map',
        );
        if (!$tableExists) {
            return;
        }

        foreach (self::IDENTITY as $type => $codes) {
            $maps = self::mapIds([$type]);
            foreach ($codes as $code) {
                $mapId = $maps[$type][$code] ?? 0;
                if ($mapId > 0) {
                    FeatureMapper::addAlias(EurositeFeatureAssigner::SOURCE, $code, $mapId, 'exact');
                }
            }
        }

        $maps = self::mapIds(FeatureMapper::FACILITY_TYPES);
        foreach (self::FACILITIES as $label => $code) {
            foreach (FeatureMapper::FACILITY_TYPES as $type) {
                $mapId = $maps[$type][$code] ?? 0;
                if ($mapId > 0) {
                    FeatureMapper::addAlias(EurositeFeatureAssigner::SOURCE, $label, $mapId, 'exact');
                    break;
                }
            }
        }

        FeatureMapper::clearCache();
    }

    /**
     * canonical_code => map_id per type. Row lists, not a code-keyed hash:
     * numeric codes ('1'–'5') would become int keys and get lost (the bug
     * that kept Novoton's star aliases from ever being written).
     *
     * @param list<string> $types
     * @return array<string, array<string, int>>
     */
    private static function mapIds(array $types): array
    {
        $maps = [];
        foreach (TypeCoerce::toList(db_get_array(
            'SELECT feature_type, canonical_code, map_id FROM ?:travel_feature_map WHERE feature_type IN (?a)',
            $types,
        )) as $row) {
            $row = TypeCoerce::toStringMap($row);
            $maps[TypeCoerce::toString($row['feature_type'] ?? '')][TypeCoerce::toString($row['canonical_code'] ?? '')] = TypeCoerce::toInt($row['map_id'] ?? 0);
        }

        return $maps;
    }
}
