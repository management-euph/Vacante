# Hotel page — Design A: development plan

Status: **in progress** (started 25 Oct 2026). The owner approved Design A for
development. The source of truth for the layout is the Design A mockup board
(14 frames) and the written proposal on the design canvas; this file records
the decisions and the order of work so any session can pick it up.

## How the owner switches designs

Each design is a CS-Cart **product template**. CS-Cart lists every
`blocks/product_templates/<file>.tpl` in *Settings → Appearance → Product
detailed page view* (store default) and in each product's *Product details
view* field, labelled by the bare lang var named after the file (see
`TravelcoreProductTemplateTest`).

| Template file | Label | What it is |
| --- | --- | --- |
| `travelcore_template.tpl` | Travel Core Template | the current hotel page, unchanged |
| `travelcore_design_a.tpl` | Travel Core · Design A | new (this plan) |
| (later) `travelcore_design_b.tpl` | Travel Core · Design B | not started |

Design A stays opt-in: nothing changes on the live store until the owner
selects it (for the whole store or for one product to try it first).

The results inside the page (rooms and prices) follow the template: the
provider search request knows the product, and travel_core answers which
template that product uses, so the supplier search renders the Design A
results only for a Design A page. The standalone search results pages keep
their current layout.

## What Design A is (from the mockups)

1. **First visit, no dates** — title row: hotel name, stars, location line with
   "Vezi pe hartă" (a link to the Google Maps pin, `HotelMapUrl`), and on the
   right an indicative "de la X € / noapte" (only when a reliable per-night
   figure exists) plus an outline "Alege datele" button. Gallery mosaic
   60/40 (one large photo, smaller ones beside it, "+N fotografii"), opening
   the theme's image previewer. Height `clamp(400px, 100vh - 270px, 480px)` on
   short laptop screens (`max-height: 720px`), 480px otherwise.
2. **Yellow one-line search bar directly under the gallery**: dates, guests,
   primary button "Vezi prețurile". No short description, no rooms section
   before dates are chosen, no price/reserve column next to the gallery.
3. **Results on the same page, under the bar, above the tabs** (where the
   inline search already inserts them).
4. **Real CS-Cart product tabs**, "Descriere" open by default.
5. **Compact sticky bar after a search** — a region named "Rezumatul
   căutării": dates · guests · "Schimbă".
6. **Room-first results** — the owner's board 13b (29 Oct 2026), which
   replaced the first cards (rate rows per meal group, R1–R3, best 4):
   one card per room type on three columns from 1101 px — the room | its
   meal options and what they share | the "till" — two on a tablet (the
   till under the options), one on a phone (the till pinned to the bottom
   while the card's options are in view).
   - The room: "Cel mai mic preț" on the cheapest room; the title names the
     difference ("Cameră Dublă Deluxe, fără balcon") with the supplier's
     room code under it in 12 px grey (IdRoom; none for a numeric code);
     status pills (green "Confirmare imediată", red "Ultima cameră
     disponibilă", amber "Doar N camere disponibile", grey "N camere
     disponibile", blue "La cerere"); capacity in words ("Max. 3
     persoane"); facilities, six then "Toate dotările (+N)".
   - Options: one per meal plan and terms, a radio each (the cheapest
     chosen; "Alege masa" only for screen readers); one meal plan = the
     same block, a blue tick, a white row. The same meal on the same terms
     from two packages shows once, its cheapest. What every option shares
     is said once under them (the package with its Early Booking as one
     unit, the cancellation, the payment, the terms link); what differs
     stays on its option. Package names wrap at 1.45 line height.
   - The till: the chosen meal, "Total · N nopți · N adulți", the struck
     price with its "−12%" badge (an empty line of the same height without
     one), the total in 28 px, per night, "Rezervă" (48 px) and one line
     under it: "Nu plătești încă", or on request "Răspuns în max. 48 h" with
     an ⓘ that opens, on click or tap, how a request works. The total fades
     in when the meal changes (never on load), said by a live line. The
     Early Booking line says until when and how much: "Early Booking până
     la 31 oct. · economisești 115 €"; the percent only on the badge.
   - The meal filter only with 2+ meal plans, each with its room types
     "(7)" and lowest price, the active one solid navy; it shows the rooms
     that have the meal and chooses it in them. Chips only when they split
     the options: free cancellation, instant confirmation (no "Oferte
     speciale": the owner dropped it, 29 Oct 2026). The first 4 room types, then "Vezi toate cele N tipuri de
     cameră" and "Încă K: …, de la X €". The footnote names the discount the
     struck price leaves out.
7. **Multi-room** — a tab per room, "Mergi la Camera N", the final
   "Rezervă N camere · total"; a quota shortage note with alternatives.
8. **Phone** — the same information model stacked (390px frames).

## Phases (one pull request each, from `claude/focused-johnson-krch9q`)

1. **Page shell** — the new product template and its label; title row;
   gallery mosaic; the bar mount under the gallery; tabs below with
   Descriere first; the compact sticky bar; phone CSS. Results still use the
   current markup inside the new page.
2. **Search bar** — a `bar` variant of the React booking engine (one line,
   yellow), "Vezi prețurile", calendar popup with prices, "Alege datele"
   opens the calendar.
3. **Room-first results** — a shared results view model in travel_core
   (room card, rate row, meal plans, payment line, cancellation, status) and
   one shared Smarty component with rules R1–R3 and the best-4 collapse.
4. **Supplier mappers** — Novoton first, then Sphinx (streamed rows, terms
   loaded on demand, one room per booking), then Eurosite (whole-group
   offers, 30% / 100% payment choice).
5. **Multi-room in Design A** — tabs per room, totals, quota shortage.
6. **Store fixes found by the design work**
   - Sphinx books one room (`'num_rooms' => 1`) but `ProviderRoomLimit` sets
     no limit for it, so the guest picker offers 12 rooms.
   - `DepositPolicy` covers Novoton and Sphinx only; Eurosite's 30% / 70% rule
     is admin text.
   - Status wording: the booking sidebar says "Disponibil" where the page
     says "Confirmare imediată".
   - Product tabs: arrow-key navigation (roving tabindex).

Customer-facing text never names a supplier, a supplier price, commission or
an internal code.

## Working rules for this plan

- Edit `design/themes/responsive/...` and run `composer mirror` for the
  `nova_theme` copy (production runs `nova_theme`; `ThemeMirrorTest`).
- React changes: edit `addon-travel-core/react-src/src`, rebuild with
  `npm run build` there; the committed bundles in
  `addon-travel-core/js/addons/travel_core/` are the artifact.
- Before each push: `composer stan`, `composer fix:dry`, `composer rector:dry`,
  the travel_core PHPUnit suite (plus any supplier suite touched), and
  `npx eslint .`.
- Commit and push after every working step, so no work exists only in a
  session.

## Progress log

- 25 Oct 2026 — plan written; branch restarted from `main` after #80 merged.
  Supplier data maps saved in `hotel-page-design-a-supplier-map.md`.
- 25 Oct 2026 — **phase 1 (page shell) done**:
  - `blocks/product_templates/travelcore_design_a.tpl` (both themes), label
    "Travel Core · Design A"; `css/addons/travel_core/design-a.css` (every
    rule under `.travel-pdpa`); `js/addons/travel_core/pdp-design-a.js`.
  - Title row with stars (`travel_pdp_stars`, assigned in
    `dispatch_before_display`), location + "Vezi pe hartă", wishlist/compare,
    "Alege datele" (opens the calendar); the "de la X € / noapte" slot
    renders when `travel_pdp_from_price` is assigned (not yet — phase 1b).
  - Photo mosaic 60/40 opening the theme previewer through the hidden stock
    gallery; phone: one photo with "1 / N".
  - Bar and inline results under the photos, above the tabs (existing
    mount hooks). Nothing under the bar: the owner had the two facts that
    sat there ("Confirmare imediată pentru camerele disponibile", "Prețul
    total, cu masa inclusă, înainte să rezervi") removed on 25 Oct 2026.
  - Compact search summary after a search ("Rezumatul căutării").
  - Photo height `clamp(320px, 100vh - 330px, 480px)`.
  - Verified by compiling/rendering with Smarty 5.8 and the real CSS/JS:
    bar ends at 748px of 900, 586px of 657; tests:
    `DesignAProductTemplateTest`, `tests/js/pdp-design-a.test.mjs`.
  - **To check on the dev store**: the previewer opens from the mosaic
    (it relies on the theme's `a.cm-image-previewer` links), wishlist /
    compare buttons in the title row, a theme sticky header vs the compact
    summary (fixed at the top).
- 25 Oct 2026 — **phase 1b (indicative price) done**: travel_core
  `Contracts\IndicativePriceSourceInterface` +
  `TravelProviderRegistry::setIndicativePriceSource()` /
  `indicativeNightlyPrice()`; sources `NovotonIndicativePrice`,
  `SphinxIndicativePrice` (the calendar_prices cron now stores the nights it
  priced under `sphinx_calendar_prices_nights`), `EurositeIndicativePrice`;
  `travel_pdp_from_price` is formatted by `MoneyFormatter::forStore(true)`.
- 25 Oct 2026 — **phase 2 (search bar) done**: the engine detects a Design A
  page (`[data-travel-design="a"]`) and asks `booking_config` with
  `design=a`, which adds per-night calendar prices (two adults, shopper's
  currency) from `IndicativePriceSourceInterface::nightlyCalendar()` and the
  bar's texts. Split Check-in / Check-out fields, "Oaspeți", "Vezi
  prețurile"; calendar (`variant="a"`): stay header with nights, legend,
  "Schimbă oaspeții", "Șterge datele", its own search button; opening from
  Check-out moves only the check-out; the check-out day shows no price.
  Shared calendar fixes: even day columns, full-width phone calendar.
  `TRAVEL_CACHE_VER` 9. Test: `DesignASearchBarTest`.
- 25 Oct 2026 — **first look on a real CS-Cart store** (owner's screenshot):
  the theme lays out the stock `ty-product-block__wrapper` children side by
  side, so the title row sat next to the photos → Design A's wrapper is now
  `travel-pdpa__wrapper` and stacks its rows; the theme's image rules kept
  the large photo at its own proportions → every photo fills its tile
  (`!important`, the tile is the frame); the header feature list under the
  name is gone. Location line printed once (capture `travel_pdp_design`).
- 26 Oct 2026 — **checked against the store's own files** (export of
  `docker/fullstore/export-theme.sh`: CS-Cart 4.21.2, Nova Theme, product 433,
  a Eurosite hotel): the fixes above hold with the real CSS (title row full
  width, photos fill their tiles). The theme's header and breadcrumbs take
  244px, so `pdp-design-a.js` now sizes the photos to keep the bar on the
  first screen (240–480px). Phones: the price beside wishlist/compare,
  "Alege datele" full width. The stock gallery's links carry
  `cm-image-previewer` (Swiper previewer), as the mosaic expects. The
  Eurosite search for 2–8 Nov returned no offers (out of season), so the
  room cards still need an export with offers.
- 26 Oct 2026 — **white bands beside the photos** (owner's screenshots of
  two hotels): CS-Cart never enlarges a photo into a thumbnail; asked for
  1200px of a 1024px photo (hotel 433's photos are all 1024 wide) it centres
  the photo on a 1200px canvas in the thumbnail background colour (white),
  and small tiles asked 600px of narrower photos got white edges. Each tile
  now asks at most the photo's own width (`image_x`), so every thumbnail is
  photo edge to edge. A photo too small for its tile (filling it would
  enlarge it more than 1.5×) or too tall for it (filling would crop away
  more than half) shows whole, at most 1.5× its size, over a blurred copy of
  itself (`pdp-design-a.js`, `.travel-pdpa-gallery__tile--whole`).
- 26 Oct 2026 — **phase 3 (room-first results) and the Novoton mapper
  (phase 4, first supplier) done**, checked with the store's export of
  Admiral (hotel 476, product 292, 10–17 Jul 2027: eight room types with one
  rate each, all Ultra All Inclusive, Early Booking −30%, free cancellation
  until 30 Jun 2027 — the board's one-meal-plan case):
  - travel_core `Results\RateOffer` (what a supplier hands over per rate:
    customer-facing text, primary-currency totals, ISO dates) and
    `Results\RoomResultsBuilder` (the room cards by the board's rules: the
    meal strip only with 2+ meal plans, one meal plan said once in the fact
    line, chips only when they split the list, what a room's rates share
    said once, the best 4 per room, above 12 rates the other rooms collapsed
    to their cheapest; the room's cheapest rate has the filled button);
    `Results\DesignA` (design=a, the store's builder).
  - `components/design_a_results.tpl` with `_room`, `_rate`, `_payment`,
    `_terms` (structured terms as the booking sidebar's timeline,
    `booking_terms_timeline.tpl`) and `_icon`; CSS `design-a.css` #60
    (desktop, ≤1100px, phones); `pdp-design-a.js` filters by meal plan and
    chips, sorts the rooms, opens "see all"; 61 texts in en / ro with the
    three Romanian plural forms (`RoomResultsBuilder::form()`).
  - The engine sends design=a with the inline search (`TRAVEL_CACHE_VER`
    10). Novoton's search builds the cards from the same rows for a
    one-room search (`DesignARates`: the room's name without its code and
    what sets it apart from the code's words, "DBL 2+0 DELUXE NO BALCONY" →
    "Cameră Dublă · Deluxe · fără balcon"; Novoton's meal texts; the
    sidebar's discount rules; quota 0 = on request; the booking link field
    for field). Several rooms keep today's selection until phase 5.
  - On the exported page with the store's CSS: the engine asks design=a, the
    cards land under the bar, no overflow at 1440 or 390 px.
- 29 Oct 2026 — **board 13b in the store, for all three suppliers**:
  - travel_core: `RoomResultsBuilder` rebuilt for 13b (options per meal plan
    and terms, the dominance rule, shared vs per-option facts, rooms-left
    tiers, first 4 room types + hint, stable keys); `RateOffer` gains the
    room title / code / capacity, the package, the discount's last day,
    free cancellation without a day, a terms URL and a payment text;
    `Results\MealRank` ranks meal names (en / ro). Templates
    `design_a_room` / `_option` / `_till` / `_package` / `_cancel` /
    `_payment` / `_terms` / `_icon` (`_rate` gone); CSS #60 rewritten;
    `pdp-design-a.js`: radios switch the till, filters choose the meal, the
    ⓘ toggletip, terms fetched as JSON `{sections}` when first opened, live
    results (`data-travel-live`) swapped in with the guest's choices kept.
    43 new texts, 17 old ones gone.
  - Novoton: the title from the code's tier and features, IdRoom, capacity
    from the occupancy, the package, "Early Booking" with its EBToDate (now
    read by `SearchService`), the hotel's room facilities.
  - Eurosite: `Services\DesignARates` (one room at one meal plan per offer,
    the cart line's price, PriceNoRedd struck with the offer's text, IM /
    OR, stop sale left out, the admin's payment line); `offer_terms`
    design=a.
  - Sphinx: `Services\DesignARates` from the flat rows (marketing price
    struck, labels, `is_free`, on request); the poll's slim rows carry the
    new fields; cached / finished searches render the cards at once, a
    running one a live container fed by `search_poll` design=a
    (`{html, done, cursor}`); `offer_terms` design=a.
  - The title row shows the hotel's product code: "COD: NVT476" between
    the location and "Vezi pe hartă".
  - Checked by rendering the real templates with Smarty 5 and the store's
    CSS / JS in Chromium at 1440, 1000 and 390 px (three columns, two, one
    with the sticky till) and driving them (radios, filters, chips, sort,
    see-all, ⓘ, terms on demand); suites: travel_core 923, Novoton 1031,
    Sphinx 627, Eurosite 319 PHPUnit tests, 304 vitest tests.
  - **To check on the dev store**: a Sphinx search on a Design A page end to
    end (the live cards, `$view->fetch` from the poll), Eurosite and Sphinx
    terms on demand against the live APIs, the phone's sticky till under
    the theme's own sticky elements.
- 29 Oct 2026 — the owner dropped the "Oferte speciale" chip (the struck
  price and its badge already say it); Qodana's three "redundant character
  escape" warnings in `pdp-design-a.js` fixed.
- 1 Nov 2026 — **phase 5, step 5a: several rooms in Design A (boards
  13c), Novoton** — committed on the branch, no pull request yet:
  - travel_core `Results\RoomGroupsBuilder`: the rooms searched grouped by
    their guests (same adults, same children's ages), one tab per group
    ("Camerele 1–8 · 2 adulți"), each group's 13b cards priced for its
    guests and keyed per group; every till counts the group's rooms (at
    most the rooms left of the type) with its totals. `RateOffer::roomData`
    (the entry the booking posts per room), `MoneyFormatter::scriptFormat()`
    (the page's prices in the shopper's currency format).
  - `components/design_a_groups.tpl` (tabs, panels, the sticky bar, the GET
    form to today's booking form) and the till's group mode (room counter,
    "Alege pentru 8 camere"); CSS #61; `pdp-design-a-rooms.js`: choices per
    group, availability shared across groups (`quota`), the next group
    opened, "Anulează" instead of a confirmation, the amber partial state,
    the closest type suggested (same meal, enough rooms, the difference
    shown, never applied by itself), "Mergi la …" only when the next rooms
    are not the ones open, "Vezi alegerile", the mixed label "Rezervă 7
    camere · cere 1". 67 texts en / ro.
  - Novoton: the multi-room search asks once per distinct occupancy (twelve
    rooms of three kinds = three requests) and now reads the hotel's quota,
    so several rooms get "rooms left" and on request like one room;
    `DesignARates::groups()` / `bookForm()` / `everyRoomPriced()`; the
    search shows the groups when every room searched has rates (else
    today's selection says which has none). add_to_cart books as many rooms
    as the search offers (`ProviderRoomLimit`, 12): it refused more than 5.
  - Suites: travel_core 1024, Novoton 1166 PHPUnit tests, 313 vitest tests.
  - **Before phase 5's pull request**: the split booking the owner chose —
    the instantly confirmed rooms booked and paid now, the on-request rooms
    requested apart. Today the page posts every room as one booking, so the
    bar's "acum … · restul după confirmare" is not yet true. Then
    `ext_board_id` in each room's entry once #113 merges; per-room mode
    ("Aleg fiecare cameră separat"); Eurosite's whole-group offer (13c.3);
    the choices kept when the page reloads.
  - The reservation of several rooms, by Novoton's documented example (two
    "DBL 2+1" of SLAVEY **** in one hotel_res_RQ): every room of the same
    hotel, package and stay goes in one request, the package compared as
    `RoomOfferRows` compares it, so a "+BEACH" a link returned as " BEACH"
    no longer splits the booking into two reservations; a request mixing
    them is refused before sending. IdRoom keeps its "+" (the form post
    sends it as the example's "%2b"; a literal "%2b" matches no room, read
    with room_price). The guest's "Customer's notes" from checkout are each
    room's Remark, without diacritics (the request is declared
    windows-1251). Separate bookings in one order stay separate requests
    (the owner's call).
  - **To check on the dev store**: a 6–12-room Novoton booking end to end
    (booking form, add_to_cart, checkout) — no real reservation can be
    sent from the session.
- Next: the split booking (above); Novoton rows through `RoomOfferRows`;
  the title row's after-search state ("10–17 iul. · de la 1.485 €", "Vezi
  camerele") and the compact summary's price (the section carries
  `data-cheapest`); the room facts the board shows beyond capacity (area,
  bed) once a source exists.
