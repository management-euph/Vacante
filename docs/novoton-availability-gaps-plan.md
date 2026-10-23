# Novoton hotels with availability gaps — plan

**Status:** Proposed 2026-10-16. Decisions recorded 2026-10-17 (§0). Phase 0 done.
**Scope:** the rules live in `travel_core` and Novoton is the first provider to
use them; Eurosite and Sphinx move onto them later (§0.2, `docs/CONVERGENCE.md`).
**Related:** `docs/adr/0001-availability-early-render-and-metrics.md`, Eurosite
`ProductGate` / `AvailabilityPlan`, Sphinx `HotelAvailabilityGate`.

Paths are relative to `addon-novoton-holidays/app/addons/novoton_holidays/`
unless they start with another addon's folder.

---

## 0. Decisions (2026-10-17)

### 0.1 What was decided

| Question (§11) | Decision |
|---|---|
| Hotels between seasons | **Stay visible**: Active and listed, with the season panel (§7.1). |
| When to hide a hotel with no availability | **An admin setting**: "Hide a hotel after N months without availability", default **12**. |
| Sell hotels Novoton doesn't offer offline ("Ask us for a price") | **No, not for now.** Those hotels show "online booking unavailable", similar hotels and "notify me"; staff only get the purchasing list (§7.2). |
| Where the logic lives | **The rules in Travel Core, the data collection in Novoton** (§0.2). |
| How long to stay report-only | **The admin switches it by hand** after reviewing the "would hide" list once, around Feb–Mar 2027 when the 2027 early-booking prices are loaded (§0.3). The switch is a Travel Core setting: *Hotel availability → Mode: Off / Report only (default) / Automatic*. |

| Hotels that left Novoton's list (3 runs in a row) | **Automatic mode:** hidden automatically. **Report only:** listed for the admin to decide (decided 2026-10-17). |
| Closed hotels | **301 to the resort page by default** after 6 months; the admin can choose another target per hotel. |
| "Notify me" | **Email only.** Consent text in §8. |
| The batched syncs refreshing far more than designed (§13) | **Fixed now**, in phase 0. |
| Does "on request" count as availability? | **Yes** (decided 2026-10-21, §15.6). |
| One mode for the store, or one per supplier? | **A mode per supplier** (decided 2026-10-21). |
| Eurosite's and Sphinx's own gates until each switches | **Kept, after the phase 0b fixes**; the report compares them (decided 2026-10-21). |
| How often to check, and with which crons? | **Weekly per resort, with the crons that already run; no new cron** (decided 2026-10-22, §4.5). |

Nothing in §11 or §15.6 is open any more.

### 0.2 Architecture: the rules in Travel Core, the data in Novoton

| Travel Core (shared, one behaviour for the store) | Novoton (provider-specific) |
|---|---|
| Settings: hide after N months (default 12); a mode per supplier, off / report / enforce (§15.3); left-the-feed runs; redirect grace; mass-hide brake | Collecting evidence: still in `hotel_list`, published price window (priceinfo → calendar), resort `room_price` checks, offers on hotel-page searches |
| The evidence and state table, keyed by provider + hotel id + product, and the change log | The weekly resort check that feeds it: the existing `room_price` job, reworked (§4.3) |
| The state rules (a pure policy) and the date windows (moved from Eurosite's `AvailabilityPlan`) | Novoton's own data fixes (phase 0) |
| The gate: guarded Active ↔ Hidden flips, brake, overrides, `gateHidden` for booking | |
| The hotel-page panel, "notify me", and the "prices are out" emails | |
| The 301 for closed hotels, and the Availability admin page with a provider filter | |

Why Travel Core:

1. **One rule for the whole store.** Today Eurosite hides hotels between
   seasons, Sphinx hides even more (it checks near dates only), and Novoton
   hides none. A customer does not care which supplier a hotel comes from, and
   the "keep them visible" decision should hold for all three.
2. **Travel Core already owns what this plugs into:**
   - the cart rule that lets a gate-hidden product be booked (`ProviderCartProduct`);
   - the hotel-page booking engine and JSON-LD;
   - availability requests and their admin grid;
   - the cron framework;
   - a runtime schema self-heal, which Novoton lacks (audit `data-perf-3`).
3. **Building it in Novoton and moving it later** means building twice and
   migrating a table, settings and an admin page.

Novoton still goes first: it is the only provider wired at the start, and
Eurosite and Sphinx keep their current gates until they are moved over
(phase 6). New shared code follows `docs/CONVERGENCE.md`: the contract in
`travel_core/src/Contracts` first, then the pure policy, with
`ConvergenceLedgerTest` updated.

### 0.3 What "report-only" means

In report-only mode the job works out every hotel's state and lists what it
**would** hide or show (on the Availability page and in the weekly email), but
it changes no product. It is a dry run, so the rules can be checked against
real data before they act on the catalog.

"How long" is when to flip the mode setting to *enforce*. The recommendation is
February–March 2027:

- the job starts recording offers now, so by then it has months of
  observations instead of guesses;
- by then a hotel still without offers or prices is a real candidate, whereas
  in October almost every seaside hotel looks empty;
- with a 12-month threshold, little would be hidden before then anyway.

Review the "would hide" list once, switch the setting, and from then on it runs
by itself.

---

## 1. Summary

- A hotel with no offers today is usually healthy. Seaside hotels sell
  May–October, and next season's prices arrive between November and February.
  Hiding or deleting such hotels would throw away SEO and early-booking demand.
  "No offers" should be a **state with a reason**, not a one-off event.
- Keep three things apart:
  1. **Evidence**: what Novoton showed us, and when. The hotel was in the feed,
     prices were published for dates X–Y, a live offer was seen for stay date Z,
     or a booking was made.
  2. **State**: what the evidence means. The states are *bookable*, *between
     seasons*, *not offered*, *dormant*, *left the feed* and *closed*.
  3. **Product visibility**: the CS-Cart status, Active / Hidden / Disabled.
- Record evidence from the jobs that already run (no extra API calls). Turn
  the existing `room_price` job into a weekly check of future dates per resort,
  including next summer's peak dates. No new cron (§4.5).
- Add an admin page **Hotel availability** (Travel Core, with a provider
  filter). It filters on "no offer in the last X months" and has CSV export and
  bulk actions.
- Only *dormant* hotels (no offer for N months; an admin setting, default 12)
  and hotels that *left the feed* are hidden. Hidden (status H) keeps the page
  and URL but drops the hotel from listings. A closed hotel gets a 301 redirect
  after a grace period. Nothing is ever deleted or Disabled.
- The hotel page never ends in a dead end. It can show a season notice, "notify
  me when 2027 prices appear", and similar hotels that can be booked now.
- Run in report-only mode first. Turn on automatic hiding only after the report
  has been checked against the 2027 early-booking season.

## 2. The four situations

| | Between seasons | Not offered by Novoton | No offer for 12+ months | Closed / left Novoton |
|---|---|---|---|---|
| Typical cause | Season over; next season's prices not loaded yet (Oct–Feb) | Hotel operates, but Novoton has no contract or allotment, or a stop sale | The hotel missed a whole selling year | Hotel closed, the contract ended, or Novoton removed it from `hotel_list` |
| How we recognise it | No offer now. Offers or prices within the last 13 months. The other hotels in the resort have none either. | Other hotels in the resort sell the same dates; this one hasn't for 2+ weeks | No offer seen for N months (admin setting, default 12) | Missing from `hotel_list` 3 runs in a row (~9 days), or an admin marks it closed |
| Product | Stays Active | Stays Active | Hidden | Hidden, then a 301 after 6 months |
| Customer sees | "Summer 2027 prices are not published yet. Last season: Jun–Sep", plus Notify me and similar hotels | "Online booking is not available right now", plus similar hotels and Notify me | Direct link only: "No offers for over a year", plus similar hotels and Notify me | "No longer available", plus similar hotels; redirected later |
| Staff | Nothing to do | Purchasing list for Novoton | Review list: keep, wait, or close | Choose the redirect target |

## 3. What the addon does today

### Novoton

- **No hotel status.** `?:novoton_hotels` has no active, deleted or visibility
  column (`addon.xml:407-441`).
- **A hotel that leaves the feed goes unnoticed.**
  - `hotel_list` only inserts and updates the hotels it returns, and stamps
    `hotel_list_synced_at` on them (`src/Cron/Commands/HotelListSyncCommand.php:49-92`).
  - A missing hotel keeps its row, its product and its syncs.
  - Only whole resorts are flagged as "gone", when they are 2 days behind their
    country (`src/Services/DestinationsPage.php:22,103`). This shows up only as a
    dashboard alert.
- **The only live availability flag is `has_room_price`.**
  - It comes from a single check at today+30 days, 7 nights, 2 adults
    (`src/Cron/Commands/RoomPriceCheckCommand.php:44-51`).
  - Failed calls are also stored as "no price" (`:108-135`). In October every
    seaside hotel therefore reads "no price".
  - The storefront never reads this flag; only product creation does.
- **Future prices already exist, but nothing customer-facing uses them.**
  - `calendar_prices_raw` holds the cheapest price per date from today to
    +18 months, built from the priceinfo seasons
    (`src/Services/CalendarPriceBuilder.php:44-46`).
  - NULL means there is no future published price.
  - Nothing uses it to tell customers when the hotel opens.
- **Products.**
  - Every creation path creates them with status D and price 0
    (`src/Cron/Commands/AddProductsCommand.php:203-211`). An admin activates them by hand.
  - No code hides or disables a product because of availability.
  - The only status change in the addon is the whitelist's confirmed
    "disable outside" step.
- **The "from" price can be stale.** It is the cheapest `min_price` across all
  seasons, including past ones (`PriceComputeCommand::extractMinPrice`).
- **Hotel page when nothing is found.**
  - A ±10-day scan runs, capped at 50 API calls (`src/Services/AlternativeDateSearcher.php`).
  - Then a "No Availability" box appears with a "Notify me when available" button.
  - That button actually sends Novoton a `hotel_request` asking staff for
    alternatives (`src/Services/AlternativeRequestService.php`). Nobody tells the
    customer when the season opens.
  - The season note uses a language key that doesn't exist
    (`novoton_holidays.accommodation_period`,
    `addon-novoton-holidays/design/themes/responsive/templates/addons/novoton_holidays/views/novoton_booking/search.tpl:636-648`),
    so the raw key prints instead.
  - The season dates it needs are usually empty, because the readers disagree on
    the season field names.
- **Date picker:** only past days are blocked. There is no maximum date and no
  out-of-season hint on the hotel page (`addon-travel-core/react-src/src/Calendar.jsx`).
- **Listings:** a hotel with offers looks exactly like one without.
- **Deleting or disabling products is unsafe.**
  - Deleting a product deletes its Novoton bookings
    (`hooks/product_hooks.php:92-108`, audit `data-perf-1`).
  - A Disabled product makes deposit balances unpayable (audit `core-sphinx-money-7`).
- **Schema changes don't reach existing stores.** New tables and columns need a
  runtime heal, which Novoton lacks (audit `data-perf-3`).

### Prior art to reuse

- **Eurosite `ProductGate`** (`addon-eurosite/app/addons/eurosite/src/Services/ProductGate.php`):
  - hides with H, not D;
  - flips are guarded: only A→H, and H→A only for products it hid itself
    (its own `gate_hidden` column);
  - a manual status change releases the product from the gate;
  - it acts only on destinations whose check answered.
- **Eurosite `AvailabilityPlan`** (`.../src/Services/AvailabilityPlan.php`):
  - checks near dates (14/30/60 days) and also peak-season dates such as
    "07-15, 08-15" (the next occurrence of each).
  - Its comment records why: for 23–30 Oct only 50 of 500 hotels had an
    immediate offer, so near-date checks alone would hide almost every seaside
    hotel from autumn to spring.
- **Sphinx `HotelAvailabilityGate`** — two mistakes not to copy:
  - It checks near dates only (+14/+30/+60), so it hides seaside hotels every autumn.
  - Its flag shares `product_skip_reason`, so `add_products retry_skipped=1` can wipe it.
- **travel_core:**
  - `ProviderCartProduct::canBeBought` already lets a provider's gate-hidden (H)
    product be booked.
  - `travel_alternative_requests` and `AlternativeRequestNotifier`.
  - The cron framework: auto-discovery, run lock, run log.
  - `DestinationPicker`.
- **Missing everywhere:**
  - availability history;
  - "closed for the season / reopens" messages;
  - notify-on-reopen;
  - redirects for removed hotels;
  - a brake against hiding too many products at once;
  - any shared hotel identity across providers.

## 4. Recording availability ("record the last availability, check future availability")

### 4.1 Evidence kept per hotel

New Travel Core table `?:travel_hotel_availability` (§0.2), one row per
provider hotel, keyed by `provider` + `hotel_id`, with the hotel's
`product_id`. Being a separate table, the busy `novoton_hotels` needs no ALTER,
and Travel Core's runtime schema self-heal brings it to installed stores. The
columns below are what Novoton fills; other providers fill what they can.

| Column | Meaning | Written by |
|---|---|---|
| `destination_key` | Resort, city code or destination id: the peer group for rule 5 (§5) | Each supplier |
| `known_since` | When the supplier first listed the hotel | Each supplier (Novoton: `novoton_hotels.created_at`) |
| `listed_last_seen_at` | Last `hotel_list` run that returned the hotel | `hotel_list` |
| `listed_missing_runs` | Consecutive good runs without it | `hotel_list` |
| `contract_from`, `contract_to` | First and last future date with a season price (NULL = none) | After `recompute_calendar_prices` / `compute_prices` |
| `contract_last_date` | Latest priced date ever seen (keeps past seasons) | Same, plus the backfill |
| `offer_seen_at` | **Last time we saw a bookable offer** ("on request" counts) | `room_price` (§4.3), hotel-page searches, bookings |
| `offer_stay_date` | Check-in date of that offer | Same |
| `immediate_seen_at` | Last offer that could be booked at once, for the setting that stops "on request" counting | Same |
| `offer_next_date` | Earliest future check-in with an offer in the latest check | `room_price` |
| `offer_checked_at` | Last check that answered for this hotel, offer or not | `room_price` |
| `offer_months` | JSON: stay months with offers → when last seen, e.g. `{"2026-07":"2026-06-28"}` | Same |
| `backfilled_at` | Past seasons filled in from stored data (§4.4); records then count as covering the months before | The backfill |
| `last_booking_check_in` | Check-in of the latest confirmed booking | The weekly Travel Core step (§4.5), from the provider's bookings (`novoton_bookings`) |
| `state`, `state_since`, `state_reason`, `season_published` | Derived state (§5), its reason code, and whether next season is already priced or offered | The weekly Travel Core step (§4.5) |
| `gate_hidden` | This gate set the product to H | The gate |
| `override`, `override_note`, `override_by`, `override_at` | Admin decision: keep visible / keep hidden / closed | Admin page |
| `redirect_to` | For closed hotels: target product, category or URL | Admin page |

A second table, `?:travel_hotel_state_log`, records every change: provider,
hotel, product, old → new state and status, reason, who, and when. Every change
can then be explained, and the log feeds the weekly email.

"When was it last available" has two answers, and both are kept:

- `offer_seen_at` is **when we last saw it sellable**. It drives "no offer in
  the last X months".
- `offer_stay_date` / `offer_months` are **which stays were sellable**. They
  drive "last season: June–September".

### 4.2 Passive capture (no extra API calls)

- **hotel_list:**
  - Stamp every hotel the run returns.
  - Increment `listed_missing_runs` for the country's other hotels, but **only**
    when that country's answer is complete: no exception, not empty, and at least
    90% of the previous count.
  - Today an exception aborts the whole run (the call at
    `HotelListSyncCommand.php:49` is outside any try/catch), and an empty answer
    is skipped.
  - Both cases must count as "no information", never as "missing".
- **Calendar:** whenever `calendar_prices_raw` is rewritten, set
  `contract_from/to` from its first and last key, and move `contract_last_date`
  forward.
- **Hotel-page searches:**
  - When a search returns offers, record them (`HotelAvailabilitySearcher` /
    `MultiRoomSearchBatcher` via `controllers/frontend/novoton_booking/search.php`).
  - Record positives only: an empty result for one occupancy proves nothing.
  - One UPDATE, at most once per hotel per hour.
- **Bookings:** `MAX(check_in)` over confirmed bookings, read by the weekly
  Travel Core step (§4.5).

### 4.3 Active check: the existing `room_price` job, reworked

This check asks what a hotel offers in the future, not just next week. It
needs no new cron: the existing `room_price` job (`0 4 * * *`) does it,
on its existing line (decided 2026-10-22, §4.5).

- **Dates checked** (the Eurosite rule; all are settings):
  - near days `14, 30`;
  - season dates `06-20, 07-15, 08-15, 09-05`, each meaning its next occurrence;
  - for a hotel with a future `contract_from` and no offer yet, one stay in its
    first published week.
  - Every stay is 7 nights, 2 adults.
- **API call:**
  - Use the resort-level `room_price` (`PricingApiClient::getRoomPriceByResort`,
    already used by the admin's "Check Prices"). One call per resort per date
    returns every hotel that has a price.
  - A full pass costs resorts × dates calls: for example, 70 sold resorts ×
    6 dates ≈ 420 calls.
  - Use the per-hotel `room_price` only for hotels a resort answer can't cover,
    such as a missing or mismatched city.
- **Schedule: weekly per resort.**
  - Each night's run checks one seventh of the resorts on every date, least
    recently checked first, so every resort is checked once a week: about 60
    calls a night, where today's job makes up to 500 (one per hotel).
  - No daily check of the near dates: the rules count weeks and months (§5),
    and what can be booked today is the live search's job on the hotel page.
  - A time budget (`max_time`, default 20 minutes) ends the run before
    `add_hotels_as_products` at 04:30; resorts it did not reach go first the
    next night. The run lock and the run log stay as they are.
- **`has_room_price` stays.** It now means "an offer on any checked date in
  the hotel's latest check", and `add_hotels_as_products` reads it as before.
  Because next summer's dates are checked, seaside hotels can also become
  products in winter; today's check, 30 days ahead, finds no price for them out
  of season.
- **Only answers count.**
  - A resort whose call failed changes nothing: no evidence lost, no "no offer"
    written.
  - This avoids the problem at `RoomPriceCheckCommand.php:108-135` and audit
    `eurosite-platform-3`.
- **Reuse the date logic instead of copying it.** Move Eurosite's pure
  `AvailabilityPlan::parseDays/parseSeasonDates/windows` into travel_core (for
  example `Services\AvailabilityWindows`) and use it from both addons, as
  `docs/CONVERGENCE.md` prescribes.
- **Classifying and hiding are not part of this job.** The weekly Travel Core
  step does them for every supplier (§4.5).

### 4.4 Backfill on the first run

Done once by the reworked `room_price`, before its first checks: stored data
only, no API calls, resumed the next night if the time budget runs out.

- **`contract_last_date`** comes from the stored `priceinfo_data` of every
  package.
  - Package rows are never pruned (`src/Helpers/BatchedHotelInfoSyncV2.php:440-454`),
    so past seasons are still there.
  - Reuse `CalendarPriceBuilder`'s season reading, which accepts every season
    shape, but without its today-clamp.
- **`last_booking_check_in`** comes from bookings, and `listed_last_seen_at` from
  `hotel_list_synced_at`.
- **Mark backfilled values in the report.** "No offer in the last 12 months"
  only becomes fully reliable once the job has run through a selling season.
  Until then, "last availability" uses the best evidence there is: the last
  offer, else the last priced date, else the last booked stay.

### 4.5 Which cron does what: no new crons, no overlaps

Decided 2026-10-22: availability is checked **weekly**, with the crons that
already run. Nothing is urgent about it: the rules count weeks and months, and
the hotel page always searches live. No new crontab line is needed. The only
job that changes is Novoton's `room_price`, on its existing line.

| Job (existing line) | What it records for this plan | Extra API calls |
|---|---|---|
| Novoton `hotel_list` (`0 2 */3 * *`) | Seen in / missing from the list (§4.2) | None |
| Novoton `recompute_calendar_prices` (`30 3 * * *`) and `compute_prices` (every 5 minutes) | The published price window (§4.2) | None |
| Novoton `room_price` (`0 4 * * *`), reworked | The weekly resort check and `has_room_price` (§4.3); the backfill, once (§4.4) | Fewer: about 60 calls a night instead of up to 500 |
| Novoton `add_hotels_as_products` (`30 4 * * *`) | Nothing new; it reads `has_room_price` as today | None |
| Eurosite `hotels` (`0 3 * * *`, or inside `full`) and `availability` (`30 4 * * *`) | Seen in the list; the per-city answers its gate already fetches (phase 1b) | None |
| Sphinx `hotels` (daily; 02:00 on its settings tab, 03:00 on its dashboard, §14 item 14) | List completeness; the destination answers its gate searches already fetch (phase 1b). Only if needed: a season-date search for one seventh of the destinations with products each night (§15.3, point 9) | None, or that search |
| Hotel-page searches (customers, no cron) | Offers seen (§4.2) | None |
| Travel Core's daily cron (the `exchange_rates` line) | Every day: shows again the hotels the gate hid that have an offer (Automatic mode only). Once a week: bookings read back, the classification (§5), the report and the weekly email; in Automatic mode, hiding (§10) | None: stored records only |

Rules, so that the jobs never overlap:

1. **No new crontab lines.** A new line only if one of the jobs above cannot
   carry its part, and then with a slot chosen against this table, in
   `CronPlanBuilder`'s `SCHEDULES` (Novoton) or Travel Core's mode lists.
2. **One job per kind of record, one writer per product.** Only `room_price`
   makes Novoton's availability calls (the admin's "Check Prices" stays a
   manual tool). Only the list jobs decide seen or missing. Only the Travel
   Core step classifies, and changes the status of a supplier's products once
   that supplier is in Automatic mode. Until then the supplier's own gate is
   the only one that changes them; switching turns it off (§15.3, point 8).
3. **A job never runs twice at once.** Every job keeps its per-job run lock
   (`CronRunLock`), which also stops a manual "Run now" from overlapping a
   scheduled run. The Travel Core step gets a lock of its own. One gap to
   close in phase 1b: Eurosite's `full` runs its `hotels` step without that
   step's lock (Sphinx's `full` takes each step's lock), so a `full` still
   running at 03:00 can overlap a scheduled `hotels`.
4. **Order within the night:** `room_price` ends before
   `add_hotels_as_products` at 04:30 (its time budget, §4.3).
5. **The Travel Core step does not depend on the clock.** It calls no supplier
   API, and it skips while a job that writes records is running: the jobs in
   the table, and a `full` run that includes one of them (each supplier names
   them in the contract). Travel Core's run log shows which are running, and a
   stalled run does not block it. It then tries again on its next daily run.
   The weekly part runs once 7 days have passed since its last complete run.
6. **No more API traffic at 04:00 than today.** `offers_update` (every 2 hours,
   so also at 04:00) and `resinfo` (04:15) are unchanged, and `room_price`
   makes far fewer calls.
7. **Visible on its own.** The step records its runs as `hotel_availability`,
   so Travel Core → Tools shows its health apart from the exchange rates, and
   one failing never stops the other. The Availability page shows when it last
   ran, and warns when Travel Core's cron has not run.

## 5. Classifying hotels

The classifier is a pure class with no database and no clock, like
`AvailabilityPlan`. For example:
`Services\AvailabilityPolicy::classify($evidence, $resortStats, $settings, $today)`.
It is unit-tested, including the October case. The rules are checked in order,
and the first match wins:

1. Admin override `closed` → **closed**.
2. Offer seen in the last 8 days (one weekly check plus a day's slack) →
   **bookable**. "On request" counts.
3. Missing from `hotel_list` for 3 good runs → **left the feed**. After 6 months
   it is proposed as **closed**.
4. Last availability (§4.4) is older than N months (the admin setting, default
   12), the hotel has been known and recorded for at least that long (or its
   records were backfilled), and the latest check of its resort answered →
   **dormant**. Last availability is the latest of: the last offer, its stay
   date, the latest priced date and the latest booked stay, so a hotel priced
   for next summer is never dormant.
5. The resort is selling but this hotel has had no offer for 14 days →
   **not offered**. "Selling" means at least 25% of at least 5 checked hotels had
   offers on the same dates in the last 2 weekly checks.
   - If the hotel had offers in the last 30 days, label it *sold out* instead.
     It is the same state and the same panel.
   - Under 14 days it stays **bookable**: one empty weekly check is not enough
     (decided while building it, 2026-10-23).
6. An offer, a published price or a booked stay within the last 13 months, or
   prices published for a future date → **between seasons**. A flag records
   whether next season is already published.
7. Anything else → **no data yet**.

Rule 5's resort comparison is what tells "the whole coast is closed" apart from
"Novoton isn't selling this hotel":

- **October:** no Sunny Beach hotel has offers, so all of them are between seasons.
- **July:** if 60 of 80 hotels have offers and one has had none for two weeks,
  that one is not offered.

Every threshold is a setting. `dormant_months` is the one admins see in Travel
Core's settings, as "Hide a hotel after N months without availability"
(default 12, decided 2026-10-17); the others can stay advanced settings:

- `fresh_days` 8
- `delisted_runs` 3
- `dormant_months` 12 (admin setting)
- `season_memory_months` 13
- `peer_share` 25%
- `not_offered_days` 14
- `closed_redirect_months` 6

**Example, as of 16 Oct 2026:** a hotel that sold in August 2026 stays "between
seasons" until August 2027 at the earliest. The automatic rules cannot hide it
this winter.

## 6. Report: hotels without availability in the last X months

A new Travel Core admin page, **Hotel availability**, with a provider filter,
its own controller (`travel_core` `controllers/backend/travel_hotel_availability.php`),
template, menu entry and permission entry (audit `web-security-9`: the other
travel pages lack one). Novoton's `hotels` / `view_hotel` modes in
`controllers/backend/novoton_holidays.php` have no templates and are never
rendered; they can go.

- **Filters:**
  - "No offer seen in the last [3 / 6 / 12 / 18 / 24] months";
  - state;
  - country / resort;
  - product status;
  - has bookings;
  - has an override.
- **Columns:**
  - hotel (linked to its product) and resort;
  - product status;
  - state and since when;
  - last offer seen (date and stay month), and next offer date;
  - prices published (from–to);
  - last seen in the feed, with missed runs;
  - last booking;
  - page views (CS-Cart product popularity);
  - open notify-me requests;
  - suggested action.
- **Bulk actions:**
  - hide, show, keep visible;
  - mark closed, set redirect target;
  - clear override, export CSV.
- **Dashboard tile:** counts per state, plus an attention line when hotels are
  waiting for a decision.
- **Weekly email**, from the weekly Travel Core step (§4.5), as an editable
  email template (§15.5):
  - hotels newly dormant, newly out of the feed, or back on sale;
  - not-offered hotels that have demand;
  - "notify me" emails sent.

The core query:

```sql
SELECT h.hotel_id, h.hotel_name, h.city, h.product_id, p.status,
       a.state, a.offer_seen_at, a.offer_stay_date, a.contract_from, a.contract_to,
       a.listed_last_seen_at, a.last_booking_check_in
FROM ?:novoton_hotels h
LEFT JOIN ?:travel_hotel_availability a ON a.provider = 'novoton' AND a.hotel_id = h.hotel_id
LEFT JOIN ?:products p ON p.product_id = h.product_id
WHERE h.created_at < NOW() - INTERVAL ?i MONTH          -- known long enough to judge
  AND (a.offer_seen_at IS NULL OR a.offer_seen_at < NOW() - INTERVAL ?i MONTH)
ORDER BY a.offer_seen_at IS NULL DESC, a.offer_seen_at
```

When reading the report, X under 12 months answers a season question, not a
health question. In January, "no offer in the last 3 months" matches every
seaside hotel.

## 7. What happens in each situation

### 7.1 Between seasons (next season not published yet)

- **Product:** stays Active and listed (decision 1 in §11).
- **Hotel page:** a state panel appears above the search, with:
  - "Prices for summer 2027 are not published yet." and "Last season:
    June–September."
  - **[Notify me when prices appear]**;
  - up to 3 similar hotels that can be booked now (same resort, ±1 star).
    They are picked from the evidence table, so no extra API calls.
- **When the next season appears** (an offer is seen, or prices are published):
  - every waiting "notify me" request gets one email linking to the hotel;
  - the request is then closed.
- **Date picker:**
  - once the season is published, it opens on the first published date instead
    of next week;
  - dates outside the published window are marked.
- **Listings (optional):** a label such as "2027 prices soon" or "Bookable until
  Sep 2027", taken from `contract_to`.

### 7.2 Not offered by Novoton (the hotel has rooms, but we can't sell it)

- **Product:** stays Active for SEO and demand.
- **Hotel page:** a panel above the search, with:
  - "Online booking for this hotel is not available at the moment.";
  - similar hotels that have offers;
  - **[Notify me when it can be booked]**.
- **No offline sales for now** (decided 2026-10-17): no "Ask us for a price"
  form. It can be added later on the same request table (`request_type`
  `quote`).
- **Purchasing list:** not-offered hotels sorted by demand (views and notify-me
  requests), exported as a CSV for the Novoton account manager to request
  allotment or a contract.
- **The same hotel through another supplier:**
  - Today every product belongs to one provider. There is no shared hotel
    identity: Sphinx exposes a GIATA id, but it isn't stored.
  - Short term, an admin can point the redirect at the Sphinx or Eurosite
    product for the same hotel.
  - Long term, store GIATA ids and match hotels across providers.
- **After N months** (default 12) still not offered, the hotel becomes dormant
  (§7.3).

### 7.3 No availability for N months or more (dormant; admin setting, default 12)

- **Product:**
  - A → H, only if the product is Active, only by the gate, and with
    `gate_hidden = Y` set.
  - The page and URL stay, but the hotel drops out of category lists and search.
- **Booking from a direct link** remains possible if a live search finds an
  offer. Pass the gate flag from `HotelCartProduct` to `ProviderCartProduct`, as
  Sphinx and Eurosite already do. That search also records the evidence that
  reactivates the hotel.
- **Hotel page:** "This hotel has had no offers for over a year.", plus similar
  hotels and **[Notify me if it returns]**.
- **Checks** continue weekly on the season dates. The first offer makes the
  product Active again automatically, at the next daily Travel Core run (H → A,
  only if the gate hid it; §4.5).
- **Review list** on the Availability page: keep visible, wait, or mark closed.

### 7.4 Closed / no longer offering accommodation

- **Detection:**
  - missing from `hotel_list` for 3 good runs (about 9 days on the current
    every-3-days schedule) → *left the feed*;
  - or an admin marks it closed, for example from news, the hotel itself, or
    Novoton.
- **Left the feed:**
  - Hide it at once (H): Novoton can no longer sell it.
  - Panel: "No longer available for booking", plus similar hotels.
  - Remove it from the per-hotel syncs (hotelinfo, priceinfo) in
    `DestinationScope::syncWhere`. Keep the weekly check in case it comes back.
- **Closed:**
  - After 6 months hidden (or at once, if an admin chooses), redirect with a 301.
  - Redirect target, in order: the same hotel at another supplier → a similar
    hotel → the resort category (default).
  - Redirect only when the product has no future bookings and no unpaid
    balances.
- **How the 301 works:**
  - A Travel Core frontend controller, `controllers/frontend/products.pre.php`,
    finds the product's provider (`TravelProviderRegistry::resolveProductOwner`)
    and redirects `products.view` for a closed hotel with
    `fn_redirect($url, false, true)`. The third argument means permanent;
    confirm it on 4.21.2.
  - The product stays Hidden. It is never Disabled or deleted, so bookings,
    balances and order history keep working (audits `data-perf-1`,
    `core-sphinx-money-7`).
  - Removing the redirect only means clearing the state, so a hotel that returns
    is easy to restore (an admin confirms).
- **Never** answer 404 or 410 for a hotel page that has backlinks, and never
  delete products.

## 8. Hotel page and listings: what changes

| Today | After |
|---|---|
| Empty search → "No Availability" + ±10-day scan | A state panel first; the search stays available underneath |
| "Notify me when available" really sends a staff alternatives request | Two clear actions: "Notify me when prices appear" (a watcher) and "Ask us for alternatives" (the existing Novoton request, now with working emails) |
| The season note prints a raw language key | Fixed key; dates from the evidence (`contract_from/to`, or last season's months) |
| The date picker opens on next week, with no limits | Opens on the first published date; dates outside the window are marked |
| Every hotel in a listing looks the same | Optional labels, and bookable hotels first (phase 6) |

**Lead storage:**

- Add `request_type` (`alternatives` · `notify`; `quote` reserved for later)
  to `novoton_alternative_requests` and to its travel_core mirror. The existing
  "Availability Requests" grid then shows and filters them.
- Add the mirror table to travel_core's schema self-heal (audit `data-perf-2`).

**Consent text** (decided 2026-10-17: email only, a generic text). It sits
next to an unticked checkbox that must be ticked to send the form. The request
stores the time of consent and the text's version, and the link goes to the
store's privacy policy page.

- EN: "I agree to receive one email when booking opens for this hotel. My
  email address is used only for this and is deleted once the email is sent,
  or after 12 months at the latest. See the Privacy Policy."
- RO: „Sunt de acord să primesc un singur e-mail când se deschid rezervările
  pentru acest hotel. Adresa mea de e-mail este folosită doar în acest scop și
  este ștearsă după trimiterea e-mailului sau cel târziu după 12 luni. Vezi
  Politica de confidențialitate."

A notify-me request is therefore kept until its email goes out, or for 12
months at most, longer than the 30 days alternative requests get.

**New language keys** go in EN and RO, in the owning addon's seed file and both
`.po` files (Novoton: `lang_keys.php`, pinned by `LangSeedParityTest`). They
cover:

- season not published yet;
- last season;
- notify me;
- not offered;
- no offers for over a year;
- no longer available;
- similar hotels.

## 9. SEO

- **Between seasons / not offered:** nothing changes. The page stays Active,
  returns 200, and keeps travel_core's JSON-LD `Hotel` (which has no `offers`
  block, so no "out of stock" mismatch). No noindex.
- **Dormant / left the feed:** Hidden. The page returns 200 with its content
  plus alternatives, and drops out of listings and internal search.
  - Check on 4.21.2 that Hidden products are left out of the sitemap. The repo
    has no sitemap or noindex handling of its own.
- **Closed:** a 301 after the grace period. Never a 404; never a delete.
- **Reactivation** removes the redirect, and the page comes back under its old URL.

## 10. Safety rails

- **Mode setting:** off / report (default) / enforce.
  - Report mode computes everything and emails a "would hide / would show"
    list, without touching any product.
- **Only answered checks count**, per resort (the Eurosite rule). A refusal
  (`Api\Refusal`, e.g. a wrong password), a non-XML reply and an empty body
  are not answers.
- **Brake against mass hiding:** if a run would hide more than max(10 products,
  5% of visible Novoton products), it hides none and emails the admin. Neither
  existing gate has this.
- **Guarded flips** (the Eurosite rule):
  - A → H only from A;
  - H → A only when `gate_hidden = Y`;
  - a manual status change releases the product.
  - The gate never activates a product an admin hasn't activated. Novoton
    products start Disabled.
- **Own flag column** (`gate_hidden`), not shared with other reasons (the
  Sphinx pitfall).
- **Overrides win:** keep visible, keep hidden, closed.
- **Nothing deleted, nothing Disabled.**
- **Every change logged** in `travel_hotel_state_log` and included in the
  weekly email.

## 11. Decisions

1. **Between seasons:** *decided 2026-10-17: keep them listed.* Eurosite
   hides them today and Sphinx hides even more; both move onto the shared rule
   in phase 6.
2. **Dormant:** *decided: an admin setting, default 12 months.* Anything
   shorter catches the winter gap.
3. **Left the feed:** *decided: hidden automatically in Automatic mode; in
   Report only, listed for the admin to decide.*
4. **Closed hotels:** *decided: 301 to the resort page by default after 6
   months; the admin can pick another target per hotel.*
5. **Not offered:** *decided: no offline sales for now.*
6. **Notify me:** *decided: email only; the consent text is in §8.*
7. **Report-only until when?** *Decided 2026-10-17: the admin switches Mode
   to Automatic by hand after one review*, recommended once the 2027
   early-booking prices are loaded (Feb–Mar 2027) and the report has been
   checked. Then enforce for *dormant* and *left the feed* only.

## 12. Phases

| Phase | What ships | Customers see it | Size |
|---|---|---|---|
| 0 | Fix the data bugs in §13 — **done 2026-10-17**. Calibration (§14) still to run on the live store | Small fixes | S |
| 0b | Eurosite and Sphinx fixes that would corrupt the records or mislead the report — **done 2026-10-21** (§15.4) | Small fixes | S |
| 1a | Travel Core: the contract with supplier capabilities (§15.3), the evidence and log tables (schema self-heal), the policy, the date windows (moved from Eurosite), settings (a mode per supplier; hide after N months, default 12; what counts as availability) — **these done 2026-10-23 (§16)** —, the Hotel availability page with a supplier filter, CSV, weekly email; the daily and weekly step in Travel Core's existing cron (§4.5). Novoton: the evidence source (passive capture, `room_price` reworked into the weekly resort check, backfill), dashboard card | No | L |
| 1b | Eurosite and Sphinx: record what their existing checks and hotel-page searches already fetch, hotel-list completeness, bookings read back, dashboard cards; Sphinx stores `giata_id` (§15.3); Eurosite's `full` takes its steps' run locks (§4.5) | No | M |
| 2 | Hotel-page panels; "notify me" and "prices are out" emails; similar hotels; date-picker window; language keys. All suppliers; Eurosite first needs a request flow | Yes | M |
| 3 | Automatic mode, per supplier: dormant / left the list → Hidden, and back to Active; brake; overrides; `gateHidden` for bookings. Novoton first (Feb–Mar 2027) | Yes | M |
| 4 | Closed hotels: mark closed, redirect target, 301 controller, grace checks | Yes | S |
| 5 | Purchasing list of not-offered hotels with their demand | Staff only | S |
| 6 | Eurosite and Sphinx switch to Automatic once their records cover a season (about autumn 2027), and their old gates are removed; listing labels and sorting | Yes | M |

**Repo rules every phase must pass:**

- The CI gates: PHPStan level 10, both CS Fixer configs, Rector, PHPUnit.
- The schema tests: `LangSeedParityTest`, `UninstallCompletenessTest` and
  `GodFileRatchetTest`.
- New code goes into new classes, because `functions/hotels.php`,
  `functions/email.php`, `controllers/backend/novoton_prices.php` and
  `novoton_tools.php` are each within one line of their size ceiling.
- New tables go to Travel Core, whose runtime schema self-heal
  (`fn_travel_core_ensure_schema`) reaches installed stores. Novoton needs its
  own `Install\SchemaMigrator` (ported from Sphinx/Eurosite, closing audit
  `data-perf-3`) only if Novoton's own tables change.
- No new cron lines (§4.5). `room_price` keeps its mode and its slot; its
  `dash_job_room_price` texts change with what it does.

**New tests:**

- `AvailabilityPolicyTest`, covering the October, July, sold-out, left-the-feed
  and override cases;
- the date-window tests, moved from Eurosite;
- gate tests: guards, the brake, release;
- the hotel_list missing-run guard;
- the report query;
- schema parity;
- the gate flag in `HotelCartProduct`.

## 13. Phase 0: the data bugs, fixed 2026-10-17

Each fix is its own commit on `claude/serene-cray-lxk90u`, with tests; PHPStan
level 10, both CS Fixer configs, Rector and the Novoton and Travel Core suites
pass.

| Commit | What was wrong | Now |
|---|---|---|
| `f7d5918` | Four price checks (the `room_price` cron, the admin resort-based and per-hotel checks, the legacy `AdminCronService`) saved "no price" when Novoton did not answer. The admin checks also defaulted to this year's July until November, so from August to October they asked about dates already past. | No answer leaves the flag unchanged (`Helpers\PriceCheckFlags`); the admin checks default to the next 7 July. |
| `6a68c3f` | The "no availability" season note read only the nested season shape, took the first package's first-to-last season, past ones included, and used a language key that did not exist. | `Helpers\SeasonDates` reads both shapes and gives the current or next window across all packages; one translated sentence with dd.mm.yyyy dates. |
| `9323c57` | The catalog "from" price took past seasons too. | Only seasons not yet over count; the season count reads both shapes. |
| `692131b` | Alternative-request emails used template codes and an area registered nowhere, so customers never got them. | `Services\AlternativeEmail` uses the registered customer templates and passes a greeting, hotel names and rooms; `EmailTemplateCodesTest` pins every code to `addon.xml`. |
| `ee1c530` | `offers_update` needed a sync-log row that the nightly cleanup deletes, after which it never ran again. | A durable baseline per country in storage_data (`Helpers\OffersUpdateBaseline`). |
| `e5d44bb` | The hotel info sync wrote a `package_name` column that `novoton_hotels` does not have, in the same transaction as the package upsert. | The write is gone. |
| `cb7986e` | The price sync looked up its last full run under `sync_priceinfo` while logging as `priceinfo`. It never found one, so full cycles ran back to back all day, downloading every price list many times a day. | It reads its own type: a full cycle every 7 days and, in between, only packages last synced more than 24 h ago. |
| `f2df745` | The nightly cleanup kept the newest 100 sync-log rows, about 2–3 hours with URL-called cron jobs, so the batched syncs' "last full run" rows were deleted and the hotel info and facilities syncs ran in full every day. | `SyncLogRepository::prune()` keeps 14 days and always each sync's last finished (and last full) run. The hotel info full refresh defaults to 7 days instead of 180 (installed stores: change the setting). |
| `940d540` | Two Novoton email templates showed in the admin list as raw keys (`_email_template.novoton_holidays_price_…`). | All five templates are named, and the names are seeded to installed stores. |
| `dad0346` | With a wrong API username or password, Novoton answers `<error><err>Invalid username or password!</err></error>` (probe 3 on the live API, 2026-10-17). That parsed as an empty answer. The price syncs stored it as every package's prices, wiping seasons, "from" price and calendar. The `room_price` checks wrote "no prices" for every hotel. `offers_update` moved its baseline past changes it never fetched. | `Api\Refusal`: a reply whose root is `<error>` is a refusal. `priceinfo` and `offers_update` throw, so nothing is stored and the baseline stays. The price checks leave the flag. Live search and booking calls are unchanged. |

**Still open, on purpose:**

- **Admin action after deploying `f2df745`:** set Add-ons → Novoton Holidays
  → Settings → "Hotel info full sync interval (days)" from 180 to 7. Installed
  stores keep their saved value.
- **Optional, not done:** a 5-minute URL cron run that did nothing still
  writes a log row and, with "send cron report email" on (the default), a
  report email. The cleanup now copes with the rows; the emails can be turned
  off in the setting.
- **The legacy backend mode `cron_offers_update`** stamps `last_price_check`
  on hotels without products without checking anything. It is legacy and
  reached by URL only, so it is left as is.
- **Privacy:** the travel_core mirror of alternative requests stores the email
  and phone in plain text, while Novoton's own table encrypts them (phase 2).
- **Check on the live store:**
  - Email templates (`admin.php?dispatch=email_templates.manage`), the
    **Customer notifications** tab, should list "Novoton: Alternatives
    Available" and "Novoton: Alternatives Request Confirmation". CS-Cart
    imports them only at install; if they are missing, these two emails still
    cannot go out until they are imported.
  - The season shape is confirmed (§14, check 9).

## 14. Calibration (to run on the live store)

Fourteen checks: five API probes, seven SQL queries, and two looks in the
admin and the server. Each says what it tells us.

### API probes (1–5)

Run these in PowerShell from the repo folder. Copy all four values from Admin
→ Add-ons → Novoton Holidays → Settings on the live store.

- `hotel_list` and `hotel_info` need only the key and ID.
- `price_info` and `room_price` also need the username and password. A
  pair that doesn't match answers "Invalid username or password!".
- A wrong key or ID answers with an HTML line, `…no connection to dabatase.`
- `search` sent without a username and password got an empty body.

The probes mask the values in their output. When you share results, paste
the output only, never the `$env:` lines you typed.

```powershell
$env:NOVOTON_API_KEY      = "..."
$env:NOVOTON_API_ID       = "..."
$env:NOVOTON_API_USER     = "..."
$env:NOVOTON_API_PASSWORD = "..."

# 1. What Novoton sends per hotel: is there any status field?
php dev/novoton/hotel_list.php --country=BULGARIA --city="SUNNY BEACH" --limit=5
# 2. One hotel's packages (use a hotel id from 1): are the 2027 packages loaded yet?
php dev/novoton/hotel_info.php <hotel_id>
# 3. The season dates of one package (a PackageName from 2): which date format?
php dev/novoton/price_info.php --hotel_id=<hotel_id> --package="<PackageName>" --limit=5
# 4. Can July 2027 be booked already? (IdRoom and IdBoard from 2)
php dev/novoton/room_price.php --hotel_id=<hotel_id> --room_id="<IdRoom>" --board_id=<IdBoard> --check_in=2027-07-10 --check_out=2027-07-17 --adults=2
# 4b. The same for a week no contract covers: what does "no prices" look like?
#     (dad0346 assumes it is NOT a bare <error>…</error> reply; this confirms it)
php dev/novoton/room_price.php --hotel_id=<hotel_id> --room_id="<IdRoom>" --board_id=<IdBoard> --check_in=2028-07-10 --check_out=2028-07-17 --adults=2
# 5. What Novoton answers for a hotel it no longer lists (a sample id from SQL 7)
php dev/novoton/hotel_info.php <hotel_id_from_check_7>
```

### SQL on the live database (6–12)

Run these in phpMyAdmin. The default table prefix is `cscart_`.

```sql
-- 6. Hotels by product status and price signals
SELECT IFNULL(p.status, '-') AS product_status, h.has_room_price,
       h.calendar_prices_raw IS NOT NULL AS has_future_prices, COUNT(*) AS hotels
FROM cscart_novoton_hotels h
LEFT JOIN cscart_products p ON p.product_id = h.product_id
GROUP BY 1, 2, 3 ORDER BY 1, 2, 3;

-- 7. Hotels Novoton has not listed for 10+ days (left the feed?), with sample ids for probe 5
SELECT country, city, COUNT(*) AS hotels, MAX(hotel_list_synced_at) AS last_seen,
       SUBSTRING_INDEX(GROUP_CONCAT(hotel_id ORDER BY hotel_list_synced_at), ',', 3) AS sample_ids
FROM cscart_novoton_hotels
WHERE hotel_list_synced_at < NOW() - INTERVAL 10 DAY
GROUP BY country, city ORDER BY hotels DESC;

-- 8. Active products with no future price (what customers can land on today)
SELECT h.country, h.city, COUNT(*) AS active_without_future_prices
FROM cscart_novoton_hotels h
JOIN cscart_products p ON p.product_id = h.product_id
WHERE p.status = 'A' AND h.calendar_prices_raw IS NULL
GROUP BY 1, 2 ORDER BY 3 DESC;

-- 9. The season format actually stored
SELECT hotel_id, package_name, LEFT(JSON_EXTRACT(priceinfo_data, '$.seasons'), 400) AS seasons
FROM cscart_novoton_hotel_packages
WHERE priceinfo_data IS NOT NULL LIMIT 3;

-- 10. Hotels by the year of their last booked stay (input for the backfill)
SELECT YEAR(last_check_in) AS last_stay_year, COUNT(*) AS hotels
FROM (SELECT hotel_id, MAX(check_in) AS last_check_in
      FROM cscart_novoton_bookings
      WHERE status IN ('confirmed', 'completed')
      GROUP BY hotel_id) t
GROUP BY 1 ORDER BY 1;

-- 11. Price lists downloaded in the last hour (a large share = the price sync is cycling)
SELECT COUNT(*) AS refreshed_last_hour,
       (SELECT COUNT(*) FROM cscart_novoton_hotel_packages) AS total
FROM cscart_novoton_hotel_packages
WHERE synced_at > NOW() - INTERVAL 1 HOUR;

-- 12. Sync log rows per job in the last hour (about 12 per job = cron jobs called by URL)
SELECT sync_type, COUNT(*) AS runs
FROM cscart_novoton_sync_log
WHERE sync_date > NOW() - INTERVAL 1 HOUR
GROUP BY sync_type;
```

### In the admin and on the server (13–14)

13. **Email templates** (`admin.php?dispatch=email_templates.manage`), the
    **Customer notifications** tab. Are "Novoton: Alternatives Available"
    (`novoton_holidays_alternatives_available`) and "Novoton: Alternatives
    Request Confirmation" (`novoton_holidays_alternatives_request`) listed?
    CS-Cart imports them only at install. If they're missing, the phase 0
    email fix cannot send until they're imported.
14. **cPanel → Cron Jobs.** Do the Novoton lines start with `php …/cron.php`,
    or are they URLs (`curl …` / `wget …`)? URL calls are what make the
    sync log fill up (§13). Note the time of every travel line too (Novoton,
    Eurosite, Sphinx, Travel Core): §4.5 relies on them, and Sphinx's
    settings tab and dashboard suggest different times (`hotels` at 02:00 or
    03:00, `add_products` at 04:00 or 03:30).

The answers tell us:

- how many hotels are affected today;
- whether 2027 prices are already arriving;
- the real season format;
- how Novoton answers for a removed hotel;
- how hard the batched syncs hit the API.

They set the thresholds in §5 and the effort for phases 1–2.

### Results so far (2026-10-17, from the local Docker store)

Checks 6–13 were first run on the local store (`localhost:8080`, phpMyAdmin
server `db`). There no cron job runs, the hotel list was last synced on
2026-09-27 22:12, and there are no bookings. Checks 7, 10, 11, 12 and 14 must
therefore be run on the **live** database and server.

- **6.** 1,154 Novoton hotels.
  - 83 are products, all Active, all with `has_room_price = Y`.
  - **38 of the 83 Active products (46%) have no future price at all**:
    customers can land on them and find nothing for any date.
  - The other 1,071 hotels have no product.
- **8.** Those 38 are mostly **mountain, spa and city hotels**, not seaside:
  Bansko 4, Velingrad 4, Veliko Tarnovo 4, Sarnitsa 3, Sofia 3, Pavel Banya 2,
  Varna 2, Hisarya 2, Govedartsi 2, then 1 each in 12 more places. The gap is
  not only a seaside question.
- **9.** Confirmed: the stored seasons are a flat list with `Season` /
  `FromDate` / `ToDate`, e.g. hotel 476 "ADMIRAL ***** BB/HB [STAY 22.09 -
  19.12]". This is the shape the old season note could not read, and the one
  `SeasonDates` reads now.
- **7.** Not meaningful on the local store: every hotel was last listed on
  2026-09-27, because `hotel_list` has not run there since.
- **10–12.** Empty on the local store: no bookings, no cron runs.
  - 961 packages in total.
  - 0 refreshed in the last hour.
- **13.** The screenshot showed the *Administrator notifications* tab:
  - the two price templates were there, under raw keys (named since
    `940d540`);
  - the alternatives templates are customer templates, so they are on the
    *Customer notifications* tab, which is still to check.

### Results so far: live API probes (2026-10-17)

- **1. `hotel_list`, Sunny Beach:** 206 hotels (e.g. 21 FORUM, 22 IMPERIAL
  RESORT, 26 LAGUNA PARK).
  - There is **no status field**. Novoton never says "closed": a closed
    hotel shows only by leaving the list or by having no future prices, so
    the rules in §5 stand.
  - Each hotel carries `PriceFrom` and `Currency`, which the addon doesn't
    store. That is a cheap signal to look at in phase 1; what an empty or
    zero `PriceFrom` means is not known yet.
- **2. `hotelinfo` 476 (Admiral):**
  - Rooms such as "DBL 2+0 DELUXE NO BALCONY" and "DBL 2+1 DELUXE";
    boards BB, HB, ULTRA ALL INCL.
  - Five packages, among them **"ADMIRAL ***** +BEACH 2027" (IdCont
    1297221)**. Next summer's contract is already listed in October, so
    "between seasons" can be told from "dormant" by the packages, months
    before the season.
- **3. `priceinfo` 476 "ADMIRAL ***** +BEACH": refused**, "Invalid
  username or password!".
  - That run used the test key and ID 713, which may not belong to the
    store's Novoton account.
  - The refusal is what exposed the bug fixed in `dad0346`.
- **3, 4, 4b and 5 still to run** with the four values from the live store:

  ```powershell
  php dev/novoton/price_info.php --hotel_id=476 --package="ADMIRAL ***** +BEACH 2027" --limit=5
  php dev/novoton/room_price.php --hotel_id=476 --room_id="DBL 2+0 DELUXE NO BALCONY" --board_id=BB --check_in=2027-07-10 --check_out=2027-07-17 --adults=2
  php dev/novoton/room_price.php --hotel_id=476 --room_id="DBL 2+0 DELUXE NO BALCONY" --board_id=BB --check_in=2028-07-10 --check_out=2028-07-17 --adults=2
  php dev/novoton/hotel_info.php <hotel_id_from_live_check_7>
  ```

## 15. Eurosite and Sphinx: what changes (checked 2026-10-20)

Checked before phase 1 starts, from both add-ons' code and their API
documentation (`Documentation/Specificatii_API_Eurosite.pdf`,
`Documentation/Sphinx API.txt`). The phase 1 mockup shows the result: a
supplier filter, Eurosite and Sphinx hotels next to Novoton's, and a mode per
supplier.

### 15.1 What each supplier can tell us

| Evidence | Novoton | Eurosite | Sphinx |
|---|---|---|---|
| Hotel list (to see a hotel leave) | `hotel_list` per country, every 3 days | Own hotels per whitelisted city, daily. Only adds and updates; an empty answer is `[]`; "own hotels" is not everything search sells | Full list only on a full sync; deltas carry no deletion marker. A 404 on `/static/hotels/{id}` confirms a removal |
| Published prices (season dates) | Yes: priceinfo seasons, about 18 months ahead | **None** | **None** (only a 60-day cached price calendar) |
| Live check | One `room_price` call per resort and date covers every hotel | One `getHotelPrice` call per city and date covers every hotel, with Immediate / On request / Stop sale | One async search per destination and date: about 15–20 polls, about 1 minute. A search by hotel id returns nothing, and the API documentation asks not to build caches from live search |
| Bookings | `novoton_bookings` | `eurosite_bookings`: few rows, and "confirmed" is unreliable | `sphinx_bookings`: skip circuits and experiences, require `api_booking_ref` |
| Peers | the resort's answer | the city's answer | the destination's answer. Hotel-page searches fetch the whole destination, but today they discard the other hotels' offers (`search_poll.php:75-87`) |
| History at the start | prices and bookings of past seasons (backfill) | bookings only | bookings only |
| Own gate today | none | `ProductGate`: hides hotels without an Immediate offer on the near and peak dates, so it hides hotels between seasons and hotels sold on request only | `HotelAvailabilityGate`: hides hotels without an immediate offer at +14/+30/+60 days, and deletes hotels that have no product |

### 15.2 What does not change

- The rules in Travel Core, the collection in each add-on (§0.2).
- The states, "hide after N months" (12), Report only first.
- Hidden, never Disabled or deleted; guarded flips, the brake, overrides, the
  301 for closed hotels.

### 15.3 What changes

1. **Supplier capabilities.** The contract declares what each supplier can give:
   the hotel list (complete per country / per city / full sync only), published
   prices (yes / no), the live check's scope and cost, bookings. The policy uses
   only what exists, and the report says "not provided by Eurosite" instead of
   leaving a cell empty.
2. **Dormant needs records.** A hotel can be judged dormant only once its
   supplier's records cover the last N months. Novoton backfills from stored
   prices and bookings. Eurosite and Sphinx have only bookings, so for them
   dormant starts about 12 months after recording starts (Nov 2027 if phase 1b
   ships in Nov 2026). Until then their hotels can only be bookable, between
   seasons, not offered, left the list, or no data yet.
3. **Between seasons without published prices** comes from offers seen in the
   last 13 months, booked stays, and the destination's peers (the whole
   destination has no offers, so it is out of season). "Next season published"
   exists only for Novoton; for the others it becomes "next season seen", when a
   season-date check finds an offer.
4. **The offer type is kept**: immediate, on request, stop sale. On request
   counts as availability (decision 1 in §15.6). A stop sale never
   counts: the supplier has the hotel but sells nothing for those dates, so it
   points to *not offered*.
5. **Only complete answers count, per destination and date.**
   - Novoton: the resort answered, and a refusal is no answer (`dad0346`).
   - Eurosite: per city and date (audit `eurosite-platform-3`).
   - Sphinx: only a search polled to the end (`cursor` null).
   - Any supplier: a run in which no hotel anywhere has an offer counts as no
     answer. The Sphinx dev account returned zero offers everywhere because no
     supplier was connected to it (`docs/sphinx-no-availability-report.md`).
6. **Evidence per supplier hotel id, state per product.** Sphinx links several
   of its ids to one product; the product is available when any of its ids is.
   Circuits and experiences are left out.
7. **A destination key on every evidence row** (Novoton resort, Eurosite city
   code, Sphinx destination id), for the peer comparison, with a minimum number
   of checked peers (5) before *not offered* can apply. Sphinx's destination
   tree is irregular: some hotels hang off a country node.
8. **A mode per supplier** (off / report only / automatic) instead of one for the
   store, because each supplier's records mature at a different time. One gate
   per supplier: switching a supplier to Automatic turns its old gate off.
9. **No new API sweeps for Eurosite and Sphinx in phase 1.** Record what their
   existing checks already fetch: Eurosite's daily `availability` job, Sphinx's
   gate searches, and the full destination results of hotel-page searches. Sphinx
   adds at most a weekly season-date search, only for destinations with products,
   inside its existing `hotels` run (one seventh of them a night, §4.5).
10. **Start recording Eurosite and Sphinx in phase 1 (1b), not phase 6.** They
    cannot backfill, so every month without records is lost. Their gates keep
    running until each switch, and the report shows "hidden by Eurosite's own
    check" next to what the shared rule would do.
11. **Store Sphinx's `giata_id`.** The API offers it and the add-on drops it
    today (`HotelRowMapper`). It is the first step towards "the same hotel at
    another supplier" (redirect targets, alternatives).

### 15.4 Fixed first (phase 0b, done 2026-10-21)

Bugs in the existing gates that would corrupt the records or mislead the
report. Each fix is its own commit on `claude/serene-cray-lxk90u`, with tests;
PHPStan level 10, both CS Fixer configs, Rector and the Novoton, Travel Core,
Sphinx and Eurosite suites pass.

| Commit | What was wrong | Now |
|---|---|---|
| `d576b0a` | Sphinx: the gate deleted a hotel without a product when no search found an immediate offer. Incremental hotel syncs never bring a deleted row back, so a seaside hotel deleted in October could never become a product the next summer. | The hotel stays, flagged `no_availability`, and becomes a product once an immediate offer appears. The Hotels grid leaves these hotels out by default; a new Availability filter shows them. |
| `72904d5` | Sphinx: the gate marked a hidden product in `product_skip_reason`, which "Retry skipped" and the hotel upsert clear, leaving the product Hidden for good. A product an admin re-activated was hidden again on the next run. | Own columns `gate_hidden` and `gate_released`, moved over once by `SchemaMigrator`. Decided per product (sibling hotel ids share it). A gate-hidden product the admin changes by hand is left alone until an immediate offer returns. "Retry skipped" and the rename reset leave waiting hotels alone; the dashboard counts them apart. |
| `c3b248a` | Sphinx: a search that was accepted but not polled to its end, or a window that failed while another answered, still let the gate flag or hide a destination's hotels. | A destination counts as answered only when every window was polled to `cursor:null`; offers seen before a cut still count. Destinations without hotels to decide are no longer searched. |
| `6eafad9` | Sphinx: `hasMorePages` compared a country-wide count with each chunk's `meta.total`, so from the second chunk of 200 destinations the paging could stop after one page, and the hotels on the pages never fetched were marked inactive. | Each chunk is paged by its own count. Hotels are marked inactive only after a complete list: every chunk to its end, with all `meta.total` announced, no page error, no breaker abort. |
| `3a901da` | Eurosite: a destination counted as answered when any one of its dates answered (audit `eurosite-platform-3`); the others' hotels became "no offer" with a zero price, and their products were hidden. | Whole only when every date answered. Otherwise only answers better than what a hotel has are stored (`AvailabilityPlan::improvements()`), nobody becomes "no offer", and products can be shown again but not hidden. |
| `7eaa75e` | Eurosite: a product an admin re-activated was hidden again two runs later (the release cleared `gate_hidden`, and the product then looked like any Active one). | New `gate_released`: the gate leaves the product as the admin set it until an Immediate offer returns. The release runs first; hiding writes the flag before the product. |
| `4c59956` | Eurosite, outside this plan: the booking status sync read `<Status>`, while `getBookingResponse` has `<ItemStatus Code="C\|CP\|RJ\|XP\|X">`, so no Eurosite booking ever changed status. | The codes map onto the shared statuses: C confirmed, CP pending, RJ failed, X cancelled; XP (a cancellation waiting for the operator) changes nothing until the answer. |

**Still open, on purpose:**

- **After deploying, open any admin page before the next Sphinx hotels cron.**
  Sphinx adds `gate_hidden` / `gate_released` (and moves the old marks) on the
  first admin page load, as for its earlier columns. Eurosite does the same for
  `gate_released`.
- **Sphinx products already stuck Hidden** by the old wipe cannot be told
  apart from products an admin hid. They are Sphinx products in status Hidden
  whose hotel has neither `gate_hidden` nor `gate_released`; check them by
  hand.
- **Eurosite products an admin re-activated before `7eaa75e`** were already
  released the old way and can be hidden once more; re-activate them again.
- **Sphinx sibling hotel ids:** hotels skipped as `duplicate` are not
  searched, so an offer seen only under a duplicate's id does not count for
  the shared product. The phase 1b records will show how often that happens.

### 15.5 Travel Core work this adds

- The daily and weekly step runs inside Travel Core's existing daily cron
  (§4.5), so it needs no crontab line. Travel Core has no cron dispatcher; its
  modes are listed by hand in three places (`cron.php`,
  `controllers/frontend/travel_cron.php`, `CronOverview::CORE_MODES`). The step
  records its runs as `hotel_availability`, so `CORE_MODES` lists it and Tools
  shows its health; a manual run is a button on the Availability page, not a
  cron mode.
- A new table needs the addon.xml queries, `fn_travel_core_ensure_schema` and
  both uninstall lists. New settings reach installed stores through
  `SettingsMigrator`.
- Travel Core sends plain `fn_send_mail`; the weekly email should be a
  registered admin email template, so it can be edited.
- `travel_alternative_requests` (the "requests" column): Eurosite does not feed
  it, Novoton's mirror has no `product_id`, and the table is not self-healed
  (audit `data-perf-2`).
- `ConvergenceLedgerTest` covers Novoton and Sphinx only; the new contract
  should cover Eurosite too.

### 15.6 Decisions (2026-10-21)

1. **"On request" counts as availability: yes.** A hotel sold on request is
   still in business, and on request rooms are shown as such. Eurosite's and
   Sphinx's own gates count Immediate only, so switching them to the shared
   rule will show more hotels.
2. **A mode per supplier: yes** (§15.3, point 8).
3. **Until each switch, Eurosite's and Sphinx's own gates keep hiding hotels
   between seasons**, after the phase 0b fixes (§15.4, done), and the report
   compares them with the shared rule. This goes against decision 1 in §11
   until each supplier switches; turning them off now would also show hotels
   that are truly gone.

## 16. Phase 1a progress

### 16.1 Step 1, the Travel Core foundation (done 2026-10-23)

Nothing records, classifies or changes a product yet: the pieces below are
what the next steps write to and call. PHPStan level 10, both CS Fixer
configs, Rector and the Travel Core, Novoton, Eurosite and Sphinx suites
pass; the two tables were created twice on MariaDB 10.11 without error.

| Piece | Where (travel_core `src/`) | Notes |
|---|---|---|
| Contract and supplier capabilities | `Contracts\HotelAvailabilitySourceInterface`, `ValueObjects\AvailabilityCapabilities`, `Enums\HotelListCoverage`; `TravelProviderRegistry::setAvailabilitySource()` | Each supplier declares its hotel-list coverage, published prices, live-check scope, past prices, bookings, and the cron modes that write records (§4.5, rule 5). No supplier implements it yet |
| The two tables | `Repository\HotelAvailabilityRepository` (the DDL), addon.xml install/upgrade/uninstall items, `fn_travel_core_ensure_schema`, the PHP uninstall list | Columns of §4.1 plus `immediate_seen_at`; `AvailabilityTablesTest` pins addon.xml to the repository |
| The rules | `Services\AvailabilityPolicy`, `Enums\HotelAvailabilityState`, `Dto\Availability\*`, `ValueObjects\AvailabilityThresholds` | §5, with fresh_days 8 and the rule 5 refinement above; 21 tests, including October, July, sold out, left the feed and the August example |
| The date windows | `Services\AvailabilityWindows` | Moved from Eurosite's `AvailabilityPlan`, which now calls it; `docs/CONVERGENCE.md` updated |
| Settings | Travel Core → Settings → Hotel availability: a mode per supplier (Off / Report only, default Report only), "Hide a hotel after N months without availability" (12), "Count on-request offers as availability" (on) | Reach installed stores through `SettingsMigrator`; the mode options come from a variants function so they keep their labels. Automatic is offered once the gate exists (phase 3). The other thresholds are fixed defaults for now |

### 16.2 Next

2. Novoton recording from the jobs that already run (§4.2), and the backfill (§4.4).
3. The weekly step in Travel Core's daily cron (§4.5), the Hotel availability
   page, its CSV and dashboard tile, and the weekly email.

