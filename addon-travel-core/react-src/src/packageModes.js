/**
 * A hotel's page in a package mode (board 16.2: "Doar cazare / Cu autocarul
 * / Cu avionul", HotelModes.jsx, PackageBar.jsx, PackageRooms.jsx) without
 * React: the mode, city, date and nights the bar opens on, the provider's
 * links, the other departures priced next to the chosen one, and the
 * offers by room type. Plain functions, so tests/js can cover them.
 *
 * The modes come with the page's config (booking_config "package"):
 * {modes: [{transport, departures: [{id, name}]}], datesDispatch,
 * quoteDispatch, bookingDispatch}. A city's dates from datesDispatch
 * (sphinx_booking.package_dates): {dates: [{date, nights: [..]}], error};
 * its offers from quoteDispatch (sphinx_booking.package_quote): {blocks:
 * [{transport, flight, check_in, check_out, offers: [{offer_id, rooms:
 * [{name, count}], meal, confirmation, price, currency}]}], adults,
 * children, error}.
 */

/** The URL parameters a package choice keeps (a way back to it). */
export const PACKAGE_PARAMS = ['transport', 'departure_id', 'departure_date', 'nights'];

/**
 * The mode the page opens in: the URL's transport when the hotel has it,
 * else '' (accommodation only, the page as it always was).
 *
 * @param {string} search window.location.search
 * @param {Array<{transport: string}>} modes
 * @returns {string}
 */
export function modeFromUrl(search, modes) {
    const wanted = new URLSearchParams(search || '').get('transport') || '';
    return (modes || []).some((mode) => mode.transport === wanted) ? wanted : '';
}

/**
 * The city the bar opens on: the one asked for when the mode has it, else
 * Bucharest (where most leave from), else the first (the most dates).
 *
 * @param {Array<{id: number, name: string}>} departures
 * @param {number|string} wanted a city id, or ''
 * @returns {number} 0 without any
 */
export function pickDeparture(departures, wanted) {
    const list = departures || [];
    const id = parseInt(wanted, 10);
    if (id > 0 && list.some((d) => d.id === id)) return id;
    const bucharest = list.find((d) => /bucure|buchar/i.test(d.name || ''));
    if (bucharest) return bucharest.id;
    return list.length > 0 ? list[0].id : 0;
}

/**
 * The nights of a date (its routes' lengths), sorted.
 *
 * @param {Array<{date: string, nights: number[]}>} dates
 * @param {string} date YYYY-MM-DD
 * @returns {number[]}
 */
export function nightsOf(dates, date) {
    const day = (dates || []).find((d) => d.date === date);
    return day ? [...day.nights].sort((a, b) => a - b) : [];
}

/**
 * The nights the bar shows: the ones asked for when the date has them,
 * else a week when it has one, else the length nearest to a week.
 *
 * @param {number[]} nights nightsOf()
 * @param {number|string} wanted
 * @returns {number} 0 without any
 */
export function pickNights(nights, wanted) {
    const list = nights || [];
    const asked = parseInt(wanted, 10);
    if (asked > 0 && list.includes(asked)) return asked;
    if (list.length === 0) return 0;
    return [...list].sort((a, b) => Math.abs(a - 7) - Math.abs(b - 7) || a - b)[0];
}

/**
 * The rooms as the provider takes them (the hotel bar's rooms_data), with
 * the totals its endpoints also read.
 *
 * @param {Array<{adults: number, children: number, childrenAges: Array<number|null>}>} rooms
 * @returns {Record<string, string>}
 */
function partyParams(rooms) {
    const list = (rooms || []).map((room) => ({
        adults: room.adults,
        children: room.children,
        childrenAges: (room.childrenAges || []).slice(0, room.children),
    }));
    const ages = list.flatMap((room) => room.childrenAges);
    return {
        adults: String(list.reduce((sum, room) => sum + room.adults, 0)),
        children: String(ages.length),
        children_ages: ages.join(','),
        rooms: String(list.length),
        rooms_data: JSON.stringify(list),
    };
}

/**
 * The offers of one departure for the chosen rooms.
 *
 * @param {string} base the store's index URL (…/index.php)
 * @param {string} dispatch e.g. "sphinx_booking.package_quote"
 * @param {{hotelId: string, transport: string, departureId: number, date: string, nights: number, rooms: Array}} choice
 * @returns {string}
 */
export function packageQuoteUrl(base, dispatch, choice) {
    const params = new URLSearchParams({
        dispatch,
        hotel_id: String(choice.hotelId),
        transport: choice.transport,
        departure_id: String(choice.departureId || ''),
        departure_date: choice.date,
        nights: String(choice.nights),
        ...partyParams(choice.rooms),
    });
    return `${base}?${params.toString()}`;
}

/**
 * A city's dates and nights.
 *
 * @param {string} base the store's index URL (…/index.php)
 * @param {string} dispatch e.g. "sphinx_booking.package_dates"
 * @param {string} hotelId
 * @param {string} transport
 * @param {number} departureId
 * @returns {string}
 */
export function packageDatesUrl(base, dispatch, hotelId, transport, departureId) {
    const params = new URLSearchParams({
        dispatch,
        hotel_id: String(hotelId),
        transport,
        departure_id: String(departureId || ''),
    });
    return `${base}?${params.toString()}`;
}

/**
 * The provider's package booking form on one offer (it verifies the offer
 * with the supplier), with the rooms it was quoted for.
 *
 * @param {string} base the store's index URL (…/index.php)
 * @param {string} dispatch e.g. "sphinx_booking.package_booking_form"
 * @param {{rooms: Array}} choice the choice the offer was quoted for
 * @param {{offer_id: string}} offer
 * @returns {string}
 */
export function packageBookUrl(base, dispatch, choice, offer) {
    const params = new URLSearchParams({ dispatch, offer_id: offer.offer_id || '', ...partyParams(choice.rooms) });
    return `${base}?${params.toString()}`;
}

/**
 * The page's address for a mode and a departure (replaceState): a way back
 * to the same choice; the accommodation-only page has none of it.
 *
 * @param {string} href window.location.href
 * @param {{transport: string, departureId?: number, date?: string, nights?: number}|null} choice
 * @returns {string}
 */
export function packageUrl(href, choice) {
    const url = new URL(href);
    PACKAGE_PARAMS.forEach((key) => url.searchParams.delete(key));
    if (choice && choice.transport) {
        url.searchParams.set('transport', choice.transport);
        if (choice.departureId) url.searchParams.set('departure_id', String(choice.departureId));
        if (choice.date) url.searchParams.set('departure_date', choice.date);
        if (choice.nights) url.searchParams.set('nights', String(choice.nights));
    }
    return url.toString();
}

/**
 * The other departures priced next to the chosen one ("Alte plecări"): the
 * dates nearest to it with the same nights, at most `count`, in date order.
 *
 * @param {Array<{date: string, nights: number[]}>} dates
 * @param {string} date the chosen one
 * @param {number} nights
 * @param {number} count
 * @returns {string[]}
 */
export function neighbourDates(dates, date, nights, count = 4) {
    const day = (d) => Date.parse(`${d}T00:00:00Z`);
    const chosen = day(date);
    if (Number.isNaN(chosen)) return [];
    return (dates || [])
        .filter((d) => d.date !== date && (d.nights || []).includes(nights))
        .map((d) => d.date)
        .sort((a, b) => Math.abs(day(a) - chosen) - Math.abs(day(b) - chosen) || (a < b ? -1 : 1))
        .slice(0, count)
        .sort();
}

/**
 * The cheapest offer of a quote ({price, currency}), else null.
 *
 * @param {Array<{offers: Array<{price: number, currency: string}>}>} blocks
 * @returns {{price: number, currency: string}|null}
 */
export function cheapest(blocks) {
    let best = null;
    (blocks || []).forEach((block) => (block.offers || []).forEach((offer) => {
        if (offer.price > 0 && (best === null || offer.price < best.price)) {
            best = { price: offer.price, currency: offer.currency };
        }
    }));
    return best;
}

/**
 * A block's offers by room type (board 16.2: "Cameră dublă" with its meal
 * plans under it), each type with its options cheapest first, the types
 * by their cheapest option.
 *
 * @param {Array<{offer_id: string, rooms: Array<{name: string, count: number}>, price: number}>} offers
 * @returns {Array<{rooms: Array<{name: string, count: number}>, options: Array}>}
 */
export function roomTypes(offers) {
    const types = new Map();
    (offers || []).forEach((offer) => {
        const key = JSON.stringify(offer.rooms || []);
        if (!types.has(key)) types.set(key, { rooms: offer.rooms || [], options: [] });
        types.get(key).options.push(offer);
    });
    const list = [...types.values()];
    list.forEach((type) => type.options.sort((a, b) => a.price - b.price));
    return list.sort((a, b) => a.options[0].price - b.options[0].price);
}
