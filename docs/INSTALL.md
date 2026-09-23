# Fresh Install — Travel Addons (travel_core, novoton_holidays, sphinx_holidays)

Checklist for installing the travel addons into a CS-Cart store from scratch.
On a fresh install the `addon.xml` CREATE TABLEs produce the **complete,
current schema** — no upgrade items or migrations need to be applied.

## 1. Requirements

| Requirement | Value | Why / how to check |
|---|---|---|
| CS-Cart | **4.19.1 – 4.20.x** | developed/tested against 4.20.1; requires the Smarty 5 engine (CS-Cart ≥ 4.19.1) — the addons duck-type the view object and never check `instanceof \Smarty` (gone in Smarty 5) |
| PHP | **8.3+** | typed class constants, readonly properties (`composer.json` requires `^8.3`) |
| Database | **MySQL 8.0+ or MariaDB 10.6+** | all upserts use the portable `VALUES(col)` form (converted 2026-07-03; the earlier MySQL-only `AS new_row` alias is gone). Change-detection upserts proven on MariaDB 10.11. Check with `SELECT VERSION();`. |
| Theme | `responsive` or `nova_theme` | the addons ship design files for both |

## 2. Deploy the addon files

Copy (rsync) each addon's trees into the CS-Cart root, preserving paths:

```
addon-<name>/app/addons/<addon>/   → <cscart>/app/addons/<addon>/
addon-<name>/design/…              → <cscart>/design/…
addon-<name>/js/…                  → <cscart>/js/…
```

Deploy from **merged main** including the 2026-07 fixes — older code recreates
the removed `RESTRICT` foreign keys (booked hotels become undeletable) and
lacks the booking-atomicity and sync-performance fixes.

⚠️ **Always Clear cache after deploying** (Admin → Settings → Clear cache, or
delete `var/cache/templates/`): production mode does not recompile templates
when files change (`compile_check` off), so stale compiled templates keep
executing — old template bugs (e.g. the admin booking view's
`{capture}` crash) resurface even though the deployed files are fixed.
Clearing the cache also recompiles the LESS design tokens.

⚠️ **Deploys must also DELETE removed files** (`rsync --delete`, or remove
them by hand). The 2026-07 design consolidation deleted these dead/stale
files, and a copy-only deploy leaves them behind:
`design/themes/{responsive,nova_theme}/css/addons/novoton_holidays/`
`booking-engine.css`, `styles.min.css`, `booking-form-react.css`,
`booking-form-react.min.css` — the stale `booking-engine.css` in particular
was still loaded on nova_theme and **overrode the shared booking-engine
styles**; leaving it on the server keeps that bug alive.

## 3. Install order (strict)

Install from **Admin → Add-ons → Manage add-ons**, in this order:

1. **Travel Core** (`travel_core`) — shared foundation; the others declare a
   dependency on it
2. **Novoton Holidays** (`novoton_holidays`)
3. **Sphinx Holidays** (`sphinx_holidays`)

Installation runs the CREATE TABLEs and each addon's post-install setup
(novoton's `setup_db()` creates its CASCADE FKs and seeds feature aliases;
SEO defaults seed on first admin load).

**Uninstall order (reverse-ish): novoton → sphinx → travel_core.**
⚠️ Uninstalling **drops all addon tables** — hotels, packages, prices,
**bookings** — irreversibly. The novoton setting `delete_products_on_uninstall`
decides whether the CS-Cart products created for hotels are also deleted
(default `N`: products remain; re-linking after a reinstall relies on the
sync's product-code dedup).

Table teardown is expressed twice per addon — in the PHP uninstall function
**and** as `addon.xml` uninstall SQL — so tables are removed even if the PHP
hook fails to run (a `UninstallCompletenessTest` per addon enforces this). If
an **older** deployment's uninstall leaked `cscart_sphinx_*` tables, drop them
manually (all ten, including `sphinx_image_sync_queue`) and delete the
`sphinx_holidays.%` language values and `api_source='sphinx'` alias rows.

## 4. Configure (nothing works until these are set)

### travel_core (Settings → Travel Core)
- `cron_key` — the **one** cron security key, shared by every travel addon's
  cron URLs and CLI commands. Use **Travel Core → Tools → Generate key** rather
  than typing one; it creates a random 128-bit key and shows it beside the
  commands. Rotating it changes the key for Eurosite, Sphinx and Novoton too.
  (It replaced the four per-addon `cron_access_key` settings; stores that had
  them are migrated automatically on the first admin page load.)
- Exchange-rate commission (applied on top of BNR rates for RON/USD/GBP)
- **Checkout price guard** (ONE policy for all providers): `checkout_alert_percent`
  (default 20), `checkout_alert_floor` (€5), `checkout_big_overage` (€100),
  `checkout_absorb_increase` (€0 — increases above this correct the cart and
  ask the customer to re-confirm; up to it, the merchant absorbs the difference).
  Novoton's old `price_higher_threshold` setting is superseded and ignored.
- **Display settings** (ONE policy for all providers' product pages):
  `show_booking_form` (default on — kill-switch for the injected booking form)
  and `booking_form_position` (*Before Tabs* default / *After Description*).
  These moved here from novoton, where they previously had no effect.
- **Brand colors** live in the Theme Editor (Colors section, the "Travel: …"
  pickers — primary, accent, search button + hover, calendar price colors).
  The design-token bridge (`css/addons/travel_core/styles.less` → `:root
  --nvt-*` custom properties) is owned by travel_core and drives the booking
  engine, results pages and booking forms for ALL providers; chrome/status
  colors follow the active theme preset automatically. (Moved here from
  novoton 2026-07; the underlying variable names are unchanged, so colors
  merchants saved earlier still apply.)

### novoton_holidays
- **API**: `api_url`, `api_id`, `api_user` / `api_password` (+ `api_key` where
  applicable). Leave `allow_insecure_api` off.
- `commission` — markup applied to API prices
- `api_currency` — normally `EUR`
- `cron_batch_size`, `cron_max_execution_time` (the cron **key** is the shared
  one in Travel Core — see above)
- `enable_preorder_price_check` — keep **on** (re-verifies price at checkout)
- **Feature mapping**: create/select the CS-Cart product features (property
  rating, meals, hotel/room facilities, resort, property type) and set their
  feature IDs in the addon settings, then review **Admin → Travel →
  Feature mappings**. Board/room/star aliases are seeded automatically at
  install.
- Leave `disable_api_submission` **off** in production (on = bookings are
  saved locally and never sent to Novoton — test mode).

### sphinx_holidays
- **API** credentials and endpoint
- (no cron key here — the shared one lives in Travel Core, see above)
- Search settings: `cache_ttl_search`, `search_max_polls`, `default_currency`
  (`search_poll_interval` currently has no storefront effect — the browser
  polls on a fixed client-side cadence)
- `require_immediate_availability` — the availability policy, TWO effects:
  (a) storefront searches show only immediate-confirmation offers;
  (b) during the hotels sync, the **availability gate** probes each
  whitelisted destination across several windows (+14/+30/+60 days, 7 nights,
  2 adults) and flags hotels with no immediate offer in any window as
  `product_skip_reason='no_availability'` — `add_products` then skips them,
  so **only hotels with real availability become products**. The flag clears
  automatically on a later sync when the hotel becomes bookable.
- **Destination whitelist**: after the destination sync (next section), pick
  the destinations to sell in the sphinx admin — hotels sync only for
  whitelisted destinations.

Clear the cache after configuring (**Admin → Settings → Clear cache**, or
delete `var/cache/`).

## 5. Initial data sync (order matters)

Cron URL format (also runnable via CLI `php cron.php …` where provided):

```
index.php?dispatch=travel_cron.run&access_key=KEY&cron_mode=exchange_rates
index.php?dispatch=sphinx_cron.run&access_key=KEY&mode=<mode>
index.php?dispatch=novoton_cron.run&access_key=KEY&mode=<mode>
```

Calling a cron endpoint **without a mode prints its list of available modes**
— use that as the authoritative reference.

1. **travel_core**: `exchange_rates` — BNR rates for RON display prices.
   Schedule daily thereafter.
2. **sphinx**, in order:
   `destinations` → *(configure the destination whitelist in admin)* →
   `hotels` → `add_products` → `sync_images` / `process_image_queue` →
   `discover_boards` → `assign_boards` → `calendar_prices` (per-date
   "from" prices for the booking calendar; schedule daily).
   (`full` chains the main steps; `cleanup` is the recurring janitor;
   `diagnose_*` modes are read-only health checks.)
3. **novoton**: hotel/resort sync → price computation → product creation, per
   the mode list the cron prints. The backend dashboard (**Admin → Novoton**)
   shows sync status and provides manual triggers.
   - **Hotel names** are written **only** by `hotel_list` (from the API
     `<Hotel>` element, empty fallback); `hotel_info`/`facilities` never
     backfill them. A hotel that shows `(unnamed)` in the facilities cron
     output genuinely has no name yet — re-run `hotel_list` once the API
     returns one. (Run `hotel_list` before `hotel_facilities_batched`.)

Schedule the recurring jobs (typical: exchange rates + price refresh daily,
hotel sync weekly, `cleanup` daily) via real cron on the server.

## 6. Storefront checks after install

- **Default search must stay the default search.** The header Search block is
  core CS-Cart; the addons never touch it. The booking widgets are **dedicated
  block types** — in Block Manager → Add block they appear as *Novoton:
  Homepage Booking Search*, *Novoton: Booking Form*, *Sphinx: Booking Form*
  and *Sphinx: Best Deals*. They can no longer be selected as the Search
  block's template (that hijack is what previously made the product search
  box disappear).
- Hotel product page renders the React booking engine (calendar, occupancy,
  live price).
- Search-results pages and booking forms share travel_core's design system:
  `search-results.css` (offer cards, badges, skeleton), `booking-pages.css`
  (booking-form summary/guest cards/terms/inputs) and the `styles.less` token
  bridge. BOTH providers render with it — novoton's results migrated onto
  the shared cards in 2026-07 (its desktop table became the same 3-zone
  cards sphinx uses; provider-specific bits live in
  `novoton_holidays/novoton-results.css`), and both providers' booking FORMS
  followed: the `.travel-booking-page` classes give one look with colour-coded
  guest cards, keyboard focus rings + required-field indicators on inputs, a
  single `.travel-btn--primary` button (one brand blue across engine, results
  and forms), and disabled/loading submit states. novoton's booking form no
  longer ships an embedded `<style>` block.
- Add to cart → checkout completes; the booking row appears in the addon's
  bookings admin with the API confirmation (or a clear failure status).
- Currency switcher shows sane RON/EUR prices (exchange-rate cron ran).

## 7. Developer verification (optional but recommended)

```bash
composer install && composer check          # PHPStan L10 + cs-fixer + rector + unit suites
npm ci && npm test                          # vitest (jsdom) — behavioral tests for the booking JS
docker compose -f docker-compose.test.yml up -d db   # CS-Cart test DB (MySQL 8)
cd addon-novoton-holidays/app/addons/novoton_holidays
DB_DSN='mysql:host=127.0.0.1;port=3307;dbname=cscart' DB_USER=cscart DB_PASS=cscart \
  vendor/bin/phpunit -c phpunit-integration.xml       # booking + currencies write-path tests
```

The integration suite needs the pre-baked CS-Cart test-DB image. In CI the
`PHPUnit Integration (novoton)` job stays **skipped** until a maintainer
completes the one-time setup (build + push the image, add the
`GHCR_READ_TOKEN` secret, set `INTEGRATION_TESTDB_READY=true`) — the exact
runbook, including the `gh secret set` / `gh variable set` commands, is in
`docker/test-db/README.md` under "One-time repo setup".

## 8. Future schema changes (post-install rule)

Every schema change must land in **both** places: the `addon.xml`
CREATE TABLE (fresh installs) **and** the addon's self-heal path
(installed sites): travel_core's `fn_travel_core_ensure_schema()` or
sphinx's `Install\SchemaMigrator` column/index maps. The self-heals run
automatically on the first admin request after a deploy (stamp-gated in
`?:storage_data`, re-armed whenever the defining file changes), so no
manual deploy-checklist step is needed. (Historical context:
`docs/archive/AUDIT_2026-07-01.md`, roadmap item #2.)
