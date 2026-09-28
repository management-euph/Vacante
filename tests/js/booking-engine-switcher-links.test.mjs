import { beforeEach, describe, expect, it } from 'vitest';
import { syncSwitcherLinks } from '../../addon-travel-core/react-src/src/switcherLinks.js';

/**
 * After a search pushes new params into the address bar, the language and
 * currency links must point at THAT URL — they were rendered by the server
 * from the page load, so switching language used to reload the old search
 * (or none).
 */
const origin = window.location.origin;
const page = origin + '/index.php?dispatch=eurosite_booking.search';

beforeEach(() => {
    window.history.replaceState({}, '', page + '&country=RO&city=MAM&check_in=2026-10-05&check_out=2026-10-11&adults=2');
    document.body.innerHTML = `
        <a id="ro" href="${page}&sl=ro">RO</a>
        <a id="eur" href="${page}&check_in=2026-01-01&currency=EUR">EUR</a>
        <a id="other" href="${origin}/index.php?dispatch=checkout.cart&sl=ro">Cart RO</a>
        <a id="plain" href="${page}&check_in=2026-01-01">Plain</a>
        <a id="away" href="https://example.com/index.php?dispatch=eurosite_booking.search&sl=ro">Away</a>`;
});

describe('syncSwitcherLinks', () => {
    it('points the language and currency links of this page at the current search', () => {
        window.history.pushState({}, '', page + '&country=RO&city=MAM&check_in=2026-11-01&check_out=2026-11-08&adults=3');
        syncSwitcherLinks();

        const ro = new URL(document.getElementById('ro').href);
        expect(ro.searchParams.get('sl')).toBe('ro');
        expect(ro.searchParams.get('check_in')).toBe('2026-11-01');
        expect(ro.searchParams.get('adults')).toBe('3');

        const eur = new URL(document.getElementById('eur').href);
        expect(eur.searchParams.get('currency')).toBe('EUR');
        expect(eur.searchParams.get('check_in')).toBe('2026-11-01');
    });

    it('leaves every other link alone', () => {
        syncSwitcherLinks();

        expect(document.getElementById('other').getAttribute('href')).toBe(origin + '/index.php?dispatch=checkout.cart&sl=ro');
        expect(document.getElementById('plain').getAttribute('href')).toBe(page + '&check_in=2026-01-01');
        expect(document.getElementById('away').getAttribute('href')).toBe('https://example.com/index.php?dispatch=eurosite_booking.search&sl=ro');
    });
});
