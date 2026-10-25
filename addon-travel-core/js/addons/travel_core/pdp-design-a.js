/**
 * Travel Core — hotel page Design A
 * (blocks/product_templates/travelcore_design_a.tpl).
 *
 *  - Photo mosaic: a tile opens the theme's image previewer through the
 *    matching link of the stock gallery the template keeps out of sight.
 *    With no previewer link the tile keeps its own href (the photo itself).
 *  - "Alege datele" / "Modifică": scroll to the search bar and open the
 *    calendar.
 *  - Compact search summary: after a search, once the bar has scrolled away,
 *    a fixed strip shows the dates and guests with "Modifică" and "Camere".
 *
 * Document-level listeners: the results arrive later (inline swap) and keep
 * working without re-initialising.
 */
(function () {
    'use strict';

    if (window.TravelPdpDesignA) {
        return;
    }

    var SCROLL_GAP = 16;

    function toInt(value) {
        var n = parseInt(value, 10);
        return isNaN(n) || n < 0 ? 0 : n;
    }

    /** 'YYYY-MM-DD' as a local date, or null. */
    function parseDay(value) {
        var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value || '');
        if (!m) {
            return null;
        }
        var day = new Date(+m[1], +m[2] - 1, +m[3]);
        return day.getMonth() === +m[2] - 1 ? day : null;
    }

    function formatDates(checkIn, checkOut, locale, today) {
        var start = parseDay(checkIn);
        var end = parseDay(checkOut);
        if (!start || !end || end <= start) {
            return '';
        }
        var options = { day: 'numeric', month: 'short' };
        var thisYear = (today || new Date()).getFullYear();
        if (start.getFullYear() !== thisYear || end.getFullYear() !== thisYear) {
            options.year = 'numeric';
        }
        var format;
        try {
            format = new Intl.DateTimeFormat(locale || undefined, options);
        } catch (e) {
            format = new Intl.DateTimeFormat(undefined, options);
        }
        if (typeof format.formatRange === 'function') {
            return format.formatRange(start, end);
        }
        return format.format(start) + ' – ' + format.format(end);
    }

    function counted(n, one, many) {
        return n + ' ' + String(n === 1 ? one : many).toLowerCase();
    }

    /** Guests from rooms_data when present (the engine's source of truth), else the totals. */
    function guestTotals(params) {
        var totals = {
            adults: toInt(params.get('adults')),
            children: toInt(params.get('children')),
            rooms: Math.max(1, toInt(params.get('rooms'))),
        };
        var raw = params.get('rooms_data');
        if (!raw) {
            return totals;
        }
        try {
            var rooms = JSON.parse(raw);
            if (Array.isArray(rooms) && rooms.length > 0) {
                totals = { adults: 0, children: 0, rooms: rooms.length };
                rooms.forEach(function (room) {
                    totals.adults += toInt(room && room.adults);
                    totals.children += toInt(room && room.children);
                });
            }
        } catch (e) {
            // malformed rooms_data: keep the flat totals
        }
        return totals;
    }

    /**
     * "26–31 oct. · 2 adulți · 1 copil · 2 camere" from a search's URL
     * parameters; '' when the dates are missing or invalid.
     *
     * @param {URLSearchParams} params
     * @param {{adult: string, adults: string, child: string, children: string, room: string, rooms: string}} labels
     * @param {string} [locale]
     * @param {Date} [today]
     */
    function formatSummary(params, labels, locale, today) {
        var dates = formatDates(params.get('check_in'), params.get('check_out'), locale, today);
        if (dates === '') {
            return '';
        }
        var guests = guestTotals(params);
        var parts = [dates];
        if (guests.adults > 0) {
            parts.push(counted(guests.adults, labels.adult, labels.adults));
        }
        if (guests.children > 0) {
            parts.push(counted(guests.children, labels.child, labels.children));
        }
        if (guests.rooms > 1) {
            parts.push(counted(guests.rooms, labels.room, labels.rooms));
        }
        return parts.join(' · ');
    }

    function prefersReducedMotion() {
        return typeof window.matchMedia === 'function'
            && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    }

    function scrollToElement(el) {
        var top = el.getBoundingClientRect().top + window.pageYOffset - SCROLL_GAP;
        window.scrollTo({ top: Math.max(0, top), behavior: prefersReducedMotion() ? 'auto' : 'smooth' });
    }

    /** The theme previewer's links, in gallery order (main photo first). */
    function previewerLinks(page) {
        return page.querySelectorAll('.travel-pdpa-stock-gallery a.cm-image-previewer');
    }

    function openPhoto(page, index) {
        var links = previewerLinks(page);
        var link = links[index] || links[0];
        if (!link) {
            return false;
        }
        link.click();
        return true;
    }

    function openDates() {
        var root = document.getElementById('travel-booking-root');
        if (!root) {
            return false;
        }
        scrollToElement(root.closest('.travel-search-form-wrapper') || root);
        var trigger = root.querySelector('.nvt-field--date .nvt-field-input');
        if (trigger && trigger.getAttribute('aria-expanded') !== 'true') {
            trigger.click();
        }
        return true;
    }

    /** The first node the inline search swapped in after the bar, or null. */
    function firstResult(page) {
        var bar = page.querySelector('.travel-pdp-results .travel-search-form-wrapper');
        return bar ? bar.nextElementSibling : null;
    }

    function gotoResults(page) {
        var first = firstResult(page);
        if (!first) {
            return false;
        }
        scrollToElement(first);
        return true;
    }

    function labelsOf(compact) {
        return {
            adult: compact.getAttribute('data-label-adult') || 'adult',
            adults: compact.getAttribute('data-label-adults') || 'adults',
            child: compact.getAttribute('data-label-child') || 'child',
            children: compact.getAttribute('data-label-children') || 'children',
            room: compact.getAttribute('data-label-room') || 'room',
            rooms: compact.getAttribute('data-label-rooms') || 'rooms',
        };
    }

    function refreshSummary(compact) {
        var summary = compact.querySelector('[data-travel-pdpa-summary]');
        if (!summary) {
            return;
        }
        var locale = document.documentElement.getAttribute('lang') || undefined;
        summary.textContent = formatSummary(new URLSearchParams(window.location.search), labelsOf(compact), locale);
    }

    function initCompact(page) {
        var compact = page.querySelector('[data-travel-pdpa-compact]');
        var bar = page.querySelector('.travel-pdp-results .travel-search-form-wrapper');
        if (!compact || !bar || typeof window.IntersectionObserver !== 'function') {
            return;
        }
        var barAway = false;
        var update = function () {
            var show = barAway && firstResult(page) !== null;
            if (show) {
                refreshSummary(compact);
            }
            compact.hidden = !show;
        };
        new window.IntersectionObserver(function (entries) {
            var entry = entries[entries.length - 1];
            barAway = !entry.isIntersecting && entry.boundingClientRect.top < 0;
            update();
        }).observe(bar);
        document.addEventListener('travel:results-swapped', update);
    }

    function init() {
        Array.prototype.forEach.call(document.querySelectorAll('.travel-pdpa'), function (page) {
            if (page.getAttribute('data-travel-pdpa-ready') === '1') {
                return;
            }
            page.setAttribute('data-travel-pdpa-ready', '1');
            // No booking form on this product (not a supplier hotel, or the
            // form is switched off): nothing for "Alege datele" to open.
            if (!document.getElementById('travel-booking-root')) {
                Array.prototype.forEach.call(page.querySelectorAll('.travel-pdpa-head__dates'), function (button) {
                    button.hidden = true;
                });
            }
            initCompact(page);
        });
    }

    document.addEventListener('click', function (event) {
        var target = event.target instanceof Element ? event.target : null;
        var page = target ? target.closest('.travel-pdpa') : null;
        if (!page) {
            return;
        }
        var tile = target.closest('[data-travel-gallery-index]');
        if (tile) {
            if (openPhoto(page, toInt(tile.getAttribute('data-travel-gallery-index')))) {
                event.preventDefault();
            }
            return;
        }
        if (target.closest('[data-travel-open-dates]')) {
            if (openDates()) {
                event.preventDefault();
            }
            return;
        }
        if (target.closest('[data-travel-goto-results]') && gotoResults(page)) {
            event.preventDefault();
        }
    });

    window.TravelPdpDesignA = {
        init: init,
        formatSummary: formatSummary,
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
