# `addon-eurosite/` — Eurosite Touring provider addon (MVP)

A new CS-Cart provider addon (id **`eurosite`**) for the **Eurosite** XML web
service — a distinct Touroperator platform, a sibling to the existing
`novoton_holidays` and `sphinx_holidays` providers. Built from
`Documentation/Specificatii_API_Eurosite.pdf`.

> **Status: feature-complete provider addon (Cazari individuale vertical).**
> The API protocol layer is live-verified (2026-08-04, account credentials:
> 86 countries, 358 RO cities of ~21,700 total, 116 own-offer cities, 22
> room types; tag catalog empty). On top of it the addon now ships: DB
> persistence for all six static catalogs + cron sync (CLI + HTTP), a
> sphinx-style destination whitelist, an admin dashboard, the unified
> travel_bookings grid integration (mirror dual-write, status callbacks,
> cancel/refresh-fees actions), live prices on each hotel's product page via
> the shared travel_core booking engine, the "Condiții de Anulare și Plată"
> modal (live getItemFees), a guest booking form (TGender/DOB pax data),
> and the cart→order→AddBookingRequest pipeline. The cart line goes on the
> hotel's own product, by product_id (the product page booked from, else the
> hotel's linked product), as for Sphinx and Novoton; a hotel that is not a
> store product cannot be booked online.
> Placeholder pages cover the Pachete/Transport/Circuite modules.
>
> **Known items:** (1) live search verified working end-to-end 2026-08-05
> (239 Mamaia offers; getItemFees accepts placeholder pax names — the terms
> modal contract holds) — but the service had a full outage on 2026-08-04
> (every search reset at ~6.6s while static data answered), so treat it as
> potentially intermittent; `dev/eurosite/search_diagnose.php` generates an
> evidence report (EN+RO, timestamps + RequestIds) to send the operator if
> it recurs. (2) The addon is deliberately not yet wired into the repo's
> PHPStan/cs-fixer/CI paths (final graduation step).

```
addon-eurosite/
├── var/langs/{en,ro}/addons/eurosite.po  addon name/description + settings labels
└── app/addons/eurosite/
    ├── addon.xml            id=eurosite, deps=travel_core, settings + eurosite_bookings table
    ├── init.php             PSR-4 autoload + TravelProviderRegistry registration
    ├── func.php             thin CS-Cart procedural boundary (hooks are a follow-up)
    ├── composer.json        PSR-4 (Tygh\Addons\Eurosite\)
    ├── phpunit.xml
    ├── src/
    │   ├── EurositeXmlBuilder.php        builds the <Request>/<AuditInfo> envelope
    │   ├── EurositeHttpClient.php        curl POST + retry/circuit-breaker (travel_core ResiliencePolicy)
    │   ├── EurositeTransportInterface.php  transport seam (fakeable in tests)
    │   ├── EurositeXmlParser.php         cleans + unwraps <ResponseDetails>
    │   ├── Api/
    │   │   ├── EurositeApiClient.php     the endpoint facade (static data → search → booking)
    │   │   └── EurositeNormalizer.php    Eurosite → canonical codes (ProviderNormalizerInterface)
    │   ├── Dto/HotelOffer.php            a normalized search-result offer
    │   └── Services/
    │       ├── ConfigProvider.php        settings getters (extends travel_core AbstractConfigProvider)
    │       └── Container.php             composition root (wires the API stack)
    └── tests/Unit/                       30 tests, 138 assertions (envelope, static data, search, booking, normalizer)
```

## The Eurosite protocol

XML over HTTP. Every request is the same envelope (spec: *Autentificarea*):

```xml
<Request RequestType="getHotelPriceRequest">
  <AuditInfo>
    <RequestId>001</RequestId>
    <RequestUser>YourUser</RequestUser>
    <RequestPass>YourPassword</RequestPass>
    <RequestTime>2012-09-04T18:00:46</RequestTime>
    <RequestLang>RO</RequestLang>
  </AuditInfo>
  <RequestDetails> … endpoint payload … </RequestDetails>
</Request>
```

Responses mirror it: `<Response ResponseType="…Response"><AuditInfo/><ResponseDetails>…`.
This is genuinely different from Novoton (form-POST `fn=…`) and Sphinx
(REST/JSON Bearer) — hence a separate addon.

## MVP scope — the "individual accommodations" (Cazari individuale) vertical

`EurositeApiClient` covers the endpoints that make a coherent hotel
search-to-booking flow, mapped 1:1 to the spec:

| Method | Eurosite RequestType | Purpose |
| --- | --- | --- |
| `getCountries()` | getCountryRequest | static: countries |
| `getCities($country)` | getCityRequest | static: cities |
| `getOwnCities()` | getOwnCityRequest | static: cities with own offers (all countries) |
| `getOwnHotels($city)` | getOwnHotelsRequest | static: own hotels + rooms |
| `getRoomTypes()` | getRoomRequest | static: room-type catalog |
| `getTagOffers()` | getTagOffersRequest | static: offer-tag catalog (may be empty) |
| `searchHotels($params)` | getHotelPriceRequest | availability + price search → `HotelOffer[]` |
| `getProductInfo(...)` | getProductInfoRequest | hotel details (description, images, coords) |
| `addBooking($booking)` | AddBookingRequest | create a booking → api/client references |
| `getBooking($ref)` | getBookingRequest | booking status + details |
| `cancelBooking($ref)` | CancelBookingRequest | request cancellation |

**Deferred (spec covers them; not in the MVP):** supplementary services,
item fees / cancellation penalties, packages, transport (charter), circuits,
excursions, and pax modification. The envelope + parser already generalize to
these — they're additional `EurositeApiClient` methods.

**Error contract:** every read method throws `EurositeApiException` when the
server answers with its error envelope (e.g. the `-1000` auth refusal) or an
unparseable body — an API failure is never returned as an empty catalog, so a
future sync job can't mistake "credentials rejected" for "no data".
`addBooking()`/`cancelBooking()` keep returning `ok`/`error` result arrays.

## Configuration

Addon settings (Admin → Add-ons → Eurosite), read via `ConfigProvider`:

- `api_url` — the XML web-service endpoint (default:
  `https://laguna.touringit.ro/server_xml/server.php` — the single URL every
  RequestType is POSTed to)
- `api_user` / `api_password` — the credentials embedded in `<AuditInfo>`
- `tourop_code` — the operator code in payloads (default `EU`)
- `default_currency` (EUR) / `default_language` (RO)
- `allow_insecure_api` — accept a plain `http://` `api_url` (credentials sent
  unencrypted; default off). `https://` URLs always verify the TLS certificate
  and host name regardless of this flag, and a scheme-less URL becomes `https://`
- retry / timeout / circuit-breaker tuning

There are **no default credentials** — the spec ships placeholders
(`YourUser`/`YourPassword`), so nothing here authenticates until you enter
the real account keys. **Credentials are required for every service, static
data included**: with an invalid account the server answers every request
with `ErrorId -1000` ("You are not authorised to access this server!"), and
with a valid account the same requests answer from any host (verified live
2026-08-04; the earlier "IP allowlist" reading of -1000 was wrong). The
endpoint URL ships as the default above (HTTPS, on the touringit.ro platform
Eurosite runs on); the spec's sample payloads reference the same platform
(`EU.touringit.ro` asset links).

Live own-hotels data on this platform reports `Touropcode` **`LA`** (Laguna),
not the spec-example `EU` the `tourop_code` setting defaults to — confirm the
right code for your account with the operator before relying on
search/booking payloads.

## Destination whitelist

**Eurosite → Destination whitelist** (`eurosite.whitelist`) is Travel Core's
destination picker (the page Novoton and Sphinx use too). Each country is
**Not sold**, **All cities** (new cities included), **Own cities** (every
own-offer city, including ones the `own_cities` sync adds later) or **Only
selected**. A country's cities load when it opens (`eurosite.whitelist_body`;
a country with no synced city is listed live from getCityRequest). Cities
first seen after the last Save are flagged new; a ticked city whose cities-sync
stamp falls two days behind its country's is flagged as gone. Save refuses an
empty whitelist (it would stop every hotel sync) and never touches products;
**Disable products outside the whitelist** is its own confirmed step
(status D, never deleted). The dashboard's Destinations card sums it up.

## Hotels and products

**Eurosite → Hotels** (`eurosite.hotels`) lists the hotels of the
*whitelisted* destinations only. The `hotels` sync fetches those
destinations and nothing else; hotels outside the whitelist are hidden and
kept (`sync_status = 'inactive'`, `inactive_reason = 'not_whitelisted'`), and
return on the next sync once their destination is whitelisted. The list
filters by destination, availability, images and product, sorts by every
column (images: hotels without any first), pages the CS-Cart way, and has
two actions for the ticked hotels: **Create products** and **Check
availability now**.

Three cron jobs take hotels to products, after `product_info` (04:00):

| Mode | Default slot | What it does |
|------|--------------|--------------|
| `availability` | daily 04:30 | One price search per destination and date: the near dates (settings: 14, 30, 60 days) plus the peak-season dates (default `07-15, 08-15`, repeating yearly), 7 nights, double room, 2 adults. Each hotel keeps its best answer (Immediate > On request > Stop sale > none), the lowest price and the date that proved it. Then, with "Hide a product when…" on, products that lost every Immediate offer are hidden (H) and shown again when one returns; products changed by hand are left alone. `&city=CODE` |
| `add_products` | daily 05:30 | A product for every listed hotel with an Immediate offer that is not a product yet. Hotel details are fetched first when missing. Hotels without images are skipped unless "Create products for hotels without images" is on. `&city=`, `&limit=` (100), `&dry_run=1` |
| `update_products` | daily 06:00 | Price from the latest check; description and pictures only while the product has none. `&city=`, `&limit=` (200) |

Products: code `EUS-<tour op>-<hotel code>` (e.g. `EUS-LA-RO0363`; no clash
with Sphinx `HR59843` or Novoton `NVT123`), category *[Hotels root category
ID]* › country › destination, price converted to the store currency. Set
**CS-Cart category ID for Eurosite hotels** (a category dropdown, like
Sphinx's) in the add-on settings first: until it is set, no product is
created. The product page gets Travel Core's booking form, which
searches `eurosite_booking.search` with the hotel's code (`hotel_id`) and
shows the live offers inline.

Hotels are static data in the store (synced within the whitelist); prices and
availability are live. Guests only see hotels that are store products: there
is no destination search, and `eurosite_booking.search` without a hotel that
is a buyable product is a 404. A refused step (offer expired, stop sale,
child ages changed, the cart would not take the line) sends the guest back
to the product page with their stay, where the search runs again. If the
cart drops the line, the booking is marked failed.

**Eurosite → SEO Templates** (`eurosite.seo_templates`) sets each product's
name, page title, meta description, keywords, URL and (optionally) full
description from the hotel's data, per storefront language. It is Travel
Core's shared SEO Templates page, the same one Sphinx and Novoton use (a tab
row links the three): click in a field, then click a placeholder to insert
it at the cursor, or a modifier to add it to the placeholder under the
cursor; each field shows its length and a preview for a sample hotel.
Placeholders: name, classification, stars_emoji, property_type, rooms, code,
description, image_url, city, country, city_code, country_code, latitude,
longitude, min_price, currency, year. **Apply templates now** saves the page
and re-applies it to every Eurosite product. Until a template is saved, the
built-in one in `fn_eurosite_seo_defaults()` applies.

Not in this step: product features (stars, destination filters) and
matching the same hotel across providers.

## Testing

```bash
cd addon-eurosite/app/addons/eurosite
composer install                 # once, for phpunit
vendor/bin/phpunit               # 30 tests, 138 assertions
```

The tests inject a fake transport (`EurositeTransportInterface`) that captures
the request the client builds and returns canned responses copied from the
spec, so both request-building and response-mapping are verified without
network. The code is PHPStan level-10 clean and PSR-12 formatted.

## Graduation (next steps, in order)

1. ~~Install & smoke-test static data~~ — done 2026-08-04 (all six services
   answer live; `dev/eurosite/` probes cover each one).
2. ~~Persistence + cron~~ — done: 8 tables, 9 cron modes (CLI `cron.php` +
   `eurosite_cron.run`), sync log, dashboard quick actions
   (`Documentation/CRON_JOBS.txt` has the schedule).
3. ~~Whitelist, admin, bookings grid, storefront search + booking pipeline~~ —
   done (see Status above).
4. **Live search/booking smoke test** — search + getItemFees verified live
   2026-08-05 (see Status); the remaining piece is a full search → book →
   cancel cycle on the docker store (AddBookingRequest against a real
   offer, then CancelBookingRequest cleanup).
5. **Wire into the CI gates**: add the addon's `src`/`controllers`/`func.php`
   to `phpstan.neon`, `.php-cs-fixer.dist.php`, the CI `php -l` step, and a
   PHPUnit coverage job — the same treatment novoton/sphinx get.
