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
- [ ] The Travel Core list view (`blocks/product_list_templates/`) inside
      CS-Cart's sorting, paging and view switch, and the "Load N more"
      script — wired against the store's own list templates, which
      `docker/fullstore/export-theme.sh` now packs (list views, sorting,
      pagination, filters, the busiest category page as a visitor gets it).
- [ ] Product price = indicative price at each supplier's sync.
- [ ] City shortcuts and the "Oraș" filter.
