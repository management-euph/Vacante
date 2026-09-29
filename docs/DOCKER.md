# Local Docker sandbox — full CS-Cart store for addon testing

A disposable CS-Cart 4.20.1 store on **MariaDB 10.11** (matching the production
server) that runs the four repo addons from live source, so features can be
exercised locally — install/uninstall, crons, booking flows, template rendering,
and emails — before verifying on devx. It fills the gap the codebase repeatedly notes ("verify
manually on devx"): there was no local full store until now.

Everything lives under **`docker/fullstore/`**; the full walkthrough is in
[`docker/fullstore/README.md`](../docker/fullstore/README.md). This page is the
short pointer.

## TL;DR (Windows + WSL2)

```bash
cd docker/fullstore
cp .env.example .env                 # set CSCART_LICENSE_KEY
cp /mnt/c/path/cscart_v4.20.1.zip kit/
docker compose up -d --build         # first boot installs everything
```

| What | URL |
|------|-----|
| Storefront | http://localhost:8080 |
| Admin | http://localhost:8080/admin.php (`admin@example.com` / `admin`) |
| Mailpit (emails) | http://localhost:8025 |
| phpMyAdmin | http://localhost:8081 |

Edit an addon in the repo → refresh the browser (development mode recompiles
templates; opcache revalidates PHP — no manual cache clear). Wipe and rebuild
clean with `docker compose down -v && docker compose up -d --build`.

## How it works

- **You supply** the CS-Cart 4.20.1 kit (dropped in `docker/fullstore/kit/`) and
  a license key (in `.env`) — both gitignored. Nothing in the repo vendors the
  CS-Cart core.
- The `app` image (`php:8.3-apache`) uses the same PHP extension set as
  `docker/test-db/build.sh`. On first boot the entrypoint ingests the kit, runs
  the CS-Cart **console installer** with all four addons, enables development
  mode, and clears the cache. A sentinel file makes later boots fast.
- `link-addons.sh` **symlinks each addon's deploy artifacts** from the `/repo`
  mount into the docroot, so the store runs your live repo code. The dev-only
  trees (`react-src`, tests, vendor) are excluded by construction. (The repo is
  mounted read-write because CS-Cart's installer requires its `design/`/`var/`
  trees writable; CS-Cart writes runtime data into the `var/` volume, not your
  addon source, so the working tree stays clean.)
- After pulling code that adds a new addon folder (`js/addons/<id>`, `design/backend/css/addons/<id>`),
  re-run `link-addons.sh` or restart the `app` container, then clear the cache: the links are made at start.
- `mailpit` captures every outbound email (booking confirmations, availability
  notifications, fgo invoices); `phpmyadmin` exposes the DB.
- **Storefront theme**: the provision installs `nova_theme` automatically when
  the kit ships it (production's theme), else classic `responsive`; override
  with `CSCART_THEME=` in `.env`. This matters because the hotel PDP booking
  form + location line hang on theme hook anchors in the product templates —
  see "Storefront theme" and the PDP troubleshooting table in
  [`docker/fullstore/README.md`](../docker/fullstore/README.md). Quick
  diagnosis on any product page: append `&travel_debug=1` and read the
  `[travel_debug]` object in the browser console (F12).

## Relationship to the CI test-DB image

`docker/test-db/` builds a **MySQL-only** seed image for CI integration tests —
it's a different thing and is untouched by this sandbox. `docker/fullstore/` is
for **local, interactive** full-store testing.

## Limits

- Live Novoton/Sphinx API calls need real credentials + network; the seeded
  `enc:` test credentials cover everything else (store, admin, install/uninstall,
  rendering, crons, email). Keep `disable_api_submission` on for booking-UI tests.
- Dev sandbox only — not a production image.
