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
6. **Room-first results** — one card per room type: an info column (room
   facts, guests, one payment line) and its rate rows (meal plan, rate name,
   cancellation, price, one primary button per row; "Cere rezervarea" for
   on-request rates with "La cerere · răspuns în max. 48 h" and
   "Nu plătești nimic până confirmă hotelul").
   - R1: the meal-plan strip shows only with 2+ plans, tiles share the row
     equally, 6+ scroll sideways.
   - R2: one meal plan → no strip, no meal sub-headers, rows titled by rate,
     one fact line ("Toate tarifele includ All Inclusive · Toate se confirmă
     imediat").
   - R3: a filter chip shows only when it splits the list; facts true for
     every rate join the fact line.
   - Many rates: the best 4 per room, then "Vezi toate cele N tarife ·
     min–max"; other rooms start collapsed.
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
