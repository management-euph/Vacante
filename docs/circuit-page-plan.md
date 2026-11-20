# Circuit page plan

A circuit's product page, its booking card and its admin tab: what was
decided, in which order it is built, and what each step delivered. Sphinx
circuits come first; every seam is supplier-neutral so Eurosite circuits can
plug in later (§6).

Mockup (the owner's canvas, private until shared):
https://claude.ai/artifact/G9Qh5jmGkK3kne9cFsYo3b

## 1. What existed before

- `?:sphinx_circuits` (the Sphinx add-on's own table): one row per circuit,
  linked to its CS-Cart product by `product_id`, with the itinerary
  (`itinerary_json`) and the page's details (`details_json`) as JSON.
- The page: Travel Core's "Design A" product template asks the supplier
  (`CircuitProductProviderInterface`) for `CircuitPage` and `CircuitDetails`
  and draws the facts, the departure bar (React `CircuitBar`), the rooms, the
  program, the hotels on the route, the inclusions and the notes.
- The bar's endpoints are named after the supplier:
  `<provider>_booking.circuit_dates`, `circuit_quote`, `circuit_booking_form`.
- Dates and prices were asked from Sphinx live for each visitor
  (`circuits/rates`, kept 15 minutes in the session).

## 2. Decisions

| Topic | Decision |
|---|---|
| Layout | One scrolling page with a section menu; the store's other product tabs follow below. |
| Booking | A sticky booking card on the right (desktop); a bottom bar that opens a sheet (phone). It is the existing bar re-housed: same dates, quote, rooms and cart logic. |
| Date picker | A month grid with each month's lowest price, then that month's departures. A circuit with only a few departures opens straight on the list. |
| Button | "Check availability" before the live check, "Book now · total" after it. |
| Price box | Up to three of the circuit's own "included" items about transport, airport taxes and luggage; a "not included" one shows as such. Nothing is claimed that the supplier's lists do not say. |
| Transport | The transport fact opens a dialog: the flights of the chosen date from the live quote (several flights are options), or a coach circuit's boarding cities and legs. |
| Extras | "Add to your trip" lists the optional services of the quoted offer (`additional_services`), with the total updated live. The static excursion cards stay informational: they carry no booking code. |
| Age | Shown only when the circuit requires it and gives at least one limit. |
| Photos | The circuit's photos become the product's CS-Cart images. Documents and the itinerary's day photos are loaded from the supplier's servers. |
| Admin | A read-only "Circuit" tab on the product page, in Travel Core, fed by the supplier. |

## 3. Departures: `?:travel_circuit_departures`

One shared table in Travel Core (`CircuitDepartureRepository`), keyed like
`?:travel_hotel_availability`: the supplier's registry name and its own ids as
text. One row per circuit, city it leaves from and date, with the supplier's
price for 2 adults (before the store's commission, which is added where a
price is shown), and, when the supplier says, `availability` (immediate /
on_request) and `seats`.

- Each supplier's sync hands over one complete reading
  (`replace($provider, $rows, $now)`): rows are upserted, and what the reading
  no longer has is removed. An empty reading writes and removes nothing, so
  an outage never empties the table. A supplier that reads a few circuits at
  a time passes those circuits' ids, and only theirs are cleared.
- Sphinx fills it from the `circuits/rates` pages the circuits sync reads
  anyway to know which circuits are still on sale: no extra calls. A rate
  that names no city takes the one the static list has for that circuit and
  day (`departures: [{date, destination_id}]`, an id of the static
  destinations, `?:sphinx_destinations`).
- It will feed the month picker, the date list, "Dates & prices", the admin
  tab and the departure month / city features.

The itinerary, inclusions, notes and the rest stay JSON on the circuit's row:
they are always read and replaced whole, never queried by day.

## 4. What the Sphinx sync keeps (`details_json`)

`CircuitStaticContent::details()` keeps, of one circuit of `static/circuits`,
only what there is to show:

| Key | Content |
|---|---|
| `included`, `not_included`, `additional_info` | items: `title`, `description`, and `category` when Sphinx gives one other than "other" |
| `group_size` | `min`, `max`, `description` |
| `age` | `min`, `max` when `is_required`; `description` |
| `difficulty` | `level` 1 to 5, `description` |
| `accommodation` | `hotel`, `category` as sent, `description` |
| `activities` | optional excursions: `title`, `description`, `duration`, `price` + `currency` when known, `category`, `highlighted` |
| `documents` | `title`, `description`, `url` |
| `images` | the photos' links (40 at most) |

`image_url` is the first photo. The whole itinerary stays in `itinerary_json`
as sent (day photos, transport legs, labels).

## 5. Steps

| # | Step | Status |
|---|---|---|
| 1 | Sync and storage: the shared departures table, the departure city from the static list, the dropped fields kept | done |
| 2 | Photos: the circuit gallery becomes the product's images (job `sync_circuit_images`, daily 04:05, then `process_image_queue`) | done |
| 3 | Page sections, both themes, EN/RO: high points, days that open and close with their photos, the trip's hotel, optional excursions, discounts, documents; difficulty, stars and age under the title | done |
| 4a | Booking bar: dates from the saved departures (live rates as fallback), month grid with each month's lowest price, "Check availability", price box (from / person and what the supplier says the price includes), flights dialog from the transport fact | done |
| 4b | Booking card: sticky in a right column (wide screens), bottom bar with the price and "Alege data plecării" (phones, tablets), the optional extras each flight's offer has previewed under its rooms (they are chosen, with the live total, on the booking form) | done |
| 5 | Admin "Circuit" tab (read-only, Travel Core, any supplier): facts, program, inclusions, excursions, where each note shows, saved departures, documents. A "Sync this circuit now" button is left for later | done |
| 6 | Filters and structured data: difficulty, group size and hotel stars as facts in Feature Mappings › Circuits (a Number feature gets the number, a list the value that starts with it, made when missing: "3 – Moderate", "20 people", "4 stars"); a schema.org TouristTrip on the page (route, days, an offer from the lowest saved departure a person), encoded with JSON_HEX_TAG | done |

The old columns `departure_ids`, `departure_names` and `departure_months`
of `?:sphinx_circuits` are gone: the circuit page's cities and the departure
month / city features read the table (`CircuitDepartureRepository::summaries()`),
and the Sphinx add-on's `SchemaMigrator` drops the columns on existing installs.

Sphinx's notes have no type, so `SphinxCircuitProductProvider::NOTE_SECTIONS`
routes them by words in their title: high points, excursions, discounts and
supplements; any other note is a thing to know. Making that list editable in
the add-on settings is left for later.

## 6. Eurosite circuits (later)

Checked against the "Circuite" chapter of the Eurosite spec. What fits as it
is: the page contract, the bar's endpoints by supplier name, the circuit
feature mapping, cart and order cards. What this plan keeps neutral for it:

- The departures table takes text ids (`196|EU`, `ROBCH1`), seats and
  "on request", and a partial reading by circuit.
- Every new part of the booking card's data is optional: Eurosite gives
  flight times without the airline, prices its extras in a separate call,
  and lists airport taxes as a service line.
- Reading a supplier's own data (Sphinx routes its notes by title) stays in
  that supplier's add-on; the page gets neutral slots.

Eurosite's own work when it is built: finding circuits by search (it has no
list), a quote by search, `AddBookingRequest` with a circuit item, and a
product-details cache keyed by product type too. Its account returned no
circuits when probed, so none of it can be tested yet.

## 7. To confirm on live data

- That `circuits/rates` names its departure cities with the same ids as the
  static destinations (the docs and the static list say so). The dev page
  `dev/tools/sphinx-circuit.php?circuit=…` prints both after a sync.
