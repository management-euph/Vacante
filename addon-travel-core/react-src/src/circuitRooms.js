/**
 * "Camera pentru tot circuitul" (board 16.3, CircuitRooms.jsx) without
 * React: what the rooms of a departure say and where their "Rezervă"
 * leads. Plain functions, so tests/js can cover them.
 *
 * The rooms come from config.quoteDispatch (sphinx_booking.circuit_quote):
 * {blocks: [{flight, nights, offers: [{offer_id, key, rooms: [{name, count}],
 * meal, price, currency}]}], adults, children, error}.
 */

import { quoteUrl } from './circuitDates';

/**
 * The rooms as the quote is asked for them: each room's adults and each of
 * its children's age (null while not chosen), in the picker's order. Two
 * choices with the same key ask the same quote.
 *
 * @param {Array<{adults: number, children: number, childrenAges: Array<number|null>}>} rooms
 * @returns {string}
 */
export function roomsKey(rooms) {
    return JSON.stringify((rooms || []).map((room) => [
        room.adults,
        Array.from({ length: room.children || 0 }, (_, c) => {
            const age = (room.childrenAges || [])[c];
            return age === undefined || age === '' ? null : age;
        }),
    ]));
}

/**
 * "Standard Double" / "2 × Standard Double + Single": an offer's rooms.
 *
 * @param {Array<{name: string, count: number}>} rooms
 * @returns {string}
 */
export function roomsLabel(rooms) {
    return (rooms || []).map((room) => (room.count > 1 ? `${room.count} × ${room.name}` : room.name)).join(' + ');
}

/**
 * "4" / "3–4": the stars of the hotels on the route; '' when unknown.
 *
 * @param {{min: number, max: number}|null} stars config.hotelStars
 * @returns {string}
 */
export function starsText(stars) {
    if (!stars || !(stars.min > 0)) return '';
    return stars.max > stars.min ? `${stars.min}–${stars.max}` : String(stars.min);
}

/**
 * The meal plan every offer of a block has; '' when they differ.
 *
 * @param {Array<{meal: string}>} offers
 * @returns {string}
 */
export function commonMeal(offers) {
    const meals = [...new Set((offers || []).map((offer) => offer.meal || ''))];
    return meals.length === 1 ? meals[0] : '';
}

/**
 * The price for each traveller, rounded; 0 for a party of one (whose
 * price is the total).
 *
 * @param {number} price the total
 * @param {number} persons adults and children
 * @returns {number}
 */
export function perPerson(price, persons) {
    return persons > 1 ? Math.round(price / persons) : 0;
}

/**
 * "Bucharest Henri Coanda International Airport (OTP)": an airport, by its
 * name and its code, whichever the supplier gave.
 *
 * @param {string} name
 * @param {string} code
 * @returns {string}
 */
export function place(name, code) {
    if (name && code) return `${name} (${code})`;
    return name || code || '';
}

/**
 * The days a flight lands after it leaves (0 the same day).
 *
 * @param {string} departureDate YYYY-MM-DD
 * @param {string} arrivalDate YYYY-MM-DD, or ''
 * @returns {number}
 */
export function daysLater(departureDate, arrivalDate) {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(departureDate || '') || !/^\d{4}-\d{2}-\d{2}$/.test(arrivalDate || '')) return 0;
    const days = Math.round((Date.parse(`${arrivalDate}T00:00:00Z`) - Date.parse(`${departureDate}T00:00:00Z`)) / 86400000);
    return days > 0 ? days : 0;
}

/**
 * The booking form on one offer: the choice it was quoted for (the same
 * parameters as the quote step), its id, and what it is (its key), which
 * finds the same room in a new quote when the offer is no longer kept.
 *
 * @param {string} base the store's index URL (…/index.php)
 * @param {string} dispatch e.g. "sphinx_booking.circuit_booking_form"
 * @param {{circuitId: string, departureId: string, date: string, rooms: Array}} choice
 * @param {{offer_id: string, key: string}} offer
 * @returns {string}
 */
export function bookUrl(base, dispatch, choice, offer) {
    const params = new URLSearchParams({ offer_id: offer.offer_id || '', offer_key: offer.key || '' });
    return `${quoteUrl(base, dispatch, choice)}&${params.toString()}`;
}
