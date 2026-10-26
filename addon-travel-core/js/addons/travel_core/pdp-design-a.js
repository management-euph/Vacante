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
 *  - Photo height: the photos give way so the search bar ends on the first
 *    screen, whatever the theme's header takes (design-a.css carries a
 *    CSS-only estimate; phones keep their own layout).
 *  - Room-first results (components/design_a_results.tpl): the meal strip
 *    and the chips filter the rates, "see all" opens a room's other rates,
 *    the sort orders the rooms.
 *  - Photo fit: each photo fills its tile, unless it is too small for it
 *    (filling would enlarge it more than 1.5×) or too tall (filling would
 *    crop away more than half of it); that one shows whole, at most 1.5×
 *    its own size, over a blurred copy of itself (design-a.css,
 *    .travel-pdpa-gallery__tile--whole).
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
    var GALLERY_MAX = 480;
    var GALLERY_MIN = 240;
    var BAR_FALLBACK = 64;
    var MAX_ENLARGE = 1.5;
    var MIN_SHOWN = 0.5;
    // Below this the image is a placeholder (CS-Cart's spacer while a
    // thumbnail is generated), not the photo.
    var MIN_PHOTO = 10;

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

    /** Sizes the photos so the bar ends on the first screen (desktop). */
    function fitGallery(page) {
        var gallery = page.querySelector('.travel-pdpa-gallery');
        if (!gallery) {
            return;
        }
        if (typeof window.matchMedia === 'function' && window.matchMedia('(max-width: 767px)').matches) {
            page.style.removeProperty('--travel-pdpa-gallery-h');
            return;
        }
        var form = page.querySelector('.travel-pdp-results .travel-search-form-wrapper');
        var row = form ? form.querySelector('.nvt-form-row') : null;
        var box = gallery.getBoundingClientRect();
        var top = box.top + window.pageYOffset;
        var gap = form ? Math.max(0, form.getBoundingClientRect().top - box.bottom) : SCROLL_GAP;
        var bar = row && row.offsetHeight > 0 ? row.offsetHeight : BAR_FALLBACK;
        var room = Math.floor(window.innerHeight - top - gap - bar - 8);
        page.style.setProperty('--travel-pdpa-gallery-h', Math.max(GALLERY_MIN, Math.min(GALLERY_MAX, room)) + 'px');
    }

    /**
     * True when a photo should show whole in its tile: filling the tile
     * (object-fit: cover) would enlarge it more than MAX_ENLARGE, or leave
     * less than MIN_SHOWN of it in view.
     */
    function showsWhole(tileWidth, tileHeight, photoWidth, photoHeight) {
        var scale = Math.max(tileWidth / photoWidth, tileHeight / photoHeight);
        var shown = (tileWidth * tileHeight) / (photoWidth * photoHeight * scale * scale);
        return scale > MAX_ENLARGE || shown < MIN_SHOWN;
    }

    function fitPhoto(tile) {
        var photo = tile.querySelector('img:not(.travel-pdpa-gallery__backdrop)');
        if (!photo || !photo.complete || photo.naturalWidth < MIN_PHOTO || photo.naturalHeight < MIN_PHOTO) {
            return;
        }
        // A tile the layout hides (phones show the main photo only).
        if (tile.clientWidth === 0 || tile.clientHeight === 0) {
            return;
        }
        var whole = showsWhole(tile.clientWidth, tile.clientHeight, photo.naturalWidth, photo.naturalHeight);
        if (whole) {
            var backdrop = tile.querySelector('.travel-pdpa-gallery__backdrop');
            if (!backdrop) {
                backdrop = document.createElement('img');
                backdrop.className = 'travel-pdpa-gallery__backdrop';
                backdrop.alt = '';
                backdrop.setAttribute('aria-hidden', 'true');
                photo.parentNode.insertBefore(backdrop, photo);
            }
            var src = photo.currentSrc || photo.src;
            if (backdrop.getAttribute('src') !== src) {
                backdrop.setAttribute('src', src);
            }
            tile.style.setProperty('--travel-pdpa-whole-w', Math.round(photo.naturalWidth * MAX_ENLARGE) + 'px');
            tile.style.setProperty('--travel-pdpa-whole-h', Math.round(photo.naturalHeight * MAX_ENLARGE) + 'px');
        }
        tile.classList.toggle('travel-pdpa-gallery__tile--whole', whole);
    }

    function fitPhotos(page) {
        Array.prototype.forEach.call(page.querySelectorAll('.travel-pdpa-gallery__tile'), fitPhoto);
    }

    function fitGalleries() {
        Array.prototype.forEach.call(document.querySelectorAll('.travel-pdpa'), function (page) {
            fitGallery(page);
            fitPhotos(page);
        });
    }

    /*
     * Room-first results (components/design_a_results.tpl): the meal strip
     * and the chips filter the rates, "see all" opens a room's other rates,
     * the sort orders the rooms. Visibility is worked out again from that
     * state on every change, so the controls combine.
     */

    function each(root, selector, fn) {
        Array.prototype.forEach.call(root.querySelectorAll(selector), fn);
    }

    function pressed(section, selector, attribute) {
        var keys = [];
        each(section, selector, function (control) {
            if (control.getAttribute('aria-pressed') === 'true') {
                keys.push(control.getAttribute(attribute) || '');
            }
        });
        return keys;
    }

    function filterRates(section) {
        var meal = pressed(section, '[data-travel-meal]', 'data-travel-meal')[0] || '';
        var chips = pressed(section, '[data-travel-chip]', 'data-travel-chip');
        var filtering = meal !== '' || chips.length > 0;
        var roomsShown = 0;

        each(section, '[data-travel-room]', function (room) {
            var open = room.hasAttribute('data-travel-open');
            var collapsed = room.querySelector('[data-travel-compact]') !== null;
            var shown = 0;
            each(room, '[data-travel-rates] [data-travel-rate]', function (row) {
                var show = filtering
                    ? (meal === '' || row.getAttribute('data-meal') === meal) && chips.every(function (key) {
                        return row.getAttribute('data-' + key) === '1';
                    })
                    : open || !row.hasAttribute('data-travel-more');
                row.hidden = !show;
                shown += show ? 1 : 0;
            });
            each(room, '[data-travel-group]', function (group) {
                group.hidden = group.querySelector('[data-travel-rate]:not([hidden])') === null;
            });
            each(room, '[data-travel-rates]', function (list) {
                list.hidden = !(filtering || open || !collapsed);
            });
            each(room, '[data-travel-compact]', function (compact) {
                compact.hidden = filtering || open;
            });
            each(room, '[data-travel-see-all]', function (button) {
                button.hidden = filtering || open;
            });
            room.hidden = filtering && shown === 0;
            roomsShown += room.hidden ? 0 : 1;
        });

        each(section, '[data-travel-no-match]', function (empty) {
            empty.hidden = roomsShown > 0;
        });
    }

    function pickMeal(section, key) {
        each(section, '[data-travel-meal]', function (button) {
            button.setAttribute('aria-pressed', (button.getAttribute('data-travel-meal') || '') === key ? 'true' : 'false');
        });
    }

    function seeAll(button) {
        var room = button.closest('[data-travel-room]');
        var section = button.closest('[data-travel-results]');
        if (!room || !section) {
            return;
        }
        room.setAttribute('data-travel-open', '');
        button.setAttribute('aria-expanded', 'true');
        filterRates(section);
        // The button is gone: carry the focus to the room's first rate.
        var first = room.querySelector('[data-travel-rates] [data-travel-rate] a');
        if (first) {
            first.focus();
        }
    }

    /** Rooms by their cheapest rate: "low" first or "high" first. */
    function sortRooms(section, order) {
        var list = section.querySelector('[data-travel-room-list]');
        if (!list) {
            return;
        }
        var rooms = Array.prototype.slice.call(list.querySelectorAll('[data-travel-room]'));
        var from = function (room) {
            return parseFloat(room.getAttribute('data-from')) || 0;
        };
        rooms.map(function (room, index) {
            return { room: room, index: index };
        }).sort(function (a, b) {
            var diff = from(a.room) - from(b.room);
            return (order === 'high' ? -diff : diff) || a.index - b.index;
        }).forEach(function (entry) {
            list.appendChild(entry.room);
        });
    }

    function resultsClick(target) {
        var section = target.closest('[data-travel-results]');
        if (!section) {
            return false;
        }
        var meal = target.closest('[data-travel-meal]');
        if (meal) {
            pickMeal(section, meal.getAttribute('data-travel-meal') || '');
            filterRates(section);
            return true;
        }
        var chip = target.closest('[data-travel-chip]');
        if (chip) {
            chip.setAttribute('aria-pressed', chip.getAttribute('aria-pressed') === 'true' ? 'false' : 'true');
            filterRates(section);
            return true;
        }
        if (target.closest('[data-travel-clear-filters]')) {
            pickMeal(section, '');
            each(section, '[data-travel-chip]', function (button) {
                button.setAttribute('aria-pressed', 'false');
            });
            filterRates(section);
            return true;
        }
        var all = target.closest('[data-travel-see-all]');
        if (all) {
            seeAll(all);
            return true;
        }
        return false;
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
            fitGallery(page);
            fitPhotos(page);
        });
    }

    document.addEventListener('click', function (event) {
        var target = event.target instanceof Element ? event.target : null;
        var page = target ? target.closest('.travel-pdpa') : null;
        if (!page) {
            return;
        }
        if (resultsClick(target)) {
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

    document.addEventListener('change', function (event) {
        var select = event.target instanceof Element ? event.target.closest('[data-travel-sort]') : null;
        var section = select ? select.closest('[data-travel-results]') : null;
        if (section) {
            sortRooms(section, select.value);
        }
    });

    var resizeTimer = null;
    window.addEventListener('resize', function () {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(fitGalleries, 150);
    });
    // Late layout: the header's images and fonts, the engine's bar.
    window.addEventListener('load', fitGalleries);
    // Each photo as it arrives (load does not bubble: capture it).
    document.addEventListener('load', function (event) {
        var target = event.target instanceof Element ? event.target : null;
        var tile = target && target.tagName === 'IMG' ? target.closest('.travel-pdpa-gallery__tile') : null;
        if (tile && !target.classList.contains('travel-pdpa-gallery__backdrop')) {
            fitPhoto(tile);
        }
    }, true);

    window.TravelPdpDesignA = {
        init: init,
        formatSummary: formatSummary,
        fitGallery: fitGallery,
        fitPhotos: fitPhotos,
        showsWhole: showsWhole,
        filterRates: filterRates,
        sortRooms: sortRooms,
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
