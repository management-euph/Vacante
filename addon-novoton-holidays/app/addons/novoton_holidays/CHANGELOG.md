# Novoton Holidays - Changelog

## 4.1.0 — Destination whitelist

- **NEW:** Novoton → Destinations: per country Not sold / All resorts / Only selected, replacing the dashboard's Excluded resorts and the "Selected countries" setting once saved. New and vanished resorts are flagged; "Disable products outside the whitelist" is its own confirmed step.
- **CHANGED:** the Destinations page runs on Travel Core's shared destination picker (the one Eurosite and Sphinx move to next): chips and a filter inside a country, live products as a pill, the countries with no hotels folded into one line, products outside the whitelist grouped by resort, and Save in the summary and in a bar that appears once something is unsaved (no longer in the top row with the page links).
- **FIXED:** every country row read "Array resorts · 1121 hotels", and the dashboard card "ALL Array resorts" / "71 of Array": the resort list and the resort count shared a key.
- **FIXED:** a country with no hotels showed two messages when opened.
- **CHANGED:** every product creator (add_hotels_as_products, offers_update, admin run_cron) and the per-hotel API syncs (hotelinfo, priceinfo, facilities, room_price) follow the whitelist, plus hotels with live products.
- **FIXED:** compute_prices and the per-hotel price update wrote `?:products.price`, a column CS-Cart 4 does not have ("Unknown column 'price' (1054)" per hotel); catalog prices now go to the base row of `?:product_prices`.
- **FIXED:** offers_update and incremental hotelinfo read only the first `<Offer>`.
- **FIXED:** products were created without a description: Novoton sends it as HTML elements inside `<Description>`, and `(string) $response->Description` read only the whitespace around them. `HotelDescription` reads the HTML and drops the Word-pasted inline styles. New on-demand job `backfill_descriptions` fills in the products already created.
- **FIXED:** the admin title and diagnostics showed the version the store was installed with; they now show the running code's version.

## A96 — Refactor: CS-Cart Special CSS Classes in Admin Templates

- **REFACTORED:** Backend admin templates to use CS-Cart special utility classes instead of inline styles
- `center` class replaces `style="text-align: center;"` in stats cards (view_hotels_to_add.tpl)
- `no-margin` class replaces `style="margin: 0;"` on headings/paragraphs (view_hotels_to_add.tpl, alternatives.tpl)
- `hand` class replaces `style="cursor: pointer;"` on checkbox label (manage.tpl)
- `checkbox` class added for proper checkbox alignment in forms (manage.tpl)
- **SCOPE:** Backend admin templates only — frontend theme templates intentionally unchanged (they follow theme-specific CSS, not admin utility classes)

### Files Changed

- `design/backend/templates/.../views/novoton_holidays/view_hotels_to_add.tpl` — `center`, `no-margin` (8 edits)
- `design/backend/templates/.../views/novoton_bookings/alternatives.tpl` — `no-margin` (3 edits)
- `design/backend/templates/.../views/novoton_bookings/manage.tpl` — `hand`, `checkbox` (2 edits)

---

## A95 — Fix: Cross-Provider Order Placement + Admin Failure Notifications

### Problem

Mixed-provider orders (Novoton + Sphinx hotels in one cart) had two critical issues:

1. **Sphinx `pre_place_order` blocked entire order:** If any Sphinx offer became unavailable, `$allow = false` was set, blocking valid Novoton bookings too
2. **Failed booking status lost after ROLLBACK:** `BookingSubmissionService` set `status = 'failed'` inside a DB transaction, but the outer catch blocks did `ROLLBACK` — undoing the failed status. Bookings silently reverted to `pending` on API failure.

### Fix 1: Novoton BookingSubmissionService — failed status survives ROLLBACK

- **FIXED:** All 3 catch blocks (`ApiException`, `NovotonException`, `\Throwable`) now re-apply `STATUS_FAILED` + `order_id` + error notes **after** the `ROLLBACK`, so the failure status persists in DB
- The `bookingRepo->update()` call outside the transaction ensures both `novoton_bookings` and `travel_bookings` (via dual-write) record the failure

### Fix 2: Admin panel notification via `fn_set_notification('W', ...)`

- **NEW:** `fn_novoton_holidays_get_order_info()` now checks for failed bookings when `AREA === 'A'` (admin panel)
- Queries `novoton_bookings` by `order_id`, and if any booking has `status = 'failed'`, shows an **orange warning notification** via `fn_set_notification('W', __('warning'), ...)`
- Notification displays hotel name and order ID, appears every time admin opens the order
- Guarded by `AREA === 'A'` — customers never see this notification

### Language Variables

- **NEW:** `novoton_holidays.booking_api_failed` (en + ro) — admin warning message for failed API submissions

### Files Changed

- `hooks/order_hooks.php` — admin notification in `fn_novoton_holidays_get_order_info()`
- `src/Services/BookingSubmissionService.php` — re-apply failed status after ROLLBACK in all catch blocks
- `var/langs/en/addons/novoton_holidays.po` — new language variable
- `var/langs/ro/addons/novoton_holidays.po` — new language variable (Romanian)

---

## A94 — Fix: Child Pricing Discrepancy (Exact Age Matching + Descending Sort)

### Bug Fix: 2nd+ child priced at wrong percentage (hotel 476 FAM 3+2 DELUXE)

- **ROOT CAUSE:** `matchAgeType()` had a fuzzy ordinal-stripping fallback that treated "1 ST CHD 2-11,99" and "2 ND CHD 2-11,99" as equivalent — both matched any CHD row. The 2nd child got the 1st child's 50% rate instead of its own 25% rate.
- **IMPACT:** Price calculated as 4,123.10 EUR instead of correct 3,867.50 EUR (+6.6% overcharge)

### Fix 1: Exact age type matching (no fuzzy logic)

- **REMOVED:** All fuzzy/ordinal-stripping logic from `matchAgeType()`. The API data (fAge field) always provides the full, specific age type — there is no real scenario with bare "CHD 2-11.99" without an ordinal prefix.
- **REMOVED:** `matchAgeTypeScore()` — unnecessary with exact matching.
- **SIMPLIFIED:** `matchAgeType()` now does exact comparison with only comma/dot normalization (serialization artifact tolerance). "1 ST CHD 2-11,99" ≠ "2 ND CHD 2-11,99" — they are distinct pricing types.
- **SIMPLIFIED:** `findSeasonPriceRow()` back to simple FromDays selection (no scoring needed when matching is exact).

### Fix 2: Children sorted by age descending

- **FIXED:** `buildOccupancyStructure()` now sorts children by age descending (oldest first) before assigning ordinals. The API expects the oldest child = 1ST CHD (highest discount %).
- **EXAMPLE:** Children [2, 11] → sorted [11, 2] → 11yo=1ST CHD (50%), 2yo=2ND CHD (25%)

### Price Verification (hotel 476, FAM 3+2 DELUXE, 3 adults + 2 children ages 11,2)

| Component | Before | After |
|---|---|---|
| 1st CHD (11 y.o.) | 50% × 1,136 = 568 | 50% × 1,136 = 568 |
| 2nd CHD (2 y.o.) | **50%** × 1,136 = **568** | **25%** × 1,136 = **284** |
| Base total | 4,544 | **4,260** |
| After EB -10% | 4,089.60 | **3,834** |
| + Handling fee | **4,123.10** | **3,867.50** ✓ |

### Architecture Decision

Fuzzy matching (ordinal stripping, scored selection) was rejected as over-engineering. The API data contract guarantees specific age types via the `fAge` field. Exact matching is simpler, correct, and impossible to break with edge cases.

### Documentation

- **UPDATED:** `Documentation/PriceInfo_calculation_algorithm.txt` v1.5 — child sorting rule, exact age type matching policy

### Files Changed

- `src/Services/PriceInfoFormatter.php` — matchAgeType rewritten as exact match, matchAgeTypeScore removed
- `src/Services/PriceInfoCalculator.php` — findSeasonPriceRow simplified
- `src/Services/PriceInfoParser.php` — rsort children in buildOccupancyStructure
- `Documentation/PriceInfo_calculation_algorithm.txt` — v1.5 updates

---

## A93 — Fix: Children Per-Person Price Calculation (Code/Base Percentage Rule)

### Bug Fix: Children price not calculated in base price breakdown

- **FIXED:** `PriceInfoCalculator::resolvePrice()` now correctly detects percentage-based pricing using the Code/Base rule: when `Code != Base`, price values are percentages of the base row (the row where `Code == current row's Base`), even without an explicit `%` suffix
- **FIXED:** Previously, percentage detection relied solely on the `%` character in the price string. If percentage values were stored as plain numbers (e.g., `20` instead of `"20%"`), children's prices resolved to the raw number instead of being calculated as a percentage of the adult base price
- **ADDED:** `PriceInfoCalculator::findBestBaseRow()` — when multiple season_price rows share the same Code, the lookup now prefers the row matching the current row's IdRoom and IdBoard for accurate percentage resolution
- **FIXED:** Per-person totals now always include entries for all occupants (adults and children), even when no matching season_price row is found, ensuring the price comparison UI always displays all persons

### Pricing Rule (Code / Base relationship)

- `Code == Base` → Price1..Price20 values are absolute amounts (EUR)
- `Code != Base` → Price1..Price20 values are percentages of the base row's price, where base row is identified by `Code == current row's Base`

### Tests

- **ADDED:** `testGetPriceFromRowCodeNotBaseImplicitPercentage` — verifies implicit percentage (no `%` suffix) when Code != Base
- **ADDED:** `testGetPriceFromRowCodeEqualsBaseIsAbsolute` — verifies absolute pricing when Code == Base
- **ADDED:** `testGetPriceFromRowExplicitPercentWithCodeNotBase` — verifies explicit `%` still works
- **ADDED:** `testGetPriceFromRowBaseRowMatchesRoomBoard` — verifies room/board-aware base row selection
- **ADDED:** `testCalculateBasePriceIncludesChildPercentage` — end-to-end: adult room price + 2 children at 20%
- **ADDED:** `testCalculateBasePriceChildImplicitPercentage` — end-to-end: implicit percentage for child pricing

### Files Changed

- `src/Services/PriceInfoCalculator.php` — resolvePrice, findBestBaseRow, calculateBasePrice
- `tests/Unit/PriceInfoCalculatorTest.php` — 6 new test cases

---

## A92 — Audit Fixes: HotelSync has_room_price Bug, Method & Filter Renames

### Bug Fix: has_room_price incorrectly set in HotelSync.php (missed in A91)

- **FIXED:** `HotelSync::syncPackagesForHotel()` was setting `has_room_price` based on priceinfo package sync count — now correctly updates only `packages_count` (matching the A91 fix applied to `helpers.php`, `BatchedHotelInfoSync.php`, `PriceInfoSync.php`)

### Code Smell: Method and filter renames for clarity

- **RENAMED:** `batchUpdateHasPricesFlag()` → `batchUpdateHasRoomPriceFlag()` in `DatabaseHelper`, `DatabaseHelperInterface`, `RoomPriceCheckCommand` — method name now matches the `has_room_price` column it updates
- **RENAMED:** `has_room_prices` filter → `has_verified_room_price` in `HotelRepository::buildWhereClause()` and `novoton_holidays.php` controller — eliminates confusing singular/plural distinction with `has_room_price`

## A91 — room_price Exclusivity Fix, OK → Good Status Rename, Dashboard Documentation

### Bug Fix: has_room_price Set Exclusively by room_price Check

- **FIXED:** `has_room_price` and `last_price_check` are now set **only** by the room_price check process (Check Prices resort-based, Check Prices per-hotel, `room_price` cron mode)
- **REMOVED:** `has_room_price` assignment from Hotel Info Sync (`helpers.php`, `BatchedHotelInfoSync.php`) — these syncs now only update `packages_count`
- **REMOVED:** `has_room_price = 'Y'` from hotel info download in `novoton_hotels.php` controller — now only updates `packages_count`
- **REMOVED:** `has_room_price` and `last_price_check` from PriceInfo Sync (`PriceInfoSync.php`) — now only updates `packages_count`
- **RATIONALE:** Real-time prices are provided by the `room_price` API response; having packages (priceinfo) does not mean the hotel has real-time availability

### Status Rename: OK → Good

- **CHANGED:** `NOVOTON_STATUS_CONFIRMED` constant from `'OK'` to `'Good'` — all internal status storage and display now uses "Good"
- **CHANGED:** `AVAIL_OK` constant from `'OK'` to `'Good'`
- **ADDED:** `NOVOTON_API_WIRE_MAP` constant — maps API wire format `'OK'` to internal `'Good'`
- **ADDED:** `Constants::normalizeApiStatus()` static method — converts API responses (`'OK'` → `'Good'`) before storage
- **CHANGED:** `BookingSubmissionService` normalizes API status via `normalizeApiStatus()` before storing in DB
- **CHANGED:** `ResInfoCommand` normalizes API response status before comparison
- **CHANGED:** `RoomPriceService` and `SearchService` normalize availability status from API responses
- **CHANGED:** `BookingRepository` and `BookingRepositoryInterface` default parameter from `'OK'` to `'Good'`
- **CHANGED:** `novoton_admin.php` allowed statuses: `'OK'` → `'Good'`
- **CHANGED:** All booking template status comparisons and display labels from `'OK'` to `'Good'` (`manage.tpl`, `view.tpl`, `order_tab.tpl`)
- **CHANGED:** Alternative match labels from `[OK]` to `[Good]` (`alternatives.tpl`, `order_tab.tpl`, `test_alternative_rs.tpl`)
- **CHANGED:** Diagnostic output from `[OK]` to `[Good]` (`novoton_diagnostic.php`)
- **CHANGED:** Cron/CLI success output from `"OK"` to `"Good"` (`CalendarPricesCommand.php`, frontend controller)
- **CHANGED:** CSV export status from `[OK]` to `[Good]` (`novoton_holidays.php`, `novoton_tools.php`)
- **CHANGED:** `addon.xml` schema comment from `'OK, ASK, ST, WT, RQ'` to `'Good, ASK, ST, WT, RQ'`
- **KEPT:** `NOVOTON_STATUS_TO_INTERNAL` mapping accepts both `'OK'` (API wire) and `'Good'` (internal) → `STATUS_CONFIRMED`

### Documentation

- **DOCUMENTED:** Dashboard statistics "Real-time (room_price) available" and "Season prices (priceinfo) available" — explained DB conditions and what populates each counter
- **NOTED:** `room_price` available counter now reflects only hotels checked via room_price (not hotel info sync)
- **NOTED:** Check Prices (Resort-based) and Check Prices (Per-Hotel) currently return the same number of hotels with prices

### Files Changed (19)

Constants: `Constants.php`
Controllers: `novoton_holidays.php` (backend), `novoton_holidays.php` (frontend), `novoton_hotels.php`, `novoton_admin.php`, `novoton_diagnostic.php`, `novoton_prices.php` (unchanged — already correct), `novoton_tools.php`
Services: `BookingSubmissionService.php`, `RoomPriceService.php`, `SearchService.php`
Repository: `BookingRepository.php`, `BookingRepositoryInterface.php`
Sync: `BatchedHotelInfoSync.php`, `PriceInfoSync.php`
Cron: `ResInfoCommand.php`, `CalendarPricesCommand.php`
Functions: `helpers.php`
Templates: `manage.tpl`, `view.tpl`, `order_tab.tpl`, `alternatives.tpl`, `test_alternative_rs.tpl`
Schema: `addon.xml`
Documentation: `README.md`, `CHANGELOG.md`

---

## A90 — Code Smell Refactor: DI Consistency, Deduplication, Error Suppression Cleanup

### Dependency Injection (Container / ServiceLoader)

- **CHANGED:** Replaced 23 direct `new ClassName()` instantiations across controllers and functions with Container/ServiceLoader calls
- **ADDED:** `Container::novotonApi()` — singleton NovotonApi instance
- **ADDED:** `Container::adminCronService()` — AdminCronService with injected API
- **ADDED:** `Container::propertyTypeDetector()` — PropertyTypeDetector singleton
- **ADDED:** `_nvt_api()` ServiceLoader helper for procedural code
- **ADDED:** `_nvt_admin_cron_service()` ServiceLoader helper
- **ADDED:** `_nvt_property_type_detector()` ServiceLoader helper
- **ADDED:** `_nvt_alternative_request_repo()` ServiceLoader helper
- **CHANGED:** `BookingSubmissionService` now receives `NovotonApi` via Container (`$this->novotonApi()`) instead of `new NovotonApi()`
- **CHANGED:** `NovotonApi` constructor uses `Container::getInstance()->cacheService()` instead of `new CacheService('file')`

### Duplicated Logic

- **ADDED:** `fn_novoton_match_price_from_xml()` helper in `functions/helpers.php` — shared XML price matching for flat room_price responses
- **FIXED:** Inconsistent room matching between `ajax_recalculate_price.php` (`strcasecmp`) and `novoton_price_compare.php` (`===`) — both now use `strcasecmp` via shared helper
- **CHANGED:** Helper handles both `//IdBoard` and `//Board` xpath variants automatically

### Magic Strings → Constants

- **CHANGED:** Hardcoded `'novoton_holidays'` in DB queries replaced with `Constants::ADDON_ID` using parameterized `?s` placeholders (novoton_diagnostic, novoton_holidays controller, install.php)

### Error Suppression Cleanup

- **CHANGED:** Removed redundant `@` error suppression from `db_query()` calls inside `try-catch` blocks (`PriceInfoService::precomputeCalendarPrices()`)
- **CHANGED:** Removed redundant `@` from `mkdir()` / `file_put_contents()` inside `try-catch` blocks (product_hooks.php error logging)
- **CHANGED:** Removed `@` from `file_get_contents()` calls already guarded by `file_exists()` (StateManager load/restoreFromBackup)
- **CHANGED:** Replaced `@copy()` with `is_readable()` guard (StateManager save)
- **CHANGED:** Removed `@` from `mkdir()` already guarded by `!is_dir()` (DirectoryManager, BatchedHotelInfoSync, BatchedHotelFacilitiesSync, BatchedPriceInfoSync)
- **KEPT:** Intentional `@` suppression in StateManager atomic file ops (race conditions), install.php schema migrations, and PriceInfoService optional column checks

### Files Changed (27)

Controllers: `novoton_admin`, `novoton_alternatives`, `novoton_diagnostic`, `novoton_holidays`, `novoton_hotels`, `novoton_price_compare`, `ajax_recalculate_price`, `novoton_cron`
Functions: `helpers`, `bookings`, `formatting`, `install`
Hooks: `product_hooks`, `order_hooks`, `cart_hooks`
Services: `Container`, `ServiceLoader`, `PriceInfoService`, `DirectoryManager`
Core: `NovotonApi`, `HotelSync`, `AbstractBatchedSync`, `BatchedHotelInfoSync`, `BatchedHotelFacilitiesSync`, `BatchedPriceInfoSync`, `StateManager`
Entry: `cron.php`

---

## A89 — Facility-to-Feature Routing, Travel Group & Beach Access, Adults-Only Detection

### New Features

- **NEW:** Adults-only hotel detection via `AdultOnlyDetector` — scans hotel names for patterns like "Adults Only", "18+", "No Children"
- **NEW:** `is_adults_only` column on `novoton_hotels` table, populated during hotel list and hotel info sync
- **NEW:** Travel Group feature type (`travel_group`) — maps adults_only, pets (facility 3), families (facility 26), disabilities (facility 23)
- **NEW:** Beach Access feature type (`beach_access`) — maps beachfront/first line (facility 31) displayed as "Beachfront" / "La malul mării"
- **NEW:** Data-driven facility routing — hotel facility IDs are routed to their target feature type via `hotel_feature_mappings` table lookup, not hardcoded constants
- **NEW:** `findFeatureTypeForCode()` method on `FeatureMappingRepository` for routing lookup
- **NEW:** Property type assignment during product sync

### Admin UI Improvements

- **CHANGED:** Settings tab renamed from "Feature Mapping" to "Feature IDs Mapping"
- **CHANGED:** All 8 feature ID settings converted from input fields to selectbox dropdowns showing CS-Cart feature name + ID + type
- **NEW:** Variant Name column on Feature Mappings manage page — shows resolved CS-Cart variant name
- **CHANGED:** Resort names displayed as Title Case everywhere (normalizer, sync, seed)

### Seed Data

- **ADDED:** Travel Group seed entries: Adults only, Pets allowed, Suitable for families with children, Suitable for people with disabilities
- **ADDED:** Beach Access seed entry: Beachfront (facility 31)
- **CHANGED:** Hotel facility seed skips rerouted facility IDs (3, 23, 26, 31)
- **CHANGED:** Resort seed applies Title Case to display names

### Settings

- **ADDED:** `feature_id_travel_group` setting (default: 14)
- **ADDED:** `feature_id_beach_access` setting (default: 3)

---

## A88 — Codebase Audit + Storefront ID Fix

### Bug Fixes

- **FIXED:** "ID-ul magazinului este necesar (parametrul storefront_id)" error on frontend
  - Root cause: manual `fetch()` AJAX calls bypassed CS-Cart's `fn_url` URL builder, missing the required `storefront_id` parameter for multi-storefront setups
  - `booking_form.tpl` (both themes): replaced manual URL construction with `fn_url` Smarty function
  - `scripts.post.tpl` (both themes): added `NovotonConfig.ajaxRecalcUrl` — pre-built URL via `fn_url` exposed to JavaScript
  - `booking-form-validation.js`: uses `NovotonConfig.ajaxRecalcUrl` with fallback
  - `dob-validation.js`: uses `NovotonConfig.ajaxRecalcUrl` with fallback

### Code Quality (Codebase Audit)

- **REMOVED:** Empty leftover template files (`country_selector.tpl`, `update_prices_button.tpl`)
- **CHANGED:** Replaced hardcoded English strings in `novoton_bookings/manage.tpl` with `__()` language calls (cleanup confirm, orphan bookings button, incomplete bookings toggle)
- **CHANGED:** Replaced hardcoded strings in `settings/cron_info.tpl` with language variables (headings, descriptions, table headers)
- **ADDED:** 11 new translation keys to both EN and RO `.po` language files

---

## Version 3.2.0

Complete hotel booking integration with Novoton XML API.
Features React 19 booking engine with Booking.com-style interface.

### Features

- Novoton XML API integration (27 functions)
- Real-time hotel availability search
- Multi-room booking with guest assignment
- React 19 booking engine
- Early booking discounts
- Flexible date search
- Mobile responsive design
- Admin booking management
- API response caching
- Service-based architecture
- Security hardening
- Performance optimizations

---

## A86 — PHP Warning Fix + UI Polish

### Bug Fixes

- **FIXED:** PHP warnings corrupting AJAX JSON response (root cause fix)
  - `dob-validation.js`: replaced dirty URL (`Tygh.current_url.replace`) with clean baseUrl + dispatch-only URL construction
  - `booking-form-validation.js`: already had clean URL (no change)
  - Controller: scoped `set_error_handler()` replaces blanket `error_reporting(0)` — warnings logged, not suppressed
  - Controller top: sanitize `$_REQUEST`/`$_GET` arrays (`children_ages[]`, `ages[]`) to comma-separated strings before CS-Cart `__()` can choke
- **FIXED:** DOB event handler race condition with multi-room support
- **FIXED:** AJAX URL leaking booking page params (`children_ages[]`, etc.)

### UI / Frontend

- **CHANGED:** Removed dark backgrounds from search results page CSS
- **NEW:** Visual Editor integration for search button color
- **CHANGED:** Replaced hardcoded modal text and Early Booking labels with translation keys (NovotonTranslations)

---

## A71–A72 — Service Integration Cleanup

- **NEW:** Service getters (`_nvt_get_*_service`) for lazy-loaded singletons
- **NEW:** `_nvt_get_cached_hotel_info()` adds 30-min caching to hotel info
- **KEPT:** Validation helpers `_nvt_parse_and_validate_guests`, `_nvt_parse_dob`
- **KEPT:** XML utility `_nvt_get_xml_value`
- **REMOVED:** Duplicate helpers that overlap with existing services
- Use `SearchService::parseSearchParams()` directly
- Use `SearchService::calculateRoomTotals()` directly
- Use `BookingService::createBooking()` and `addToCart()` directly
- Use `RoomPriceService::getRoomPrice()` directly (has built-in caching)
- Use `CacheService::remember()` for caching patterns
- Code reduced by ~280 lines through proper service delegation

---

## A70 — Order Details Terms Display

- **NEW:** Display Terms of Payment in order details (frontend)
- **NEW:** Display Cancellation Terms in order details (frontend)
- **NEW:** PHP hook `fn_novoton_holidays_get_order_info` formats terms
- Terms displayed after `orders:extra_list` hook
- Proper formatting of XML terms into readable text

---

## A69 — Child Age Validation + Auto Price Update

- **CHANGED:** Child age >= 18 at check-in now BLOCKED (not just warning)
- **NEW:** Server-side validation rejects children >= 18 at check-in
- **FIXED:** Price recalculates only when DOB age differs from selected age
- Price unchanged if same bracket (e.g., child 5→7 in 2–11 bracket)
- Added translations for child age validation errors
- Form submission blocked until valid child DOB entered

---

## A68 — Server-Side DOB Validation

- **NEW:** Server-side DOB future date validation in controller
- **NEW:** `validateBirthday()` helper in GuestDataService
- Defense-in-depth: both client and server reject future DOB
- Logs invalid DOB attempts for security monitoring

---

## A67 — Desktop/Mobile + DOB Validation

- **FIXED:** Desktop showing both desktop AND mobile layouts
- Added stronger CSS media queries with `!important`
- Added `position:absolute left:-9999px` for mobile hiding
- **NEW:** `dob-validation.js` — client-side DOB future date prevention
- Added JS failsafe for desktop/mobile visibility on resize
- Added MutationObserver for dynamically added guest fields
- Added `dobCannotBeFuture` translation key

---

## A20 — Complete Optimization

### Phase 1–3: Architecture & Performance

- **NEW:** Service classes for code organization
  - `SearchService` — search/availability
  - `BookingService` — booking operations
  - `GuestDataService` — guest handling
  - `RoomPriceService` — real-time prices
  - `PriceInfoService` — season price lists
  - `CacheService` — API response caching
- **NEW:** API response caching (configurable TTL)
- **NEW:** Database indexes for common queries
- **FIXED:** Booking data sync to database

### Phase 4: Frontend Optimization

- **NEW:** `utils.js` — shared utility functions
- **NEW:** `lazy-loader.js` — on-demand component loading
- Reduced duplicate code across JS files
- Improved load performance

### Phase 5: Security

- **NEW:** SecurityService with input validation
- **NEW:** Rate limiting for API/booking requests
- **NEW:** CSRF token validation
- **NEW:** Data encryption helpers
- Sanitized all user inputs

### Phase 6: Code Quality

- **NEW:** `Constants.php` — centralized constants
- **NEW:** `LoggerTrait` — consistent PSR-3-like logging
- Removed magic strings
- Improved error handling
- Better code documentation
