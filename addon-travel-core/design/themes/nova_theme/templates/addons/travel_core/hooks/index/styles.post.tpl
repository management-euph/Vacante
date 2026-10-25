{**
 * Travel Core - CSS Styles Connection Hook
 *
 * Loads shared CSS files used by all travel provider addons.
 * Provider-specific styles are loaded by each provider's own hooks.
 *
 * @package TravelCore
 * @since 1.0.0
 *}

{* Design tokens — LESS variables + :root --nvt-* bridge. MUST load first:
   every stylesheet below (and the provider addons' CSS) consumes these
   custom properties. Theme Editor color pickers bind to the LESS variables. *}
{style src="addons/travel_core/styles.less"}

{* Booking engine React component styles *}
{style src="addons/travel_core/booking-engine.css"}

{* Booking form styles (guest details, DOB, multi-room) *}
{style src="addons/travel_core/booking-form-react.css"}

{* Shared search-results design system (offer cards, badges) —
   the class contract every provider's results page renders with *}
{style src="addons/travel_core/search-results.css"}

{* Shared booking-page design system (summary, guest cards, terms,
   services, submit) — the class contract provider booking forms use *}
{style src="addons/travel_core/booking-pages.css"}

{* Hotel page Design A (blocks/product_templates/travelcore_design_a.tpl);
   every rule is scoped under .travel-pdpa *}
{style src="addons/travel_core/design-a.css"}
