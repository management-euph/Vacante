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

describe('photo height', () => {
    const box = (el, top, bottom) => {
        el.getBoundingClientRect = () => ({ top, bottom, left: 0, right: 0, x: 0, y: top, width: 0, height: bottom - top });
    };
    const height = () => page().style.getPropertyValue('--travel-pdpa-gallery-h');

    it('gives way so the bar ends on the first screen, whatever the header takes', () => {
        // A 244px theme header and the title row put the photos at 336px.
        box(page().querySelector('.travel-pdpa-gallery'), 336, 816);
        box(page().querySelector('.travel-pdp-results .travel-search-form-wrapper'), 832, 896);

        window.innerHeight = 900;
        window.TravelPdpDesignA.fitGallery(page());
        expect(height()).toBe('476px'); // 900 - 336 - 16 gap - 64 bar - 8

        window.innerHeight = 1200;
        window.TravelPdpDesignA.fitGallery(page());
        expect(height()).toBe('480px'); // never taller than the design

        window.innerHeight = 657;
        window.TravelPdpDesignA.fitGallery(page());
        expect(height()).toBe('240px'); // never smaller than 240px
    });

    it('leaves phones to the stylesheet', () => {
        window.matchMedia = () => ({ matches: true });
        window.TravelPdpDesignA.fitGallery(page());
        expect(height()).toBe('');
        delete window.matchMedia;
    });
});

describe('photo fit', () => {
    const tiles = () => page().querySelectorAll('.travel-pdpa-gallery__tile');
    const size = (tile, width, height) => {
        Object.defineProperty(tile, 'clientWidth', { value: width, configurable: true });
        Object.defineProperty(tile, 'clientHeight', { value: height, configurable: true });
    };
    const photo = (tile, width, height, complete = true) => {
        const img = tile.querySelector('img:not(.travel-pdpa-gallery__backdrop)');
        img.setAttribute('src', `/images/thumbnails/${width}/${height}/detailed/photo.jpg`);
        Object.defineProperty(img, 'naturalWidth', { value: width, configurable: true });
        Object.defineProperty(img, 'naturalHeight', { value: height, configurable: true });
        Object.defineProperty(img, 'complete', { value: complete, configurable: true });
        return img;
    };
    const whole = (tile) => tile.classList.contains('travel-pdpa-gallery__tile--whole');

    it('fills the tile unless that enlarges the photo more than 1.5× or crops away more than half', () => {
        const { showsWhole } = window.TravelPdpDesignA;
        // The large tile on a wide screen, 870 × 480.
        expect(showsWhole(870, 480, 1024, 683)).toBe(false); // the store's photos: shrunk to fit
        expect(showsWhole(870, 480, 790, 527)).toBe(false); // enlarged 1.1×
        expect(showsWhole(870, 480, 560, 373)).toBe(true); // enlarged 1.55×: soft
        expect(showsWhole(870, 480, 400, 300)).toBe(true);
        expect(showsWhole(870, 480, 600, 900)).toBe(true); // portrait: 37% would stay in view
        expect(showsWhole(870, 480, 1000, 1000)).toBe(false); // square: 55% in view
        expect(showsWhole(870, 480, 3000, 1000)).toBe(false); // panorama: 60% in view
        // A small tile, 290 × 236.
        expect(showsWhole(290, 236, 600, 400)).toBe(false);
        expect(showsWhole(290, 236, 150, 100)).toBe(true);
    });

    it('shows a small photo whole, over a blurred copy of itself, once', () => {
        const [big, small] = tiles();
        size(big, 870, 480);
        size(small, 290, 236);
        photo(big, 1024, 683);
        const img = photo(small, 150, 100);

        window.TravelPdpDesignA.fitPhotos(page());
        window.TravelPdpDesignA.fitPhotos(page());

        expect(whole(big)).toBe(false);
        expect(big.querySelector('.travel-pdpa-gallery__backdrop')).toBeNull();
        expect(whole(small)).toBe(true);
        // At most 1.5× its own size.
        expect(small.style.getPropertyValue('--travel-pdpa-whole-w')).toBe('225px');
        expect(small.style.getPropertyValue('--travel-pdpa-whole-h')).toBe('150px');
        const backdrops = small.querySelectorAll('.travel-pdpa-gallery__backdrop');
        expect(backdrops).toHaveLength(1);
        expect(backdrops[0].getAttribute('src')).toBe(img.src);
        expect(backdrops[0].getAttribute('alt')).toBe('');
        expect(backdrops[0].getAttribute('aria-hidden')).toBe('true');
        expect(backdrops[0].nextElementSibling).toBe(img); // under the photo
    });

    it('fills the tile again once it is small enough for the photo', () => {
        const small = tiles()[1];
        size(small, 200, 120);
        window.TravelPdpDesignA.fitPhotos(page());
        expect(whole(small)).toBe(false);
    });

    it('decides when the photo arrives, and leaves hidden tiles and placeholders alone', () => {
        const third = tiles()[2];
        size(third, 290, 236);
        const img = photo(third, 500, 1000, false);
        window.TravelPdpDesignA.fitPhotos(page());
        expect(whole(third)).toBe(false); // not loaded yet

        Object.defineProperty(img, 'complete', { value: true, configurable: true });
        img.dispatchEvent(new Event('load'));
        expect(whole(third)).toBe(true); // 500 × 1000 in 290 × 236: 41% in view

        photo(third, 1, 1); // CS-Cart's spacer while a thumbnail is generated
        third.classList.remove('travel-pdpa-gallery__tile--whole');
        window.TravelPdpDesignA.fitPhotos(page());
        expect(whole(third)).toBe(false);

        photo(third, 150, 100);
        size(third, 0, 0); // phones show the main photo only
        window.TravelPdpDesignA.fitPhotos(page());
        expect(whole(third)).toBe(false);
    });
});

describe('room-first results (board 13b)', () => {
    // One option of a room: meal key, total, flags; its till and (optionally) its own terms.
    const option = (index, meal, amount, flags = {}) => `
        <label class="travel-pdpa-opt" data-travel-option data-index="${index}" data-key="k${meal}${amount}" data-meal="${meal}"
               data-free="${flags.free ? 1 : 0}" data-immediate="${flags.onRequest ? 0 : 1}" data-special="${flags.special ? 1 : 0}" data-amount="${amount}">
            <input type="radio" name="opt-${flags.room}" value="${index}" data-travel-pick${flags.chosen ? ' checked' : ''}>
            <span>${meal} ${amount}</span>
        </label>`;
    const till = (index, meal, amount, chosen) => `
        <div class="travel-pdpa-till" data-travel-for="${index}" data-travel-say="${meal}, total ${amount} €"${chosen ? '' : ' hidden'}>
            <b class="total">${amount} €</b><a href="#book-${meal}-${amount}">Rezervă</a>
        </div>`;
    // A room: [meal, amount, flags] options, the cheapest chosen.
    const room = (key, name, options, more = false) => {
        const amounts = options.map(([, amount]) => amount);
        const cheapest = amounts.indexOf(Math.min(...amounts));
        return `
        <li class="travel-pdpa-rcard" id="${key}" data-travel-room data-room-key="${key}" data-from="${amounts[cheapest]}"
            data-from-text="${amounts[cheapest]} €" data-name="${name}" ${more ? 'hidden' : ''}>
            <ul data-travel-amen tabindex="-1"><li>Wi-Fi</li><li data-travel-amen-more hidden>Seif</li></ul>
            <button type="button" data-travel-amen-all aria-expanded="false">Toate dotările (+1)</button>
            <fieldset>${options.map(([meal, amount, flags], i) => option(i, meal, amount, { ...flags, room: key, chosen: i === cheapest })).join('')}</fieldset>
            <details data-travel-for="0"${cheapest === 0 ? '' : ' hidden'}><summary>Condiții</summary></details>
            <div class="travel-pdpa-rcard__till">${options.map(([meal, amount], i) => till(i, meal, amount, i === cheapest)).join('')}</div>
            <p data-travel-announce></p>
        </li>`;
    };
    const RESULTS = (id) => `
        <section data-travel-results id="${id}">
            <button type="button" data-travel-meal="" aria-pressed="true">Toate mesele</button>
            <button type="button" data-travel-meal="bb" aria-pressed="false">Mic dejun</button>
            <button type="button" data-travel-meal="ai" aria-pressed="false">All Inclusive</button>
            <button type="button" data-travel-chip="free" aria-pressed="false">Anulare gratuită</button>
            <button type="button" data-travel-chip="special" aria-pressed="false">Oferte speciale</button>
            <select data-travel-sort><option value="low" selected>low</option><option value="high">high</option></select>
            <ul data-travel-room-list id="${id}-list">
                ${room(`${id}-dbl`, 'Cameră Dublă', [['bb', 615, { free: true }], ['ai', 845, { free: true, special: true }]])}
                ${room(`${id}-deluxe`, 'Cameră Dublă', [['bb', 675], ['ai', 905, { special: true }]])}
                ${room(`${id}-sea`, 'Cameră Dublă', [['ai', 735, { free: true }]])}
                ${room(`${id}-superior`, 'Cameră Dublă', [['ai', 1295, { onRequest: true }]])}
                ${room(`${id}-junior`, 'Suită Junior', [['bb', 1305], ['ai', 1505]], true)}
                ${room(`${id}-family`, 'Cameră de familie', [['ai', 1390]], true)}
            </ul>
            <div data-travel-rall>
                <button type="button" data-travel-see-all aria-expanded="false" aria-controls="${id}-list">Vezi toate cele 6 tipuri de cameră</button>
                <p data-travel-hint data-template="Încă [n]: [names], de la [price]">Încă 2: Suită Junior, Cameră de familie, de la 1305 €</p>
            </div>
            <div data-travel-no-match hidden><button type="button" data-travel-clear-filters>Arată toate</button></div>
        </section>`;
    let section;
    const shown = () => Array.from(section.querySelectorAll('[data-travel-room]')).filter((r) => !r.hidden).map((r) => r.id.replace(/^\w+-/, ''));
    const total = (id) => {
        const r = document.getElementById(id);
        return r.querySelector('.travel-pdpa-till:not([hidden]) .total').textContent;
    };

    beforeAll(() => {
        page().insertAdjacentHTML('beforeend', RESULTS('r'));
        section = document.getElementById('r');
    });

    it('a radio switches the room\'s till and terms; the total fades in only after a change, and is said', () => {
        const dbl = document.getElementById('r-dbl');
        expect(total('r-dbl')).toBe('615 €');
        expect(dbl.hasAttribute('data-travel-picked')).toBe(false);

        const radio = dbl.querySelectorAll('[data-travel-pick]')[1];
        radio.checked = true;
        radio.dispatchEvent(new Event('change', { bubbles: true }));
        expect(total('r-dbl')).toBe('845 €');
        expect(dbl.querySelector('.travel-pdpa-till:not([hidden]) a').getAttribute('href')).toBe('#book-ai-845');
        expect(dbl.querySelector('details[data-travel-for="0"]').hidden).toBe(true);
        expect(dbl.hasAttribute('data-travel-picked')).toBe(true);
        expect(dbl.querySelector('[data-travel-announce]').textContent).toBe('ai, total 845 €');
    });

    it('a meal shows the rooms that have it and chooses it in them; clearing chooses the cheapest again', () => {
        click(section.querySelector('[data-travel-meal="bb"]'));
        expect(shown()).toEqual(['dbl', 'deluxe', 'junior']);
        expect(total('r-dbl')).toBe('615 €');
        expect(total('r-junior')).toBe('1305 €');
        expect(section.querySelector('[data-travel-rall]').hidden).toBe(true);
        expect(section.querySelector('[data-travel-meal="bb"]').getAttribute('aria-pressed')).toBe('true');
        expect(section.querySelector('[data-travel-meal=""]').getAttribute('aria-pressed')).toBe('false');

        click(section.querySelector('[data-travel-meal="ai"]'));
        expect(shown()).toEqual(['dbl', 'deluxe', 'sea', 'superior', 'junior', 'family']);
        expect(total('r-deluxe')).toBe('905 €');
        expect(total('r-junior')).toBe('1505 €');

        click(section.querySelector('[data-travel-meal=""]'));
        expect(shown()).toEqual(['dbl', 'deluxe', 'sea', 'superior']);
        expect(total('r-deluxe')).toBe('675 €');
        expect(section.querySelector('[data-travel-rall]').hidden).toBe(false);
    });

    it('chips narrow it further; nothing left says so, and one click shows everything again', () => {
        click(section.querySelector('[data-travel-chip="special"]'));
        expect(shown()).toEqual(['dbl', 'deluxe']);
        expect(total('r-dbl')).toBe('845 €');

        click(section.querySelector('[data-travel-meal="bb"]'));
        expect(shown()).toEqual([]);
        expect(section.querySelector('[data-travel-no-match]').hidden).toBe(false);

        click(section.querySelector('[data-travel-clear-filters]'));
        expect(shown()).toEqual(['dbl', 'deluxe', 'sea', 'superior']);
        expect(total('r-dbl')).toBe('615 €');
        expect(section.querySelector('[data-travel-no-match]').hidden).toBe(true);
        expect(section.querySelector('[data-travel-chip="special"]').getAttribute('aria-pressed')).toBe('false');
    });

    it('the sort orders the rooms by their cheapest option; the first four show and the hint names the others', () => {
        const select = section.querySelector('[data-travel-sort]');
        select.value = 'high';
        select.dispatchEvent(new Event('change', { bubbles: true }));
        expect(shown()).toEqual(['family', 'junior', 'superior', 'sea']);
        expect(section.querySelector('[data-travel-hint]').textContent).toBe('Încă 2: Cameră Dublă, de la 615 €');

        select.value = 'low';
        select.dispatchEvent(new Event('change', { bubbles: true }));
        expect(shown()).toEqual(['dbl', 'deluxe', 'sea', 'superior']);
        expect(section.querySelector('[data-travel-hint]').textContent).toBe('Încă 2: Suită Junior, Cameră de familie, de la 1305 €');
    });

    it('"see all" shows the other rooms and hands the focus to the first one\'s chosen option', () => {
        const button = section.querySelector('[data-travel-see-all]');
        click(button);
        expect(shown()).toEqual(['dbl', 'deluxe', 'sea', 'superior', 'junior', 'family']);
        expect(button.getAttribute('aria-expanded')).toBe('true');
        expect(section.querySelector('[data-travel-rall]').hidden).toBe(true);
        expect(document.activeElement).toBe(document.getElementById('r-junior').querySelector('[data-travel-pick]:checked'));
    });

    it('"Toate dotările" shows the room\'s other facilities', () => {
        const dbl = document.getElementById('r-dbl');
        click(dbl.querySelector('[data-travel-amen-all]'));
        expect(dbl.querySelector('[data-travel-amen-more]').hidden).toBe(false);
        expect(dbl.querySelector('[data-travel-amen-all]').hidden).toBe(true);
        expect(document.activeElement).toBe(dbl.querySelector('[data-travel-amen]'));
    });
});

describe('a room on request: the ⓘ', () => {
    let tip;
    let pop;

    beforeAll(() => {
        page().insertAdjacentHTML('beforeend', `
            <div class="travel-pdpa-cta">
                <p>Răspuns în max. 48 h<button type="button" data-travel-tip aria-expanded="false" aria-controls="tip-1">i</button></p>
                <div id="tip-1" role="dialog" data-travel-tip-pop hidden>
                    <p>Cum funcționează cererea</p>
                    <button type="button" data-travel-tip-close>×</button>
                </div>
            </div>`);
        tip = document.querySelector('[data-travel-tip]');
        pop = document.getElementById('tip-1');
    });

    it('opens on click (not hover) and closes on the ×, on Esc and on a click elsewhere', () => {
        click(tip);
        expect([tip.getAttribute('aria-expanded'), pop.hidden]).toEqual(['true', false]);
        click(pop.querySelector('p'));
        expect(pop.hidden).toBe(false);

        click(pop.querySelector('[data-travel-tip-close]'));
        expect([tip.getAttribute('aria-expanded'), pop.hidden]).toEqual(['false', true]);
        expect(document.activeElement).toBe(tip);

        click(tip);
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        expect(pop.hidden).toBe(true);
        expect(document.activeElement).toBe(tip);

        click(tip);
        click(document.body);
        expect(pop.hidden).toBe(true);
    });
});

describe('terms loaded when opened (a supplier without them in the search)', () => {
    const terms = (url) => `
        <details data-travel-terms-url="${url}">
            <summary>Condiții de plată și anulare</summary>
            <div data-travel-terms-body data-error="Nu am putut încărca condițiile."><p data-travel-terms-wait>Se încarcă…</p></div>
        </details>`;

    it('loads once, as text, and says so when it can\'t', async () => {
        const fetch = vi.fn(async (url) => (url === '/ok'
            ? { ok: true, json: async () => ({ sections: [{ title: 'Anulare', lines: ['Gratuit până la 3 nov. 2026', '<b>100%</b> după'] }] }) }
            : { ok: false, status: 500 }));
        vi.stubGlobal('fetch', fetch);
        page().insertAdjacentHTML('beforeend', terms('/ok') + terms('/down'));
        const [ok, down] = document.querySelectorAll('[data-travel-terms-url]');

        ok.open = true;
        ok.dispatchEvent(new Event('toggle'));
        await vi.waitFor(() => expect(ok.querySelector('.travel-pdpa-terms__title')).not.toBeNull());
        expect(ok.querySelector('.travel-pdpa-terms__title').textContent).toBe('Anulare');
        expect(Array.from(ok.querySelectorAll('li')).map((li) => li.textContent)).toEqual(['Gratuit până la 3 nov. 2026', '<b>100%</b> după']);
        expect(ok.querySelector('b')).toBeNull();
        ok.dispatchEvent(new Event('toggle'));
        expect(fetch.mock.calls.filter(([url]) => url === '/ok')).toHaveLength(1);

        down.open = true;
        down.dispatchEvent(new Event('toggle'));
        await vi.waitFor(() => expect(down.querySelector('[data-travel-terms-wait]').textContent).toBe('Nu am putut încărca condițiile.'));
        vi.unstubAllGlobals();
    });
});

describe('results that keep arriving (Sphinx polls)', () => {
    it('replaces the list with each answer, keeping the filters and each room\'s option, until done', async () => {
        const answers = [
            { html: RESULTS_HTML(['dbl']), done: false, cursor: 'c1' },
            { html: RESULTS_HTML(['dbl', 'sea']), done: true, cursor: '' },
        ];
        const fetch = vi.fn(async () => ({ ok: true, json: async () => answers.shift() }));
        vi.stubGlobal('fetch', fetch);
        page().insertAdjacentHTML('beforeend', `
            <div data-travel-live data-travel-live-url="/poll?design=a" data-travel-live-interval="1" data-travel-live-max="5">
                <p data-travel-live-status>Căutăm…</p>
                <div data-travel-live-results></div>
            </div>`);
        const live = document.querySelector('[data-travel-live]');
        document.dispatchEvent(new CustomEvent('travel:results-swapped'));

        await vi.waitFor(() => expect(live.querySelector('[data-travel-room]')).not.toBeNull());
        // The guest picks All Inclusive in the first room and filters on it.
        const radio = live.querySelectorAll('#l-dbl [data-travel-pick]')[1];
        radio.checked = true;
        radio.dispatchEvent(new Event('change', { bubbles: true }));
        click(live.querySelector('[data-travel-meal="ai"]'));

        await vi.waitFor(() => expect(live.hasAttribute('data-travel-live-done')).toBe(true));
        expect(live.querySelectorAll('[data-travel-room]')).toHaveLength(2);
        expect(live.querySelector('[data-travel-meal="ai"]').getAttribute('aria-pressed')).toBe('true');
        expect(live.querySelector('#l-dbl [data-travel-pick]:checked').value).toBe('1');
        expect(live.querySelector('[data-travel-live-status]').hidden).toBe(true);
        expect(fetch.mock.calls.map(([url]) => url)).toEqual(['/poll?design=a', '/poll?design=a&cursor=c1']);
        vi.unstubAllGlobals();
    });

    function RESULTS_HTML(rooms) {
        const options = {
            dbl: [['bb', 615], ['ai', 845]],
            sea: [['ai', 735]],
        };
        return `<section data-travel-results>
            <button type="button" data-travel-meal="" aria-pressed="true">Toate</button>
            <button type="button" data-travel-meal="ai" aria-pressed="false">AI</button>
            <ul data-travel-room-list>${rooms.map((key) => `
                <li id="l-${key}" data-travel-room data-room-key="${key}" data-from="${options[key][0][1]}">
                    ${options[key].map(([meal, amount], i) => `
                        <label data-travel-option data-index="${i}" data-key="${meal}" data-meal="${meal}" data-amount="${amount}">
                            <input type="radio" name="l-${key}" value="${i}" data-travel-pick${i === 0 ? ' checked' : ''}>
                        </label>`).join('')}
                    ${options[key].map(([meal, amount], i) => `<div class="travel-pdpa-till" data-travel-for="${i}"${i === 0 ? '' : ' hidden'}>${amount}</div>`).join('')}
                </li>`).join('')}
            </ul>
            <div data-travel-no-match hidden></div>
        </section>`;
    }
});
