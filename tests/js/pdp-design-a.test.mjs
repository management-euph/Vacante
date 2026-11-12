import { afterAll, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * Behavioral tests for the hotel page Design A script
 * (addon-travel-core/js/addons/travel_core/pdp-design-a.js): the photo
 * mosaic opening the theme previewer, "Alege datele" opening the calendar,
 * the two-line sticky header and the rooms.
 */

function page() {
    return document.querySelector('.travel-pdpa');
}

beforeAll(async () => {
    window.scrollTo = vi.fn();
    document.documentElement.setAttribute('lang', 'ro');
    document.body.innerHTML = `
        <div class="travel-pdpa" data-travel-design="a">
            <div class="travel-pdpa-head">
                <h1 class="ty-product-block-title travel-pdpa-head__title"><bdi>Hotel Admiral</bdi></h1>
                <div class="travel-pdpa-head__actions">
                    <button type="button" id="button_wishlist_7" class="ty-btn ty-btn__tertiary ty-btn-icon ty-add-to-wish" title="Adaugă la favorite"></button>
                </div>
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
            <div class="travel-pdpa-sticky" data-travel-pdpa-sticky hidden role="region" aria-label="Navigare rapidă">
                <div class="travel-pdpa-sticky__line">
                    <div class="travel-pdpa-sticky__inner">
                        <div class="travel-pdpa-sticky__id">
                            <span class="travel-pdpa-sticky__namebox">
                                <button type="button" class="travel-pdpa-sticky__name" data-travel-pdpa-top><bdi>Hotel Admiral</bdi></button>
                            </span>
                            <span class="travel-pdpa-sticky__stars" role="img" aria-label="5 stele">5<span aria-hidden="true"> ★</span></span>
                            <a href="https://www.google.com/maps/search/?api=1&amp;query=43.2,28.0" target="_blank" rel="noopener" class="travel-pdpa-sticky__map" id="sticky-map"></a>
                        </div>
                        <div class="travel-pdpa-sticky__side">
                            <button type="button" class="travel-pdpa-sticky__cta" data-travel-pdpa-cta
                                    data-label-dates="Alege datele" data-label-departure="Alege data plecării"
                                    data-label-room="Alege cameră">Alege datele</button>
                            <button type="button" class="travel-pdpa-sticky__fav" data-travel-pdpa-fav aria-pressed="false" aria-label="Adaugă la favorite"></button>
                        </div>
                    </div>
                </div>
                <nav class="travel-pdpa-sticky__tabs" data-travel-pdpa-sticky-tabs hidden aria-label="Secțiunile paginii">
                    <div class="travel-pdpa-sticky__tablist" data-travel-pdpa-sticky-tablist></div>
                </nav>
            </div>
            <div class="travel-pdpa-tabs" data-travel-pdpa-tabs>
                <div class="ty-tabs cm-j-tabs clearfix">
                    <ul class="ty-tabs__list">
                        <li id="description" class="ty-tabs__item cm-js active"><a class="ty-tabs__a">Descriere</a></li>
                        <li id="features" class="ty-tabs__item cm-js"><a class="ty-tabs__a">Facilități</a></li>
                        <li id="policies" class="ty-tabs__item cm-js"><a class="ty-tabs__a">Informații utile</a></li>
                        <li id="discussion" class="ty-tabs__item cm-js cm-ajax"><a class="ty-tabs__a">Recenzii</a></li>
                    </ul>
                </div>
                <div class="cm-tabs-content ty-tabs__content clearfix" id="tabs_content">
                    <div id="content_description" class="ty-wysiwyg-content content-description"><p>Plajă privată.</p></div>
                    <div id="content_features" class="ty-wysiwyg-content content-features hidden"><p>Wi-Fi</p></div>
                    <div id="content_policies" class="ty-wysiwyg-content content-policies hidden"><p>Check-in 14:00</p></div>
                    <div id="content_discussion" class="ty-wysiwyg-content content-discussion hidden"></div>
                    <div id="content_attachments" class="ty-wysiwyg-content content-attachments"></div>
                </div>
            </div>
        </div>`;
    await import('../../addon-travel-core/js/addons/travel_core/pdp-design-a.js');
    window.TravelPdpDesignA.init();
});

beforeEach(() => {
    window.scrollTo.mockClear();
});

// A few frames (requestAnimationFrame or its stand-in).
function frames(n = 2) {
    return new Promise((resolve) => setTimeout(resolve, 20 * n));
}

function click(el) {
    const event = new MouseEvent('click', { bubbles: true, cancelable: true });
    el.dispatchEvent(event);
    return event;
}

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
    it('opens the calendar, then scrolls to the bar once the calendar has the focus', async () => {
        const trigger = document.getElementById('date-trigger');
        // the calendar takes the focus as it opens (and the browser jumps to it)
        const opened = vi.fn(() => trigger.focus());
        trigger.addEventListener('click', opened);

        const event = click(page().querySelector('.travel-pdpa-head__dates'));

        expect(event.defaultPrevented).toBe(true);
        expect(opened).toHaveBeenCalledTimes(1);
        expect(window.scrollTo).not.toHaveBeenCalled(); // not yet: the jump would cut it short
        await frames(1);
        expect(window.scrollTo).toHaveBeenCalledTimes(1);
        trigger.removeEventListener('click', opened);
        trigger.blur();
    });

    it('scrolls anyway when the calendar takes no focus', () => {
        vi.useFakeTimers();
        click(page().querySelector('.travel-pdpa-head__dates'));
        expect(window.scrollTo).not.toHaveBeenCalled();
        vi.advanceTimersByTime(400);
        expect(window.scrollTo).toHaveBeenCalledTimes(1);
        vi.useRealTimers();
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

describe('sticky header: what shows when (#40)', () => {
    // Scrolled past the hero, above the tab strip, before a search.
    const BASE = {
        heroBottom: -100, stripBottom: 400, line1: 56, line2: 48, viewport: 800, searched: false,
        rooms: [], booking: [{ top: -300, bottom: -100 }], panels: [], atBottom: false, locked: null,
    };
    const state = (m) => window.TravelPdpDesignA.headerState({ ...BASE, ...m });

    it('shows line 1 only once the whole hero has scrolled off', () => {
        expect(state({ heroBottom: 120 }).show).toBe(false); // the booking box still on screen
        expect(state({ heroBottom: -1 }).show).toBe(false); // a scroll that lands on its very end
        expect(state({ heroBottom: -3 }).show).toBe(true);
        expect(state({ heroBottom: -Infinity }).show).toBe(false); // nothing measured: the block is not displayed
    });

    it('keeps line 2 away in a window under 480 px tall, and the whole header under 300 px', () => {
        expect(state({ viewport: 479, stripBottom: -900 }).dock).toBe(false);
        expect(state({ viewport: 479, stripBottom: -900 }).show).toBe(true);
        expect(state({ viewport: 299 }).show).toBe(false);
        expect(state({ viewport: 480, stripBottom: -900 }).dock).toBe(true);
    });

    it('docks line 2 once the page\'s tab strip has gone under the header, never without line 1', () => {
        expect(state({ stripBottom: 105 }).dock).toBe(false); // its end still in view under line 1
        expect(state({ stripBottom: 104 }).dock).toBe(true); // line 2 (56–104 px) covers it whole
        expect(state({ stripBottom: -900 }).dock).toBe(true);
        expect(state({ stripBottom: null }).dock).toBe(false); // no tabs
        expect(state({ heroBottom: 10, stripBottom: -900 }).dock).toBe(false);
    });

    it('before a search, the button shows only while the booking box is off screen', () => {
        expect(state({}).cta).toBe(true);
        expect(state({ booking: [{ top: -100, bottom: 40 }] }).cta).toBe(true); // only under line 1
        expect(state({ booking: [{ top: 100, bottom: 164 }] }).cta).toBe(false);
        expect(state({ heroBottom: 50 }).cta).toBe(false); // no header, no button
    });

    it('after a search, it shows only while no room option is on screen', () => {
        const rooms = (top, bottom, more = {}) => state({ searched: true, rooms: [{ top, bottom }], ...more });
        expect(rooms(300, 700).cta).toBe(false); // the rooms in view
        expect(rooms(-900, -200).cta).toBe(true); // scrolled past them
        expect(rooms(900, 1300).cta).toBe(true); // not reached yet
        expect(rooms(-300, 50).cta).toBe(true); // only under line 1
        expect(rooms(-300, 90, { stripBottom: 0 }).cta).toBe(true); // only under both lines (104 px)
        expect(rooms(-300, 120, { stripBottom: 0 }).cta).toBe(false);
    });

    it('marks the section across the middle of what the header leaves in view', () => {
        const panels = [{ id: 'description', top: -500 }, { id: 'features', top: 300 }, { id: 'policies', top: 700 }];
        // under both lines (104 px) of an 800 px window, the middle is at 452 px
        expect(state({ stripBottom: -600, panels }).active).toBe('features');
        expect(state({ stripBottom: -600, panels: panels.map((p) => ({ ...p, top: p.top + 1000 })) }).active).toBe(null); // above the first
        expect(state({ stripBottom: -600, panels, atBottom: true }).active).toBe('policies'); // a last section too short to reach the middle
        expect(state({ stripBottom: -600, panels, locked: 'description' }).active).toBe('description'); // the tab clicked last
        expect(state({ stripBottom: 300, panels }).active).toBe(null); // line 2 not docked
    });
});

describe('sticky header: a link to a tab (#40)', () => {
    const tab = (id, on) => {
        const li = document.createElement('li');
        li.id = id;
        if (on) {
            li.className = 'active';
        }
        return { id, li };
    };
    const info = (on) => ({ tabs: ['description', 'features', 'policies'].map((id) => tab(id, id === on)) });

    it('opens on the tab the address names, else on the one the theme marks when not the first', () => {
        const { requestedTab } = window.TravelPdpDesignA;
        expect(requestedTab(info('description'))).toBe(null);
        expect(requestedTab(info('policies'))).toBe('policies'); // ?selected_section=policies
        window.history.replaceState({}, '', '#content_features');
        expect(requestedTab(info('description'))).toBe('features');
        window.history.replaceState({}, '', '#features');
        expect(requestedTab(info('policies'))).toBe('features');
        window.history.replaceState({}, '', window.location.pathname + window.location.search);
    });
});

describe('sticky header: where a scroll stops (#40)', () => {
    // The hero ends at 1000 px, the tab strip at 3000 px (document px): line 2
    // docks from a scroll of 3000 - 56 - 48 = 2896.
    const land = (top) => window.TravelPdpDesignA.landingY({ top, heroEnd: 1000, stripBottom: 3000, line1: 56, line2: 48 });

    it('in the hero, 16 px from the top as before: no header there', () => {
        expect(land(900)).toBe(884);
        expect(land(10)).toBe(0);
    });

    it('between the hero and the tab strip, 8 px under line 1', () => {
        expect(land(2000)).toBe(2000 - 56 - 8);
    });

    it('once line 2 will show, 8 px under both lines', () => {
        expect(land(4000)).toBe(4000 - 56 - 48 - 8);
    });

    it('a section right under the strip, right under line 2, never above where it docks', () => {
        expect(land(3000)).toBe(2896); // line 2 over the strip, the section right under it
        expect(land(3010)).toBe(3010 - 56 - 48 - 8);
        expect(land(2958)).toBe(2958 - 56 - 8); // the strip itself: under line 1, in view
    });

    it('just under the hero, with the hero\'s end at the top, so no header covers it', () => {
        expect(land(1040)).toBe(1000);
    });
});

describe('sticky header on the page (#40)', () => {
    const header = () => page().querySelector('[data-travel-pdpa-sticky]');
    const nav = () => header().querySelector('[data-travel-pdpa-sticky-tabs]');
    const cta = () => header().querySelector('[data-travel-pdpa-cta]');
    const fav = () => header().querySelector('[data-travel-pdpa-fav]');
    const tabs = () => Array.from(header().querySelectorAll('[data-travel-pdpa-tab]'));
    const active = () => tabs().filter((b) => b.classList.contains('is-active')).map((b) => b.getAttribute('data-travel-pdpa-tab'));
    const stubbed = new Set();
    const at = (el, top, height) => {
        el.getBoundingClientRect = () => ({ top, bottom: top + height, left: 0, right: 1024, x: 0, y: top, width: 1024, height });
        stubbed.add(el);
    };
    let room = null;
    // The page scrolled `y` px: title row 100–220, photos 240–720, the bar
    // 740–804 (the hero's end), a room 900–1200 once searched, the tab strip
    // at 1500, the sections from 1560, 600 px each.
    const scrolled = (y) => {
        Object.defineProperty(window, 'pageYOffset', { value: y, configurable: true, writable: true });
        at(page().querySelector('.travel-pdpa-head'), 100 - y, 120);
        at(page().querySelector('.travel-pdpa-gallery'), 240 - y, 480);
        at(page().querySelector('.travel-pdp-results .travel-search-form-wrapper'), 740 - y, 64);
        at(page().querySelector('[data-travel-pdpa-tabs] .ty-tabs'), 1500 - y, 48);
        ['description', 'features', 'policies'].forEach((id, i) => at(document.getElementById('content_' + id), 1560 + i * 600 - y, 600));
        if (room) {
            at(room, 900 - y, 300);
        }
        window.TravelPdpDesignA.updateHeaders();
    };
    const addRoom = () => {
        room = document.createElement('div');
        room.setAttribute('data-travel-room', '');
        page().querySelector('.travel-pdp-results').appendChild(room);
    };

    beforeAll(() => {
        window.innerHeight = 800;
        Object.defineProperty(document.documentElement, 'scrollHeight', { value: 3400, configurable: true });
    });

    afterAll(() => {
        stubbed.forEach((el) => delete el.getBoundingClientRect);
        Object.defineProperty(window, 'pageYOffset', { value: 0, configurable: true, writable: true });
        delete document.documentElement.scrollHeight;
        if (room) {
            room.remove();
            room = null;
        }
    });

    it('builds line 2 from the page\'s tab strip and shows every panel under its title', () => {
        expect(header().hidden).toBe(false);
        expect(tabs().map((b) => [b.getAttribute('data-travel-pdpa-tab'), b.textContent]))
            .toEqual([['description', 'Descriere'], ['features', 'Facilități'], ['policies', 'Informații utile']]);
        expect(page().classList.contains('travel-pdpa--stacked')).toBe(true);
        ['description', 'features', 'policies'].forEach((id) => {
            const panel = document.getElementById('content_' + id);
            expect(panel.getAttribute('data-travel-pdpa-panel')).toBe(id);
            expect(panel.firstElementChild.matches('h2.travel-pdpa-tabs__title')).toBe(true);
        });
        expect(document.getElementById('content_features').firstElementChild.textContent).toBe('Facilități');
        // a panel the strip does not list (no content) stays as it is, and so
        // does a tab the theme loads on demand (empty until clicked)
        expect(document.getElementById('content_attachments').hasAttribute('data-travel-pdpa-panel')).toBe(false);
        expect(document.getElementById('content_discussion').hasAttribute('data-travel-pdpa-panel')).toBe(false);
        // the theme keeps its strip on a phone instead of an accordion
        expect(page().querySelector('.ty-tabs').classList.contains('cm-j-tabs-disable-convertation')).toBe(true);
        expect(document.getElementById('tabs_content').classList.contains('cm-j-content-disable-convertation')).toBe(true);
    });

    it('stays away while the hero shows; line 1 once it has gone; line 2 once the tab strip has gone under it', () => {
        scrolled(0);
        expect(header().classList.contains('is-shown')).toBe(false);
        scrolled(805); // the bar's end 1 px above the top: a scroll that lands on it
        expect(header().classList.contains('is-shown')).toBe(false);
        scrolled(810);
        expect(header().classList.contains('is-shown')).toBe(true);
        expect(nav().hidden).toBe(true);
        expect(header().classList.contains('is-docked')).toBe(false);
        scrolled(1450); // the strip at 50 px, under line 1
        expect(nav().hidden).toBe(false);
        expect(header().classList.contains('is-docked')).toBe(true);
        scrolled(500);
        expect(header().classList.contains('is-shown')).toBe(false);
        expect(nav().hidden).toBe(true);
    });

    it('follows the page\'s scroll, once a frame', async () => {
        Object.defineProperty(window, 'pageYOffset', { value: 1000, configurable: true, writable: true });
        stubbed.forEach((el) => {
            const box = el.getBoundingClientRect();
            at(el, box.top - 500, box.height);
        });
        window.dispatchEvent(new Event('scroll'));
        await new Promise((resolve) => setTimeout(resolve, 50));
        expect(header().classList.contains('is-shown')).toBe(true);
    });

    it('"Alege datele" before a search opens the calendar; it gives way while the booking box shows', async () => {
        scrolled(1000);
        expect(cta().textContent).toBe('Alege datele');
        expect(cta().classList.contains('is-away')).toBe(false);

        const trigger = document.getElementById('date-trigger');
        const opened = vi.fn(() => trigger.focus());
        trigger.addEventListener('click', opened);
        const event = click(cta());
        expect(event.defaultPrevented).toBe(true);
        expect(opened).toHaveBeenCalledTimes(1);
        await frames(1);
        expect(window.scrollTo).toHaveBeenCalledTimes(1);
        trigger.removeEventListener('click', opened);
        trigger.blur();
    });

    it('tells the browser and CS-Cart what the header covers, so their own scrolls stop under it', () => {
        const store = {};
        window.jQuery = () => ({
            data: (key, value) => {
                if (value === undefined) {
                    return store[key];
                }
                store[key] = value;
                return undefined;
            },
        });
        window.innerHeight = 400; // a short window: line 1 only
        scrolled(1000);
        expect(document.documentElement.style.scrollPaddingTop).toBe('64px');
        expect(store.caScrollToElmOffset).toBe(64);
        window.innerHeight = 800;
        scrolled(1000);
        expect(document.documentElement.style.scrollPaddingTop).toBe('112px'); // 56 + 48 + 8
        expect(store.caScrollToElmOffset).toBe(112);
        delete window.jQuery;
    });

    it('a short window keeps line 2, then the whole header, away', () => {
        window.innerHeight = 400; // a phone held sideways, a zoomed page
        scrolled(1800);
        expect(header().classList.contains('is-shown')).toBe(true);
        expect(nav().hidden).toBe(true);
        window.innerHeight = 250;
        scrolled(1800);
        expect(header().classList.contains('is-shown')).toBe(false);
        window.innerHeight = 800;
        scrolled(1800);
        expect(nav().hidden).toBe(false);
    });

    it('Sphinx\'s grey placeholders while it searches are not rooms', () => {
        const skeleton = document.createElement('div');
        skeleton.className = 'travel-offer-card sphinx-offer-card travel-skeleton-card sphinx-skeleton-card';
        skeleton.setAttribute('aria-hidden', 'true');
        page().querySelector('.travel-pdp-results').appendChild(skeleton);
        stubbed.add(skeleton);
        at(skeleton, 900 - 1300, 200);
        window.TravelPdpDesignA.updateHeaders();
        expect(cta().textContent).toBe('Alege datele');
        skeleton.remove();
        stubbed.delete(skeleton);
    });

    it('a package by coach or plane chooses a departure: "Alege data plecării"', () => {
        const results = page().querySelector('.travel-pdp-results');
        results.classList.add('travel-pdp-results--package');
        scrolled(1000);
        expect(cta().textContent).toBe('Alege data plecării');
        results.classList.remove('travel-pdp-results--package');
        scrolled(1000);
        expect(cta().textContent).toBe('Alege datele');
    });

    it('"Alege cameră" once rooms show, only while none is on screen; it brings the first under line 1', () => {
        addRoom();
        scrolled(1000); // the room at -100–200: in view under line 1
        expect(cta().textContent).toBe('Alege cameră');
        expect(cta().classList.contains('is-away')).toBe(true);
        scrolled(1300); // scrolled past it
        expect(cta().classList.contains('is-away')).toBe(false);

        click(cta());
        // the room at 900 px stops 8 px under line 1, the focus on it
        expect(window.scrollTo).toHaveBeenCalledWith({ top: 900 - 56 - 8, behavior: 'smooth' });
        expect(document.activeElement).toBe(room);
        expect(room.getAttribute('tabindex')).toBe('-1');
    });

    it('after an inline search, the results come into view again once the engine\'s "searching" row has gone', async () => {
        const wrapper = page().querySelector('.travel-pdp-results .travel-search-form-wrapper');
        const loading = document.createElement('div');
        loading.className = 'nvt-search-loading';
        wrapper.appendChild(loading);
        scrolled(1000);
        window.scrollTo.mockClear();

        document.dispatchEvent(new CustomEvent('travel:results-swapped'));
        await frames(2);
        expect(window.scrollTo).not.toHaveBeenCalled(); // the row still takes room
        loading.remove();
        await frames(2);
        // the first result (the room, at 900 px) under line 1
        expect(window.scrollTo).toHaveBeenCalledWith({ top: 900 - 56 - 8, behavior: 'smooth' });
    });

    it('…but not when the visitor has scrolled meanwhile', async () => {
        const wrapper = page().querySelector('.travel-pdp-results .travel-search-form-wrapper');
        const loading = document.createElement('div');
        loading.className = 'nvt-search-loading';
        wrapper.appendChild(loading);
        window.scrollTo.mockClear();

        document.dispatchEvent(new CustomEvent('travel:results-swapped'));
        await frames(1);
        window.dispatchEvent(new WheelEvent('wheel', { deltaY: 200 }));
        loading.remove();
        await frames(2);
        expect(window.scrollTo).not.toHaveBeenCalled();
    });

    it('a filter that hides every room keeps "Alege cameră", which then leads to the list', () => {
        const list = document.createElement('section');
        list.setAttribute('data-travel-results', '');
        page().querySelector('.travel-pdp-results').appendChild(list);
        room.hidden = true;
        stubbed.add(list);
        const empty = (y) => {
            scrolled(y);
            room.getBoundingClientRect = () => ({ top: 0, bottom: 0, left: 0, right: 0, x: 0, y: 0, width: 0, height: 0 }); // hidden by the filter
            at(list, 900 - y, 120);
            window.TravelPdpDesignA.updateHeaders();
        };
        empty(950); // the list at -50–70 px, its end in view under line 1
        expect(cta().textContent).toBe('Alege cameră');
        expect(cta().classList.contains('is-away')).toBe(true);
        empty(1300);
        expect(cta().classList.contains('is-away')).toBe(false);
        click(cta());
        expect(window.scrollTo).toHaveBeenCalledWith({ top: 900 - 56 - 8, behavior: 'smooth' });
        room.hidden = false;
        list.remove();
        stubbed.delete(list);
    });

    it('the name goes back to the top, the focus on the title; the pin keeps its own link', () => {
        const event = click(header().querySelector('[data-travel-pdpa-top]'));
        expect(event.defaultPrevented).toBe(true);
        expect(window.scrollTo).toHaveBeenCalledWith({ top: 0, behavior: 'smooth' });
        expect(document.activeElement).toBe(page().querySelector('.travel-pdpa-head__title'));

        // A listener after the script's sees the pin's click untouched (then stops jsdom navigating).
        let prevented = null;
        const after = (e) => {
            prevented = e.defaultPrevented;
            e.preventDefault();
        };
        document.addEventListener('click', after);
        click(document.getElementById('sticky-map'));
        document.removeEventListener('click', after);
        expect(prevented).toBe(false); // Google Maps opens in a new tab
        expect(window.scrollTo).toHaveBeenCalledTimes(1);
    });

    it('a tab of line 2 brings its section under both lines and stays marked until the visitor scrolls', () => {
        scrolled(1800);
        expect(active()).toEqual(['features']); // 1560 + 600 - 1800 = 360 px, above the middle (452 px)
        expect(page().querySelector('#features').classList.contains('active')).toBe(true);

        click(tabs()[2]);
        expect(window.scrollTo).toHaveBeenCalledWith({ top: 2760 - 56 - 48 - 8, behavior: 'smooth' });
        expect(document.activeElement).toBe(document.querySelector('#content_policies .travel-pdpa-tabs__title'));
        expect(active()).toEqual(['policies']);
        expect(tabs()[2].getAttribute('aria-current')).toBe('true');
        expect(page().querySelector('#policies').classList.contains('active')).toBe(true);
        expect(page().querySelector('#features').classList.contains('active')).toBe(false);

        scrolled(1800); // the smooth scroll still on its way
        expect(active()).toEqual(['policies']);
        window.dispatchEvent(new WheelEvent('wheel', { deltaY: 120 }));
        scrolled(1800);
        expect(active()).toEqual(['features']);
        expect(tabs()[2].hasAttribute('aria-current')).toBe(false);
    });

    it('a wheel that does not move the page, or a touch on the header, keeps the clicked tab; a touch on the page lets it go', () => {
        scrolled(1800);
        click(tabs()[2]);
        scrolled(1800);
        window.dispatchEvent(new WheelEvent('wheel', { deltaX: 80 })); // sideways
        header().dispatchEvent(new Event('touchstart', { bubbles: true })); // swiping line 2
        scrolled(1800);
        expect(active()).toEqual(['policies']);
        document.body.dispatchEvent(new Event('touchstart', { bubbles: true }));
        scrolled(1800);
        expect(active()).toEqual(['features']);
    });

    it('a scroll cut short and gone still lets the tab go, with no wheel, touch or key', () => {
        vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout', 'Date'] });
        scrolled(1800);
        click(tabs()[2]);
        scrolled(1700); // the scrollbar took over before it arrived
        expect(active()).toEqual(['policies']);
        vi.advanceTimersByTime(1600); // nothing else happens: the check comes on its own
        expect(active()).toEqual(['description']);
        vi.useRealTimers();
    });

    it('Esc closes the name\'s tooltip until the focus moves on', () => {
        const name = header().querySelector('[data-travel-pdpa-top]');
        const box = name.closest('.travel-pdpa-sticky__namebox');
        name.focus();
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        expect(box.classList.contains('is-tip-off')).toBe(true);
        name.blur();
        expect(box.classList.contains('is-tip-off')).toBe(false);
    });

    it('once the tab\'s scroll has arrived, any move of the page lets it go (the scrollbar too)', () => {
        scrolled(1800);
        click(tabs()[2]); // its section stops at the page's end, 2600
        scrolled(2600);
        expect(active()).toEqual(['policies']);
        scrolled(1800); // dragged back up, no wheel, no key
        expect(active()).toEqual(['features']);
    });

    it('marks the first section as line 2 docks, the last one at the very bottom of the page', () => {
        scrolled(1450); // the strip at 50 px, the first section right under it
        expect(active()).toEqual(['description']);
        expect(page().querySelector('#description').classList.contains('active')).toBe(true);
        scrolled(2600); // the window's end at the page's end (3400)
        expect(active()).toEqual(['policies']);
    });

    it('a click the script or the theme sends to the page\'s strip still reaches the theme', () => {
        const theme = vi.fn();
        const li = page().querySelector('#features');
        li.addEventListener('click', theme);
        click(li.querySelector('a'));
        expect(theme).toHaveBeenCalledTimes(1);
        expect(window.scrollTo).not.toHaveBeenCalled();
        li.removeEventListener('click', theme);
    });

    it('the heart clicks the title row\'s wishlist button and turns red', () => {
        const wishlist = document.getElementById('button_wishlist_7');
        const added = vi.fn();
        wishlist.addEventListener('click', added);

        const event = click(fav());

        expect(event.defaultPrevented).toBe(true);
        expect(added).toHaveBeenCalledTimes(1);
        expect(fav().getAttribute('aria-pressed')).toBe('true');
        expect(fav().classList.contains('is-saved')).toBe(true);
        wishlist.removeEventListener('click', added);
    });

    it('no booking form: no button; no wishlist button: no heart; no tabs: no line 2', () => {
        const root = document.getElementById('travel-booking-root');
        const parent = root.parentNode;
        parent.removeChild(root);
        const other = document.createElement('div');
        other.className = 'travel-pdpa';
        other.innerHTML = header().outerHTML.replace(/ data-travel-pdpa-tab="[^"]*"/g, '');
        other.querySelector('[data-travel-pdpa-sticky-tablist]').innerHTML = '';
        other.querySelector('[data-travel-pdpa-sticky]').hidden = true;
        document.body.appendChild(other);

        window.TravelPdpDesignA.init();

        expect(other.querySelector('[data-travel-pdpa-sticky]').hidden).toBe(false);
        expect(other.querySelector('[data-travel-pdpa-cta]').hidden).toBe(true);
        expect(other.querySelector('[data-travel-pdpa-fav]').hidden).toBe(true);
        expect(other.querySelector('[data-travel-pdpa-tab]')).toBeNull();
        expect(other.querySelector('[data-travel-pdpa-sticky-tabs]').hidden).toBe(true);
        other.remove();
        parent.appendChild(root);
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
               data-free="${flags.free ? 1 : 0}" data-immediate="${flags.onRequest ? 0 : 1}" data-amount="${amount}">
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
            <button type="button" data-travel-chip="immediate" aria-pressed="false">Confirmare imediată</button>
            <select data-travel-sort><option value="low" selected>low</option><option value="high">high</option></select>
            <ul data-travel-room-list id="${id}-list">
                ${room(`${id}-dbl`, 'Cameră Dublă', [['bb', 615], ['ai', 845, { free: true }]])}
                ${room(`${id}-deluxe`, 'Cameră Dublă', [['bb', 675], ['ai', 905]])}
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
        click(section.querySelector('[data-travel-chip="free"]'));
        expect(shown()).toEqual(['dbl', 'sea']);
        expect(total('r-dbl')).toBe('845 €');

        click(section.querySelector('[data-travel-meal="bb"]'));
        expect(shown()).toEqual([]);
        expect(section.querySelector('[data-travel-no-match]').hidden).toBe(false);

        click(section.querySelector('[data-travel-clear-filters]'));
        expect(shown()).toEqual(['dbl', 'deluxe', 'sea', 'superior']);
        expect(total('r-dbl')).toBe('615 €');
        expect(section.querySelector('[data-travel-no-match]').hidden).toBe(true);
        expect(section.querySelector('[data-travel-chip="free"]').getAttribute('aria-pressed')).toBe('false');
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

    it('opens above the ⓘ where the bar of the rooms chosen would cover it', () => {
        const bar = document.createElement('div');
        bar.setAttribute('data-travel-bar', '');
        bar.getBoundingClientRect = () => ({ top: 600, bottom: 768, left: 0, right: 1024, width: 1024, height: 168 });
        document.body.appendChild(bar);
        pop.getBoundingClientRect = () => ({ top: 480, bottom: 640, left: 700, right: 1020, width: 320, height: 160 });

        click(tip);
        expect(pop.classList.contains('travel-pdpa-tip__pop--up')).toBe(true);
        click(tip);

        // room under it (no bar): it opens under the ⓘ as before
        bar.remove();
        pop.getBoundingClientRect = () => ({ top: 300, bottom: 460, left: 700, right: 1020, width: 320, height: 160 });
        click(tip);
        expect(pop.classList.contains('travel-pdpa-tip__pop--up')).toBe(false);
        click(tip);
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
        const ended = vi.fn();
        live.addEventListener('travel:live-done', (event) => ended(event.detail));
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
        expect(ended).toHaveBeenCalledTimes(1);
        expect(ended.mock.calls[0][0].shown).toBe(true);
        vi.unstubAllGlobals();
    });

    it('stops at the most polls and says when no room came, for the supplier to say so', async () => {
        const fetch = vi.fn(async () => ({ ok: true, json: async () => ({ html: '', done: false, cursor: 'c', alternatives: [{ check_in: '2027-07-12' }] }) }));
        vi.stubGlobal('fetch', fetch);
        page().insertAdjacentHTML('beforeend', `
            <div data-travel-live id="empty-live" data-travel-live-url="/poll" data-travel-live-interval="1" data-travel-live-max="3">
                <div data-travel-live-results></div>
            </div>`);
        const live = document.getElementById('empty-live');
        const ended = vi.fn();
        live.addEventListener('travel:live-done', (event) => ended(event.detail));
        window.TravelPdpDesignA.armLiveResults();

        await vi.waitFor(() => expect(ended).toHaveBeenCalled());
        expect(fetch).toHaveBeenCalledTimes(3);
        expect(ended.mock.calls[0][0]).toEqual({ shown: false, data: { html: '', done: false, cursor: 'c', alternatives: [{ check_in: '2027-07-12' }] } });
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

describe('the terms on a phone: a bottom sheet (board 14.6)', () => {
    let details;
    let booked;

    beforeAll(() => {
        window.matchMedia = (query) => ({ matches: query === '(max-width: 767px)', media: query, addListener() {}, removeListener() {} });
        page().insertAdjacentHTML('beforeend', `
            <ul><li class="travel-pdpa-rcard" data-travel-room>
                <details class="travel-pdpa-terms">
                    <summary class="travel-pdpa-terms__toggle">Condiții de plată și anulare</summary>
                    <div class="travel-pdpa-terms__panel" data-travel-terms-panel>
                        <div class="travel-pdpa-terms__sheethead"><button type="button" data-travel-terms-close aria-label="Închide condițiile">×</button></div>
                        <div class="travel-pdpa-terms__body"><ol class="travel-timeline"><li>Până pe 4 nov. 2026, ora 23:59</li></ol></div>
                        <div class="travel-pdpa-terms__sheetfoot"><button type="button" data-travel-terms-cta></button></div>
                    </div>
                </details>
                <div class="travel-pdpa-till" data-travel-for="0" hidden><b class="travel-pdpa-till__total">995 €</b><a class="travel-pdpa-till__btn" href="#hb">Rezervă</a></div>
                <div class="travel-pdpa-till" data-travel-for="1"><b class="travel-pdpa-till__total">785 €</b><a class="travel-pdpa-till__btn" href="#bb">Rezervă</a></div>
            </li></ul>`);
        details = document.querySelector('.travel-pdpa-terms');
        booked = vi.fn((event) => event.preventDefault());
        document.querySelector('[href="#bb"]').addEventListener('click', booked);
    });

    const open = () => {
        details.open = true;
        details.dispatchEvent(new Event('toggle'));
    };
    const shut = () => details.dispatchEvent(new Event('toggle'));

    it('stops the page scrolling, focuses its × and says what the card books', () => {
        open();
        expect(document.documentElement.classList.contains('travel-pdpa-sheet-open')).toBe(true);
        expect(document.activeElement).toBe(details.querySelector('[data-travel-terms-close]'));
        expect(details.querySelector('[data-travel-terms-cta]').textContent).toBe('Rezervă · 785 €');

        click(details.querySelector('[data-travel-terms-close]'));
        shut();
        expect(details.open).toBe(false);
        expect(document.documentElement.classList.contains('travel-pdpa-sheet-open')).toBe(false);
        expect(document.activeElement).toBe(details.querySelector('summary'));
    });

    it('closes on Esc and on a tap beside it', () => {
        open();
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        expect(details.open).toBe(false);
        shut();

        open();
        click(details);
        expect(details.open).toBe(false);
        shut();
    });

    it('its button closes it and presses the card\'s own', () => {
        open();
        click(details.querySelector('[data-travel-terms-cta]'));
        expect(details.open).toBe(false);
        expect(booked).toHaveBeenCalledTimes(1);
        shut();
    });
});
