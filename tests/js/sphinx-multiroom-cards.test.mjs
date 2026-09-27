import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest';

/**
 * Sphinx multi-room offers: a multi-room search returns ONE offer for the
 * whole party whose `rooms` array holds a room per occupancy
 * (SearchOfferNormalizer → room_lines). The card drawn by the poller lists
 * every room with its guests and marks the price as the total for all rooms.
 */
const labels = {
    roomN: 'Camera [num]',
    adultsOne: '1 adult',
    adultsMany: '[count] adulți',
    childrenOne: '1 copil',
    childrenMany: '[count] copii',
    totalForRooms: 'Total pentru [count] camere',
};

function renderPending(searchId) {
    document.body.innerHTML = `
        <div class="sphinx-loading-skeleton" style="display: none;"></div>
        <div id="sphinx-results-container" data-search-id="${searchId}" data-search-status="pending"
             data-client-params='{"check_in":"2026-10-05","check_out":"2026-10-11","nights":6,"currency":"EUR","adults":5,"children":1,"children_ages":"7","rooms":2,"product_id":"12"}'
             data-client-config='${JSON.stringify({ maxPolls: 1, pollInterval: 1, labels })}'></div>
        <div id="sphinx-no-results" style="display: none;"></div>`;
}

function stubPoll(results) {
    vi.stubGlobal('fetch', vi.fn(() => Promise.resolve({
        json: () => Promise.resolve({ status: 'completed', results, cursor: null }),
    })));
}

async function drawn() {
    document.dispatchEvent(new CustomEvent('travel:results-swapped'));
    await vi.waitFor(() => {
        expect(document.querySelector('.sphinx-offer-card')).not.toBeNull();
    });
    return document.querySelector('.sphinx-offer-card');
}

beforeAll(async () => {
    document.body.innerHTML = '';
    await import('../../addon-sphinx-holidays/js/addons/sphinx_holidays/search-results.js');
});

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('sphinx offer cards', () => {
    it('list every room of a multi-room offer with its guests', async () => {
        renderPending('multi-1');
        stubPoll([{
            offer_id: 'o1', hotel_id: '7', hotel_name: 'Hotel', price: 1840, currency: 'EUR',
            room_name: 'Double Sea View', board_name: 'Half Board',
            room_lines: [
                { name: 'Double Sea View', adults: 2, children: 1 },
                { name: 'Triple Room', adults: 3, children: 0 },
            ],
        }]);

        const card = await drawn();
        const items = [...card.querySelectorAll('.travel-offer-rooms__item')].map((li) => li.textContent);

        expect(items).toEqual([
            'Camera 1Double Sea View2 adulți + 1 copil',
            'Camera 2Triple Room3 adulți',
        ]);
        expect(card.querySelector('.sphinx-price-rooms').textContent).toBe('Total pentru 2 camere');
        expect(card.querySelector('.sx-room')).toBeNull();
    });

    it('keep the single room line for a one-room offer', async () => {
        renderPending('single-1');
        stubPoll([{
            offer_id: 'o2', hotel_id: '7', hotel_name: 'Hotel', price: 900, currency: 'EUR',
            room_name: 'Double Room', board_name: 'Half Board',
            room_lines: [{ name: 'Double Room', adults: 2, children: 0 }],
        }]);

        const card = await drawn();

        expect(card.querySelector('.sx-room').textContent).toBe('Double Room');
        expect(card.querySelector('.travel-offer-rooms')).toBeNull();
        expect(card.querySelector('.sphinx-price-rooms')).toBeNull();
    });

    it('never injects room names as HTML', async () => {
        renderPending('xss-1');
        stubPoll([{
            offer_id: 'o3', hotel_id: '7', hotel_name: 'Hotel', price: 900, currency: 'EUR',
            room_lines: [
                { name: '<img src=x onerror=alert(1)>', adults: 2, children: 0 },
                { name: 'Twin', adults: 1, children: 2 },
            ],
        }]);

        const card = await drawn();

        expect(card.querySelector('img[onerror]')).toBeNull();
        expect(card.querySelector('.travel-offer-rooms__name').textContent).toBe('<img src=x onerror=alert(1)>');
        expect(card.querySelectorAll('.travel-offer-rooms__guests')[1].textContent).toBe('1 adult + 2 copii');
    });
});
