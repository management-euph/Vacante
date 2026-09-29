import { beforeAll, describe, expect, it, vi } from 'vitest';

/**
 * Behavioral tests for Travel Core's destination picker
 * (addon-travel-core/js/addons/travel_core/destination-picker.js), driven
 * through the markup components/destination_picker.tpl, destination_country.tpl
 * and destination_country_body.tpl render.
 *
 * Three pickers share the page, one per supplier shape:
 *   nv — Novoton: country › resort, every body on the page;
 *   es — Eurosite: country › city, an "Own cities" mode, bodies loaded on open;
 *   sp — Sphinx: country › region › city, whole regions, a pager.
 */

const WORDS = {
    search: 'Search', filter: 'Filter…', item_type: 'City', group_type: 'Region', country_type: 'Country',
    gone: 'not in feed', pending: '[n] change not saved|[n] changes not saved', no_pending: 'All changes saved',
    badge_all: 'ALL', group_whole: 'WHOLE REGION', group_some: '[sold] of [total]', group_none: 'none',
    sold: 'sold', not_sold: 'not sold', no_match: 'No match.', more: 'Show [n] more', showing: 'Showing [shown] of [total]',
    visible: '[shown] / [total] countries', fold: '[n] more country has nothing.|[n] more countries have nothing.',
    loading: 'Loading…', load_failed: 'Could not load.',
};

const MODES = {
    nv: [['off', 'none', 'NOT SOLD'], ['all', 'all', 'ALL'], ['specific', 'ticked', '[sold] of [total]']],
    es: [['off', 'none', 'NOT SOLD'], ['all', 'all', 'ALL'], ['own', 'flag:own', 'OWN ([sold])'], ['specific', 'ticked', '[sold] of [total]']],
    sp: [['off', 'none', 'NOT SOLD'], ['all', 'all', 'ALL'], ['specific', 'ticked', '[groups_sold] of [groups] regions']],
};

function item(key, value, label, { checked = false, hotels = 1, live = 0, isNew = false, gone = false, own = false } = {}) {
    return `
        <label class="travel-dest-item${gone ? ' is-gone' : ''}" data-label="${label}" data-search="${label.toLowerCase()} ${value.toLowerCase()}"
               data-hotels="${hotels}" data-priced="0" data-instant="0" data-live="${live}"${isNew ? ' data-new="1"' : ''}${gone ? ' data-gone="1"' : ''}${own ? ' data-flag-own="1"' : ''}>
            <input type="checkbox" name="dest[${key}][items][]" value="${value}"${checked ? ' checked' : ''}>
            <span class="travel-dest-item__label">${label}</span><span data-dest-dot hidden></span>
        </label>`;
}

function group(key, gkey, label, items, { whole = false, open = true } = {}) {
    if (gkey === '') {
        return `<div class="travel-dest-group"><div class="travel-dest-grid" data-dest-grid>${items}</div></div>`;
    }
    return `
        <div class="travel-dest-group" data-group="${gkey}" data-label="${label}" data-search="${label.toLowerCase()}">
            <div class="travel-dest-group__head">
                <button type="button" data-dest-fold-group aria-expanded="${open}">${open ? '−' : '+'}</button>
                <input type="checkbox" name="dest[${key}][groups][]" value="${gkey}" data-dest-group-cb${whole ? ' checked' : ''}>
                <span data-group-badge></span>
            </div>
            <div class="travel-dest-grid" data-dest-grid${open ? '' : ' hidden'}>${items}</div>
        </div>`;
}

function body(shape, key, mode, groups, { ownButton = false, limit = false } = {}) {
    const hints = MODES[shape].map(([v]) => `<span data-hint="${v}"${v === mode ? '' : ' hidden'}>${v}</span>`).join('');
    return `
        <input type="hidden" name="dest[${key}][loaded]" value="1">
        <div data-dest-hint-row>${hints}
            <span data-hint="ticked">
                ${ownButton ? '<button type="button" data-dest-select-flag="own">Select own</button>' : ''}
                <button type="button" data-dest-select-shown>Select shown</button>
                <button type="button" data-dest-clear-shown>Clear shown</button>
            </span>
        </div>
        <div class="travel-dest-tools">
            <button type="button" data-dest-chip="all" aria-pressed="true">All <b data-chip-n></b></button>
            <button type="button" data-dest-chip="flag:own" aria-pressed="false">Own <b data-chip-n></b></button>
            <button type="button" data-dest-chip="sold" aria-pressed="false">Sold <b data-chip-n></b></button>
            <button type="button" data-dest-chip="unsold" aria-pressed="false">Not sold <b data-chip-n></b></button>
            <button type="button" data-dest-chip="new" aria-pressed="false">New <b data-chip-n></b></button>
            <input type="search" data-dest-leaf-filter>
            <select data-dest-sort><option value="">Own first</option><option value="az">A–Z</option><option value="hotels">Most hotels</option></select>
            <button type="button" data-dest-expand>Expand all</button><button type="button" data-dest-collapse>Collapse all</button>
        </div>
        ${groups}
        <p data-dest-nomatch hidden>Nothing matches.</p>
        ${limit ? '<div data-dest-more hidden><span data-dest-more-text></span><button type="button"></button></div>' : ''}`;
}

function country(shape, key, label, mode, inner, { lazy = false, empty = false, attrs = '', flags = '', isNew = 0, facet = '' } = {}) {
    const radios = MODES[shape].map(([v, sells, badge]) => `
        <label class="travel-dest-mode__opt"><input type="radio" name="dest[${key}][mode]" value="${v}" data-sells="${sells}" data-badge="${badge}"${v === mode ? ' checked' : ''}><span>${v}</span></label>`).join('');
    return `
        <section class="travel-dest-country" data-country="${key}" data-label="${label}" data-search="${label.toLowerCase()} ${key.toLowerCase()}"
                 data-new="${isNew}"${facet ? ` data-facet="${facet}"` : ''}${lazy ? ' data-lazy="1"' : ''}${empty ? ' data-empty="1"' : ''} ${flags} ${attrs}>
            <div class="travel-dest-country__head">
                <button type="button" class="travel-dest-country__toggle" aria-expanded="false"><span class="travel-dest-country__arrow">▶</span>${label}</button>
                <span class="travel-dest-badge" data-dest-badge></span>
                <fieldset class="travel-dest-mode">${radios}</fieldset>
            </div>
            <div class="travel-dest-country__body" data-dest-body hidden>${inner}</div>
        </section>`;
}

function picker(id, countries, { bodyUrl = '', searchUrl = '', perPage = 0, leafLimit = 0, filters = '', facet = '' } = {}) {
    return `
        <div class="travel-dest" id="${id}" data-travel-dest data-dest-words='${JSON.stringify(WORDS)}'>
            <div data-dest-total="countries"></div><div data-dest-total="items"></div><div data-dest-total="hotels"></div>
            <button type="button" data-dest-show-new aria-pressed="false">Show new</button>
            <form data-dest-form method="post" id="${id}-form" data-body-url="${bodyUrl}" data-search-url="${searchUrl}" data-per-page="${perPage}" data-leaf-limit="${leafLimit}">
                <input type="hidden" name="dest_json" value="" data-dest-json disabled>
                <input type="search" data-dest-search>
                <div data-dest-results hidden></div>
                <input type="checkbox" data-dest-only-sold>
                ${filters}${facet}
                <span data-dest-count></span>
                ${countries}
                <div data-dest-fold hidden><span data-dest-fold-text></span><button type="button" data-dest-fold-show>Show</button><button type="button" data-dest-fold-hide hidden>Hide</button></div>
                <div data-dest-none hidden>No match.</div>
                <div data-dest-pager hidden><button type="button" data-dest-page="first"></button><button type="button" data-dest-page="prev"></button><span data-dest-page-info></span><button type="button" data-dest-page="next"></button><button type="button" data-dest-page="last"></button></div>
            </form>
            <div data-dest-summary-list></div>
            <div data-dest-pending></div>
            <button type="button" data-dest-undo>Undo</button>
            <button type="button" data-dest-outside-open aria-expanded="false">Disable…</button>
            <form data-dest-outside-confirm hidden><button type="button" data-dest-outside-cancel>Cancel</button></form>
            <div data-dest-savebar hidden><span data-dest-pending></span><button type="button" data-dest-undo>Undo</button></div>
        </div>`;
}

const LAZY_BODIES = {
    TR: body('es', 'TR', 'own', group('TR', '', '',
        item('TR', 'TRAY', 'Antalya', { own: true, hotels: 50 })
        + item('TR', 'TRSD', 'Side', { own: true, hotels: 30 })
        + item('TR', 'TRIS', 'Istanbul', { hotels: 20 })), { ownButton: true }),
    EG: body('es', 'EG', 'off', group('EG', '', '', item('EG', 'EGHR', 'Hurghada', { own: true }) + item('EG', 'EGCA', 'Cairo'))),
};
const fetchCalls = [];
const $ = (sel, root = document) => root.querySelector(sel);
const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));
const tick = () => new Promise((r) => setTimeout(r, 0));
const nv = () => $('#nv');
const es = () => $('#es');
const sp = () => $('#sp');
const section = (root, key) => $(`[data-country="${key}"]`, root);
const radio = (root, key, value) => $(`input[type="radio"][name="dest[${key}][mode]"][value="${value}"]`, root);
const box = (root, key, value) => $(`.travel-dest-item input[value="${value}"]`, section(root, key));
const badge = (root, key) => $('[data-dest-badge]', section(root, key)).textContent;
const pending = (root) => $('[data-dest-pending]', root).textContent;
function change(el) { el.dispatchEvent(new Event('change', { bubbles: true })); }
function pickMode(root, key, value) { radio(root, key, value).checked = true; change(radio(root, key, value)); }
function type(input, value) { input.value = value; input.dispatchEvent(new Event('input', { bubbles: true })); }

beforeAll(async () => {
    Element.prototype.scrollIntoView = vi.fn();
    global.fetch = (url) => {
        fetchCalls.push(url);
        const u = new URL(url, 'http://x/');
        if (u.searchParams.get('q')) {
            return Promise.resolve({ json: () => Promise.resolve({ hits: [{ country: 'EG', item: 'EGHR', label: 'Hurghada' }] }) });
        }
        const html = LAZY_BODIES[u.searchParams.get('country')];
        return Promise.resolve({ json: () => Promise.resolve(html ? { html } : {}) });
    };

    const nvCountries =
        country('nv', 'BULGARIA', 'Bulgaria', 'specific', body('nv', 'BULGARIA', 'specific', group('BULGARIA', '', '',
            item('BULGARIA', 'SUNNY BEACH', 'Sunny Beach', { checked: true, hotels: 206, live: 3 })
            + item('BULGARIA', 'BANSKO', 'Bansko', { hotels: 138 })
            + item('BULGARIA', 'KITEN', 'Kiten', { hotels: 6, isNew: true })
            + item('BULGARIA', 'HISTORICAL PARK', 'Historical Park', { checked: true, gone: true }))), { isNew: 1 })
        + country('nv', 'ALBANIA', 'Albania', 'all', body('nv', 'ALBANIA', 'all', group('ALBANIA', '', '',
            item('ALBANIA', 'DURRES', 'Durres', { hotels: 17 }) + item('ALBANIA', 'GOLEM', 'Golem', { hotels: 16 }))))
        + country('nv', 'GREECE', 'Greece', 'off', body('nv', 'GREECE', 'off', group('GREECE', '', '', item('GREECE', 'THASSOS', 'Thassos', { hotels: 61 }))))
        + country('nv', 'CYPRUS', 'Cyprus', 'off', '<input type="hidden" name="dest[CYPRUS][loaded]" value="1"><p>No hotels.</p>', { empty: true })
        + country('nv', 'EGYPT', 'Egypt', 'off', '<input type="hidden" name="dest[EGYPT][loaded]" value="1"><p>No hotels.</p>', { empty: true });

    const esCountries =
        country('es', 'TR', 'Turkey', 'own', '', { lazy: true, flags: 'data-flag-own="2"', attrs: 'data-total="3" data-saved-sold="2" data-saved-hotels="80" data-own-sold="2" data-own-hotels="80" data-all-sold="3" data-all-hotels="100"' })
        + country('es', 'EG', 'Egypt', 'off', '', { lazy: true, flags: 'data-flag-own="1"', attrs: 'data-total="2" data-all-sold="2" data-all-hotels="2"' })
        + country('es', 'RO', 'Romania', 'specific', body('es', 'RO', 'specific', group('RO', '', '',
            item('RO', 'ROMM', 'Mamaia', { checked: true, own: true, hotels: 96 })
            + item('RO', 'ROEN', 'Eforie Nord', { own: true, hotels: 64 })
            + item('RO', 'ROBU', 'Bucuresti', { hotels: 88 })
            + item('RO', 'ROSB', 'Sibiu', { hotels: 22 })), { ownButton: true, limit: true }), { flags: 'data-flag-own="2"' });

    const spCountries =
        country('sp', 'GR', 'Greece', 'specific', body('sp', 'GR', 'specific',
            group('GR', '10', 'Crete', item('GR', '11', 'Heraklion', { checked: true, hotels: 120 }) + item('GR', '12', 'Chania', { checked: true, hotels: 85 }), { whole: true })
            + group('GR', '20', 'Rhodes', item('GR', '21', 'Lindos', { checked: true, hotels: 25 }) + item('GR', '22', 'Faliraki', { hotels: 40 }))
            + group('GR', '30', 'Kos', item('GR', '31', 'Kos Town', { hotels: 0 }), { open: false })), { facet: 'Europe' })
        + country('sp', 'TR', 'Turkey', 'all', body('sp', 'TR', 'all', group('TR', '40', 'Antalya', item('TR', '41', 'Belek', { hotels: 80 }))), { facet: 'Asia' })
        + ['IT', 'CY', 'TN'].map((k) => country('sp', k, k, 'off', body('sp', k, 'off', group(k, `9${k}`, `${k} region`, item(k, `9${k}1`, `${k} city`))), { facet: k === 'TN' ? 'Africa' : 'Europe' })).join('');

    document.body.innerHTML =
        picker('nv', nvCountries)
        + picker('es', esCountries, { bodyUrl: 'admin.php?dispatch=eurosite.whitelist_body', searchUrl: 'admin.php?dispatch=eurosite.search', leafLimit: 3,
            filters: '<input type="checkbox" data-dest-filter="own">' })
        + picker('sp', spCountries, { perPage: 2, facet: '<select data-dest-facet><option value=""></option><option>Europe</option><option>Asia</option><option>Africa</option></select>' });

    await import('../../addon-travel-core/js/addons/travel_core/destination-picker.js');
});

describe('Novoton shape (every body on the page)', () => {
    it('badges and totals follow the saved choice, gone resorts left out', () => {
        expect(badge(nv(), 'BULGARIA')).toBe('1 of 3');
        expect(badge(nv(), 'ALBANIA')).toBe('ALL');
        expect(badge(nv(), 'GREECE')).toBe('NOT SOLD');
        expect($('[data-dest-total="countries"]', nv()).textContent).toBe('2');
        expect($('[data-dest-total="items"]', nv()).textContent).toBe('3');
        expect($('[data-dest-total="hotels"]', nv()).textContent).toBe('239');
        expect(pending(nv())).toBe('All changes saved');
        expect($('[data-dest-savebar]', nv()).hidden).toBe(true);
    });

    it('folds the countries that are not sold and have nothing synced', () => {
        expect(section(nv(), 'CYPRUS').hidden).toBe(true);
        expect($('[data-dest-fold]', nv()).hidden).toBe(false);
        expect($('[data-dest-fold-text]', nv()).textContent).toBe('2 more countries have nothing.');
        $('[data-dest-fold-show]', nv()).click();
        expect(section(nv(), 'CYPRUS').hidden).toBe(false);
        $('[data-dest-fold-hide]', nv()).click();
        expect(section(nv(), 'CYPRUS').hidden).toBe(true);
    });

    it('a tick counts as a change, shows the save bar, and Undo puts it back', () => {
        box(nv(), 'BULGARIA', 'BANSKO').click();
        expect(badge(nv(), 'BULGARIA')).toBe('2 of 3');
        expect(pending(nv())).toBe('1 change not saved');
        expect($('[data-dest-savebar]', nv()).hidden).toBe(false);
        expect($('[data-dest-dot]', box(nv(), 'BULGARIA', 'BANSKO').closest('label')).hidden).toBe(false);
        $('[data-dest-undo]', nv()).click();
        expect(box(nv(), 'BULGARIA', 'BANSKO').checked).toBe(false);
        expect(pending(nv())).toBe('All changes saved');
    });

    it('All resorts -> Only selected keeps selling what was sold', () => {
        pickMode(nv(), 'ALBANIA', 'specific');
        expect(box(nv(), 'ALBANIA', 'DURRES').checked).toBe(true);
        expect(box(nv(), 'ALBANIA', 'GOLEM').checked).toBe(true);
        expect(badge(nv(), 'ALBANIA')).toBe('2 of 2');
        expect(pending(nv())).toBe('1 change not saved', 'a changed mode counts once, whatever it ticks');
        $('[data-dest-undo]', nv()).click();
    });

    it('chips and Select shown work on what is shown', () => {
        const bg = section(nv(), 'BULGARIA');
        $('.travel-dest-country__toggle', bg).click();
        $('[data-dest-chip="new"]', bg).click();
        expect(box(nv(), 'BULGARIA', 'KITEN').closest('label').hidden).toBe(false);
        expect(box(nv(), 'BULGARIA', 'BANSKO').closest('label').hidden).toBe(true);
        $('[data-dest-select-shown]', bg).click();
        expect(box(nv(), 'BULGARIA', 'KITEN').checked).toBe(true);
        expect(box(nv(), 'BULGARIA', 'BANSKO').checked).toBe(false);
        $('[data-dest-chip="all"]', bg).click();
        expect($('[data-dest-chip="sold"] [data-chip-n]', bg).textContent).toBe('2');
        $('[data-dest-undo]', nv()).click();
    });

    it('picking a resort in the search opens its country, switches it to Only selected and ticks it', () => {
        const search = $('[data-dest-search]', nv());
        type(search, 'thas');
        const hits = $$('.travel-dest__hit', nv());
        expect(hits.map((h) => Array.from(h.children).map((c) => c.textContent.trim()).join(' '))).toEqual(['Country Greece', 'City Thassos not sold']);
        expect(section(nv(), 'BULGARIA').hidden).toBe(true, 'the list follows the search');
        hits[1].click();
        return tick().then(() => {
            expect(radio(nv(), 'GREECE', 'specific').checked).toBe(true);
            expect(box(nv(), 'GREECE', 'THASSOS').checked).toBe(true);
            expect(search.value).toBe('');
            expect(section(nv(), 'BULGARIA').hidden).toBe(false);
            $('[data-dest-undo]', nv()).click();
        });
    });

    it('Save sends one JSON field and disables the separate fields', () => {
        const form = $('[data-dest-form]', nv());
        box(nv(), 'BULGARIA', 'BANSKO').click();
        form.dispatchEvent(new Event('submit', { cancelable: true }));
        const json = $('[data-dest-json]', nv());
        expect(json.disabled).toBe(false);
        const data = JSON.parse(json.value);
        expect(data.BULGARIA).toEqual({ mode: 'specific', loaded: true, items: ['SUNNY BEACH', 'BANSKO', 'HISTORICAL PARK'], groups: [] });
        expect(data.ALBANIA.mode).toBe('all');
        expect(radio(nv(), 'BULGARIA', 'all').disabled).toBe(true);
        window.dispatchEvent(new Event('pageshow'));
        expect(radio(nv(), 'BULGARIA', 'all').disabled).toBe(false);
        expect(json.disabled).toBe(true);
        $('[data-dest-undo]', nv()).click();
    });
});

describe('Eurosite shape (bodies loaded on open, an Own cities mode)', () => {
    it('a body not loaded yet shows the server figures', () => {
        expect(badge(es(), 'TR')).toBe('OWN (2)');
        expect($('[data-dest-total="items"]', es()).textContent).toBe('3');
        expect(fetchCalls.some((u) => u.includes('whitelist_body'))).toBe(false);
    });

    it('opening a country loads its body once', async () => {
        $('.travel-dest-country__toggle', section(es(), 'TR')).click();
        await tick(); await tick();
        expect(section(es(), 'TR').hasAttribute('data-loaded')).toBe(true);
        expect(box(es(), 'TR', 'TRAY')).not.toBeNull();
        expect(box(es(), 'TR', 'TRAY').disabled).toBe(true, 'Own cities decides; nothing to tick');
        expect(fetchCalls.filter((u) => u.includes('country=TR'))).toHaveLength(1);
        $('.travel-dest-country__toggle', section(es(), 'TR')).click();
        $('.travel-dest-country__toggle', section(es(), 'TR')).click();
        expect(fetchCalls.filter((u) => u.includes('country=TR'))).toHaveLength(1);
    });

    it('Own cities -> Only selected ticks the own cities', () => {
        pickMode(es(), 'TR', 'specific');
        expect(box(es(), 'TR', 'TRAY').checked).toBe(true);
        expect(box(es(), 'TR', 'TRSD').checked).toBe(true);
        expect(box(es(), 'TR', 'TRIS').checked).toBe(false);
        expect(badge(es(), 'TR')).toBe('2 of 3');
        $('[data-dest-undo]', es()).click();
        expect(badge(es(), 'TR')).toBe('OWN (2)');
    });

    it('Select own cities, and the own chip', () => {
        const ro = section(es(), 'RO');
        $('.travel-dest-country__toggle', ro).click();
        $('[data-dest-select-flag="own"]', ro).click();
        expect(box(es(), 'RO', 'ROEN').checked).toBe(true);
        expect(box(es(), 'RO', 'ROBU').checked).toBe(false);
        $('[data-dest-chip="flag:own"]', ro).click();
        expect(box(es(), 'RO', 'ROBU').closest('label').hidden).toBe(true);
        $('[data-dest-chip="all"]', ro).click();
        $('[data-dest-undo]', es()).click();
    });

    it('past the limit, "Show more" shows the rest', () => {
        const ro = section(es(), 'RO');
        expect(box(es(), 'RO', 'ROSB').closest('label').hidden).toBe(true);
        expect($('[data-dest-more]', ro).hidden).toBe(false);
        expect($('[data-dest-more-text]', ro).textContent).toBe('Showing 3 of 4');
        $('[data-dest-more] button', ro).click();
        expect(box(es(), 'RO', 'ROSB').closest('label').hidden).toBe(false);
        expect($('[data-dest-more]', ro).hidden).toBe(true);
    });

    it('the own filter keeps the countries with own cities', () => {
        const own = $('[data-dest-filter="own"]', es());
        own.checked = true; change(own);
        expect(section(es(), 'EG').hidden).toBe(false);
        own.checked = false; change(own);
    });

    it('a country never opened posts its mode only, flagged not loaded', () => {
        const form = $('[data-dest-form]', es());
        form.dispatchEvent(new Event('submit', { cancelable: true }));
        const data = JSON.parse($('[data-dest-json]', es()).value);
        expect(data.EG).toEqual({ mode: 'off', loaded: false, items: [], groups: [] });
        expect(data.TR.loaded).toBe(true);
        window.dispatchEvent(new Event('pageshow'));
    });

    it('the server search finds cities in bodies not loaded; picking one loads it and ticks it', async () => {
        const search = $('[data-dest-search]', es());
        type(search, 'hurg');
        await new Promise((r) => setTimeout(r, 300));
        await tick();
        const hit = $$('.travel-dest__hit', es()).find((h) => h.getAttribute('data-hit-item') === 'EGHR');
        expect(hit).toBeTruthy();
        expect(section(es(), 'EG').hidden).toBe(false);
        hit.click();
        await tick(); await tick(); await tick();
        expect(radio(es(), 'EG', 'specific').checked).toBe(true);
        expect(box(es(), 'EG', 'EGHR').checked).toBe(true);
        expect(box(es(), 'EG', 'EGCA').checked).toBe(false);
    });
});

describe('Sphinx shape (regions, whole regions, a pager)', () => {
    it('regions say how much of them is sold; a ticked region is whole', () => {
        const gr = section(sp(), 'GR');
        expect(badge(sp(), 'GR')).toBe('2 of 3 regions');
        expect($('[data-group="10"] [data-group-badge]', gr).textContent).toBe('WHOLE REGION');
        expect($('[data-group="20"] [data-group-badge]', gr).textContent).toBe('1 of 2');
        expect($('[data-group="30"] [data-group-badge]', gr).textContent).toBe('none');
        expect($('[data-group="20"] [data-dest-group-cb]', gr).indeterminate).toBe(true);
    });

    it('unticking a city makes its region partial; ticking the region makes it whole again', () => {
        const gr = section(sp(), 'GR');
        box(sp(), 'GR', '11').click();
        const crete = $('[data-group="10"] [data-dest-group-cb]', gr);
        expect(crete.checked).toBe(false);
        expect(crete.indeterminate).toBe(true);
        expect($('[data-group="10"] [data-group-badge]', gr).textContent).toBe('1 of 2');
        crete.click();
        expect(box(sp(), 'GR', '11').checked).toBe(true);
        expect($('[data-group="10"] [data-group-badge]', gr).textContent).toBe('WHOLE REGION');
        expect(pending(sp())).toBe('All changes saved', 'back to the saved choice');
    });

    it('all cities ticked one by one is not the whole region', () => {
        const gr = section(sp(), 'GR');
        box(sp(), 'GR', '22').click();
        expect($('[data-group="20"] [data-dest-group-cb]', gr).checked).toBe(false);
        expect($('[data-group="20"] [data-group-badge]', gr).textContent).toBe('2 of 2');
        $('[data-dest-undo]', sp()).click();
    });

    it('Expand all opens every region', () => {
        const gr = section(sp(), 'GR');
        $('.travel-dest-country__toggle', gr).click();
        expect($('[data-group="30"] [data-dest-grid]', gr).hidden).toBe(true);
        $('[data-dest-expand]', gr).click();
        expect($('[data-group="30"] [data-dest-grid]', gr).hidden).toBe(false);
        $('[data-dest-collapse]', gr).click();
        expect($('[data-group="10"] [data-dest-grid]', gr).hidden).toBe(true);
    });

    it('the pager windows the countries; the continent filter narrows them', () => {
        const pager = $('[data-dest-pager]', sp());
        expect(pager.hidden).toBe(false);
        expect($('[data-dest-page-info]', pager).textContent).toBe('1–2 / 5');
        expect(section(sp(), 'IT').hidden).toBe(true);
        $('[data-dest-page="next"]', pager).click();
        expect(section(sp(), 'IT').hidden).toBe(false);
        expect(section(sp(), 'GR').hidden).toBe(true);
        const facet = $('[data-dest-facet]', sp());
        facet.value = 'Africa'; change(facet);
        expect(section(sp(), 'TN').hidden).toBe(false);
        expect(section(sp(), 'GR').hidden).toBe(true);
        expect(pager.hidden).toBe(true);
        facet.value = ''; change(facet);
    });

    it('All destinations -> Only selected ticks whole regions', () => {
        pickMode(sp(), 'TR', 'specific');
        const tr = section(sp(), 'TR');
        expect($('[data-group="40"] [data-dest-group-cb]', tr).checked).toBe(true);
        expect(box(sp(), 'TR', '41').checked).toBe(true);
        const form = $('[data-dest-form]', sp());
        form.dispatchEvent(new Event('submit', { cancelable: true }));
        const data = JSON.parse($('[data-dest-json]', sp()).value);
        expect(data.TR.groups).toEqual(['40']);
        expect(data.GR.groups).toEqual(['10']);
        window.dispatchEvent(new Event('pageshow'));
    });
});
