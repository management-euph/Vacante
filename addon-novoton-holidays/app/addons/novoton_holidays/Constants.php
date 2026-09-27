<?php
declare(strict_types=1);
/**
 * Novoton Constants
 * 
 * Centralized constants for the addon to avoid magic strings and values.
 * 
 * @package NovotonHolidays
 * @since 2.7.0
 */

namespace Tygh\Addons\NovotonHolidays;

use Tygh\Addons\TravelCore\TravelConstants;

/**
 * Addon-wide constants
 */
final class Constants
{
    // Addon info
    public const string ADDON_ID = 'novoton_holidays';
    public const string VERSION = '4.1.0';

    // ========== Booking Status ==========
    // Shared statuses are in TravelConstants (STATUS_PENDING, STATUS_CONFIRMED, etc.)
    // Use \Tygh\Addons\TravelCore\TravelConstants::STATUS_* for those.

    // ========== Novoton API Status Codes ==========
    //
    // hotel_res_RQ response:
    //   OK  – reservation is accepted and confirmed (displayed as "Good")
    //   ASK – reservation is accepted with asking status
    //   ST  – reservation is cancelled
    //   WT  – reservation is with waiting status
    //
    // resinfo polling (for ASK reservations):
    //   OK  – confirmed (displayed as "Good")
    //   ST  – cancelled / rejected
    //   RQ  – alternatives pending (retrieve via alternative_RS)

    public const string NOVOTON_STATUS_CONFIRMED  = 'Good';
    public const string NOVOTON_STATUS_ON_REQUEST = 'ASK';
    public const string NOVOTON_STATUS_CANCELLED  = 'ST';
    public const string NOVOTON_STATUS_WAITLIST   = 'WT';
    public const string NOVOTON_STATUS_ALTERNATIVES_PENDING = 'RQ';

    // ========== API Wire Format → Internal Status ==========
    // The Novoton API returns 'OK' on the wire; we normalize it to 'Good' internally.

    public const array NOVOTON_API_WIRE_MAP = [
        'OK' => 'Good',   // API sends 'OK', we store/display 'Good'
    ];

    // ========== Reservation Status Mapping ==========
    // Maps internal status codes (after wire normalization) to internal statuses.

    public const array NOVOTON_STATUS_TO_INTERNAL = [
        'OK'                                       => TravelConstants::STATUS_CONFIRMED,  // API wire format (legacy/direct)
        self::NOVOTON_STATUS_CONFIRMED             => TravelConstants::STATUS_CONFIRMED,  // Good -> confirmed
        self::NOVOTON_STATUS_ON_REQUEST            => TravelConstants::STATUS_ASK,        // ASK  -> ask (poll via resinfo)
        self::NOVOTON_STATUS_CANCELLED             => TravelConstants::STATUS_CANCELLED,  // ST   -> cancelled
        self::NOVOTON_STATUS_WAITLIST              => TravelConstants::STATUS_WAITING,    // WT   -> waiting
        self::NOVOTON_STATUS_ALTERNATIVES_PENDING  => TravelConstants::STATUS_PENDING,    // RQ   -> pending (check alternative_RS)
    ];

    /**
     * Normalize a Novoton API status from wire format to internal format.
     * Converts 'OK' → 'Good'; all other statuses pass through unchanged.
     */
    public static function normalizeApiStatus(string $status): string
    {
        return self::NOVOTON_API_WIRE_MAP[$status] ?? $status;
    }

    // ========== Availability Status ==========

    public const string AVAIL_OK = 'Good';
    public const string AVAIL_RQ = 'RQ';           // On Request
    public const string AVAIL_STOP = 'STOP';       // Stop Sale
    public const string AVAIL_NA = 'NA';           // Not Available

    // ========== Age Types ==========

    public const string AGE_ADULT = 'ADULT';
    public const string AGE_CHILD = 'CHILD';
    public const string AGE_INFANT = 'INFANT';

    // ========== Accommodation Types ==========

    public const string ACC_REGULAR = 'REGULAR';
    public const string ACC_SINGLE = 'SINGLE';
    public const string ACC_EXTRA_BED = 'EXTRA_BED';

    // ========== Currency ==========
    // Shared currency constants are in TravelConstants (CURRENCY_EUR, CURRENCY_USD, etc.)

    // ========== External URLs ==========

    public const string IMAGE_BASE_URL = 'https://booking.allinclusive.bg';

    // ========== Limits ==========
    // Shared limits are in TravelConstants (MAX_ADULTS, MAX_CHILDREN, etc.)

    // ========== Cache TTL (seconds) ==========
    // ONLY for live API calls - static data uses database storage

    public const int CACHE_TTL_ROOM_PRICE = 300;        // 5 minutes (live booking prices)
    public const int CACHE_TTL_AVAILABILITY = 180;      // 3 minutes (live availability)
    public const int CACHE_TTL_SEARCH = 300;            // 5 minutes (search results)

    // Note: hotel_list, hotel_info, priceinfo are NOT cached
    // They are stored in database and synced via cron jobs

    // ========== API Functions ==========
    // Names match the Novoton XML API function identifiers exactly.
    // See Novoton API docs: each constant = the <function> value sent in the URL.

    // --- Catalog & Hotel Data ---
    public const string API_FUNCTION_HOTEL_LIST        = 'hotel_list';         //  1. List hotel names
    public const string API_FUNCTION_HOTEL_INFO        = 'hotelinfo';          //  2. Hotel services/rooms/packages
    public const string API_FUNCTION_HOTEL_DESCRIPTION = 'hotel_description';  //  5. Hotel description text
    public const string API_FUNCTION_HOTEL_IMAGES      = 'hotel_images';       //  6. Hotel pictures

    // --- Pricing & Availability ---
    public const string API_FUNCTION_ROOM_PRICE        = 'room_price';         //  3. Real-time accommodation prices
    public const string API_FUNCTION_HOTEL_QUOTA       = 'hotel_quota';        //  4. Free allotments (availability)
    public const string API_FUNCTION_HOTEL_QUOTA_ADD   = 'hotel_quota_add';    // 21. Additional allotments
    public const string API_FUNCTION_PRICE_INFO        = 'priceinfo';          // 13. Season prices
    public const string API_FUNCTION_SPECIAL_OFFERS    = 'spo';                // 10. Early booking & discounts
    public const string API_FUNCTION_OFFERS_UPDATE     = 'offers_update';      // 25. Updated/new offers (delta)

    // --- Reservations ---
    public const string API_FUNCTION_RESERVATION       = 'hotel_res_RQ';       //  7. Submit reservation request
    public const string API_FUNCTION_RES_INFO          = 'resinfo';            // 15. Reservation status check
    public const string API_FUNCTION_CANCEL            = 'cancel_reservation'; //     Cancel reservation
    public const string API_FUNCTION_INVOICE_HTML      = 'hotel_acc_RQ_html';  //  8. Invoice (HTML)
    public const string API_FUNCTION_INVOICE_XML       = 'hotel_acc_RQ';       //  9. Invoice (XML)
    public const string API_FUNCTION_LIST_INVOICES     = 'list_invoices';      // 14. List invoices

    // --- Alternatives ---
    public const string API_FUNCTION_HOTEL_REQUEST     = 'hotel_request';      // 22. Request alternatives
    public const string API_FUNCTION_ALTERNATIVE_RS    = 'alternative_RS';     // 23. Check alternative offers

    // --- Search & Destinations ---
    public const string API_FUNCTION_SEARCH            = 'frmsearch';          //     Availability search
    public const string API_FUNCTION_RESORT_LIST       = 'resort_list';        // 16. Destinations/resort names

    // --- Facilities ---
    public const string API_FUNCTION_LIST_FACILITIES   = 'list_facilities';    // 26. All facilities catalog
    public const string API_FUNCTION_HOTEL_FACILITIES  = 'hotel_facilities';   // 27. Facilities for a hotel

    // --- Commission ---
    public const string API_FUNCTION_KICKBACK          = 'kickback_RS';        // 24. Commission/kickback info

    // ========== Database Tables (V3 Architecture) ==========

    public const string TABLE_HOTELS           = 'novoton_hotels';
    public const string TABLE_HOTEL_PACKAGES   = 'novoton_hotel_packages';   // Packages from hotelinfo API with priceinfo
    public const string TABLE_BOOKINGS         = 'novoton_bookings';
    public const string TABLE_HOTEL_FACILITIES = 'novoton_hotel_facilities'; // Junction: hotel ↔ facility
    public const string TABLE_FACILITIES       = 'novoton_facilities';       // Facility catalog
    public const string TABLE_RESORTS          = 'novoton_resorts';          // Resort names by country
    public const string TABLE_SYNC_LOG         = 'novoton_sync_log';
    public const string TABLE_CACHE            = 'novoton_cache';
    public const string TABLE_ALTERNATIVE_REQUESTS = 'novoton_alternative_requests';
    public const string TABLE_FEATURE_MAPPINGS = 'hotel_feature_mappings';   // Provider → CS-Cart feature mapping hub

    // ========== Feature Types (Mapping Hub) ==========
    // Used as `feature_type` column in hotel_feature_mappings table.

    public const string FEATURE_TYPE_PROPERTY_RATING = 'property_rating';
    public const string FEATURE_TYPE_MEALS           = 'meals';
    public const string FEATURE_TYPE_HOTEL_FACILITY  = 'hotel_facility';
    public const string FEATURE_TYPE_ROOM_FACILITY   = 'room_facility';
    public const string FEATURE_TYPE_RESORT          = 'resort';
    public const string FEATURE_TYPE_PROPERTY_TYPE   = 'property_type';
    public const string FEATURE_TYPE_TRAVEL_GROUP    = 'travel_group';
    public const string FEATURE_TYPE_BEACH_ACCESS    = 'beach_access';

    /** All valid feature types for input validation */
    public const array VALID_FEATURE_TYPES = [
        self::FEATURE_TYPE_PROPERTY_RATING,
        self::FEATURE_TYPE_MEALS,
        self::FEATURE_TYPE_HOTEL_FACILITY,
        self::FEATURE_TYPE_ROOM_FACILITY,
        self::FEATURE_TYPE_RESORT,
        self::FEATURE_TYPE_PROPERTY_TYPE,
        self::FEATURE_TYPE_TRAVEL_GROUP,
        self::FEATURE_TYPE_BEACH_ACCESS,
    ];

    /** Strict feature types: unknown codes are logged + skipped, never auto-created */
    public const array STRICT_FEATURE_TYPES = [
        self::FEATURE_TYPE_PROPERTY_RATING,
        self::FEATURE_TYPE_MEALS,
        self::FEATURE_TYPE_PROPERTY_TYPE,
        self::FEATURE_TYPE_TRAVEL_GROUP,
        self::FEATURE_TYPE_BEACH_ACCESS,
    ];

    /** Dynamic feature types: unknown codes are auto-registered in the mapping table */
    public const array DYNAMIC_FEATURE_TYPES = [
        self::FEATURE_TYPE_HOTEL_FACILITY,
        self::FEATURE_TYPE_ROOM_FACILITY,
        self::FEATURE_TYPE_RESORT,
    ];

    // ========== Age Type Mapping (Novoton API IdAge → label) ==========

    public const array AGE_TYPE_MAP = [
        '1' => 'ADULT',
        '2' => 'CHD 0-1.99',
        '3' => 'CHD 2-11.99',
        '4' => 'CHD 12-17.99',
        'ADULT' => 'ADULT',
        'ADULT ' => 'ADULT',
    ];

    // ========== Countries ==========

    public const array COUNTRIES = [
        'ALBANIA',
        'BULGARIA',
        'CYPRUS',
        'EGYPT',
        'FRANCE',
        'GREECE',
        'ITALY',
        'MALDIVES',
        'SPAIN',
        'TURKEY',
        'UNITED ARAB EMIRATES',
        'UNITED KINGDOM',
    ];

    public const string DEFAULT_COUNTRY = 'BULGARIA';

    // ========== Hidden Resorts ==========
    // Resorts used only for internal/administrative purposes.
    // These are filtered from all frontend and backend resort listings.

    public const array HIDDEN_RESORTS = [
        'GIFT VOUCHER',
    ];

    // ========== Languages ==========

    public const string LANG_EN = 'UK';
    public const string LANG_BG = 'BG';
    public const string LANG_RO = 'RO';

    public const string DEFAULT_LANG = self::LANG_EN;

    // ========== Error Codes ==========

    public const string ERROR_INVALID_DATA = 'E001';
    public const string ERROR_API_FAILURE = 'E002';
    public const string ERROR_NOT_AVAILABLE = 'E003';
    public const string ERROR_PRICE_CHANGED = 'E004';
    public const string ERROR_BOOKING_FAILED = 'E005';
    public const string ERROR_RATE_LIMITED = 'E006';
    public const string ERROR_UNAUTHORIZED = 'E007';

    // ========== Board (Meal Plan) Mapping ==========
    // Maps user-facing board codes to Novoton API board type identifiers.
    // Single source of truth — replaces scattered $boardMapping arrays.

    public const array BOARD_MAPPING = [
        'AI'  => ['ALL INCL', 'AI', 'ALLINC'],
        'UAI' => ['ULTRA ALL', 'UAI'],
        'FB'  => ['FB', 'FULL BOARD'],
        'HB'  => ['HB', 'HALF BOARD'],
        'BB'  => ['BB', 'BED BREAKFAST', 'B&B'],
        'RO'  => ['RO', 'ROOM ONLY'],
    ];

    // ========== Default Values ==========
    // Shared defaults are in TravelConstants (DEFAULT_ADULTS, DEFAULT_CHILDREN, etc.)

    public const int DEFAULT_COMMISSION = 0;
    public const int DEFAULT_ADULT_AGE = 33;
    public const string DEFAULT_ISO_NATIONAL = 'RO';
    public const string DEFAULT_CREATED_BY = 'CS-Cart';

    // ========== Debug / Logging Settings Keys ==========

    /** Registry key for verbose service logging (default Y) */
    public const string SETTING_DEBUG_LOGGING = 'addons.novoton_holidays.debug_logging';

    /** Registry key for full debug mode (default N) */
    public const string SETTING_DEBUG_MODE = 'addons.novoton_holidays.debug_mode';

    // ========== API Timeouts ==========

    public const int API_TIMEOUT = 30;              // seconds
    public const int API_CONNECT_TIMEOUT = 10;      // seconds

    // ========== Cache TTL for cron-synced data (seconds) ==========
    // These are for DB-cached data that cron refreshes periodically.
    // See also CACHE_TTL_* above for live API call caching.

    public const int CACHE_TTL_HOTEL_INFO = 3600;        // 1 hour
    public const int CACHE_TTL_PRICE_INFO = 1800;        // 30 minutes
    public const int CACHE_TTL_SEARCH_RESULTS = 900;     // 15 minutes
    public const int CACHE_TTL_FACILITIES = 86400;        // 24 hours

    // ========== Cron ==========

    public const int CRON_BATCH_SIZE = 100;
    public const int CRON_CHECK_INTERVAL = 86400;         // 24 hours

    // ========== API Rate Limiting Delays (microseconds) ==========
    // Used between consecutive API calls to avoid overwhelming the Novoton API.
    // All values in microseconds (1 second = 1_000_000).

    /** Light delay between fast operations (product creation, price checks) */
    public const int API_DELAY_LIGHT   =  50_000;   //  50ms
    /** Standard delay between API polling calls */
    public const int API_DELAY_NORMAL  = 100_000;   // 100ms
    /** Moderate delay for heavier API operations (alternatives, status checks) */
    public const int API_DELAY_MODERATE = 200_000;  // 200ms
    /** Heavy delay for expensive API calls (hotel info sync) */
    public const int API_DELAY_HEAVY   = 300_000;   // 300ms
    /** Backoff delay for retry after transient failures */
    public const int API_DELAY_BACKOFF = 500_000;   // 500ms

    // ========== Booking Limits (per-room) ==========

    public const int MAX_ADULTS_PER_ROOM = 4;
    public const int MAX_CHILDREN_PER_ROOM = 3;

    // ========== Product ==========

    public const string PRODUCT_CODE_PREFIX = 'NVT';

    /** Category path template for hotel products. {country} is replaced at runtime. */
    public const string PRODUCT_CATEGORY_TEMPLATE = '{country}/Litoral {country}';

    // ========== Sync Log ==========

    public const int SYNC_LOG_RETENTION_DAYS = 30;

    // ========== Internal Cache Limits ==========

    /** Max entries in BookingRepository in-memory hydrated cache before trimming */
    public const int HYDRATED_CACHE_MAX = 500;
    /** Trim to this many entries when HYDRATED_CACHE_MAX is exceeded */
    public const int HYDRATED_CACHE_TRIM = 250;

    // ========== Diagnostics ==========

    /** Hours since last sync before health is considered degraded */
    public const int SYNC_HEALTH_THRESHOLD_HOURS = 48;

    // ========== Price Check Defaults ==========

    /** Default check-in offset for automatic price checks (days from today) */
    public const int PRICE_CHECK_OFFSET_DAYS = 30;

    /** Default check-in offset for test/diagnostic forms (days from today) */
    public const int DEFAULT_CHECKIN_DAYS_AHEAD = 30;

    /** Default stay duration in nights for test/diagnostic forms */
    public const int DEFAULT_STAY_NIGHTS = 7;

    // ========== Date Formats ==========
    // Shared date formats are in TravelConstants (DATETIME_FORMAT, DATE_FORMAT)

    // ========== File Paths ==========

    public const string CACHE_DIR = 'var/cache/novoton/';
    public const string LOG_DIR = 'var/log/';

    // Prevent instantiation
    private function __construct() {}
}
