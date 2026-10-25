import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * Behavioral tests for the hotel page Design A script
 * (addon-travel-core/js/addons/travel_core/pdp-design-a.js): the photo
 * mosaic opening the theme previewer, "Alege datele" opening the calendar,
 * and the compact search summary.
 */

const LABELS = { adult: 'adult', adults: 'Adulți', child: 'copil', children: 'Copii', room: 'Cameră', rooms: 'camere' };
const TODAY = new Date(2026, 9, 25);

let observe = null;

class FakeIntersectionObserver {
    constructor(callback) {
        observe = (entry) => callback([entry]);
    }

    observe() {}
}

function page() {
    return document.querySelector('.travel-pdpa');
}

beforeAll(async () => {
    window.IntersectionObserver = FakeIntersectionObserver;
    window.scrollTo = vi.fn();
    document.documentElement.setAttribute('lang', 'ro');
    document.body.innerHTML = `
        <div class="travel-pdpa" data-travel-design="a">
            <div class="travel-pdpa-head">
                <a href="#travel-booking-root" class="travel-pdpa-head__dates" data-travel-open-dates>Alege datele</a>
            </div>
            <div class="travel-pdpa-gallery" data-travel-gallery>
                <a href="/img/1.jpg" class="travel-pdpa-gallery__tile" data-travel-gallery-index="0"><img alt=""></a>
                <a href="/img/2.jpg" class="travel-pdpa-gallery__tile" data-travel-gallery-index="1"><img alt=""></a>
                <a href="/img/3.jpg" class="travel-pdpa-gallery__tile" data-travel-gallery-index="2"><img alt=""></a>
                <button type="button" class="travel-pdpa-gallery__all" data-travel-gallery-index="0">Toate fotografiile (3)</button>
            </div>
            <div class="travel-pdpa-stock-gallery" aria-hidden="true">
                <a class="cm-image-previewer" href="/img/1.jpg" id="stock-0"></a>
                <a class="cm-image-previewer hidden" href="/img/2.jpg" id="stock-1"></a>
                <a class="cm-image-previewer hidden" href="/img/3.jpg" id="stock-2"></a>
            </div>
            <div class="travel-search-results-page travel-pdp-results">
                <div class="travel-search-form-wrapper">
                    <div id="travel-booking-root">
                        <div class="nvt-field nvt-field--date">
                            <button type="button" class="nvt-field-input" aria-expanded="false" id="date-trigger"></button>
                        </div>
                    </div>
                </div>
            </div>
            <div class="travel-pdpa-compact" data-travel-pdpa-compact hidden
                 data-label-adult="adult" data-label-adults="Adulți" data-label-child="copil"
                 data-label-children="Copii" data-label-room="Cameră" data-label-rooms="camere">
                <p data-travel-pdpa-summary></p>
                <button type="button" data-travel-open-dates id="compact-change">Modifică</button>
                <button type="button" data-travel-goto-results id="compact-rooms">Camere</button>
            </div>
        </div>`;
    await import('../../addon-travel-core/js/addons/travel_core/pdp-design-a.js');
    window.TravelPdpDesignA.init();
});

beforeEach(() => {
    window.scrollTo.mockClear();
});

function click(el) {
    const event = new MouseEvent('click', { bubbles: true, cancelable: true });
    el.dispatchEvent(event);
    return event;
}

describe('formatSummary', () => {
    const params = (query) => new URLSearchParams(query);

    it('reads dates and guests from the search URL', () => {
        expect(window.TravelPdpDesignA.formatSummary(
            params('check_in=2026-10-26&check_out=2026-10-31&adults=2&children=0&rooms=1'),
            LABELS,
            'ro',
            TODAY,
        )).toBe('26–31 oct. · 2 adulți');
    });

    it('counts guests from rooms_data and names the rooms when there are several', () => {
        const roomsData = JSON.stringify([
            { adults: 2, children: 1, childrenAges: [7] },
            { adults: 2, children: 0, childrenAges: [] },
        ]);
        expect(window.TravelPdpDesignA.formatSummary(
            params('check_in=2026-10-26&check_out=2026-11-02&adults=9&rooms_data=' + encodeURIComponent(roomsData)),
            LABELS,
            'ro',
            TODAY,
        // Intl puts thin spaces (U+2009) around the range dash.
        )).toBe('26 oct.\u2009–\u20092 nov. · 4 adulți · 1 copil · 2 camere');
    });

    it('adds the year for a stay outside the current year', () => {
        expect(window.TravelPdpDesignA.formatSummary(
            params('check_in=2027-01-03&check_out=2027-01-08&adults=1'),
            LABELS,
            'ro',
            TODAY,
        )).toBe('3–8 ian. 2027 · 1 adult');
    });

    it('is empty without a valid stay', () => {
        const format = window.TravelPdpDesignA.formatSummary;
        expect(format(params('adults=2'), LABELS, 'ro', TODAY)).toBe('');
        expect(format(params('check_in=2026-10-31&check_out=2026-10-26'), LABELS, 'ro', TODAY)).toBe('');
        expect(format(params('check_in=2026-02-30&check_out=2026-03-02'), LABELS, 'ro', TODAY)).toBe('');
    });
});

describe('photo mosaic', () => {
    it('opens the theme previewer through the matching stock link', () => {
        const stock = document.getElementById('stock-2');
        const opened = vi.fn((e) => e.preventDefault());
        stock.addEventListener('click', opened);

        const event = click(page().querySelector('[data-travel-gallery-index="2"]'));

        expect(opened).toHaveBeenCalledTimes(1);
        expect(event.defaultPrevented).toBe(true);
        stock.removeEventListener('click', opened);
    });

    it('"All photos" opens the previewer at the first photo', () => {
        const stock = document.getElementById('stock-0');
        const opened = vi.fn((e) => e.preventDefault());
        stock.addEventListener('click', opened);

        click(page().querySelector('.travel-pdpa-gallery__all'));

        expect(opened).toHaveBeenCalledTimes(1);
        stock.removeEventListener('click', opened);
    });

    it('keeps the tile link (the photo itself) when the theme has no previewer links', () => {
        const stock = page().querySelector('.travel-pdpa-stock-gallery');
        const saved = stock.innerHTML;
        stock.innerHTML = '';

        const event = click(page().querySelector('[data-travel-gallery-index="1"]'));

        expect(event.defaultPrevented).toBe(false);
        stock.innerHTML = saved;
    });
});

describe('"Alege datele"', () => {
    it('scrolls to the bar and opens the calendar', () => {
        const trigger = document.getElementById('date-trigger');
        const opened = vi.fn();
        trigger.addEventListener('click', opened);

        const event = click(page().querySelector('.travel-pdpa-head__dates'));

        expect(event.defaultPrevented).toBe(true);
        expect(window.scrollTo).toHaveBeenCalledTimes(1);
        expect(opened).toHaveBeenCalledTimes(1);
        trigger.removeEventListener('click', opened);
    });

    it('does not toggle a calendar that is already open', () => {
        const trigger = document.getElementById('date-trigger');
        trigger.setAttribute('aria-expanded', 'true');
        const opened = vi.fn();
        trigger.addEventListener('click', opened);

        click(page().querySelector('.travel-pdpa-head__dates'));

        expect(opened).not.toHaveBeenCalled();
        trigger.removeEventListener('click', opened);
        trigger.setAttribute('aria-expanded', 'false');
    });

    it('is hidden on a page without a booking form', () => {
        const root = document.getElementById('travel-booking-root');
        const parent = root.parentNode;
        parent.removeChild(root);
        const other = document.createElement('div');
        other.className = 'travel-pdpa';
        other.innerHTML = '<a href="#travel-booking-root" class="travel-pdpa-head__dates" data-travel-open-dates>Alege datele</a>';
        document.body.appendChild(other);

        window.TravelPdpDesignA.init();

        expect(other.querySelector('.travel-pdpa-head__dates').hidden).toBe(true);
        expect(page().querySelector('.travel-pdpa-head__dates').hidden).toBe(false);
        other.remove();
        parent.appendChild(root);
    });
});

describe('compact search summary', () => {
    const compact = () => page().querySelector('[data-travel-pdpa-compact]');
    const away = { isIntersecting: false, boundingClientRect: { top: -200 } };
    const inView = { isIntersecting: true, boundingClientRect: { top: 300 } };

    it('stays hidden before a search, even with the bar scrolled away', () => {
        observe(away);
        expect(compact().hidden).toBe(true);
    });

    it('shows the stay once results are under the bar and the bar has scrolled away', () => {
        window.history.pushState({}, '', '/hotel-admiral?check_in=2026-10-26&check_out=2026-10-31&adults=2&children=0&rooms=1');
        const results = document.createElement('div');
        results.className = 'room-results';
        page().querySelector('.travel-pdp-results').appendChild(results);
        document.dispatchEvent(new CustomEvent('travel:results-swapped'));

        expect(compact().hidden).toBe(false);
        expect(compact().querySelector('[data-travel-pdpa-summary]').textContent).toBe('26–31 oct. · 2 adulți');
    });

    it('hides again when the bar is back in view', () => {
        observe(inView);
        expect(compact().hidden).toBe(true);
        observe(away);
        expect(compact().hidden).toBe(false);
    });

    it('"Camere" scrolls to the results and "Modifică" opens the calendar', () => {
        click(document.getElementById('compact-rooms'));
        expect(window.scrollTo).toHaveBeenCalledTimes(1);

        const trigger = document.getElementById('date-trigger');
        const opened = vi.fn();
        trigger.addEventListener('click', opened);
        click(document.getElementById('compact-change'));
        expect(opened).toHaveBeenCalledTimes(1);
        trigger.removeEventListener('click', opened);
    });
});
