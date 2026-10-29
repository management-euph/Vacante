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
 *  - Room-first results (components/design_a_results.tpl, board 13b): the
 *    meal filter and the chips show the rooms that have it and choose it in
 *    them, a room's radios switch its till, the sort orders the rooms, "see
 *    all" shows the rooms after the first four, the ⓘ of a room on request
 *    explains the request, terms not in the search load when opened, and
 *    results a supplier keeps sending (Sphinx polls) replace the list with
 *    the guest's choices kept.
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
     * Room-first results (components/design_a_results.tpl, board 13b): the
     * meal filter and the chips show the rooms that have it and choose it in
     * them; a room's radios switch its till (meal, total, button, terms);
     * the sort orders the rooms; the first ROOMS_SHOWN room types show until
     * "see all"; the ⓘ of a room on request explains the request; terms a
     * supplier doesn't send with the search load when opened. Which rooms
     * show is worked out again from that state on every change, so the
     * controls combine.
     */

    var ROOMS_SHOWN = 4;
    var HINT_NAMES = 3;

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

    function filtersOf(section) {
        return {
            meal: pressed(section, '[data-travel-meal]', 'data-travel-meal')[0] || '',
            chips: pressed(section, '[data-travel-chip]', 'data-travel-chip'),
        };
    }

    /** The cheapest of the room's options matching the filters, or null. */
    function bestOption(room, filters) {
        var best = null;
        each(room, '[data-travel-option]', function (option) {
            var match = (filters.meal === '' || option.getAttribute('data-meal') === filters.meal) && filters.chips.every(function (key) {
                return option.getAttribute('data-' + key) === '1';
            });
            var amount = parseFloat(option.getAttribute('data-amount')) || 0;
            if (match && (best === null || amount < best.amount)) {
                best = { index: toInt(option.getAttribute('data-index')), amount: amount };
            }
        });
        return best;
    }

    function chosenIndex(room) {
        var radio = room.querySelector('[data-travel-pick]:checked');
        return radio ? toInt(radio.value) : 0;
    }

    /**
     * The room's option at `index` in its till: its radio checked, its till
     * and terms shown. `picked` = the guest's change (or a filter's): the
     * total fades in and the card's live line says the new total.
     */
    function choose(room, index, picked) {
        var value = String(index);
        each(room, '[data-travel-pick]', function (radio) {
            radio.checked = radio.value === value;
        });
        each(room, '[data-travel-for]', function (part) {
            part.hidden = part.getAttribute('data-travel-for') !== value;
        });
        if (!picked) {
            return;
        }
        room.setAttribute('data-travel-picked', '');
        var till = room.querySelector('.travel-pdpa-till[data-travel-for="' + value + '"]');
        var say = room.querySelector('[data-travel-announce]');
        if (till && say) {
            say.textContent = till.getAttribute('data-travel-say') || '';
        }
    }

    /** "Încă 4: Suită Junior, …, de la 975 €" for the rooms "see all" would show. */
    function writeHint(section, rest) {
        var hint = section.querySelector('[data-travel-hint]');
        if (!hint || rest.length === 0) {
            return;
        }
        var names = [];
        var cheapest = null;
        rest.forEach(function (room) {
            var name = room.getAttribute('data-name') || '';
            if (name !== '' && names.indexOf(name) === -1) {
                names.push(name);
            }
            var from = parseFloat(room.getAttribute('data-from')) || 0;
            if (cheapest === null || from < cheapest.from) {
                cheapest = { from: from, text: room.getAttribute('data-from-text') || '' };
            }
        });
        var parts = {
            '[n]': String(rest.length),
            '[names]': names.slice(0, HINT_NAMES).join(', ') + (names.length > HINT_NAMES ? '…' : ''),
            '[price]': cheapest ? cheapest.text : '',
        };
        hint.textContent = (hint.getAttribute('data-template') || '').replace(/\[n]|\[names]|\[price]/g, function (key) {
            return parts[key];
        });
    }

    /**
     * Which rooms show: while filtering, every room with a matching option;
     * else the first ROOMS_SHOWN in the list's order, all once "see all" was
     * pressed.
     */
    function showRooms(section) {
        var filters = filtersOf(section);
        var filtering = filters.meal !== '' || filters.chips.length > 0;
        var open = section.hasAttribute('data-travel-open');
        var shown = 0;
        var rest = [];
        each(section, '[data-travel-room]', function (room) {
            var show = filtering ? bestOption(room, filters) !== null : open || shown < ROOMS_SHOWN;
            if (!show && !filtering) {
                rest.push(room);
            }
            room.hidden = !show;
            shown += show ? 1 : 0;
        });
        each(section, '[data-travel-rall]', function (all) {
            all.hidden = filtering || rest.length === 0;
        });
        writeHint(section, rest);
        each(section, '[data-travel-no-match]', function (empty) {
            empty.hidden = shown > 0;
        });
    }

    /** A filter changed: every room with a match gets its cheapest match chosen. */
    function filterRooms(section) {
        var filters = filtersOf(section);
        each(section, '[data-travel-room]', function (room) {
            var best = bestOption(room, filters);
            if (best !== null && best.index !== chosenIndex(room)) {
                choose(room, best.index, true);
            }
        });
        showRooms(section);
    }

    function pickMeal(section, key) {
        each(section, '[data-travel-meal]', function (button) {
            button.setAttribute('aria-pressed', (button.getAttribute('data-travel-meal') || '') === key ? 'true' : 'false');
        });
    }

    function seeAll(button) {
        var section = button.closest('[data-travel-results]');
        if (!section) {
            return;
        }
        var hidden = Array.prototype.filter.call(section.querySelectorAll('[data-travel-room]'), function (room) {
            return room.hidden;
        });
        section.setAttribute('data-travel-open', '');
        button.setAttribute('aria-expanded', 'true');
        showRooms(section);
        // The button is gone: carry the focus to the first room it showed
        // (its chosen option, or its button).
        var first = hidden.length > 0 ? hidden[0].querySelector('[data-travel-pick]:checked, .travel-pdpa-till:not([hidden]) a') : null;
        if (first) {
            first.focus();
        }
    }

    /** Rooms by their cheapest option: "low" first or "high" first. */
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
        showRooms(section);
    }

    function closeTips(except) {
        each(document, '[data-travel-tip][aria-expanded="true"]', function (button) {
            if (button === except) {
                return;
            }
            button.setAttribute('aria-expanded', 'false');
            var pop = document.getElementById(button.getAttribute('aria-controls') || '');
            if (pop) {
                pop.hidden = true;
            }
        });
    }

    function toggleTip(button) {
        var pop = document.getElementById(button.getAttribute('aria-controls') || '');
        if (!pop) {
            return;
        }
        var open = button.getAttribute('aria-expanded') !== 'true';
        closeTips(button);
        button.setAttribute('aria-expanded', open ? 'true' : 'false');
        pop.hidden = !open;
    }

    function resultsClick(target) {
        var tip = target.closest('[data-travel-tip]');
        if (tip) {
            toggleTip(tip);
            return true;
        }
        var close = target.closest('[data-travel-tip-close]');
        if (close) {
            var pop = close.closest('[data-travel-tip-pop]');
            var opener = pop ? document.querySelector('[data-travel-tip][aria-controls="' + pop.id + '"]') : null;
            closeTips(null);
            if (opener) {
                opener.focus();
            }
            return true;
        }
        var section = target.closest('[data-travel-results]');
        if (!section) {
            return false;
        }
        var meal = target.closest('[data-travel-meal]');
        if (meal) {
            pickMeal(section, meal.getAttribute('data-travel-meal') || '');
            filterRooms(section);
            return true;
        }
        var chip = target.closest('[data-travel-chip]');
        if (chip) {
            chip.setAttribute('aria-pressed', chip.getAttribute('aria-pressed') === 'true' ? 'false' : 'true');
            filterRooms(section);
            return true;
        }
        if (target.closest('[data-travel-clear-filters]')) {
            pickMeal(section, '');
            each(section, '[data-travel-chip]', function (button) {
                button.setAttribute('aria-pressed', 'false');
            });
            filterRooms(section);
            return true;
        }
        var all = target.closest('[data-travel-see-all]');
        if (all) {
            seeAll(all);
            return true;
        }
        var amenities = target.closest('[data-travel-amen-all]');
        if (amenities) {
            var room = amenities.closest('[data-travel-room]');
            if (room) {
                each(room, '[data-travel-amen-more]', function (item) {
                    item.hidden = false;
                });
                amenities.setAttribute('aria-expanded', 'true');
                amenities.hidden = true;
                var list = room.querySelector('[data-travel-amen]');
                if (list) {
                    list.focus();
                }
            }
            return true;
        }
        return false;
    }

    /**
     * Terms a supplier doesn't send with the search (Sphinx, Eurosite): the
     * first time they are opened, from the url the card carries — JSON
     * {sections: [{title, lines}]}, written as text.
     */
    function loadTerms(details) {
        var url = details.getAttribute('data-travel-terms-url') || '';
        var body = details.querySelector('[data-travel-terms-body]');
        if (url === '' || !body || details.hasAttribute('data-travel-terms-loaded')) {
            return;
        }
        details.setAttribute('data-travel-terms-loaded', '');
        var fail = function () {
            var wait = body.querySelector('[data-travel-terms-wait]');
            if (wait) {
                wait.textContent = body.getAttribute('data-error') || '';
            }
            details.removeAttribute('data-travel-terms-loaded');
        };
        fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }
                return response.json();
            })
            .then(function (data) {
                var sections = data && Array.isArray(data.sections) ? data.sections : [];
                if (sections.length === 0) {
                    fail();
                    return;
                }
                body.textContent = '';
                sections.forEach(function (section) {
                    if (section && section.title) {
                        var title = document.createElement('p');
                        title.className = 'travel-pdpa-terms__title';
                        title.textContent = String(section.title);
                        body.appendChild(title);
                    }
                    var list = document.createElement('ul');
                    list.className = 'travel-pdpa-terms__list';
                    (section && Array.isArray(section.lines) ? section.lines : []).forEach(function (line) {
                        var item = document.createElement('li');
                        item.textContent = String(line);
                        list.appendChild(item);
                    });
                    body.appendChild(list);
                });
            })
            .catch(fail);
    }

    /*
     * Results that keep arriving (Sphinx polls its search): the supplier
     * renders a live container (data-travel-live: the poll url, the interval,
     * the most polls); each answer — JSON {html, done, cursor} — brings the
     * whole results component for what has arrived so far. It replaces the
     * last one with what the guest chose kept: filters, sort, "see all",
     * each room's option (by the builder's stable keys), the focus. At the
     * end the container fires travel:live-done ({data: the last answer,
     * shown: whether a room came}): the supplier's script says when none did.
     */

    function stateOf(holder) {
        var section = holder.querySelector('[data-travel-results]');
        if (!section) {
            return null;
        }
        var select = section.querySelector('[data-travel-sort]');
        var state = {
            filters: filtersOf(section),
            sort: select ? select.value : 'low',
            open: section.hasAttribute('data-travel-open'),
            chosen: {},
            focus: null,
        };
        each(section, '[data-travel-room]', function (room) {
            var radio = room.querySelector('[data-travel-pick]:checked');
            var option = radio ? radio.closest('[data-travel-option]') : null;
            if (option) {
                state.chosen[room.getAttribute('data-room-key') || ''] = option.getAttribute('data-key') || '';
            }
        });
        var active = document.activeElement;
        if (active && section.contains(active)) {
            var room = active.closest('[data-travel-room]');
            var option = active.closest('[data-travel-option]');
            if (active.hasAttribute('data-travel-pick') && room && option) {
                state.focus = '[data-room-key="' + room.getAttribute('data-room-key') + '"] [data-key="' + option.getAttribute('data-key') + '"] [data-travel-pick]';
            } else if (active.hasAttribute('data-travel-meal')) {
                state.focus = '[data-travel-meal="' + active.getAttribute('data-travel-meal') + '"]';
            } else if (active.hasAttribute('data-travel-chip')) {
                state.focus = '[data-travel-chip="' + active.getAttribute('data-travel-chip') + '"]';
            } else if (active.hasAttribute('data-travel-sort')) {
                state.focus = '[data-travel-sort]';
            }
        }
        return state;
    }

    function restore(holder, state) {
        var section = holder.querySelector('[data-travel-results]');
        if (!section || !state) {
            return;
        }
        if (state.filters.meal !== '' && section.querySelector('[data-travel-meal="' + state.filters.meal + '"]')) {
            pickMeal(section, state.filters.meal);
        }
        each(section, '[data-travel-chip]', function (chip) {
            chip.setAttribute('aria-pressed', state.filters.chips.indexOf(chip.getAttribute('data-travel-chip')) !== -1 ? 'true' : 'false');
        });
        if (state.open) {
            section.setAttribute('data-travel-open', '');
            each(section, '[data-travel-see-all]', function (button) {
                button.setAttribute('aria-expanded', 'true');
            });
        }
        var filters = filtersOf(section);
        each(section, '[data-travel-room]', function (room) {
            var key = state.chosen[room.getAttribute('data-room-key') || ''];
            var option = key ? room.querySelector('[data-travel-option][data-key="' + key + '"]') : null;
            if (option) {
                choose(room, toInt(option.getAttribute('data-index')), false);
                return;
            }
            var best = bestOption(room, filters);
            if (best !== null) {
                choose(room, best.index, false);
            }
        });
        var select = section.querySelector('[data-travel-sort]');
        if (select) {
            select.value = state.sort;
            sortRooms(section, select.value);
        } else {
            showRooms(section);
        }
        var focus = state.focus ? section.querySelector(state.focus) : null;
        if (focus) {
            focus.focus();
        }
    }

    function armLive(live) {
        if (live.hasAttribute('data-travel-live-armed')) {
            return;
        }
        live.setAttribute('data-travel-live-armed', '');
        var url = live.getAttribute('data-travel-live-url') || '';
        var interval = toInt(live.getAttribute('data-travel-live-interval')) || 1000;
        var max = toInt(live.getAttribute('data-travel-live-max')) || 30;
        var holder = live.querySelector('[data-travel-live-results]');
        var status = live.querySelector('[data-travel-live-status]');
        var polls = 0;
        var cursor = '';
        var last = null;
        var stop = function () {
            live.setAttribute('data-travel-live-done', '');
            if (status) {
                status.hidden = true;
            }
            live.dispatchEvent(new CustomEvent('travel:live-done', {
                bubbles: true,
                detail: { data: last, shown: !!(holder && holder.querySelector('[data-travel-room]')) },
            }));
        };
        var poll = function () {
            // A new search replaced this one: let it go.
            if (!document.body.contains(live)) {
                return;
            }
            polls += 1;
            var next = url + (cursor !== '' ? '&cursor=' + encodeURIComponent(cursor) : '');
            fetch(next, { credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('HTTP ' + response.status);
                    }
                    return response.json();
                })
                .then(function (data) {
                    if (!document.body.contains(live)) {
                        return;
                    }
                    last = data;
                    cursor = data && data.cursor ? String(data.cursor) : cursor;
                    if (holder && data && typeof data.html === 'string' && data.html !== '') {
                        var state = stateOf(holder);
                        holder.innerHTML = data.html;
                        restore(holder, state);
                    }
                    if ((data && data.done) || polls >= max) {
                        stop();
                        return;
                    }
                    setTimeout(poll, interval);
                })
                .catch(function () {
                    if (polls >= max) {
                        stop();
                        return;
                    }
                    setTimeout(poll, interval);
                });
        };
        if (url === '' || !holder) {
            stop();
            return;
        }
        poll();
    }

    function armLiveResults() {
        each(document, '[data-travel-live]', armLive);
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
        armLiveResults();
    }

    document.addEventListener('click', function (event) {
        var target = event.target instanceof Element ? event.target : null;
        // A click anywhere else closes an open ⓘ explanation.
        if (target && !target.closest('[data-travel-tip-pop]') && !target.closest('[data-travel-tip]')) {
            closeTips(null);
        }
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
        var target = event.target instanceof Element ? event.target : null;
        var radio = target ? target.closest('[data-travel-pick]') : null;
        var room = radio ? radio.closest('[data-travel-room]') : null;
        if (room) {
            choose(room, toInt(radio.value), true);
            return;
        }
        var select = target ? target.closest('[data-travel-sort]') : null;
        var section = select ? select.closest('[data-travel-results]') : null;
        if (section) {
            sortRooms(section, select.value);
        }
    });

    // Esc closes an open ⓘ explanation and gives the focus back to its ⓘ.
    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') {
            return;
        }
        var open = document.querySelector('[data-travel-tip][aria-expanded="true"]');
        if (open) {
            closeTips(null);
            open.focus();
        }
    });

    // Terms opened for the first time (toggle does not bubble: capture it).
    document.addEventListener('toggle', function (event) {
        var details = event.target instanceof Element ? event.target : null;
        if (details && details.open && details.hasAttribute('data-travel-terms-url')) {
            loadTerms(details);
        }
    }, true);

    // Results swapped in by the inline search may keep arriving.
    document.addEventListener('travel:results-swapped', armLiveResults);

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
        filterRooms: filterRooms,
        showRooms: showRooms,
        sortRooms: sortRooms,
        choose: choose,
        armLiveResults: armLiveResults,
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
