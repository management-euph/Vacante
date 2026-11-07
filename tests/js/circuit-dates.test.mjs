import { describe, expect, it } from 'vitest';
import { groupByMonth, pickDate, monthIndexOf, missingAges, quoteUrl } from '../../addon-travel-core/react-src/src/circuitDates.js';

/**
 * The circuit bar on a circuit's page (board 16.3): its departures by month
 * under the bar, the date it opens on, the children's ages it needs, and
 * the quote step it opens with each room.
 */
const departures = [
    { date: '2027-08-02', price: 1150, currency: 'EUR' },
    { date: '2027-07-12', price: 1140, currency: 'EUR' },
    { date: '2027-07-05', price: 1092, currency: 'EUR' },
    { date: 'soon', price: 999, currency: 'EUR' },
];

describe('groupByMonth', () => {
    it('groups the departures by month, in date order, leaving out what is no date', () => {
        expect(groupByMonth(departures)).toEqual([
            { month: '2027-07', departures: [departures[2], departures[1]] },
            { month: '2027-08', departures: [departures[0]] },
        ]);
        expect(groupByMonth([])).toEqual([]);
        expect(groupByMonth(null)).toEqual([]);
    });
});

describe('pickDate and monthIndexOf', () => {
    it('keeps the date asked for when the city has it, else takes its first departure', () => {
        const sorted = groupByMonth(departures).flatMap((g) => g.departures);
        expect(pickDate(sorted, '2027-07-12')).toBe('2027-07-12');
        expect(pickDate(sorted, '2027-09-01')).toBe('2027-07-05');
        expect(pickDate([], '2027-07-12')).toBe('');
    });

    it("opens the chips on the chosen date's month", () => {
        const groups = groupByMonth(departures);
        expect(monthIndexOf(groups, '2027-08-02')).toBe(1);
        expect(monthIndexOf(groups, '')).toBe(0);
        expect(monthIndexOf(groups, '2028-01-01')).toBe(0);
    });
});

describe('missingAges', () => {
    it("names each child without an age, room by room, as the guest picker marks them", () => {
        expect(missingAges([
            { adults: 2, children: 2, childrenAges: [5, null] },
            { adults: 1, children: 1, childrenAges: [] },
            { adults: 2, children: 0, childrenAges: [] },
        ])).toEqual([{ room: 0, child: 1 }, { room: 1, child: 0 }]);
        expect(missingAges([{ adults: 2, children: 1, childrenAges: [0] }])).toEqual([]);
    });
});

describe('quoteUrl', () => {
    it('opens the quote step with each room and the totals', () => {
        const url = new URL(quoteUrl('https://shop.example/index.php', 'sphinx_booking.circuit_booking_form', {
            circuitId: '17',
            departureId: '222',
            date: '2027-07-05',
            rooms: [
                { adults: 2, children: 1, childrenAges: [7, 3] },
                { adults: 1, children: 0, childrenAges: [] },
            ],
        }));

        expect(url.pathname).toBe('/index.php');
        const params = Object.fromEntries(url.searchParams);
        expect(params).toMatchObject({
            dispatch: 'sphinx_booking.circuit_booking_form',
            circuit_id: '17',
            departure_id: '222',
            departure_date: '2027-07-05',
            adults: '3',
            children: '1',
            children_ages: '7',
            rooms: '2',
        });
        // A child's age beyond the room's children is not sent.
        expect(JSON.parse(params.rooms_data)).toEqual([
            { adults: 2, children: 1, childrenAges: [7] },
            { adults: 1, children: 0, childrenAges: [] },
        ]);
    });
});
