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
  - An exception used to abort the whole run, and an empty answer was
    skipped. Both now count as "no information", never as "missing": each
    country is asked in its own try, a refused or failed country leaves its
    hotels as they are, and the other countries still sync (done in step 2,
    §16.2).
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
on its existing line (decided 2026-10-22, §4.5). Built 2026-10-26: what it
does in practice, and its settings, are in §16.5.

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
  - **Skip dates without published prices** (added 2026-10-28, §16.7): a date
    none of the resort's hotels has a priceinfo price for can have no offer,
    so it is not asked and counts as answered without one. A hotel whose
    prices were never read, whose window ended before today, or whose latest
    check found an offer outside it keeps its resort's every date asked.
    Out of season this leaves out most of a seaside resort's dates. A setting,
    on by default; `&skip_unpriced=0` asks every date for one run.
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

Done once by the nightly calendar rebuild (`recompute_calendar_prices`,
03:30), before it rebuilds the calendars: stored data only, no API calls, at
most 5 minutes a night, resumed the next night until every hotel is done. It
was planned for the reworked `room_price`, and moved here while that job
waited for Novoton's answer on the request volume.

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
| Novoton `hotel_list` (`30 1 */3 * *`) | Seen in / missing from the list (§4.2) | None |
| Novoton `recompute_calendar_prices` (`30 3 * * *`) and `compute_prices` (every 5 minutes) | The published price window (§4.2); the backfill, once (§4.4) | None |
| Novoton `room_price` (`0 4 * * *`), reworked (done, §16.5) | The weekly resort check and `has_room_price` (§4.3) | Fewer: about 60 calls a night instead of up to 500, at most 120 (a setting) |
| Novoton `add_hotels_as_products` (`30 4 * * *`) | Nothing new; it reads `has_room_price` as today | None |
| Eurosite `hotels` (`0 3 * * 0`, weekly since §16.7, or inside `full`) and `availability` (`15 4 * * *`) | Seen in the list; the per-city answers its gate already fetches (phase 1b, done §16.4) | None |
| Sphinx `hotels` (daily; 02:00 on its settings tab, 03:00 on its dashboard, §14 item 14) | List completeness; the destination answers its gate searches already fetch (phase 1b, done §16.4). Only if needed: a season-date search for one seventh of the destinations with products each night (§15.3, point 9) | None, or that search |
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
6. **No more API traffic at 04:00 than today.** `room_price` makes far fewer
   calls, and since Novoton's answers (§16.7) nothing that calls Novoton
   starts between 03:00 and 04:00: `offers_update` runs at 05:00 and 17:00,
   `resinfo` every 15 minutes for the few bookings still on request.
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
| 1a | Travel Core: the contract with supplier capabilities (§15.3), the evidence and log tables (schema self-heal), the policy, the date windows (moved from Eurosite), settings (a mode per supplier; hide after N months, default 12; what counts as availability), the Hotel availability page with a supplier filter, CSV, weekly email; the weekly step in Travel Core's existing cron (§4.5). Novoton: the evidence source (passive capture, backfill), the Tools page card — **these done 2026-10-23 (§16)**; `room_price` reworked into the weekly resort check (§4.3) — **done 2026-10-26 (§16.5)** | No | L |
| 1b | Eurosite and Sphinx: record what their existing checks and hotel-page searches already fetch, hotel-list completeness, bookings read back, dashboard cards; Sphinx stores `giata_id` (§15.3); Eurosite's `full` takes its steps' run locks (§4.5) — **done 2026-10-23 (§16.4)** | No | M |
| 2 | Hotel-page panels; "notify me" and "prices are out" emails; similar hotels; date-picker window; language keys. All suppliers; Eurosite first needs a request flow. **Back end done 2026-10-27 (§16.6); the panels on both hotel page templates and the date-picker window done 2026-10-30 (§16.8)**, behind a switch that is off; listing labels moved to phase 6 | Yes | M |
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
  should cover Eurosite too (done: it pins all three sources, §16.4).

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

### 16.2 Step 2, Novoton records availability (done 2026-10-23)

Novoton now writes what its existing jobs see; no extra API call, and still
nothing is classified or changed in the store. The writes are Travel Core's
(`HotelAvailabilityRepository::recordList / recordPriceWindow /
recordOfferSeen / recordBackfill`), so Eurosite and Sphinx will use the same
ones. Each was run on MariaDB 10.11 against tables with different collations.

| Evidence | Written by | Notes |
|---|---|---|
| Seen in / missing from the hotel list | `hotel_list`, per country | Missing counts only from a complete answer (`ListCompleteness`: not empty, at least 90% of the last complete list). A country that fails or refuses no longer stops the others; the run then reports which country gave no list |
| Published price window | Every calendar rebuild (`PriceInfoService::precomputeCalendarPrices`) | First and last priced date; the latest priced date ever seen only moves forward |
| Offers on hotel-page searches | `novoton_booking.search`, the main and the alternative-date search | Positives only, at most one write per hotel an hour, `immediate_seen_at` when any room is not on request, `offer_months` per stay month |
| Past seasons (the backfill) | `recompute_calendar_prices`, its first runs | Latest priced date from every stored season, the last confirmed stay (`novoton_bookings`), the last hotel_list, when Novoton first listed the hotel; a cursor in storage_data |

`NovotonAvailabilitySource` declares Novoton's capabilities and the modes the
weekly step waits for (`hotel_list`, `recompute_calendar_prices`,
`room_price`); it is registered from `init.php` and pinned in
`ConvergenceLedgerTest`. The peer group is `COUNTRY|Resort`.

Recording runs in every mode: Off stops the classification, not the
records, because records cannot be recovered later.

### 16.3 Step 3, the weekly review and the report (done 2026-10-23)

Every supplier's records are now classified once a week and shown to the
admin. Still report only: no product is hidden, shown or changed. PHPStan
level 10, both CS Fixer configs, Rector and the four suites pass; the review
and the page's queries were run on MariaDB 10.11, and both templates were
compiled and rendered with Smarty 5.

| Piece | Where (travel_core) | Notes |
|---|---|---|
| The weekly review | `Services\AvailabilityReview` | Runs at the end of Travel Core's daily `exchange_rates` line, in `cron.php` and in `travel_cron.run` (§4.5: no new crontab line, no new mode). Due 7 days after the last complete review; a failed one is due again the next day. Waits, until the next daily run, while a supplier mode that writes records is running (a run open over 6 hours counts as dead). Logged as `travel_core` / `hotel_availability`, so it counts in Travel Core's cron health on the Tools page |
| What it does | the same | Carries each hotel's last confirmed stay over from `travel_bookings`, counts each destination's peers over the last two weekly checks, runs `AvailabilityPolicy` on every record of every supplier not set to Off, writes only the rows whose state, reason or "next season published" changed, and logs each state change in `travel_hotel_state_log`. A hotel's first classification is logged but not reported as a change |
| Hotel availability page | `controllers/backend/travel_hotel_availability.php`, `views/travel_hotel_availability/manage.tpl`, `Repository\HotelAvailabilityListing` | Travel menu and tabs, with a permission entry. Counts per supplier and state (each links to its hotels), the last review and what it changed, a "What the states mean" legend, and the list: hotel (linked to its product), supplier, state and since when, why, last offer seen and its stay date, prices published, last booked stay, last seen in the hotel list and missed lists. Filters: supplier, state, "no offer for 3/6/12/18/24 months", text (hotel, destination or id); sorted by name, longest without an offer, or latest change |
| Review now | the page, POST `run` | Runs the review at once unless a recording job is running; says why when it cannot |
| CSV | the page, `export` | What the filters match, up to 20,000 rows, semicolon-separated with a UTF-8 BOM so Excel opens it in columns with diacritics; states and reasons as codes; a cell starting with `=`, `+` or `@` cannot run as a formula |
| Weekly email | `Services\AvailabilityReportMail` | To the orders department, only when the review changed a state or classified hotels for the first time: counts per supplier and state, up to 50 changes by hotel name, a link to the page. Its wording comes from language keys (editable under Translations), not a registered email template as §6 and §15.5 planned: a template reaches a store only at install, and this project updates stores by pulling code |
| Tools page | the Maintenance card | The last review in one line, and a link to the page |
| Hotel names in the records | `hotel_name`, written by Novoton's hotel list and backfill | For the page and the email without joining a supplier table. The table heal adds the column to a table created by step 2 (`HotelAvailabilityRepository::ADDED_COLUMNS`), checked on MariaDB |

**Until `room_price` was reworked (step 5, §16.5), the report showed four of
the seven states.** "Dormant" and "not offered" both need the destination's
latest check to have answered (rules 4 and 5), and only that weekly resort
check writes `offer_checked_at`; the searches record offers, not empty
answers. So Novoton's hotels showed as bookable, between seasons, left the
supplier's list or no data yet, and a hotel that would be dormant showed as
"no data yet", never as something it is not.

Left for phase 3, when there is something to decide: the product status,
page views, notify-me and suggested-action columns, the bulk actions and
overrides, the "has bookings" and "has an override" filters, and an
attention line on the CS-Cart dashboard for hotels waiting for a decision.

### 16.4 Step 4, Eurosite and Sphinx record availability (done 2026-10-23)

Phase 1b. Eurosite and Sphinx now write what their existing jobs and their
customers' searches see, into the same records as Novoton; no extra API
call, and still nothing is classified differently or changed in the store.
The weekly review and the Hotel availability page pick them up as they are.
PHPStan level 10, both CS Fixer configs, Rector and the four suites pass;
both recorders were run on MariaDB 10.11 against the suppliers' own tables.

| Evidence | Eurosite | Sphinx |
|---|---|---|
| The hotel's id in the records | Its hotel code, as in the bookings Eurosite mirrors | Its Sphinx hotel id, as in the bookings Sphinx mirrors |
| Its destination (the peer group) | Its city: `RO\|Mamaia (RO0363)` | Its destination: `GR\|Halkidiki (1234)` |
| Seen in / missing from the hotel list | `hotels`, per city: seen; missing from a complete city answer | `hotels`: every run marks the hotels it saved as seen; missing only after a full sync that came back whole (every chunk paged to its end), since the daily sync brings only changed hotels |
| A destination's whole answer (`recordCheck`) | `availability`: a city where every date answered checks all its listed hotels; offers count as Immediate or On request, a Stop sale does not | The gate's searches: a destination whose every search was polled to its end checks the hotels the gate decides there |
| Part of an answer (`recordOffersSeen`) | A city where some dates did not answer: only its offers | A destination that did not answer in full, and hotels the gate does not decide: only their offers |
| No offer anywhere | The whole run counts as no answer | The whole country counts as no answer |
| Customers' searches | `eurosite_booking.search`: the whole city's offers | `sphinx_booking.search` and `search_poll`: the whole destination's offers, which the page then narrows to its hotel |
| Bookings | The review reads them from `travel_bookings`, as for Novoton | The same; a circuit booking matches no hotel record |

Shared in Travel Core: `OfferTally` folds any supplier's offers per hotel
(the latest stay, the next one, whether any could be booked at once, the
months); `HotelAvailabilityRepository::recordCheck()` and
`recordOffersSeen()` write a destination's answer; `listedCount()` gives the
size a new list is measured against. `ConvergenceLedgerTest` pins the three
sources.

Also in this step:

- **Hotel lists, all suppliers.** A list may now be one hotel shorter than
  the group's size and still count, so a city of three hotels can lose one;
  and the group's size counts only hotels a list had in the last 30 days, so
  a group that really shrank is measured by its new size after a month
  instead of every later list looking short (until now a country that lost
  more than 10% of its hotels at once would never have counted any of them
  missing). Found on MariaDB: a Eurosite city that lost one of three hotels
  never counted it missing.
- **Eurosite's `full`** runs each step under that step's own run lock and logs
  it as its own run (§4.5, rule 3), so a scheduled `hotels` still running is
  skipped, never run twice at once, and Travel Core sees which step runs.
- **Sphinx stores `giata_id`** (§15.3, point 11): a column with an index, from
  addon.xml for new stores and `SchemaMigrator` for installed ones. The upsert
  writes it only once the column exists, so a hotels cron that runs after a
  deploy, before any admin page, still saves its hotels.
- **Dashboard cards.** The Novoton, Eurosite and Sphinx dashboards each show
  their hotels per availability state and the last review, linking to the
  page (`AvailabilityCard`, `components/availability_card.tpl`).

What the records can say, per supplier, for now:

- **Eurosite and Sphinx have no history** (§15.3, point 2): "dormant" needs a
  year of their records, so it starts about October 2027.
- **Sphinx sees a hotel leave only in a full hotel sync** (`hotels full=1`).
  The Production Cron Plan runs one every Sunday at 02:00, so "left the
  supplier's list" works for Sphinx once that line is in the crontab; until
  then only the dashboard's "Run (full)" button runs one. Sphinx's API
  documentation says nothing on removed hotels, so the plan asks Sphinx
  whether there is a way to learn of them without the whole list.
- **Sphinx checks only while its gate is on** (the "Require immediate
  availability" setting) and only the destinations whose hotels it decides.

### 16.5 Step 5, the weekly resort check (done 2026-10-26)

Phase 1a's last piece. Novoton's `room_price` job, on its existing line
(`0 4 * * *`), is now the weekly resort check of §4.3. It did not wait for
Novoton's answer on how often it may be called: how often each resort is
checked, how many calls one run may make and how long it may take are
settings, so that answer changes settings or the crontab, not code. Still
report only. PHPStan level 10, both CS Fixer configs, Rector and the four
suites pass; a run was checked on MariaDB 10.11 against a scripted Novoton,
and the weekly review then classified what it wrote.

| Piece | Where (novoton_holidays `src/`) | Notes |
|---|---|---|
| What to ask, in which order | `Services\ResortCheckQueue` | A unit is a resort, whose resort-level call per date covers every hotel of it that has a price, or a hotel whose city is none of its country's resorts, asked on its own (§4.3). The resorts come from Novoton's `resort_list`, one call per country with a sold hotel: the resort-level call needs Novoton's own spelling, which the stored `novoton_resorts` names lose. A country whose list does not come waits for the next night. A resort is a unit while one of its hotels is sold (`DestinationScope`). Least recently checked first: a unit's last check is its hotels' oldest `last_price_check` |
| The run | `Services\ResortCheckRunner`, `Cron\Commands\RoomPriceCheckCommand` | Each night its share of the units (one seventh for a weekly cycle), on every date: the settings' near days and season dates (`AvailabilityWindows`, as Eurosite), 7 nights, 2 adults; plus, per unit, up to two first published weeks of hotels whose prices start later and had no offer in their latest check. One call at a time with a short pause (two at a time for a hotel asked on its own) |
| Only answers count | the same; `Helpers\PriceCheckFlags::resortOffers()` | A reply that does not parse, a refusal or an `<Error>` is no answer; Novoton's empty document is its "no prices". A hotel counts as offered once one of its rows has a price above zero. Every date answered: `has_room_price` Y for the unit's hotels with an offer on any date, N for the others, and `recordCheck()`, so each hotel was checked, offer or not. Some dates answered: Y for those with an offer, the others keep their flag, `recordOffersSeen()`. None: nothing changes, and the unit comes first again the next night |
| A hotel Novoton files under another resort | the same | A hotel in a resort's answer whose city in the store is another one: Y, and its offers recorded; its own resort's check decides the rest |
| When a run stops | the same | At the time budget, estimated from the units done so far so that a run started at 04:00 ends before `add_hotels_as_products` at 04:30; at the call budget; or after two units in a row without one answer (Novoton not answering). Units not reached go first next time. The job reports a failure, which Travel Core's cron health shows, when no unit got an answer or no country's resort list came |
| Settings | Settings → Advanced → "Weekly availability check (room_price)" | Near dates `14, 30`; season dates `06-20, 07-15, 08-15, 09-05`; each resort once every 7 days; at most 120 calls and 20 minutes a run; since §16.7, skip dates without published prices (on). They reach installed stores through Travel Core's settings heal. A run's URL can set `resorts=N` or `resorts=all`, `max_calls`, `max_time` (minutes), `nights` and `skip_unpriced` for that run; `check_in`, `limit` and `concurrency` are no longer used, and a run that passes them says so |
| Dashboard | the `dash_job_room_price` texts | "Availability check": weekly, a seventh of the resorts a night. Mode, slot, run lock and run log unchanged. Add products, finding no priced hotel in a country, says how to check that country now (`&country=…&resorts=all`) |
| Records read | Travel Core `HotelAvailabilityRepository::awaitingFirstOffer()` | The hotels whose published prices start after today and that had no offer in their latest check, with their first priced date |

What this changes for the records and the report:

- **Novoton's records now get `offer_checked_at`**, so "dormant" and "not
  offered" can show for Novoton. The backfill already filled in past
  seasons, so a hotel without availability for 12 months can show as dormant
  after its resort's first answered check; "not offered" needs 14 days of
  records and a destination that sells.
- **A run with no offer anywhere still counts**, unlike Eurosite's and
  Sphinx's. Novoton tells a failure apart (a refusal, an error, a reply that
  does not parse), its empty document is its "no prices" answer, and one
  night's seventh of the resorts can be empty out of season: discarding it
  would hold the queue on the same resorts night after night.
- **`room_price` cannot tell an on-request room** from one that can be booked
  at once (that comes from `hotel_quota`, which customers' searches call), so
  its offers never count as immediate. With "Count on-request offers as
  availability" off, Novoton's availability rests on the hotel-page
  searches.
- **`has_room_price` now means "an offer on any checked date of the hotel's
  latest check".** Next summer is among the dates, so seaside hotels can
  become products in winter (§4.3).

### 16.6 Phase 2, the back end (done 2026-10-27)

Decided 2026-10-27: phase 2 starts with what does not touch the hotel page,
because another branch is rebuilding that page (Design A,
`docs/hotel-page-design-a-plan.md`: search bar, calendar, results). The
panels go onto the page once that work has landed, so the two do not edit
the same templates; the date-picker window (§7.1) goes with them, into the
new calendar. Everything customer-facing is behind a new setting, off by
default: Travel Core → Settings → "Show availability panels on hotel pages",
to switch on once the Hotel availability page looks right on the live store
(§14). PHPStan level 10, both CS Fixer configs, Rector and the four suites
pass; the storage, the emails and the panel's queries were run on MariaDB
10.11, the admin grid rendered with Smarty 5.

| Piece | Where (travel_core) | Notes |
|---|---|---|
| "Notify me" requests | `Repository\NotifyRequestRepository`, in `travel_alternative_requests` | As §8 planned, beside the alternatives requests: `request_type` notify, what the guest waits for (`watch_for`: prices, booking, return), the email's language, when they consented and to which text (version `2026-10-17`). Kept only in Travel Core's table, for every supplier: Novoton's own table is for its alternatives workflow. One waiting request per address and hotel. The address is removed once the email went out, when an admin cancels the request, or after 12 months; the 30-day expiry of alternatives requests leaves them alone |
| The table's heal | `AlternativeRequestRepository::ensureTable()`, called by `fn_travel_core_ensure_schema` and by every query that reads the new columns | Closes audit `data-perf-2`: the table had no runtime heal. Creates it, and adds the new columns to an older one; `AlternativeRequestsTableTest` pins addon.xml to it |
| The one email | `Services\AvailabilityNotifyMail`, on Travel Core's daily line after the review | Once booking opened since the request: an offer seen (an immediate one, when on-request offers do not count), or, for a guest waiting for prices, prices published for a date from today on. In the guest's language, from language keys, linking to the hotel page. At most 200 a run; none while the product is disabled; a failed send is tried again the next day |
| The panel's wording | `Services\AvailabilityPanel` | Per product, from the records: §7's states and texts, the "notify me" each offers, last season's months (the latest run of months with offers in the past 13; one month without offers stays inside a season), the first published date. No panel for a bookable hotel, one without records, a supplier set to Off, or while the setting is off |
| Similar hotels | the same | Up to 3 of the same supplier and destination that can be booked now, with an Active product, at most a star apart (stars from each supplier's hotel data), the latest offers first; hotels without stars come after |
| The form | `controllers/frontend/travel_availability.php` (`travel_availability.notify`), `Services\NotifyRequestForm` | Email and the consent box of §8; a honeypot field, a minute between requests and five per visit. What the guest waits for comes from the panel, never from the form |
| Admin grid | Travel → Availability Requests | Shows them with what they wait for, filters by type (and Eurosite), and can cancel one |
| Language keys | `lang_keys.php`, both `.po` | The panel, the buttons, the consent text of §8, the form's answers, the emails, the grid |

The page part followed once the Design A page had landed (§16.8); the
listing labels moved to phase 6.

### 16.7 The suppliers' answers, applied (2026-10-28)

The three suppliers answered the Production Cron Plan's questions on
28 Oct 2026. The plan (a Claude doc) now carries their answers, the new
schedule and the replies still to send; the code follows it. PHPStan level
10, both CS Fixer configs, Rector and the suites pass (Novoton 1065, Eurosite
332, Travel Core 924); the new SQL was run on MariaDB 10.11.

| Supplier | What they asked for | What changed |
|---|---|---|
| Novoton | At most 5 requests at once and 5 a second | `Api\RequestGate`: one file-locked gate for every request of the store, jobs and customers' searches |
| Novoton | Bulk work 01:00–06:00, not 03:00–04:00 | No job that calls Novoton starts in that hour; `hotel_list` at 01:30, hotel facilities Sunday 01:45 |
| Novoton | hotelinfo every 3 days in February–May, 7 otherwise | Two settings; `Services\FullPassSchedule` starts each full pass from 05:00 |
| Novoton | offers_update every 12 hours; priceinfo only for what it returns, a full pass monthly | `offers_update` at 05:00 and 17:00 for every sold country, marking packages (`price_changed_at`) and hotels (`hotelinfo_due_at`); priceinfo asks only for new and marked packages, monthly in full; hotelinfo no longer calls offers_update itself (`Install\SchemaMigrator` adds the columns) |
| Novoton | resinfo for ASK only, every 15–30 minutes, stop at OK/ST/RQ | Every 15 minutes (`Services\AskBookingPoller`); RQ now reaches the alternatives jobs; Travel Core's "Sync statuses" checks bookings instead of fetching hotel lists |
| Eurosite | Catalogs monthly, hotel lists weekly | On the 1st and on Sundays; the nightly `full` runs each step only when due |
| Eurosite | Booking status: 30 minutes after booking, every 30 minutes for 2 hours, again 8 hours later | New `booking_status` job (every 15 minutes, `BookingStatusSchedule`); a taken booking stays pending until Eurosite confirms it |
| Eurosite | No set limits | Scheduled runs keep to Sphinx's: at most 10 price searches a minute, other requests at most once a second (`Api\RequestPacer`) |
| Sphinx | 10 requests a minute for non-static data; calendar prices from the cache | Nothing yet: whether the limit covers searches and the cache, and which request shape the cache wants, are asked back |

Open: the five questions in the plan's "Replies to send".

Later the same day, the limits became settings, so a supplier's new numbers
change a setting, not code, and the weekly check stopped asking about dates
nobody has prices for. The suites pass (Novoton 1079, Eurosite 339, Travel
Core 925); the new query was run on MariaDB 10.11.

| Add-on | Setting (default) | Where it acts |
|---|---|---|
| Novoton, Advanced | Requests to Novoton at the same time (5); per second (5) | `Api\RequestGate`, for the whole store |
| Novoton, Weekly availability check | Skip dates without published prices (on) | `ResortCheckQueue::split()`: a date none of a unit's hotels has a published price for (Travel Core `HotelAvailabilityRepository::priceWindows()`, the calendar rebuild's window) is not asked and counts as answered without an offer (§4.3) |
| Eurosite, "Scheduled runs: pace and limits" | Price searches per minute (10); other requests per minute (60) | `Api\RequestPacer`, scheduled runs only |
| Eurosite, the same | Hotel details per night (100); product updates per night (200) | `product_info` and `update_products`; `&limit=N` still overrides one run |

The filter costs nothing when every hotel has prices; out of season it
leaves out most dates. In a MariaDB run, a resort with no published prices
needed no call and a resort priced for next summer skipped its two
November dates: 13 calls instead of 21.

**Hotel photos, refreshed weekly (29 Oct 2026).** Novoton suggested
refreshing image lists about weekly; photos were downloaded once, when a
product was made, at most 10. New job `refresh_images`, nightly at 02:15: a
seventh of the hotels with a product, least recently checked first, so each
hotel about once a week, done before 03:00. `Services\HotelImageSync`
decides what changes, and product creation and `backfill_images` attach
through it too:

- No answer, or an empty list: nothing changes. All of Novoton's photos
  count, without a limit.
- A photo seen before (its path, in `novoton_hotel_images`) is not
  downloaded again; one an admin deleted stays deleted.
- A new photo is downloaded through the request gate and compared with the
  product's images: the same file (SHA-1) or the same picture saved again
  at another size or quality (a 64-bit difference hash, Travel Core
  `Helpers\ImageFingerprint`) is already there. Re-saved copies differ by
  at most 4 of 64 bits, different photos by 16 or more; the threshold is 6.
- Only when new photos were added are the Novoton photos it no longer lists
  removed; new ones go in first, so a product never has fewer.
- The photos follow Novoton's order at every refresh, and Novoton's first
  photo is the main one (chosen 29 Oct 2026).
- Photos added by hand: a product image is Novoton's when a row points at
  it (attached by the sync, or, on an older product's first refresh, the
  same picture as a listed photo); every other image counts as added by
  hand. The setting "Keep photos added by hand" (on) never removes, moves
  or demotes them; off, they are treated like photos Novoton no longer
  lists.

The first pass downloads every listed photo of an older product once, to
compare; after that a week without changes costs one `hotel_images` call
per hotel and no download.

### 16.8 Phase 2, the hotel page (done 2026-10-30)

With the Design A page on `main` (its page, calendar and room-first results,
`docs/hotel-page-design-a-plan.md`), the panel went onto both hotel page
templates and the date-picker window into the shared calendar. Still behind
"Show availability panels on hotel pages" (off): nothing changes for
customers until the admin switches it on. The suites pass (Travel Core 981,
Novoton 1119, Eurosite 348, Sphinx 627; 304 vitest tests), with PHPStan level
10, both CS Fixer configs and Rector; the panel was rendered with Smarty 5
and the add-on's CSS, and both calendars driven in Chromium with the rebuilt
bundle, at 1280 and 390 px. To check on the dev store: the panel under the
theme's own styles, and the form's notice after sending.

| Piece | Where (travel_core) | Notes |
|---|---|---|
| Where the panel sits | `components/availability_panel.tpl`, included first by `components/booking_form_mount.tpl` | Above the search on every template that renders the booking form mount: the current hotel page, and Design A between the photos and the yellow bar. Outside the shell the inline search swaps results into, so a search never replaces it |
| What it shows | `Services\AvailabilityPanelView` (from `AvailabilityPanel`, assigned by the `dispatch_before_display` hook) | §7's sentence for the state; last season's months in the visitor's language ("Sezonul trecut: iunie – septembrie", one month named once); from when prices are published, in the store's date format; up to three similar hotels as cards: the store's product name, photo, stars and the indicative "from" price per night, all from stored data |
| "Notify me" | A native `<details>` under the sentence; the form posts to `travel_availability.notify` | Email, the unticked consent box with §8's text, the security hash, a honeypot. No script needed; the controller answers with a notice on the page. No form for a hotel that takes no request (no longer available, prices published from a later date) |
| Privacy link | New setting "Privacy policy page" (`availability_privacy_url`) | The consent text links to it; only an https:// or store-relative address. **Set it before switching the panels on**: without it the consent text has no link |
| The date-picker window | `Services\CalendarPriceWindow`, sent by `booking_config` as `priceWindow` | The records' first and last stay date with a published price (`contract_from` / `contract_to`), from today on; only while the panels are on and the supplier is not set to Off. Today only Novoton records windows; for the others nothing changes |
| In the calendar | `react-src` `Calendar.jsx` (both the current bar and Design A's), bundle rebuilt, `TRAVEL_CACHE_VER` 11 | With no check-in chosen it opens on the first published date's month instead of this one (phones scroll to it). Days outside the window are muted and lightly hatched, say "no prices published yet" on hover and to screen readers, and stay selectable: a search may still find offers. A note under the months gives the window ("Prices are published for stays from 1 May 2027 to 30 September 2027") |
| Styles | `booking-pages.css` (`.travel-avail-*`), Design A's navy in `design-a.css`, the calendar's in `booking-engine.css`; both themes | No inline styles |
| Tests | `AvailabilityPanelViewTest`, `AvailabilityPanelTemplateTest`, `CalendarPriceWindowTest`, `CalendarPriceWindowWiringTest` | |

Design A's `pdp-design-a.js` sizes the photos so the bar ends on the first
screen. It measures the gap between the photos and the bar, so with a panel
the photos give way (down to 240 px) to keep the panel and the bar on the
first screen where the screen is tall enough.

**Listing labels** ("2027 prices soon", "Bookable until Sep 2027") moved to
phase 6, with the listing sorting already planned there: placing them needs
the store's category list templates, which `docker/fullstore/export-theme.sh`
does not pack yet (it packs the product page's).

### 16.9 Next

6. Calibration on the live store (§14) once the suppliers have recorded for a
   few weeks; then set the privacy policy page and switch the panels on.
7. Phase 3 (Automatic mode for Novoton) before the Feb–Mar 2027 review.
