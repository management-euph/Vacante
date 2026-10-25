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

**Tests pinning it**: `StopSaleTest`, `SearchLocationAndRoomLimitTest`,
`BookingCartProductTest`, `RoomOccupancyTest`, `EurositeApiClientTest`,
`AvailabilityPlanTest`, `BookingSidebarBuilderTest`; travel_core
`InlineResultsOnPdpTest`, `AddonScriptPathsTest`.

## Novoton

(pending)

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

**Tests pinning it**: `SearchLayoutBadgeTest`, `SearchTermsModalTest`,
`VerifySkipWiringTest`, `VerifyOutageTest`, `HotelOnlySearchTest`,
`ImmediateConfirmationListTest`, `SearchOfferNormalizerTest`,
`OfferLocationTest` (poll whitelist), `OfferSnapshotStoreTest`,
`TermsFormatterTest`; JS `tests/js/sphinx-search-results.test.mjs`,
`tests/js/sphinx-multiroom-cards.test.mjs`; travel_core
`InlineResultsOnPdpTest`, `ProviderRoomLimitTest`.
