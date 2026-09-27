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
