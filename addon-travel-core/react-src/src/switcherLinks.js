/**
 * Travel Booking Engine - language / currency switcher links.
 *
 * Dependency-free so vitest can import it directly
 * (tests/js/booking-engine-switcher-links.test.mjs).
 */

/**
 * Points the store's language / currency switcher links at the CURRENT URL.
 *
 * The server renders those links (…&sl=ro, …&currency=EUR) from the URL of
 * the page load; a search run in the page then pushes new params into the
 * address bar, and switching language would reload the OLD search (or none).
 * Only links to this same page (same path and dispatch) carrying sl= or
 * currency= are rewritten: the current URL plus their own sl / currency.
 */
export function syncSwitcherLinks() {
    const here = new URL(window.location.href);
    const dispatch = here.searchParams.get('dispatch') || '';
    document.querySelectorAll('a[href]').forEach((a) => {
        let link;
        try {
            link = new URL(a.getAttribute('href'), window.location.href);
        } catch (_) {
            return;
        }
        if (link.origin !== here.origin || link.pathname !== here.pathname) return;
        if ((link.searchParams.get('dispatch') || '') !== dispatch) return;
        const sl = link.searchParams.get('sl');
        const currency = link.searchParams.get('currency');
        if (sl === null && currency === null) return;
        const next = new URL(here.toString());
        if (sl !== null) next.searchParams.set('sl', sl);
        if (currency !== null) next.searchParams.set('currency', currency);
        a.setAttribute('href', next.toString());
    });
}
