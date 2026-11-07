/**
 * Travel Booking Engine - the circuit bar (board 16.3)
 *
 * On a circuit's product page, the bar the hotel page has, with the
 * circuit's fields: "Plecare din" (the cities the circuit leaves from),
 * "Data plecării" (its departures from that city, each with its price for
 * 2 adults, chosen in the chips under the bar), "Turiști" (the hotel bar's
 * own guest picker: rooms, adults, children's ages). "Vezi prețul" shows the
 * rooms of that departure for that group under the bar ("Camera pentru tot
 * circuitul", CircuitRooms.jsx); from then on the rooms follow the bar (a
 * date, a city, the guests once the picker closes) and the button,
 * "Modifică", is the secondary one, so each room's "Rezervă" stands out.
 *
 * The dates come from config.datesDispatch (sphinx_booking.circuit_dates):
 * {departures: [{date, price, currency}], error}; the rooms from
 * config.quoteDispatch (sphinx_booking.circuit_quote). The logic without
 * React lives in circuitDates.js and circuitRooms.js.
 */

import { useState, useEffect, useMemo, useRef, useCallback } from 'react';
import GuestPicker from './GuestPicker';
import CircuitRooms from './CircuitRooms';
import { CalendarIcon, GuestIcon, ChevronDown, ChevronLeft, ChevronRight, PinIcon } from './icons';
import { parseDate, formatDateField, getLocale, t } from './utils';
import { injectStyles } from './styles';
import { groupByMonth, pickDate, monthIndexOf, missingAges, quoteUrl } from './circuitDates';
import { chipDate, formatPrice, partySummary } from './circuitFormat';
import { roomsKey, bookUrl } from './circuitRooms';

const MONTH_KEYS = ['january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december'];

/** "iulie 2027" / "July 2027" for "2027-07". */
function monthLabel(month) {
    const [year, m] = month.split('-').map((part) => parseInt(part, 10));
    const key = MONTH_KEYS[m - 1] || '';
    const name = t(key, key.charAt(0).toUpperCase() + key.slice(1));
    return `${getLocale() === 'ro' ? name.toLowerCase() : name} ${year}`;
}

/** The rooms to start with: the ones in the URL (a way back), else 2 adults. */
function initialRooms(roomsData, maxRooms) {
    if (roomsData) {
        try {
            const parsed = JSON.parse(roomsData);
            if (Array.isArray(parsed) && parsed.length > 0) {
                return parsed.slice(0, Math.max(1, maxRooms)).map((room) => ({
                    adults: Math.max(1, parseInt(room.adults, 10) || 2),
                    children: Math.max(0, parseInt(room.children, 10) || 0),
                    childrenAges: Array.isArray(room.childrenAges) ? room.childrenAges : [],
                }));
            }
        } catch (_) { /* fall through */ }
    }
    return [{ adults: 2, children: 0, childrenAges: [] }];
}

export default function CircuitBar({ config }) {
    useEffect(() => { injectStyles(); }, []);

    const {
        circuitId = '',
        departures = [],
        datesDispatch = '',
        quoteDispatch = '',
        bookingDispatch = '',
        hotelStars = null,
        maxRooms = 12,
        maxAdults = 9,
        maxChildren = 4,
    } = config;
    const base = (window.Tygh?.current_location || window.location.origin) + '/index.php';

    const [departureId, setDepartureId] = useState(() => {
        const wanted = config.initialDepartureId || '';
        return departures.some((d) => d.id === wanted) ? wanted : (config.departureId || (departures[0] ? departures[0].id : ''));
    });
    const [dates, setDates] = useState({ status: 'loading', list: [] });
    const [date, setDate] = useState(config.initialDate || '');
    const [monthIndex, setMonthIndex] = useState(0);
    const [rooms, setRooms] = useState(() => initialRooms(config.roomsData, maxRooms));
    const [showGuests, setShowGuests] = useState(false);
    const [ageErrors, setAgeErrors] = useState([]);
    const [message, setMessage] = useState('');
    // The rooms of the departure: asked once by the button, then following the bar.
    const [asked, setAsked] = useState(false);
    const [askCount, setAskCount] = useState(0);
    const [quote, setQuote] = useState({ status: 'idle', data: null, choice: null });
    // The guests the rooms are for: the picker's, once it is closed.
    const [settledRooms, setSettledRooms] = useState(rooms);
    const guestTriggerRef = useRef(null);
    const datesRef = useRef(null);
    const roomsRef = useRef(null);
    const scrollToRoomsRef = useRef(false);

    // The departures from the chosen city (asked again when it changes).
    useEffect(() => {
        let cancelled = false;
        setDates({ status: 'loading', list: [] });
        const url = `${base}?dispatch=${encodeURIComponent(datesDispatch)}`
            + `&circuit_id=${encodeURIComponent(circuitId)}&departure_id=${encodeURIComponent(departureId)}`;
        fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then((response) => (response.ok ? response.json() : null))
            .then((answer) => {
                if (cancelled) return;
                if (!answer || answer.error || !Array.isArray(answer.departures)) {
                    setDates({ status: 'error', list: [] });
                    return;
                }
                setDates({ status: 'ready', list: answer.departures });
                setDate((previous) => pickDate(answer.departures, previous));
            })
            .catch(() => { if (!cancelled) setDates({ status: 'error', list: [] }); });
        return () => { cancelled = true; };
    }, [base, datesDispatch, circuitId, departureId]);

    const groups = useMemo(() => groupByMonth(dates.list), [dates.list]);
    // A new list opens on the chosen date's month.
    // (date is read, not watched: choosing a chip keeps the month shown.)
    useEffect(() => { setMonthIndex(monthIndexOf(groups, date)); }, [groups]);

    const city = useMemo(() => {
        const departure = departures.find((d) => d.id === departureId);
        return departure ? (departure.name || `#${departure.id}`) : '';
    }, [departures, departureId]);

    const totalAdults = rooms.reduce((sum, room) => sum + (room.adults || 0), 0);
    const totalChildren = rooms.reduce((sum, room) => sum + (room.children || 0), 0);
    const guestSummary = partySummary(totalAdults, totalChildren, rooms.length);

    // The picker's guests count once it closes (not at each + or −).
    useEffect(() => { if (!showGuests) setSettledRooms(rooms); }, [showGuests, rooms]);
    const settledKey = roomsKey(settledRooms);

    // The rooms of the chosen departure for the settled guests: once asked,
    // again whenever the departure or the guests change (the answer of an
    // older choice is dropped).
    const datesReady = dates.status === 'ready' && dates.list.some((d) => d.date === date);
    useEffect(() => {
        if (!asked) return undefined;
        if (!datesReady) {
            // The city's dates are loading (the rooms come with its date),
            // failed, or it has none (the dates say so).
            const waiting = { loading: 'loading', error: 'error' }[dates.status] || 'idle';
            setQuote({ status: waiting, data: null, choice: null });
            return undefined;
        }
        if (missingAges(settledRooms).length > 0) {
            setQuote({ status: 'ages', data: null, choice: null });
            return undefined;
        }
        let cancelled = false;
        const choice = { circuitId, departureId, date, rooms: settledRooms };
        setQuote({ status: 'loading', data: null, choice });
        fetch(quoteUrl(base, quoteDispatch, choice), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then((response) => (response.ok ? response.json() : null))
            .then((answer) => {
                if (cancelled) return;
                if (!answer || answer.error || !Array.isArray(answer.blocks)) {
                    setQuote({ status: 'error', data: null, choice });
                    return;
                }
                setQuote({ status: 'ready', data: answer, choice });
            })
            .catch(() => { if (!cancelled) setQuote({ status: 'error', data: null, choice }); });
        return () => { cancelled = true; };
        // settledKey stands for settledRooms; askCount asks again on the button.
    }, [asked, askCount, datesReady, dates.status, base, quoteDispatch, circuitId, departureId, date, settledKey]);

    // After the button, the rooms come into view (once, when they arrive).
    useEffect(() => {
        if (quote.status !== 'ready' || !scrollToRoomsRef.current || !roomsRef.current) return;
        scrollToRoomsRef.current = false;
        const top = roomsRef.current.getBoundingClientRect().top;
        if (top < 0 || top > window.innerHeight * 0.6) {
            roomsRef.current.scrollIntoView({ block: 'start', behavior: 'smooth' });
        }
    }, [quote.status]);

    const handleRoomsUpdate = useCallback((newRooms) => {
        setRooms(newRooms);
        setAgeErrors((previous) => (previous.length === 0 ? previous : missingAges(newRooms)));
    }, []);

    const showDates = () => {
        setShowGuests(false);
        if (datesRef.current) {
            datesRef.current.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            const chip = datesRef.current.querySelector('button');
            if (chip) chip.focus();
        }
    };

    const handleSubmit = () => {
        if (!date) {
            setMessage(t('chooseDepartureDate', 'Choose a departure date'));
            showDates();
            return;
        }
        const errors = missingAges(rooms);
        if (errors.length > 0) {
            setAgeErrors(errors);
            setShowGuests(true);
            return;
        }
        setShowGuests(false);
        setSettledRooms(rooms);
        setAsked(true);
        setAskCount((count) => count + 1);
        scrollToRoomsRef.current = true;
    };

    const bookHref = (offer) => bookUrl(base, bookingDispatch, quote.choice || { circuitId, departureId, date, rooms: settledRooms }, offer);

    const group = groups[monthIndex] || null;

    return (
        <div className="nvt-booking-engine nvt-circuit-bar">
            <div className="nvt-form-row">
                {/* Plecare din: a native select over the field, for the phone's own picker. */}
                <div className="nvt-field nvt-field--departure">
                    <label className="nvt-field-input nvt-field-input--select">
                        <span className="nvt-field-input-icon"><PinIcon /></span>
                        <span className="nvt-field-input-text nvt-field-input-text--stacked">
                            <span className="nvt-label">{t('departureFrom', 'Departure from')}</span>
                            <span className="nvt-value">{city}</span>
                        </span>
                        {departures.length > 1 && <span className="nvt-field-input-arrow"><ChevronDown /></span>}
                        <select
                            className="nvt-circuit-select"
                            value={departureId}
                            onChange={(event) => { setDepartureId(event.target.value); setMessage(''); }}
                            disabled={departures.length < 2}
                            aria-label={t('departureFrom', 'Departure from')}
                        >
                            {departures.map((d) => (
                                <option key={d.id} value={d.id}>{d.name || `#${d.id}`}</option>
                            ))}
                        </select>
                    </label>
                </div>

                {/* Data plecării: chosen in the chips under the bar. */}
                <div className={`nvt-field nvt-field--date-split${message && !date ? ' nvt-field--error' : ''}`}>
                    <button type="button" className="nvt-field-input" onClick={showDates}>
                        <span className="nvt-field-input-icon"><CalendarIcon /></span>
                        <span className="nvt-field-input-text nvt-field-input-text--stacked">
                            <span className="nvt-label">{t('departureDate', 'Departure date')}</span>
                            <span className={`nvt-value${date ? '' : ' nvt-value--placeholder'}`}>
                                {date ? formatDateField(parseDate(date)) : t('chooseDepartureDate', 'Choose a departure date')}
                            </span>
                        </span>
                    </button>
                </div>

                {/* Turiști: the hotel bar's guest picker. */}
                <div className="nvt-field nvt-field--guests">
                    <button
                        ref={guestTriggerRef}
                        type="button"
                        className="nvt-field-input"
                        onClick={() => setShowGuests(!showGuests)}
                        aria-expanded={showGuests}
                        aria-haspopup="dialog"
                    >
                        <span className="nvt-field-input-icon"><GuestIcon /></span>
                        <span className="nvt-field-input-text nvt-field-input-text--stacked">
                            <span className="nvt-label">{t('guests', 'Guests')}</span>
                            <span className="nvt-value">{guestSummary}</span>
                        </span>
                        <span className="nvt-field-input-arrow"><ChevronDown /></span>
                    </button>
                    {showGuests && (
                        <GuestPicker
                            rooms={rooms}
                            maxRooms={maxRooms}
                            maxAdults={maxAdults}
                            maxChildren={maxChildren}
                            onUpdate={handleRoomsUpdate}
                            onClose={() => setShowGuests(false)}
                            onValidate={setAgeErrors}
                            ageErrors={ageErrors}
                            triggerRef={guestTriggerRef}
                        />
                    )}
                </div>

                <div className="nvt-field nvt-field--btn">
                    <button type="button" className={`nvt-btn-search${asked ? ' nvt-btn-search--secondary' : ''}`} onClick={handleSubmit}>
                        {asked ? t('circuitModify', 'Change') : t('circuitSeePrice', 'See the price')}
                    </button>
                </div>
            </div>

            {message && <div className="nvt-validation-message" role="alert">{message}</div>}

            <p className="travel-circuit-note">{t('circuitPricesNote', "Total prices for 2 adults, with the transport and the accommodation; your group's price comes with the rooms.")}</p>

            <div className="travel-circuit-dates" ref={datesRef} aria-live="polite">
                {dates.status === 'loading' && (
                    <p className="travel-circuit-dates__state">{t('loadingDepartures', 'Loading the departures…')}</p>
                )}
                {dates.status === 'error' && (
                    <p className="travel-circuit-dates__state">{t('departuresError', 'The departures cannot be loaded right now. Please try again.')}</p>
                )}
                {dates.status === 'ready' && !group && (
                    <p className="travel-circuit-dates__state">{t('noDepartures', 'No departures from [city] in the coming months.').replace('[city]', city)}</p>
                )}
                {dates.status === 'ready' && group && (
                    <>
                        <span className="travel-circuit-dates__title">
                            {t('departuresFrom', 'Departures from [city], [month]:').replace('[city]', city).replace('[month]', monthLabel(group.month))}
                        </span>
                        {monthIndex > 0 && (
                            <button type="button" className="travel-circuit-dates__nav" onClick={() => setMonthIndex(monthIndex - 1)}>
                                <ChevronLeft /> {monthLabel(groups[monthIndex - 1].month)}
                            </button>
                        )}
                        <ul className="travel-circuit-dates__list">
                            {group.departures.map((d) => (
                                <li key={d.date}>
                                    <button
                                        type="button"
                                        className={`travel-circuit-date${d.date === date ? ' travel-circuit-date--selected' : ''}`}
                                        aria-pressed={d.date === date}
                                        onClick={() => { setDate(d.date); setMessage(''); }}
                                    >
                                        <span className="travel-circuit-date__day">{chipDate(d.date)}</span>
                                        <span className="travel-circuit-date__price">{formatPrice(d.price, d.currency)}</span>
                                    </button>
                                </li>
                            ))}
                        </ul>
                        {monthIndex < groups.length - 1 && (
                            <button type="button" className="travel-circuit-dates__nav" onClick={() => setMonthIndex(monthIndex + 1)}>
                                {monthLabel(groups[monthIndex + 1].month)} <ChevronRight />
                            </button>
                        )}
                    </>
                )}
            </div>

            {asked && quote.status !== 'idle' && (
                <div ref={roomsRef}>
                    <CircuitRooms quote={quote} hotelStars={hotelStars} bookHref={bookHref} />
                </div>
            )}
        </div>
    );
}
