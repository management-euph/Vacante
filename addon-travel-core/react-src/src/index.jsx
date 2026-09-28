/**
 * Travel Booking Engine - Entry point
 *
 * Auto-initializes React roots on mount-points found via
 * [data-travel-booking] attribute and exposes the public API
 * on window.TravelBooking.
 *
 * Provider addons (novoton, sphinx) set data-search-dispatch on
 * the mount element to control which controller handles the search.
 */

import { createRoot } from 'react-dom/client';
import { Component } from 'react';
import BookingEngine from './BookingEngine';
import Calendar from './Calendar';
import GuestPicker from './GuestPicker';
import { getLocale, parseDate, toDateString, t } from './utils';

// Conditional debug logger — only outputs when ?travel_debug=1 is in URL
const DEBUG = typeof window !== 'undefined' && new URLSearchParams(window.location.search).has('travel_debug');
function dbg(...args) { if (DEBUG) console.log('[TravelBooking]', ...args); }

class ErrorBoundary extends Component {
    constructor(props) {
        super(props);
        this.state = { hasError: false };
    }
    static getDerivedStateFromError(error) {
        return { hasError: true, error };
    }
    componentDidCatch(error, info) {
        if (typeof window !== 'undefined' && window.fn_log_event) {
            window.fn_log_event('TravelBooking ErrorBoundary', error.message);
        }
    }
    render() {
        if (this.state.hasError) {
            return (
                <div className="nvt-error-boundary">
                    {t('componentError', 'Something went wrong. Please reload the page.')}
                </div>
            );
        }
        return this.props.children;
    }
}

/**
 * Read data-* attributes from an element and build a config object.
 * URL query parameters take priority for booking fields (check_in, check_out,
 * adults, children, rooms, etc.) so the search-results form always reflects
 * the URL.
 */
function readConfig(el) {
    const url = new URLSearchParams(window.location.search);

    const str  = (urlKey, dataKey) => url.get(urlKey) || el.dataset[dataKey] || '';
    const num  = (urlKey, dataKey, fallback) => {
        const fromUrl  = url.get(urlKey);
        if (fromUrl !== null && fromUrl !== '') return parseInt(fromUrl, 10);
        const fromData = el.dataset[dataKey];
        if (fromData !== undefined && fromData !== '') return parseInt(fromData, 10);
        return fallback;
    };

    // Calendar prices: JSON map from data-calendar-prices attribute
    let calendarPrices = null;
    let calendarPricesCurrency = '';
    if (el.dataset.calendarPrices) {
        try {
            calendarPrices = JSON.parse(el.dataset.calendarPrices);
            calendarPricesCurrency = el.dataset.calendarPricesCurrency || '';
        } catch (e) { dbg('calendar prices JSON parse failed:', e.message); }
    }

    return {
        provider:            el.dataset.provider || '',
        hotelId:             str('hotel_id',   'hotelId'),
        productId:           str('product_id', 'productId'),
        mode:                el.dataset.mode || 'product',
        searchDispatch:      el.dataset.searchDispatch || '',
        initialCheckIn:      str('check_in',       'checkIn'),
        initialCheckOut:     str('check_out',      'checkOut'),
        initialAdults:       num('adults',         'adults',       2),
        initialChildren:     num('children',       'children',     0),
        initialChildrenAges: str('children_ages',  'childrenAges'),
        initialRooms:        num('rooms',          'rooms',        1),
        maxRooms:            parseInt(el.dataset.maxRooms, 10)     || 12,
        maxAdults:           parseInt(el.dataset.maxAdults, 10)    || 9,
        maxChildren:         parseInt(el.dataset.maxChildren, 10)  || 4,
        buttonText:          el.dataset.buttonText || '',
        roomsData:           url.get('rooms_data') || el.dataset.roomsData || '',
        calendarPrices,
        calendarPricesCurrency,
        // The mount element itself, so the engine can read LIVE attributes at
        // search time — providers without dedicated engine fields (eurosite's
        // country/city selects) maintain a JSON map in data-extra-params and
        // it must reflect the current selection, not the initial render.
        mountEl:             el,
    };
}

/**
 * CSS variable name mapping for color overrides.
 * Keys match the JSON keys in data-colors attribute.
 */
const COLOR_CSS_MAP = {
    primary:      '--nvt-primary',
    accent:       '--nvt-accent',
    text:         '--nvt-text',
    textLight:    '--nvt-text-light',
    bg:           '--nvt-bg',
    border:       '--nvt-border',
    btnBg:        '--nvt-search-btn-bg',
    btnHover:     '--nvt-search-btn-hover',
    btnText:      '--nvt-search-btn-text',
    calCheapest:  '--nvt-cal-cheapest-color',
    calPrice:     '--nvt-cal-price-color',
    danger:       '--nvt-danger',
};

/**
 * Apply color overrides as CSS custom properties on :root.
 * Accepts either a colors object or reads from data-colors attribute.
 */
function applyColorsFromObject(colors) {
    if (!colors || typeof colors !== 'object') return;
    const root = document.documentElement;
    for (const [key, cssVar] of Object.entries(COLOR_CSS_MAP)) {
        const value = colors[key];
        if (value && typeof value === 'string' && value.trim()) {
            root.style.setProperty(cssVar, value.trim());
        }
    }
}

function applyColors(el) {
    const raw = el.dataset.colors;
    if (!raw) return;
    try {
        applyColorsFromObject(JSON.parse(raw));
    } catch (e) { dbg('colors JSON parse failed:', e.message); }
}

/**
 * Parse data-translations JSON attribute and merge into
 * window.TravelTranslations so the t() helper can find them.
 */
function loadTranslations(el) {
    const raw = el.dataset.translations;
    if (!raw) return;
    try {
        const parsed = JSON.parse(raw);
        window.TravelTranslations = Object.assign(window.TravelTranslations || {}, parsed);
    } catch (e) { dbg('translations JSON parse failed:', e.message); }
}

/**
 * Fetch booking engine config from server via AJAX.
 * Used when the mount point only has data-product-id (no inline config).
 * Caches per product_id to avoid duplicate fetches on the same page.
 */
const _configCache = {};
async function fetchConfig(productId) {
    if (_configCache[productId]) {
        dbg('fetchConfig: cache hit for', productId);
        return _configCache[productId];
    }

    const baseUrl = (window.Tygh?.current_location || window.location.origin) + '/index.php';
    const url = `${baseUrl}?dispatch=travel_booking.booking_config&product_id=${encodeURIComponent(productId)}`;
    dbg('fetchConfig: GET', url);

    try {
        const resp = await fetch(url);
        dbg('fetchConfig: status', resp.status, resp.statusText);
        if (!resp.ok) {
            dbg('fetchConfig: HTTP error', resp.status);
            return null;
        }
        const text = await resp.text();
        dbg('fetchConfig: response length', text.length, 'chars');
        try {
            const data = JSON.parse(text);
            _configCache[productId] = data;
            return data;
        } catch (parseErr) {
            dbg('fetchConfig: JSON parse error:', parseErr.message, 'Response:', text.substring(0, 500));
            return null;
        }
    } catch (err) {
        dbg('fetchConfig: network error:', err);
        return null;
    }
}

/**
 * Render a booking engine into a mount point element.
 */
function renderMount(el, config) {
    if (!config.mode) config.mode = el.dataset.travelBooking || 'product';
    createRoot(el).render(<ErrorBoundary><BookingEngine config={config} /></ErrorBoundary>);
}

/**
 * Main initialisation function.
 * Supports two modes:
 *   1. Inline config: data-provider, data-colors, data-translations on the element
 *      (used by search results pages and legacy templates)
 *   2. AJAX config: only data-product-id on the element — fetches everything from
 *      travel_booking.booking_config endpoint (used by product detail pages to
 *      avoid Smarty scope chain crash)
 */
function init() {
    const mountPoints = document.querySelectorAll('[data-travel-booking]');
    dbg('init: found', mountPoints.length, 'mount point(s)');

    mountPoints.forEach((el, idx) => {
        // Guard: skip already-initialized mount points (prevents double-init
        // when scripts are accidentally loaded twice or init() is called again).
        if (el.dataset.travelInitialized) {
            dbg('mount #' + idx + ': already initialized, skipping');
            return;
        }
        el.dataset.travelInitialized = 'true';

        dbg('mount #' + idx, {
            provider: el.dataset.provider || '(none)',
            productId: el.dataset.productId || '(none)',
            allDataAttrs: Object.keys(el.dataset)
        });

        if (el.dataset.provider) {
            // ── Inline mode: all config in data attributes ──
            dbg('→ inline mode (data-provider present)');
            loadTranslations(el);
            applyColors(el);
            const config = readConfig(el);
            renderMount(el, config);
        } else if (el.dataset.productId) {
            // ── AJAX mode: fetch config from server ──
            const pid = el.dataset.productId;
            dbg('→ AJAX mode, fetching config for product_id=' + pid);
            fetchConfig(pid).then(serverConfig => {
                dbg('fetchConfig response:', serverConfig);

                if (!serverConfig || !serverConfig.isHotel) {
                    dbg('Not a hotel product or fetch failed — clearing mount. Response:', JSON.stringify(serverConfig));
                    el.innerHTML = '';
                    return;
                }

                // Apply colors and translations from server response
                applyColorsFromObject(serverConfig.colors);
                window.TravelTranslations = Object.assign(
                    window.TravelTranslations || {},
                    serverConfig.translations || {}
                );

                // Merge server config with any URL params (for search mode)
                const url = new URLSearchParams(window.location.search);
                const config = {
                    provider:            serverConfig.provider,
                    hotelId:             url.get('hotel_id') || serverConfig.hotelId,
                    productId:           String(serverConfig.productId),
                    mode:                serverConfig.mode || 'product',
                    inlineResults:       !!serverConfig.inlineResults,
                    searchDispatch:      serverConfig.searchDispatch,
                    initialCheckIn:      url.get('check_in') || '',
                    initialCheckOut:     url.get('check_out') || '',
                    initialAdults:       parseInt(url.get('adults'), 10) || 2,
                    initialChildren:     parseInt(url.get('children'), 10) || 0,
                    initialChildrenAges: url.get('children_ages') || '',
                    initialRooms:        parseInt(url.get('rooms'), 10) || 1,
                    maxRooms:            parseInt(serverConfig.maxRooms, 10) || 12,
                    maxAdults:           9,
                    maxChildren:         4,
                    buttonText:          '',
                    roomsData:           url.get('rooms_data') || '',
                    calendarPrices:      null,
                    calendarPricesCurrency: '',
                };
                dbg('Rendering with config:', config);
                renderMount(el, config);
            }).catch(err => {
                dbg('fetchConfig error:', err);
            });
        } else {
            dbg('mount #' + idx + ': no data-provider or data-product-id — skipping');
        }
    });
}

// ---------------------------------------------------------------------------
// Auto-initialize on DOM ready
// ---------------------------------------------------------------------------

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}

// ---------------------------------------------------------------------------
// Public API
// ---------------------------------------------------------------------------

window.TravelBooking = {
    init,
    BookingEngine,
    Calendar,
    GuestPicker,
    getLocale,
    parseDate,
    toDateString,
};

