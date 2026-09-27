import { beforeAll, describe, expect, it } from 'vitest';

/**
 * Behavioral tests for the Destinations page
 * (addon-novoton-holidays/js/addons/novoton_holidays/destinations.js).
 *
 * Each country is Not sold / All resorts / Only selected (radio buttons);
 * the search lists matching countries and resorts under the box, as on the
 * Sphinx whitelist, and picking a resort ticks it.
 */

function resort(country, name, label, hotels, { checked = false, isNew = false, gone = false } = {}) {
    return `
        <label class="novoton-dest-resort" data-resort="${label.toLowerCase()} ${name.toLowerCase()}" data-name="${label}"
               data-hotels="${hotels}" data-priced="0" data-live="0"${isNew ? ' data-new="1"' : ''}${gone ? ' data-gone="1"' : ''}>
            <input type="checkbox" name="destinations[${country}][resorts][]" value="${name}"${checked ? ' checked' : ''}>
            <span class="novoton-dest-resort__name">${label}<span class="novoton-dest-resort__dot" hidden></span></span>
        </label>`;
}

function country(code, label, mode, resorts, index) {
    const radios = ['off', 'all', 'specific'].map((m) => `
        <label class="novoton-dest-mode__opt"><input type="radio" name="destinations[${code}][mode]" value="${m}"${m === mode ? ' checked' : ''}><span>${m}</span></label>`).join('');
    return `
        <section class="novoton-dest-country" data-country="${code}" data-label="${label}" data-name="${label.toLowerCase()} ${code.toLowerCase()}">
            <div class="novoton-dest-country__head">
                <button type="button" class="novoton-dest-country__toggle" aria-expanded="false"><span class="novoton-dest-country__arrow">▶</span>${label}</button>
                <span class="novoton-dest-badge" data-badge></span>
                <fieldset class="novoton-dest-mode">${radios}</fieldset>
            </div>
            <div class="novoton-dest-country__body" id="dest-body-${index}" hidden>
                <span data-hint="off">off</span><span data-hint="all">all</span><span data-hint="specific">specific</span>
                <button type="button" class="btn" data-select-shown>Select shown</button>
                <button type="button" class="btn" data-clear-shown>Clear shown</button>
                <div class="novoton-dest-grid">${resorts}</div>
            </div>
        </section>`;
}

beforeAll(async () => {
    document.body.innerHTML = `
        <div class="novoton-dest">
            <button type="button" id="dest-show-new" aria-pressed="false">Show new</button>
            <form id="novoton-dest-form" method="post"
                  data-txt-pending="[n] changes not saved" data-txt-none="All changes saved"
                  data-txt-all="ALL" data-txt-off="NOT SOLD" data-txt-some="[sold] of [total]"
                  data-txt-country="Country" data-txt-resort="Resort" data-txt-sold="sold" data-txt-not-sold="not sold" data-txt-no-match="No match">
                <input type="search" id="dest-search">
                <div id="dest-search-results" hidden></div>
                <input type="checkbox" id="dest-only-sold">
                <span id="dest-visible-count"></span>
                ${country('BULGARIA', 'Bulgaria', 'specific',
                    resort('BULGARIA', 'SUNNY BEACH', 'Sunny Beach', 206, { checked: true })
                    + resort('BULGARIA', 'BANSKO', 'Bansko', 138)
                    + resort('BULGARIA', 'KITEN', 'Kiten', 6, { isNew: true })
                    + resort('BULGARIA', 'HISTORICAL PARK', 'Historical Park', 1, { checked: true, gone: true }), 0)}
                ${country('ALBANIA', 'Albania', 'all', resort('ALBANIA', 'DURRES', 'Durres', 17), 1)}
                ${country('GREECE', 'Greece', 'off', resort('GREECE', 'THASSOS', 'Thassos', 61), 2)}
                <div id="dest-no-results" hidden></div>
            </form>
            <aside>
                <strong data-dest-total="countries"></strong>
                <strong data-dest-total="resorts"></strong>
                <strong data-dest-total="hotels"></strong>
                <div id="dest-summary-list"></div>
                <div id="dest-pending"></div>
                <button type="button" id="dest-undo">Undo</button>
                <button type="button" id="dest-outside-open" aria-expanded="false">Disable…</button>
                <form id="dest-outside-confirm" hidden><button type="button" id="dest-outside-cancel">Cancel</button></form>
            </aside>
        </div>`;

    await import('../../addon-novoton-holidays/js/addons/novoton_holidays/destinations.js');
});

const $ = (sel) => document.querySelector(sel);
const section = (code) => $(`.novoton-dest-country[data-country="${code}"]`);
const radio = (code, mode) => $(`input[name="destinations[${code}][mode]"][value="${mode}"]`);
const cb = (name) => $(`input[value="${name}"]`);
const total = (key) => $(`[data-dest-total="${key}"]`).textContent;
const type = (value) => {
    const s = $('#dest-search');
    s.value = value;
    s.dispatchEvent(new Event('input', { bubbles: true }));
};
const choose = (code, mode) => {
    const r = radio(code, mode);
    r.checked = true;
    r.dispatchEvent(new Event('change', { bubbles: true }));
};
const leave = () => {
    const e = new Event('beforeunload', { cancelable: true });
    window.dispatchEvent(e);
    return e.defaultPrevented;
};

describe('what is sold', () => {
    it('badges and totals follow each country\'s mode; gone resorts are not sold', () => {
        expect(section('BULGARIA').querySelector('[data-badge]').textContent).toBe('1 of 3');
        expect(section('ALBANIA').querySelector('[data-badge]').textContent).toBe('ALL');
        expect(section('GREECE').querySelector('[data-badge]').textContent).toBe('NOT SOLD');
        expect(total('countries')).toBe('2');
        expect(total('resorts')).toBe('2');
        expect(total('hotels')).toBe(String(206 + 17));
        expect($('#dest-pending').textContent).toBe('All changes saved');
        expect(leave()).toBe(false);
    });

    it('All resorts -> Only selected keeps selling everything: every resort ticked', () => {
        choose('ALBANIA', 'specific');
        expect(cb('DURRES').checked).toBe(true);
        expect(section('ALBANIA').querySelector('[data-badge]').textContent).toBe('1 of 1');
        expect($('#dest-pending').textContent).toBe('1 changes not saved');
        expect(leave()).toBe(true);
        $('#dest-undo').click();
        expect(radio('ALBANIA', 'all').checked).toBe(true);
        expect($('#dest-pending').textContent).toBe('All changes saved');
    });

    it('"Select shown" ticks only what the search shows', () => {
        type('ban');
        section('BULGARIA').querySelector('[data-select-shown]').click();
        expect(cb('BANSKO').checked).toBe(true);
        expect(cb('KITEN').checked).toBe(false);
        expect($('.novoton-dest-resort[data-name="Bansko"] .novoton-dest-resort__dot').hidden).toBe(false);
        type('');
        $('#dest-undo').click();
        expect(cb('BANSKO').checked).toBe(false);
    });
});

describe('search, as on the Sphinx whitelist', () => {
    it('lists matching resorts under the box, grouped by country, the match in bold', () => {
        type('th');
        const results = $('#dest-search-results');
        expect(results.hidden).toBe(false);
        expect(results.textContent).toContain('Greece');
        expect(results.innerHTML).toContain('<strong>Th</strong>assos');
        expect(results.textContent).toContain('not sold');
        expect(section('BULGARIA').hidden).toBe(true);
        expect(section('GREECE').hidden).toBe(false);
    });

    it('picking a resort in a country not sold switches it to "Only selected" and ticks it', () => {
        $('#dest-search-results .novoton-dest__hit[data-hit-resort]').click();
        expect(radio('GREECE', 'specific').checked).toBe(true);
        expect(cb('THASSOS').checked).toBe(true);
        expect(section('GREECE').querySelector('.novoton-dest-country__body').hidden).toBe(false);
        expect($('#dest-search').value).toBe('');
        expect($('#dest-search-results').hidden).toBe(true);
        $('#dest-undo').click();
        expect(radio('GREECE', 'off').checked).toBe(true);
    });

    it('Enter picks the first hit and never submits the form', () => {
        type('kit');
        const e = new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true });
        $('#dest-search').dispatchEvent(e);
        expect(e.defaultPrevented).toBe(true);
        expect($('#dest-search-results').hidden).toBe(true);
        $('#dest-undo').click();
    });

    it('says when nothing matches', () => {
        type('zzz');
        expect($('#dest-search-results').textContent).toBe('No match');
        expect($('#dest-no-results').hidden).toBe(false);
        type('');
        expect($('#dest-no-results').hidden).toBe(true);
    });
});

describe('filters', () => {
    it('"Show sold countries only" hides the countries not sold', () => {
        const only = $('#dest-only-sold');
        only.checked = true;
        only.dispatchEvent(new Event('change', { bubbles: true }));
        expect(section('GREECE').hidden).toBe(true);
        expect(section('BULGARIA').hidden).toBe(false);
        only.checked = false;
        only.dispatchEvent(new Event('change', { bubbles: true }));
    });

    it('"Show new resorts" shows only the new ones, opened', () => {
        $('#dest-show-new').click();
        expect(section('BULGARIA').hidden).toBe(false);
        expect(section('ALBANIA').hidden).toBe(true);
        expect($('.novoton-dest-resort[data-name="Kiten"]').hidden).toBe(false);
        expect($('.novoton-dest-resort[data-name="Bansko"]').hidden).toBe(true);
        $('#dest-show-new').click();
        expect(section('ALBANIA').hidden).toBe(false);
    });
});

describe('disabling products outside the whitelist', () => {
    it('asks on the page first, and Cancel puts it away', () => {
        $('#dest-outside-open').click();
        expect($('#dest-outside-confirm').hidden).toBe(false);
        expect($('#dest-outside-open').hidden).toBe(true);
        $('#dest-outside-cancel').click();
        expect($('#dest-outside-confirm').hidden).toBe(true);
        expect($('#dest-outside-open').hidden).toBe(false);
    });
});
