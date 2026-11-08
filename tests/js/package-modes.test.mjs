import { describe, expect, it } from 'vitest';
import {
    modeFromUrl, pickDeparture, nightsOf, pickNights, packageQuoteUrl, packageDatesUrl,
    packageBookUrl, packageUrl, neighbourDates, cheapest, roomTypes, allNights, dateForNights,
} from '../../addon-travel-core/react-src/src/packageModes.js';

/**
 * A hotel's page in a package mode (board 16.2): the mode, city, date and
 * nights the bar opens on, the provider's links, the departures priced next
 * to the chosen one and the offers by room type. Rixos Downtown (Antalya
 * City) is reached by plane only: from Bucharest, 3, 5 or 7 nights.
 */
const modes = [{ transport: 'flight', departures: [{ id: 20, name: 'Cluj-Napoca' }, { id: 10, name: 'Bucuresti' }] }];
const rooms = [{ adults: 2, children: 1, childrenAges: [7, null] }];
const choice = { hotelId: '3612', transport: 'flight', departureId: 10, date: '2027-04-03', nights: 7, rooms };
const base = 'https://shop.test/index.php';

describe('the mode and city the page opens on', () => {
    it('opens in the URL\'s mode only when the hotel has it', () => {
        expect(modeFromUrl('?transport=flight&adults=2', modes)).toBe('flight');
        expect(modeFromUrl('?transport=bus', modes)).toBe('');
        expect(modeFromUrl('', modes)).toBe('');
    });

    it('opens on the city asked for, else Bucharest, else the first', () => {
        const departures = modes[0].departures;
        expect(pickDeparture(departures, '20')).toBe(20);
        expect(pickDeparture(departures, '99')).toBe(10);
        expect(pickDeparture([{ id: 5, name: 'Iasi' }, { id: 6, name: 'Sibiu' }], '')).toBe(5);
        expect(pickDeparture([], '')).toBe(0);
    });

    it('opens on the nights asked for, else a week, else the nearest length', () => {
        const dates = [{ date: '2027-04-03', nights: [7, 3, 5] }, { date: '2027-04-10', nights: [4, 10] }];
        expect(nightsOf(dates, '2027-04-03')).toEqual([3, 5, 7]);
        expect(nightsOf(dates, '2027-05-01')).toEqual([]);
        expect(pickNights([3, 5, 7], '5')).toBe(5);
        expect(pickNights([3, 5, 7], '9')).toBe(7);
        expect(pickNights([4, 10], '')).toBe(4); // as near: the shorter
        expect(pickNights([3, 11], '')).toBe(3);
        expect(pickNights([], '7')).toBe(0);
    });
});

describe('"Nopți" offers every length of the city', () => {
    // Bucharest → Antalya, live: 30 Dec. for 5 nights only, then April for 3, 5 or 7.
    const dates = [
        { date: '2026-12-30', nights: [5] },
        { date: '2027-04-03', nights: [3, 5, 7] },
        { date: '2027-04-10', nights: [7] },
    ];

    it('lists every length the city\'s dates have', () => {
        expect(allNights(dates)).toEqual([3, 5, 7]);
        expect(allNights([])).toEqual([]);
    });

    it('keeps the date when it has the length, else moves to the nearest that has it', () => {
        expect(dateForNights(dates, '2026-12-30', 5)).toBe('2026-12-30');
        expect(dateForNights(dates, '2026-12-30', 7)).toBe('2027-04-03');
        expect(dateForNights(dates, '2027-04-10', 3)).toBe('2027-04-03');
        expect(dateForNights(dates, '', 7)).toBe('2027-04-03');
        expect(dateForNights(dates, '2026-12-30', 10)).toBe('');
    });
});

describe('the provider\'s links', () => {
    it('asks the offers of one departure for each room', () => {
        const url = new URL(packageQuoteUrl(base, 'sphinx_booking.package_quote', { ...choice, rooms: [{ adults: 2, children: 1, childrenAges: [7] }] }));
        expect(Object.fromEntries(url.searchParams)).toEqual({
            dispatch: 'sphinx_booking.package_quote',
            hotel_id: '3612',
            transport: 'flight',
            departure_id: '10',
            departure_date: '2027-04-03',
            nights: '7',
            adults: '2',
            children: '1',
            children_ages: '7',
            rooms: '1',
            rooms_data: '[{"adults":2,"children":1,"childrenAges":[7]}]',
        });
    });

    it('asks a city\'s dates', () => {
        expect(packageDatesUrl(base, 'sphinx_booking.package_dates', '3612', 'flight', 10))
            .toBe(`${base}?dispatch=sphinx_booking.package_dates&hotel_id=3612&transport=flight&departure_id=10`);
    });

    it('opens the package booking form on the offer, for the rooms it was priced for', () => {
        const url = new URL(packageBookUrl(base, 'sphinx_booking.package_booking_form', { rooms: [{ adults: 2, children: 0, childrenAges: [] }] }, { offer_id: 'o-1' }));
        expect(url.searchParams.get('dispatch')).toBe('sphinx_booking.package_booking_form');
        expect(url.searchParams.get('offer_id')).toBe('o-1');
        expect(url.searchParams.get('adults')).toBe('2');
        expect(url.searchParams.get('rooms')).toBe('1');
    });

    it('keeps the choice in the page\'s address, and drops it for accommodation only', () => {
        const href = 'https://shop.test/hotel-rixos.html?adults=2&transport=bus&nights=3';
        expect(packageUrl(href, { transport: 'flight', departureId: 10, date: '2027-04-03', nights: 7 }))
            .toBe('https://shop.test/hotel-rixos.html?adults=2&transport=flight&departure_id=10&departure_date=2027-04-03&nights=7');
        expect(packageUrl(href, null)).toBe('https://shop.test/hotel-rixos.html?adults=2');
    });
});

describe('the other departures and the offers', () => {
    it('prices the nearest dates with the same nights next to the chosen one', () => {
        const dates = [
            { date: '2027-03-20', nights: [7] },
            { date: '2027-03-27', nights: [7] },
            { date: '2027-04-03', nights: [7] },
            { date: '2027-04-06', nights: [3] },
            { date: '2027-04-10', nights: [7] },
            { date: '2027-04-17', nights: [7] },
            { date: '2027-05-01', nights: [7] },
        ];
        expect(neighbourDates(dates, '2027-04-03', 7, 4)).toEqual(['2027-03-20', '2027-03-27', '2027-04-10', '2027-04-17']);
        expect(neighbourDates(dates, '2027-04-03', 3, 4)).toEqual(['2027-04-06']);
        expect(neighbourDates(dates, '', 7)).toEqual([]);
    });

    it('finds the cheapest offer', () => {
        expect(cheapest([{ offers: [{ price: 1990, currency: 'EUR' }] }, { offers: [{ price: 1756, currency: 'EUR' }] }])).toEqual({ price: 1756, currency: 'EUR' });
        expect(cheapest([])).toBeNull();
    });

    it('groups a block\'s offers by room type, each type\'s meal plans cheapest first', () => {
        const double = [{ name: 'Cameră dublă', count: 1 }];
        const triple = [{ name: 'Cameră triplă', count: 1 }];
        const types = roomTypes([
            { offer_id: 'a', rooms: double, meal: 'Demipensiune', price: 690 },
            { offer_id: 'b', rooms: triple, meal: 'Mic dejun', price: 612 },
            { offer_id: 'c', rooms: double, meal: 'Mic dejun', price: 598 },
        ]);
        expect(types.map((type) => type.rooms)).toEqual([double, triple]);
        expect(types[0].options.map((option) => option.offer_id)).toEqual(['c', 'a']);
    });
});
