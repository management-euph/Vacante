import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * Behavioral tests for the Design A page with several rooms
 * (addon-travel-core/js/addons/travel_core/pdp-design-a-rooms.js, the owner's
 * boards 13c): rooms grouped by their guests, one tab per group; a till
 * counts the group's rooms (at most the rooms left of the type, shared
 * between the groups) and chooses them; notes say what was added, offer the
 * closest type when one runs out and undo; the bar books every room with
 * the entries the supplier's booking of several rooms reads.
 */

const TEXTS = {
    'rooms-one': '[n] cameră',
    'rooms-few': '[n] camere',
    'rooms-many': '[n] de camere',
    room: 'Camera [n]',
    numbers: 'Camerele [numbers]',
    'state-todo-one': 'Nealeasă',
    'state-todo-many': 'Nealese',
    'state-now': 'Alegi acum',
    'state-part': '[chosen] din [size] alese · mai alegi [left]',
    'state-done-one': 'Aleasă,',
    'state-done-many': 'Alese,',
    'choose-room': 'Alege pentru Camera [n]',
    'choose-rooms': 'Alege pentru [rooms]',
    chosen: 'Aleasă',
    'chosen-rooms': 'Aleasă · [rooms]',
    'total-rooms': 'Total · [rooms] · [nights]',
    cap: 'Ajung pentru [n] din cele [size] camere',
    'sold-out': 'Nu mai sunt camere de acest tip',
    done: 'Am ales [what] pentru [rooms]: +[amount].',
    'done-left': 'Mai alegi [left] pentru câte [guests].',
    undo: 'Anulează',
    'undo-aria': 'Anulează alegerea pentru [rooms]',
    'all-done': 'Toate cele [n] camere sunt alese.',
    'suggest-lead': 'Pentru celelalte [rooms], cea mai apropiată:',
    suggest: '[room] · [meal], [price] de cameră ([diff] față de [was]).',
    'suggest-btn': 'Alege pentru [rooms] ([n] × [price] = [total])',
    'suggest-all': 'Văd toate tipurile',
    'bar-progress': 'Camere alese: [n] din [total]',
    'bar-pick': '[rooms]: [room] · [meal]',
    change: 'Schimbă',
    'change-aria': 'Schimbă alegerea pentru [rooms]',
    'so-far': 'Total până acum',
    total: 'Total',
    goto: 'Mergi la [rooms]',
    book: 'Rezervă [rooms] · [total]',
    'book-aria': 'Rezervă [rooms], total [total] pentru [nights]',
    'book-mixed': 'Rezervă [booked] · cere [requested]',
    'book-mixed-aria': 'Rezervă acum [booked] și cere la hotel [requested]; total [total]',
    'request-part': 'din care [amount] la cerere',
    'reply-for': 'Răspuns în max. 48 h pentru [rooms]'
};

function attr(value) {
    return String(value).replace(/&/g, '&amp;').replace(/"/g, '&quot;');
}

/** One option's till of a group's room card, as design_a_till.tpl prints it. */
function till(group, option) {
    const counter = group.size > 1
        ? `<div class="travel-pdpa-qty"><div class="travel-pdpa-qty__c">
               <button type="button" data-travel-qty-step="-1">-</button>
               <span data-travel-qty>${option.qty}</span>
               <button type="button" data-travel-qty-step="1">+</button></div></div>`
        : '';

    return `<div class="travel-pdpa-till travel-pdpa-till--group" data-travel-for="${option.index}"
                 data-unit="${option.unit}" data-unit-was="${option.unitWas || 0}" data-meal-name="${option.meal}" data-meal-key="${option.mealKey}"
                 data-on-request="${option.onRequest ? 1 : 0}" data-room-json="${attr(JSON.stringify(option.json))}"${option.index === 0 ? '' : ' hidden'}>
        ${counter}
        <p class="travel-pdpa-till__sum">${group.size > 1 ? '<span data-travel-till-label></span>' : ''}
            <span data-travel-till-was>${option.unitWas ? '<s></s>' : ''}</span><b data-travel-till-total></b></p>
        <p data-travel-cap hidden></p>
        <div class="travel-pdpa-cta"><button type="button" data-travel-choose aria-pressed="false">Alege</button></div>
    </div>`;
}

function card(group, room) {
    const radios = room.options.map((o) => `<input type="radio" data-travel-pick name="${group.key}-${room.key}" value="${o.index}"${o.index === 0 ? ' checked' : ''}>`).join('');

    return `<li class="travel-pdpa-rcard" id="travel-pdpa-${group.key}-${room.key}" data-travel-room data-quota="${room.quota}" data-left="${room.left}" data-title="${room.title}">
        ${radios}${room.options.map((o) => till(group, o)).join('')}
    </li>`;
}

function admiralOptions(per, opts = {}) {
    return [
        { index: 0, unit: per.bb, meal: 'Mic dejun', mealKey: 'mbb', json: { room_id: opts.code, board_id: 'BB', price: per.bb } },
        { index: 1, unit: per.uai, unitWas: per.was, meal: 'Ultra All Inclusive', mealKey: 'muai', onRequest: !!opts.onRequest,
            json: { room_id: opts.code, board_id: 'UAI', price: per.uai, package_name: 'ADMIRAL ***** ULTRA ALL INCL' } }
    ];
}

/** 8 rooms of 2 adults and 2 rooms of 2 adults and a child of 7: Admiral's Deluxe and sea-view doubles. */
function page({ deluxeLeft = 10, seaLeft = 2, kidsOnRequest = false } = {}) {
    const g1 = { key: 'g1', size: 8, rooms: [1, 2, 3, 4, 5, 6, 7, 8], adults: 2, ages: '', guests: '2 adulți' };
    const g2 = { key: 'g2', size: 2, rooms: [9, 10], adults: 2, ages: '7', guests: '2 adulți + 1 copil (7 ani)' };
    const deluxe = (g, per, opts = {}) => ({ key: 'dlx', quota: 'q-dlx', left: deluxeLeft, title: 'Cameră Dublă Deluxe',
        options: admiralOptions(per, { code: 'DBL 2+1 DELUXE', ...opts }).map((o) => ({ ...o, qty: Math.min(g.size, deluxeLeft) })) });
    const sea = (g, per) => ({ key: 'sea', quota: 'q-sea', left: seaLeft, title: 'Cameră Dublă Deluxe, spre mare',
        options: admiralOptions(per, { code: 'DBL 2+1 DELUXE SEA SIDE' }).map((o) => ({ ...o, qty: Math.min(g.size, seaLeft) })) });
    const groups = [
        [g1, [deluxe(g1, { bb: 675, uai: 905, was: 1028 }), sea(g1, { bb: 735, uai: 965, was: 1097 })]],
        [g2, [deluxe(g2, { bb: 765, uai: 1030, was: 1170 }, { onRequest: kidsOnRequest }), sea(g2, { bb: 830, uai: 1095, was: 1245 })]]
    ];
    const texts = Object.entries(TEXTS).map(([k, v]) => `data-t-${k}="${attr(v)}"`).join(' ');
    const tabs = groups.map(([g], i) => `<button type="button" role="tab" data-travel-gtab="${g.key}" aria-selected="${i === 0}"><span data-travel-gtab-state></span></button>`).join('');
    const panels = groups.map(([g, rooms], i) => `
        <div class="travel-pdpa-gpanel" role="tabpanel" data-travel-group="${g.key}" data-size="${g.size}" data-rooms="${g.rooms.join(',')}"
             data-adults="${g.adults}" data-ages="${g.ages}" data-guests="${g.guests}"${i === 0 ? '' : ' hidden'}>
            <div data-travel-gnotes></div>
            <section data-travel-results><ul data-travel-room-list>${rooms.map((r) => card(g, r)).join('')}</ul></section>
        </div>`).join('');

    document.body.innerHTML = `
        <div class="travel-pdpa">
            <section class="travel-pdpa-multi" data-travel-multi data-rooms="10" data-nights="6 nopți"
                     data-money-symbol="€" data-money-after="1" data-money-decimals="0" data-money-decimal="," data-money-thousands="." ${texts}>
                <div role="tablist">${tabs}</div>
                ${panels}
                <template data-travel-icon-check><svg></svg></template>
                <template data-travel-icon-ok><svg></svg></template>
                <div data-travel-bar hidden>
                    <p data-travel-bar-progress></p><span data-travel-bar-meter></span>
                    <div data-travel-bar-picks></div>
                    <p data-travel-bar-total></p>
                    <a href="#" data-travel-bar-goto hidden></a>
                    <div data-travel-bar-cta hidden>
                        <button type="button" data-travel-book></button>
                        <p data-travel-bar-note>Nu plătești încă</p>
                        <p data-travel-bar-note-request hidden><span data-travel-bar-reply></span></p>
                    </div>
                </div>
                <form method="get" action="/" data-travel-book-form hidden>
                    <input type="hidden" name="dispatch" value="novoton_booking.booking_form">
                    <input type="hidden" name="rooms_data" value="" data-travel-book-rooms>
                    <input type="hidden" name="total_price" value="" data-travel-book-total>
                </form>
            </section>
        </div>`;
    window.TravelPdpDesignARooms.init();
}

const $ = (selector) => document.querySelector(selector);
const click = (node) => node.dispatchEvent(new MouseEvent('click', { bubbles: true }));
const tab = (key) => $(`[data-travel-gtab="${key}"]`);
const panel = (key) => $(`[data-travel-group="${key}"]`);
const tillOf = (key, room, option = 0) => $(`#travel-pdpa-${key}-${room} [data-travel-for="${option}"]`);
const pickMeal = (key, room, option) => {
    const radio = $(`#travel-pdpa-${key}-${room} [data-travel-pick][value="${option}"]`);
    radio.checked = true;
    // pdp-design-a.js shows the radio's till; done here by hand
    document.querySelectorAll(`#travel-pdpa-${key}-${room} [data-travel-for]`).forEach((t) => { t.hidden = t.getAttribute('data-travel-for') !== String(option); });
    radio.dispatchEvent(new Event('change', { bubbles: true }));
};
const text = (node) => node.textContent.replace(/\s+/g, ' ').trim();

let submitted = null;

beforeAll(async () => {
    HTMLFormElement.prototype.submit = vi.fn(function () {
        submitted = this;
    });
    await import('../../addon-travel-core/js/addons/travel_core/pdp-design-a-rooms.js');
});

beforeEach(() => {
    submitted = null;
});

describe('helpers', () => {
    it('reads Romanian plural forms, fills lang vars and formats money as the server does', () => {
        const rooms = window.TravelPdpDesignARooms;
        expect([1, 2, 19, 20, 101, 120].map(rooms.form)).toEqual(['one', 'few', 'few', 'many', 'few', 'many']);
        expect(rooms.fill('Alege pentru [rooms]', { rooms: '8 camere' })).toBe('Alege pentru 8 camere');
        expect(rooms.formatMoney(10660, { symbol: '€', after: true, decimals: 0, decimal: ',', thousands: '.' })).toBe('10.660 €');
        expect(rooms.formatMoney(481.99, { symbol: '€', after: true, decimals: 2, decimal: ',', thousands: '.' })).toBe('481,99 €');
        expect(rooms.formatMoney(1798, { symbol: '$', after: false, decimals: 2, decimal: '.', thousands: ',' })).toBe('$1,798.00');
        expect(rooms.numbers([1, 2, 3, 4, 5, 6, 7, 8])).toBe('1–8');
        expect(rooms.numbers([1, 3, 4])).toBe('1, 3–4');
    });
});

describe('a group of eight rooms', () => {
    beforeEach(() => page());

    it('opens on the first group and counts its rooms in every till', () => {
        expect(text(tab('g1'))).toBe('Alegi acum');
        expect(text(tab('g2'))).toBe('Nealese');
        expect(panel('g2').hidden).toBe(true);
        const deluxe = tillOf('g1', 'dlx');
        expect(text(deluxe.querySelector('[data-travel-qty]'))).toBe('8');
        expect(text(deluxe.querySelector('[data-travel-till-label]'))).toBe('Total · 8 camere · 6 nopți');
        expect(text(deluxe.querySelector('[data-travel-till-total]'))).toBe('5.400 €');
        expect(text(deluxe.querySelector('[data-travel-choose]'))).toBe('Alege pentru 8 camere');
        // two sea-view rooms left: the till says how many of the 8 it covers
        const sea = tillOf('g1', 'sea');
        expect(text(sea.querySelector('[data-travel-qty]'))).toBe('2');
        expect(text(sea.querySelector('[data-travel-cap]'))).toBe('Ajung pentru 2 din cele 8 camere');
    });

    it('chooses the whole group in one click, says what it added and opens the next group', () => {
        pickMeal('g1', 'dlx', 1);
        click(tillOf('g1', 'dlx', 1).querySelector('[data-travel-choose]'));

        expect(text(tab('g1'))).toBe('Alese, 7.240 €');
        expect(tab('g2').getAttribute('aria-selected')).toBe('true');
        expect(panel('g2').hidden).toBe(false);
        expect(text(panel('g2').querySelector('[data-travel-gnotes] [role="status"]'))).toBe(
            'Am ales Cameră Dublă Deluxe · Ultra All Inclusive pentru Camerele 1–8: +7.240 €.'
        );
        expect(panel('g2').querySelector('[data-travel-undo]').getAttribute('aria-label')).toBe('Anulează alegerea pentru Camerele 1–8');
        expect(text($('[data-travel-bar-progress]'))).toBe('Camere alese: 8 din 10');
        expect(text($('[data-travel-bar-picks] .travel-pdpa-bbar__pick > span'))).toBe('Camerele 1–8: Cameră Dublă Deluxe · Ultra All Inclusive · 8 × 905 €');
        expect($('[data-travel-bar-picks] [data-travel-change]').getAttribute('aria-label')).toBe('Schimbă alegerea pentru Camerele 1–8');
        expect(text($('[data-travel-bar-total]'))).toBe('Total până acum 7.240 €');
        expect($('[data-travel-bar-goto]').hidden).toBe(true);
        // the other group shares the Deluxe rooms: 10 − 8 = 2 left for it
        expect(text(tillOf('g2', 'dlx').querySelector('[data-travel-qty]'))).toBe('2');

        click(panel('g2').querySelector('[data-travel-undo]'));
        expect(text(tab('g1'))).toBe('Alegi acum');
        expect(tab('g1').getAttribute('aria-selected')).toBe('true');
        expect($('[data-travel-bar]').hidden).toBe(true);
    });

    it('a pressed choice lets go when pressed again', () => {
        const button = tillOf('g1', 'dlx').querySelector('[data-travel-choose]');
        click(button);
        click(tab('g1'));
        expect(button.getAttribute('aria-pressed')).toBe('true');
        expect(text(button)).toBe('Aleasă · 8 camere');
        click(button);
        expect(button.getAttribute('aria-pressed')).toBe('false');
        expect($('[data-travel-bar]').hidden).toBe(true);
    });

    it('the counter stays within the group and the rooms left', () => {
        const deluxe = tillOf('g1', 'dlx');
        const more = deluxe.querySelector('[data-travel-qty-step="1"]');
        const less = deluxe.querySelector('[data-travel-qty-step="-1"]');
        expect(more.disabled).toBe(true);
        click(less);
        click(less);
        expect(text(deluxe.querySelector('[data-travel-qty]'))).toBe('6');
        expect(text(deluxe.querySelector('[data-travel-choose]'))).toBe('Alege pentru 6 camere');
        expect(text(deluxe.querySelector('[data-travel-till-total]'))).toBe('4.050 €');
    });
});

describe('a type that runs short', () => {
    beforeEach(() => page({ deluxeLeft: 6 }));

    it('chooses what is left, shows the group in part and offers the closest type for the rest', () => {
        expect(text(tillOf('g1', 'dlx').querySelector('[data-travel-cap]'))).toBe('Ajung pentru 6 din cele 8 camere');
        pickMeal('g1', 'dlx', 1);
        click(tillOf('g1', 'dlx', 1).querySelector('[data-travel-choose]'));

        expect(tab('g1').getAttribute('aria-selected')).toBe('true');
        expect(text(tab('g1'))).toBe('6 din 8 alese · mai alegi 2 camere');
        const notes = text(panel('g1').querySelector('[data-travel-gnotes]'));
        expect(notes).toContain('Am ales Cameră Dublă Deluxe · Ultra All Inclusive pentru Camerele 1–6: +5.430 €. Mai alegi 2 camere pentru câte 2 adulți.');
        expect(notes).toContain('Pentru celelalte 2 camere, cea mai apropiată: Cameră Dublă Deluxe, spre mare · Ultra All Inclusive, 965 € de cameră (+60 € față de Cameră Dublă Deluxe).');
        expect(notes).toContain('Alege pentru 2 camere (2 × 965 € = 1.930 €)');
        // the Deluxe card has nothing left for this group
        expect(text(tillOf('g1', 'dlx', 0).querySelector('[data-travel-cap]'))).toBe('Nu mai sunt camere de acest tip');
        expect(tillOf('g1', 'dlx', 0).querySelector('[data-travel-choose]').disabled).toBe(true);

        click(panel('g1').querySelector('[data-travel-suggest]'));
        expect(text(tab('g1'))).toBe('Alese, 7.360 €');
        expect(tab('g2').getAttribute('aria-selected')).toBe('true');
        expect(text($('[data-travel-bar-picks]'))).toContain('Camerele 7–8: Cameră Dublă Deluxe, spre mare · Ultra All Inclusive · 2 × 965 €');
    });
});

describe('booking every room', () => {
    it('posts each room with its guests, in room order, and the total', () => {
        page();
        pickMeal('g1', 'dlx', 1);
        click(tillOf('g1', 'dlx', 1).querySelector('[data-travel-choose]'));
        click(tillOf('g2', 'dlx', 0).querySelector('[data-travel-choose]'));

        expect(text($('[data-travel-book]'))).toBe('Rezervă 10 camere · 8.770 €');
        expect($('[data-travel-bar-cta]').hidden).toBe(false);
        click($('[data-travel-book]'));

        expect(submitted).not.toBeNull();
        const rooms = JSON.parse(submitted.querySelector('[data-travel-book-rooms]').value);
        expect(rooms).toHaveLength(10);
        expect(rooms.map((r) => r.room_num)).toEqual([1, 2, 3, 4, 5, 6, 7, 8, 9, 10]);
        expect(rooms[0]).toEqual({ room_id: 'DBL 2+1 DELUXE', board_id: 'UAI', price: 905, package_name: 'ADMIRAL ***** ULTRA ALL INCL', room_num: 1, adults: 2, children: 0, childrenAges: [] });
        expect(rooms[9]).toEqual({ room_id: 'DBL 2+1 DELUXE', board_id: 'BB', price: 765, room_num: 10, adults: 2, children: 1, childrenAges: [7] });
        expect(submitted.querySelector('[data-travel-book-total]').value).toBe('8770.00');
    });

    it('a room on request is requested apart: the button says both, the total what is paid now', () => {
        page({ kidsOnRequest: true });
        click(tillOf('g1', 'dlx', 0).querySelector('[data-travel-choose]'));
        pickMeal('g2', 'dlx', 1);
        click(tillOf('g2', 'dlx', 1).querySelector('[data-travel-choose]'));

        expect(text($('[data-travel-book]'))).toBe('Rezervă 8 camere · cere 2 camere');
        // The total of every room, and the share on request: paid by card now (refunded if
        // not confirmed) or by bank transfer once confirmed, as the bar's steps say
        expect(text($('[data-travel-bar-total]'))).toBe('Total 7.460 €din care 2.060 € la cerere');
        expect($('[data-travel-bar-note]').hidden).toBe(true);
        expect($('[data-travel-bar-note-request]').hidden).toBe(false);
        expect(text($('[data-travel-bar-reply]'))).toBe('Răspuns în max. 48 h pentru Camerele 9–10');
    });

    it('the tabs move with the arrow keys', () => {
        page();
        tab('g1').dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true }));
        expect(tab('g2').getAttribute('aria-selected')).toBe('true');
        expect(document.activeElement).toBe(tab('g2'));
        tab('g2').dispatchEvent(new KeyboardEvent('keydown', { key: 'Home', bubbles: true }));
        expect(tab('g1').getAttribute('aria-selected')).toBe('true');
    });
});
