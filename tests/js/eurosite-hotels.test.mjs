import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * Behavioral tests for the Eurosite → Hotels selection bar
 * (addon-eurosite/js/addons/eurosite/hotels.js), driven through the markup
 * hotels.tpl renders.
 *
 * What must hold: the bar counts what is ticked and how many of those can
 * become products (the row's data-eligible, decided server-side); "Create
 * products (N)" is enabled only for N > 0 and asks first; "Check availability
 * now" asks first, and says so when nothing is ticked.
 */

function row(key, eligible) {
    return `<tr class="es-row"><td><input type="checkbox" name="hotel_keys[]" value="${key}" class="es-row-check" data-eligible="${eligible ? 1 : 0}" /></td></tr>`;
}

beforeAll(async () => {
    document.body.innerHTML = `
        <div class="es-hotels" id="es-hotels"
             data-txt-confirm-create="Create [n] products?"
             data-txt-confirm-check="Check the selected?"
             data-txt-confirm-check-all="Check everything?">
            <form method="post" id="es-hotels-form">
                <div id="es-selection">
                    <strong id="es-sel-count">0</strong> <strong id="es-sel-ok">0</strong>
                    <span id="es-sel-skip-wrap" hidden><strong id="es-sel-skip">0</strong></span>
                    <button type="button" id="es-select-eligible">eligible</button>
                    <button type="button" id="es-select-clear">clear</button>
                    <button type="submit" name="dispatch[eurosite.check_availability]" id="es-check-btn">check</button>
                    <button type="submit" name="dispatch[eurosite.create_products]" id="es-create-btn" disabled>create (<span id="es-create-n">0</span>)</button>
                </div>
                <table><thead><tr><th><input type="checkbox" id="es-check-page" /></th></tr></thead>
                <tbody>${row('LA:RO0363', true)}${row('LA:RO0435', false)}${row('LA:RO0490', true)}</tbody></table>
            </form>
        </div>`;
    // jsdom cannot navigate: stop the real submit a confirmed click would make.
    document.getElementById('es-hotels-form').addEventListener('submit', (e) => e.preventDefault());
    await import('../../addon-eurosite/js/addons/eurosite/hotels.js');
});

const $ = (sel) => document.querySelector(sel);
const boxes = () => Array.from(document.querySelectorAll('.es-row-check'));
const tick = (i, on = true) => {
    const b = boxes()[i];
    b.checked = on;
    b.dispatchEvent(new Event('change', { bubbles: true }));
};
const text = (id) => $('#' + id).textContent;

beforeEach(() => {
    boxes().forEach((b) => { b.checked = false; });
    $('#es-select-clear').click();
});

describe('the selection bar', () => {
    it('starts with nothing selected and Create disabled', () => {
        expect(text('es-sel-count')).toBe('0');
        expect(text('es-create-n')).toBe('0');
        expect($('#es-create-btn').disabled).toBe(true);
    });

    it('counts ticked hotels and those that can become products', () => {
        tick(0);
        tick(1);

        expect(text('es-sel-count')).toBe('2');
        expect(text('es-sel-ok')).toBe('1');
        expect(text('es-sel-skip')).toBe('1');
        expect($('#es-sel-skip-wrap').hidden).toBe(false);
        expect(text('es-create-n')).toBe('1');
        expect($('#es-create-btn').disabled).toBe(false);
        expect(boxes()[0].closest('tr').classList.contains('is-selected')).toBe(true);
    });

    it('selecting only ineligible hotels keeps Create disabled', () => {
        tick(1);

        expect(text('es-sel-ok')).toBe('0');
        expect($('#es-create-btn').disabled).toBe(true);
    });

    it('"Select all that can become products" ticks exactly those', () => {
        tick(1);
        $('#es-select-eligible').click();

        expect(boxes().map((b) => b.checked)).toEqual([true, false, true]);
        expect(text('es-create-n')).toBe('2');
        expect($('#es-sel-skip-wrap').hidden).toBe(true);
    });

    it('the header checkbox selects the page, and reflects a partial selection', () => {
        const page = $('#es-check-page');
        page.checked = true;
        page.dispatchEvent(new Event('change', { bubbles: true }));
        expect(boxes().every((b) => b.checked)).toBe(true);

        tick(2, false);
        expect(page.checked).toBe(false);
        expect(page.indeterminate).toBe(true);
    });
});

describe('the two actions ask first', () => {
    it('Create products says how many and stops on Cancel', () => {
        tick(0);
        tick(2);
        const ask = vi.spyOn(window, 'confirm').mockReturnValue(false);
        const ev = new MouseEvent('click', { bubbles: true, cancelable: true });

        $('#es-create-btn').dispatchEvent(ev);

        expect(ask).toHaveBeenCalledWith('Create 2 products?');
        expect(ev.defaultPrevented).toBe(true);
        ask.mockRestore();
    });

    it('Create products goes ahead on OK', () => {
        tick(0);
        const ask = vi.spyOn(window, 'confirm').mockReturnValue(true);
        const ev = new MouseEvent('click', { bubbles: true, cancelable: true });

        $('#es-create-btn').dispatchEvent(ev);

        expect(ev.defaultPrevented).toBe(false);
        ask.mockRestore();
    });

    it('Check availability says when it will check everything', () => {
        const ask = vi.spyOn(window, 'confirm').mockReturnValue(false);

        $('#es-check-btn').dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
        expect(ask).toHaveBeenLastCalledWith('Check everything?');

        tick(1);
        $('#es-check-btn').dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
        expect(ask).toHaveBeenLastCalledWith('Check the selected?');
        ask.mockRestore();
    });
});
