# Shared Travel Core Architecture
## Cross-API Addon System for CS-Cart

**Version:** 1.0
**Date:** 2026-03-02
**Scope:** Novoton XML API + Sphinx REST API (+ future providers)

---

## Table of Contents

1. [Problem Statement](#1-problem-statement)
2. [High-Level Architecture](#2-high-level-architecture)
3. [Addon Dependency Graph](#3-addon-dependency-graph)
4. [Shared Feature Mapping (Core Tables)](#4-shared-feature-mapping)
5. [Shared Booking Form & Guest Entry](#5-shared-booking-form--guest-entry)
6. [Shared Search Infrastructure](#6-shared-search-infrastructure)
7. [Shared Cart & Checkout Integration](#7-shared-cart--checkout-integration)
8. [Shared Booking Submission Pipeline](#8-shared-booking-submission-pipeline)
9. [Shared Admin Panel](#9-shared-admin-panel)
10. [Shared Email Templates](#10-shared-email-templates)
11. [Shared JavaScript & CSS](#11-shared-javascript--css)
12. [Provider Adapter Contract](#12-provider-adapter-contract)
13. [Data Flow Diagrams](#13-data-flow-diagrams)
14. [Migration Strategy](#14-migration-strategy)
15. [File Layout](#15-file-layout)

---

## 1. Problem Statement

Each travel API uses different terminology, data formats, and protocols for the same concepts:

| Concept | Novoton API | Sphinx API |
|---------|-------------|------------|
| **Protocol** | XML over HTTP POST | REST/JSON |
| **Board/Meal** | Codes: `AI`, `FB`, `HB` | Free text: `"Mic dejun"`, `"ALL INCLUSIVE PLUS"` + `meal_type_category_id` |
| **Room Type** | Codes: `DBL`, `SGL`, `TRP` | Free text: `"Twin Room with Sea View"` + numeric `code` |
| **Stars** | Parsed from `hotel_type: "4*"` → int | Direct int: `classification: 5` |
| **Location** | `city`/`country` strings | `destination_id` + `geoname_id` (GeoNames DB) |
| **Property type** | Parsed from `hotel_type` string | Enum: `"hotel"`, `"villa"`, `"apartment"` |
| **Availability** | `room_price` XML + `hotel_quota` | `GET /accommodations/{id}/availability` |
| **Booking** | `hotel_res_RQ` XML with guest XML | `POST /bookings` JSON with guest objects |
| **Price terms** | Embedded XML (`<Terms_of_Payment>`) | Separate endpoint or JSON field |
| **Status codes** | `OK`, `ASK`, `ST`, `WT`, `RQ` | `confirmed`, `pending`, `cancelled` |

**Without a shared layer**, adding Sphinx means duplicating:
- The entire booking form (guest entry, DOB validation, multi-room)
- Search parameter normalization (dates, occupancy, flexible dates)
- Cart display formatting (room breakdown, guest list, meal plan)
- Checkout integration (booking details, edit flow)
- Email templates (booking confirmation details)
- Admin booking management (list, filter, view, status check)
- Price verification logic (pre-order check, threshold comparison)
- JavaScript (DOB masking, multi-room selection, price formatting)
- CSS (booking form styles, result cards, responsive breakpoints)

**Goal:** Extract ~70% of the current codebase into a shared `travel_core` addon that both `novoton_holidays` and `sphinx_holidays` depend on, with each API addon providing only a thin adapter layer.

---

## 2. High-Level Architecture

```
┌─────────────────────────────────────────────────────────────────────┐
│                        CS-Cart Frontend                              │
│  (consistent UI regardless of data source — same booking form,      │
│   same cart display, same checkout flow, same email templates)       │
└──────────────────────────────┬──────────────────────────────────────┘
                               │ Smarty templates, JS, CSS
┌──────────────────────────────┴──────────────────────────────────────┐
│                     travel_core (SHARED ADDON)                       │
│                                                                      │
│  ┌─────────────┐  ┌──────────────┐  ┌──────────────────────────┐   │
│  │ Feature Map  │  │ Booking Form │  │ Cart/Checkout Display    │   │
│  │ (DB tables)  │  │ (templates)  │  │ (hooks + templates)     │   │
│  └─────────────┘  └──────────────┘  └──────────────────────────┘   │
│  ┌─────────────┐  ┌──────────────┐  ┌──────────────────────────┐   │
│  │ Search Norm  │  │ Guest Data   │  │ Price Verification      │   │
│  │ (services)   │  │ (services)   │  │ (services)              │   │
│  └─────────────┘  └──────────────┘  └──────────────────────────┘   │
│  ┌─────────────┐  ┌──────────────┐  ┌──────────────────────────┐   │
│  │ Admin Mgmt   │  │ Email Tpl    │  │ JS + CSS Assets         │   │
│  │ (views)      │  │ (mail tpl)   │  │ (React, DOB, styles)    │   │
│  └─────────────┘  └──────────────┘  └──────────────────────────┘   │
│                                                                      │
│  Provider Registry ──────────────────────────────────────────────   │
│  │ Resolves active provider(s) → delegates API calls                │
│  └──────────────────────────────────────────────────────────────   │
└──────────┬─────────────────────────────────┬───────────────────────┘
           │                                 │
           │ implements TravelProvider       │ implements TravelProvider
           │                                 │
┌──────────┴──────────────┐     ┌────────────┴──────────────────────┐
│   novoton_holidays      │     │   sphinx_holidays                 │
│   (API ADAPTER)         │     │   (API ADAPTER)                   │
│                         │     │                                   │
│  - NovotonApi (XML)     │     │  - SphinxApi (REST/JSON)          │
│  - API clients          │     │  - API client                     │
│  - Cron sync commands   │     │  - Cron sync commands             │
│  - Hotel data tables    │     │  - Hotel data tables              │
│  - Alias registration   │     │  - Alias registration             │
│  - Status mapping       │     │  - Status mapping                 │
│  - Commission calc      │     │  - Commission calc                │
└─────────────────────────┘     └───────────────────────────────────┘
```

---

## 3. Addon Dependency Graph

```
travel_core (shared)
  ├── novoton_holidays (requires: travel_core)
  └── sphinx_holidays  (requires: travel_core)
```

**`addon.xml` dependency declaration:**

```xml
<!-- novoton_holidays/addon.xml -->
<addon scheme="4.0" edition_type="ROOT,ULT:VENDOR">
    <id>novoton_holidays</id>
    <dependencies>travel_core</dependencies>
    ...
</addon>

<!-- sphinx_holidays/addon.xml -->
<addon scheme="4.0" edition_type="ROOT,ULT:VENDOR">
    <id>sphinx_holidays</id>
    <dependencies>travel_core</dependencies>
    ...
</addon>
```

**Key principle:** `travel_core` has **zero** API-specific code. It only knows about interfaces and generic travel concepts.

---

## 4. Shared Feature Mapping

### 4.1 Database Tables

**Table: `travel_feature_map`** — Canonical definitions (what things ARE):

```sql
CREATE TABLE IF NOT EXISTS `?:travel_feature_map` (
    `map_id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `feature_type`      VARCHAR(50)  NOT NULL,  -- 'board', 'room_type', 'stars', 'property_type', 'location'
    `canonical_code`    VARCHAR(100) NOT NULL,  -- 'AI', 'DBL', '5', 'hotel', 'sunny_beach'
    `display_name_en`   VARCHAR(255) NOT NULL,  -- 'All Inclusive'
    `display_name_ro`   VARCHAR(255) NOT NULL,  -- 'All Inclusive'
    `cscart_feature_id` INT          DEFAULT NULL,  -- links to ?:product_features
    `cscart_variant_id` INT          DEFAULT NULL,  -- links to ?:product_feature_variants
    `geoname_id`        INT          DEFAULT NULL,  -- for auto-matching Sphinx destinations
    `position`          INT          DEFAULT 0,
    `status`            CHAR(1)      DEFAULT 'A',   -- A=active, D=disabled
    UNIQUE KEY `uq_type_code` (`feature_type`, `canonical_code`),
    KEY `idx_feature_type` (`feature_type`),
    KEY `idx_cscart_variant` (`cscart_variant_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;
```

**Table: `travel_api_alias`** — Per-API translations (what each API CALLS them):

```sql
CREATE TABLE IF NOT EXISTS `?:travel_api_alias` (
    `alias_id`   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `map_id`     INT UNSIGNED NOT NULL,         -- FK to travel_feature_map
    `api_source` VARCHAR(50)  NOT NULL,         -- 'novoton', 'sphinx', 'eurosite'
    `api_value`  VARCHAR(255) NOT NULL,         -- 'AI', 'Mic dejun', 'ALL INCLUSIVE PLUS'
    `match_type` ENUM('exact','prefix','contains','regex') DEFAULT 'exact',
    UNIQUE KEY `uq_source_value` (`api_source`, `api_value`),
    KEY `idx_map` (`map_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;
```

### 4.2 FeatureMapper Service

```php
namespace Tygh\Addons\TravelCore\Services;

class FeatureMapper
{
    /**
     * Resolve any API value to a canonical mapping.
     *
     * @return array|null {map_id, canonical_code, display_name_en, display_name_ro, cscart_variant_id}
     */
    public static function resolve(string $apiSource, string $featureType, string $apiValue): ?array;

    /**
     * Get CS-Cart variant_id directly (for product feature assignment).
     */
    public static function toVariantId(string $apiSource, string $featureType, string $apiValue): ?int;

    /**
     * Bulk resolve for import performance (single query, keyed result).
     */
    public static function resolveMany(string $apiSource, string $featureType, array $apiValues): array;

    /**
     * Get display name for a canonical code (language-aware).
     */
    public static function getDisplayName(string $featureType, string $canonicalCode, string $lang = 'en'): string;

    /**
     * Register an alias (called by each API addon during install/sync).
     */
    public static function addAlias(string $apiSource, string $apiValue, int $mapId, string $matchType = 'exact'): void;

    /**
     * Get all canonical codes for a feature type.
     */
    public static function allCodes(string $featureType): array;
}
```

### 4.3 Example Data

| feature_type | canonical_code | display_name_en | Novoton aliases | Sphinx aliases |
|---|---|---|---|---|
| `board` | `AI` | All Inclusive | `AI`, `ALL INCL`, `ALLINC` | `ALL INCLUSIVE PLUS`, `All Inclusive` |
| `board` | `BB` | Bed & Breakfast | `BB`, `B&B`, `BED AND BREAKFAST` | `Mic dejun` |
| `board` | `HB` | Half Board | `HB`, `HALF BOARD` | `Demipensiune`, `Half Board` |
| `board` | `FB` | Full Board | `FB`, `FULL BOARD` | `Pensiune completa` |
| `room_type` | `DBL` | Double Room | `DBL` | `Double Room` (prefix) |
| `room_type` | `TWIN` | Twin Room | `TWIN`, `TWN` | `Twin Room` (prefix) |
| `room_type` | `SGL` | Single Room | `SGL` | `Single Room` (prefix) |
| `stars` | `5` | 5 Stars | `5*`, `5` | `5` |
| `property_type` | `hotel` | Hotel | (parsed from hotel_type) | `hotel` |
| `property_type` | `apartment` | Apartment | `Apart` (from hotel_type) | `apartment` |

---

## 5. Shared Booking Form & Guest Entry

The booking form is **100% API-agnostic** — it only needs structured data, not API-specific logic.

### 5.1 What Moves to `travel_core`

| Component | Current Location | Shareable? | Notes |
|---|---|---|---|
| **Booking form template** | `novoton_holidays/views/novoton_booking/booking_form.tpl` | **Yes** | Fields are generic: hotel name, dates, rooms, guests |
| **Guest entry fields** | Embedded in booking_form.tpl | **Yes** | Name, DOB, type (adult/child) — universal |
| **DOB masking JS** | `dob-validation.js` (26KB) | **Yes** | DD/MM/YYYY format — no API dependency |
| **Booking form validation JS** | `booking-form-validation.js` (23KB) | **Yes** | Client-side validation — no API dependency |
| **Multi-room selection JS** | `multiroom-booking.js` (8.5KB) | **Yes** | Room radio buttons, price formatting |
| **CSS styles** | Inline in booking_form.tpl + `styles.css` | **Yes** | CSS custom properties already theme-agnostic |
| **GuestDataService** | `src/Services/GuestDataService.php` | **Yes, 100%** | Parsing, formatting, validation — no API calls |
| **GuestDataNormalizer** | `src/Services/GuestDataNormalizer.php` | **Yes, 100%** | Format conversion — no API calls |
| **Room type formatting** | `{function format_room_type}` in .tpl | **Yes** | Uses `FeatureMapper` instead of hardcoded if/else |
| **Board name formatting** | `fn_novoton_holidays_format_board_name()` | **Yes** | Uses `FeatureMapper` instead of Constants |

### 5.2 What Stays in Each API Addon

| Component | Why API-specific |
|---|---|
| **Age categories** | Different per hotel, fetched from `hotelinfo` (Novoton) or `/accommodations/{id}` (Sphinx) |
| **Room limits** (max adults, max children) | Part of hotel metadata from each API |
| **Package name resolution** | Novoton uses `IdCont`/`PackageName`, Sphinx uses `rate_plan_code` |
| **Calendar prices** | Computed from `priceinfo` (Novoton) or `availability` (Sphinx) |
| **Price recalculation AJAX** | Calls different API endpoints |

### 5.3 Template Architecture

```
travel_core/views/travel_booking/
├── booking_form.tpl              # Main form layout
│   ├── {include "booking_header.tpl"}     # Hotel name, stars, location, badge
│   ├── {include "booking_details.tpl"}    # Image, info grid, price box
│   ├── {include "guest_entry.tpl"}        # Guest name/DOB fields (per room)
│   └── {include "booking_actions.tpl"}    # Back link + submit button
├── booking_header.tpl
├── booking_details.tpl
├── guest_entry.tpl               # Reusable guest entry block
└── booking_actions.tpl
```

The form posts to a **shared controller** (`travel_booking.add_to_cart`) which delegates to the active provider's adapter:

```php
// travel_core/controllers/frontend/travel_booking/add_to_cart.php

$provider = TravelProviderRegistry::getProviderForHotel($hotel_id);
// → returns 'novoton' or 'sphinx'

$adapter = TravelProviderRegistry::getAdapter($provider);
// → returns NovotonBookingAdapter or SphinxBookingAdapter

$priceResult = $adapter->verifyPrice($params);
// Each adapter calls its own API, but returns the same structure:
// ['success' => bool, 'total_price' => float, 'base_price' => float,
//  'terms_of_payment' => string, 'terms_of_cancellation' => string]
```

### 5.4 Form Data Contract (Shared)

Both APIs produce the same form data structure:

```php
// Data passed to booking_form.tpl (provider-agnostic)
$booking_data = [
    'hotel_id'       => string,    // Provider-specific hotel ID
    'provider'       => string,    // 'novoton' or 'sphinx'
    'room_id'        => string,    // Room code (provider-specific)
    'board_id'       => string,    // Canonical board code (from FeatureMapper)
    'board_name'     => string,    // Display name (from FeatureMapper)
    'check_in'       => string,    // Y-m-d
    'check_out'      => string,    // Y-m-d
    'nights'         => int,
    'adults'         => int,
    'children'       => int,
    'children_ages'  => string,    // Comma-separated
    'total_price'    => float,
    'package_name'   => string,
    'num_rooms'      => int,
    'rooms_data'     => array,     // Multi-room details
    'age_categories' => array,     // From hotel metadata
    'current_room_limits' => array // Max adults/children per room
];
```

---

## 6. Shared Search Infrastructure

### 6.1 What Moves to `travel_core`

| Component | Current | Shareable? | Notes |
|---|---|---|---|
| **SearchParameterNormalizer** | 270 lines, 100% generic | **Yes, entire class** | Date calc, multi-room parsing, age handling |
| **Search form (React)** | `react19-bundle.js` (43KB) | **Mostly** | Core UI is generic; data source is pluggable |
| **Search results template** | `search.tpl` | **Yes** | Grid/card layout is universal |
| **Result deduplication** | `SearchService::deduplicateResults()` | **Yes** | Logic is identical |
| **Flexible date search** | `SearchService::searchFlexibleDates()` | **Strategy is shared** | ±N days logic is generic; API calls differ |
| **SearchResultFormatter** | 395 lines | **Mostly** | Template variable assignment is generic |
| **AlternativeDateSearcher** | | **Strategy is shared** | Fallback logic is generic |

### 6.2 What Stays in Each API Addon

| Component | Why API-specific |
|---|---|
| **Actual search API call** | Novoton: `room_price` XML; Sphinx: `GET /availability` JSON |
| **Response parsing** | XML vs JSON, different field names |
| **Quota/availability check** | Novoton: `hotel_quota` API; Sphinx: included in availability response |
| **Early booking discounts** | Novoton: from `priceinfo_data` JSON; Sphinx: from `special_offers` endpoint |
| **Commission calculation** | Different commission structures per provider |

### 6.3 Search Adapter Interface

```php
namespace Tygh\Addons\TravelCore\Contracts;

interface SearchAdapterInterface
{
    /**
     * Search availability for a specific hotel.
     *
     * @param array $params Normalized search params (from SearchParameterNormalizer)
     * @return array Standardized result items
     */
    public function searchAvailability(array $params): array;

    /**
     * Batch search for flexible dates (±N days).
     *
     * @param array $params  Base search params
     * @param int   $flexDays Number of days to try each direction
     * @return array Standardized result items across all dates
     */
    public function searchFlexibleDates(array $params, int $flexDays): array;

    /**
     * Get hotel metadata (rooms, boards, packages, age categories).
     *
     * @return array Standardized hotel info
     */
    public function getHotelInfo(string $hotelId): array;
}
```

### 6.4 Standardized Search Result Item

Both adapters return results in this format:

```php
// Each result item (provider-agnostic)
[
    'provider'              => 'novoton',          // Which API sourced this
    'hotel_id'              => string,
    'room_id'               => string,             // Provider-specific room code
    'room_name'             => string,             // Raw room name from API
    'room_type_display'     => string,             // From FeatureMapper
    'board_id'              => string,             // Canonical code (from FeatureMapper)
    'board_name'            => string,             // Display name (from FeatureMapper)
    'package_name'          => string,
    'total_price'           => float,              // With commission applied
    'base_price'            => float,              // API raw price
    'price_per_night'       => float,
    'currency'              => string,             // ISO code
    'check_in'              => 'Y-m-d',
    'check_out'             => 'Y-m-d',
    'nights'                => int,
    'rooms_available'       => int|null,
    'is_on_request'         => bool,
    'remark'                => string,
    'early_booking_discount'=> float|null,
    'terms_of_payment'      => string,             // Provider formats to HTML
    'terms_of_cancellation' => string,             // Provider formats to HTML
    'free_cancellation_date'=> string|null,
]
```

---

## 7. Shared Cart & Checkout Integration

### 7.1 What Moves to `travel_core`

| Component | Current | Lines | Notes |
|---|---|---|---|
| **Cart content hook template** | `hooks/cart_content/product_info.post.tpl` | ~100 | Room/guest display — no API logic |
| **Checkout product hook template** | `hooks/checkout/product_info.post.tpl` | ~120 | Multi-room cards, guest names |
| **Block checkout hook** | `hooks/block_checkout/product_extra.post.tpl` | ~60 | Compact format |
| **Checkout summary hook** | `hooks/checkout/summary_extra.post.tpl` | ~30 | Sidebar summary |
| **Booking summary block** | `blocks/booking_summary.tpl` | ~40 | Reusable summary component |
| **Cart display formatter** | `fn_novoton_holidays_add_booking_display_data()` | ~150 | Builds `product_options_value[]` |
| **Cart injection logic** | `fn_novoton_holidays_calculate_cart_items()` | ~100 | DB → cart sync |

### 7.2 Cart `extra` Data Contract

Both providers populate the same `extra` structure on cart products:

```php
$cart['products'][$cart_id]['extra'] = [
    // Shared fields (travel_core reads these)
    'travel_booking'       => true,              // Flag: this is a travel product
    'travel_provider'      => 'novoton',         // Which API
    'travel_booking_id'    => int,               // DB booking ID
    'hotel_id'             => string,
    'hotel_name'           => string,
    'hotel_city'           => string,
    'hotel_country'        => string,
    'room_id'              => string,
    'room_type_display'    => string,            // From FeatureMapper
    'board_id'             => string,            // Canonical code
    'board_name'           => string,            // Display name
    'check_in'             => 'Y-m-d',
    'check_out'            => 'Y-m-d',
    'nights'               => int,
    'adults'               => int,
    'children'             => int,
    'children_ages'        => string,
    'num_rooms'            => int,
    'holder_name'          => string,
    'guests_data'          => string,            // JSON
    'rooms_data'           => string,            // JSON
    'total_price'          => float,
    'terms_of_payment'     => string,
    'terms_of_cancellation'=> string,
];
```

### 7.3 Hook Registration

`travel_core` registers the CS-Cart hooks:

```php
// travel_core/hooks/cart_hooks.php

function fn_travel_core_get_cart_product_data_post(&$product, $cart, $auth): void
{
    if (!empty($product['extra']['travel_booking'])) {
        fn_travel_core_add_booking_display_data($product);
    }
}

function fn_travel_core_calculate_cart_items(&$cart, &$cart_products, $auth): void
{
    // Delegates to the provider's booking repository
    $provider = TravelProviderRegistry::getProviderForProduct($product_id);
    $adapter = TravelProviderRegistry::getAdapter($provider);
    $bookings = $adapter->getBookingsForProducts($product_ids);
    // ... inject into cart
}
```

---

## 8. Shared Booking Submission Pipeline

### 8.1 Architecture

The submission pipeline has a **shared orchestrator** and **provider-specific submitters**.

```
CS-Cart place_order hook
    │
    ▼
┌─────────────────────────────────────────────┐
│ TravelBookingOrchestrator (travel_core)     │
│                                              │
│ 1. Find travel bookings in cart              │
│ 2. Group by provider                         │
│ 3. For each provider:                        │
│    a. Hydrate booking from DB                │
│    b. Resolve rooms and guests               │
│    c. Group rooms by package/dates           │
│    d. Delegate to provider's submitter       │
│ 4. Update DB with results                    │
│ 5. Transaction commit/rollback               │
└──────┬───────────────────┬──────────────────┘
       │                   │
       ▼                   ▼
┌──────────────┐   ┌──────────────────┐
│ Novoton      │   │ Sphinx           │
│ Submitter    │   │ Submitter        │
│              │   │                  │
│ - Build XML  │   │ - Build JSON     │
│ - POST to    │   │ - POST to        │
│   Novoton    │   │   Sphinx         │
│ - Parse resp │   │ - Parse resp     │
│ - Map status │   │ - Map status     │
└──────────────┘   └──────────────────┘
```

### 8.2 Booking Submitter Interface

```php
namespace Tygh\Addons\TravelCore\Contracts;

interface BookingSubmitterInterface
{
    /**
     * Submit a booking group to the API.
     *
     * @param array $bookingGroup {
     *   hotel_id: string,
     *   package_name: string,
     *   check_in: string,
     *   check_out: string,
     *   holder: string,
     *   order_num: string,
     *   rooms: [{room_id, board_id, adults, children, childrenAges}],
     *   guests: [{name, type, age, birthday, room, is_holder}],
     * }
     * @return array {
     *   success: bool,
     *   provider_booking_id: string,    // Invoice/reference from API
     *   provider_status: string,        // Raw API status
     *   internal_status: string,        // Mapped to: confirmed|pending|failed|cancelled
     *   api_price: float,               // Price returned by API
     *   api_currency: string,
     *   api_request: string,            // Raw request (for logging)
     *   api_response: string,           // Raw response (for logging)
     *   error_message: string|null,
     * }
     */
    public function submit(array $bookingGroup): array;

    /**
     * Check booking status with the API.
     *
     * @return array {internal_status: string, provider_status: string, details: array}
     */
    public function checkStatus(string $providerBookingId): array;

    /**
     * Cancel a booking via the API.
     *
     * @return array {success: bool, cancellation_fee: float|null, error_message: string|null}
     */
    public function cancel(string $providerBookingId): array;
}
```

### 8.3 Shared Booking Database Table

`travel_core` owns the unified booking table:

```sql
CREATE TABLE IF NOT EXISTS `?:travel_bookings` (
    `booking_id`                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `provider`                  VARCHAR(50) NOT NULL,        -- 'novoton', 'sphinx'
    `user_id`                   INT DEFAULT 0,
    `session_id`                VARCHAR(64) DEFAULT '',
    `order_id`                  INT DEFAULT 0,
    `product_id`                INT DEFAULT 0,
    `hotel_id`                  VARCHAR(50) NOT NULL,
    `hotel_name`                VARCHAR(255) DEFAULT '',
    `room_id`                   VARCHAR(255) DEFAULT '',     -- Provider room code(s)
    `room_type_display`         VARCHAR(255) DEFAULT '',     -- Canonical display name
    `board_id`                  VARCHAR(50) DEFAULT '',      -- Canonical board code
    `board_name`                VARCHAR(100) DEFAULT '',
    `check_in`                  DATE NOT NULL,
    `check_out`                 DATE NOT NULL,
    `nights`                    INT DEFAULT 0,
    `adults`                    INT DEFAULT 0,
    `children`                  INT DEFAULT 0,
    `children_ages`             VARCHAR(100) DEFAULT '',
    `num_rooms`                 INT DEFAULT 1,
    `holder_name`               VARCHAR(255) DEFAULT '',
    `guest_name`                VARCHAR(500) DEFAULT '',
    `guests_data`               JSON DEFAULT NULL,
    `rooms_data`                JSON DEFAULT NULL,
    `base_price`                DECIMAL(10,2) DEFAULT 0.00,  -- API raw price
    `total_price`               DECIMAL(10,2) DEFAULT 0.00,  -- With commission
    `currency`                  VARCHAR(3) DEFAULT 'EUR',
    `status`                    VARCHAR(50) DEFAULT 'pending',
    `provider_booking_id`       VARCHAR(100) DEFAULT '',     -- External ref (invoice)
    `provider_status`           VARCHAR(50) DEFAULT '',      -- Raw API status
    `provider_request`          JSON DEFAULT NULL,           -- API request log
    `provider_response`         JSON DEFAULT NULL,           -- API response log
    `terms_of_payment`          TEXT DEFAULT NULL,
    `terms_of_cancellation`     TEXT DEFAULT NULL,
    `created_at`                DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at`                DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    KEY `idx_provider`          (`provider`),
    KEY `idx_order_id`          (`order_id`),
    KEY `idx_user_id`           (`user_id`),
    KEY `idx_session_id`        (`session_id`),
    KEY `idx_product_id`        (`product_id`),
    KEY `idx_hotel_id`          (`hotel_id`),
    KEY `idx_status`            (`status`),
    KEY `idx_check_in`          (`check_in`),
    KEY `idx_provider_booking`  (`provider_booking_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;
```

This replaces per-provider booking tables (`novoton_bookings`, `sphinx_bookings`). Each provider's specific metadata goes in `provider_request`/`provider_response` JSON columns.

### 8.4 Price Verification (Shared)

```php
namespace Tygh\Addons\TravelCore\Services;

class PreOrderPriceVerifier
{
    // Session cache TTL (seconds) — same for all providers
    private const CACHE_TTL = 180;

    /**
     * Verify cart prices against live API prices before order placement.
     * Delegates the actual API call to the provider adapter.
     */
    public function verify(array $cart): array
    {
        $corrections = [];
        foreach ($this->findTravelProducts($cart) as $cartId => $product) {
            $provider = $product['extra']['travel_provider'];
            $adapter = TravelProviderRegistry::getAdapter($provider);

            // Check session cache first
            $cached = $this->getCachedPrice($cartId);
            if ($cached && !$this->isStale($cached)) {
                continue;
            }

            // Call provider's API for live price
            $livePrice = $adapter->verifyPrice($product['extra']);
            $this->cachePrice($cartId, $livePrice);

            // Compare and decide (threshold logic is shared)
            $correction = $this->comparePrice(
                $product['price'],
                $livePrice['total_price'],
                $this->getThreshold($provider)
            );
            if ($correction) {
                $corrections[$cartId] = $correction;
            }
        }
        return ['allow' => true, 'corrections' => $corrections];
    }
}
```

### 8.5 Cross-Provider Failure Handling & Status Flow

When an order contains hotels from multiple providers (e.g., Novoton + Sphinx in one cart), each provider's `place_order_post` hook fires sequentially (Novoton priority 111, then Sphinx priority 112). Provider API failures must not corrupt the booking status or silently lose data.

#### 8.5.1 Booking Status Flow

Each provider follows this status progression during order placement:

```
Cart → add_to_cart → DB: status = 'pending'
                          │
                          ▼
              place_order_post hook fires
                          │
                          ▼
              DB: status = 'pending' (confirmed before API call)
                          │
                    ┌──────┴──────┐
                    ▼             ▼
              API Success     API Failure
                    │             │
                    ▼             ▼
            status = 'confirmed'  status = 'failed'
            provider_booking_id   notes = error details
            saved                 admin notified
```

**Critical rule:** Status must be `pending` before the API call, NOT `confirmed`. Only set `confirmed` after a successful API response. This prevents the window where a booking appears confirmed but the API hasn't actually accepted it.

#### 8.5.2 ROLLBACK-Safe Status Persistence

When using DB transactions around the API call (as Novoton does), a `ROLLBACK` undoes all writes made inside the transaction — including status updates to `failed`. The fix is to re-apply the failed status **after** the ROLLBACK:

```php
try {
    db_query("START TRANSACTION");
    // ... update status, call API, save response ...
    db_query("COMMIT");
} catch (\Throwable $e) {
    db_query("ROLLBACK");
    // ROLLBACK undid our status='failed' write inside the transaction.
    // Re-apply it OUTSIDE the transaction so it persists:
    $repo->update($bookingId, [
        'status' => TravelConstants::STATUS_FAILED,
        'order_id' => $orderId,
        'notes' => 'API error: ' . $e->getMessage(),
    ]);
}
```

This pattern is used in Novoton's `BookingSubmissionService` across all three catch blocks (`ApiException`, `NovotonException`, `\Throwable`).

#### 8.5.3 Pre-Order Price Verification — Mixed-Provider Carts

When a cart contains items from multiple providers, the `pre_place_order` hook must **never block the entire order** due to one provider's unavailable item. Instead:

1. Check availability for provider-specific items only
2. If an item is unavailable, **remove it from the cart** with a customer notification
3. Only block the order (`$allow = false`) if the cart becomes completely empty after removals

```php
// Pattern for pre_place_order in each provider:
$result = $verifier->verify($cart);

if (!empty($result['unavailable'])) {
    foreach ($result['unavailable'] as $cartId => $info) {
        fn_set_notification('W', __('warning'),
            __('provider.offer_removed_unavailable', ['[hotel]' => $info['hotel_name']]));
        unset($cart['products'][$cartId]);
    }
}

// Only block if cart is now empty
if (empty($cart['products'])) {
    $allow = false;
    fn_set_notification('E', __('error'), __('provider.all_offers_unavailable'));
}
```

#### 8.5.4 Admin Panel Notification for Failed Bookings

Each provider's `get_order_info` hook checks for failed bookings and displays an orange warning banner in the admin panel via `fn_set_notification('W', ...)`:

```php
// In fn_{provider}_get_order_info():
if (defined('AREA') && AREA === 'A' && !empty($order['order_id'])) {
    $bookings = $repo->findByOrderId((int) $order['order_id']);
    foreach ($bookings as $booking) {
        if (($booking['status'] ?? '') === TravelConstants::STATUS_FAILED) {
            fn_set_notification('W', __('warning'),
                __('provider.booking_api_failed', [
                    '[hotel]' => $booking['hotel_name'] ?? '',
                    '[order_id]' => $order['order_id'],
                ]));
            break; // One notification per order per provider
        }
    }
}
```

The `AREA === 'A'` guard ensures this notification only appears in the admin panel, not on the customer-facing storefront.

**Notification types reference (CS-Cart):**
| Type | Color | Usage |
|------|-------|-------|
| `'N'` | Green | Success — booking confirmed |
| `'W'` | Orange | Warning — booking failed, needs attention |
| `'E'` | Red | Error — order blocked |
| `'I'` | Popup | Informational dialog |

---

## 9. Shared Admin Panel

### 9.1 Booking Management (Shared)

The admin booking list/view is provider-agnostic:

```
travel_core/views/travel_bookings/manage.tpl
├── Filters: Order ID, Provider, Status, Check-in range, Sort
├── Table columns:
│   - Order ID (link)
│   - Provider badge (Novoton / Sphinx)
│   - Hotel name
│   - Room type (canonical display)
│   - Board (canonical display)
│   - Check-in date
│   - Status badge (confirmed/pending/cancelled)
│   - Provider status (raw)
│   - Last updated
│   - Actions (view, check status)
└── Bulk actions: Check status, Cleanup orphans

travel_core/views/travel_bookings/view.tpl
├── Booking header + status
├── Provider booking ID
├── Details grid (dates, rooms, guests, price)
├── Per-room breakdown (multi-room)
├── Terms of payment/cancellation
├── API request/response (debug, collapsible)
└── Actions: Check status, Resend, Cancel
```

### 9.2 Feature Mapping Admin (Shared)

```
travel_core/views/travel_features/manage.tpl
├── Tab: Board Types
│   - Canonical code | Display (EN) | Display (RO) | Novoton aliases | Sphinx aliases
│   - [+ Add canonical] [+ Add alias]
├── Tab: Room Types
│   - Same structure
├── Tab: Stars
│   - Linked to CS-Cart feature variants
├── Tab: Property Types
│   - Same structure
└── Tab: Locations
    - Canonical name | GeoName ID | Novoton aliases | Sphinx aliases
```

### 9.3 Dashboard (Per-Provider, in Provider Addon)

Each provider keeps its own dashboard for API-specific stats:

```
novoton_holidays/views/novoton_holidays/manage.tpl
├── Hotels synced, prices updated, etc.
├── Cron status / last sync times
└── Provider-specific tools (check prices, add products, etc.)
```

---

## 10. Shared Email Templates

### 10.1 Order Email Hook

```smarty
{* travel_core/hooks/orders/product_info.post.tpl *}

{if $product.extra.travel_booking}
<table style="width:100%; font-size:12px; border-collapse:collapse;">
    <tr>
        <td colspan="2" style="background:#f0f0f0; padding:8px; font-weight:bold;">
            {$product.extra.hotel_name}
            {if $product.extra.hotel_city} - {$product.extra.hotel_city}{/if}
            <span style="float:right; color:#666; font-size:11px;">
                via {$product.extra.travel_provider|upper}
            </span>
        </td>
    </tr>
    <tr>
        <td style="padding:4px 8px;">Check-in:</td>
        <td>{$product.extra.check_in|date_format:"%d %b %Y"}</td>
    </tr>
    <tr>
        <td style="padding:4px 8px;">Check-out:</td>
        <td>{$product.extra.check_out|date_format:"%d %b %Y"}
            ({$product.extra.nights} {if $product.extra.nights == 1}night{else}nights{/if})</td>
    </tr>
    <tr>
        <td style="padding:4px 8px;">Room:</td>
        <td>{$product.extra.room_type_display}</td>
    </tr>
    <tr>
        <td style="padding:4px 8px;">Board:</td>
        <td>{$product.extra.board_name}</td>
    </tr>
    {* Guest list — uses shared guest formatting *}
    {include file="addons/travel_core/components/email_guest_list.tpl"
             guests_data=$product.extra.guests_data
             rooms_data=$product.extra.rooms_data
             num_rooms=$product.extra.num_rooms}
</table>
{/if}
```

---

## 11. Shared JavaScript & CSS

### 11.1 JavaScript Modules (Move to `travel_core`)

| File | Size | Purpose | API-dependent? |
|---|---|---|---|
| `dob-validation.js` | 26KB | DOB masking, age validation at check-in | No |
| `booking-form-validation.js` | 23KB | Form validation, submission handling | Mostly no (AJAX price recalc is pluggable) |
| `multiroom-booking.js` | 8.5KB | Room selection, total calculation | No |
| `utils.js` | 8.7KB | HTML escaping, price formatting, i18n | No |
| `react19-bundle.js` | 43KB | React search form component | Needs provider-aware data fetching |
| `react-vendor.js` | 185KB | React vendor library | No |

**For the React search component**, the data source becomes pluggable:

```javascript
// React component receives provider via data attribute
<div id="travel-search-form-root"
     data-provider="novoton"
     data-search-url="{fn_url('travel_booking.search')}"
     data-hotel-id="{$hotel_id}"
     ...
/>
```

### 11.2 CSS Architecture (Move to `travel_core`)

The CSS custom properties bridge already makes styles theme-agnostic:

```css
/* travel_core/styles.css — shared across all providers */
:root {
    --tvl-primary: var(--nvt-primary, #003580);
    --tvl-success: var(--nvt-success, #28a745);
    --tvl-danger:  var(--nvt-danger, #dc3545);
    --tvl-warning: var(--nvt-warning, #ffc107);
    --tvl-border:  var(--nvt-border, #dee2e6);
    --tvl-bg:      var(--nvt-bg, #ffffff);
    --tvl-radius:  var(--nvt-radius, 8px);
    --tvl-font:    var(--nvt-font-family, inherit);
}

/* All existing .novoton-* classes renamed to .tvl-* */
.tvl-reservation-form { max-width: 900px; margin: 0 auto; }
.tvl-reservation-header { background: linear-gradient(135deg, var(--tvl-primary), ...); }
.tvl-guest-entry { ... }
.tvl-result-row { ... }
/* etc. */
```

### 11.3 Smarty Modifiers (Shared)

```php
// travel_core/init.php

// Replace provider-specific modifiers with shared ones
$smarty->registerPlugin('modifier', 'travel_format_board', function($code) {
    return \Tygh\Addons\TravelCore\Services\FeatureMapper::getDisplayName('board', $code);
});

$smarty->registerPlugin('modifier', 'travel_format_room_type', function($code) {
    return \Tygh\Addons\TravelCore\Services\FeatureMapper::getDisplayName('room_type', $code);
});

// Legacy aliases for backward compatibility during migration
$smarty->registerPlugin('modifier', 'novoton_format_board', function($code) {
    return \Tygh\Addons\TravelCore\Services\FeatureMapper::getDisplayName('board', $code);
});
```

---

## 12. Provider Adapter Contract

### 12.1 Provider Registration

Each API addon registers itself with `travel_core` on install:

```php
// novoton_holidays/init.php (during addon initialization)

use Tygh\Addons\TravelCore\TravelProviderRegistry;

TravelProviderRegistry::register('novoton', [
    'name'                => 'Novoton',
    'adapter_class'       => \Tygh\Addons\NovotonHolidays\Adapters\NovotonTravelAdapter::class,
    'hotel_table'         => 'novoton_hotels',
    'product_code_prefix' => 'NVT-',
    'supports_multi_room' => true,
    'supports_alternatives' => true,
    'supports_calendar_prices' => true,
]);
```

### 12.2 Master Adapter Interface

Each provider implements a single adapter that covers all touchpoints:

```php
namespace Tygh\Addons\TravelCore\Contracts;

interface TravelAdapterInterface
    extends SearchAdapterInterface,
            BookingSubmitterInterface,
            PriceVerifierInterface
{
    /**
     * Get the provider identifier.
     */
    public function getProvider(): string;

    /**
     * Resolve hotel_id → CS-Cart product_id.
     */
    public function resolveProductId(string $hotelId): int;

    /**
     * Get bookings for given product IDs (for cart injection).
     */
    public function getBookingsForProducts(array $productIds, string $sessionId, int $userId): array;

    /**
     * Get hotel display info (name, city, country, stars, image URL).
     */
    public function getHotelDisplayInfo(string $hotelId): array;

    /**
     * Format terms of payment as HTML.
     */
    public function formatPaymentTerms($termsData): string;

    /**
     * Format terms of cancellation as HTML.
     */
    public function formatCancellationTerms($termsData): string;

    /**
     * Register feature aliases with the FeatureMapper.
     * Called during addon install.
     */
    public function registerAliases(): void;

    /**
     * Get commission rate for a hotel/booking.
     */
    public function getCommissionRate(string $hotelId): float;
}
```

### 12.3 Provider Registry

```php
namespace Tygh\Addons\TravelCore;

class TravelProviderRegistry
{
    private static array $providers = [];

    public static function register(string $provider, array $config): void;

    public static function getAdapter(string $provider): TravelAdapterInterface;

    /**
     * Determine which provider owns a hotel_id.
     * Checks each provider's hotel table in registration order.
     */
    public static function getProviderForHotel(string $hotelId): string;

    /**
     * Determine which provider owns a CS-Cart product.
     * Uses product_code prefix matching.
     */
    public static function getProviderForProduct(int $productId): ?string;

    /**
     * Get all registered provider names.
     */
    public static function allProviders(): array;

    /**
     * Check if a provider is registered and active.
     */
    public static function isActive(string $provider): bool;
}
```

---

## 13. Data Flow Diagrams

### 13.1 Search Flow (Shared)

```
Customer enters search params on hotel page
    │
    ▼
┌─────────────────────────────────────────────────────────────────┐
│ travel_core: SearchParameterNormalizer.normalize()               │
│   - Parse dates, rooms, occupancy                               │
│   - Validate constraints (max adults, max children, max nights) │
│   - Return standardized params                                  │
└──────────────────────────┬──────────────────────────────────────┘
                           │
                           ▼
┌─────────────────────────────────────────────────────────────────┐
│ travel_core: Determine provider for hotel_id                    │
│   → TravelProviderRegistry::getProviderForHotel($hotel_id)     │
└──────────────────────────┬──────────────────────────────────────┘
                           │
              ┌────────────┴────────────┐
              ▼                         ▼
    ┌─────────────────┐      ┌──────────────────┐
    │ NovotonSearch    │      │ SphinxSearch      │
    │ Adapter          │      │ Adapter           │
    │                  │      │                   │
    │ - room_price XML │      │ - GET /avail JSON │
    │ - hotel_quota    │      │ - included quota  │
    │ - Apply 12% comm │      │ - Apply 8% comm   │
    │ - Parse XML resp │      │ - Parse JSON resp │
    └────────┬────────┘      └────────┬──────────┘
             │                        │
             └────────────┬───────────┘
                          │ Standardized result items
                          ▼
┌─────────────────────────────────────────────────────────────────┐
│ travel_core: SearchResultFormatter                              │
│   - Assign template variables                                   │
│   - Board/room names via FeatureMapper                         │
│   - Currency conversion                                         │
│   - SEO meta variables                                          │
└──────────────────────────┬──────────────────────────────────────┘
                           │
                           ▼
                    search.tpl renders
```

### 13.2 Booking Flow (Shared)

```
Customer selects room from search results
    │
    ▼
┌──────────────────────────────────────────────────────────────┐
│ travel_core: booking_form.tpl                                │
│   - Provider passes standardized $booking_data               │
│   - Shared guest entry fields (name, DOB)                    │
│   - Shared JS (DOB mask, validation, multi-room)             │
│   - [Complete Booking] → POST travel_booking.add_to_cart     │
└──────────────────────────┬───────────────────────────────────┘
                           │
                           ▼
┌──────────────────────────────────────────────────────────────┐
│ travel_core: add_to_cart controller                           │
│                                                               │
│ 1. SecurityService.validateBookingData()     ← shared        │
│ 2. GuestDataService.parseGuestsData()        ← shared        │
│ 3. $adapter->verifyPrice($params)            ← PROVIDER      │
│ 4. BookingRepository.create($booking)        ← shared DB     │
│ 5. CartProductBuilder.assemble()             ← shared        │
│ 6. fn_add_product_to_cart()                  ← CS-Cart       │
└──────────────────────────┬───────────────────────────────────┘
                           │
                           ▼
┌──────────────────────────────────────────────────────────────┐
│ travel_core: Cart display                                    │
│   - Shared templates show hotel, dates, rooms, guests        │
│   - Provider badge shows data source                         │
└──────────────────────────┬───────────────────────────────────┘
                           │ Customer places order
                           ▼
┌──────────────────────────────────────────────────────────────┐
│ travel_core: TravelBookingOrchestrator.submitOrder()         │
│                                                               │
│ 1. Find travel bookings in cart                  ← shared    │
│ 2. Group by provider                             ← shared    │
│ 3. Hydrate from travel_bookings DB               ← shared    │
│ 4. Group rooms by package/dates                  ← shared    │
│ 5. $adapter->submit($bookingGroup)               ← PROVIDER  │
│ 6. Update DB with API result                     ← shared    │
│ 7. Send confirmation email                       ← shared    │
└──────────────────────────────────────────────────────────────┘
```

### 13.3 Feature Resolution Flow

```
API returns room data (e.g., Sphinx: "Twin Room with Sea View", meal: "Mic dejun")
    │
    ▼
┌──────────────────────────────────────────────────────────────┐
│ Provider Adapter (sphinx_holidays)                            │
│                                                               │
│ $board = FeatureMapper::resolve('sphinx', 'board', 'Mic dejun');
│ → {canonical_code: 'BB', display_name: 'Bed & Breakfast'}   │
│                                                               │
│ $room = FeatureMapper::resolve('sphinx', 'room_type', 'Twin Room');
│ → {canonical_code: 'TWIN', display_name: 'Camera Twin'}     │
│   (match_type: 'prefix' matches "Twin Room with Sea View")  │
└──────────────────────────┬───────────────────────────────────┘
                           │
                           ▼
              Standardized result item uses canonical codes
              → Templates display canonical display_names
              → Filters work across providers
```

---

## 14. Migration Strategy

### Phase 1: Create `travel_core` (No Breaking Changes)

1. **Create `travel_core` addon** with:
   - `travel_feature_map` + `travel_api_alias` tables
   - `FeatureMapper` service
   - `TravelProviderRegistry`
   - Interfaces: `TravelAdapterInterface`, `SearchAdapterInterface`, `BookingSubmitterInterface`, `PriceVerifierInterface`

2. **Seed canonical data** from existing Novoton `BoardType::DISPLAY_NAMES`, `RoomType::DISPLAY_NAMES`, star ratings

3. **Seed Novoton aliases** from `BoardType::ALIASES`, `RoomType::ALIASES` with `api_source='novoton'`

**Impact:** Zero. New addon exists but nothing uses it yet.

### Phase 2: Move Generic Services (Low Risk)

4. **Copy** (not move) generic services to `travel_core`:
   - `GuestDataService` → `Tygh\Addons\TravelCore\Services\GuestDataService`
   - `GuestDataNormalizer` → `Tygh\Addons\TravelCore\Services\GuestDataNormalizer`
   - `SearchParameterNormalizer` → `Tygh\Addons\TravelCore\Services\SearchParameterNormalizer`
   - `PreOrderPriceVerifier` → `Tygh\Addons\TravelCore\Services\PreOrderPriceVerifier`
   - `ValidationHelper` → `Tygh\Addons\TravelCore\Services\ValidationHelper`
   - `SecurityService` → `Tygh\Addons\TravelCore\Services\SecurityService`
   - `DateHelper` → `Tygh\Addons\TravelCore\Services\DateHelper`
   - `CurrencyService` → `Tygh\Addons\TravelCore\Services\CurrencyService`

5. **Update Novoton** to import from `travel_core` (class aliases or direct use)

**Impact:** Internal refactor only. All external behavior identical.

### Phase 3: Move Templates & Assets (Medium Risk)

6. **Copy templates** to `travel_core`:
   - Booking form (`.tpl` files)
   - Cart/checkout hooks
   - Email hooks
   - Booking summary blocks

7. **Copy JS/CSS** to `travel_core`:
   - DOB validation, multi-room, utils, React bundle, styles

8. **Update template paths** in Novoton to include from `travel_core`

9. **Rename CSS classes** from `.novoton-*` to `.tvl-*` with backward-compat aliases

**Impact:** Visual appearance unchanged. Template paths updated.

### Phase 4: Move Booking Table (Higher Risk)

10. **Create `travel_bookings`** table with migration script
11. **Migrate existing `novoton_bookings`** data (add `provider='novoton'` column)
12. **Update Novoton** to use shared `BookingRepository`
13. **Deprecate** `novoton_bookings` table (keep as read-only archive for 1 release)

**Impact:** Database schema change. Requires careful migration testing.

### Phase 5: Build Sphinx Adapter

14. **Create `sphinx_holidays`** addon with:
    - `SphinxApi` REST client
    - `SphinxTravelAdapter` implementing `TravelAdapterInterface`
    - Sphinx-specific hotel tables (`sphinx_hotels`, `sphinx_hotel_packages`)
    - Sphinx alias registration (board names in Romanian, room description prefixes)
    - Sphinx cron commands for data sync

15. **Register** Sphinx aliases in `travel_api_alias`

**Impact:** New provider available. Novoton unaffected.

---

## 15. File Layout

```
app/addons/
├── travel_core/                              # SHARED ADDON
│   ├── addon.xml
│   ├── init.php                              # PSR-4 autoloader, Smarty modifiers
│   ├── hooks.php                             # Hook dispatcher
│   ├── func.php                              # Shared utility functions
│   │
│   ├── controllers/
│   │   ├── backend/
│   │   │   ├── travel_bookings.php           # Admin booking management
│   │   │   └── travel_features.php           # Feature mapping admin
│   │   └── frontend/
│   │       ├── travel_booking.php            # Main booking controller
│   │       └── travel_booking/
│   │           ├── search.php                # Search mode
│   │           ├── booking_form.php          # Guest entry form
│   │           ├── add_to_cart.php           # Finalize booking
│   │           ├── edit_booking.php          # Edit in cart
│   │           └── update_booking.php        # Save edits
│   │
│   ├── hooks/
│   │   ├── cart_hooks.php                    # Cart display, injection
│   │   ├── order_hooks.php                   # Order placement, booking submission
│   │   └── product_hooks.php                 # Product page enrichment
│   │
│   ├── src/
│   │   ├── Contracts/                        # Provider interfaces
│   │   │   ├── TravelAdapterInterface.php
│   │   │   ├── SearchAdapterInterface.php
│   │   │   ├── BookingSubmitterInterface.php
│   │   │   └── PriceVerifierInterface.php
│   │   │
│   │   ├── Services/                         # Generic services
│   │   │   ├── FeatureMapper.php
│   │   │   ├── GuestDataService.php
│   │   │   ├── GuestDataNormalizer.php
│   │   │   ├── SearchParameterNormalizer.php
│   │   │   ├── SearchResultFormatter.php
│   │   │   ├── PreOrderPriceVerifier.php
│   │   │   ├── BookingOrchestrator.php
│   │   │   ├── CartProductBuilder.php
│   │   │   ├── CartDisplayFormatter.php
│   │   │   ├── SecurityService.php
│   │   │   ├── CurrencyService.php
│   │   │   ├── DateHelper.php
│   │   │   └── ValidationHelper.php
│   │   │
│   │   ├── Repository/
│   │   │   ├── BookingRepository.php         # Unified travel_bookings
│   │   │   └── FeatureMapRepository.php      # travel_feature_map
│   │   │
│   │   └── TravelProviderRegistry.php        # Provider registry
│   │
│   └── schemas/
│       └── permissions/admin.post.php
│
├── novoton_holidays/                          # NOVOTON ADAPTER (slimmed)
│   ├── addon.xml                             # dependencies: travel_core
│   ├── init.php
│   │
│   ├── src/
│   │   ├── Adapters/
│   │   │   └── NovotonTravelAdapter.php      # implements TravelAdapterInterface
│   │   ├── Api/                              # Unchanged API clients
│   │   │   ├── ApiClientBase.php
│   │   │   ├── AvailabilityApiClient.php
│   │   │   ├── HotelApiClient.php
│   │   │   ├── PricingApiClient.php
│   │   │   └── ReservationApiClient.php
│   │   ├── Cron/                             # Unchanged cron commands
│   │   ├── Helpers/                          # Sync helpers
│   │   ├── Repository/
│   │   │   ├── HotelRepository.php           # novoton_hotels (provider-specific)
│   │   │   └── HotelPackageRepository.php    # novoton_hotel_packages
│   │   ├── Services/
│   │   │   ├── NovotonSearchAdapter.php      # Wraps API calls for search
│   │   │   ├── NovotonBookingSubmitter.php   # Wraps API calls for booking
│   │   │   ├── NovotonPriceVerifier.php      # Wraps room_price for verification
│   │   │   └── ConfigProvider.php            # Novoton-specific settings
│   │   └── ValueObjects/                     # Novoton-specific codes (for API calls)
│   │       ├── BoardType.php
│   │       └── RoomType.php
│   │
│   ├── controllers/
│   │   └── backend/
│   │       ├── novoton_holidays.php          # Novoton dashboard
│   │       ├── novoton_hotels.php            # Hotel sync management
│   │       └── novoton_diagnostic.php        # API diagnostics
│   │
│   └── functions/
│       ├── hotels.php                        # Sync functions
│       ├── install.php                       # Install + alias registration
│       └── exchange_rates.php                # BNR rates
│
└── sphinx_holidays/                           # SPHINX ADAPTER (future)
    ├── addon.xml                             # dependencies: travel_core
    ├── init.php
    │
    ├── src/
    │   ├── Adapters/
    │   │   └── SphinxTravelAdapter.php       # implements TravelAdapterInterface
    │   ├── Api/
    │   │   └── SphinxApiClient.php           # REST/JSON client
    │   ├── Cron/
    │   │   └── Commands/
    │   │       ├── AccommodationSyncCommand.php
    │   │       └── AvailabilitySyncCommand.php
    │   ├── Repository/
    │   │   └── SphinxHotelRepository.php     # sphinx_hotels (provider-specific)
    │   └── Services/
    │       ├── SphinxSearchAdapter.php
    │       ├── SphinxBookingSubmitter.php
    │       ├── SphinxPriceVerifier.php
    │       └── SphinxConfigProvider.php
    │
    └── functions/
        └── install.php                       # Install + alias registration

design/themes/nova_theme/templates/addons/
├── travel_core/                               # SHARED TEMPLATES
│   ├── views/
│   │   └── travel_booking/
│   │       ├── search.tpl                    # Search results page
│   │       └── booking_form.tpl              # Guest entry form
│   ├── blocks/
│   │   ├── booking_engine.tpl                # Search widget
│   │   ├── booking_summary.tpl               # Reusable summary
│   │   └── product_tabs/
│   │       └── hotel_prices.tpl              # Prices tab
│   ├── hooks/
│   │   ├── cart_content/
│   │   │   └── product_info.post.tpl         # Cart display
│   │   ├── checkout/
│   │   │   ├── product_info.post.tpl         # Checkout display
│   │   │   └── summary_extra.post.tpl        # Sidebar summary
│   │   ├── block_checkout/
│   │   │   └── product_extra.post.tpl        # Mini checkout
│   │   └── common/
│   │       └── product_info.post.tpl         # Product page
│   └── components/
│       ├── guest_entry.tpl                   # Guest name/DOB block
│       ├── room_card.tpl                     # Collapsible room card
│       ├── provider_badge.tpl                # "via Novoton" / "via Sphinx"
│       └── email_guest_list.tpl              # Email guest formatting
│
├── novoton_holidays/                          # NOVOTON-SPECIFIC TEMPLATES
│   └── views/
│       └── novoton_holidays/
│           └── manage.tpl                    # Novoton dashboard only
│
└── sphinx_holidays/                           # SPHINX-SPECIFIC TEMPLATES
    └── views/
        └── sphinx_holidays/
            └── manage.tpl                    # Sphinx dashboard only

js/addons/
├── travel_core/                               # SHARED JS
│   ├── dob-validation.js
│   ├── booking-form-validation.js
│   ├── multiroom-booking.js
│   ├── utils.js
│   ├── react-vendor.js
│   └── react19-bundle.js
│
├── novoton_holidays/                          # NOVOTON-SPECIFIC JS (if any)
│   └── admin-dashboard.js
│
└── sphinx_holidays/                           # SPHINX-SPECIFIC JS (if any)
    └── admin-dashboard.js

css/addons/
├── travel_core/
│   └── styles.css                            # All shared styles (.tvl-* classes)
├── novoton_holidays/
│   └── admin.css                             # Novoton admin-only styles
└── sphinx_holidays/
    └── admin.css                             # Sphinx admin-only styles

design/backend/templates/addons/
├── travel_core/
│   └── views/
│       ├── travel_bookings/
│       │   ├── manage.tpl                    # Shared booking list
│       │   └── view.tpl                      # Shared booking detail
│       └── travel_features/
│           └── manage.tpl                    # Feature mapping admin
│
├── novoton_holidays/
│   └── views/
│       ├── novoton_holidays/
│       │   └── manage.tpl                    # Novoton dashboard
│       └── novoton_hotels/
│           └── manage.tpl                    # Hotel sync admin
│
└── sphinx_holidays/
    └── views/
        └── sphinx_holidays/
            └── manage.tpl                    # Sphinx dashboard

design/backend/mail/templates/addons/
└── travel_core/
    └── hooks/
        └── orders/
            └── product_info.post.tpl         # Shared email template
```

---

## Summary: What Goes Where

### `travel_core` Owns (~70% of current code):

| Category | Components |
|---|---|
| **Database** | `travel_bookings`, `travel_feature_map`, `travel_api_alias` |
| **Services** | FeatureMapper, GuestDataService, GuestDataNormalizer, SearchParameterNormalizer, SearchResultFormatter, PreOrderPriceVerifier, BookingOrchestrator, CartProductBuilder, CartDisplayFormatter, SecurityService, CurrencyService, DateHelper, ValidationHelper |
| **Contracts** | TravelAdapterInterface, SearchAdapterInterface, BookingSubmitterInterface, PriceVerifierInterface |
| **Registry** | TravelProviderRegistry |
| **Templates** | Booking form, search results, cart hooks, checkout hooks, email hooks, admin bookings, admin features |
| **JavaScript** | DOB validation, form validation, multi-room, utils, React search |
| **CSS** | All shared styles (`.tvl-*`) |

### Each API Addon Owns (~30% of current code):

| Category | Components |
|---|---|
| **Database** | Provider hotel tables, package tables, sync tables, cache tables |
| **API clients** | HTTP client, XML/JSON parsing, request building |
| **Adapter** | Implements TravelAdapterInterface (search, submit, verify) |
| **Cron** | All sync commands (hotel list, prices, facilities, etc.) |
| **Config** | Provider-specific settings (API URL, credentials, commission) |
| **Dashboard** | Provider admin dashboard with sync stats |
| **Alias seeds** | Board/room/star aliases registered during install |
| **ValueObjects** | Provider-specific codes for API communication |

### Backward Compatibility During Migration:

- Novoton `BoardType`/`RoomType` ValueObjects remain for internal API communication
- Old Smarty modifiers (`novoton_format_board`) become aliases to shared ones
- Old CSS classes (`.novoton-*`) get backward-compat aliases via `styles.css`
- Old `novoton_bookings` table data migrated to `travel_bookings` with `provider='novoton'`
- Old controller routes (`novoton_booking.search`) redirect to shared routes

## 16. Referential Integrity Model (decided 2026-07-01, audit H3/H4)

One rule, applied to both providers:

**FKs (`ON DELETE CASCADE`) only for true child data that must die with its
parent. No FKs for historical records or cross-aggregate references — those are
denormalized snapshots keyed by stable external ids, kept consistent by hooks
and cleanup crons.**

| Relationship | Mechanism | Why |
|---|---|---|
| `novoton_hotel_packages` / `novoton_hotel_facilities` → `novoton_hotels` | FK `CASCADE` | Pure child data; meaningless without the hotel. |
| `novoton_bookings` / `novoton_alternative_requests` → hotels | **No FK** (was `RESTRICT`, dropped) | Historical records that must outlive the hotel; they denormalize `hotel_name`, dates, prices. `hotel_id` is the stable Novoton `IdHotel`, so the join is restored automatically when a hotel is re-synced. `RESTRICT` made booked hotels undeletable and broke delete-then-reinsert reconciliation. Reads must LEFT JOIN hotels (see `BookingQueryService`). |
| `*_hotels.product_id` → `?:products` | **No FK**; `delete_product_post` hook (novoton) / `CleanupCommand` orphan-unlink (sphinx) | CS-Cart core owns products; an FK into a core table is fragile across core upgrades. |
| `sphinx_destination_whitelist.destination_id` → `sphinx_destinations` | **No FK, no auto-cleanup — intentional** | The whitelist is *admin configuration*; entries must survive a destination table re-import. Auto-deleting "orphans" during a re-sync would destroy the admin's whitelist. |
| Sphinx tables generally | **No FKs by design** | Consistency via sync upserts + `CleanupCommand` (orphan bookings, stale logs, expired cache, orphan product links). |

Consequences for new code:
- Never add a `RESTRICT` FK to a table that accumulates history.
- Any query joining history → master data must use `LEFT JOIN` and render from
  the denormalized columns when the master row is absent.
- Adding an FK to an *existing* table requires an orphan-cleanup migration
  first (`ADD CONSTRAINT` fails on violating rows) — prefer the cron model.

## 17. Booking-ID Contract on Order Items (decided 2026-07-10)

Order-item `extra` carries booking identifiers in TWO distinct id-spaces.
The contract, enforced by `travel_core`'s `BookingIdContractTest`:

| Key                          | Id-space                                   | Written by                              |
|------------------------------|--------------------------------------------|-----------------------------------------|
| `novoton_booking_id`         | `novoton_bookings.booking_id` (provider PK)| novoton add_to_cart (persisted)          |
| `travel_booking_id`          | `sphinx_bookings.booking_id` (provider PK — legacy name, sphinx items only) | sphinx add_to_cart (persisted) |
| `eurosite_booking_id`, `booking_id` | `eurosite_bookings.booking_id` (provider PK; `booking_id` is the same value under a generic name) | eurosite add_to_cart (persisted) |
| `travel_surrogate_id`        | `travel_bookings.booking_id` (unified surrogate) | each provider's `get_order_info` hook — novoton, sphinx and eurosite (computed per render, never persisted) |

Rules:
1. `travel_surrogate_id` is the ONLY key allowed in `travel_bookings.view`
   links and unified-table lookups.
2. Provider actions (retry, resinfo, cancel…) take the PROVIDER PK — the
   unified grid passes `provider_booking_id` from the mirror row.
3. Never publish a surrogate under `travel_booking_id`. That mix-up (novoton
   did exactly this until 2026-07-10) is what made booking #1 open a different
   customer's booking depending on provider.
