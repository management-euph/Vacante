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

(pending)
