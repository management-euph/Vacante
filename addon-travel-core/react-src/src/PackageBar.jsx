/**
 * Travel Booking Engine - the package bar on a hotel's page (board 16.2)
 *
 * "Cu autocarul" / "Cu avionul": the hotel's page keeps its photos, text and
 * tabs; the bar asks "Plecare din" (the cities the hotel is reached from),
 * "Data plecării" (that city's departures, in the chips under the bar),
 * "Nopți" (that date's lengths) and "Turiști" (the hotel bar's guest
 * picker). "Vezi prețul" shows the hotel's offers for that departure under
 * the bar ("Camere și prețuri", PackageRooms.jsx); from then on they follow
 * the bar, and the dates next to the chosen one get their price too.
 *
 * A city's dates come from config.datesDispatch (sphinx_booking.package_dates),
 * the offers from config.quoteDispatch (sphinx_booking.package_quote), and
 * "Rezervă" opens config.bookingDispatch (the package booking form). The
 * logic without React lives in packageModes.js and circuitDates.js.
 */

import { useState, useEffect, useMemo, useRef, useCallback } from 'react';
import GuestPicker from './GuestPicker';
import PackageRooms from './PackageRooms';
import { CalendarIcon, GuestIcon, ChevronDown, ChevronLeft, ChevronRight, PinIcon } from './icons';
import { parseDate, formatDateField, getLocale, t } from './utils';
import { groupByMonth, pickDate, monthIndexOf, missingAges } from './circuitDates';
import { chipDate, formatPrice, partySummary } from './circuitFormat';
import { roomsKey } from './circuitRooms';
import {
    pickDeparture, nightsOf, pickNights, packageQuoteUrl, packageDatesUrl, packageBookUrl,
    packageUrl, neighbourDates, cheapest,
} from './packageModes';

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

/** The answer of one quote, or null when it failed. */
function fetchQuote(url) {
    return fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then((response) => (response.ok ? response.json() : null))
        .then((answer) => (answer && !answer.error && Array.isArray(answer.blocks) ? answer : null))
        .catch(() => null);
}

export default function PackageBar({ config }) {
    const {
        hotelId = '',
        transport = 'flight',
        departures = [],
        datesDispatch = '',
        quoteDispatch = '',
        bookingDispatch = '',
        maxRooms = 12,
        maxAdults = 9,
        maxChildren = 4,
    } = config;
    const base = (window.Tygh?.current_location || window.location.origin) + '/index.php';

    const [departureId, setDepartureId] = useState(() => pickDeparture(departures, config.initialDepartureId));
    const [dates, setDates] = useState({ status: 'loading', list: [] });
    const [date, setDate] = useState(config.initialDate || '');
    const [nights, setNights] = useState(() => parseInt(config.initialNights, 10) || 0);
    const [monthIndex, setMonthIndex] = useState(0);
    const [rooms, setRooms] = useState(() => initialRooms(config.roomsData, maxRooms));
    const [showGuests, setShowGuests] = useState(false);
    const [ageErrors, setAgeErrors] = useState([]);
    const [message, setMessage] = useState('');
    // The offers: asked once by the button (or a way back), then following the bar.
    const [asked, setAsked] = useState(() => Boolean(config.initialDate));
    const [askCount, setAskCount] = useState(0);
    const [quote, setQuote] = useState({ status: 'idle', data: null, choice: null });
    const [settledRooms, setSettledRooms] = useState(rooms);
    // The cheapest price of each date already asked, for these nights and guests.
    const [prices, setPrices] = useState({ key: '', byDate: {} });
    const guestTriggerRef = useRef(null);
    const datesRef = useRef(null);
    const roomsRef = useRef(null);
    const scrollToRoomsRef = useRef(false);

    // The chosen city's dates (asked again when it changes).
    useEffect(() => {
        let cancelled = false;
        setDates({ status: 'loading', list: [] });
        fetch(packageDatesUrl(base, datesDispatch, hotelId, transport, departureId), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then((response) => (response.ok ? response.json() : null))
            .then((answer) => {
                if (cancelled) return;
                if (!answer || answer.error || !Array.isArray(answer.dates)) {
                    setDates({ status: 'error', list: [] });
                    return;
                }
                setDates({ status: 'ready', list: answer.dates });
                setDate((previous) => pickDate(answer.dates, previous));
            })
            .catch(() => { if (!cancelled) setDates({ status: 'error', list: [] }); });
        return () => { cancelled = true; };
    }, [base, datesDispatch, hotelId, transport, departureId]);

    // A date keeps the nights when it has them, else the length nearest a week.
    const dateNights = useMemo(() => nightsOf(dates.list, date), [dates.list, date]);
    useEffect(() => { setNights((previous) => pickNights(dateNights, previous)); }, [dateNights]);

    const groups = useMemo(() => groupByMonth(dates.list), [dates.list]);
    // A new list opens on the chosen date's month (date is read, not watched).
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
    const pricesKey = `${departureId}|${nights}|${settledKey}`;

    // The offers of the chosen departure for the settled guests: once asked,
    // again whenever the departure or the guests change (an older answer is
    // dropped). Then the dates next to it get their price, one at a time.
    const datesReady = dates.status === 'ready' && dateNights.includes(nights);
    useEffect(() => {
        if (!asked) return undefined;
        if (!datesReady) {
            const waiting = { loading: 'loading', error: 'error' }[dates.status] || 'idle';
            setQuote({ status: waiting, data: null, choice: null });
            return undefined;
        }
        if (missingAges(settledRooms).length > 0) {
            setQuote({ status: 'ages', data: null, choice: null });
            return undefined;
        }
        let cancelled = false;
        const choice = { hotelId, transport, departureId, date, nights, rooms: settledRooms };
        setQuote({ status: 'loading', data: null, choice });
        try {
            window.history.replaceState(window.history.state, '', packageUrl(window.location.href, choice));
        } catch (_) { /* the address stays */ }
        setPrices((previous) => (previous.key === pricesKey ? previous : { key: pricesKey, byDate: {} }));

        fetchQuote(packageQuoteUrl(base, quoteDispatch, choice)).then(async (answer) => {
            if (cancelled) return;
            if (answer === null) {
                setQuote({ status: 'error', data: null, choice });
                return;
            }
            setQuote({ status: 'ready', data: answer, choice });
            setPrices((previous) => ({ key: pricesKey, byDate: { ...(previous.key === pricesKey ? previous.byDate : {}), [date]: cheapest(answer.blocks) } }));

            for (const other of neighbourDates(dates.list, date, nights, 4)) {
                if (cancelled) return;
                const otherAnswer = await fetchQuote(packageQuoteUrl(base, quoteDispatch, { ...choice, date: other }));
                if (cancelled) return;
                setPrices((previous) => (previous.key === pricesKey
                    ? { key: pricesKey, byDate: { ...previous.byDate, [other]: otherAnswer ? cheapest(otherAnswer.blocks) : null } }
                    : previous));
            }
        });
        return () => { cancelled = true; };
        // settledKey stands for settledRooms; askCount asks again on the button.
    }, [asked, askCount, datesReady, dates.status, base, quoteDispatch, hotelId, transport, departureId, date, nights, settledKey]);

    // After the button, the offers come into view (once, when they arrive).
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

    const bookHref = (offer) => packageBookUrl(base, bookingDispatch, quote.choice || { rooms: settledRooms }, offer);

    const group = groups[monthIndex] || null;
    const knownPrices = prices.key === pricesKey ? prices.byDate : {};
    const note = transport === 'bus'
        ? t('packagePricesNoteBus', 'Total prices for [party], with the return coach trip and the accommodation.')
        : t('packagePricesNoteFlight', 'Total prices for [party], with the return flight and the accommodation.');

    return (
        <div className="nvt-booking-engine nvt-circuit-bar nvt-package-bar">
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
                            onChange={(event) => { setDepartureId(parseInt(event.target.value, 10)); setMessage(''); }}
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

                {/* Nopți: the chosen date's lengths. */}
                <div className="nvt-field nvt-field--nights">
                    <label className="nvt-field-input nvt-field-input--select">
                        <span className="nvt-field-input-text nvt-field-input-text--stacked">
                            <span className="nvt-label">{t('packageNights', 'Nights')}</span>
                            <span className="nvt-value">{nights > 0 ? nights : '–'}</span>
                        </span>
                        {dateNights.length > 1 && <span className="nvt-field-input-arrow"><ChevronDown /></span>}
                        <select
                            className="nvt-circuit-select"
                            value={nights}
                            onChange={(event) => setNights(parseInt(event.target.value, 10))}
                            disabled={dateNights.length < 2}
                            aria-label={t('packageNights', 'Nights')}
                        >
                            {dateNights.map((n) => <option key={n} value={n}>{n}</option>)}
                        </select>
                    </label>
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

            <p className="travel-circuit-note">{note.replace('[party]', partySummary(totalAdults, totalChildren, rooms.length))}</p>

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
                            {group.departures.map((d) => {
                                const price = knownPrices[d.date] || null;
                                return (
                                    <li key={d.date}>
                                        <button
                                            type="button"
                                            className={`travel-circuit-date${d.date === date ? ' travel-circuit-date--selected' : ''}`}
                                            aria-pressed={d.date === date}
                                            onClick={() => { setDate(d.date); setMessage(''); }}
                                        >
                                            <span className="travel-circuit-date__day">{chipDate(d.date)}</span>
                                            {price && <span className="travel-circuit-date__price">{formatPrice(price.price, price.currency)}</span>}
                                        </button>
                                    </li>
                                );
                            })}
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
                    <PackageRooms quote={quote} bookHref={bookHref} />
                </div>
            )}
        </div>
    );
}
