# Hotel page — Design A: what each supplier's results give us

Companion to `hotel-page-design-a-plan.md`. A factual map of how each
supplier's hotel results are produced and rendered today (25 Oct 2026), so the
room-first Design A results can be fed from the same data. Paths are relative
to the addon's `app/addons/<id>/` unless they start with `addon-` or `design/`.

On the product page the React engine (`BookingEngine.jsx`, `performAjaxSearch`)
fetches `<supplier>_booking.search` and copies everything after
`.travel-search-form-wrapper` inside `.travel-search-results-page` into the
page, re-runs same-origin scripts in the copied part, then fires
`travel:results-swapped`. Scripts outside the copied region are not run.

## Eurosite

**Flow** (`controllers/frontend/eurosite_booking/search.php`)
- The hotel comes from `hotel_id`; `product_id` is kept only when it is this
  hotel's product. Country and city come from the hotel row, never the request.
- Rooms: `RoomOccupancy::fromRequest(rooms_data)`, one `<Room>` per requested
  room (code SB/DB/TR/Q by adults). Guards: dates present, rooms ≤ 12
  (`ProviderRoomLimit`), city whitelisted.
- One `getHotelPriceRequest` per tour-operator code of the city (city-wide,
  cut down to this hotel afterwards). Prices are never cached; `refresh=1` is
  ignored. A session snapshot (`OfferContextStore`, max 200 offers) is
  replaced on every search; offers past index 200 get no key and render as
  stop sale.
- **Every offer covers the whole group** (all rooms, one total price). There
  is no per-room choice.

**Data** (`src/Dto/HotelOffer.php`, `EurositeApiClient::mapOffer`)
- rooms[] (code, gcode, name, quantity, price), meals[] (type, code, name),
  offerType ("Normal"), grila ("Standard"), price = whole stay for all rooms
  (+ gross, net, commission — never shown), currency, availability text + code
  IM / OR / ST (`isBookable` = not ST).
- The pre-discount price (`PriceNoRedd`, `oldPrice()`) reaches only the
  session snapshot and the booking sidebar, never the results.
- Not computed for results: nights, price per night, old price, per-room price.
- Cancellation terms are not in the search: a modal fetches
  `eurosite_booking.offer_terms` (getItemFees with placeholder guests).
- Payment rule: admin text `payment_terms_text` ("Avans 30% … / Diferența de
  70% …"); `DepositPolicy` excludes Eurosite on purpose.
- **min_price is per whole stay, not per night**: the cheapest offer at the
  best availability for one DB room, 2 adults, `availability_nights` nights
  (default 7) on near and peak dates (`AvailabilityPlan`, `AvailabilityCommand`),
  stored on `eurosite_hotels` (min_price, price_currency, availability,
  availability_check_in, meals) and used as the CS-Cart product price.

**Markup** (`design/themes/responsive/templates/addons/eurosite/views/eurosite_booking/search.tpl`)
- `.travel-search-results-page.eurosite-search-results-page` > form wrapper,
  notices, one `.eurosite-hotel-group` (image, stars, city, description), then
  one `.travel-offer-card.eurosite-offer-card` per offer in API order: rooms
  (`ol.travel-offer-rooms` when several), boards, dates, grila, availability,
  "info" link (`a.eurosite-info-link[data-offer-key][data-modal-id]`), price
  ("Total for N rooms") and the Book link.
- Book = plain GET `eurosite_booking.booking_form?offer_key=…&return_product_id=…`
  (key = md5 of code|variant|series|in|out|price; the rest is re-read from
  the session).

**Found while mapping (to fix)**
- The terms modal script (`js/addons/eurosite/search-results.js`) is included
  outside the copied region and Eurosite has no `hooks/index/scripts.post.tpl`,
  so on the product page the "info" modal likely does nothing.
- `window.EurositeI18n` is read but never defined (Romanian fallbacks show).
- `data-extra-params` (country/city) is unused for Eurosite on the product page.

**Design A, board 13b (29 Oct 2026)**: `src/Services/DesignARates.php`
turns this hotel's offers into travel_core `Results\RateOffer`s for a
one-room search when the page sends design=a (`search.php` collects the
offers with their snapshot keys; `search.tpl` shows
`components/design_a_results.tpl` in place of the list; none bookable →
the list, which says stop sale). One offer = one room (`rooms[0]`, its
numeric `code` not shown) at one meal plan (`meals[].name`, ranked by
`MealRank`; none = "Fără masă"); the price on the cart line's scale
(currency coefficient, as `add_to_cart`), `oldPrice()` struck with the
offer's `OfferDescription`; IM / OR; ST left out; the admin's payment terms
(first line) as the card's payment line; "Rezervă" = today's booking link.
Terms load when opened: `offer_terms` with design=a answers
`{sections: [{title, lines}]}` (`DesignARates::termsSections`: the fees as
the booking sidebar words them, or "confirmed at booking"; the payment
terms). Tests: eurosite `DesignARatesTest`, `DesignAResultsWiringTest`.

**Tests pinning it**: `StopSaleTest`, `SearchLocationAndRoomLimitTest`,
`BookingCartProductTest`, `RoomOccupancyTest`, `EurositeApiClientTest`,
`AvailabilityPlanTest`, `BookingSidebarBuilderTest`; travel_core
`InlineResultsOnPdpTest`, `AddonScriptPathsTest`.

## Novoton

**Flow** (`controllers/frontend/novoton_booking/search.php`)
- Params pass `SecurityService::validateSearchParams`;
  `SearchParameterNormalizer` parses `rooms_data`. No `check_in` → defaults
  only; no `hotel_id` → redirect to `products.search`.
- `HotelAvailabilitySearcher::search`: cached hotelinfo (1800 s) gives the
  room-code → room-type map.
  - One room: one `room_price` call for the whole hotel + one `hotel_quota`
    call, then `SearchService::parseRoomPriceResponse` + `deduplicateResults`.
  - Several rooms: `MultiRoomSearchBatcher`, one `room_price` per room in
    parallel, no quota call, no dedup; `all_room_results` keyed 1..N.
  - No availability: `AlternativeDateSearcher` (±`flex_days`, ≤ 50 calls).
- Caching: `room_price` 300 s (only answers with a price), quota 180 s; no
  whole-result cache; `refresh=1` ignored.
- View variables (`SearchResultFormatter::assignToView`): `novoton_results`,
  `novoton_params` (dates, nights, guests, rooms_data…), display currency
  (`novoton_display_currency`, `_coefficient`, `_symbol`,
  `novoton_round_prices`), `all_room_results` / `is_multi_room_search`,
  `max_room_capacity`, early-booking info, alternatives, hotel identity,
  `novoton_room_facilities` (hotel-level list).

**One result row** (`SearchService.php:172-333`)
- A flat list in API order, **not grouped by room**, with no offer id.
- **The search parser lines up separate XML lists by position**, which
  `RoomOfferRows` (used at checkout) documents can attach values to the wrong
  offer. Design A should read Novoton offers through `RoomOfferRows`.
- Room: `room_id` (raw code, e.g. "DBL 2+1"), `room_type_display`
  ("Camera Dublă (DBL 2+1)"). No room photo, no per-room facilities, no
  per-row max occupancy.
- Board: `board_id` (raw) + `board_name`; the template rebuilds the label from
  a hard-coded map.
- Rate type: no field — offers differ by `package_name`,
  `early_booking_discount` (%) and `extras` ("7 = 6"); single-room dedup keeps
  the cheapest per room|board|package and folds the promo into `extras_price`.
- Price: `total_price` = whole stay for that room's occupancy, EUR, with
  commission; `price_per_night` exists but is not shown. The struck "was"
  price is computed in the template (also in
  `NovotonBookingSidebarBuilder::discount`).
- Availability: `rooms_available` / `is_on_request` from quota (per room
  code); no quota entry → bookable with nothing shown; no stop-sale state.
  Multi-room rows always null/false.
- Cancellation: raw `<Penalty>` XML + `free_cancellation_date` (first
  zero-penalty date); no non-refundable flag. Payment: raw `<Percent>` XML;
  deposit only on the booking form and at add-to-cart.

**Markup** (`views/novoton_booking/search.tpl`, 729 lines)
- Root `.travel-search-results-page.novoton-search-results-page`, form wrapper,
  early-booking banner.
- Multi-room: `#multi-room-selection.novoton-mr` (data-* config), one
  `section.room-type-selection[data-room][data-occupancy]` per room with
  radios `room_N_selection` carrying data-room-id, board-id, price,
  standard-price, was-price, quota…, a summary aside and the hidden GET form
  `#multi-room-booking-form`. **`js/addons/travel_core/multiroom-booking.js`
  depends on this DOM** (ids, classes `is-open/is-chosen/is-selected`,
  sessionStorage `travel_mr_selection:{search-key}`).
- One room: `.novoton-results-list` > `.travel-offer-card.novoton-offer-card`
  in three zones (room, choices with terms modal, price + Book).
- Book (one room) = GET `novoton_booking.booking_form` with room/board codes,
  dates, guests, price, standard_price, package, extras, early_booking,
  is_on_request, rooms_available (no product id). `add_to_cart` re-prices
  everything (`RoomsQuote` / `BookedOffer`), never trusting posted prices.
  It looks for the offer as the booking form's re-price does
  (`BookedOffer::find`): the room on its board, then every room and board,
  since Novoton answers some packages' rooms only without a board (Holiday
  Park, 270). Unpriced, the guest goes back to the same booking form
  (`BookingFormLink`).

**"From" prices before dates**
- Calendar prices (`CalendarPriceBuilder` → `novoton_hotels.calendar_prices_raw`):
  per date, the cheapest regular room's **1-night total for 2 adults**, raw
  EUR; commission and currency are added at display. They reach only the
  booking form's date picker; the product-page engine passes
  `calendarPrices: null`, so the hotel page calendar shows none.
- The catalog product price is one priceinfo cell (per person or per room),
  not normalized for occupancy.

**Found while mapping (to fix)**
- "Change search", nearby dates and the alternatives button link to the
  standalone results page and drop `rooms_data`.
- `nearby_availability` is read by the template but never produced.

**Design A (26 Oct 2026)**: `src/Services/DesignARates.php` turns these rows
into travel_core `Results\RateOffer`s for a one-room search when the page
sends design=a (`controllers/frontend/novoton_booking/search.php`, step 6b;
`search.tpl` includes `components/design_a_results.tpl` in place of the
cards). Checked with the store's export of Admiral (hotel 476, 10–17 Jul
2027): eight room types, one rate each, Ultra All Inclusive, Early Booking
−30%, free cancellation until 30 Jun 2027 (`DesignARatesTest`). It still
reads the rows the positional search parser builds; reading them through
`RoomOfferRows` is the next Novoton step.

**Board 13b (29 Oct 2026)**: the card's title from the code's tier and
features ("DBL 2+0 DELUXE NO BALCONY" → "Cameră Dublă Deluxe, fără
balcon", `roomTitle()`), the code itself under it, capacity from the
occupancy (`capacity()`: 2+1 → 3), the package (`package_name`), "Early
Booking" without its percent and its last day: `SearchService` now reads
`EBToDate` into `early_booking_until` (sent with every offer, empty without
an Early Booking, like `early_booking`; the recorded answer
`tests/Fixtures/room_price_476.xml` pins it). The hotel's room facilities
(`fn_novoton_holidays_get_hotel_facilities_by_type`, hotel-level) go on
every room. Each meal plan comes from its own package; the cancellation and
payment were the same across meal plans in the API check, so the card says
them once.

**Tests pinning it**: `AvailabilityBadgeTest`, novoton `SmartyCompatTest`
(no `style=` except display:none), `NovotonBookingSidebarDiscountTest`,
`IntegrationPathsTest`, `MultiRoomSearchBatcherTest`, `RoomOfferRowsTest`,
`CalendarPriceBuilderTest`; JS `tests/js/multiroom-booking.test.mjs`,
`tests/js/novoton-search-results.test.mjs`; travel_core
`InlineResultsOnPdpTest`, `HotelHeaderComponentTest`, `ClientI18nTest`.

## Indicative "de la X € / noapte" for the title row

| Supplier | Source | Basis | Per night, 2 adults |
| --- | --- | --- | --- |
| Novoton | `calendar_prices_raw` | 1-night total, 2 adults, per date | min over future dates (+ commission) |
| Sphinx | `calendar_prices_json` | 7-night total, 2 adults, per check-in | min ÷ 7 (+ commission) |
| Eurosite | `eurosite_hotels.min_price` | stay total, 1 double room, 2 adults, `availability_nights` | min_price ÷ nights |

## Sphinx

**Flow** (`controllers/frontend/sphinx_booking/search.php`, `search_poll.php`)
- The hotel comes from `hotel_id` (404 without a row). No `check_in` → a
  warning and nothing in the results area.
- Occupancy: `SearchOccupancyResolver` (one `{adults, children_ages}` per room
  from `rooms_data`). The API search covers the whole destination
  (`hotel_ids` returns nothing) and is narrowed to this hotel afterwards.
- Cache: `search:v2:<md5>` in `?:sphinx_cache` when `enable_api_cache` and
  `cache_ttl_search` (900 s) allow; `refresh=1` evicts.
- Usually the first answer is only a cursor → status `pending`; the page
  polls `sphinx_booking.search_poll` (JSON, 250 ms interval, `search_max_polls`
  30) from `js/addons/sphinx_holidays/search-results.js` (`armPoll`, re-armed
  on `travel:results-swapped`), appending cards in arrival order (no sort, no
  de-duplication). An older poll loop is not cancelled by a new search.
- **Offers cover the whole party** (`rooms[]` one per occupancy), but booking
  is single-room: `booking_form.php` builds one room with totals
  (`'num_rooms' => 1`), and `ProviderRoomLimit` gives Sphinx 12 rooms.

**Data** (raw offer, `Documentation/Sphinx API.txt`)
- `offer_id`, `confirmation` (`immediate` / `on_request`), `check_in/out`,
  `pricing` (`marketing_price` before discount, `discount`, `selling_price` =
  stay total for the party, commission/supplier price — never shown,
  currency, taxes, additional fees), `rooms[{code, name, adults,
  children_ages}]`, `meal_type_name`, `labels`, `special_deal`,
  `payment_terms{is_loaded, rules}`, `cancellation_fees{is_loaded, is_free,
  rules}`, `must_verify`.
- **No room photos or facilities exist**; hotel images/facilities/stars sit on
  `?:sphinx_hotels` but are not joined into results.
- Flattened (`SearchOfferNormalizer`): room_name, board_name, price (with
  commission; `original_price` = before commission, not before discount),
  currency, room_lines.
- **The poll path slims rows to a whitelist** (`search_poll.php`): offer_id,
  hotel_id, product_id, names, image, stars, destination, room_name/type,
  room_lines, board_name/type, price, original_price, currency, confirmation.
  Missing there: marketing price/discount, room codes, labels, must_verify,
  `cancellation_fees.is_free`, payment terms, dates. Design A's "free
  cancellation" and "−X € vs the standard rate" need that whitelist widened.
- On-request: setting `require_immediate_availability` (default Y) drops
  on-request offers; only `immediate` offers get the "Instant confirmation"
  badge; there is no on-request label.
- Cancellation/payment terms load on demand: `sphinx_booking.offer_terms`
  (verify) returns `free_until`, `is_free`, payment and cancellation rules;
  before that the card shows only the terms link.
- Deposit: `DepositPolicy` covers Sphinx, applied at booking only.
- "From" price: `sphinx_from_price` (stay total for `nights`, from
  `cache/hotels`) exists only on a cache miss with dates — null before dates.
  Before dates only `calendar_prices_json` exists: the lowest price per
  check-in date for a **7-night, 2-adult stay**.

**Markup** (`views/sphinx_booking/search.tpl`, mirrored by the JS card)
- Root `.travel-search-results-page.sphinx-search-results`, form wrapper,
  skeleton, `#sphinx-results-container[data-search-id][data-search-status]
  [data-client-params][data-client-config]`, cards
  `.travel-offer-card.sphinx-offer-card[data-offer-id]` (rooms list, board,
  dates, instant badge, terms link, price, per night = price ÷ nights, Book),
  `#sphinx-no-results` + alternative dates, `#sphinx-terms-modal`.
- Book = plain GET `sphinx_booking.booking_form?offer_id, hotel_id,
  product_id, dates, guests, room_name, board_name`.

**Found while mapping (to fix)**
- The server-rendered card prints `$sphinx_search_params.currency`, a key that
  doesn't exist, so it always says "EUR".
- The engine's calendar footer says "1-night stay" while Sphinx calendar
  prices are for 7 nights.

**Design A, board 13b (29 Oct 2026)**: `src/Services/DesignARates.php`
maps the flat rows (commission applied) for a one-room search on a Design A
page: room = `room_name` (+ `room_code`, numeric, not shown), meal =
`board_name` ranked by `MealRank` (all capitals in title case, none = "Fără
masă"), price in the store's primary currency as the booking form and cart
convert it, `marketing_price` struck (now commissioned like the price, in
`search.php` and `search_poll.php`), the offer's `labels` naming the
discount, `cancellation_fees.is_free` = "Anulare gratuită" without a day,
`confirmation` on_request; the booking link as today's card.
`SearchOfferNormalizer` adds `room_code`, `marketing_price`, `deal_label`
and `free_cancellation` (from the nested offer only, so flat rows pass
through), and the poll's slim whitelist keeps them — cached results make
the cards too. A cache hit or a finished search renders the cards at once;
a running search renders a live container (`data-travel-live`), and the
search meta remembers the search (`design_a`): `search_poll` with design=a
answers `{status, done, html, cursor, alternatives}` — html is the whole
component for every offer so far, rendered again when new offers came;
`pdp-design-a.js` swaps it in keeping the filters, sort, "see all" and each
room's option. No room at the end: `travel:live-done`, and
`search-results.js` shows the no-results block with nearby dates. Terms:
`offer_terms` design=a answers `{sections}` (verify's lines, the search's
`is_free` during a verify outage, outage / unavailable messages). Tests:
sphinx `DesignARatesTest`, `DesignAResultsWiringTest`; JS
`sphinx-search-results.test.mjs`, `pdp-design-a.test.mjs`.

**Tests pinning it**: `SearchLayoutBadgeTest`, `SearchTermsModalTest`,
`VerifySkipWiringTest`, `VerifyOutageTest`, `HotelOnlySearchTest`,
`ImmediateConfirmationListTest`, `SearchOfferNormalizerTest`,
`OfferLocationTest` (poll whitelist), `OfferSnapshotStoreTest`,
`TermsFormatterTest`; JS `tests/js/sphinx-search-results.test.mjs`,
`tests/js/sphinx-multiroom-cards.test.mjs`; travel_core
`InlineResultsOnPdpTest`, `ProviderRoomLimitTest`.
