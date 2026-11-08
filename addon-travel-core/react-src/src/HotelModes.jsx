/**
 * Travel Booking Engine - a hotel's page with its packages (board 16.2)
 *
 * The same hotel page, another mode: a switch above the bar, "Doar cazare /
 * Cu autocarul / Cu avionul", with only the modes the hotel has (the
 * page's config: booking_config "package"). "Doar cazare" is the hotel bar
 * as it always was (BookingEngine.jsx, kept mounted, so its search and its
 * results stay); a transport shows the package bar and its offers
 * (PackageBar.jsx). The photos, text and tabs do not change.
 *
 * While a transport is chosen, the accommodation results the hotel bar
 * swapped in under it are hidden (.travel-pdp-results--package); the mode is
 * kept in the page's address ("transport"), so a way back opens in it.
 */

import { useState, useEffect, useRef } from 'react';
import BookingEngine from './BookingEngine';
import PackageBar from './PackageBar';
import { BedIcon, BusIcon, PlaneIcon } from './icons';
import { t } from './utils';
import { modeFromUrl, packageUrl } from './packageModes';

const LABELS = {
    bus: () => t('packageByBus', 'By coach'),
    flight: () => t('packageByPlane', 'By plane'),
};

export default function HotelModes({ hotelConfig, packageConfig }) {
    const modes = packageConfig.modes || [];
    const [mode, setMode] = useState(() => modeFromUrl(window.location.search, modes));
    const rootRef = useRef(null);

    // The accommodation results under the bar are not this mode's.
    useEffect(() => {
        const page = rootRef.current ? rootRef.current.closest('.travel-pdp-results') : null;
        if (page) page.classList.toggle('travel-pdp-results--package', mode !== '');
    }, [mode]);

    const choose = (next) => {
        if (next === mode) return;
        setMode(next);
        try {
            window.history.replaceState(window.history.state, '', packageUrl(window.location.href, next ? { transport: next } : null));
        } catch (_) { /* the address stays */ }
    };

    const current = modes.find((m) => m.transport === mode) || null;
    const url = new URLSearchParams(window.location.search);

    return (
        <div className="travel-modes" ref={rootRef}>
            <div className="travel-modes__switch" role="radiogroup" aria-label={t('packageSwitchLabel', 'How you travel')}>
                <button
                    type="button"
                    role="radio"
                    aria-checked={mode === ''}
                    className={`travel-modes__option${mode === '' ? ' travel-modes__option--selected' : ''}`}
                    onClick={() => choose('')}
                >
                    <BedIcon />
                    <span>{t('packageStayOnly', 'Accommodation only')}</span>
                </button>
                {modes.map((m) => (
                    <button
                        key={m.transport}
                        type="button"
                        role="radio"
                        aria-checked={mode === m.transport}
                        className={`travel-modes__option${mode === m.transport ? ' travel-modes__option--selected' : ''}`}
                        onClick={() => choose(m.transport)}
                    >
                        {m.transport === 'bus' ? <BusIcon /> : <PlaneIcon />}
                        <span>{(LABELS[m.transport] || (() => m.transport))()}</span>
                    </button>
                ))}
            </div>

            <div hidden={mode !== ''}>
                <BookingEngine config={hotelConfig} />
            </div>

            {current && (
                <PackageBar
                    key={current.transport}
                    config={{
                        hotelId: hotelConfig.hotelId,
                        transport: current.transport,
                        departures: current.departures,
                        datesDispatch: packageConfig.datesDispatch,
                        quoteDispatch: packageConfig.quoteDispatch,
                        bookingDispatch: packageConfig.bookingDispatch,
                        maxRooms: hotelConfig.maxRooms,
                        maxAdults: hotelConfig.maxAdults,
                        maxChildren: hotelConfig.maxChildren,
                        initialDepartureId: url.get('departure_id') || '',
                        initialDate: url.get('departure_date') || '',
                        initialNights: url.get('nights') || '',
                        roomsData: url.get('rooms_data') || '',
                    }}
                />
            )}
        </div>
    );
}
