# Sphinx Holidays Addon - Development Log

## Overview

The **Sphinx Holidays** addon integrates with the Sphinx/Christian Tour REST API to provide hotel and package booking capabilities within CS-Cart. It follows the same architectural patterns established by the Novoton Holidays addon, sharing common infrastructure through the `travel_core` addon.

**Provider**: Christian Tour (via Sphinx API)
**Dependency**: `travel_core` addon (shared booking infrastructure)
**CS-Cart compatibility**: 4.9.3 – 4.21.2
**PHP requirement**: 8.3+

---

## Architecture

### Addon Structure (33 files)

```
addon-sphinx-holidays/
├── app/addons/sphinx_holidays/
│   ├── addon.xml                          # Addon manifest, settings, DB schema, language vars
│   ├── init.php                           # Addon initialization (autoload PSR-4 registration)
│   ├── func.php                           # CS-Cart hook functions
│   ├── cron.php                           # Cron entry point
│   ├── controllers/
│   │   ├── backend/
│   │   │   └── sphinx_holidays.php        # Admin controller (dashboard, destinations, hotels, AJAX)
│   │   └── frontend/
│   │       ├── sphinx_booking.php         # Frontend mode dispatcher
│   │       └── sphinx_booking/
│   │           ├── search.php             # Hotel search with polling
│   │           ├── booking_form.php       # Offer verification + guest form
│   │           ├── add_to_cart.php         # Booking creation + cart
│   │           └── ajax_recalculate_price.php  # Price re-verification
│   ├── schemas/
│   │   ├── menu/actions.post.php          # Admin navigation tabs
│   │   └── permissions/admin.post.php     # Admin access control
│   └── src/
│       ├── SphinxApi.php                  # API facade (all endpoints)
│       ├── Api/
│       │   ├── SphinxHttpClient.php       # HTTP client (Bearer auth, retry, circuit breaker)
│       │   └── SphinxNormalizer.php        # Data normalization (property types, etc.)
│       ├── Repository/
│       │   ├── DestinationRepository.php   # CRUD for sphinx_destinations
│       │   └── HotelRepository.php         # CRUD for sphinx_hotels
│       ├── Services/
│       │   ├── Container.php              # DI container (lazy singletons)
│       │   ├── ConfigProvider.php         # Type-safe settings access
│       │   ├── DestinationSyncService.php # Paginated destination sync from API
│       │   └── HotelSyncService.php       # Country/destination-filtered hotel sync
│       └── Cron/
│           ├── CronDispatcher.php         # Mode → Command routing
│           └── Commands/
│               ├── DestinationSyncCommand.php  # CLI: sync destinations
│               └── HotelSyncCommand.php        # CLI: sync hotels
├── design/
│   ├── backend/templates/addons/sphinx_holidays/
│   │   ├── views/sphinx_holidays/
│   │   │   ├── manage.tpl                # Admin dashboard (stats, sync controls, logs)
│   │   │   ├── destinations.tpl          # Destination list with filters
│   │   │   └── hotels.tpl               # Hotel list with cascading dropdowns
│   │   └── hooks/index/
│   │       └── styles.post.tpl           # Backend CSS loading
│   └── themes/responsive/templates/addons/sphinx_holidays/
│       ├── blocks/
│       │   └── booking_engine.tpl        # React booking engine mount point
│       ├── views/sphinx_booking/
│       │   ├── search.tpl               # Search results (offer cards)
│       │   └── booking_form.tpl          # Guest entry form
│       └── hooks/index/
│           ├── scripts.post.tpl          # Frontend JS loading
│           └── styles.post.tpl           # Frontend CSS loading
```

### Database Tables

| Table | Purpose |
|-------|---------|
| `sphinx_destinations` | Cached 5-level destination hierarchy (continent → country → region → city → destination) |
| `sphinx_hotels` | Cached hotel static data with sync status tracking |
| `sphinx_bookings` | Provider-specific booking records (linked to travel_bookings) |
| `sphinx_package_routes` | Cached flight/bus routes for packages |
| `sphinx_sync_log` | Sync operation history with timing and error tracking |
| `sphinx_cache` | API response cache with TTL-based expiry |

### API Endpoints Covered

| Category | Endpoint | Method | Status |
|----------|----------|--------|--------|
| Connectivity | `/api/v1/ping` | GET | Implemented |
| Connectivity | `/api/v1/me` | GET | Implemented |
| Static Data | `/api/v1/static/destinations` | GET | Implemented + synced |
| Static Data | `/api/v1/static/hotels` | GET | Implemented + synced |
| Static Data | `/api/v1/static/package-routes` | GET | API method ready |
| Static Data | `/api/v1/static/circuits` | GET | API method ready |
| Static Data | `/api/v1/static/experiences` | GET | API method ready |
| Hotel Search | `/api/v1/hotels/search` | POST | API method ready |
| Hotel Search | `/api/v1/hotels/results` | GET | API method ready |
| Hotel Booking | `/api/v1/hotels/verify` | GET | API method ready |
| Hotel Booking | `/api/v1/hotels/book` | POST | API method ready |
| Package Search | `/api/v1/packages/search` | POST | API method ready |
| Package Search | `/api/v1/packages/results` | GET | API method ready |
| Package Booking | `/api/v1/packages/verify` | GET | API method ready |
| Package Booking | `/api/v1/packages/customize` | POST | API method ready |
| Package Booking | `/api/v1/packages/book` | POST | API method ready |
| Orders | `/api/v1/orders` | GET | API method ready |
| Orders | `/api/v1/orders/{id}` | GET | API method ready |
| Cache | `/api/v1/cache/hotels` | POST | API method ready |
| Cache | `/api/v1/cache/packages` | POST | API method ready |

---

## Development History

### Phase 0: Foundation & Architecture

**Commit**: `fe507cd` - Add 3-addon architecture

Established the three-addon architecture:
- **travel_core**: Shared booking infrastructure (cart hooks, display services, feature mapping, value objects)
- **novoton_holidays**: Novoton-specific booking provider
- **sphinx_holidays**: Sphinx/Christian Tour booking provider

**Commit**: `9863694` - Add 3 self-contained addon folders

Created standalone addon directory structure for independent deployment.

**Commit**: `db5e427` - Wire travel_core as shared layer

Connected travel_core as the shared layer with reconciled interfaces, extracted services, and centralized hooks.

### Phase 0.5: Shared Booking Engine

**Commit**: `2e2e86c` - Shared booking engine

Moved provider-agnostic components to travel_core:
- React booking engine (Calendar, GuestPicker, BookingEngine components)
- Shared JS utilities (validation, DOB handling, multiroom)
- Shared CSS (booking engine styles, form styles)
- Provider-agnostic Smarty templates
- Sphinx backend controller scaffolding
- Frontend controller structure with mode dispatch pattern

### Phase 1: Destination Sync (P1.0)

**Commit**: `994071e` - Sphinx destinations sync + shared backend admin styles

Built the first data pipeline from the Sphinx API:

**What was implemented:**
- `DestinationSyncService` — Paginated fetch from `/api/v1/static/destinations`, normalize 5-level hierarchy, batch upsert
- `DestinationSyncCommand` — CLI cron command: `php cron.php access_key=KEY mode=destinations`
- `DestinationRepository` — Full CRUD: upsertBatch, getFiltered, getById, getChildren, getCountries, search, getCountsByType
- `CronDispatcher` — Mode-based command routing
- Admin dashboard (`manage.tpl`) — Stat cards for destination counts by type, sync button, sync log viewer
- Admin destinations list (`destinations.tpl`) — Filterable/searchable/paginated destination browser
- Admin navigation tabs via `actions.post.php` schema
- Shared admin CSS (`travel_core/admin_styles.css`) — Stat cards, status badges, sync log styling
- All language variables (EN/RO)

**Key design decisions:**
- Destinations have a 5-level hierarchy: continent → country → region → city → destination
- `parent_id` enables tree traversal for cascading filters
- API returns "resort" type which maps to "destination" in our schema
- Batch upsert (INSERT ... ON DUPLICATE KEY UPDATE) for idempotent syncs

### Phase 1.1: Hotel Data Sync (P1)

**Commit**: `d722c18` - Sphinx hotel data sync with country-based destination filtering

Built hotel sync pipeline with country-level targeting:

**What was implemented:**
- `HotelSyncService` — Per-country hotel sync: resolves destination IDs for selected countries, fetches all hotels paginated, filters by country, batch upserts, marks stale hotels inactive
- `HotelSyncCommand` — CLI: `php cron.php access_key=KEY mode=hotels [country=GR,BG]`
- `HotelRepository` — Full CRUD: upsertBatch, getFiltered (country + destination + status + search), getById, getByDestination, getDistinctCountries, markInactiveExcept
- `ConfigProvider::getSelectedCountryCodes()` — Parses comma-separated country codes from addon settings with fallback
- `SphinxNormalizer` — Property type normalization for hotel data
- Admin hotels list (`hotels.tpl`) — Country filter, status filter, search, pagination
- Updated dashboard with hotel stats (total, by country, last sync time)
- `selected_destinations` addon setting (textarea, default: "GR")
- Hotels tab in admin navigation
- All language variables (EN/RO)

**Key design decisions:**
- Hotels are synced per-country, not all at once (API returns 100k+ hotels globally)
- `selected_destinations` setting controls which countries to sync
- Hotels reference `destination_id` and `region_id` from the destination hierarchy
- `sync_status` ENUM (active/inactive/error) tracks hotel lifecycle
- Inactive marking happens after sync to detect API-removed hotels

### Phase 1.2: Resort/City Drill-Down Filtering (P1.1)

**Commit**: `4d764e3` - Resort/city drill-down filtering for hotels

Added granular filtering at region/city level for both admin browsing and sync targeting:

**What was implemented:**

1. **DestinationRepository** — Two new methods:
   - `getRegionsByCountry(string $countryCode)` — Gets regions (direct children of country destination)
   - `getCitiesByParent(int $parentId)` — Gets cities/resorts under a region

2. **Backend Controller AJAX** — Three new JSON modes:
   - `get_regions` — Returns regions for a country code (used by cascading dropdown)
   - `get_cities` — Returns cities under a region (used by cascading dropdown)
   - `get_destinations_tree` — Returns full nested tree for a country

3. **HotelRepository** — Added `$regionId` parameter to `getFiltered()` for region-level filtering

4. **Admin Hotels UI** (`hotels.tpl`) — Cascading Country → Region → City/Resort dropdowns:
   - Country change triggers AJAX load of regions
   - Region change triggers AJAX load of cities
   - State restoration on page reload (preserves selected filters)
   - Shows hotel count per region/city in dropdown labels
   - Uses modern `fetch()` API (same pattern as novoton_price_compare)

5. **ConfigProvider** — New `getSelectedSyncTargets()` method:
   - Supports mixed format: country codes AND numeric destination IDs
   - Example: `"GR,1234"` = all of Greece + specific destination 1234
   - Returns `{country_codes: string[], destination_ids: int[]}`
   - `getSelectedCountryCodes()` kept as convenience wrapper

6. **HotelSyncService** — Enhanced sync targeting:
   - `sync()` now accepts `$extraDestinationIds` parameter
   - `resolveDestinationIds()` handles specific destination IDs with automatic child resolution
   - Selecting a region automatically includes all its child cities/resorts

7. **HotelSyncCommand** — New `destination_ids` CLI parameter:
   - `php cron.php access_key=KEY mode=hotels destination_ids=1234,5678`
   - Can be combined with country parameter

8. **Dashboard** (`manage.tpl`) — Shows current sync targets with link to addon settings

9. **Language Variables** — 8 new EN/RO pairs:
   - `all_regions` / `all_cities` / `region` / `city_resort`
   - `sync_targets` / `change_settings` / `select_region` / `select_city`

### Phase 2: Destination Names + Frontend Cron (P2)

**Commit**: `66bf451` - Destination name support in sync targets + frontend cron endpoint

Two enhancements for improved usability and automation:

**Part A — Destination Name Resolution:**
- `DestinationRepository::findByExactName()` — Case-insensitive exact match with hierarchy ordering via `FIELD(type, ...)`
- `ConfigProvider::getSelectedSyncTargets()` — 3-way token classification: 2-letter alpha → country code, numeric → destination ID, anything else → name lookup
- `ConfigProvider::resolveNameTokens()` — Disambiguation strategy: prefer matches in already-selected countries, then prefer higher hierarchy (region > city > destination), log warnings for ambiguous matches
- Setting now accepts: `"GR,Crete,Rhodes"` → syncs all Greece + Crete region + Rhodes by name
- Updated setting description in addon.xml (EN/RO)

**Part B — Frontend Cron Controller:**
- `controllers/frontend/sphinx_cron.php` — URL-accessible cron endpoint for external services
- URL: `index.php?dispatch=sphinx_cron.run&access_key=KEY&mode=hotels`
- Auth: `hash_equals()` with stored cron access key (no admin login needed)
- Supports all modes: `destinations`, `hotels` with parameter pass-through
- Status check: `&status=1` returns last sync info without triggering a new sync
- Plain text output with timestamps, stats summary, error reporting
- Updated cron_access_key setting description to show URL format

**Key design decisions:**
- Name resolution happens at `getSelectedSyncTargets()` level so all callers (admin sync, CLI, frontend cron) benefit automatically
- Exact match only (not LIKE) prevents false positives on partial names
- 2-letter alpha tokens are always country codes — avoids treating "GR" as a destination name
- Frontend controller uses `exit;` to prevent CS-Cart HTML layout rendering
- No admin permissions needed — frontend controllers authenticate via access key only

---

## Admin Interface

### Dashboard (manage mode)
- API configuration status warning
- Sync buttons: Destinations + Hotels (with confirmation dialogs)
- Current sync targets display with settings link
- Destination stats: total, by type (continent/country/region/city)
- Hotel stats: total, by country (top 5)
- Last sync timestamps
- Browse links to Destinations and Hotels lists
- Recent sync log (last 10 entries with timing and errors)

### Destinations Tab (destinations mode)
- Filter by type (continent/country/region/city/destination)
- Filter by parent_id
- Text search by name
- Paginated results (50 per page)
- Columns: ID, name, type, parent_id, country_code, hotel_count, coordinates

### Hotels Tab (hotels mode)
- **Cascading dropdown filters**: Country → Region → City/Resort (AJAX-powered)
- Filter by sync status (active/inactive/error)
- Text search by name
- Paginated results (50 per page)
- Columns: ID, name, stars, country, region, destination, type, status, last synced

---

## Cron Commands

### CLI (via cron.php)

```bash
# Sync all destinations from API
php cron.php access_key=KEY mode=destinations

# Sync hotels for configured countries (default: GR)
php cron.php access_key=KEY mode=hotels

# Sync hotels for specific countries
php cron.php access_key=KEY mode=hotels country=GR,BG

# Sync hotels for specific destinations (region/city IDs)
php cron.php access_key=KEY mode=hotels destination_ids=1234,5678
```

### Frontend URL (via CS-Cart dispatcher)

```bash
# Sync destinations (for external cron services like cron-job.org)
curl "https://domain.com/index.php?dispatch=sphinx_cron.run&access_key=KEY&mode=destinations"

# Sync hotels
curl "https://domain.com/index.php?dispatch=sphinx_cron.run&access_key=KEY&mode=hotels"

# Sync hotels for specific countries
curl "https://domain.com/index.php?dispatch=sphinx_cron.run&access_key=KEY&mode=hotels&country=GR,BG"

# Check last sync status without triggering a new sync
curl "https://domain.com/index.php?dispatch=sphinx_cron.run&access_key=KEY&mode=hotels&status=1"
```

---

## Addon Settings

| Setting | Type | Default | Description |
|---------|------|---------|-------------|
| `api_base_url` | input | (Sphinx API URL) | API base URL |
| `api_key` | password | — | Bearer token (format: `digits\|alphanumeric`) |
| `enable_api_cache` | checkbox | Y | Enable API response caching |
| `cache_ttl_search` | input | 300 | Search cache TTL in seconds |
| `default_currency` | selectbox | EUR | Default search currency |
| `ignore_domains` | input | — | Supplier IDs to skip (comma-separated) |
| `search_poll_interval` | input | 2 | Search polling interval (seconds) |
| `search_max_polls` | input | 30 | Max polls before timeout |
| `commission` | input | 0 | Commission percentage |
| `hotels_category_id` | input | 0 | CS-Cart category for hotels |
| `packages_category_id` | input | 0 | CS-Cart category for packages |
| `api_max_retries` | input | 3 | API failure retry count |
| `api_retry_delay_ms` | input | 500 | Initial retry delay (ms) |
| `api_retry_multiplier` | input | 2 | Exponential backoff multiplier |
| `circuit_breaker_threshold` | input | 5 | Failures before circuit opens |
| `circuit_breaker_timeout` | input | 60 | Circuit breaker timeout (seconds) |
| `selected_destinations` | textarea | GR | Sync targets (country codes and/or destination IDs, comma-separated) |
| `cron_access_key` | password | — | Cron access key |
| `debug_logging` | checkbox | N | Enable debug logging |

---

## What's Next (Planned)

### Immediate Priorities
- **Package route sync** — Sync flight/bus routes from `/api/v1/static/package-routes` (table exists, API method ready)
- **Hotel search integration** — Connect `searchHotels()` → polling → result display with the shared React booking engine
- **Booking flow** — Wire verify → booking_form → add_to_cart → book flow (frontend controllers scaffolded)

### Future
- Circuit/Experience sync from static endpoints
- Package search + booking flow
- Order status tracking via `/api/v1/orders`
- Pre-caching via `/api/v1/cache/hotels` and `/api/v1/cache/packages`
- Shared components migration (move booking engine JS/CSS/templates to travel_core for both providers)

---

## Cross-Provider Order Placement Fixes (2026-03-16)

### Problem: Sphinx blocks mixed-provider orders

When a cart contained both Novoton and Sphinx hotels, and a Sphinx offer became unavailable at checkout, `PreOrderPriceVerifier` set `$allow = false` which blocked the **entire** order — including valid Novoton bookings.

### Fixes Applied

**1. `PreOrderPriceVerifier.php` — graceful removal instead of blocking**
- Returns `unavailable[]` array instead of setting `$allow = false`
- Caller (`fn_sphinx_holidays_pre_place_order`) removes unavailable items from cart with customer notification
- Order only blocked if ALL remaining cart items become unavailable

**2. `func.php` — `fn_sphinx_holidays_pre_place_order` rewritten**
- Iterates `$result['unavailable']`, calls `unset($cart['products'][$cartId])` for each
- Shows `fn_set_notification('W', ...)` per removed item with hotel name
- Falls through to `$allow = false` only when `empty($cart['products'])`

**3. `func.php` — `fn_sphinx_holidays_place_order_post` status flow fixed**
- **Before:** `linkToOrder()` set `STATUS_CONFIRMED` before API call → API failure left booking as "confirmed"
- **After:** Sets `STATUS_PENDING` → API call → `STATUS_CONFIRMED` on success, `STATUS_FAILED` on error
- Failed bookings now properly recorded in both `sphinx_bookings` and `travel_bookings` (via dual-write)

**4. `func.php` — new `fn_sphinx_holidays_get_order_info()` hook**
- When `AREA === 'A'` (admin panel), queries `sphinx_bookings` for the order
- If any booking has `status = 'failed'`, shows orange warning notification via `fn_set_notification('W', ...)`
- Notification shows hotel name and order ID

**5. Language variables added (`addon.xml`)**
- `sphinx_holidays.booking_api_failed` — admin warning for failed bookings
- `sphinx_holidays.offer_removed_from_order` — customer notification when offer removed from cart
- `sphinx_holidays.all_offers_unavailable` — customer notification when all offers unavailable

### Status Flow (after fix)

```
add_to_cart → pending
    │
place_order_post → linkToOrder(pending)
    │
    ├─ API success → confirmed
    │
    └─ API failure → failed  (admin sees orange warning on order view)
```

---

## Patterns & Conventions

### AJAX JSON Endpoints
```php
if ($mode === 'get_something') {
    header('Content-Type: application/json; charset=utf-8');
    // ... fetch data ...
    echo json_encode(['key' => $data]);
    exit;
}
```

### Client-side Fetch
```javascript
fetch('{"sphinx_holidays.get_regions"|fn_url:"A"}&country_code=' + cc)
    .then(function(r) { return r.json(); })
    .then(function(data) { /* populate UI */ });
```

### Repository Pattern
- One repository per table
- `upsertBatch()` for idempotent sync (INSERT ... ON DUPLICATE KEY UPDATE)
- `getFiltered()` with dynamic WHERE conditions
- `search()` with LIKE queries

### Sync Service Pattern
- Paginated API fetch → normalize → filter → batch upsert → mark stale
- Logging to `sphinx_sync_log` with start/complete/failed status
- Output callback for CLI progress display
- Returns stats array: `{success, total, synced, skipped, failed, duration_ms, error}`

### ConfigProvider
- Static methods with type-safe return types
- `getSetting()` with defaults via CS-Cart Registry
- Mixed target format: `"GR,1234"` → country codes + destination IDs
