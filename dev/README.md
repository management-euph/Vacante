# `dev/` — standalone provider API probes

Small, dependency-free PHP scripts that call the **Novoton** and **Sphinx**
provider APIs directly so you can see the raw request + response for one API
feature at a time. Handy for debugging: "what does the API actually return for
this hotel / this search / this booking?" without going through CS-Cart, the
addons, the cart, or the database.

```
dev/
├── novoton/         XML-over-HTTP API (b2b.allinclusivebg.com)
├── sphinx/          REST/JSON API (Bearer auth)
├── sphinx_api_dev/  older focused Sphinx investigation tools (coordinates, raw search dump)
├── eurosite/        Eurosite/TouringIT XML API probes (see eurosite/README.md)
└── tools/           store-side sandbox tools (bootstrap the CS-Cart in the container)
```

Unlike the API probes, `dev/tools/` scripts bootstrap the full CS-Cart from the
fullstore container (dev/ is bind-mounted into the docroot), so they can read
and heal the store itself:

- `tools/seed-langs.php` — language self-heal diagnostic. Shows each addon's
  seed stamp vs live fingerprint, whether the container loads current addon
  files, and every checkout/PDP label row; `?force=1` reseeds all three addons'
  language keys and clears the cache. Refuses non-localhost requests.
  `http://localhost:8080/dev/tools/seed-langs.php`
- `tools/sphinx-relink.php` — sphinx hotel/product link report. Lists every
  sphinx-shaped product (legacy `SPX…` + country-prefixed `TR3612`) and whether
  a `?:sphinx_hotels` row points at it — an unlinked product is a hotel page
  with no booking form. `?force=1` runs the same repair as the dashboard's
  Relink button (fetch each missing hotel from the API, re-insert it linked)
  and clears the cache. `http://localhost:8080/dev/tools/sphinx-relink.php`
- `tools/eurosite-sample.php` — real Eurosite hotel data as JSON, for design
  work: the store's synced hotels and destination names, plus a READ-ONLY live
  price search per whitelisted destination (availability IM/OR/ST, from-price),
  the whitelist itself and each hotel's picture count from the product-info
  cache. Nothing is booked, written or cached; no credential is printed. `?cities=RO0218,RO2M`,
  `?check_in=YYYY-MM-DD&nights=7`, `&download=1` saves the file.
  `http://localhost:8080/dev/tools/eurosite-sample.php`
- `tools/eurosite-hotel-name.php` — where one Eurosite hotel's name comes
  from (e.g. the "CM" in "ALTUS CM"): the store's synced row and product name
  per language, what the SEO name template would make of it, then READ-ONLY
  live getOwnHotelsRequest (raw `<Hotel>`), getProductInfoRequest
  (`<ProductName>`) and a sample getHotelPriceRequest, with a summary of which
  answers carry the suffix. Only request details are printed, never the
  envelope with the account. `?hotel=ALTUS` (part of the name or product
  code), `?product_id=123`, `&check_in=YYYY-MM-DD&nights=7`, `&search=0`.
  `http://localhost:8080/dev/tools/eurosite-hotel-name.php?hotel=ALTUS`
- `tools/eurosite-packages.php` — how the store's Eurosite account can sell
  transport with a hotel, READ-ONLY, with the store's account. (1) The package
  module (Pachete): getPackageNVRoutesRequest with Transport=search (the spec
  says that is in general enough; `?transport=bus,plane` tells them apart),
  each destination city with its hotels in the store; then a sample
  getPackageNVPriceRequest (the destination with the most store hotels, from
  Bucharest, on its next date; a few stay lengths until one answers): each
  hotel's offers with PackageId / PackageVariantId, room, meal and services
  (bus or flights with times and seats, transfers, airport tax, package fee),
  and whether the hotel is in the store's hotel list with a product.
  (2) Charters (spec "Transport"): getCharterCitiesRequest for departures and
  arrivals, then a sample getCharterPriceRequest from Bucharest to the arrival
  city with the most store hotels, on a few dates until one answers.
  (3) Hotel supplements: for the busiest whitelisted cities, the storefront's
  own hotel search, then getHotelServiceTypesRequest on a store hotel's offer
  (a month ahead and next summer) — meals, other services, transport (with a
  CharterId when it is a charter) — and each transport supplement's price.
  A summary says which of the three the account has. Params: `&sample=3`,
  `&city=…&dep_city=…&check_in=…&nights=7`, `&search=0`, `&packages=0`,
  `&charter=0`, `&charter_from=…&charter_to=…&charter_date=…`,
  `&supplements=0|N`, `&hotel=ALTUS`, `&hotel_city=…`, `&hotel_check_in=…`,
  `&raw=1`. Nothing is booked or written; the store's circuit breaker is not
  touched; neither the API address nor the account is printed, errors
  included. `http://localhost:8080/dev/tools/eurosite-packages.php`

Each folder has a shared `_*_client.php` (the standalone HTTP client +
pretty-printer) plus one probe file per API feature. This mirrors the existing
`dev/sphinx_api_dev/` sandbox — **not part of any addon**, no bootstrap, no
autoload, no DB, and outside the CI gate (nothing here is analysed or tested).

## Running

Every probe works from the CLI or a browser.

```bash
php dev/novoton/hotel_list.php --country=BULGARIA --limit=20
php dev/sphinx/hotels.php --per_page=50 --limit=20
```

Serve the folder with any PHP server and hit the same script in a browser:

```bash
php -S localhost:8080 -t dev
# then: http://localhost:8080/novoton/hotel_list.php?country=BULGARIA&limit=20
#       http://localhost:8080/sphinx/hotels.php?per_page=50&limit=20
```

Run any probe with `--help` (or `?help`) to see its arguments.

## The `limit` knob

Every list-style probe takes `--limit=N` (`?limit=N`) to keep the output
readable:

- **Novoton** — the XML API has no server-side paging, so `limit` trims the
  most-repeated element (hotels, resorts, rooms, …) to the first N and notes
  how many were hidden.
- **Sphinx** — the REST API pages server-side via `--page` / `--per_page`
  (note: Sphinx requires `per_page >= 10`). `limit` is a **separate** client-
  side print-trim on top of whatever page you fetched, so you can pull a full
  page but only print a couple of rows.

## Credentials

Both suites default to the **non-production DEV/staging** credentials already
committed in the addons' `addon.xml` (and in `dev/sphinx_api_dev/`). Override with
environment variables when you have other keys:

| Novoton | Sphinx |
| --- | --- |
| `NOVOTON_API_URL` | `SPHINX_API_URL` |
| `NOVOTON_API_KEY` | `SPHINX_API_TOKEN` |
| `NOVOTON_API_ID` | `SPHINX_API_INSECURE` (skip TLS verify, dev only) |
| `NOVOTON_API_USER` | |
| `NOVOTON_API_PASSWORD` | |
| `NOVOTON_API_LANG` (default `UK`) | |
| `NOVOTON_API_INSECURE` (skip TLS verify, dev only) | |

```bash
NOVOTON_API_KEY=... NOVOTON_API_USER=... php dev/novoton/hotel_list.php
SPHINX_API_TOKEN=... SPHINX_API_URL=... php dev/sphinx/ping.php
```

Requests print with `<usr>`/`<psw>` (Novoton) masked. The Bearer token is not
echoed.

## Booking safety

The two booking probes — `novoton/reservation.php` and `sphinx/hotel_book.php`
— create a record on the provider, so they are **dry-run by default**: without
`--send` they only PRINT the exact request they would post. Novoton's probe
also defaults to the API's "test reservation, do not proceed" remark even with
`--send` (add `--real` for a live booking). Read each probe's `--help` before
sending.

See `novoton/README.md` and `sphinx/README.md` for the per-feature list.
