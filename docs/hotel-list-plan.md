# Hotel list — one card for every supplier (board 15)

Mockups: the design canvas, boards **15** (the list) and **16** (the page
family). Decisions of 6 Nov 2026 are in 15.7; this file tracks the build.

## Decisions (from 15.7)

1. Desktop keeps the grid / list switch (CS-Cart's own); the phone shows one
   column and no switch.
2. No "Add to cart" on a hotel. The button reads "Vezi disponibilitățile" on
   hotels, "Vezi oferta" on packages and circuits, and opens the product (or
   offer) page, with the dates and turiști when they were chosen.
3. Without dates: the indicative price — one night, 2 adults, the store's
   currency, commission included (the one the hotel page shows without
   dates). With dates: the total for the chosen period and turiști (15.3),
   as Booking does.
4. Tabs per offer type (accommodation only, bus + hotel, flight + hotel);
   circuits on their own page, with the same card.
5. Sorting is CS-Cart's, with the options and the default set in the admin.
   For "price" to sort right, the CS-Cart product price becomes the
   indicative price at every supplier's sync.
6. Per page: CS-Cart's setting; then "Încarcă încă N", N = what is left, at
   most one page (29 hotels, 20 a page → "Încarcă încă 9").
7. Cities: the subcategories (Eurosite creates country → city) as shortcuts
   above the list, plus a CS-Cart "Oraș" filter on the left, with counts and
   multiple choice; names with diacritics.
8. "CM" comes from the API (getOwnHotelsRequest `<HotelName>` and
   getProductInfoRequest `<ProductName>`, e.g. "ALTUS CM"; checked with
   `dev/tools/eurosite-hotel-name.php`): it is shown, as the owner decided.

## Phases

1. **Category without dates** — the unified card, the indicative price, no
   supplier call; "Load N more"; product price = indicative price.
2. **Dates on the category** — Sphinx and Eurosite (one call per
   destination), then Novoton (per hotel, progressively).
3. **Packages and circuits** in the same card; the page family of board 16.

## Progress

### Phase 1

- [x] `ViewModels\ListCardFactory`: per product of a list page, from stored
      data only — kind, stars, place ("Mamaia, Constanța · România", never
      the street), the indicative nightly price, the button's label key. A
      product no supplier owns gets no card. `HotelLocationLine::destination()`
      gives the place parts, cased and de-duplicated like the hotel page line.
- [x] `ViewModels\ListLoadMore`: "Load N more" from CS-Cart's `$search`.
- [x] `functions/list_cards.php`: on `categories.view` and `products.search`,
      `dispatch_before_display` assigns `$travel_list_cards` and
      `$travel_list_more`.
- [x] `components/list_card.tpl` and `css/addons/travel_core/list.css`
      (row, grid tiles, one column on the phone), the texts (en / ro).
- [x] Two Travel Core list views, `travelcore_cards_list` (rows) and
      `travelcore_cards_grid` (tiles), body `components/list_view.tpl`, built
      against the store's own core templates (theme export, CS-Cart 4.21.2):
      CS-Cart's `fn_get_products_views()` finds them in the add-on's
      `blocks/product_list_templates/`; the body sits in the
      `common/pagination.tpl` pair (`#pagination_contents`, the target of the
      sorting, view and page links) under CS-Cart's sorting bar; the view
      switch draws `.ty-icon-travelcore-cards-*` with the glyphs of CS-Cart's
      list and grid, and hides on the phone. A product no supplier owns gets
      the same card with its CS-Cart price and "Vezi detalii". "Load N more"
      (`list-load-more.js`) appends the next page. Checked in Chromium at
      1440 / 1024 / 900 / 390 px with the store's compiled theme CSS.
- [ ] **To switch it on (admin):** Settings → Appearance → "Available product
      list views": tick "Travel Core: carduri de hotel, listă" and "…, grilă",
      untick CS-Cart's grid and list; "Default product list view": the list
      one (or per category, on the category's page).
- [x] Product price = indicative price at every supplier
      (`Services\CatalogPrice`, one rule): Novoton's compute_prices and its
      manual price update (no longer a night per person), Eurosite's product
      create / update (no longer the 7-night stay), Sphinx's calendar_prices
      run (it wrote nothing). Travel Core reprices every hotel product on the
      daily exchange_rates line (a new rate moves the prices), and on demand
      with `cron_mode=catalog_prices`. No price → 0, and the card says the
      price comes with the dates. Circuits (SPXC…) keep their price for now.
- [ ] City shortcuts and the "Oraș" filter.
