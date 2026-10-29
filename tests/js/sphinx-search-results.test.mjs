import { beforeAll, describe, expect, it } from 'vitest';

/**
 * Behavioral tests for the sphinx search-results module
 * (addon-sphinx-holidays/js/addons/sphinx_holidays/search-results.js) —
 * the REAL file the storefront loads, extracted from search.tpl's inline
 * scripts. Covers the badge rooms/offers line (singular/plural), the
 * raw-lang-key guard (lbl), amount/percent formatting and the terms-modal
 * render branches.
 */
beforeAll(async () => {
    // The modal markup must exist before import: the module binds its close
    // handlers at DOM-ready and renderTerms writes into the modal body.
    document.body.innerHTML = `
        <div id="sphinx-terms-modal" style="display: none;">
            <div id="sphinx-terms-modal-body"></div>
        </div>`;
    window.__sphinxConfig = {
        labels: {
            room: 'cameră', rooms: 'camere', offer: 'ofertă', offers: 'oferte',
            freeCancellation: 'Anulare gratuită',
            freeCancellationUntil: 'Anulare gratuită înainte de',
            paymentTerms: 'Termeni de plată',
            cancellationPolicy: '_sphinx_holidays.cancellation_policy',
            noTermsInfo: 'Nu există condiții specifice pentru această ofertă.',
        },
    };
    await import('../../addon-sphinx-holidays/js/addons/sphinx_holidays/search-results.js');
});

describe('badgeRoomsLine', () => {
    it('uses singular labels for exactly one room / one offer', () => {
        const labels = window.__sphinxConfig.labels;
        expect(window.SphinxSearch.badgeRoomsLine(1, 1, labels)).toBe('1 cameră (1 ofertă)');
    });

    it('uses plural labels otherwise', () => {
        const labels = window.__sphinxConfig.labels;
        expect(window.SphinxSearch.badgeRoomsLine(2, 5, labels)).toBe('2 camere (5 oferte)');
    });

    it('falls back to english defaults without labels', () => {
        expect(window.SphinxSearch.badgeRoomsLine(1, 2, undefined)).toBe('1 room (2 offers)');
    });
});

describe('lbl (raw-lang-key guard)', () => {
    it('returns the fallback for empty or "_"-prefixed values', () => {
        const { lbl } = window.SphinxSearch.terms;
        expect(lbl('', 'fb')).toBe('fb');
        expect(lbl('_sphinx_holidays.terms_due_by', 'fb')).toBe('fb');
        expect(lbl(null, 'fb')).toBe('fb');
        expect(lbl('Real label', 'fb')).toBe('Real label');
    });
});

describe('formatting helpers', () => {
    it('fmtAmount drops trailing zeros on whole amounts and appends the currency', () => {
        const { fmtAmount } = window.SphinxSearch.terms;
        expect(fmtAmount('890', 'EUR')).toBe('890 EUR');
        expect(fmtAmount(12.5, 'EUR')).toBe('12.50 EUR');
        expect(fmtAmount(0, '')).toBe('0');
    });

    it('fmtPct rounds to one decimal and trims whole numbers', () => {
        const { fmtPct } = window.SphinxSearch.terms;
        expect(fmtPct('100')).toContain('100');
        expect(fmtPct('33.33')).toContain('33.3');
    });
});

describe('renderTerms', () => {
    function modalBodyAfter(data) {
        window.SphinxSearch.terms.renderTerms(data);
        return document.getElementById('sphinx-terms-modal-body').innerHTML;
    }

    it('renders the free-cancellation banner when is_free with no deadline', () => {
        const html = modalBodyAfter({ is_free: true });
        expect(html).toContain('travel-terms-modal__free');
        expect(html).toContain('Anulare gratuită');
    });

    it('renders "before <date>" when free_until is present', () => {
        const html = modalBodyAfter({ free_until: '2026-08-01' });
        expect(html).toContain('Anulare gratuită înainte de');
        expect(html).toContain('2026-08-01');
    });

    it('renders structured rules as a timeline and never a raw lang key', () => {
        const html = modalBodyAfter({
            schedule_total: '890',
            currency: 'EUR',
            cancellation_rules: [{ since: '2026-08-01', percent: 100 }],
        });
        expect(html).toContain('travel-terms-timeline');
        // cancellationPolicy label is a raw "_"-key in the fixture — the lbl()
        // guard must swap in the Romanian fallback.
        expect(html).not.toContain('_sphinx_holidays.cancellation_policy');
        expect(html).toContain('Politica de anulare');
    });

    it('falls back to the no-terms message when the payload is empty', () => {
        const html = modalBodyAfter({});
        expect(html).toContain('Nu există condiții specifice');
    });
});

describe('a Design A page whose live room cards end empty', () => {
    it('shows the no-results block with the nearby dates the last poll found', () => {
        document.body.insertAdjacentHTML('beforeend', `
            <div data-travel-live id="live"></div>
            <div id="sphinx-no-results" style="display: none;" data-alt-heading="Încearcă alte date:" data-alt-from="de la">
                <div id="sphinx-alt-dates" style="display: none;"></div>
            </div>`);
        const live = document.getElementById('live');
        const noResults = document.getElementById('sphinx-no-results');

        live.dispatchEvent(new CustomEvent('travel:live-done', { bubbles: true, detail: { shown: true, data: {} } }));
        expect(noResults.style.display).toBe('none');

        live.dispatchEvent(new CustomEvent('travel:live-done', {
            bubbles: true,
            detail: { shown: false, data: { alternatives: [{ check_in: '2027-07-12', check_out: '2027-07-19', price: 980, currency: 'EUR' }] } },
        }));
        expect(noResults.style.display).toBe('');
        const chip = document.querySelector('#sphinx-alt-dates .travel-alt-date-chip');
        expect(chip.textContent).toBe('12.07 – 19.07 · de la 980 €');
        expect(new URL(chip.href).searchParams.get('check_in')).toBe('2027-07-12');
    });
});
