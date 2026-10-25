/**
 * Novoton Booking Engine - Calendar date-range picker
 *
 * Shows two consecutive months side-by-side. The user picks a check-in
 * date first, then a check-out date. Selected range is highlighted.
 *
 * When `prices` prop is provided, each day cell displays a small per-night
 * price label below the day number, similar to Booking.com. Dates without
 * prices show "_" and are greyed out.
 *
 * Mobile (<= 768px): full-screen overlay with scrollable months, sticky
 * weekday header, close (X) button, and confirm button at the bottom.
 *
 * variant="a" (the Design A hotel page): a header with the stay and its
 * nights, a footer with the price legend, "Schimbă oaspeții", "Șterge
 * datele" and the search button; `selecting` says which date the next click
 * sets (the field that opened it); the check-out day shows no price.
 */

import { useState, useCallback, useEffect, useRef, useMemo } from 'react';
import { getLocale, nightsBetween, formatDateShort, formatDateField, t, tPlural, useFocusTrap } from './utils';
import { MONTHS_EN, MONTHS_RO, WEEKDAYS_EN, WEEKDAYS_RO } from './translations';
import { CalendarIcon, ChevronLeft, ChevronRight, XIcon } from './icons';

/**
 * Build a matrix of weeks (rows of 7 cells) for a given month.
 * Empty cells are `null`.
 */
function buildCalendarGrid(year, month) {
    const firstDay = new Date(year, month, 1);
    // JS getDay(): 0=Sun … 6=Sat → convert to Mon-based (0=Mon … 6=Sun)
    let startDay = firstDay.getDay() - 1;
    if (startDay < 0) startDay = 6;

    const daysInMonth = new Date(year, month + 1, 0).getDate();
    const cells = [];

    for (let i = 0; i < startDay; i++) cells.push(null);
    for (let d = 1; d <= daysInMonth; d++) cells.push(d);

    return cells;
}

function isSameDay(a, b) {
    if (!a || !b) return false;
    return a.getFullYear() === b.getFullYear() &&
           a.getMonth() === b.getMonth() &&
           a.getDate() === b.getDate();
}

function isInRange(date, checkIn, checkOut) {
    if (!date || !checkIn || !checkOut) return false;
    return date > checkIn && date < checkOut;
}

/**
 * Convert a Date to "YYYY-MM-DD" string for price map lookup.
 */
function toDateKey(year, month, day) {
    const m = String(month + 1).padStart(2, '0');
    const d = String(day).padStart(2, '0');
    return `${year}-${m}-${d}`;
}

/**
 * Format a price for compact calendar display.
 * 5000 → "5k", 12500 → "13k", 350 → "350", 99 → "99"
 */
function formatCalendarPrice(price) {
    if (price >= 1000) {
        return Math.round(price / 1000) + 'k';
    }
    return String(Math.round(price));
}

/** Number of months to render ahead in mobile scrollable view. */
const MOBILE_MONTHS_AHEAD = 12;

/** Simple media query check for mobile. */
function useIsMobile(breakpoint = 768) {
    const [isMobile, setIsMobile] = useState(() =>
        typeof window !== 'undefined' && window.innerWidth <= breakpoint
    );

    useEffect(() => {
        if (typeof window === 'undefined') return;
        const mq = window.matchMedia(`(max-width: ${breakpoint}px)`);
        const handler = (e) => setIsMobile(e.matches);
        // Modern browsers
        if (mq.addEventListener) {
            mq.addEventListener('change', handler);
            return () => mq.removeEventListener('change', handler);
        }
        // Safari 13 fallback
        mq.addListener(handler);
        return () => mq.removeListener(handler);
    }, [breakpoint]);

    return isMobile;
}

export default function Calendar({
    checkIn, checkOut, onSelect, onClose, prices, pricesCurrency, triggerRef,
    variant = '', selecting = 'checkIn', onSearch, onChangeGuests, triggerRefs,
}) {
    const isVariantA = variant === 'a';
    const locale = getLocale();
    const monthNames = locale === 'ro' ? MONTHS_RO : MONTHS_EN;
    const weekdays = locale === 'ro' ? WEEKDAYS_RO : WEEKDAYS_EN;

    const hasPrices = prices && typeof prices === 'object' && Object.keys(prices).length > 0;

    const isMobile = useIsMobile();

    // Memoize today so it doesn't invalidate useCallback deps on every render
    const today = useMemo(() => {
        const d = new Date();
        d.setHours(0, 0, 0, 0);
        return d;
    }, []);

    // Start calendar from check-in month, or current month
    const startMonth = checkIn ? new Date(checkIn.getFullYear(), checkIn.getMonth(), 1)
                               : new Date(today.getFullYear(), today.getMonth(), 1);

    const [viewDate, setViewDate] = useState(startMonth);
    const [tempCheckIn, setTempCheckIn] = useState(checkIn);
    const [tempCheckOut, setTempCheckOut] = useState(checkOut);
    // Which date the next click sets. Opened from the Check-out field (Design A)
    // with a check-in already chosen, a click after it moves only the check-out.
    const [phase, setPhase] = useState(() => (selecting === 'checkOut' && checkIn ? 'checkOut' : 'checkIn'));
    const popupRef = useRef(null);

    // Only a change of the field that opened the calendar moves the phase
    // from outside (deps on purpose: not on every date pick).
    useEffect(() => {
        setPhase(selecting === 'checkOut' && tempCheckIn ? 'checkOut' : 'checkIn');
    }, [selecting]);

    // WCAG 2.1: trap focus within the calendar dialog
    useFocusTrap(popupRef);

    // Close on outside click or Escape key
    // Use 'click' (not 'mousedown') so the toggle button's onClick fires first
    // and can set a flag to prevent the close handler from interfering.
    useEffect(() => {
        function handleClick(e) {
            // Ignore clicks on the trigger button(s) (prevents close-then-reopen flicker)
            const triggers = triggerRefs || (triggerRef ? [triggerRef] : []);
            if (triggers.some(ref => ref && ref.current && ref.current.contains(e.target))) return;
            if (popupRef.current && !popupRef.current.contains(e.target)) {
                onClose && onClose();
            }
        }
        function handleKeyDown(e) {
            if (e.key === 'Escape') {
                onClose && onClose();
            }
        }
        document.addEventListener('mousedown', handleClick);
        document.addEventListener('keydown', handleKeyDown);
        return () => {
            document.removeEventListener('mousedown', handleClick);
            document.removeEventListener('keydown', handleKeyDown);
        };
    }, [onClose, triggerRef, triggerRefs]);

    const goToPrev = useCallback(() => {
        setViewDate(prev => new Date(prev.getFullYear(), prev.getMonth() - 1, 1));
    }, []);

    const goToNext = useCallback(() => {
        setViewDate(prev => new Date(prev.getFullYear(), prev.getMonth() + 1, 1));
    }, []);

    const handleDayClick = useCallback((date) => {
        if (date < today) return;

        // Check-out: a check-in is chosen, the click is after it, and either
        // no check-out yet or the Check-out field opened the calendar. A
        // complete stay makes the next click start a new one.
        if (tempCheckIn && date > tempCheckIn && (!tempCheckOut || phase === 'checkOut')) {
            setTempCheckOut(date);
            setPhase('checkIn');
            onSelect(tempCheckIn, date);
            return;
        }
        // Otherwise a fresh check-in (no dates, both dates chosen from the
        // Check-in side, or a click on or before the current check-in).
        setTempCheckIn(date);
        setTempCheckOut(null);
        setPhase('checkOut');
        onSelect(date, null);
    }, [tempCheckIn, tempCheckOut, phase, today, onSelect]);

    const clearDates = useCallback(() => {
        setTempCheckIn(null);
        setTempCheckOut(null);
        setPhase('checkIn');
        onSelect(null, null);
    }, [onSelect]);

    // -----------------------------------------------------------------------
    // Month lists
    // -----------------------------------------------------------------------

    // Desktop: two consecutive months from viewDate
    const month1Year = viewDate.getFullYear();
    const month1Month = viewDate.getMonth();
    const month2Date = new Date(month1Year, month1Month + 1, 1);
    const month2Year = month2Date.getFullYear();
    const month2Month = month2Date.getMonth();

    // Mobile: 12 months ahead from current month (always start from today)
    const mobileMonths = useMemo(() => {
        if (!isMobile) return [];
        const months = [];
        for (let i = 0; i < MOBILE_MONTHS_AHEAD; i++) {
            const d = new Date(today.getFullYear(), today.getMonth() + i, 1);
            months.push({ year: d.getFullYear(), month: d.getMonth() });
        }
        return months;
    }, [isMobile, today]);

    const canGoPrev = new Date(month1Year, month1Month, 1) > new Date(today.getFullYear(), today.getMonth(), 1);

    const nights = nightsBetween(tempCheckIn, tempCheckOut);

    // Compute price tier thresholds (quartiles) across visible months
    const priceTiers = useMemo(() => {
        if (!hasPrices) return null;
        const monthList = isMobile
            ? mobileMonths
            : [{ year: month1Year, month: month1Month }, { year: month2Year, month: month2Month }];
        const allPrices = [];
        for (const monthInfo of monthList) {
            const daysInMonth = new Date(monthInfo.year, monthInfo.month + 1, 0).getDate();
            for (let d = 1; d <= daysInMonth; d++) {
                const key = toDateKey(monthInfo.year, monthInfo.month, d);
                const date = new Date(monthInfo.year, monthInfo.month, d, 12, 0, 0);
                if (date >= today && prices[key] !== undefined) {
                    const p = Number(prices[key]);
                    if (isFinite(p) && p > 0) allPrices.push(p);
                }
            }
        }
        if (allPrices.length === 0) return null;
        allPrices.sort((a, b) => a - b);
        const q1 = allPrices[Math.floor(allPrices.length * 0.25)];
        const q2 = allPrices[Math.floor(allPrices.length * 0.50)];
        const q3 = allPrices[Math.floor(allPrices.length * 0.75)];
        return { q1, q2, q3 };
    }, [hasPrices, prices, isMobile, mobileMonths, month1Year, month1Month, month2Year, month2Month, today]);

    function renderMonth(year, month) {
        const cells = buildCalendarGrid(year, month);

        return (
            <div className="nvt-calendar-month" key={`${year}-${month}`}>
                {/* Month title — visible on mobile only (desktop uses nvt-calendar-nav-row) */}
                {isMobile && (
                    <div className="nvt-calendar-header">
                        <h3>{monthNames[month]} {year}</h3>
                    </div>
                )}
                <div className="nvt-calendar-weekdays">
                    {weekdays.map((d, i) => <span key={i}>{d}</span>)}
                </div>
                <div className={`nvt-calendar-days${hasPrices ? ' nvt-calendar-days--with-prices' : ''}`}>
                    {cells.map((day, i) => {
                        if (day === null) {
                            return <span key={`e${i}`} className="nvt-calendar-day nvt-calendar-day--empty" />;
                        }

                        const date = new Date(year, month, day, 12, 0, 0);
                        const isPast = date < today;
                        const isToday = isSameDay(date, today);
                        const isSelectedCheckIn = isSameDay(date, tempCheckIn);
                        const isSelectedCheckOut = isSameDay(date, tempCheckOut);
                        const inRange = isInRange(date, tempCheckIn, tempCheckOut);

                        // Price lookup
                        const dateKey = toDateKey(year, month, day);
                        const dayPrice = hasPrices ? prices[dateKey] : undefined;
                        const hasNoPrice = hasPrices && !isPast && dayPrice === undefined;

                        // Determine price tier: lowest, medium, higher, highest
                        let priceTier = '';
                        if (hasPrices && !isPast && dayPrice !== undefined && priceTiers) {
                            const p = Number(dayPrice);
                            if (p <= priceTiers.q1) priceTier = 'lowest';
                            else if (p <= priceTiers.q2) priceTier = 'medium';
                            else if (p <= priceTiers.q3) priceTier = 'higher';
                            else priceTier = 'highest';
                        }

                        let className = 'nvt-calendar-day';
                        if (hasPrices) className += ' nvt-calendar-day--has-prices';
                        if (isPast) className += ' nvt-calendar-day--disabled';
                        if (isToday) className += ' nvt-calendar-day--today';
                        if (isSelectedCheckIn || isSelectedCheckOut) className += ' nvt-calendar-day--selected';
                        if (inRange) className += ' nvt-calendar-day--in-range';
                        if (hasNoPrice) className += ' nvt-calendar-day--no-price';
                        if (priceTier) className += ` nvt-calendar-day--price-${priceTier}`;

                        // Build accessible label for screen readers
                        const ariaLabel = (() => {
                            const dateStr = `${day} ${monthNames[month]} ${year}`;
                            if (isPast) return `${dateStr}, ${t('unavailable', 'unavailable')}`;

                            const parts = [dateStr];
                            if (isSelectedCheckIn) parts.push(t('selectedCheckIn', 'selected as check-in'));
                            else if (isSelectedCheckOut) parts.push(t('selectedCheckOut', 'selected as check-out'));
                            else if (inRange) parts.push(t('withinStay', 'within your stay'));

                            if (hasPrices && dayPrice !== undefined) {
                                parts.push(`${dayPrice} ${pricesCurrency || ''} ${t('perNight', 'per night')}`.trim());
                            } else if (hasPrices) {
                                parts.push(t('priceUnavailable', 'price unavailable'));
                            }
                            return parts.join(', ');
                        })();

                        const tooltip = hasPrices && dayPrice !== undefined && !isPast
                            ? `${dayPrice} ${pricesCurrency || ''}`.trim()
                            : undefined;
                        // Design A: the departure day is not a night of the stay.
                        const showPrice = hasPrices && !isPast && !(isVariantA && isSelectedCheckOut);

                        return (
                            <button
                                key={day}
                                type="button"
                                className={className}
                                disabled={isPast}
                                onClick={() => handleDayClick(date)}
                                aria-label={ariaLabel}
                                aria-pressed={isSelectedCheckIn || isSelectedCheckOut || undefined}
                                title={tooltip}
                            >
                                <span className="nvt-calendar-day-num">{day}</span>
                                {showPrice && (
                                    <span className="nvt-calendar-day-price">
                                        {dayPrice !== undefined ? formatCalendarPrice(dayPrice) : '_'}
                                    </span>
                                )}
                            </button>
                        );
                    })}
                </div>
            </div>
        );
    }

    // Footer text – e.g. "Mon. 14 Feb. → Mon. 21 Feb. (7 nights)"
    const footerText = (() => {
        if (tempCheckIn && tempCheckOut) {
            const nightLabel = tPlural(nights, 'night', 'nights', 'nightsMany', 'night', 'nights', 'nights');
            return `${formatDateShort(tempCheckIn)} \u2192 ${formatDateShort(tempCheckOut)} (${nights} ${nightLabel})`;
        }
        if (tempCheckIn) {
            return `${formatDateShort(tempCheckIn)} \u2192 ${t('selectCheckOut', 'Select check-out date')}`;
        }
        return t('selectCheckIn', 'Select check-in date');
    })();

    // Price footer text — "Approximate prices in EUR for a 1-night stay"
    const priceFooterText = hasPrices && pricesCurrency
        ? t('calendarPriceFooter', 'Approximate prices in %s for a 1-night stay').replace('%s', pricesCurrency)
        : '';

    // Confirm button label (bilingual)
    const confirmLabel = isVariantA
        ? t('seePrices', 'See prices')
        : (locale === 'ro'
            ? t('selectThisDate', 'Selectează această dată')
            : t('selectThisDate', 'Select this date'));

    // Design A header: "lun. 26 oct. 2026 – sâm. 31 oct. 2026 · 5 nopți"
    const stayText = (() => {
        if (tempCheckIn && tempCheckOut) {
            const nightLabel = tPlural(nights, 'night', 'nights', 'nightsMany', 'night', 'nights', 'nights');
            return `${formatDateField(tempCheckIn)} \u2013 ${formatDateField(tempCheckOut)} \u00b7 ${nights} ${nightLabel}`;
        }
        if (tempCheckIn) {
            return `${formatDateField(tempCheckIn)} \u2013 ${t('selectCheckOut', 'Select check-out date')}`;
        }
        return t('selectCheckIn', 'Select check-in date');
    })();

    const search = () => {
        if (tempCheckIn && tempCheckOut && onSearch) {
            onSearch();
        } else if (onClose) {
            onClose();
        }
    };

    return (
        <div
            className={`nvt-calendar-popup${isVariantA ? ' nvt-calendar-popup--a' : ''}`}
            ref={popupRef}
            role="dialog"
            aria-modal="true"
            aria-label={t('datePicker', 'Date picker')}
        >
            {isVariantA && (
                <div className="nvt-calendar-stay">
                    <span className="nvt-calendar-stay-icon"><CalendarIcon /></span>
                    <span className="nvt-calendar-stay-text" aria-live="polite">{stayText}</span>
                    <button
                        type="button"
                        className="nvt-calendar-stay-close"
                        onClick={() => onClose && onClose()}
                        aria-label={t('close', 'Close')}
                    >
                        <XIcon />
                    </button>
                </div>
            )}

            {/* Close (X) button — visible on mobile only (hidden via CSS on desktop) */}
            <button
                type="button"
                className="nvt-calendar-close"
                onClick={() => onClose && onClose()}
                aria-label={t('close', 'Close')}
            >
                <XIcon />
            </button>

            {/* Desktop: unified nav row — arrows inline with month titles (hidden on mobile via CSS) */}
            <div className="nvt-calendar-nav-row">
                <button
                    type="button"
                    className="nvt-calendar-nav"
                    onClick={goToPrev}
                    disabled={!canGoPrev}
                    aria-label={t('previousMonth', 'Previous month')}
                >
                    <ChevronLeft />
                </button>
                <h3 className="nvt-month-title">{monthNames[month1Month]} {month1Year}</h3>
                <h3 className="nvt-month-title">{monthNames[month2Month]} {month2Year}</h3>
                <button
                    type="button"
                    className="nvt-calendar-nav"
                    onClick={goToNext}
                    aria-label={t('nextMonth', 'Next month')}
                >
                    <ChevronRight />
                </button>
            </div>

            {/* Mobile: shared sticky weekday header (hidden on desktop via CSS) */}
            <div className="nvt-calendar-sticky-weekdays">
                {weekdays.map((d, i) => <span key={i}>{d}</span>)}
            </div>

            {/* Mobile: scrollable months container */}
            {isMobile ? (
                <div className="nvt-calendar-scroll-container">
                    <div className="nvt-calendar-months">
                        {mobileMonths.map(({ year, month }) => renderMonth(year, month))}
                    </div>
                </div>
            ) : (
                /* Desktop: two months side-by-side (unchanged) */
                <div className="nvt-calendar-months">
                    {renderMonth(month1Year, month1Month)}
                    {renderMonth(month2Year, month2Month)}
                </div>
            )}

            {isVariantA ? (
                <div className="nvt-calendar-actions">
                    <div className="nvt-calendar-legend">
                        {hasPrices && (
                            <span className="nvt-calendar-legend-lowest">{t('lowestPrices', 'Lowest prices')}</span>
                        )}
                        <span className="nvt-calendar-legend-note">
                            {hasPrices && `${t('nightlyPricesNote', 'Indicative prices per night, for 2 adults.')} `}
                            {onChangeGuests && (
                                <button type="button" className="nvt-calendar-link" onClick={onChangeGuests}>
                                    {t('changeGuests', 'Change guests')}
                                </button>
                            )}
                        </span>
                    </div>
                    <div className="nvt-calendar-buttons">
                        {(tempCheckIn || tempCheckOut) && (
                            <button type="button" className="nvt-calendar-link" onClick={clearDates}>
                                {t('clearDates', 'Clear dates')}
                            </button>
                        )}
                        <button type="button" className="nvt-btn-search nvt-calendar-search" onClick={search}>
                            {t('seePrices', 'See prices')}
                        </button>
                    </div>
                </div>
            ) : (
                <>
                    {priceFooterText && (
                        <div className="nvt-calendar-price-footer">
                            {priceFooterText}
                        </div>
                    )}

                    <div className="nvt-calendar-footer">
                        <span>{footerText}</span>
                    </div>
                </>
            )}

            {/* Confirm button — visible on mobile only (hidden via CSS on desktop) */}
            <div className="nvt-calendar-confirm">
                <button
                    type="button"
                    className="nvt-done-btn"
                    onClick={isVariantA ? search : () => onClose && onClose()}
                    disabled={!tempCheckIn}
                >
                    {confirmLabel}
                </button>
            </div>
        </div>
    );
}
