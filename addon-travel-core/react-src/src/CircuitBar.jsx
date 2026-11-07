/**
 * Travel Booking Engine - the circuit bar (board 16.3)
 *
 * On a circuit's product page, the bar the hotel page has, with the
 * circuit's fields: "Plecare din" (the cities the circuit leaves from),
 * "Data plecării" (its departures from that city, each with its price for
 * 2 adults, chosen in the chips under the bar), "Turiști" (the hotel bar's
 * own guest picker: rooms, adults, children's ages). The button opens the
 * provider's quote step with that choice.
 *
 * The dates come from config.datesDispatch (sphinx_booking.circuit_dates):
 * {departures: [{date, price, currency}], error}. The logic without React
 * lives in circuitDates.js.
 */

import { useState, useEffect, useMemo, useRef, useCallback } from 'react';
import GuestPicker from './GuestPicker';
import { CalendarIcon, GuestIcon, ChevronDown, ChevronLeft, ChevronRight, PinIcon } from './icons';
import { parseDate, formatDateField, formatDateShort, getLocale, t } from './utils';
import { injectStyles } from './styles';
import { groupByMonth, pickDate, monthIndexOf, missingAges, quoteUrl } from './circuitDates';

const MONTH_KEYS = ['january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december'];

/** "iulie 2027" / "July 2027" for "2027-07". */
function monthLabel(month) {
    const [year, m] = month.split('-').map((part) => parseInt(part, 10));
    const key = MONTH_KEYS[m - 1] || '';
    const name = t(key, key.charAt(0).toUpperCase() + key.slice(1));
    return `${getLocale() === 'ro' ? name.toLowerCase() : name} ${year}`;
}

/** "lun. 5 iul." / "Mon. 5 Jul." for a chip. */
function chipDate(date) {
    const text = formatDateShort(parseDate(date));
    return getLocale() === 'ro' ? text.toLowerCase() : text;
}

/** "1.092 €" (ro) / "1,092 €" (en): whole units, as the board shows. */
function formatPrice(price, currency) {
    const amount = new Intl.NumberFormat(getLocale() === 'ro' ? 'ro-RO' : 'en-GB', { maximumFractionDigits: 0 }).format(price);
    return `${amount} ${currency === 'EUR' ? '€' : currency}`;
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
        bookingDispatch = '',
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
    const guestTriggerRef = useRef(null);
    const datesRef = useRef(null);

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
    const guestSummary = useMemo(() => {
        const parts = [`${totalAdults} ${(totalAdults === 1 ? t('adult', 'adult') : t('adults', 'adults')).toLowerCase()}`];
        if (totalChildren > 0) {
            parts.push(`${totalChildren} ${(totalChildren === 1 ? t('child', 'child') : t('children', 'children')).toLowerCase()}`);
        }
        if (rooms.length > 1) {
            parts.push(`${rooms.length} ${t('rooms', 'rooms').toLowerCase()}`);
        }
        return parts.join(' · ');
    }, [totalAdults, totalChildren, rooms.length]);

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
        window.location.href = quoteUrl(base, bookingDispatch, { circuitId, departureId, date, rooms });
    };

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
                    <button type="button" className="nvt-btn-search" onClick={handleSubmit}>
                        {t('circuitSeePrice', 'See the price')}
                    </button>
                </div>
            </div>

            {message && <div className="nvt-validation-message" role="alert">{message}</div>}

            <p className="travel-circuit-note">{t('circuitPricesNote', 'Total prices for 2 adults, with the transport and the accommodation; the price for your group comes at the next step.')}</p>

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
        </div>
    );
}
