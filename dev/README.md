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
  price search per destination (availability IM/OR/ST, from-price). Nothing is
  booked, written or cached; no credential is printed. `?cities=RO0218,RO2M`,
  `?check_in=YYYY-MM-DD&nights=7`, `&download=1` saves the file.
  `http://localhost:8080/dev/tools/eurosite-sample.php`

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
