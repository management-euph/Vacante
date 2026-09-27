import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * Black-box tests for the multi-room selection module
 * (addon-travel-core/js/addons/travel_core/multiroom-booking.js).
 *
 * The module exposes NOTHING — it attaches delegated document-level
 * change/click listeners at import and reads all configuration from data
 * attributes on #multi-room-selection. Tests inject the DOM fixture the
 * novoton search page renders and drive it with real events. A fresh
 * container element per test resets the module's internal selection state
 * (it re-keys state when the container node changes — the AJAX-reload path).
 */
beforeAll(async () => {
    await import('../../addon-travel-core/js/addons/travel_core/multiroom-booking.js');
});

function renderFixture({ numRooms = 2, coefficient = 1, roundPrices = false, currency = 'EUR' } = {}) {
    document.body.innerHTML = `
        <div id="multi-room-selection"
             data-num-rooms="${numRooms}"
             data-rooms-data='[{"adults":2,"children":0,"childrenAges":[]},{"adults":2,"children":1,"childrenAges":[7]}]'
             data-currency="${currency}"
             data-coefficient="${coefficient}"
             data-round-prices="${roundPrices}">
            <div data-room="1">
                <div id="room-1-price">-- ${currency}</div>
                <label class="room-option">
                    <input type="radio" name="room_1_selection" value="R1|BB|500"
                           data-room-num="1" data-room-id="R1" data-board-id="BB"
                           data-price="500" data-room-display="DBL" data-board-name="Bed &amp; Breakfast"
                           data-package-name="Summer"
                           data-standard-price="560" data-early-booking="10" data-extras="7 = 6">
                </label>
            </div>
            <div data-room="2">
                <div id="room-2-price">-- ${currency}</div>
                <label class="room-option">
                    <input type="radio" name="room_2_selection" value="R2|AI|750.5"
                           data-room-num="2" data-room-id="R2" data-board-id="AI"
                           data-price="750.5" data-room-display="FAM" data-board-name="All Inclusive"
                           data-package-name="">
                </label>
            </div>
            <div id="total-combined-price">-- ${currency}</div>
            <button id="book-multi-room-btn" disabled></button>
        </div>
        <form id="multi-room-booking-form">
            <input type="hidden" id="hidden_rooms_data" value="">
            <input type="hidden" id="hidden_total_price" value="">
        </form>
    `;
}

function selectRadio(name) {
    const radio = document.querySelector(`input[name="${name}"]`);
    radio.checked = true;
    radio.dispatchEvent(new Event('change', { bubbles: true }));
    return radio;
}

beforeEach(() => {
    renderFixture();
});

describe('room selection', () => {
    it('keeps the book button disabled until EVERY room has a selection', () => {
        const btn = document.getElementById('book-multi-room-btn');

        selectRadio('room_1_selection');
        expect(btn.disabled).toBe(true);
        expect(document.getElementById('total-combined-price').textContent).toBe('500 EUR');

        selectRadio('room_2_selection');
        expect(btn.disabled).toBe(false);
    });

    it('sums the total across rooms and formats decimals with a sup marker', () => {
        selectRadio('room_1_selection');
        selectRadio('room_2_selection');

        // 500 + 750.5 = 1250.50 → decimal rendering path
        expect(document.getElementById('total-combined-price').textContent).toContain('1.250');
        expect(document.getElementById('room-2-price').textContent).toContain('750');
    });

    it('applies the display coefficient and integer rounding when configured', () => {
        renderFixture({ coefficient: 5, roundPrices: true, currency: 'RON' });

        selectRadio('room_1_selection');

        // 500 × 5 = 2500 → thousands-separated, no decimals
        expect(document.getElementById('room-1-price').textContent).toBe('2.500 RON');
    });
});

describe('booking submission', () => {
    it('fills the hidden form with the selected rooms and submits it', () => {
        const submitSpy = vi.fn();
        document.getElementById('multi-room-booking-form').submit = submitSpy;

        selectRadio('room_1_selection');
        selectRadio('room_2_selection');
        document.getElementById('book-multi-room-btn').disabled = false;
        document.getElementById('book-multi-room-btn')
            .dispatchEvent(new MouseEvent('click', { bubbles: true }));

        expect(submitSpy).toHaveBeenCalledTimes(1);
        expect(document.getElementById('hidden_total_price').value).toBe('1250.5');

        const roomsData = JSON.parse(document.getElementById('hidden_rooms_data').value);
        expect(roomsData).toHaveLength(2);
        expect(roomsData[0]).toMatchObject({ room_num: 1, room_id: 'R1', board_id: 'BB', price: 500, adults: 2 });
        // Occupancy comes from data-rooms-data (room 2 carries the child)
        expect(roomsData[1]).toMatchObject({ room_num: 2, children: 1, childrenAges: [7] });
        // The offer behind room 1's price rides along (the booking page's
        // "was" price); room 2's radio renders none, so it sends none.
        expect(roomsData[0]).toMatchObject({ standard_price: 560, early_booking: 10, extras: '7 = 6' });
        expect(roomsData[1]).not.toHaveProperty('standard_price');
    });

    it('does not submit while the button is disabled', () => {
        const submitSpy = vi.fn();
        document.getElementById('multi-room-booking-form').submit = submitSpy;

        selectRadio('room_1_selection');
        document.getElementById('book-multi-room-btn')
            .dispatchEvent(new MouseEvent('click', { bubbles: true }));

        expect(submitSpy).not.toHaveBeenCalled();
    });
});

/**
 * The redesigned block (novoton search.tpl): room headers, summary list,
 * progress, labels, and — from 4 rooms — one room open at a time.
 */
function renderRooms({ rooms, stepper = false, quota = '' }) {
    const sections = rooms.map((occ, i) => {
        const n = i + 1;
        const open = !stepper || n === 1;
        return `
            <section class="room-type-selection novoton-mr-room${open ? ' is-open' : ''}" data-room="${n}" data-occupancy="${occ}">
                <h4><button type="button" class="novoton-mr-room__toggle" aria-expanded="${open}"></button></h4>
                <span id="room-${n}-choice"></span><span id="room-${n}-price">Not chosen yet</span>
                <div class="novoton-mr-options"${open ? '' : ' hidden'}>
                    <label class="room-option"><input type="radio" name="room_${n}_selection" value="DBL|HB|100"
                        data-room-num="${n}" data-room-id="DBL" data-board-id="HB" data-price="100"
                        data-was-price="120" data-quota="${quota}" data-room-display="Double" data-board-name="Half board"></label>
                    <label class="room-option"><input type="radio" name="room_${n}_selection" value="APT|HB|150"
                        data-room-num="${n}" data-room-id="APT" data-board-id="HB" data-price="150"
                        data-was-price="" data-quota="" data-room-display="Apartment" data-board-name="Half board"></label>
                    <div class="novoton-mr-next" hidden>
                        <button type="button" class="novoton-mr-next__same" data-same-from="${n}" hidden></button>
                        <button type="button" class="novoton-mr-next__go" data-next-from="${n}">Next room</button>
                    </div>
                </div>
            </section>`;
    }).join('');
    const list = rooms.map((_, i) => `<button type="button" class="novoton-mr__list-item is-pending" data-goto-room="${i + 1}"><span id="mr-sum-${i + 1}">Not chosen yet</span></button>`).join('');
    document.body.innerHTML = `
        <div id="multi-room-selection" data-num-rooms="${rooms.length}" data-rooms-data="[]"
             data-currency="EUR" data-coefficient="1" data-round-prices="false" data-stepper="${stepper}"
             data-label-progress="[chosen] of [total] rooms chosen"
             data-label-left="Rooms left to choose: [count]"
             data-label-book="Book all rooms ([count])"
             data-label-same="Apply to the other rooms with the same guests ([count])"
             data-label-savings="You save [amount]">
            ${sections}
            <span id="mr-progress-text"></span><span id="mr-progress-bar"></span>
            ${list}
            <div id="total-combined-price">-- EUR</div>
            <div id="mr-savings" hidden></div>
            <button id="book-multi-room-btn" disabled>Rooms left to choose: ${rooms.length}</button>
        </div>
        <form id="multi-room-booking-form">
            <input type="hidden" id="hidden_rooms_data" value="">
            <input type="hidden" id="hidden_total_price" value="">
        </form>`;
}

function pick(room, index = 0) {
    const radio = document.querySelectorAll(`input[name="room_${room}_selection"]`)[index];
    radio.checked = true;
    radio.dispatchEvent(new Event('change', { bubbles: true }));
}

function click(selector) {
    document.querySelector(selector).dispatchEvent(new MouseEvent('click', { bubbles: true }));
}

const isOpen = (n) => document.querySelector(`[data-room="${n}"]`).classList.contains('is-open');

describe('selection summary and labels', () => {
    it('counts progress, names the rooms left and only then offers to book', () => {
        renderRooms({ rooms: ['2a0c', '3a0c', '2a1c-7'] });
        const btn = document.getElementById('book-multi-room-btn');

        pick(1);
        expect(document.getElementById('mr-progress-text').textContent).toBe('1 of 3 rooms chosen');
        expect(document.getElementById('mr-progress-bar').style.width).toBe('33%');
        expect(btn.textContent).toBe('Rooms left to choose: 2');
        expect(btn.disabled).toBe(true);
        expect(document.getElementById('room-1-choice').textContent).toBe('Double');
        expect(document.querySelector('[data-goto-room="1"]').classList.contains('is-pending')).toBe(false);
        expect(document.querySelector('[data-room="1"] .room-option').classList.contains('is-selected')).toBe(true);

        pick(2, 1);
        pick(3);
        expect(btn.disabled).toBe(false);
        expect(btn.textContent).toBe('Book all rooms (3)');
        // (120 - 100) × 2 rooms that show a "was" price
        expect(document.getElementById('mr-savings').hidden).toBe(false);
        expect(document.getElementById('mr-savings').textContent).toBe('You save 40 EUR');
    });

    it('never submits a partial booking, even when the button is forced on', () => {
        renderRooms({ rooms: ['2a0c', '2a0c'] });
        const submitSpy = vi.fn();
        document.getElementById('multi-room-booking-form').submit = submitSpy;

        pick(1, 1);
        document.getElementById('book-multi-room-btn').disabled = false;
        click('#book-multi-room-btn');

        expect(submitSpy).not.toHaveBeenCalled();
        expect(isOpen(2)).toBe(true);
    });
});

describe('4+ rooms: one room open at a time', () => {
    it('closes the chosen room and opens the next one still to choose', () => {
        renderRooms({ rooms: ['2a0c', '3a0c', '1a0c', '2a1c-5'], stepper: true });

        pick(1);
        expect(isOpen(1)).toBe(false);
        expect(isOpen(2)).toBe(true);
        expect(document.querySelector('[data-room="2"] .novoton-mr-options').hidden).toBe(false);
    });

    it('reopens a room from its header or from the summary list', () => {
        renderRooms({ rooms: ['2a0c', '3a0c', '1a0c', '2a1c-5'], stepper: true });
        pick(1);

        click('[data-goto-room="1"]');
        expect(isOpen(1)).toBe(true);
        expect(isOpen(2)).toBe(false);

        click('[data-room="1"] .novoton-mr-room__toggle');
        expect(isOpen(1)).toBe(false);
    });

    it('applies a choice to the other rooms with the same guests', () => {
        renderRooms({ rooms: ['2a0c', '2a0c', '3a0c', '2a0c', '2a1c-5'], stepper: true });

        pick(1);
        const same = document.querySelector('[data-room="1"] .novoton-mr-next__same');
        expect(same.hidden).toBe(false);
        expect(same.textContent).toBe('Apply to the other rooms with the same guests (2)');
        expect(isOpen(1)).toBe(true); // stays open while the offer is shown

        click('[data-room="1"] .novoton-mr-next__same');
        expect(document.getElementById('mr-progress-text').textContent).toBe('3 of 5 rooms chosen');
        expect(document.querySelector('input[name="room_4_selection"]').checked).toBe(true);
        expect(document.querySelector('input[name="room_3_selection"]').checked).toBe(false);
        expect(isOpen(3)).toBe(true);
    });

    it('never applies a room type to more rooms than the hotel has left', () => {
        renderRooms({ rooms: ['2a0c', '2a0c', '2a0c', '2a0c'], stepper: true, quota: '2' });

        pick(1);
        expect(document.querySelector('[data-room="1"] .novoton-mr-next__same').textContent)
            .toBe('Apply to the other rooms with the same guests (1)');
    });
});

describe('chosen rooms survive a reload of the same search (language switch)', () => {
    const withKey = (key) => {
        document.getElementById('multi-room-selection').setAttribute('data-search-key', key);
    };
    const reload = (key) => {
        // A reload renders a NEW container; the engine announces the swap.
        renderRooms({ rooms: ['2a0c', '3a0c', '1a0c', '2a1c-5'], stepper: true });
        withKey(key);
        document.dispatchEvent(new CustomEvent('travel:results-swapped'));
    };

    beforeEach(() => {
        window.sessionStorage.clear();
        renderRooms({ rooms: ['2a0c', '3a0c', '1a0c', '2a1c-5'], stepper: true });
        withKey('4535|2026-10-05|2026-10-11|[rooms]');
    });

    it('re-selects the saved rooms and opens the first room still to choose', () => {
        pick(1, 1);
        pick(2);
        reload('4535|2026-10-05|2026-10-11|[rooms]');

        expect(document.querySelectorAll('input[name="room_1_selection"]')[1].checked).toBe(true);
        expect(document.querySelector('input[name="room_2_selection"]').checked).toBe(true);
        expect(document.getElementById('mr-progress-text').textContent).toBe('2 of 4 rooms chosen');
        expect(document.getElementById('room-1-choice').textContent).toBe('Apartment');
        expect(isOpen(3)).toBe(true);
        expect(isOpen(1)).toBe(false);
    });

    it('keeps nothing for a different search', () => {
        pick(1);
        reload('4535|2026-10-06|2026-10-11|[rooms]');

        expect(document.querySelector('input[name="room_1_selection"]').checked).toBe(false);
        expect(document.getElementById('mr-progress-text').textContent).toBe('');
    });

    it('drops a choice whose price changed since it was made', () => {
        pick(1);
        renderRooms({ rooms: ['2a0c', '3a0c', '1a0c', '2a1c-5'], stepper: true });
        withKey('4535|2026-10-05|2026-10-11|[rooms]');
        // Same room and meal plan, new price: the radio value differs.
        document.querySelector('input[name="room_1_selection"]').value = 'DBL|HB|110';
        document.dispatchEvent(new CustomEvent('travel:results-swapped'));

        expect(document.querySelector('input[name="room_1_selection"]').checked).toBe(false);
    });
});
