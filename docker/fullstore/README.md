# Full-store CS-Cart sandbox

A disposable, local CS-Cart 4.20.1 store on **MariaDB 10.11** (matching the
production server) with the repo addons (travel core, Novoton, Sphinx, FGO, Eurosite and Netopia payments) linked in for live testing —
install/uninstall, crons, booking flows, templates, and emails — without
touching devx.

> This is a **dev sandbox**, not a production image (development mode on,
> debug-friendly PHP). devx/production remain the deploy targets.

## What you need

- **Docker Desktop** (you already have it) with the **WSL2 backend** enabled
  (Settings → General → *Use the WSL 2 based engine*).
- Your **CS-Cart 4.20.1 kit** (`cscart_v4.20.1.zip` or an extracted folder) and
  a **valid license key**. Nothing in the repo ships the CS-Cart core — you
  supply it. Both stay gitignored.

## One-time setup (Windows + WSL2)

1. **Clone the repo inside WSL2**, not on the Windows drive — file watching and
   PHP are dramatically faster on the Linux filesystem. In a WSL (Ubuntu)
   terminal:
   ```bash
   cd ~ && git clone <repo-url> NovotonBUN && cd NovotonBUN/docker/fullstore
   ```
   (If you must keep the repo on `C:`, it still works via `/mnt/c/...`, just
   slower.)

2. **Create your env file** and fill in the license key:
   ```bash
   cp .env.example .env
   nano .env            # set CSCART_LICENSE_KEY=...
   ```

3. **Drop your kit** into `./kit/`:
   ```bash
   cp /mnt/c/path/to/cscart_v4.20.1.zip ./kit/
   ```

4. **Build and start**:
   ```bash
   docker compose up -d --build
   ```
   First boot unpacks the kit, links the addons, and runs the CS-Cart installer
   — watch it with `docker compose logs -f app`. Give it a few minutes; it's
   done when you see `Provisioning complete — store is live`.

## URLs

| What | URL | Notes |
|------|-----|-------|
| Storefront | http://localhost:8080 | |
| Admin | http://localhost:8080/admin.php | `admin@example.com` / `admin` |
| Mailpit (all emails) | http://localhost:8025 | booking/invoice/alert mails land here |
| phpMyAdmin | http://localhost:8081 | |

> If `/admin.php` 404s, CS-Cart may have used a different admin script name —
> check `$config['admin_index']` in `config.local.php`
> (`docker compose exec app cat config.local.php | grep admin`).

## Daily use

- **Edit an addon** in the repo (host IDE) → **refresh the browser**. PHP picks
  up on the next request; templates recompile automatically (development mode).
  No manual cache clear needed.
- **Run a cron** (copy the real URL from Admin → the addon's Dashboard):
  ```bash
  curl "http://localhost:8080/index.php?dispatch=travel_cron.run&access_key=KEY&cron_mode=exchange_rates"
  ```
- **Re-run the ordered addon install** (to test the lifecycle):
  ```bash
  docker compose exec app php /usr/local/bin/install-addons.php
  ```
- **Shell into the store**: `docker compose exec app bash`
- **Logs**: `docker compose logs -f app`
- **Provider API probes** (the `dev/` folder — `dev/novoton`, `dev/sphinx` and the
  `dev/sphinx_api_dev` sandbox) are **bind-mounted** straight into the web root as
  real files, so they're served directly:
  `http://localhost:8080/dev/sphinx/ping.php`,
  `http://localhost:8080/dev/novoton/hotel_list.php?country=BULGARIA&limit=20` and
  `http://localhost:8080/dev/sphinx_api_dev/GetHotelbyId.php?id=3612`. Edits to the
  scripts show up live (it's a bind mount), and new files in the folder need no
  restart. If a probe 404s, the mount just isn't in your container yet —
  `docker compose up -d` recreates it to pick up the mount (no image rebuild;
  DB/docroot volumes persist).

## Netopia payments (card payments)

`addon-netopia-payments-main` is linked like the other addons, plus the two
files a payment processor keeps outside `app/addons`: `app/payments/netopia_payments.php`
and the admin form `views/payments/components/cc_processors/netopia_payments.tpl`.
It ships responsive-theme templates only; on a nova_theme store they are linked
into nova_theme too.

1. Link it (the link script is baked into the image, so run the repo copy, or rebuild):
   ```bash
   docker compose exec app bash /repo/docker/fullstore/link-addons.sh
   ```
2. Admin → **Add-ons** → Manage add-ons → **Netopia Payments** → Install.
   Installing registers the "Netopia Payments" payment processor.
3. Admin → **Administration → Payment methods** → add a method, processor
   **Netopia Payments**. In its Configure tab set Mode = sandbox, the POS
   signature, the API key and the sandbox public/private key files. Keep real
   keys in the store only, never in the repo.
4. Check out a test booking and pay with a Netopia sandbox card; the IPN lands
   on the store, so for a public return URL use a tunnel (the store is on localhost).

## Getting your changes to show up (update & verify)

After a `git pull`, what you have to run depends on **which layer** you changed — most
addon changes need nothing but a browser refresh, because addon source is symlinked live
from your repo (`/repo`) and opcache re-checks the file every request.

| What you changed | To see it in the browser |
|---|---|
| Addon PHP (`app/addons/<id>`), Smarty `.tpl`, hand-written JS/CSS | **browser refresh** (symlinked; dev mode recompiles templates) |
| React JSX (`addon-travel-core/react-src/src`) | **`npm run build`** in `react-src/`, then refresh (regenerates the committed bundle the symlink serves) |
| Sphinx language label (`lang_keys.php` / `addon.xml`) | load any **admin page** once — it self-reseeds on a content-hash change and clears cache |
| Novoton / travel_core language, a **new DB table**, or an `addon.xml` scheme change | **re-install** the addon (Admin → Add-ons, or `install-addons.php` once it's inactive) |
| DB column covered by an addon's auto-heal map | load any **admin page** once (it runs the `ALTER TABLE`) |
| `link-addons.sh` / `entrypoint.sh` / `install-addons.php` / `php-dev.ini` / `apache-cscart.conf` / `Dockerfile` | **`docker compose up -d --build`** — these are baked into the image |
| A new `dev/` probe **file** (novoton / sphinx / sphinx_api_dev) | live (bind mount); a brand-new file → **`docker compose up -d`** |
| Provider **data** already stored (e.g. hotel coordinates from an earlier sync) | **re-run the provider sync** — see *Stale coordinates* under Troubleshooting |

Two gotchas that make people think an update "didn't take":

- **`docker compose up -d` with unchanged config is a no-op** — it doesn't recreate the
  container, so it doesn't even re-link. `docker compose restart app` *does* re-link, but it
  runs the **image-baked** copy of `link-addons.sh` (so edits to that script need `--build`).
- To be **100 % sure** you're on a clean, latest everything:
  ```bash
  git pull && docker compose down -v && docker compose up -d --build
  ```
  This rebuilds the image, wipes the DB, and re-installs every addon from scratch (costs a few
  minutes and any manual test data). A lighter pass that keeps data:
  `docker compose up -d --build` → load an admin page once → **Admin → Clear cache** →
  hard-refresh the storefront (Ctrl-Shift-R, to defeat the browser's own JS/CSS cache).

Confirm you actually pulled the code: `git rev-parse --short HEAD` should match origin, and on
a hotel page append `&travel_debug=1` and read the `[travel_debug]` object in the browser console.

## Reset

- Restart (keeps data): `docker compose restart`
- **Wipe clean** (re-provision from scratch — new DB, re-install):
  ```bash
  docker compose down -v && docker compose up -d --build
  ```

## Storefront theme (nova_theme vs responsive)

The first provision installs **nova_theme automatically when your kit ships
it** (that's the modern theme production runs), falling back to classic
`responsive`. Force one via `CSCART_THEME=` in `.env` (fresh provisions only).

Why it matters: the hotel product page's **booking form and location line**
are injected through theme hook anchors (`products:product_tabs`,
`products:main_info_title`, `products:product_detail_bottom`) that live in the
theme's product-page templates. Themes/templates that don't fire those anchors
render a bare product page even though the PHP side prepared everything.

**Switching an already-provisioned store without losing synced data:** install
and activate the other theme from **Admin → Design → Themes**, then
`docker compose restart app` (each boot re-links the addon design files, so
the newly installed theme's overlays appear), then **Admin → Settings → Clear
cache**. A `down -v` re-provision also works but wipes the DB.

> Caveat: that restart re-runs the copy of `link-addons.sh` **baked into the image**,
> not the one in your working tree. If you *edit* `link-addons.sh` itself (e.g. to link
> a newly added tree), a plain restart keeps running the old baked script — rebuild with
> `docker compose up -d --build` for the change to take effect (the DB/docroot volumes
> persist, so no re-provision).

> `sphinx_holidays` ships storefront templates for `responsive` only; its
> hotel-PDP booking form comes from shared `travel_core` templates (which
> ship for both themes), but sphinx's own search/booking VIEW pages rely on
> the theme falling back to responsive templates — verify them after a theme
> switch.

## Troubleshooting: hotel page shows no booking form / location line

Open the product page with `&travel_debug=1` appended, e.g.
`http://localhost:8080/index.php?dispatch=products.view&product_id=1&travel_debug=1`,
then press **F12 → Console** and read the `[travel_debug]` object (it also
lands in Admin → Administration → Logs, and as a visible panel when the
theme's anchors fire). Interpret it top-down:

| Symptom in `[travel_debug]` | Cause → fix |
|---|---|
| no `[travel_debug]` in console at all | travel_core's theme overlay isn't linked — `docker compose restart app`, then Clear cache |
| `product.is_hotel: false`, `provider: none` | No provider claims the product. Sphinx: `sphinx_hotels.product_id` link missing — re-run the sphinx `add_products` cron (it re-links existing products by code). Novoton: code must be `NVT<hotel_id>` with a matching `novoton_hotels` row. |
| `registered_providers` empty / missing resolver | The provider addon is inactive — activate it in Add-ons |
| `settings.show_booking_form: N` | Master kill-switch off — enable it in Travel Core settings |
| `smarty_vars.travel_booking_product_id: SET` but no form on the page | The active theme's product template doesn't fire the mount anchors — switch the theme (see above), or add the provider's *Booking Form* block to the `products.view` layout in Block Manager |
| `js_files: MISSING` | JS symlinks absent — `docker compose restart app` |
| `template_files: MISSING (…)` | Theme overlay not linked for the ACTIVE theme — restart to re-link; if it persists, the addon genuinely ships no overlay for that theme (sphinx has none for nova_theme) |

Two data gotchas that look like bugs but aren't: **novoton products are
created disabled** (`status='D'`) — enable them to see their pages; and the
location line upgrades from "city, country" to "street, city, country" only
after the novoton `geocode_addresses` cron has run.

### Stale coordinates: map pin is off / lat-lng looks rounded

If a hotel's "show on map" opens at a rounded coordinate (e.g. `36.89,30.67`) while the
Sphinx API returns full precision (`36.887069,30.674622`), the **data is stale, the code is
not**. The `latitude`/`longitude` columns are `DECIMAL(10,8)`/`DECIMAL(11,8)` and every write
goes through `HotelRepository`'s upsert as `%.8F`, so current code stores full precision — but
any row written **before** the coordinate-precision fix still physically holds the truncated
value (check it in phpMyAdmin: `cscart_sphinx_hotels.latitude` for the hotel shows `36.89000000`).

Only a **full** hotel re-sync overwrites existing rows (the upsert does
`ON DUPLICATE KEY UPDATE latitude = VALUES(latitude)`). Easiest: on the dashboard's **Cron
Commands** table (**Admin → Sphinx Holidays**), the *hotels* row has a **Run (full)** link (and
the *destinations* row has one too). Prefer the URL directly? It's the hotels cron URL with
**`&full=1`**:

```
http://localhost:8080/index.php?dispatch=sphinx_cron.run&access_key=KEY&cron_mode=hotels&full=1
```

(Add `&cron_mode=destinations&full=1` for destination-level pins.) **Heads-up:** the plain
**"Sync hotels" button is *incremental*** (`updated_since`) and will *not* re-fetch an unchanged
hotel like this one — use the **Run (full)** cron link (or the `&full=1` URL). Verify in
phpMyAdmin: the row's `latitude` flips to `36.88706900` and the PDP map opens at full precision.

## Notes & limits

- **Live provider API calls** (Novoton/Sphinx) need real credentials + network
  reachability. The seeded `enc:` test credentials exercise the store, admin,
  install/uninstall, template rendering, cron plumbing, and email — but not live
  API responses. Keep `disable_api_submission` on when testing booking-flow UI.
- **Uninstall order** is enforced by the addons: CS-Cart blocks uninstalling
  `travel_core` while `novoton_holidays`/`sphinx_holidays` are active — uninstall
  the providers first. `fgo_invoicing` is independent.
- **`eurosite`** is linked so it appears under Add-ons → Downloaded add-ons,
  but it is an MVP outside the CI gates and is NOT auto-installed: install it
  from the admin when you want to work on it, then set real API credentials in
  its settings (it ships only the spec placeholders `YourUser`/`YourPassword`).
- The kit and `.env` are gitignored; everything else here is committed so the
  whole team shares the same sandbox.
- The separate `docker/test-db/` image (MySQL-only, for CI) is unrelated and
  untouched by this.
