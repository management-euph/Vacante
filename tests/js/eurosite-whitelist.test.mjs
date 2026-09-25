import { beforeAll, afterEach, describe, expect, it, vi } from 'vitest';

/**
 * Behavioral tests for the eurosite destination-whitelist editor
 * (eurosite_addon/js/addons/eurosite/whitelist.js) — the REAL file the admin
 * loads, driven through the DOM exactly as whitelist.tpl renders it.
 *
 * The page shipped inert: the template asked for the script at a path where it
 * did not exist, so search, expand and save all did nothing. Once the file was
 * put where the template looks, these pin the behaviour itself:
 *
 *   - the expand arrow (and the country name) opens a country and lazily
 *     fetches its cities,
 *   - the search box queries the server and renders its own dropdown — it used
 *     to be a select2 widget that silently no-op'd when the library was absent,
 *   - picking a search result navigates WITHOUT ticking anything,
 *   - the save payload matches what the controller expects.
 */

const CITIES = {
    RO: [
        { code: 'BUH', name: 'Bucharest', is_own: false },
        { code: 'CLJ', name: 'Cluj-Napoca', is_own: true, hotels: 97 },
    ],
    IT: [{ code: 'ROM', name: 'Rome', is_own: false }],
};

const SEARCH_RESULTS = [
    { id: 'country:IT', type: 'country', country_code: 'IT', city_code: '', text: 'Italy (IT)' },
    { id: 'city:RO:CLJ', type: 'city', country_code: 'RO', city_code: 'CLJ', text: 'Cluj-Napoca — Romania' },
];

let fetchCalls = [];

/** The markup whitelist.tpl produces, for two countries. */
function countryRow(cc, name, checked, own = 0) {
    return `
        <div class="eurosite-country-row" id="eurosite-wl-row-${cc}" data-country="${cc}" data-own="${own}">
            <div>
                <span class="eurosite-expand" data-country="${cc}">&#9654;</span>
                <input type="checkbox" class="eurosite-country-all" data-country="${cc}"${checked ? ' checked' : ''} />
                <span class="eurosite-country-name" data-country="${cc}">${name}</span>
                <code>${cc}</code>
                <span class="eurosite-wl-badge" data-country="${cc}"></span>
            </div>
            <div class="eurosite-city-box" data-country="${cc}" style="display: none;">
                <div>
                    <label>
                        <input type="checkbox" class="eurosite-select-all" data-country="${cc}"${checked ? ' checked' : ''} />
                    </label>
                </div>
                ${own ? `<label><input type="checkbox" class="eurosite-own-cities" data-country="${cc}" /></label>` : ''}
                <div class="eurosite-city-grid" data-country="${cc}"></div>
            </div>
        </div>`;
}

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
/** The script debounces the search by 250ms and then awaits fetch. */
const afterSearch = () => sleep(320);

beforeAll(async () => {
    document.body.innerHTML = `
        <div id="eurosite-whitelist">
            <div id="eurosite-whitelist-data"
                 data-whitelist='{"RO":{"all":true,"cities":[]}}'
                 data-cities-url="admin.php?dispatch=eurosite.get_cities"
                 data-search-url="admin.php?dispatch=eurosite.search_destinations"
                 data-txt-loading="Loading..."
                 data-txt-no-cities="No cities found."
                 data-txt-failed="Request failed."
                 data-txt-all-badge="ALL CITIES"
                 data-txt-selected="selected"
                 data-txt-shown="shown"
                 data-txt-confirm-remove="Remove all?"
                 data-txt-no-results="No matches."
                 data-txt-countries="Countries"
                 data-txt-cities="Cities"></div>

            <input type="text" id="eurosite-wl-search" />
            <div id="eurosite-wl-search-results" style="display:none;"></div>
            <input type="checkbox" id="eurosite-wl-filter" />
            <input type="checkbox" id="eurosite-wl-own-filter" />
            <span id="eurosite-wl-filter-count"></span>

            <form id="eurosite-whitelist-form">
                <input type="hidden" name="whitelist_json" id="eurosite-whitelist-json" value="" />
                <div id="eurosite-country-list">
                    ${countryRow('RO', 'Romania', true, 1)}
                    ${countryRow('IT', 'Italy', false)}
                </div>
            </form>

            <span id="eurosite-wl-summary-countries"></span>
            <span id="eurosite-wl-summary-cities"></span>
            <div id="eurosite-wl-summary-detail"></div>
            <button type="button" id="eurosite-wl-save"></button>
            <button type="button" id="eurosite-wl-remove-all"></button>
        </div>`;

    // jsdom implements neither of these.
    Element.prototype.scrollIntoView = vi.fn();
    HTMLFormElement.prototype.submit = vi.fn();

    global.fetch = (url) => {
        fetchCalls.push(url);
        const body = url.includes('search_destinations')
            ? { success: true, results: SEARCH_RESULTS }
            : { success: true, source: 'db', cities: CITIES[new URL(url, 'http://x/').searchParams.get('country')] || [] };

        return Promise.resolve({ json: () => Promise.resolve(body) });
    };

    await import('../../eurosite_addon/js/addons/eurosite/whitelist.js');
});

afterEach(() => {
    fetchCalls = [];
});

const $ = (sel) => document.querySelector(sel);
const $$ = (sel) => Array.prototype.slice.call(document.querySelectorAll(sel));
const box = (cc) => $(`.eurosite-city-box[data-country="${cc}"]`);
const cityBoxes = (cc) => $$(`.eurosite-city[data-country="${cc}"]`);

describe('initial paint from the saved whitelist', () => {
    it('counts the saved country in the summary and badges it as ALL', () => {
        expect($('#eurosite-wl-summary-countries').textContent).toBe('1');
        expect($('.eurosite-wl-badge[data-country="RO"]').innerHTML).toContain('ALL CITIES');
        expect($('.eurosite-wl-badge[data-country="IT"]').innerHTML).toBe('');
    });
});

describe('expanding a country', () => {
    it('opens on the arrow, fetches that country\'s cities once, and closes again', async () => {
        const arrow = $('.eurosite-expand[data-country="IT"]');

        arrow.click();
        await sleep(0);

        expect(box('IT').style.display).toBe('block');
        expect(arrow.textContent).toBe('\u25BC'); // ▼ — jsdom decodes the entity
        expect(fetchCalls).toHaveLength(1);
        expect(fetchCalls[0]).toContain('dispatch=eurosite.get_cities');
        expect(fetchCalls[0]).toContain('country=IT');
        expect(cityBoxes('IT').map((cb) => cb.value)).toEqual(['ROM']);

        arrow.click();
        expect(box('IT').style.display).toBe('none');
        expect(arrow.textContent).toBe('\u25B6'); // ▶

        // Re-opening uses the cached list — no second request.
        arrow.click();
        await sleep(0);
        expect(fetchCalls).toHaveLength(1);
    });

    it('also opens when the country name is clicked', async () => {
        const name = $('.eurosite-country-name[data-country="RO"]');

        name.click();
        await sleep(0);

        expect(box('RO').style.display).toBe('block');
        // RO is whitelisted as "all", so every city comes back ticked.
        expect(cityBoxes('RO').every((cb) => cb.checked)).toBe(true);
    });

    it('shows how many synced hotels each destination brings', async () => {
        $('.eurosite-country-name[data-country="RO"]').click();
        await sleep(0);

        const label = (code) => cityBoxes('RO').find((cb) => cb.value === code).closest('label').textContent;
        expect(label('CLJ')).toContain('97 hotels');
        expect(label('BUH')).not.toContain('hotels'); // none synced: nothing to say
    });
});

describe('search', () => {
    it('queries the server after the debounce and groups the results', async () => {
        const input = $('#eurosite-wl-search');
        input.value = 'ro';
        input.dispatchEvent(new Event('input'));
        await afterSearch();

        expect(fetchCalls.some((u) => u.includes('dispatch=eurosite.search_destinations'))).toBe(true);
        expect(fetchCalls.some((u) => u.includes('q=ro'))).toBe(true);

        const results = $('#eurosite-wl-search-results');
        expect(results.style.display).toBe('block');
        expect(results.textContent).toContain('Countries');
        expect(results.textContent).toContain('Italy (IT)');
        expect(results.textContent).toContain('Cities');
        expect(results.textContent).toContain('Cluj-Napoca — Romania');
    });

    it('does not query for a single character', async () => {
        const input = $('#eurosite-wl-search');
        input.value = 'r';
        input.dispatchEvent(new Event('input'));
        await afterSearch();

        expect(fetchCalls).toHaveLength(0);
        expect($('#eurosite-wl-search-results').style.display).toBe('none');
    });

    it('opens the country a hit belongs to WITHOUT whitelisting anything', async () => {
        const before = $('#eurosite-wl-summary-countries').textContent;

        const input = $('#eurosite-wl-search');
        input.value = 'italy';
        input.dispatchEvent(new Event('input'));
        await afterSearch();

        $$('.eurosite-wl-hit')[0].click();
        await sleep(0);

        expect(box('IT').style.display).toBe('block');
        expect($('#eurosite-wl-search-results').style.display).toBe('none');
        expect(input.value).toBe('');
        // Navigating is not selecting: a mistyped search must not silently
        // whitelist a destination.
        expect($('.eurosite-country-all[data-country="IT"]').checked).toBe(false);
        expect($('#eurosite-wl-summary-countries').textContent).toBe(before);
    });
});

describe('selection semantics', () => {
    it('ticking a country selects all of its cities and badges it ALL', async () => {
        const cb = $('.eurosite-country-all[data-country="IT"]');
        cb.checked = true;
        cb.dispatchEvent(new Event('change'));

        expect($('.eurosite-wl-badge[data-country="IT"]').innerHTML).toContain('ALL CITIES');
        expect(cityBoxes('IT').every((c) => c.checked)).toBe(true);
        expect($('#eurosite-wl-summary-countries').textContent).toBe('2');
    });

    it('unticking one city drops the country to a specific selection', () => {
        const city = cityBoxes('RO').find((c) => c.value === 'CLJ');
        city.checked = false;
        city.dispatchEvent(new Event('change'));

        const badge = $('.eurosite-wl-badge[data-country="RO"]').innerHTML;
        expect(badge).toContain('1 selected');
        expect($('.eurosite-country-all[data-country="RO"]').checked).toBe(true);
    });

    it('unticking the last city removes the country from the whitelist', () => {
        const city = cityBoxes('RO').find((c) => c.value === 'BUH');
        city.checked = false;
        city.dispatchEvent(new Event('change'));

        expect($('.eurosite-wl-badge[data-country="RO"]').innerHTML).toBe('');
        expect($('.eurosite-country-all[data-country="RO"]').checked).toBe(false);
    });

    it('re-ticking every city promotes the country back to ALL', () => {
        cityBoxes('RO').forEach((c) => {
            c.checked = true;
            c.dispatchEvent(new Event('change'));
        });

        expect($('.eurosite-wl-badge[data-country="RO"]').innerHTML).toContain('ALL CITIES');
    });
});

describe('save', () => {
    it('serialises one entry per selection in the shape the controller parses', () => {
        // RO: all. IT: all (set above). Narrow IT to one city first.
        const rom = cityBoxes('IT').find((c) => c.value === 'ROM');
        expect(rom).toBeDefined();

        $('#eurosite-wl-save').click();

        const payload = JSON.parse($('#eurosite-whitelist-json').value);
        expect(payload).toEqual(
            expect.arrayContaining([
                { country_code: 'RO', city_code: '', selection_type: 'all' },
                { country_code: 'IT', city_code: '', selection_type: 'all' },
            ]),
        );
        expect(HTMLFormElement.prototype.submit).toHaveBeenCalled();
    });
});

describe('remove all', () => {
    it('clears every selection once confirmed', () => {
        window.confirm = () => true;

        $('#eurosite-wl-remove-all').click();

        expect($('#eurosite-wl-summary-countries').textContent).toBe('0');
        expect($$('.eurosite-country-all').every((c) => !c.checked)).toBe(true);
        expect($$('.eurosite-wl-badge').every((b) => b.innerHTML === '')).toBe(true);
    });

    it('does nothing when the confirm is declined', () => {
        window.confirm = () => false;
        const cb = $('.eurosite-country-all[data-country="IT"]');
        cb.checked = true;
        cb.dispatchEvent(new Event('change'));

        $('#eurosite-wl-remove-all').click();

        expect($('#eurosite-wl-summary-countries').textContent).toBe('1');
    });
});

describe('own hotels: countries on the page, cities inside each country', () => {
    const toggle = (sel, on) => {
        const cb = $(sel);
        cb.checked = on;
        cb.dispatchEvent(new Event('change', { bubbles: true }));
    };
    const shown = (el) => el.style.display !== 'none';
    const cityLabel = (cc, code) => cityBoxes(cc).find((cb) => cb.value === code).closest('label');
    const openRo = async () => {
        if (box('RO').style.display !== 'block') {
            $('.eurosite-country-name[data-country="RO"]').click();
            await sleep(0);
        }
    };

    it('the page filter keeps countries with own cities, and an open country still shows all its cities', async () => {
        await openRo();
        toggle('#eurosite-wl-own-filter', true);

        expect(shown($('#eurosite-wl-row-RO'))).toBe(true);
        expect(shown($('#eurosite-wl-row-IT'))).toBe(false); // no own-offer city
        expect(shown(cityLabel('RO', 'CLJ'))).toBe(true);
        expect(shown(cityLabel('RO', 'BUH'))).toBe(true); // cities are not filtered by it
        expect($('#eurosite-wl-filter-count').textContent).toBe('1 / 2 shown');

        toggle('#eurosite-wl-own-filter', false);
        expect(shown($('#eurosite-wl-row-IT'))).toBe(true);
        expect($('#eurosite-wl-filter-count').textContent).toBe('');
    });

    it('"Show only own cities" inside a country keeps its own-offer cities, for that country only', async () => {
        await openRo();
        toggle('.eurosite-own-cities[data-country="RO"]', true);

        expect(shown(cityLabel('RO', 'CLJ'))).toBe(true);  // is_own
        expect(shown(cityLabel('RO', 'BUH'))).toBe(false);
        expect(shown($('#eurosite-wl-row-IT'))).toBe(true); // other countries untouched

        toggle('.eurosite-own-cities[data-country="RO"]', false);
        expect(shown(cityLabel('RO', 'BUH'))).toBe(true);
    });

    it('a country without own cities has no such option', () => {
        expect($('.eurosite-own-cities[data-country="IT"]')).toBeNull();
    });

    it('the page filters combine', () => {
        // Earlier cases may have cleared the whitelist: whitelist RO again.
        const ro = $('.eurosite-country-all[data-country="RO"]');
        if (!ro.checked) {
            ro.checked = true;
            ro.dispatchEvent(new Event('change', { bubbles: true }));
        }
        toggle('#eurosite-wl-filter', true);
        toggle('#eurosite-wl-own-filter', true);

        expect(shown($('#eurosite-wl-row-IT'))).toBe(false);
        expect(shown($('#eurosite-wl-row-RO'))).toBe(true);

        toggle('#eurosite-wl-filter', false);
        toggle('#eurosite-wl-own-filter', false);
    });
});
