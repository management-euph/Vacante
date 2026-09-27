/**
 * Travel Booking Engine - Main component
 *
 * Renders a Booking.com-style search/booking form with:
 *   - Date range selector (Calendar popup)
 *   - Guest / room picker (GuestPicker popup)
 *   - Search / Book button
 *
 * Three display modes:
 *   "product"  – on a hotel product page (shows Availability header)
 *   "search"   – on search results (shows "Change search" option)
 *   "homepage" – on the homepage (includes a location search input)
 *
 * Provider-agnostic: the search dispatch route is read from
 * config.searchDispatch (set via data-search-dispatch on the mount element).
 */

import { useState, useCallback, useRef, useEffect, useMemo } from 'react';
import Calendar from './Calendar';
import GuestPicker from './GuestPicker';
import { CalendarIcon, GuestIcon, ChevronDown } from './icons';
import { parseDate, toDateString, formatDateShort, nightsBetween, t, tPlural } from './utils';
import { injectStyles } from './styles';

// The booking params an inline (product-page) search writes onto the URL.
const INLINE_SEARCH_KEYS = ['check_in', 'check_out', 'adults', 'children', 'rooms', 'rooms_data', 'children_ages'];
const INLINE_SEARCH_STORE = 'travel_inline_search:';
const INLINE_SEARCH_TTL_MS = 60 * 60 * 1000;

/** Keeps the product's last inline search for this tab (see the restore effect). */
function rememberInlineSearch(productId, url) {
    if (!productId) return;
    try {
        const params = new URL(url, window.location.origin).searchParams;
        const saved = {};
        INLINE_SEARCH_KEYS.forEach((k) => {
            const v = params.get(k);
            if (v !== null) saved[k] = v;
        });
        window.sessionStorage.setItem(INLINE_SEARCH_STORE + productId, JSON.stringify({ params: saved, at: Date.now() }));
    } catch (_) { /* storage unavailable: reloads just show the form */ }
}

/** The product's last inline search as URLSearchParams, or null when none, stale or past. */
function recallInlineSearch(productId) {
    if (!productId) return null;
    try {
        const raw = window.sessionStorage.getItem(INLINE_SEARCH_STORE + productId);
        if (!raw) return null;
        const entry = JSON.parse(raw);
        if (!entry || !entry.params || Date.now() - entry.at > INLINE_SEARCH_TTL_MS) return null;
        const params = new URLSearchParams(entry.params);
        const checkIn = parseDate(params.get('check_in'));
        const checkOut = parseDate(params.get('check_out'));
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        if (!checkIn || !checkOut || checkIn < today) return null;
        return params;
    } catch (_) {
        return null;
    }
}

export default function BookingEngine({ config }) {
    // Inject CSS once on first render
    useEffect(() => { injectStyles(); }, []);

    const {
        provider = '',
        hotelId = '',
        productId = '',
        mode = 'product',
        inlineResults = false,
        searchDispatch = '',
        initialCheckIn = '',
        initialCheckOut = '',
        initialAdults = 2,
        initialChildren = 0,
        initialChildrenAges = '',
        initialRooms = 1,
        maxRooms = 12,
        maxAdults = 9,
        maxChildren = 4,
        buttonText = '',
        roomsData = '',
        calendarPrices: configCalendarPrices = null,
        calendarPricesCurrency: configCalendarPricesCurrency = '',
    } = config;

    // -----------------------------------------------------------------------
    // State
    // -----------------------------------------------------------------------

    const [checkIn, setCheckIn] = useState(() => parseDate(initialCheckIn));
    const [checkOut, setCheckOut] = useState(() => parseDate(initialCheckOut));

    // Build initial rooms array
    const [rooms, setRooms] = useState(() => {
        if (roomsData) {
            try {
                const parsed = JSON.parse(roomsData);
                if (Array.isArray(parsed) && parsed.length > 0) return parsed;
            } catch (_) { /* fall through */ }
        }

        const ages = initialChildrenAges
            ? initialChildrenAges.split(',').map(a => parseInt(a.trim(), 10))
            : [];
        const roomArr = [];
        const roomCount = Math.max(1, initialRooms);

        for (let i = 0; i < roomCount; i++) {
            if (i === 0) {
                roomArr.push({
                    adults: initialAdults,
                    children: initialChildren,
                    childrenAges: ages.length > 0 ? ages : Array(initialChildren).fill(null),
                });
            } else {
                roomArr.push({ adults: 2, children: 0, childrenAges: [] });
            }
        }
        return roomArr;
    });

    // Calendar prices
    const calendarPrices = useMemo(() => {
        if (configCalendarPrices && typeof configCalendarPrices === 'object' && Object.keys(configCalendarPrices).length > 0) {
            return {
                prices: configCalendarPrices,
                currency: configCalendarPricesCurrency || ''
            };
        }
        if (typeof window !== 'undefined' && window.bookingData && window.bookingData.showCalendarPrices) {
            return {
                prices: window.bookingData.calendarPrices || {},
                currency: window.bookingData.calendarPricesCurrency || ''
            };
        }
        return { prices: {}, currency: '' };
    }, [configCalendarPrices, configCalendarPricesCurrency]);

    const [showCalendar, setShowCalendar] = useState(false);
    const [showGuests, setShowGuests] = useState(false);
    const [validationError, setValidationError] = useState('');
    const [ageErrors, setAgeErrors] = useState([]);
    const [searchQuery, setSearchQuery] = useState('');
    const [isSearching, setIsSearching] = useState(false);
    const [fetchError, setFetchError] = useState('');

    const [hasSearched, setHasSearched] = useState(
        mode === 'search' || (!!initialCheckIn && !!initialCheckOut)
    );
    const [paramsChanged, setParamsChanged] = useState(false);

    const engineRef = useRef(null);
    const retryTimerRef = useRef(null);
    const dateTriggerRef = useRef(null);
    const guestTriggerRef = useRef(null);

    useEffect(() => () => { clearTimeout(retryTimerRef.current); }, []);

    // -----------------------------------------------------------------------
    // Derived values
    // -----------------------------------------------------------------------

    const totalAdults = rooms.reduce((sum, r) => sum + r.adults, 0);
    const totalChildren = rooms.reduce((sum, r) => sum + r.children, 0);
    const nights = nightsBetween(checkIn, checkOut);

    const guestSummary = useMemo(() => {
        const parts = [];

        const adultLabel = (totalAdults === 1 ? t('adult', 'adult') : t('adults', 'adults')).toLowerCase();
        parts.push(`${totalAdults} ${adultLabel}`);

        const childLabel = (totalChildren === 1 ? t('child', 'child') : t('children', 'children')).toLowerCase();
        parts.push(`${totalChildren} ${childLabel}`);

        const roomLabel = (rooms.length === 1 ? t('room', 'room') : t('rooms', 'rooms')).toLowerCase();
        parts.push(`${rooms.length} ${roomLabel}`);

        return parts.join(' \u00b7 ');
    }, [totalAdults, totalChildren, rooms.length]);

    const dateDisplayText = useMemo(() => {
        if (checkIn && checkOut) {
            const nightLabel = tPlural(nights, 'night', 'nights', 'nightsMany', 'night', 'nights', 'nights');
            return `${formatDateShort(checkIn)} \u2192 ${formatDateShort(checkOut)} (${nights} ${nightLabel})`;
        }
        if (checkIn) {
            return `${formatDateShort(checkIn)} \u2192 ...`;
        }
        return '';
    }, [checkIn, checkOut, nights]);

    // -----------------------------------------------------------------------
    // Handlers
    // -----------------------------------------------------------------------

    const handleDateSelect = useCallback((newCheckIn, newCheckOut) => {
        setCheckIn(newCheckIn);
        setCheckOut(newCheckOut);
        setValidationError('');
        if (hasSearched) setParamsChanged(true);
    }, [hasSearched]);

    const handleRoomsUpdate = useCallback((newRooms) => {
        setRooms(newRooms);
        setAgeErrors(prev => {
            if (prev.length === 0) return prev;
            const remaining = prev.filter(err => {
                const room = newRooms[err.room];
                if (!room || err.child >= room.children) return false;
                const age = (room.childrenAges || [])[err.child];
                return age === null || age === undefined || age === '';
            });
            return remaining.length === prev.length ? prev : remaining;
        });
        if (hasSearched) setParamsChanged(true);
    }, [hasSearched]);

    const buildSearchUrl = useCallback(() => {
        const base = window.location.origin + '/index.php';
        const params = new URLSearchParams();

        if (mode === 'homepage') {
            params.set('dispatch', 'products.search');
            params.set('q', searchQuery);
        } else {
            // Provider dispatch must be set via data-search-dispatch on the mount element
            const fallbackDispatch = provider ? `${provider}_booking.search` : '';
            if (!searchDispatch && !fallbackDispatch) {
                if (config.debug === 'true') console.warn('[TravelBooking] data-search-dispatch and data-provider not set on mount element');
            }
            params.set('dispatch', searchDispatch || fallbackDispatch);
            if (hotelId) params.set('hotel_id', hotelId);
            if (productId) params.set('product_id', productId);
        }

        params.set('check_in', toDateString(checkIn));
        params.set('check_out', toDateString(checkOut));
        params.set('adults', totalAdults);
        params.set('children', totalChildren);
        params.set('rooms', rooms.length);
        params.set('rooms_data', JSON.stringify(rooms));

        const allAges = [];
        rooms.forEach(room => {
            (room.childrenAges || []).forEach(age => {
                if (age !== null && age !== undefined) allAges.push(age);
            });
        });
        if (allAges.length > 0) {
            params.set('children_ages', allAges.join(','));
        }

        // Merge provider extra params (JSON map on the mount element's
        // data-extra-params) — read at SEARCH time so controls outside the
        // engine (eurosite country/city selects) stay live. Additive: mounts
        // without the attribute behave exactly as before.
        const mountEl = config.mountEl;
        if (mountEl && mountEl.dataset && mountEl.dataset.extraParams) {
            try {
                const extra = JSON.parse(mountEl.dataset.extraParams);
                Object.keys(extra).forEach(key => {
                    const value = extra[key];
                    if (value !== null && value !== undefined && value !== '') {
                        params.set(key, value);
                    }
                });
            } catch (e) {
                // malformed extra params are ignored
            }
        }

        return base + '?' + params.toString();
    }, [checkIn, checkOut, rooms, mode, hotelId, productId, searchQuery, searchDispatch, provider, totalAdults, totalChildren]);

    const performAjaxSearch = useCallback((url) => {
        setIsSearching(true);
        setFetchError('');
        setShowCalendar(false);
        setShowGuests(false);

        const fetchUrl = url + (url.includes('?') ? '&' : '?') + '_t=' + Date.now();
        const maxRetries = 2;
        const FETCH_TIMEOUT_MS = 30000;

        const attemptFetch = (attempt) => {
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), FETCH_TIMEOUT_MS);

            fetch(fetchUrl, { signal: controller.signal })
                .then(r => {
                    if (!r.ok) throw new Error(`HTTP ${r.status}`);
                    return r.text();
                })
                .then(html => {
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(html, 'text/html');

                    // Use generic .travel-search-results-page selector
                    const newPage = doc.querySelector('.travel-search-results-page');
                    const curPage = document.querySelector('.travel-search-results-page');

                    if (!newPage || !curPage) {
                        window.location.href = fetchUrl;
                        return;
                    }

                    const curForm = curPage.querySelector('.travel-search-form-wrapper');
                    const newForm = newPage.querySelector('.travel-search-form-wrapper');

                    if (!curForm || !newForm) {
                        window.location.href = fetchUrl;
                        return;
                    }

                    while (curForm.nextSibling) {
                        curForm.nextSibling.remove();
                    }

                    const fragment = document.createDocumentFragment();
                    let node = newForm.nextSibling;
                    while (node) {
                        fragment.appendChild(document.importNode(node, true));
                        node = node.nextSibling;
                    }
                    curPage.appendChild(fragment);

                    // Re-execute scripts from the fetched same-origin page.
                    curPage.querySelectorAll('script').forEach(oldScript => {
                        if (oldScript.closest('.travel-search-form-wrapper')) return;

                        if (oldScript.src) {
                            try {
                                const scriptUrl = new URL(oldScript.src, window.location.origin);
                                if (scriptUrl.origin !== window.location.origin) {
                                    oldScript.remove();
                                    return;
                                }
                            } catch {
                                oldScript.remove();
                                return;
                            }
                            if (oldScript.src.includes('react19') || oldScript.src.includes('dob-validation')) return;
                        }

                        const newScript = document.createElement('script');
                        Array.from(oldScript.attributes).forEach(attr => {
                            newScript.setAttribute(attr.name, attr.value);
                        });
                        newScript.textContent = oldScript.textContent;
                        oldScript.parentNode.replaceChild(newScript, oldScript);
                    });

                    const resultsTop = curForm.getBoundingClientRect().bottom + window.pageYOffset - 20;
                    window.scrollTo({ top: resultsTop, behavior: 'smooth' });

                    // Inline mode keeps the visitor on the product page: write
                    // the booking params onto the CURRENT (products.view / SEO)
                    // URL instead of the results dispatch, so reload restores
                    // the same inline view via the auto-restore effect.
                    let historyUrl = url;
                    if (inlineResults) {
                        const cur = new URL(window.location.href);
                        const next = new URL(url, window.location.origin);
                        INLINE_SEARCH_KEYS.forEach((k) => {
                            const v = next.searchParams.get(k);
                            if (v === null) {
                                cur.searchParams.delete(k);
                            } else {
                                cur.searchParams.set(k, v);
                            }
                        });
                        // One-shot cache-eviction flag (expired-offer redirects
                        // land with refresh=1) — never persist it in history or
                        // every later reload would force a live re-search.
                        cur.searchParams.delete('refresh');
                        historyUrl = cur.toString();
                    }
                    window.history.pushState({}, '', historyUrl);
                    if (inlineResults) rememberInlineSearch(productId, url);

                    // Swapped-in provider markup may need re-arming (sphinx
                    // polling reads its fresh search-id from the new nodes).
                    document.dispatchEvent(new CustomEvent('travel:results-swapped'));

                    setHasSearched(true);
                    setParamsChanged(false);
                    setIsSearching(false);
                })
                .catch((err) => {
                    if (err.name === 'AbortError') {
                        setIsSearching(false);
                        setFetchError(t('searchTimeout', 'Search timed out. Please try again.'));
                        return;
                    }
                    if (attempt < maxRetries) {
                        retryTimerRef.current = setTimeout(() => attemptFetch(attempt + 1), 1000 * Math.pow(2, attempt));
                    } else {
                        setIsSearching(false);
                        setFetchError(t('searchFailed', 'Search failed. Please try again.'));
                    }
                })
                .finally(() => clearTimeout(timeoutId));
        };

        attemptFetch(0);
    }, [inlineResults, productId]);

    const handleSearch = useCallback(() => {
        setFetchError('');
        if (!checkIn || !checkOut) {
            setValidationError(t('pleaseEnterDates', 'Please select check-in and check-out dates'));
            setShowCalendar(false);
            setShowGuests(false);
            return;
        }

        const errors = [];
        rooms.forEach((room, roomIdx) => {
            if (room.children > 0) {
                (room.childrenAges || []).forEach((age, childIdx) => {
                    if (age === null || age === undefined || age === '') {
                        errors.push({ room: roomIdx, child: childIdx });
                    }
                });
            }
        });

        if (errors.length > 0) {
            setAgeErrors(errors);
            setShowGuests(true);
            setShowCalendar(false);
            return;
        }

        setValidationError('');

        const url = buildSearchUrl();

        if (mode === 'search' || inlineResults) {
            performAjaxSearch(url);
            return;
        }

        window.location.href = url + '&_t=' + Date.now();
    }, [checkIn, checkOut, rooms, mode, inlineResults, buildSearchUrl, performAjaxSearch]);

    const handleButtonClick = useCallback(() => {
        if (isSearching) return;
        handleSearch();
    }, [handleSearch, isSearching]);

    // Inline-mode restore: a product URL carrying both dates is a pushed or
    // shared search URL (plain product visits never have them) — re-run that
    // search once on mount so a reload shows the results again instead of
    // just the prefilled form. Bypasses handleSearch validation on purpose:
    // the params already passed it when the URL was created.
    //
    // A product URL WITHOUT dates can still be a reload of a search: the
    // language and currency switchers link to the URL the server rendered,
    // from before the search was pushed into the address bar. The last
    // inline search of this product (this tab, recent, not in the past) is
    // then restored from sessionStorage, so switching language keeps the
    // results — and the rooms chosen in them (multiroom-booking.js).
    const didAutoSearchRef = useRef(false);
    useEffect(() => {
        if (!inlineResults || didAutoSearchRef.current) return;
        if (!checkIn || !checkOut) {
            const saved = recallInlineSearch(productId);
            if (!saved) return;
            didAutoSearchRef.current = true;
            setCheckIn(parseDate(saved.get('check_in')));
            setCheckOut(parseDate(saved.get('check_out')));
            try {
                const savedRooms = JSON.parse(saved.get('rooms_data') || '[]');
                if (Array.isArray(savedRooms) && savedRooms.length > 0) setRooms(savedRooms);
            } catch (_) { /* keep the default rooms */ }
            const restoreUrl = new URL(buildSearchUrl());
            INLINE_SEARCH_KEYS.forEach((k) => {
                const v = saved.get(k);
                if (v === null) {
                    restoreUrl.searchParams.delete(k);
                } else {
                    restoreUrl.searchParams.set(k, v);
                }
            });
            performAjaxSearch(restoreUrl.toString());
            return;
        }
        didAutoSearchRef.current = true;
        // refresh=1 arrives on expired-offer redirects from the booking form:
        // forward it once so the provider search evicts its cached result set
        // and runs live (the history writer drops it again afterwards).
        const wantsRefresh = new URLSearchParams(window.location.search).get('refresh') === '1';
        performAjaxSearch(buildSearchUrl() + (wantsRefresh ? '&refresh=1' : ''));
        // Mount-only by design (empty deps): the initial dates come from the
        // URL via config and must fire exactly once.
    }, []);

    // -----------------------------------------------------------------------
    // Render helpers
    // -----------------------------------------------------------------------

    const renderAvailabilityHeader = () => {
        if (mode === 'search') return null;

        return (
            <div className="nvt-availability-header">
                <h2 className="nvt-availability-title">
                    {t('availability', 'Availability')}
                </h2>
                {!checkIn && (
                    <p className="nvt-availability-subtitle">
                        {t('selectDatesMessage', 'Select dates to see this property\'s availability and prices')}
                    </p>
                )}
            </div>
        );
    };

    const renderSearchHeader = () => {
        if (mode !== 'search') return null;

        return (
            <div className="nvt-availability-header">
                <h2 className="nvt-availability-title" style={{ color: '#000' }}>
                    {t('availability', 'Availability')}
                </h2>
            </div>
        );
    };

    // -----------------------------------------------------------------------
    // Main render
    // -----------------------------------------------------------------------

    const searchBtnText = useMemo(() => {
        if (isSearching) return t('searching', 'Searching...');
        if (buttonText) return buttonText;
        if (hasSearched && paramsChanged) return t('applyChanges', 'Apply changes');
        if (hasSearched) return t('changeSearch', 'Change search');
        return t('search', 'Search');
    }, [isSearching, buttonText, hasSearched, paramsChanged]);

    return (
        <div className="nvt-booking-engine" ref={engineRef}>
            {renderAvailabilityHeader()}
            {renderSearchHeader()}

            <div className="nvt-form-row">
                {/* Homepage location input */}
                {mode === 'homepage' && (
                    <div className="nvt-field nvt-field--location">
                        <div className="nvt-field-input nvt-field-input--location">
                            <input
                                type="text"
                                className="nvt-homepage-input"
                                value={searchQuery}
                                onChange={e => setSearchQuery(e.target.value)}
                                placeholder={t('whereAreYouGoing', 'Where are you going?')}
                                aria-label={t('whereAreYouGoing', 'Where are you going?')}
                            />
                        </div>
                    </div>
                )}

                {/* Date field */}
                <div className={`nvt-field nvt-field--date${validationError ? ' nvt-field--error' : ''}`}>
                    <button
                        ref={dateTriggerRef}
                        type="button"
                        className="nvt-field-input"
                        onClick={() => { setShowCalendar(!showCalendar); setShowGuests(false); setValidationError(''); }}
                        aria-expanded={showCalendar}
                        aria-haspopup="dialog"
                    >
                        <span className="nvt-field-input-icon"><CalendarIcon /></span>
                        <span className="nvt-field-input-text">
                            {dateDisplayText ? (
                                <span className="nvt-value">{dateDisplayText}</span>
                            ) : (
                                <span className="nvt-value nvt-value--placeholder">
                                    {`${t('checkIn', 'Check-in')} \u2192 ${t('checkOut', 'Check-out')}`}
                                </span>
                            )}
                        </span>
                        <span className="nvt-field-input-arrow"><ChevronDown /></span>
                    </button>

                    {showCalendar && (
                        <Calendar
                            checkIn={checkIn}
                            checkOut={checkOut}
                            onSelect={handleDateSelect}
                            onClose={() => setShowCalendar(false)}
                            prices={calendarPrices.prices}
                            pricesCurrency={calendarPrices.currency}
                            triggerRef={dateTriggerRef}
                        />
                    )}

                    {validationError && !showCalendar && (
                        <div className="nvt-validation-message">
                            {validationError}
                        </div>
                    )}
                </div>

                {/* Guests field */}
                <div className="nvt-field nvt-field--guests">
                    <button
                        ref={guestTriggerRef}
                        type="button"
                        className="nvt-field-input"
                        onClick={() => { setShowGuests(!showGuests); setShowCalendar(false); }}
                        aria-expanded={showGuests}
                        aria-haspopup="dialog"
                    >
                        <span className="nvt-field-input-icon"><GuestIcon /></span>
                        <span className="nvt-field-input-text">
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

                {/* Search button */}
                <div className="nvt-field nvt-field--btn">
                    <button
                        type="button"
                        className="nvt-btn-search"
                        onClick={handleButtonClick}
                        disabled={isSearching}
                        style={isSearching ? { opacity: 0.7, cursor: 'wait' } : undefined}
                    >
                        {searchBtnText}
                    </button>
                </div>
            </div>

            {/* Fetch error message */}
            {fetchError && (
                <div className="nvt-fetch-error">
                    <span className="nvt-warning-icon">!</span>
                    {fetchError}
                </div>
            )}

            {/* Loading spinner during AJAX search */}
            {isSearching && (
                <div className="nvt-search-loading">
                    <span className="nvt-spinner" />
                    <span>{t('searching', 'Searching...')}</span>
                </div>
            )}
        </div>
    );
}
