/**
 * The circuit bar's logic, without React (board 16.3, CircuitBar.jsx):
 * departures by month, the month and date the bar opens on, and the link to
 * the quote step. Plain functions, so tests/js can cover them.
 */

/**
 * Departures grouped by month, in date order.
 *
 * @param {Array<{date: string, price: number, currency: string}>} departures
 * @returns {Array<{month: string, departures: Array<{date: string, price: number, currency: string}>}>}
 */
export function groupByMonth(departures) {
    const months = new Map();
    [...(departures || [])]
        .filter((d) => d && /^\d{4}-\d{2}-\d{2}$/.test(d.date))
        .sort((a, b) => (a.date < b.date ? -1 : a.date > b.date ? 1 : 0))
        .forEach((d) => {
            const month = d.date.slice(0, 7);
            if (!months.has(month)) months.set(month, []);
            months.get(month).push(d);
        });
    return [...months.entries()].map(([month, list]) => ({ month, departures: list }));
}

/**
 * The date the bar shows: the one asked for when the city still has it,
 * else the city's first departure ('' without any).
 *
 * @param {Array<{date: string}>} departures
 * @param {string} wanted YYYY-MM-DD, or ''
 * @returns {string}
 */
export function pickDate(departures, wanted) {
    const list = departures || [];
    if (wanted && list.some((d) => d.date === wanted)) return wanted;
    return list.length > 0 ? list[0].date : '';
}

/**
 * The month the chips show: the chosen date's, else the first with departures.
 *
 * @param {Array<{month: string}>} groups groupByMonth()
 * @param {string} date YYYY-MM-DD, or ''
 * @returns {number} its index in groups (0 when none)
 */
export function monthIndexOf(groups, date) {
    const month = (date || '').slice(0, 7);
    const index = groups.findIndex((g) => g.month === month);
    return index >= 0 ? index : 0;
}

/**
 * The rooms with a missing child age, as the guest picker marks them.
 *
 * @param {Array<{children: number, childrenAges: Array<number|null>}>} rooms
 * @returns {Array<{room: number, child: number}>}
 */
export function missingAges(rooms) {
    const errors = [];
    (rooms || []).forEach((room, r) => {
        for (let c = 0; c < (room.children || 0); c++) {
            const age = (room.childrenAges || [])[c];
            if (age === null || age === undefined || age === '') errors.push({ room: r, child: c });
        }
    });
    return errors;
}

/**
 * The quote step for the chosen departure and turiști: the provider's
 * circuit booking form, with each room (rooms_data, as the hotel bar sends
 * them) and the totals the step also reads.
 *
 * @param {string} base the store's index URL (…/index.php)
 * @param {string} dispatch e.g. "sphinx_booking.circuit_booking_form"
 * @param {{circuitId: string, departureId: string, date: string, rooms: Array<{adults: number, children: number, childrenAges: Array<number|null>}>}} choice
 * @returns {string}
 */
export function quoteUrl(base, dispatch, choice) {
    const rooms = (choice.rooms || []).map((room) => ({
        adults: room.adults,
        children: room.children,
        childrenAges: (room.childrenAges || []).slice(0, room.children),
    }));
    const ages = rooms.flatMap((room) => room.childrenAges);
    const params = new URLSearchParams({
        dispatch,
        circuit_id: String(choice.circuitId),
        departure_id: String(choice.departureId || ''),
        departure_date: choice.date,
        adults: String(rooms.reduce((sum, room) => sum + room.adults, 0)),
        children: String(ages.length),
        children_ages: ages.join(','),
        rooms: String(rooms.length),
        rooms_data: JSON.stringify(rooms),
    });
    return `${base}?${params.toString()}`;
}
