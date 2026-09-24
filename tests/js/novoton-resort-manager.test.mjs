import { beforeAll, describe, expect, it } from 'vitest';

/**
 * Behavioral tests for the dashboard's excluded-resorts list
 * (addon-novoton-holidays/js/addons/novoton_holidays/resort-manager.js).
 *
 * The list must say what the unsaved ticks affect before anyone saves:
 * excluding a resort stops its hotels from becoming products, so "Albena +
 * Varna" reads as 50 hotels, 3 of them already products.
 */

function resort(country, name, label, hotels, products, excluded) {
    return `
        <label class="novoton-resort${excluded ? ' is-excluded' : ''}" data-resort="${label.toLowerCase()} ${name.toLowerCase()}"
               data-country="${country}" data-name="${label}" data-hotels="${hotels}" data-products="${products}">
            <input type="checkbox" name="excluded_resorts[]" value="${name}"${excluded ? ' checked' : ''}>
            <span class="novoton-resort__text"><span class="novoton-resort__name">${label}<span class="novoton-resort__dot" hidden></span></span></span>
        </label>`;
}

beforeAll(async () => {
    document.body.innerHTML = `
        <form id="excluded-resorts-form" method="post"
              data-txt-pending="[changes] not saved: [add] to exclude, [remove] to include again · affects [hotels] hotels ([products] already products; they stay)"
              data-txt-saved="All changes saved">
            <input type="search" id="resort-search">
            <button type="button" data-resort-country="" aria-pressed="true">All</button>
            <button type="button" data-resort-country="BULGARIA" aria-pressed="false">Bulgaria</button>
            <button type="button" data-resort-country="ALBANIA" aria-pressed="false">Albania</button>
            <button type="button" id="resort-only-excluded" aria-pressed="false">Excluded only <span id="resort-excluded-count">1</span></button>
            <button type="button" data-resort-sort="name" aria-pressed="true">A–Z</button>
            <button type="button" data-resort-sort="hotels" aria-pressed="false">Most hotels</button>
            <span id="resort-visible-count"></span>

            <div class="novoton-resorts__group" data-country="BULGARIA">
                <span data-group-excluded>1</span>
                <button type="button" data-exclude-shown="BULGARIA">Exclude all shown</button>
                <button type="button" data-include-shown="BULGARIA">Include all shown</button>
                <div class="novoton-resorts__grid">
                    ${resort('BULGARIA', 'ALBENA', 'Albena', 38, 0, false)}
                    ${resort('BULGARIA', 'BANSKO', 'Bansko', 138, 8, true)}
                    ${resort('BULGARIA', 'SUNNY BEACH', 'Sunny Beach', 205, 60, false)}
                    ${resort('BULGARIA', 'VARNA', 'Varna', 12, 3, false)}
                </div>
            </div>
            <div class="novoton-resorts__group" data-country="ALBANIA">
                <span data-group-excluded>0</span>
                <div class="novoton-resorts__grid">
                    ${resort('ALBANIA', 'DURRES', 'Durres', 8, 3, false)}
                </div>
            </div>
            <div id="resort-no-results" hidden>No match</div>
            <span id="resort-pending"></span>
            <button type="button" id="resort-undo">Undo</button>
        </form>`;

    await import('../../addon-novoton-holidays/js/addons/novoton_holidays/resort-manager.js');
});

const $ = (sel) => document.querySelector(sel);
const item = (name) => $(`input[value="${name}"]`).closest('.novoton-resort');
const tick = (name) => {
    const cb = $(`input[value="${name}"]`);
    cb.checked = !cb.checked;
    cb.dispatchEvent(new Event('change', { bubbles: true }));
};
const visible = () => Array.from(document.querySelectorAll('.novoton-resort')).filter((i) => !i.hidden).map((i) => i.getAttribute('data-name'));
const type = (q) => {
    $('#resort-search').value = q;
    $('#resort-search').dispatchEvent(new Event('input', { bubbles: true }));
};

describe('unsaved changes', () => {
    it('start saved', () => {
        expect($('#resort-pending').textContent).toBe('All changes saved');
        expect(item('BANSKO').querySelector('.novoton-resort__dot').hidden).toBe(true);
    });

    it('say what the ticks affect: hotels, and how many are already products', () => {
        tick('ALBENA');
        tick('VARNA');
        expect($('#resort-pending').textContent).toBe('2 not saved: 2 to exclude, 0 to include again · affects 50 hotels (3 already products; they stay)');
        expect(item('VARNA').classList.contains('is-excluded')).toBe(true);
        expect(item('VARNA').querySelector('.novoton-resort__dot').hidden).toBe(false);
        expect($('#resort-excluded-count').textContent).toBe('3');
        expect(document.querySelector('[data-country="BULGARIA"] [data-group-excluded]').textContent).toBe('3');
    });

    it('count an un-tick of a saved exclusion as "include again", affecting no hotels', () => {
        tick('BANSKO');
        expect($('#resort-pending').textContent).toBe('3 not saved: 2 to exclude, 1 to include again · affects 50 hotels (3 already products; they stay)');
    });

    it('Undo puts every box back as saved', () => {
        $('#resort-undo').click();
        expect($('input[value="ALBENA"]').checked).toBe(false);
        expect($('input[value="BANSKO"]').checked).toBe(true);
        expect($('#resort-pending').textContent).toBe('All changes saved');
    });
});

describe('filters', () => {
    it('search matches the shown name and the stored one', () => {
        type('sunny');
        expect(visible()).toEqual(['Sunny Beach']);
        expect($('[data-country="ALBANIA"]').hidden).toBe(true);
        type('');
    });

    it('a country filter shows only that country', () => {
        $('[data-resort-country="ALBANIA"]').click();
        expect(visible()).toEqual(['Durres']);
        expect($('[data-resort-country="ALBANIA"]').getAttribute('aria-pressed')).toBe('true');
        $('[data-resort-country=""]').click();
        expect(visible()).toHaveLength(5);
    });

    it('"excluded only" shows the ticked resorts, and says when nothing matches', () => {
        $('#resort-only-excluded').click();
        expect(visible()).toEqual(['Bansko']);
        type('durres');
        expect($('#resort-no-results').hidden).toBe(false);
        type('');
        $('#resort-only-excluded').click();
        expect($('#resort-no-results').hidden).toBe(true);
    });

    it('"Exclude all shown" ticks only what the filters show', () => {
        type('a');                       // Albena, Bansko, Sunny Beach, Varna
        $('[data-resort-country="BULGARIA"]').click();
        type('an');                      // Bansko only
        $('[data-exclude-shown="BULGARIA"]').click();
        expect($('input[value="ALBENA"]').checked).toBe(false);
        type('');
        $('[data-resort-country=""]').click();
        $('[data-include-shown="BULGARIA"]').click();
        expect(Array.from(document.querySelectorAll('[data-country="BULGARIA"] input')).some((c) => c.checked)).toBe(false);
        $('#resort-undo').click();
    });
});

describe('sorting', () => {
    const order = () => Array.from(document.querySelectorAll('[data-country="BULGARIA"] .novoton-resort')).map((i) => i.getAttribute('data-name'));

    it('puts the biggest resorts first, then back to A–Z', () => {
        $('[data-resort-sort="hotels"]').click();
        expect(order()).toEqual(['Sunny Beach', 'Bansko', 'Albena', 'Varna']);
        $('[data-resort-sort="name"]').click();
        expect(order()).toEqual(['Albena', 'Bansko', 'Sunny Beach', 'Varna']);
    });
});
