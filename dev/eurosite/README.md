# `dev/eurosite/` — standalone Eurosite API probes

Companion to `dev/novoton/` and `dev/sphinx/`: self-contained PHP scripts
(no CS-Cart, no autoload, no DB) that exercise one Eurosite RequestType each
and print the RAW request + response. Usable from the
CLI or a browser; the shared docker bind mount (`../../dev`) serves them at
`/dev/eurosite/...` inside the fullstore container.

Neither the account nor the address set in the environment is printed:
`<RequestUser>` and `<RequestPass>` are masked, and the API host, user and
password are hidden in cURL and API error texts too (the repo is public, and
these outputs get pasted).

## The one endpoint

Everything POSTs to a single URL — RequestTypes are envelope attributes,
not paths:

```
https://laguna.touringit.ro/server_xml/server.php
```

**Authentication:** valid XML credentials are required for **every** service,
static data included. With an invalid/placeholder account every request
answers `ErrorId -1000 "You are not authorised to access this server!"`;
with valid credentials the same requests answer from any host (verified live
2026-08-04 — the earlier "IP allowlist" reading of -1000, noted 2026-07-25
when only placeholder credentials existed, was wrong). Static catalogs
confirmed live: 86 countries, 358 RO cities (~21,700 across all countries), 116 own-offer cities, 22 room
types, own hotels with room lists (e.g. Albena, Mamaia); the tag catalog is
currently empty.

## Configuration (env vars)

| Var | Default |
| --- | --- |
| `EUROSITE_API_URL` | `https://laguna.touringit.ro/server_xml/server.php` |
| `EUROSITE_API_USER` | `YourUser` (spec placeholder) |
| `EUROSITE_API_PASSWORD` | `YourPassword` (spec placeholder) |
| `EUROSITE_API_LANG` | `RO` |
| `EUROSITE_API_INSECURE` | unset (set `1` to skip TLS verify — dev only) |

## Probes

| Script | RequestType | Args |
| --- | --- | --- |
| `countries.php` | getCountryRequest | `--limit=N` |
| `cities.php` | getCityRequest | `--country=RO --limit=N` |
| `own_cities.php` | getOwnCityRequest | `--limit=N` |
| `rooms.php` | getRoomRequest | `--limit=N` |
| `own_hotels.php` | getOwnHotelsRequest | `--city=CODE --limit=N` |
| `tag_offers.php` | getTagOffersRequest | `--limit=N` |
| `hotel_search.php` | getHotelPriceRequest | `--country --city --check-in --nights --adults [--children=8,5] [--room=DB] [--tourop=LA --product=RO0486] --limit=N` (one hotel: the store's product code EUS-LA-RO0486 is tour operator LA, product RO0486), or `--code=EUS-LA-RO0486`: in the docker store the hotel's country and city come from the store's own hotel list, so no `--city` |
| `package_routes.php` | getPackageNVRoutesRequest | `[--transport=search\|bus\|plane] --limit=N` — package destinations, departure cities and dates |
| `package_search.php` | getPackageNVPriceRequest | `--country --city [--zone] [--dep-country=RO] --dep-city --transport --check-in --nights [--days=1] --adults [--children=8,5] [--room=DB] [--tourop] --limit=N` — hotels + transport, PackageId / PackageVariantId, services |
| `charter_cities.php` | getCharterCitiesRequest | `[--type=departure\|arrival\|both] --limit=N` — the charter module's (spec "Transport") departure and arrival cities |
| `charter_search.php` | getCharterPriceRequest | `--from --to [--from-country] [--to-country] --date [--nights=7 \| --return] [--flexible=0] [--transport=search\|plane\|bus] --adults [--children=8,5] --limit=N` — the flights or buses out and back, with times, seats, price, CharterId |
| `hotel_services.php` | getHotelServiceTypesRequest (+ getHotelServicePriceRequest) | `--country --city --product (or --code=EUS-LA-RO0486) [--tourop] --variant --check-in [--nights=7] [--price=CODE --type=7 --adults --children]` — an offer's supplements: meals (2), other (6), transport (7, CharterId when a charter); `--price` prices one |
| `product_info.php` | getProductInfoRequest | `--country --city --product [--type=hotel] --limit=N` |
| `booking_info.php` | getBookingRequest | `--ref=REF [--source=client|api]` |
| `search_diagnose.php` | getHotelPriceRequest battery | `[--report=FILE]` — outage evidence report (EN+RO) for the operator |

Every probe accepts `--help` (CLI) / `?help` (browser). `--limit=N` trims the
most-repeated element in the response to its first N occurrences so big
catalogs stay readable.

Booking **creation/cancellation** probes are deliberately absent until the
account is live — use the addon's `EurositeApiClient::addBooking()` /
`cancelBooking()` once credentials are configured, or ask for dry-run probes
like `dev/novoton/reservation.php` when you need them.

## Examples

```bash
php countries.php --limit=20
php cities.php --country=RO --limit=30
php hotel_search.php --city=ROMM --check-in=2026-08-10 --nights=7 --adults=2 --children=8
EUROSITE_API_USER=... EUROSITE_API_PASSWORD=... php booking_info.php --ref=int1234
```
