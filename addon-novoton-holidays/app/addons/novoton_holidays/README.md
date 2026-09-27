# Novoton Holidays - CS-Cart Addon

**Version:** 4.1.0
**Last Updated:** February 20, 2026
**Compatibility:** CS-Cart 4.9.3 - 4.19.1 (ULTIMATE edition)
**PHP:** 7.4 - 8.4
**Developer:** VacanteLitoral.ro

Complete hotel booking integration with Novoton XML API for CS-Cart.

## Table of Contents

- [Features](#features)
- [Installation](#installation)
- [Configuration](#configuration)
- [Admin Panel Pages](#admin-panel-pages)
- [Cron Jobs](#cron-jobs)
- [Exchange Rates](#exchange-rates)
- [API Functions](#api-functions)
- [Database Schema](#database-schema)
- [Architecture](#architecture)
- [Frontend JavaScript](#frontend-javascript)
- [React Booking Engine](#react-booking-engine)
- [Availability Search Features](#availability-search-features)
- [Troubleshooting](#troubleshooting)
- [Changelog](#changelog)

---

## Features

### Core Functionality
- Novoton XML API integration (27 functions)
- Real-time hotel availability search
- Multi-room booking with guest assignment
- Children age validation (smart price recalculation based on check-in date)
- Early booking discounts
- Flexible date search (+/- days option)
- Room quota display with availability indicators
- Hotel season period display (from priceinfo data)
- Nearby date availability fallback via `hotel_quota_add` API (rooms showing "On request")
- Alternative dates search when no results found (±10 days automatic scan)

### User Interface
- React 19 booking engine with Booking.com-style design
- Two-month calendar with range selection
- Multi-room guest picker with age selectors
- Mobile responsive design (yellow border styling)
- Bilingual support (English/Romanian auto-detection)

### Search Results Display
- Room Type with package name, MoreInfo, and Important warnings
- Board name formatting (Ultra All Inclusive, All Inclusive, etc.)
- Choices column with free cancellation date
- Payment terms with calculated amounts (e.g., "10% (150.00€) - due by 05.03.2026")
- Cancellation terms display
- Price per person and total price columns

### Feature Mapping System
- Provider-agnostic 4-layer pipeline: API raw values → normalization → mapping table → CS-Cart product features
- 8 feature types: star rating, board, hotel facility, room facility, resort, property type, travel group, beach access
- Data-driven facility routing via `hotel_feature_mappings` table (not hardcoded)
- Adults-only hotel detection from hotel names (regex patterns)
- Title Case normalization for resort names
- Auto-creation of CS-Cart feature variants with 3-pass fuzzy name matching
- Admin UI for managing mappings with Variant Name display
- See [Feature_Mapping_System.md](../../Documentation/Feature_Mapping_System.md) for full documentation

### Admin Features
- Booking management with Novoton sync
- Hotel import wizard (admin + cron)
- Price comparison tool
- Sync logs and diagnostics dashboard
- Excluded resorts management
- API response caching
- Email notifications with CSV reports
- **Automatic exchange rate updates from BNR API**

### Technical
- Service-based architecture (14 service classes)
- Centralized constants
- Scoped error handling and logging
- CSRF protection
- URL-encoded API parameters
- API resilience (retry + circuit breaker)

---

## Installation

1. Upload addon files to your CS-Cart installation
2. Go to **Add-ons → Downloaded add-ons**
3. Find "Novoton Holidays" and click **Install**
4. Configure API credentials in addon settings

### Required Settings

| Setting | Description |
|---------|-------------|
| API URL | Novoton API endpoint |
| API Key | Novoton API key |
| API ID | Novoton API identifier |
| API Username | Novoton API username |
| API Password | Novoton API password |
| Commission % | Markup percentage on prices |
| Currency Risk Commission % | Exchange rate markup (0-5%, default 1.8%) |

---

## Configuration

### Addon Settings Location

**Admin Panel → Add-ons → Manage add-ons → Novoton Holidays → Settings**

### Settings Sections

1. **Novoton API** - API credentials (URL, key, ID, username, password), API cache toggle
2. **Pricing** - Commission percentage, round prices toggle
3. **Exchange Rates** - Currency risk commission percentage, last update timestamp
4. **API Resilience** - Max retries, retry delay, retry multiplier, circuit breaker threshold/timeout
5. **Products** - Selected countries, product code prefix, delete on uninstall, excluded resorts
6. **Feature IDs Mapping** - CS-Cart feature ID selectbox dropdowns for: Star Rating, Board, Hotel Facility, Room Facility, Resort, Property Type, Travel Group, Beach Access
7. **Cron** - cron report email, cron links info (the cron **key** itself is the shared one in Travel Core settings)
8. **Display** - Show booking form toggle, booking form position (before tabs / after description / sidebar)
9. **Other** - Last sync date, test booking mode, disable API submission, debug logging, debug mode

---

## Admin Panel Pages

### Main Menu: Novoton Holidays

| Page | URL | Description |
|------|-----|-------------|
| Dashboard / Hotels Sync | `novoton_holidays.manage` | Overview, stats, sync, and quick actions |
| Hotel Bookings | `novoton_bookings.manage` | Manage customer bookings |
| Alternative Requests | `novoton_alternatives.manage` | Alternative room requests |
| Room Price Check | `novoton_holidays.room_price` | Check hotel prices |
| Add Hotels as Products | `novoton_holidays.add_hotels_as_products` | Import hotels to CS-Cart |
| Facilities | `novoton_holidays.list_facilities` | Hotel facilities list |
| Feature Mappings | `novoton_feature_mappings.manage` | Manage feature type mappings (with variant names) |
| **Exchange Rates** | `novoton_exchange_rates.manage` | Currency rate management |
| Diagnostic | `novoton_diagnostic.test` | API connectivity test |
| Test Hotel Request | `novoton_holidays.test_hotel_request` | Debug hotel API calls |
| Test Alternative RS | `novoton_holidays.test_alternative_rs` | Debug alternative search |

### Dashboard Features

The dashboard (`novoton_holidays.manage`) displays:
- **Hotels Statistics** - Total, with prices, with packages, as products
- **Bookings Overview** - Pending, confirmed, cancelled counts
- **Last Sync Times** - Hotel List, Hotel Info, Prices, Offers Update, Facilities
- **Quick Actions** - Check Prices, Check Packages, Add Hotels, Manage Bookings
- **Recent Sync Activity** - Last 10 sync operations with details

#### Dashboard Statistics Explained

| Statistic | DB Condition | Populated By |
|-----------|-------------|-------------|
| **Real-time (room_price) available** | `has_room_price = 'Y' AND last_price_check IS NOT NULL` | Check Prices (resort-based or per-hotel), `room_price` cron mode **only** |
| **Season prices (priceinfo) available** | `packages_count > 0` | Hotel Info Sync (`hotel_info_batched`), PriceInfo Sync (`sync_priceinfo_batched`) |
| **As Products** | Hotels linked to CS-Cart products | Add Hotels as Products action |

> **Note (v3.2.0):** The "Real-time (room_price) available" counter is populated **exclusively** by the room_price check process — either the "Check Prices" dashboard actions (resort-based or per-hotel) or the `room_price` cron mode. Hotel Info Sync and PriceInfo Sync do **not** set `has_room_price`; they only update `packages_count` for the "Season prices" counter.

> **Note (v3.2.0):** Both price check methods — **Check Prices (Resort-based)** and **Check Prices (Per-Hotel)** — currently return the same number of hotels with prices. The resort-based method queries by resort/destination in bulk, while the per-hotel method queries each hotel individually by `hotel_id`. The per-hotel method was designed to catch hotels with missing or mismatched city names, but at present no such discrepancies exist in the dataset.

---

## Cron Jobs

### Authentication

All cron URLs require the `access_key` parameter matching the shared **cron key** in
Settings → Travel Core (Travel Core → Tools can generate one). It is the same key for
every travel addon, so rotating it means re-copying the Novoton, Sphinx, Eurosite and
Travel Core commands.

### Available Cron Modes

| Mode | Sync Type | Description |
|------|-----------|-------------|
| `hotel_info_batched` | `hotelinfo` | **[RECOMMENDED]** Smart batched hotel sync with resume |
| `sync_priceinfo_batched` | `priceinfo` | **[RECOMMENDED]** Smart batched priceinfo sync with resume |
| `hotel_list` | `hotellist` | Sync hotel list from API |
| `list_facilities` | `facilities` | Update hotel facilities list |
| `exchange_rates` | `exchange_rates` | Update BNR currency rates (daily) |
| `resinfo` | `resinfo` | Check ASK bookings status (default mode) |
| `offers_update` | `offers_update` | Sync only changed offers (delta sync) |
| `add_hotels_as_products` | - | Import hotels as CS-Cart products |
| `room_price` | `prices` | Check which hotels have active prices |
| `resort_list` | - | Sync resort/destination list from API |
| `alternative_rs` | - | Check for alternative offers |
| `alternative_rs_bookings` | - | Check alternatives for pending bookings |
| `notify_alternatives` | - | Send alternative notifications to customers |
| `expire_requests` | - | Expire old alternative requests |
| `update_prices` | - | Update cached prices |

### Cron URL Examples

#### 1. Hotel List Sync
Syncs basic hotel information from Novoton API.

```bash
curl "https://yoursite.com/index.php?dispatch=novoton_cron.run&access_key=YOUR_KEY&mode=hotel_list"
```

#### 2. Hotel Info Batched Sync (Recommended)
Smart sync with resume capability. First run syncs all hotels, then daily syncs only new/changed hotels.

```bash
# Auto-detect sync type (recommended)
curl "https://yoursite.com/index.php?dispatch=novoton_cron.run&access_key=YOUR_KEY&mode=hotel_info_batched"

# Check progress
curl "https://yoursite.com/index.php?dispatch=novoton_cron.run&access_key=YOUR_KEY&mode=hotel_info_batched&status=1"

# Force full sync (all hotels)
curl "https://yoursite.com/index.php?dispatch=novoton_cron.run&access_key=YOUR_KEY&mode=hotel_info_batched&force_full=1"

# Shared hosting settings (smaller batches, shorter timeout)
curl "https://yoursite.com/index.php?dispatch=novoton_cron.run&access_key=YOUR_KEY&mode=hotel_info_batched&batch_size=50&max_time=120"

# CLI PHP with unlimited mode (no time limit - processes all in one run)
php index.php dispatch=novoton_cron.run access_key=YOUR_KEY mode=hotel_info_batched unlimited=1 force_full=1
```

**Parameters:**
- `&status=1` - Check progress without processing
- `&force_full=1` - Force full sync of all hotels
- `&reset=1` - Cancel/reset in-progress sync
- `&batch_size=N` - Hotels per batch (default: 100)
- `&max_time=N` - Max seconds per run (default: 300)
- `&unlimited=1` - No time limit (for CLI PHP usage)

#### 3. Price Info Batched Sync (Recommended)
Smart priceinfo sync with resume capability. Full sync every 7 days, incremental for stale packages (>24h).

```bash
# Auto-detect sync type (recommended)
curl "https://yoursite.com/index.php?dispatch=novoton_cron.run&access_key=YOUR_KEY&mode=sync_priceinfo_batched"

# Check progress
curl "https://yoursite.com/index.php?dispatch=novoton_cron.run&access_key=YOUR_KEY&mode=sync_priceinfo_batched&status=1"

# Force full sync (all packages)
curl "https://yoursite.com/index.php?dispatch=novoton_cron.run&access_key=YOUR_KEY&mode=sync_priceinfo_batched&force_full=1"

# Shared hosting settings
curl "https://yoursite.com/index.php?dispatch=novoton_cron.run&access_key=YOUR_KEY&mode=sync_priceinfo_batched&batch_size=30&max_time=120"

# CLI PHP with unlimited mode (no time limit - processes all in one run)
php index.php dispatch=novoton_cron.run access_key=YOUR_KEY mode=sync_priceinfo_batched unlimited=1 force_full=1
```

**Parameters:**
- `&status=1` - Check progress without processing
- `&force_full=1` - Force full sync of all packages
- `&reset=1` - Cancel/reset in-progress sync
- `&batch_size=N` - Packages per batch (default: 50)
- `&max_time=N` - Max seconds per run (default: 300)
- `&stale_hours=N` - Re-sync packages older than N hours (default: 24)
- `&unlimited=1` - No time limit (for CLI PHP usage)

#### 4. Price Check
Checks which hotels have active prices.

```bash
# All countries
curl "https://yoursite.com/index.php?dispatch=novoton_cron.run&access_key=YOUR_KEY&mode=room_price"

# Specific country
curl "https://yoursite.com/index.php?dispatch=novoton_cron.run&access_key=YOUR_KEY&mode=room_price&country=BULGARIA"
```

#### 5. Offers Update (Delta Sync)
Syncs only changed offers since last update.

```bash
curl "https://yoursite.com/index.php?dispatch=novoton_cron.run&access_key=YOUR_KEY&mode=offers_update"
```

#### 6. Facilities Sync
Updates hotel facilities list.

```bash
curl "https://yoursite.com/index.php?dispatch=novoton_cron.run&access_key=YOUR_KEY&mode=list_facilities"
```

#### 7. Add Hotels as Products
Imports hotels as CS-Cart products (no limit by default).

```bash
# All hotels for Bulgaria
curl "https://yoursite.com/index.php?dispatch=novoton_cron.run&access_key=YOUR_KEY&mode=add_hotels_as_products&country=BULGARIA"

# With limit
curl "https://yoursite.com/index.php?dispatch=novoton_cron.run&access_key=YOUR_KEY&mode=add_hotels_as_products&country=GREECE&limit=50"
```

### Recommended Crontab

```crontab
# Hotel Info Batched - every 5 minutes (self-manages sync type)
*/5 * * * * curl -s "https://yoursite.com/index.php?dispatch=novoton_cron.run&access_key=YOUR_KEY&mode=hotel_info_batched&batch_size=50&max_time=120" > /dev/null 2>&1

# Price Info Batched - every 5 minutes (self-manages sync type)
*/5 * * * * curl -s "https://yoursite.com/index.php?dispatch=novoton_cron.run&access_key=YOUR_KEY&mode=sync_priceinfo_batched&batch_size=30&max_time=120" > /dev/null 2>&1

# Hotel list sync - daily at 2 AM
0 2 * * * curl -s "https://yoursite.com/index.php?dispatch=novoton_cron.run&access_key=YOUR_KEY&mode=hotel_list" > /dev/null 2>&1

# Booking status check - every 2 hours
0 */2 * * * curl -s "https://yoursite.com/index.php?dispatch=novoton_cron.run&access_key=YOUR_KEY&mode=resinfo" > /dev/null 2>&1

# Facilities sync - weekly on Sunday at 4 AM
0 4 * * 0 curl -s "https://yoursite.com/index.php?dispatch=novoton_cron.run&access_key=YOUR_KEY&mode=list_facilities" > /dev/null 2>&1

# Exchange rates - daily at 13:05 (after BNR publishes)
5 13 * * * curl -s "https://yoursite.com/index.php?dispatch=novoton_exchange_rates.cron&cron_password=YOUR_KEY" > /dev/null 2>&1
```

### CLI PHP Usage (Unlimited Mode)

For running full syncs from command line without time limits:

```bash
cd /path/to/cscart

# Full hotel info sync (no time limit)
php index.php dispatch=novoton_cron.run access_key=YOUR_KEY mode=hotel_info_batched unlimited=1 force_full=1

# Full priceinfo sync (no time limit)
php index.php dispatch=novoton_cron.run access_key=YOUR_KEY mode=sync_priceinfo_batched unlimited=1 force_full=1
```

Benefits of CLI with `unlimited=1`:
- No time limits - processes ALL items in one run
- No HTTP timeout issues
- Better for initial setup or full re-syncs
- Memory-efficient (still uses batched processing internally)

---

## Exchange Rates

The addon includes automatic exchange rate updates from BNR (National Bank of Romania) API.

### Features

- Fetches EUR, USD, GBP rates from BNR XML API
- Applies configurable "Currency Risk Commission" (0-5%, default 1.8%)
- Updates CS-Cart currency coefficients automatically
- EUR is the primary currency (coefficient = 1)
- RON, USD, GBP coefficients are calculated relative to EUR

### Admin Page

**Admin → Novoton Holidays → Exchange Rates** (`novoton_exchange_rates.manage`)

Shows:
- Current currency coefficients
- Last update timestamp
- Commission percentage
- Manual update button
- Cron URLs for automation

### Cron URLs

```bash
# Frontend cron (requires cron_password)
curl "https://yoursite.com/index.php?dispatch=novoton_exchange_rates.cron&cron_password=YOUR_KEY"

# Admin cron (requires admin login)
# Access via browser when logged in as admin
```

### How It Works

1. Fetches XML from `https://www.bnr.ro/nbrfxrates.xml`
2. Parses EUR, USD, GBP rates (in RON)
3. Calculates coefficients relative to EUR
4. Applies commission percentage
5. Updates `cscart_currencies` table via `fn_update_currency()`

---

## API Functions

The `NovotonApi` class (`src/NovotonApi.php`) provides these methods:

### Hotel Information

| Method | Description |
|--------|-------------|
| `getHotelList($country, $city, $hotel, $hotelType)` | Get list of hotels |
| `getHotelInfo($hotelId, $lang)` | Get hotel details |
| `getHotelInfoBatch($hotelIds, $lang, $concurrency)` | Batch fetch multiple hotels (curl_multi) |
| `getHotelDescription($hotelId, $lang)` | Get hotel description |
| `getHotelImages($hotelId, $lang)` | Get hotel images |

### Availability & Pricing

| Method | Description |
|--------|-------------|
| `searchAvailability($params)` | Search available rooms |
| `getRoomPrice($params)` | Get room price with terms |
| `getRoomPriceByResort($params)` | Get room prices for entire resort |
| `getRoomPriceByResortRaw($params)` | Resort prices - raw response |
| `getHotelQuota($hotelId, $roomId, $checkIn, $checkOut, $roomType)` | Get room availability |
| `getHotelQuotaAll($hotelId, $checkIn, $checkOut)` | Get all rooms availability |
| `getHotelQuotaAdditional($hotelId, $roomId, $checkIn, $checkOut)` | Get additional allotments |
| `getHotelQuotaAddAll($hotelId, $checkIn, $checkOut)` | Get nearby availability for all rooms (±5 days) |
| `getPriceInfo($hotelId, $packageName)` | Get price info for package |
| `getSpecialOffers($hotelId, $packageName, $lang)` | Get EB discounts/extras |

### Booking & Reservations

| Method | Description |
|--------|-------------|
| `createReservation($bookingData)` | Create a booking |
| `createHotelRequest($requestData, $lang, $returnXml)` | Request alternatives when unavailable |
| `generateHotelRequestXml($requestData)` | Generate request XML (preview/testing) |
| `getAlternatives($idNum, $lang)` | Check for available alternatives |
| `getReservationInfo($idNum, $confirmAgency, $lang)` | Get reservation info |
| `listInvoices($arrFrom, $arrTo, $lang)` | List invoices |
| `getInvoiceHtml($idNum, $lang)` | Get invoice as HTML |
| `getInvoiceXml($idNum, $lang)` | Get invoice as XML |

### Destinations & Facilities

| Method | Description |
|--------|-------------|
| `getResortList($country, $lang)` | Get resort/destination list |
| `listFacilities()` | List all facilities |
| `getHotelFacilities($hotelId)` | Get hotel facilities |
| `getOffersUpdate($dateTime, $country, $resort, $hotel)` | Get updated/new offers |
| `getKickbackInfo($lang)` | Check commission info |

### Utility

| Method | Description |
|--------|-------------|
| `applyCommission($price)` | Add commission to price |
| `clearCache($function)` | Clear API cache |
| `getLastRequest()` | Debug: last API request |
| `getLastRequestFormatted()` | Debug: last request formatted |
| `getLastResponse()` | Debug: last API response |
| `getLastResponseRaw()` | Debug: raw response before XML cleaning |
| `getLastError()` | Debug: last error message |
| `getCircuitStatus()` | Get circuit breaker status |
| `resetCircuitBreaker()` | Manually reset circuit breaker |

### API Resilience Features

The API client includes built-in resilience patterns:

#### Retry with Exponential Backoff
- **Max Retries:** 3 attempts (configurable via addon settings)
- **Initial Delay:** 1 second (configurable)
- **Backoff Multiplier:** 2x (configurable) — delays: 1s, 2s, 4s
- **Retryable Errors:** Network timeouts, connection refused, 5xx errors, 429 rate limiting

#### Circuit Breaker Pattern
- **Threshold:** 5 consecutive failures opens circuit (configurable)
- **Timeout:** 60 seconds before half-open retry (configurable)
- **Auto-Recovery:** Successful request resets failure counter
- Monitor status: `$api->getCircuitStatus()`

### Webhook Callbacks (Not Supported)

The Novoton API does **not** support webhook callbacks. Based on analysis of all 27 API functions:

1. **No callback URL parameters** in any API request
2. **Polling-based architecture** - use `alternative_RS` to check for alternatives
3. **Manual status checks** - use `resinfo` to check booking status

**Recommended Polling Strategy:**
- Check `resinfo` for booking status changes every 30 minutes via cron
- Check `alternative_RS` for alternative offers every 6 hours
- Check `offers_update` for price changes daily

If Novoton adds webhook support in the future, contact them directly for documentation.

---

## Database Schema

### Main Tables

#### `cscart_novoton_hotels`
Stores synced hotel information.

| Column | Type | Description |
|--------|------|-------------|
| hotel_id | varchar(50) | Novoton hotel ID (PK) |
| product_id | int | Linked CS-Cart product ID |
| hotel_name | varchar(255) | Hotel name |
| city | varchar(100) | City/Resort |
| region | varchar(100) | Region |
| country | varchar(100) | Country |
| hotel_type | varchar(50) | e.g. 4*, 3* Sup, Apart (raw from API) |
| star_rating | tinyint | Parsed numeric rating 1-5 |
| latitude | decimal(10,8) | Hotel latitude |
| longitude | decimal(11,8) | Hotel longitude |
| hotel_data | longtext | JSON: full hotelinfo API response |
| has_room_price | enum('Y','N') | Has room_price check results |
| packages_count | int | Number of packages |
| hotelinfo_synced_at | datetime | Last hotelinfo sync |
| hotel_list_synced_at | datetime | Last hotel_list API sync |
| last_price_check | datetime | Last room_price_check result |
| created_at | datetime | Created timestamp |
| updated_at | timestamp | Updated timestamp |

#### `cscart_novoton_hotel_packages`
Stores hotel packages with priceinfo data (V3 architecture).

| Column | Type | Description |
|--------|------|-------------|
| id | int | Auto-increment PK |
| hotel_id | varchar(50) | Hotel ID (FK) |
| package_id | varchar(50) | Package ID (IdCont from API) |
| package_name | varchar(255) | Package name |
| priceinfo_data | longtext | JSON: full priceinfo API response |
| seasons_count | int | Number of seasons in priceinfo |
| has_early_booking | enum('Y','N') | Has EB discounts |
| min_price | decimal(10,2) | Lowest adult price |
| currency | varchar(3) | Currency code (default EUR) |
| synced_at | datetime | Last priceinfo sync |
| created_at | datetime | Created timestamp |
| updated_at | timestamp | Updated timestamp |

#### `cscart_novoton_bookings`
Stores customer bookings.

| Column | Type | Description |
|--------|------|-------------|
| booking_id | int | Auto-increment PK |
| order_id | int | CS-Cart order ID |
| product_id | int | CS-Cart product ID |
| user_id | int | Customer user ID |
| session_id | varchar(64) | Session ID for guest bookings |
| novoton_confirm_id | varchar(50) | Confirmation from Novoton API |
| novoton_invoice_id | varchar(50) | IdNum from API |
| novoton_res_num | varchar(50) | ResNum from resinfo |
| novoton_status | varchar(20) | Status: Good, ASK, ST, WT, RQ (API 'OK' normalized to 'Good') |
| hotel_id | varchar(50) | Novoton hotel ID |
| hotel_name | varchar(255) | Hotel name |
| package_id | varchar(50) | Package ID |
| package_name | varchar(255) | Package name |
| room_id | varchar(100) | Room type code |
| room_type | varchar(255) | Room type name |
| board_id | varchar(50) | Board type code |
| board_name | varchar(100) | Board type display name |
| item_id | varchar(32) | CS-Cart order item ID |
| check_in | date | Check-in date |
| check_out | date | Check-out date |
| nights | int | Number of nights |
| adults | int | Number of adults |
| children | int | Number of children |
| children_ages | varchar(100) | Comma-separated ages |
| num_rooms | int | Number of rooms booked |
| rooms_data | longtext | JSON: rooms configuration |
| room_number | int | Which room in multi-room booking |
| total_rooms | int | Total rooms in original booking |
| guest_name | varchar(255) | Guest name |
| guest_email | varchar(255) | Contact email |
| guest_phone | varchar(50) | Contact phone |
| guests_data | longtext | JSON: all guests details |
| holder_name | varchar(255) | Main holder name |
| base_price | decimal(12,2) | Base price |
| extras_price | decimal(12,2) | Extras price |
| total_price | decimal(12,2) | Total price |
| api_price | decimal(12,2) | Price returned from API |
| currency | varchar(3) | Currency (default EUR) |
| status | enum | pending, confirmed, cancelled, completed, failed, ask, waiting |
| api_request | longtext | JSON: API request sent |
| api_response | longtext | JSON: API response received |
| alternatives_data | longtext | JSON: alternative hotels |
| alternatives_requested | tinyint | Whether alternatives were requested |
| last_status_check | timestamp | Last time status was polled |
| notes | text | Notes |
| special_requests | text | Special requests |
| created_at | timestamp | Created timestamp |
| updated_at | timestamp | Updated timestamp |

#### `cscart_novoton_alternative_requests`
Stores alternative booking requests.

| Column | Type | Description |
|--------|------|-------------|
| request_id | int | Auto-increment PK |
| user_id | int | CS-Cart user ID |
| hotel_id | varchar(50) | Hotel ID |
| hotel_name | varchar(255) | Hotel name |
| check_in | date | Check-in date |
| check_out | date | Check-out date |
| nights | int | Number of nights |
| num_rooms | int | Number of rooms |
| adults | int | Adults count |
| children | int | Children count |
| children_ages | varchar(100) | Children ages |
| room_id | varchar(50) | Room ID |
| board_id | varchar(50) | Board ID |
| contact_name | varchar(255) | Contact name |
| contact_email | varchar(255) | Contact email |
| contact_phone | varchar(50) | Contact phone |
| notes | text | Notes |
| status | enum | pending, pending_manual, alternatives_found, notified, booked, expired, cancelled |
| novoton_request_id | varchar(50) | IdNum from hotel_request |
| api_request_xml | text | XML sent to API |
| api_response | text | Raw API response |
| alternatives_data | longtext | JSON: found alternatives |
| notified_at | datetime | Notification timestamp |
| created_at | datetime | Created timestamp |
| updated_at | timestamp | Updated timestamp |

#### `cscart_novoton_facilities`
Stores facility definitions.

| Column | Type | Description |
|--------|------|-------------|
| facility_id | int | Facility ID (PK) |
| facility_name | varchar(255) | Default name |
| facility_name_en | varchar(255) | English name |
| facility_name_ro | varchar(255) | Romanian name |
| synced_at | datetime | Last sync |
| created_at | timestamp | Created timestamp |

#### `cscart_novoton_hotel_facilities`
Hotel-facility relationships (many-to-many).

| Column | Type | Description |
|--------|------|-------------|
| hotel_id | varchar(50) | Hotel ID (PK part 1) |
| facility_id | int | Facility ID (PK part 2) |

#### `cscart_novoton_cache`
Cached API responses.

| Column | Type | Description |
|--------|------|-------------|
| cache_key | varchar(255) | Cache key (PK) |
| cache_data | mediumtext | Cached data |
| expires_at | timestamp | Expiration time |
| created_at | timestamp | Created timestamp |

#### `cscart_novoton_sync_log`
Logs synchronization history.

| Column | Type | Description |
|--------|------|-------------|
| log_id | int | Auto-increment PK |
| sync_type | varchar(50) | Type: hotellist, hotelinfo, priceinfo, prices, offers_update, facilities, exchange_rates, resinfo |
| sync_date | timestamp | Sync timestamp |
| products_total | int | Total items processed |
| products_updated | int | Items updated |
| products_failed | int | Items failed |
| products_no_data | int | Items with no data |
| products_missing | int | Items missing |
| duration_seconds | int | Duration in seconds |
| log_file | varchar(255) | Log file path |
| error_message | text | Error message |
| notes | text | Extra JSON data for sync details |
| status | enum | running, completed, failed |

---

## Architecture

### Directory Structure

```
app/addons/novoton_holidays/
├── addon.xml                 # Addon definition & DB schema
├── init.php                  # Initialization
├── func.php                  # Helper functions
├── hooks.php                 # CS-Cart hooks
├── cron.php                  # CLI cron entry
├── config.php                # Configuration constants
├── Constants.php             # Centralized constants
├── CRON_JOBS.txt             # Cron job documentation
│
├── controllers/
│   ├── backend/              # Admin controllers
│   │   ├── novoton_holidays.php      # Main + dashboard
│   │   ├── novoton_hotels.php        # Hotel management
│   │   ├── novoton_prices.php        # Price management
│   │   ├── novoton_tools.php         # Tools & tests
│   │   ├── novoton_bookings.php      # Bookings
│   │   ├── novoton_alternatives.php  # Alternatives
│   │   ├── novoton_admin.php         # Admin AJAX handlers
│   │   ├── novoton_price_compare.php # Price comparison tool
│   │   ├── novoton_exchange_rates.php # Exchange rates
│   │   └── novoton_diagnostic.php    # Diagnostics
│   └── frontend/             # Customer controllers
│       ├── novoton_booking.php       # Booking flow (search, form, cart, AJAX)
│       ├── novoton_cron.php          # Cron dispatcher
│       └── novoton_holidays.php      # Frontend hooks
│
├── functions/                # Helper function files
│   ├── formatting.php        # Date/text formatting
│   ├── email.php             # Email helpers
│   ├── exchange_rates.php    # BNR exchange rate functions
│   ├── bookings.php          # Booking helpers
│   ├── helpers.php           # General helpers + fn_novoton_match_price_from_xml()
│   ├── hotels.php            # Hotel helpers
│   └── install.php           # Install/uninstall functions
│
├── src/                      # Core classes (PSR-4 autoloaded)
│   ├── NovotonApi.php        # API facade (37 public methods)
│   ├── HotelSync.php         # V3 Hotel synchronization
│   ├── PriceInfoSync.php     # Priceinfo synchronization
│   │
│   ├── Api/                  # Domain-specific API clients
│   │   ├── HotelApiClient.php
│   │   ├── PricingApiClient.php
│   │   ├── AvailabilityApiClient.php
│   │   ├── ReservationApiClient.php
│   │   ├── DestinationApiClient.php
│   │   ├── PropertyTypeDetector.php
│   │   └── AdultOnlyDetector.php
│   │
│   ├── Services/             # Service classes
│   │   ├── Container.php     # DI container (all instantiation goes here)
│   │   ├── ServiceLoader.php # Global _nvt_*() helpers for procedural code
│   │   ├── BookingService.php
│   │   ├── CacheService.php
│   │   ├── CronService.php
│   │   ├── DateHelper.php
│   │   ├── ErrorHandler.php
│   │   ├── GuestDataService.php
│   │   ├── RoomPriceService.php
│   │   ├── PriceInfoService.php
│   │   ├── SearchService.php
│   │   ├── SecurityService.php
│   │   ├── ConfigProvider.php
│   │   ├── DirectoryManager.php
│   │   └── ValidationHelper.php
│   │
│   ├── Repository/           # Data repositories
│   │   ├── HotelRepository.php
│   │   ├── BookingRepository.php
│   │   ├── FacilityRepository.php
│   │   ├── SyncLogRepository.php
│   │   ├── AlternativeRequestRepository.php
│   │   ├── HotelPackageRepository.php
│   │   └── FeatureMappingRepository.php
│   │
│   ├── Helpers/              # Helper classes
│   │   ├── AbstractBatchedSync.php   # Base class for batched sync
│   │   ├── BatchedHotelInfoSync.php  # Batched hotel sync with resume
│   │   ├── BatchedPriceInfoSync.php  # Batched priceinfo sync with resume
│   │   ├── BatchedHotelFacilitiesSync.php
│   │   ├── CronHelper.php            # Cron utilities
│   │   ├── DatabaseHelper.php        # Database utilities
│   │   ├── DatabaseIterator.php      # PHP Generators for memory-efficient iteration
│   │   ├── StateManager.php          # Sync state management
│   │   ├── SyncInterface.php         # Sync contract interface
│   │   └── SyncLogger.php            # Sync logging utilities
│   │
│   └── Cron/                 # Cron command classes
│       ├── CronDispatcher.php
│       └── Commands/         # Individual cron commands
│
└── schemas/                  # CS-Cart schemas
    ├── block_manager/         # Dedicated booking block types
    ├── permissions/           # Admin permissions
    └── theme_editor/          # Visual editor schemas
```

### Dependency Injection

All service and repository instantiation is centralized through two classes:

| Class | Purpose |
|-------|---------|
| `Container` | DI container — constructs, wires, and caches all singletons. Supports `override()` for testing. |
| `ServiceLoader` | Global `_nvt_*()` functions that delegate to `Container::getInstance()`. Used in procedural code (hooks, controllers) where constructor injection is not possible. |

**Rule:** Controllers, hooks, and function files must **never** use `new ClassName()` for addon classes. Use the appropriate `_nvt_*()` helper or `Container::getInstance()->method()` instead. Only `Container.php` itself may call `new`.

#### Available ServiceLoader Helpers

| Helper | Returns |
|--------|---------|
| `_nvt_api()` | `NovotonApi` |
| `_nvt_booking_service()` | `BookingServiceInterface` |
| `_nvt_guest_service()` | `GuestDataServiceInterface` |
| `_nvt_search_service()` | `SearchServiceInterface` |
| `_nvt_price_service()` | `RoomPriceServiceInterface` |
| `_nvt_price_info_service()` | `PriceInfoServiceInterface` |
| `_nvt_security_service()` | `SecurityServiceInterface` |
| `_nvt_cache_service()` | `CacheServiceInterface` |
| `_nvt_validation_helper()` | `ValidationHelper` |
| `_nvt_date_helper()` | `DateHelper` |
| `_nvt_cron_service()` | `CronServiceInterface` |
| `_nvt_diagnostics_service()` | `DiagnosticsServiceInterface` |
| `_nvt_alternative_request_service()` | `AlternativeRequestServiceInterface` |
| `_nvt_booking_submission_service()` | `BookingSubmissionServiceInterface` |
| `_nvt_admin_cron_service()` | `AdminCronService` |
| `_nvt_property_type_detector()` | `PropertyTypeDetector` |
| `_nvt_hotel_repo()` | `HotelRepositoryInterface` |
| `_nvt_booking_repo()` | `BookingRepositoryInterface` |
| `_nvt_facility_repo()` | `FacilityRepositoryInterface` |
| `_nvt_sync_log_repo()` | `SyncLogRepositoryInterface` |
| `_nvt_alternative_request_repo()` | `AlternativeRequestRepositoryInterface` |
| `_nvt_db_iterator()` | `DatabaseIteratorInterface` |
| `_nvt_batched_hotelinfo_sync()` | `SyncInterface` |

### Service Classes

| Service | Purpose |
|---------|---------|
| `BookingService` | Create, update, retrieve bookings |
| `CacheService` | API response caching |
| `CronService` | Cron job scheduling and execution |
| `DateHelper` | Date formatting and calculations |
| `ErrorHandler` | Scoped error handling |
| `GuestDataService` | Parse and format guest data |
| `LoggerTrait` | Consistent PSR-3-like logging |
| `RoomPriceService` | Real-time room price calculations with commission |
| `PriceInfoService` | Season price list management |
| `PriceInfoCalculation` | Price info calculation engine |
| `SearchService` | Search parameter parsing |
| `SecurityService` | Input validation, CSRF, sanitization |
| `ValidationHelper` | Reusable validation rules |

### Booking Data Architecture (Single Source of Truth)

The addon uses `novoton_bookings` table as the **single source of truth** for all booking data.

#### Data Flow

```
Cart Add → novoton_bookings (order_id=0, pending)
     ↓
Checkout → place_order hook → UPDATE novoton_bookings (set order_id)
     ↓
API Submit → UPDATE novoton_bookings (novoton_status, novoton_invoice_id)
```

#### Key Principles

1. **One Canonical Source**: All booking data lives in `novoton_bookings` table
2. **No Duplicates**: place_order hook always UPDATEs existing bookings instead of INSERT
3. **Order Details for Display**: `order_details.extra` stores reference data for order display only
4. **Admin Reads from DB**: `getUnifiedBookings()` reads directly from `novoton_bookings` table

#### BookingRepository Methods

| Method | Description |
|--------|-------------|
| `findById($id)` | Find booking by ID |
| `findByOrderId($orderId)` | Find bookings for an order |
| `findByUserId($userId)` | Find bookings for a user |
| `getUnifiedBookings($params)` | Get bookings with filters and joins |
| `create($data)` | Create new booking |
| `update($id, $data)` | Update existing booking |
| `deleteOrphans($hours)` | Delete abandoned cart bookings |

#### Orphan Cleanup

Orphan bookings (order_id=0, older than 24h) are abandoned cart items that can be safely deleted via the "Cleanup Orphan Bookings" button in admin.

---

## Frontend JavaScript

### JS Files

| File | Purpose |
|------|---------|
| `js/addons/novoton_holidays/react19-bundle.js` | React 19 booking engine (~214 KB minified) |
| `js/addons/novoton_holidays/booking-form-validation.js` | DOB masking, age validation, AJAX price recalculation |
| `js/addons/novoton_holidays/booking_engine.js` | Booking engine form initialization |
| `js/addons/novoton_holidays/dob-validation.js` | DOB validation, price recalc, desktop/mobile visibility |
| `js/addons/novoton_holidays/multiroom-booking.js` | Multi-room selection and configuration |
| `js/addons/novoton_holidays/utils.js` | Shared utility functions |

### CSS Files

| File | Purpose |
|------|---------|
| `design/themes/*/css/addons/novoton_holidays/styles.css` | Main addon styles |
| `design/themes/*/css/addons/novoton_holidays/styles.min.css` | Minified main styles |
| `design/themes/*/css/addons/novoton_holidays/booking-form-react.css` | React booking form styles |
| `design/themes/*/css/addons/novoton_holidays/booking-form-react.min.css` | Minified React styles |
| `design/backend/css/addons/novoton_holidays/styles.css` | Admin panel styles |

Themes: `responsive` (default CS-Cart) and `nova_theme`.

### Frontend JS Configuration (`NovotonConfig`)

The `scripts.post.tpl` hook exposes a global `window.NovotonConfig` object:

| Property | Description |
|----------|-------------|
| `debug` | `true`/`false` — mirrors addon debug_mode setting |
| `ajaxRecalcUrl` | Pre-built URL for AJAX price recalculation (includes `storefront_id` via `fn_url`) |

All JS files should use `NovotonConfig.ajaxRecalcUrl` instead of building AJAX URLs manually. This ensures CS-Cart multi-storefront compatibility.

### Booking Controller Modes (Frontend)

| Dispatch | Description |
|----------|-------------|
| `novoton_booking.search` | Search availability |
| `novoton_booking.booking_form` | Display booking form |
| `novoton_booking.add_to_cart` | Add booking to cart |
| `novoton_booking.book` | Process booking |
| `novoton_booking.edit_booking` | Edit existing booking |
| `novoton_booking.update_booking` | Update booking |
| `novoton_booking.request_alternatives` | Request alternative rooms |
| `novoton_booking.ajax_recalculate_price` | AJAX price recalculation |

---

## React Booking Engine

The addon includes a React 19-based booking form for an enhanced user experience.

### Components

| Component | File | Purpose |
|-----------|------|---------|
| `BookingEngine` | booking-form.jsx | Main form container |
| `Calendar` | booking-form.jsx | Two-month date range picker |
| `GuestPicker` | booking-form.jsx | Multi-room adults/children selector |

### Features

- **Date Selection:** Two-month calendar with check-in/check-out range selection
- **Guest Management:** Multi-room support (up to 12 rooms), adults (1-9), children (0-4) per room
- **Age Validation:** Required age selection for children with visual error indicators
- **Localization:** Auto-detects Romanian/English based on page language
- **Responsive:** Mobile-friendly with collapsible popups

### Bundle Details

- **File:** `js/addons/novoton_holidays/react19-bundle.js`
- **Size:** ~214 KB (minified production build)
- **Includes:** React 19, ReactDOM, booking components

### Styling

- CSS file: `css/addons/novoton_holidays/booking-form-react.css`
- Yellow border design (Booking.com style)
- CSS variables for easy customization

---

## Availability Search Features

The search system shows the hotel's accommodation season and has two independent fallback layers that help customers find available rooms even when their exact dates don't match.

### Season Period Display

**Purpose:** Show when the hotel operates, so customers understand why dates may be unavailable.

**How it works:**
1. During search, the system reads `priceinfo_data` JSON from `cscart_novoton_hotel_packages`
2. Extracts the first season's `DateFrom` and the last season's `DateTo`
3. Displays a blue info line: *"This hotel offers accommodation from 21 May to 30 Sep 2026"*

**Where it appears:**
- In the hotel header (under the location line) — always visible when results are shown
- In the "No availability" message — explains why the search returned no results
- In the "Alternative dates found" panel — provides season context

**Technical details:**

| Aspect | Value |
|--------|-------|
| Data source | `cscart_novoton_hotel_packages.priceinfo_data` JSON |
| Fields | `seasons.season[0].DateFrom` → `seasons.season[N].DateTo` |
| Template variables | `$hotel_season_from`, `$hotel_season_to` |
| Date format | `%d %b` for start, `%d %b %Y` for end |
| Translation key | `novoton_holidays.accommodation_period` |
| Performance | No API call — reads from synced database |

### Layer 1: Nearby Date Availability (`hotel_quota_add`)

**Purpose:** Show alternative date windows for rooms that appear as "On request" (no quota).

**When it triggers:** During a normal search, after `hotel_quota` returns quota data, the system checks each room. If any room has quota = 0, "RQ", "REQUEST", or empty, this fallback activates.

**How it works:**

1. The search calls `hotel_quota` to get availability for the requested dates
2. If any rooms are unavailable, calls `hotel_quota_add` API to check ±5 days around the original dates
3. The API returns alternative check-in/check-out periods where the room becomes available
4. These periods are attached to each "On request" room as `nearby_availability`
5. The template displays a suggestion: *"Available on nearby dates: [date range]"*

**Technical details:**

| Aspect | Value |
|--------|-------|
| API function | `hotel_quota_add` |
| PHP method | `NovotonApi::getHotelQuotaAddAll()` |
| Search range | ±5 days (API-side) |
| Cache TTL | 180 seconds (3 minutes) |
| Code location | `search.php` lines 848-883 |
| Result format | `room_id => [ {check_in, check_out, quota}, ... ]` |

**Example flow:**
```
User searches: Hotel Maritza, Jun 15-22, Double Room
→ hotel_quota returns: Double Room = 0 (no quota)
→ hotel_quota_add returns: Double Room available Jun 17-24 (3 rooms)
→ Room shows as "On request" with message: "Available Jun 17-24"
```

### Layer 2: Alternative Dates Search

**Purpose:** Find any available dates when the search returns zero results entirely.

**When it triggers:** Only when `$results` is completely empty — no room/board combination returned a price for the requested dates.

**How it works:**

1. Search runs `room_price` for the requested dates across all rooms and board types
2. If zero results come back, builds a list of alternative dates: first +1 to +N days, then -1 to -N days
3. For each alternative date, iterates all rooms and board types calling `room_price`
4. Stops on the first date that returns any valid price
5. Displays a yellow banner: *"No availability on selected dates, but found on: [date range]"* with a "View availability for these dates" link

**Technical details:**

| Aspect | Value |
|--------|-------|
| API function | `room_price` (called per date/room/board) |
| Search range | ±`flex_days` if provided, otherwise ±10 days |
| Past date handling | Skips dates before today |
| Search order | Future dates first, then past dates |
| Code location | `search.php` lines 1136-1225 |
| Result format | Array of `{room_id, room_name, board_id, board_name, check_in, check_out, total_price, price_per_night, nights}` |
| Stops early | Yes — breaks on first date with any availability |

**Example flow:**
```
User searches: Hotel Maritza, Jun 15-22
→ room_price returns no prices for any room/board on Jun 15-22
→ Alternative search tries: Jun 16, Jun 17, Jun 18...
→ Jun 18-25 returns prices for Double Room AI
→ Banner shows: "No availability Jun 15-22, found on Jun 18-25"
```

### How the Two Layers Differ

| | Layer 1: `hotel_quota_add` | Layer 2: Alternative Dates |
|---|---|---|
| **Trigger** | Some rooms have quota=0/RQ | Zero results from entire search |
| **Scope** | Per-room nearby availability windows | Hotel-wide date scanning |
| **API** | `hotel_quota_add` (single call) | `room_price` (multiple calls) |
| **Range** | ±5 days (API-determined) | ±10 days (or `flex_days`) |
| **Result** | Dates attached to "On request" rooms | Separate banner with link to new dates |
| **Coexists** | Yes — shown alongside priced rooms | Only when no priced rooms exist |
| **Performance** | Fast (single API call, cached 3 min) | Slower (iterates rooms × boards × dates) |

---

## Troubleshooting

### Health Check Endpoint

Access: `admin.php?dispatch=novoton_diagnostic.health`

Returns JSON response for automated monitoring:

```json
{
  "status": "healthy",
  "timestamp": "2026-02-15T12:00:00+00:00",
  "version": "3.0.0-A86",
  "components": {
    "database": { "status": "healthy", "response_time_ms": 2.5 },
    "api": { "status": "healthy", "circuit_breaker": {...} },
    "cache": { "status": "healthy", "persistent_items": 42 },
    "sync": { "status": "healthy", "hours_since_sync": 2.5 }
  },
  "metrics": {
    "bookings_24h": 15,
    "pending_bookings": 3,
    "failed_bookings_24h": 0,
    "failure_rate_24h": "0%"
  }
}
```

**Status Values:**
- `healthy` - All systems operational
- `degraded` - Non-critical issues detected
- `unhealthy` - Critical system failure

Use this endpoint with monitoring tools (Uptime Robot, Pingdom, etc.) to track addon health.

### Debug Mode

Add `?novoton_debug=1` to any booking AJAX URL, or enable **Debug Mode** in addon settings.

For search page debugging, pass `&debug=1` in the search URL.

### Common Issues

#### "Service unavailable" when sending emails
- Check **Administration → Settings → E-mails** configuration
- Verify SMTP settings or PHP mail() is working

#### Hotels not syncing
- Check API credentials in addon settings
- View sync logs on Dashboard
- Test API: **Novoton Holidays → Diagnostic**

#### Prices showing 0
- Run price sync cron job (`mode=room_price`)
- Check if hotel has `has_room_price = 'Y'`
- Verify commission setting is not 0%

#### Booking fails with "Not available"
- Room may have sold out between search and booking
- Try different dates or room type

#### Dashboard shows "Never" for Last Sync
- Run the corresponding cron job
- Check if cron jobs have the correct `access_key`

#### Exchange rates not updating
- Check BNR API connectivity
- Verify `cron_password` parameter
- Check commission is between 0-5%

#### "ID-ul magazinului este necesar (parametrul storefront_id)"
- Fixed in A88: AJAX `fetch()` calls now use `fn_url`-generated URLs that include `storefront_id`
- JS files use `NovotonConfig.ajaxRecalcUrl` (set in `scripts.post.tpl` via `fn_url`)
- If this error reappears, check that `scripts.post.tpl` hook is loading (clear template cache)

#### PHP warnings corrupting JSON response
- Fixed in A86: AJAX URLs now send only `dispatch` param (no leaked URL params)
- Scoped error handler logs warnings without suppressing them
- `$_REQUEST` arrays sanitized at controller top

### Log Files

Addon logs events to CS-Cart's logging system:
- **Administration → Logs** - Filter by "novoton"

---

## Changelog

### Version 3.2.0 (February 20, 2026)
- **Added:** Nearby date availability fallback via `hotel_quota_add` API
  - When `hotel_quota` returns 0/RQ for a room, calls `hotel_quota_add` to find ±5 day availability windows
  - Attaches `nearby_availability` data to "On request" rooms in search results
  - New API method: `NovotonApi::getHotelQuotaAddAll()` with 180s cache TTL
- **Added:** Season period display in search results header and no-availability screens
  - Extracts first/last season dates from `priceinfo_data` JSON (no API call)
  - Shows *"This hotel offers accommodation from [date] to [date]"* in hotel header and no-availability messages
- **Added:** Documentation: new "Availability Search Features" section covering season display and both fallback layers
- **Architecture:** Split monolithic booking controller (3,400 lines) into mode handler files
- **Architecture:** Moved inline SQL from hooks and cron into Repository classes
- **Architecture:** Standardized config access on ConfigService; registered all autoloader namespaces
- **Architecture:** Service-based architecture with PSR-4 autoloader (removed stale `require_once` calls)
- **Changed:** Renamed `PriceService` to `RoomPriceService` for clarity
- **Changed:** Marked `fn_novoton_calculate_price()` and `fn_novoton_get_stored_price()` as `@deprecated`
- **Fixed:** sync_log schema mismatch
- **Fixed:** Multiple bugs, fatal errors, debug leftovers, and security issues found in addon audit

### Version 3.0.0-A86 (February 17, 2026)
- **Security:** Removed debug info disclosure via `?debug_novoton=1` and `?debug=1` on customer-facing order pages
- **Security:** Fixed XSS via unescaped `special_requests` field on order pages (added `|escape`)
- **Fixed:** `updatePriceDisplay` called with wrong arity in `dob-validation.js` — price change notifications now fire correctly
- **Fixed:** Schema: `latitude`/`longitude` columns now `DECIMAL(10,7)` (was `varchar(20)`) in fresh installs
- **Fixed:** Schema: Added missing `last_price_check` column to `novoton_hotels` CREATE TABLE in addon.xml
- **Removed:** Dead `novoton_resorts` table (was created on install then immediately dropped)
- **Removed:** Backup files (`.backup2`, `.full`) from settings templates
- **Changed:** Script `?v=` cache-busting now uses `NOVOTON_VERSION` constant from addon.xml (no more hardcoded versions)
- **Updated:** Stale version comments across JS files and addon.xml to reflect 3.0.0-A86
- **Updated:** Documentation: database schema, removed dead table docs, synced with actual schema

### Version 3.0.0-A86 (February 15, 2026) - Initial
- **Fixed:** PHP warnings corrupting AJAX JSON response — proper three-pronged root cause fix:
  - `dob-validation.js`: replaced dirty URL construction (`Tygh.current_url.replace()` leaked `children_ages[]` params) with clean `baseUrl + dispatch-only` URL
  - Controller AJAX handler: replaced blanket `error_reporting(0)` + `ob_start()` with scoped `set_error_handler()` that logs warnings to CS-Cart log without suppressing them
  - Controller top: sanitize `$_REQUEST`/`$_GET` arrays (`children_ages[]`, `ages[]`) to comma-separated integer strings before CS-Cart `__()` can trigger "Array to string conversion"
- **Fixed:** DOB event handler race condition, synced external JS with multi-room support
- **Fixed:** AJAX URL leaking booking page params into price recalculation requests
- **Changed:** Removed dark backgrounds from search results page CSS
- **Added:** Visual Editor integration for search button color customization
- **Changed:** Replaced hardcoded modal text and Early Booking labels with translation keys (`NovotonTranslations`)

### Version 3.0.0 (February 10, 2026)
- **API Resilience:** Added retry logic with exponential backoff (3 attempts, 1s/2s/4s delays)
- **API Resilience:** Added circuit breaker pattern (5 failures opens circuit for 60s)
- **Monitoring:** Added structured health check endpoint (`novoton_diagnostic.health`)
  - JSON response for automated monitoring
  - Component health: database, API, cache, sync
  - Key metrics: bookings, failures, hotels with prices
- **API:** Added `getCircuitStatus()` method to check circuit breaker state
- **Documentation:** Added webhook callback explanation (not supported by Novoton API)
- **Documentation:** Added API resilience features section
- **Fixed:** All code leftovers and inconsistencies from previous refactor

### Version 2.9.4 (February 10, 2026)
- **Architecture:** Implemented Single Source of Truth for booking data
  - `novoton_bookings` table is now the canonical source for all booking data
  - `getUnifiedBookings()` reads directly from database instead of parsing order_details.extra
  - place_order hook now UPDATEs existing bookings instead of creating duplicates
  - Simplified orphan cleanup (just DELETE old records without order_id)
- **Fixed:** XML packages extraction - multiple `<packages>` siblings now correctly iterated
- **Fixed:** XML seasons extraction - multiple `<seasons>` siblings now correctly iterated
- **Fixed:** 404 error on "Add Hotels as Products" admin page (controller return status)
- **Fixed:** Dashboard "Last Sync" showing "Never" for Hotel List and Prices
- **Fixed:** Smarty template error "Not matching {capture}{/capture}" in booking view
- **Fixed:** Booking view template now properly displays Order ID and prices
- **Added:** `room_number` and `total_rooms` fields for multi-group booking display
- **Updated:** BookingRepository with new methods and optimized queries
- **Updated:** Documentation with architecture details and database schema

### Version 2.9.3 (February 6, 2026)
- **Added:** `exchange_rates` mode to main cron controller for BNR rate updates
- **Removed:** Legacy `hotel_info` mode - use `hotel_info_batched` instead
- **Removed:** Legacy `sync_hotels` mode - use batched modes instead
- **Removed:** Legacy `check_packages` mode - redundant with `hotel_info_batched`
- **Simplified:** Cron architecture now uses only batched modes with resume capability
- **Improved:** All cron jobs now use consistent URL pattern (`novoton_cron.run&mode=XXX`)

### Version 2.9.2 (February 6, 2026)
- **Removed:** Legacy `sync_priceinfo` mode - use `sync_priceinfo_batched` instead
- **Cleaned:** Removed all legacy mode references from cron controller, backend, and documentation

### Version 2.9.1 (February 6, 2026)
- **Added:** `sync_priceinfo_batched` mode - Smart batched priceinfo sync with resume capability
- **Added:** `&unlimited=1` parameter for CLI PHP usage (no time limits)
- **Added:** PHP Generators for memory-efficient large dataset processing
- **Added:** CLI PHP usage documentation for running full syncs
- **Updated:** Recommended crontab now uses batched modes for both hotel info and priceinfo
- **Updated:** CRON_JOBS.txt with comprehensive documentation for all modes

### Version 2.9.0 (February 5, 2026)
- **Added:** Exchange rate auto-update from BNR (National Bank of Romania) API
- **Added:** Currency risk commission setting (0-5%, default 1.8%)
- **Added:** Exchange rates admin page with manual update button
- **Added:** Payment terms with calculated amounts display (e.g., "10% (150.00€)")
- **Added:** Cancellation terms display on order details and emails
- **Added:** All menu items with proper language keys (EN/RO)
- **Fixed:** 404 error on Add Hotels as Products admin page
- **Fixed:** Cron hotel_info mode limit=0 causing no processing
- **Fixed:** Cron add_hotels_as_products default limit (now unlimited)
- **Fixed:** Sync log entries now recorded for all cron modes
- **Fixed:** Dashboard Last Sync section showing correct sync types
- **Fixed:** Removed incorrect "deprecated" warning from hotel_info mode
- **Updated:** Dashboard shows Hotel List, Hotel Info, Prices, Offers Update, Facilities
- **Updated:** Date formatting to use CS-Cart settings

### Version 2.8.0-A80D (January 30, 2026)
- **Added:** Important field display in Room Type column with warning styling
- **Added:** MoreInfo field display in Room Type column with green checkmark
- **Fixed:** Board name formatting without emoji in Choices column
- **Fixed:** Payment and cancellation terms XML parsing

### Version 2.8.0-A80A (January 29, 2026)
- **Fixed:** Payment/cancellation terms variable naming
- **Fixed:** XML parsing rewrite for `<conditions>` structure

### Version 2.8.0-A79Y (January 28, 2026)
- **Added:** Payment Terms and Cancellation Terms columns in search results
- **Enhanced:** Choices column with 8 features including board name

### Previous Versions
- Dashboard 404 fixes
- Cron mapping corrections
- Database column additions
- API test error handling
- Excluded resorts UI
- Room code regex improvements
- URL encoding for API parameters

---

## Support

- **Developer:** VacanteLitoral.ro
- **Website:** https://vacantelitoral.ro

---

*Documentation last updated: February 20, 2026 - Version 3.2.0*
