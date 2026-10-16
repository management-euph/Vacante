# Novoton hotels with availability gaps — plan

**Status:** Proposed — 2026-10-16
**Scope:** `novoton_holidays` first. The shared pieces are shaped so they can
move to `travel_core` later (see `docs/CONVERGENCE.md`), for Sphinx and Eurosite.
**Related:** `docs/adr/0001-availability-early-render-and-metrics.md`, Eurosite
`ProductGate` / `AvailabilityPlan`, Sphinx `HotelAvailabilityGate`.

Paths are relative to `addon-novoton-holidays/app/addons/novoton_holidays/`
unless they start with another addon's folder.

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
- Record evidence from the jobs that already run (no extra API calls). Add one
  new job that checks future dates per resort, including next summer's peak
  dates.
- Add an admin page **Novoton → Availability**. It filters on "no offer in the
  last X months" and has CSV export and bulk actions.
- Only *dormant* hotels (12 months without an offer) and hotels that *left the
  feed* are hidden. Hidden (status H) keeps the page and URL but drops the hotel
  from listings. A closed hotel gets a 301 redirect after a grace period.
  Nothing is ever deleted or Disabled.
- The hotel page never ends in a dead end. It can show a season notice, "notify
  me when 2027 prices appear", "ask us for a price", and similar hotels that can
  be booked now.
- Run in report-only mode first. Turn on automatic hiding only after the report
  has been checked against the 2027 early-booking season.

## 2. The four situations

| | Between seasons | Not offered by Novoton | No offer for 12+ months | Closed / left Novoton |
|---|---|---|---|---|
| Typical cause | Season over; next season's prices not loaded yet (Oct–Feb) | Hotel operates, but Novoton has no contract or allotment, or a stop sale | The hotel missed a whole selling year | Hotel closed, the contract ended, or Novoton removed it from `hotel_list` |
| How we recognise it | No offer now. Offers or prices within the last 13 months. The other hotels in the resort have none either. | Other hotels in the resort sell the same dates; this one hasn't for 2+ weeks | No offer seen for 12 months | Missing from `hotel_list` 3 runs in a row (~9 days), or an admin marks it closed |
| Product | Stays Active | Stays Active | Hidden | Hidden, then a 301 after 6 months |
| Customer sees | "Summer 2027 prices are not published yet. Last season: Jun–Sep", plus Notify me and similar hotels | "Online booking is not available right now", plus Ask us for a price and similar hotels | Direct link only: "No offers for over a year", plus similar hotels and Notify me | "No longer available", plus similar hotels; redirected later |
| Staff | Nothing to do | Purchasing list for Novoton; answer price requests | Review list: keep, wait, or close | Choose the redirect target |

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

New table `?:novoton_hotel_availability`, one row per hotel. It is a separate
table so the busy `novoton_hotels` table needs no ALTER, uninstall stays simple,
and it can move to travel_core later.

| Column | Meaning | Written by |
|---|---|---|
| `listed_last_seen_at` | Last `hotel_list` run that returned the hotel | `hotel_list` |
| `listed_missing_runs` | Consecutive good runs without it | `hotel_list` |
| `contract_from`, `contract_to` | First and last future date with a season price (NULL = none) | After `recompute_calendar_prices` / `compute_prices` |
| `contract_last_date` | Latest priced date ever seen (keeps past seasons) | Same, plus the backfill |
| `offer_seen_at` | **Last time we saw a bookable offer** ("on request" counts) | `availability_check`, hotel-page searches, bookings |
| `offer_stay_date` | Check-in date of that offer | Same |
| `offer_next_date` | Earliest future check-in with an offer in the latest check | `availability_check` |
| `offer_checked_at` | Last check that answered for this hotel, offer or not | `availability_check` |
| `offer_months` | JSON: stay months with offers → when last seen, e.g. `{"2026-07":"2026-06-28"}` | Same |
| `last_booking_check_in` | Check-in of the latest confirmed booking | Nightly, from `novoton_bookings` |
| `state`, `state_since`, `state_reason` | Derived state (§5) | `availability_check` |
| `gate_hidden` | This gate set the product to H | The gate |
| `override`, `override_note`, `override_by`, `override_at` | Admin decision: keep visible / keep hidden / closed | Admin page |
| `redirect_to` | For closed hotels: target product, category or URL | Admin page |

A second table, `?:novoton_hotel_state_log`, records every change: hotel,
product, old → new state and status, reason, who, and when. Every change can
then be explained, and the log feeds the weekly email.

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
- **Bookings:** nightly `MAX(check_in)` over confirmed bookings.

### 4.3 Active check: new job `availability_check`

This job checks what a hotel offers in the future, not just next week.

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
- **Schedule:**
  - Daily. Each run covers one seventh of the resorts for the season dates (so
    every resort weekly), and every resort for the near dates during May–Sep.
  - Batched and resumable like the other jobs (`max_time`, lock, run log).
  - The existing `room_price` job can stay for product creation. Alternatively,
    `add_hotels_as_products` can switch from `has_room_price` to the evidence, so
    seaside hotels can also become products in winter.
- **Only answers count.**
  - A resort whose call failed changes nothing: no evidence lost, no "no offer"
    written.
  - This avoids the problem at `RoomPriceCheckCommand.php:108-135` and audit
    `eurosite-platform-3`.
- **Reuse the date logic instead of copying it.** Move Eurosite's pure
  `AvailabilityPlan::parseDays/parseSeasonDates/windows` into travel_core (for
  example `Services\AvailabilityWindows`) and use it from both addons, as
  `docs/CONVERGENCE.md` prescribes.
- **Afterwards** the job classifies every hotel (§5) and, in enforce mode,
  applies the gate (§10).

### 4.4 Backfill on the first run

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

## 5. Classifying hotels

The classifier is a pure class with no database and no clock, like
`AvailabilityPlan`. For example:
`Services\AvailabilityPolicy::classify($evidence, $resortStats, $settings, $today)`.
It is unit-tested, including the October case. The rules are checked in order,
and the first match wins:

1. Admin override `closed` → **closed**.
2. Offer seen in the last 7 days → **bookable**. "On request" counts.
3. Missing from `hotel_list` for 3 good runs → **left the feed**. After 6 months
   it is proposed as **closed**.
4. Last availability (§4.4) is more than 12 months old, the hotel has been known
   for at least 12 months, and the latest check of its resort answered →
   **dormant**.
5. The resort is selling but this hotel has had no offer for 14 days →
   **not offered**. "Selling" means at least 25% of its checked hotels had offers
   on the same dates in the last 2 checks.
   - If the hotel had offers in the last 30 days, label it *sold out* instead.
     It is the same state and the same panel.
6. An offer, a published price or a booked stay within the last 13 months, or
   prices published for a future date → **between seasons**. A flag records
   whether next season is already published.
7. Anything else → **no data yet**.

Rule 5's resort comparison is what tells "the whole coast is closed" apart from
"Novoton isn't selling this hotel":

- **October:** no Sunny Beach hotel has offers, so all of them are between seasons.
- **July:** if 60 of 80 hotels have offers and one has had none for two weeks,
  that one is not offered.

Every threshold is a setting:

- `fresh_days` 7
- `delisted_runs` 3
- `dormant_months` 12
- `season_memory_months` 13
- `peer_share` 25%
- `not_offered_days` 14
- `closed_redirect_months` 6

**Example, as of 16 Oct 2026:** a hotel that sold in August 2026 stays "between
seasons" until August 2027 at the earliest. The automatic rules cannot hide it
this winter.

## 6. Report: hotels without availability in the last X months

A new admin page, **Novoton → Availability**, with its own controller
(`controllers/backend/novoton_availability.php`), template, menu entry and
permission entry. It replaces the `hotels` / `view_hotel` modes in
`controllers/backend/novoton_holidays.php`, which have no templates and are never
rendered.

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
  - open requests (notify me / ask for a price);
  - suggested action.
- **Bulk actions:**
  - hide, show, keep visible;
  - mark closed, set redirect target;
  - clear override, export CSV.
- **Dashboard tile:** counts per state, plus an attention line when hotels are
  waiting for a decision.
- **Weekly email**, through the existing `sendReport`:
  - hotels newly dormant, newly out of the feed, or back on sale;
  - not-offered hotels that have demand;
  - "notify me" emails sent.

The core query:

```sql
SELECT h.hotel_id, h.hotel_name, h.city, h.product_id, p.status,
       a.state, a.offer_seen_at, a.offer_stay_date, a.contract_from, a.contract_to,
       a.listed_last_seen_at, a.last_booking_check_in
FROM ?:novoton_hotels h
LEFT JOIN ?:novoton_hotel_availability a ON a.hotel_id = h.hotel_id
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
  - **[Ask us for a price]** (dates, guests, contact);
  - similar hotels that have offers.
- **Price requests:**
  - they go to the "Availability Requests" grid;
  - staff can sell offline, through a direct contract or another supplier
    (decision 5).
- **Purchasing list:** not-offered hotels sorted by demand (views and requests),
  exported as a CSV for the Novoton account manager to request allotment or a
  contract.
- **The same hotel through another supplier:**
  - Today every product belongs to one provider. There is no shared hotel
    identity: Sphinx exposes a GIATA id, but it isn't stored.
  - Short term, an admin can point the redirect at the Sphinx or Eurosite
    product for the same hotel.
  - Long term, store GIATA ids and match hotels across providers.
- **After 12 months** still not offered, the hotel becomes dormant (§7.3).

### 7.3 No availability for 12 months or more (dormant)

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
  product Active again automatically (H → A, only if the gate hid it).
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
  - A Novoton frontend controller, `controllers/frontend/products.pre.php`,
    redirects `products.view` for a closed hotel with
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
| "Notify me when available" really sends a staff alternatives request | Two clear actions: "Notify me when prices appear" (a watcher) and "Ask us for a price / alternatives" (staff) |
| The season note prints a raw language key | Fixed key; dates from the evidence (`contract_from/to`, or last season's months) |
| The date picker opens on next week, with no limits | Opens on the first published date; dates outside the window are marked |
| Every hotel in a listing looks the same | Optional labels, and bookable hotels first (phase 6) |

**Lead storage:**

- Add `request_type` (`alternatives` · `notify` · `quote`) to
  `novoton_alternative_requests` and to its travel_core mirror. The existing
  "Availability Requests" grid then shows and filters them.
- Add the mirror table to travel_core's schema self-heal (audit `data-perf-2`).

**New language keys** go in EN and RO, in `lang_keys.php` and both `.po` files
(`LangSeedParityTest`). They cover:

- season not published yet;
- last season;
- notify me;
- ask for a price;
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
- **Only answered checks count**, per resort (the Eurosite rule).
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
- **Every change logged** in `novoton_hotel_state_log` and included in the
  weekly email.

## 11. Decisions for you

1. **Between seasons:** keep hotels listed (recommended), or hide them as
   Eurosite does today? Eurosite hides them, Sphinx hides even more, and Novoton
   shows them. All three should end up behaving the same way.
2. **Dormant:** after 12 months without an offer (recommended)? Anything
   shorter catches the winter gap.
3. **Left the feed:** hide automatically after 3 missed runs, or only after an
   admin has looked?
4. **Closed hotels:** after 6 months, redirect to the resort page by default, or
   always to a hotel an admin chooses?
5. **Not offered:** do you want to sell these offline ("Ask us for a price"),
   and who answers those requests?
6. **Notify me:** email only, or phone too? Which consent text?
7. **Report-only until when?** Recommended: until the 2027 early-booking prices
   are loaded (Feb–Mar 2027) and the report has been checked. Then enforce for
   *dormant* and *left the feed* only.

## 12. Phases

| Phase | What ships | Customers see it | Size |
|---|---|---|---|
| 0 | Calibrate (§14); fix the data bugs in §13 | Small fixes | S |
| 1 | Schema self-heal; evidence and log tables; passive capture; `availability_check` in report mode; classifier; backfill; Availability page, CSV, dashboard tile, weekly email | No | L |
| 2 | Hotel-page panels; "notify me" and "prices are out" emails; ask for a price; similar hotels; date-picker window; language keys | Yes | M |
| 3 | Gate in enforce mode: dormant / left the feed → Hidden, and back to Active; brake; overrides; `gateHidden` for bookings | Yes | M |
| 4 | Closed hotels: mark closed, redirect target, 301 controller, grace checks | Yes | S |
| 5 | Purchasing list of not-offered hotels with their demand; offline price requests | Staff only | S |
| 6 | Listing labels and sorting; move the policy, date windows and evidence table to travel_core; season dates for Sphinx; history for Eurosite | Yes | M |

**Repo rules every phase must pass:**

- The CI gates: PHPStan level 10, both CS Fixer configs, Rector, PHPUnit.
- The schema tests: `LangSeedParityTest`, `UninstallCompletenessTest` and
  `GodFileRatchetTest`.
- New code goes into new classes, because `functions/hotels.php`,
  `functions/email.php`, `controllers/backend/novoton_prices.php` and
  `novoton_tools.php` are each within one line of their size ceiling.
- Novoton's schema changes ship through a runtime `Install\SchemaMigrator`,
  ported from Sphinx/Eurosite. This also closes audit `data-perf-3`.
- New cron modes go into the `STAGES` / `SCHEDULES` of `CronPlanBuilder`, with
  `dash_job_*` language keys.

**New tests:**

- `AvailabilityPolicyTest`, covering the October, July, sold-out, left-the-feed
  and override cases;
- the date-window tests, moved from Eurosite;
- gate tests: guards, the brake, release;
- the hotel_list missing-run guard;
- the report query;
- schema parity;
- the gate flag in `HotelCartProduct`.

## 13. Fix these first (they distort availability)

- **`RoomPriceCheckCommand.php:108-135`** stores failed calls as "no price".
  When a call didn't answer, leave the flag unchanged.
- **The season note** (`search.tpl:636-648`) uses
  `novoton_holidays.accommodation_period`, which doesn't exist, so the raw key
  prints.
  - The season dates come from readers that expect `DateFrom/DateTo` under
    `seasons.season`.
  - The price-compare tool and `CalendarPriceBuilder` read `FromDate/ToDate`
    instead.
  - Confirm the real shape (§14) and read it the same way everywhere.
- **`PriceComputeCommand::extractMinPrice`** builds the "from" price from past
  seasons too. Use only seasons that end today or later.
- **Alternative-request emails use template codes that don't exist.** The code
  uses `novoton_alternatives_*` (`src/Services/AlternativeRequestService.php:354`,
  `src/Cron/Commands/AlternativesCommand.php:263`). `addon.xml:1003,1041`
  registers `novoton_holidays_alternatives_*`. Customer emails are therefore
  probably never sent, and this must work before "notify me" relies on email.
- **`OffersUpdateCommand.php:41-47`** needs a `product_import` log row that
  only it writes, so on a store without one it never starts.
  - Also, `CleanupCommand` trims `novoton_sync_log` to 100 rows, so no new state
    may live there.
- **`BatchedHotelInfoSyncV2.php:426`** writes `package_name` to
  `novoton_hotels`, which has no such column.
  - CS-Cart's `?u` placeholder normally drops unknown columns, so this is
    probably a silent no-op.
  - Confirm it anyway (§14, query 5): if that UPDATE failed, new-season packages
    would never be saved, which would look exactly like an availability gap.
- **Privacy, while there:** the travel_core mirror stores the requester's email
  and phone in plain text. Novoton's own table encrypts them.

## 14. Calibrate first (phase 0)

### API probes

Run these in PowerShell from the repo root. Use the same values as Admin →
Add-ons → Novoton Holidays → Settings; the probes mask them in their output.
Without `NOVOTON_API_USER` / `NOVOTON_API_PASSWORD`, the probes send the test
key with an empty login, and Novoton answers with an empty body.

```powershell
$env:NOVOTON_API_KEY      = "..."
$env:NOVOTON_API_ID       = "..."
$env:NOVOTON_API_USER     = "..."
$env:NOVOTON_API_PASSWORD = "..."

# 1. Fields per hotel; is there any status flag? (works off-season too)
php dev/novoton/hotel_list.php --country=BULGARIA --city="SUNNY BEACH" --limit=5
# 2. Packages of one hotel: are the 2027 packages loaded yet?
php dev/novoton/hotel_info.php <hotel_id>
# 3. Season field names and dates of one package
php dev/novoton/price_info.php --hotel_id=<hotel_id> --package="<PackageName>" --limit=5
# 4. A July 2027 offer for one room (room and board codes from step 2)
php dev/novoton/room_price.php --hotel_id=<hotel_id> --room_id="<IdRoom>" --board_id=<IdBoard> --check_in=2027-07-10 --check_out=2027-07-17 --adults=2
# 5. What Novoton answers for a hotel it no longer lists (pick one from query 2 below)
php dev/novoton/hotel_info.php <hotel_id_missing_from_feed>
```

### SQL on the live database

The default table prefix is `cscart_`.

```sql
-- 1. Hotels by product status and price signals
SELECT IFNULL(p.status, '-') AS product_status, h.has_room_price,
       h.calendar_prices_raw IS NOT NULL AS has_future_prices, COUNT(*) AS hotels
FROM cscart_novoton_hotels h
LEFT JOIN cscart_products p ON p.product_id = h.product_id
GROUP BY 1, 2, 3 ORDER BY 1, 2, 3;

-- 2. Hotels not returned by hotel_list for 10+ days (left the feed?)
SELECT country, city, COUNT(*) AS hotels, MAX(hotel_list_synced_at) AS last_seen
FROM cscart_novoton_hotels
WHERE hotel_list_synced_at < NOW() - INTERVAL 10 DAY
GROUP BY country, city ORDER BY hotels DESC;

-- 3. Active products without any future price (what customers can land on today)
SELECT h.country, h.city, COUNT(*) AS active_without_future_prices
FROM cscart_novoton_hotels h
JOIN cscart_products p ON p.product_id = h.product_id
WHERE p.status = 'A' AND h.calendar_prices_raw IS NULL
GROUP BY 1, 2 ORDER BY 3 DESC;

-- 4. The season field names actually stored
SELECT hotel_id, package_name, LEFT(JSON_EXTRACT(priceinfo_data, '$.seasons'), 400) AS seasons
FROM cscart_novoton_hotel_packages
WHERE priceinfo_data IS NOT NULL LIMIT 3;

-- 5. Does novoton_hotels have the package_name column the hotelinfo sync writes?
SHOW COLUMNS FROM cscart_novoton_hotels LIKE 'package_name';

-- 6. Last booked stay per hotel (input for the backfill)
SELECT hotel_id, MAX(check_in) AS last_check_in, COUNT(*) AS bookings
FROM cscart_novoton_bookings
WHERE status IN ('confirmed', 'completed')
GROUP BY hotel_id ORDER BY last_check_in;
```

The answers set the thresholds in §5 and the effort for phases 1–2. They show:

- how many hotels are affected today;
- whether 2027 prices are already arriving;
- the real season format;
- how Novoton answers for a removed hotel.
