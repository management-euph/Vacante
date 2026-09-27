<?php

declare(strict_types=1);
/***************************************************************************
 *                                                                          *
 *   (c) 2024-2026 VacanteLitoral.ro                                       *
 *                                                                          *
 *   Location: app/addons/travel_core/func.php                             *
 *                                                                          *
 ***************************************************************************/

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

// SELF-SUFFICIENCY GUARD: during travel_core's OWN installation the addon is
// not yet active, so CS-Cart never runs init.php — and init.php is the only
// thing that registers the Tygh\Addons\TravelCore\* autoloader. CS-Cart DOES
// load this file and calls the fn_settings_variants_addons_travel_core_*
// callbacks to build the selectbox settings, so any class referenced from
// here must be loaded explicitly or the install dies with a silent fatal
// ("click Install, nothing happens"). Guarded by FuncSelfSufficiencyTest.
require_once __DIR__ . '/src/Helpers/TypeCoerce.php';

/**
 * Addon uninstall function.
 * Drops shared tables and cleans up language variables.
 */
function fn_travel_core_uninstall(): bool
{
    // Block uninstall if provider addons are still active.
    //
    // Deliberately a LITERAL, not TravelProviderRegistry::KNOWN_PROVIDER_ADDONS:
    // this runs during uninstall, where init.php — the only registrar of the
    // Tygh\Addons\TravelCore\* autoloader — may not have run. Referencing the
    // class here would reintroduce exactly the bootstrap coupling the provider
    // init.php files were just cleaned of.
    //
    // The cost of a literal is drift, and it drifted: eurosite was added to
    // the registry and not to this list, so travel_core could be uninstalled
    // out from under a live eurosite — dropping ?:travel_bookings,
    // ?:travel_api_alias and ?:travel_feature_map, all of which eurosite
    // writes to. UninstallGuardCoverageTest now fails if the two disagree.
    $active_providers = [];
    $provider_addons = ['novoton_holidays', 'sphinx_holidays', 'eurosite'];
    foreach ($provider_addons as $addon) {
        $status = db_get_field('SELECT status FROM ?:addons WHERE addon = ?s', $addon);
        if ($status === 'A') {
            $active_providers[] = $addon;
        }
    }

    if (!empty($active_providers)) {
        fn_set_notification(
            'E',
            __('error'),
            'Cannot disable travel_core: the following addons depend on it: '
            . implode(', ', $active_providers)
            . '. Please disable them first.',
        );
        return false;
    }

    // Drop shared tables (order matters: aliases reference feature_map)
    db_query('DROP TABLE IF EXISTS ?:travel_api_alias');
    db_query('DROP TABLE IF EXISTS ?:travel_unmapped_values');
    db_query('DROP TABLE IF EXISTS ?:travel_bookings');
    db_query('DROP TABLE IF EXISTS ?:travel_alternative_requests');
    db_query('DROP TABLE IF EXISTS ?:travel_feature_map');

    // Remove language variables
    db_query("DELETE FROM ?:language_values WHERE name LIKE 'travel_core.%'");

    return true;
}

/**
 * Post-install function.
 * Seeds feature mapping data. Settings and their labels are handled
 * by addon.xml <settings> and PO files (same approach as novoton_holidays).
 */
function fn_travel_core_post_install(): bool
{
    fn_travel_core_ensure_schema();
    fn_travel_core_seed_feature_map();
    // Seed the labels rather than trusting CS-Cart's install-time .po import:
    // it only covers the languages the store had when the pack was imported,
    // and lang_keys.php entries added since the .po was last regenerated would
    // otherwise wait for the runtime probe to notice them.
    fn_travel_core_seed_language_keys();
    return true;
}

/**
 * Ensure travel_feature_map has all required columns (safe for re-installs).
 */
function fn_travel_core_ensure_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $columns = db_get_fields('SHOW COLUMNS FROM ?:travel_feature_map');
    if (!is_array($columns)) {
        return;
    }
    $columnSet = array_flip(array_map(static fn ($c): string => is_scalar($c) ? (string) $c : '', $columns));

    if (!isset($columnSet['variant_source'])) {
        db_query("ALTER TABLE ?:travel_feature_map ADD COLUMN `variant_source` ENUM('auto','manual') DEFAULT 'auto' COMMENT 'manual = admin-locked' AFTER `cscart_variant_id`");
    }
    if (!isset($columnSet['mapping_source'])) {
        db_query("ALTER TABLE ?:travel_feature_map ADD COLUMN `mapping_source` ENUM('seed','auto','manual') DEFAULT 'seed' COMMENT 'How this row was created' AFTER `variant_source`");
    }
    if (!isset($columnSet['last_used_at'])) {
        db_query("ALTER TABLE ?:travel_feature_map ADD COLUMN `last_used_at` TIMESTAMP DEFAULT NULL COMMENT 'Last time resolve() matched this row' AFTER `status`");
    }

    // Migrate travel_api_alias unique key to include map_id.
    // The old key (api_source, api_value) allowed facility aliases to silently
    // overwrite star aliases that share the same numeric api_value (e.g. sphinx
    // facility '4' = water_bottle overwrote star '4' = 4-star). The new key
    // lets both coexist — findByAlias() already filters by feature_type.
    // Ensure TypeCoerce is available — the PSR-4 autoloader in init.php is not
    // registered yet when this function is called from post_install.
    require_once __DIR__ . '/src/Helpers/TypeCoerce.php';
    $tablePrefix = \Tygh\Addons\TravelCore\Helpers\TypeCoerce::toString(\Tygh\Registry::get('config.table_prefix'));
    $aliasTable = $tablePrefix . 'travel_api_alias';
    $tableExists = \Tygh\Addons\TravelCore\Helpers\TypeCoerce::toInt(db_get_field(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?s',
        $aliasTable,
    ));
    if ($tableExists > 0) {
        $hasMapIdInKey = \Tygh\Addons\TravelCore\Helpers\TypeCoerce::toInt(db_get_field(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?s
               AND INDEX_NAME = 'uq_source_value'
               AND COLUMN_NAME = 'map_id'",
            $aliasTable,
        ));
        if ($hasMapIdInKey === 0) {
            db_query('ALTER TABLE ?:travel_api_alias DROP INDEX `uq_source_value`');
            db_query('ALTER TABLE ?:travel_api_alias ADD UNIQUE KEY `uq_source_value` (`api_source`, `api_value`, `map_id`)');
        }
    }

    // Bookings grid sorts by created_at DESC by default — backfill the index
    // on installs that predate it (the addon.xml CREATE only covers fresh
    // installs; without it every grid load filesorts the whole table).
    $bookingsTable = $tablePrefix . 'travel_bookings';
    $bookingsExists = \Tygh\Addons\TravelCore\Helpers\TypeCoerce::toInt(db_get_field(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?s',
        $bookingsTable,
    ));
    if ($bookingsExists > 0) {
        $hasCreatedAtIdx = \Tygh\Addons\TravelCore\Helpers\TypeCoerce::toInt(db_get_field(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?s
               AND INDEX_NAME = 'idx_created_at'",
            $bookingsTable,
        ));
        if ($hasCreatedAtIdx === 0) {
            db_query('ALTER TABLE ?:travel_bookings ADD KEY `idx_created_at` (`created_at`)');
        }
    }

    // Ensure travel_unmapped_values table exists
    db_query(
        'CREATE TABLE IF NOT EXISTS `?:travel_unmapped_values` (
            `unmapped_id`    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `api_source`     VARCHAR(50)  NOT NULL,
            `feature_type`   VARCHAR(50)  NOT NULL,
            `api_value`      VARCHAR(191) NOT NULL,
            `api_label`      VARCHAR(255) DEFAULT NULL,
            `hotel_count`    INT UNSIGNED DEFAULT 1,
            `first_seen_at`  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
            `last_seen_at`   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uq_source_type_value` (`api_source`, `feature_type`, `api_value`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
    );
}

/**
 * Build a dropdown list of all CS-Cart product features for addon settings.
 * Used by fn_settings_variants_addons_travel_core_feature_id_*() functions.
 *
 * @return array<int, string> feature_id => "Description #id (Type)"
 */
function fn_travel_core_get_feature_variants(): array
{
    $features = db_get_array(
        'SELECT f.feature_id, f.feature_type, fd.description
         FROM ?:product_features f
         LEFT JOIN ?:product_features_descriptions fd ON f.feature_id = fd.feature_id AND fd.lang_code = ?s
         ORDER BY fd.description',
        DESCR_SL,
    );

    $result = [0 => '-- ' . \Tygh\Addons\TravelCore\Helpers\TypeCoerce::toString(__('none')) . ' --'];
    foreach (\Tygh\Addons\TravelCore\Helpers\TypeCoerce::toRowList($features) as $f) {
        $featureType = \Tygh\Addons\TravelCore\Helpers\TypeCoerce::toString($f['feature_type'] ?? '');
        $featureId = \Tygh\Addons\TravelCore\Helpers\TypeCoerce::toInt($f['feature_id'] ?? 0);
        $description = \Tygh\Addons\TravelCore\Helpers\TypeCoerce::toString($f['description'] ?? '');
        $typeLabel = match ($featureType) {
            'M' => 'Multi',
            'S' => 'Select',
            'C' => 'Checkbox',
            'T' => 'Text',
            'N' => 'Number',
            'O' => 'Date',
            default => $featureType,
        };
        $result[$featureId] = ($description !== '' ? $description : 'Feature') . " #{$featureId} ({$typeLabel})";
    }

    return $result;
}

// CS-Cart auto-discovers these by naming convention for <type>selectbox</type> settings

/** @return array<int, string> */
function fn_settings_variants_addons_travel_core_feature_id_property_rating(): array
{
    return fn_travel_core_get_feature_variants();
}
/** @return array<int, string> */
function fn_settings_variants_addons_travel_core_feature_id_meals(): array
{
    return fn_travel_core_get_feature_variants();
}
/** @return array<int, string> */
function fn_settings_variants_addons_travel_core_feature_id_room_type(): array
{
    return fn_travel_core_get_feature_variants();
}
/** @return array<int, string> */
function fn_settings_variants_addons_travel_core_feature_id_property_type(): array
{
    return fn_travel_core_get_feature_variants();
}
/** @return array<int, string> */
function fn_settings_variants_addons_travel_core_feature_id_location(): array
{
    return fn_travel_core_get_feature_variants();
}
/** @return array<int, string> */
function fn_settings_variants_addons_travel_core_feature_id_region(): array
{
    return fn_travel_core_get_feature_variants();
}
/** @return array<int, string> */
function fn_settings_variants_addons_travel_core_feature_id_city(): array
{
    return fn_travel_core_get_feature_variants();
}
/** @return array<int, string> */
function fn_settings_variants_addons_travel_core_feature_id_travel_group(): array
{
    return fn_travel_core_get_feature_variants();
}
/** @return array<int, string> */
function fn_settings_variants_addons_travel_core_feature_id_hotel_facility(): array
{
    return fn_travel_core_get_feature_variants();
}
/** @return array<int, string> */
function fn_settings_variants_addons_travel_core_feature_id_room_facility(): array
{
    return fn_travel_core_get_feature_variants();
}
/** @return array<int, string> */
function fn_settings_variants_addons_travel_core_feature_id_beach_access(): array
{
    return fn_travel_core_get_feature_variants();
}

/**
 * Variants function for the default_currency addon setting.
 * Pulls currencies from CS-Cart's configured currencies.
 *
 * @return array<string, string>
 */
function fn_settings_variants_addons_travel_core_default_currency(): array
{
    $currencies = \Tygh\Registry::get('currencies');
    $result = [];

    if (empty($currencies) || !is_array($currencies)) {
        return $result;
    }

    foreach ($currencies as $code => $currency) {
        $currencyRow = \Tygh\Addons\TravelCore\Helpers\TypeCoerce::toStringMap($currency);
        $symbol = \Tygh\Addons\TravelCore\Helpers\TypeCoerce::toString($currencyRow['symbol'] ?? '');
        $result[(string) $code] = (string) $code . ($symbol !== '' ? ' (' . $symbol . ')' : '');
    }

    return $result;
}

/**
 * Options of "Skip the cart page for" (a multiple-checkboxes setting).
 *
 * Supplied here, not only by addon.xml, because a store that got the setting
 * through SettingsMigrator's self-heal has no ?:settings_variants labels, and
 * CS-Cart lists no option without one ("No items defined"). The keys are the
 * add-on ids CartSkipPolicy checks; the names are brands, the same in every
 * language.
 *
 * @return array<string, string>
 */
function fn_settings_variants_addons_travel_core_skip_cart_for(): array
{
    return [
        'novoton_holidays' => 'Novoton Holidays',
        'sphinx_holidays' => 'Sphinx Holidays',
        'eurosite' => 'Eurosite',
    ];
}

/**
 * Seed the travel_feature_map table with canonical codes.
 * Idempotent — uses INSERT IGNORE to skip existing entries.
 */
function fn_travel_core_seed_feature_map(): void
{
    $seeds = [
        // Board/Meal plans
        ['board', 'AI',  'All Inclusive',      'All Inclusive'],
        ['board', 'AIL', 'All Inclusive Light', 'All Inclusive Light'],
        ['board', 'UAI', 'Ultra All Inclusive', 'Ultra All Inclusive'],
        ['board', 'FB',  'Full Board',         'Pensiune completă'],
        ['board', 'HB',  'Half Board',         'Demipensiune'],
        ['board', 'BB',  'Bed & Breakfast',    'Mic dejun'],
        ['board', 'RO',  'Room Only',          'Fără masă'],
        ['board', 'SC',  'Self Catering',      'Self Catering'],

        // Room types
        ['room_type', 'SGL',   'Single Room',    'Cameră single'],
        ['room_type', 'DBL',   'Double Room',    'Cameră dublă'],
        ['room_type', 'TWIN',  'Twin Room',      'Cameră twin'],
        ['room_type', 'TRP',   'Triple Room',    'Cameră triplă'],
        ['room_type', 'QUAD',  'Quadruple Room', 'Cameră cvadruplă'],
        ['room_type', 'SUITE', 'Suite',          'Suită'],
        ['room_type', 'APT',   'Apartment',      'Apartament'],
        ['room_type', 'STUDIO','Studio',         'Studio'],

        // Star ratings
        ['stars', '1', '1 Star',  '1 Stea'],
        ['stars', '2', '2 Stars', '2 Stele'],
        ['stars', '3', '3 Stars', '3 Stele'],
        ['stars', '4', '4 Stars', '4 Stele'],
        ['stars', '5', '5 Stars', '5 Stele'],

        // Property types
        ['property_type', 'hotel',          'Hotel',          'Hotel'],
        ['property_type', 'villa',          'Villa',          'Vilă'],
        ['property_type', 'apartment',      'Apartment',      'Apartament'],
        ['property_type', 'resort',         'Resort',         'Resort'],
        ['property_type', 'hostel',         'Hostel',         'Hostel'],
        ['property_type', 'guest_house',    'Guest House',    'Pensiune'],
        ['property_type', 'chalet',         'Chalet',         'Cabană'],
        ['property_type', 'motel',          'Motel',          'Motel'],
        ['property_type', 'boarding_house', 'Boarding House', 'Pensiune'],
        ['property_type', 'cabin',          'Cabin',          'Cabană'],

        // Travel group — target audience / travel style (M-type: hotel can have multiple)
        ['travel_group', 'adults_only',     'Adults Only',      'Exclusiv pentru adulți'],
        ['travel_group', 'family_friendly', 'Family Friendly',  'Potrivit pentru familii'],
        ['travel_group', 'pets_friendly',   'Pets Allowed',     'Acceptă animale de companie'],

        // ── Hotel Facilities — property-level amenities ──
        // Food & Drink
        ['hotel_facility', 'kids_menu',           "Kid's Menu",               'Meniu masă pentru copii'],
        ['hotel_facility', 'water_bottle',        'Bottle of Water',          'Sticlă de apă'],
        ['hotel_facility', 'fruits',              'Fruits',                   'Fructe'],
        ['hotel_facility', 'restaurant',          'Restaurant',               'Restaurant'],
        ['hotel_facility', 'restaurant_alacarte', 'Restaurant (à la carte)',  'Restaurant (à la carte)'],
        ['hotel_facility', 'bar',                 'Bar',                      'Bar'],
        ['hotel_facility', 'packed_lunch',        'Packed Lunch',             'Prânz la pachet'],
        ['hotel_facility', 'special_diet',        'Special Diet Menus',       'Meniuri cu dietă specială'],
        ['hotel_facility', 'room_service',        'Room Service',             'Room service'],
        ['hotel_facility', 'breakfast_in_room',   'Breakfast in Room',        'Mic dejun în cameră'],
        // Wellness & Recreation
        ['hotel_facility', 'spa',                 'Spa Facilities',           'Facilități spa'],
        ['hotel_facility', 'fitness',             'Fitness Centre',           'Fitness'],
        ['hotel_facility', 'pool',                'Swimming Pool',            'Piscină'],
        ['hotel_facility', 'massage',             'Massage',                  'Masaj'],
        ['hotel_facility', 'casino',              'Casino',                   'Cazino'],
        ['hotel_facility', 'full_body_massage',   'Full Body Massage',        'Masaj pentru tot corpul'],
        ['hotel_facility', 'ski',                 'Skiing',                   'Schi'],
        ['hotel_facility', 'hiking',              'Hiking',                   'Drumeții'],
        ['hotel_facility', 'squash',              'Squash',                   'Squash'],
        ['hotel_facility', 'cycling',             'Cycling',                  'Ciclism'],
        ['hotel_facility', 'bowling',             'Bowling',                  'Bowling'],
        ['hotel_facility', 'game_room',           'Game Room',                'Cameră de jocuri'],
        ['hotel_facility', 'aqua_park',           'Aqua Park',                'Aqua park'],
        ['hotel_facility', 'tennis',              'Tennis Court',             'Teren de tenis'],
        ['hotel_facility', 'horse_riding',        'Horse Riding',             'Călărie'],
        ['hotel_facility', 'ski_school',          'Ski School',               'Școală de schi'],
        ['hotel_facility', 'bike_rental',         'Bicycle Rental',           'Închiriere de biciclete'],
        ['hotel_facility', 'relaxation_area',     'Relaxation Area',          'Zonă de relaxare'],
        ['hotel_facility', 'sauna',               'Sauna',                    'Saună'],
        ['hotel_facility', 'entertainment',       'Entertainment',            'Divertisment'],
        ['hotel_facility', 'balneology',          'Balneology',               'Balneologie'],
        // Parking & Transport
        ['hotel_facility', 'free_parking',        'Free Parking',             'Parcare gratuită'],
        ['hotel_facility', 'secured_parking',     'Secured Parking',          'Parcare securizată'],
        ['hotel_facility', 'transfer_service',    'Transfer Service',         'Serviciu de transfer'],
        ['hotel_facility', 'airport_transfer',    'Airport Transfer',         'Transfer aeroport'],
        ['hotel_facility', 'car_rental',          'Car Rental',               'Închirieri auto'],
        ['hotel_facility', 'bike_tours',          'Bicycle Tours',            'Tururi cu bicicleta'],
        ['hotel_facility', 'walking_tours',       'Walking Tours',            'Tururi de mers pe jos'],
        ['hotel_facility', 'parking',             'Parking',                  'Parcare'],
        ['hotel_facility', 'ski_lift_transfer',   'Transport to Ski Lift',    'Transport la telescaun'],
        ['hotel_facility', 'ev_charger',          'Electric Car Charger',     'Stație încărcare mașini electrice'],
        // Front Desk & Services
        ['hotel_facility', 'front_desk_24h',      '24-Hour Front Desk',       'Recepție non-stop'],
        ['hotel_facility', 'tour_desk',           'Tour Desk',                'Birou de turism'],
        ['hotel_facility', 'currency_exchange',   'Currency Exchange',        'Schimb valutar'],
        ['hotel_facility', 'luggage_storage',     'Luggage Storage',          'Cameră de bagaje'],
        ['hotel_facility', 'safety_deposit_box',  'Safety Deposit Box',       'Seif la recepție'],
        ['hotel_facility', 'strollers',           'Strollers',                'Cărucioare'],
        ['hotel_facility', 'dry_cleaning',        'Dry Cleaning',             'Curățătorie chimică'],
        ['hotel_facility', 'ironing_service',     'Ironing Service',          'Serviciu de călcătorie'],
        ['hotel_facility', 'laundry',             'Laundry',                  'Spălătorie'],
        ['hotel_facility', 'daily_housekeeping',  'Daily Housekeeping',       'Menaj zilnic'],
        ['hotel_facility', 'meeting_facilities',  'Meeting/Banquet Facilities','Săli de conferințe'],
        ['hotel_facility', 'business_centre',     'Business Centre',          'Business centre'],
        ['hotel_facility', 'fax',                 'Fax/Photocopying',         'Fax'],
        ['hotel_facility', 'conference_rooms',    'Conference Rooms',         'Săli de conferințe'],
        ['hotel_facility', 'wake_up_service',     'Wake-up Service',          'Serviciu de trezire'],
        ['hotel_facility', 'express_checkin',     'Express Check-in/Check-out','Check-in/check-out express'],
        ['hotel_facility', 'babysitting',         'Babysitting/Child Services','Babysitting/servicii copii'],
        ['hotel_facility', 'cafe',                'On-site Café',             'Cafenea la proprietate'],
        ['hotel_facility', 'invoice_available',   'Invoice Available',        'Factură disponibilă'],
        // Outdoor
        ['hotel_facility', 'outdoor_furniture',   'Outdoor Furniture',        'Mobilier exterior'],
        ['hotel_facility', 'garden',              'Garden',                   'Grădină'],
        ['hotel_facility', 'terrace',             'Terrace',                  'Terasă'],
        ['hotel_facility', 'sun_terrace',         'Sun Terrace',              'Terasă la soare'],
        // Security
        ['hotel_facility', 'security_24h',        '24-Hour Security',         'Securitate non-stop'],
        ['hotel_facility', 'security_alarm',      'Security Alarm',           'Alarmă de securitate'],
        ['hotel_facility', 'fire_extinguishers',  'Fire Extinguishers',       'Extinctoare'],
        ['hotel_facility', 'co_detector',         'Carbon Monoxide Detector', 'Detector de monoxid de carbon'],
        ['hotel_facility', 'card_access',         'Card Access',              'Acces cu cardul'],
        ['hotel_facility', 'cctv_common',         'CCTV in Common Areas',     'Camere supraveghere zone comune'],
        ['hotel_facility', 'cctv_outside',        'CCTV Outside Property',    'Camere supraveghere exterior'],
        ['hotel_facility', 'smoke_alarm',         'Smoke Alarm',              'Alarmă de fum'],
        ['hotel_facility', 'key_access',          'Key Access',               'Acces cu cheia'],
        // Policies & Groups
        ['hotel_facility', 'pets_allowed',        'Pets Allowed',             'Animale de companie permise'],
        ['hotel_facility', 'non_smoking',         'Non-smoking Throughout',   'Interzis fumatul'],
        ['hotel_facility', 'smoking_area',        'Smoking Area',             'Zonă pentru fumători'],
        ['hotel_facility', 'non_smoking_rooms',   'Non-smoking Rooms',        'Camere pentru nefumători'],
        ['hotel_facility', 'no_smoking_all',      'No Smoking Everywhere',    'Fumatul interzis în toate spațiile'],
        ['hotel_facility', 'family_rooms',        'Family Rooms',             'Camere de familie'],
        ['hotel_facility', 'disabled_access',     'Facilities for Disabled',  'Facilități pentru persoane cu dizabilități'],
        ['hotel_facility', 'stairs_only',         'Upper Floors by Stairs Only','Etaje superioare accesibile doar pe scări'],
        ['hotel_facility', 'shared_lounge',       'Shared Lounge/TV Area',    'Lounge/cameră cu TV comună'],
        // Family & Kids
        ['hotel_facility', 'kids_club',           'Kids Club',                'Club pentru copii'],
        ['hotel_facility', 'kids_pool',           "Children's Pool",          'Piscină pentru copii'],
        ['hotel_facility', 'playground',          'Playground',               'Loc de joacă'],
        // Sustainability
        ['hotel_facility', 'travel_sustainable',  'Travel Sustainable',       'Turism sustenabil'],

        // ── Beach Access — beach & location amenities ──
        ['beach_access', 'free_beach_equipment','Free Umbrella & Sunbed',   'Umbrelă și șezlong gratuit'],
        ['beach_access', 'beach_bar',           'Beach Bar',                'Bar pe plajă'],
        ['beach_access', 'blue_flag_beach',     'Blue Flag Beach',          'Plajă cu Steag Albastru'],
        ['beach_access', 'first_line',          'First Line',               'Prima linie'],

        // ── Room Facilities — in-room amenities ──
        ['room_facility', 'air_conditioning',    'Air Conditioning',         'Aer condiționat'],
        ['room_facility', 'heating',             'Heating',                  'Încălzire'],
        ['room_facility', 'free_wifi',           'Free Wi-Fi',               'Wi-Fi gratuit'],
        ['room_facility', 'washer',              'Washer',                   'Mașină de spălat'],
        ['room_facility', 'ski_storage',         'Ski Storage',              'Depozit schiuri'],
        ['room_facility', 'tv',                  'TV',                       'TV'],
        ['room_facility', 'fan',                 'Fan',                      'Ventilator'],
        ['room_facility', 'desk',               'Desk',                     'Birou'],
        ['room_facility', 'shower',              'Shower',                   'Duș'],
        ['room_facility', 'view',                'View',                     'Vedere'],
        ['room_facility', 'minibar',             'Minibar',                  'Minibar'],
        ['room_facility', 'toilet',              'Toilet',                   'Toaletă'],
        ['room_facility', 'towels',              'Towels',                   'Prosoape'],
        ['room_facility', 'bed_linen',           'Bed Linen',                'Lenjerie de pat'],
        ['room_facility', 'slippers',            'Slippers',                 'Papuci de casă'],
        ['room_facility', 'telephone',           'Telephone',                'Telefon'],
        ['room_facility', 'hair_dryer',          'Hair Dryer',               'Uscător de păr'],
        ['room_facility', 'alarm_clock',         'Alarm Clock',              'Ceas deșteptător'],
        ['room_facility', 'toilet_paper',        'Toilet Paper',             'Hârtie igienică'],
        ['room_facility', 'flat_screen_tv',      'Flat-screen TV',           'TV cu ecran plat'],
        ['room_facility', 'soundproofing',       'Soundproofing',            'Izolare fonică'],
        ['room_facility', 'soundproof_rooms',    'Soundproof Rooms',         'Camere izolate fonic'],
        ['room_facility', 'dressing_room',       'Dressing Room',            'Dressing'],
        ['room_facility', 'cable_channels',      'Cable Channels',           'Canale prin cablu'],
        ['room_facility', 'carpet',              'Carpet',                   'Mochetă'],
        ['room_facility', 'free_toiletries',     'Free Toiletries',          'Articole de toaletă gratuite'],
        ['room_facility', 'private_bathroom',    'Private Bathroom',         'Baie privată'],
        ['room_facility', 'private_entrance',    'Private Entrance',         'Intrare privată'],
        ['room_facility', 'safe',                'In-room Safe',             'Seif'],
        ['room_facility', 'internet',            'Internet Services',        'Servicii de internet'],
        ['room_facility', 'games_puzzles',       'Games & Puzzles',          'Jocuri și puzzle-uri'],
        ['room_facility', 'bedside_socket',      'Socket Near Bed',          'Priză lângă pat'],
        ['room_facility', 'mosquito_net',        'Mosquito Net',             'Plasă de țânțari'],
        ['room_facility', 'fridge',              'Fridge',                   'Frigider'],
        ['room_facility', 'wine_champagne',      'Wine/Champagne',           'Vin/Șampanie'],
        ['room_facility', 'wardrobe',            'Wardrobe/Closet',          'Garderobă sau dulap'],
        ['room_facility', 'kitchenette',         'Kitchenette',              'Chicineta'],
        ['room_facility', 'bathtub',             'Bathtub',                  'Cadă de baie'],
        ['room_facility', 'baby_crib',           'Baby Crib',                'Pătuț pentru bebeluși'],
    ];

    foreach ($seeds as [$featureType, $canonicalCode, $nameEn, $nameRo]) {
        db_query(
            'INSERT IGNORE INTO ?:travel_feature_map (feature_type, canonical_code, display_name_en, display_name_ro)
             VALUES (?s, ?s, ?s, ?s)',
            $featureType,
            $canonicalCode,
            $nameEn,
            $nameRo,
        );
    }
}

/**
 * Force a full language (re)seed, ignoring both the stamp and the probe.
 *
 * The delivery rules — which installed languages get which source values, and
 * the read-back that must succeed before a language is stamped as done — live
 * in LanguageDelivery so all three addons share them. The self-healing entry
 * point is fn_travel_core_heal_language_keys() (functions/self_heal.php);
 * this shell is what post_install and dev/tools/seed-langs.php call when the
 * caller wants the write attempted unconditionally.
 */
function fn_travel_core_seed_language_keys(): void
{
    \Tygh\Addons\TravelCore\Install\LanguageDelivery::seed(
        'travel_core._lang_seed_hash',
        fn_travel_core_language_variables(),
        fn_travel_core_language_seed_hash(),
    );
}

/**
 * All travel_core language variables from both sources, merged.
 *
 * @return array<string, array<string, string>> name => [lang_code => value]
 */
function fn_travel_core_language_variables(): array
{
    // Delegates to a class init.php can reach whatever the file load order —
    // see LanguageSeeder's docblock. Required explicitly because func.php also
    // runs at install time, before any autoloader is registered.
    require_once __DIR__ . '/src/Install/LanguageSeeder.php';

    return \Tygh\Addons\TravelCore\Install\LanguageSeeder::variables();
}

/**
 * Fingerprint of the language-variable sources — the value stamped onto every
 * installed language once its labels are verified in the database.
 *
 * stat-based (size + mtime), not a content hash: addon.xml alone is hundreds
 * of KB and this is computed on every request. It only has to answer "did the
 * sources change?"; whether the ROWS are present is a separate question, and
 * LanguageDelivery::isCurrent() asks the database that one directly.
 */
function fn_travel_core_language_seed_hash(): string
{
    require_once __DIR__ . '/src/Install/LanguageSeeder.php';

    return \Tygh\Addons\TravelCore\Install\LanguageSeeder::seedHash();
}
