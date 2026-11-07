import { describe, expect, it } from 'vitest';
import {
    roomsKey, roomsLabel, starsText, commonMeal, perPerson, place, daysLater, bookUrl,
} from '../../addon-travel-core/react-src/src/circuitRooms.js';

/**
 * "Camera pentru tot circuitul" (board 16.3): what a departure's rooms say
 * (their names, the hotels' stars, the meal plan, the price per person, the
 * flight's airports) and the booking form each "Rezervă" opens.
 */
describe('roomsKey', () => {
    it('is the same for the same guests, whatever the ages the picker keeps beyond its children', () => {
        const a = [{ adults: 2, children: 1, childrenAges: [7, 3] }];
        const b = [{ adults: 2, children: 1, childrenAges: [7] }];
        expect(roomsKey(a)).toBe(roomsKey(b));
    });

    it('differs once a child is added, before its age is chosen', () => {
        expect(roomsKey([{ adults: 2, children: 1, childrenAges: [] }])).not.toBe(roomsKey([{ adults: 2, children: 0, childrenAges: [] }]));
        expect(roomsKey([{ adults: 2, children: 1, childrenAges: [null] }])).toBe(roomsKey([{ adults: 2, children: 1, childrenAges: [] }]));
    });

    it('differs when a room, an adult or an age differs', () => {
        const base = roomsKey([{ adults: 2, children: 0, childrenAges: [] }]);
        expect(roomsKey([{ adults: 3, children: 0, childrenAges: [] }])).not.toBe(base);
        expect(roomsKey([{ adults: 2, children: 0, childrenAges: [] }, { adults: 1, children: 0, childrenAges: [] }])).not.toBe(base);
        expect(roomsKey([{ adults: 2, children: 1, childrenAges: [4] }])).not.toBe(roomsKey([{ adults: 2, children: 1, childrenAges: [5] }]));
    });
});

describe('roomsLabel', () => {
    it('names one room as the supplier does, and counts a room met twice', () => {
        expect(roomsLabel([{ name: 'Standard - Double', count: 1 }])).toBe('Standard - Double');
        expect(roomsLabel([{ name: 'Standard - Double', count: 2 }, { name: 'Single', count: 1 }])).toBe('2 × Standard - Double + Single');
        expect(roomsLabel([])).toBe('');
    });
});

describe('starsText', () => {
    it('gives the stars of the hotels on the route, or a range', () => {
        expect(starsText({ min: 4, max: 4 })).toBe('4');
        expect(starsText({ min: 3, max: 4 })).toBe('3–4');
    });

    it('is empty when the supplier does not say', () => {
        expect(starsText(null)).toBe('');
        expect(starsText({ min: 0, max: 0 })).toBe('');
    });
});

describe('commonMeal', () => {
    it('is the meal plan of every room, or nothing when they differ', () => {
        expect(commonMeal([{ meal: 'Mic Dejun' }, { meal: 'Mic Dejun' }])).toBe('Mic Dejun');
        expect(commonMeal([{ meal: 'Mic Dejun' }, { meal: 'Demipensiune' }])).toBe('');
    });
});

describe('perPerson', () => {
    it('shares the total between the travellers, rounded', () => {
        expect(perPerson(1092, 2)).toBe(546);
        expect(perPerson(1000, 3)).toBe(333);
    });

    it('is 0 for one traveller, whose price is the total', () => {
        expect(perPerson(742, 1)).toBe(0);
    });
});

describe('place', () => {
    it('writes an airport by its name and its code, whichever is known', () => {
        expect(place('Heraklion International Airport', 'HER')).toBe('Heraklion International Airport (HER)');
        expect(place('', 'HER')).toBe('HER');
        expect(place('Heraklion International Airport', '')).toBe('Heraklion International Airport');
    });
});

describe('daysLater', () => {
    it('counts the days a flight lands after it leaves', () => {
        expect(daysLater('2027-07-05', '2027-07-05')).toBe(0);
        expect(daysLater('2027-07-05', '2027-07-06')).toBe(1);
        expect(daysLater('2027-07-05', '')).toBe(0);
    });
});

describe('bookUrl', () => {
    it('opens the booking form on the offer, with the choice it was quoted for', () => {
        const url = new URL(bookUrl('https://shop.test/index.php', 'sphinx_booking.circuit_booking_form', {
            circuitId: '7',
            departureId: '111',
            date: '2027-07-05',
            rooms: [{ adults: 2, children: 1, childrenAges: [6] }],
        }, { offer_id: 'a1b2', key: 'standard-double|mic-dejun' }));

        expect(url.searchParams.get('dispatch')).toBe('sphinx_booking.circuit_booking_form');
        expect(url.searchParams.get('circuit_id')).toBe('7');
        expect(url.searchParams.get('departure_id')).toBe('111');
        expect(url.searchParams.get('departure_date')).toBe('2027-07-05');
        expect(JSON.parse(url.searchParams.get('rooms_data'))).toEqual([{ adults: 2, children: 1, childrenAges: [6] }]);
        expect(url.searchParams.get('offer_id')).toBe('a1b2');
        expect(url.searchParams.get('offer_key')).toBe('standard-double|mic-dejun');
    });
});
