/**
 * Travel Core — hotel page Design A
 * (blocks/product_templates/travelcore_design_a.tpl).
 *
 *  - Photo mosaic: a tile opens the theme's image previewer through the
 *    matching link of the stock gallery the template keeps out of sight.
 *    With no previewer link the tile keeps its own href (the photo itself).
 *  - "Alege datele": scroll to the search bar and open the calendar.
 *  - Sticky header, two lines (#40). Line 1 once the hero (title row,
 *    photos, booking box) has scrolled off: the name (a click goes back to
 *    the top), the stars or a circuit's length, the map pin, "Alege datele"
 *    ("Alege data plecării" for a circuit or a package by coach or plane)
 *    before a search or "Alege cameră" after it (each only while what it
 *    leads to is off screen) and the favourites heart (it clicks the title
 *    row's wishlist button). Line 2 once the page's own tab strip has gone
 *    under the header: the product's CS-Cart tabs, built from that strip,
 *    following the section in view. The tabs' panels show one under
 *    another, each under its tab's title, and every scroll the page makes
 *    lands under the header.
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

    function prefersReducedMotion() {
        return typeof window.matchMedia === 'function'
            && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    }

    // ---- Sticky header, two lines (#40) -------------------------------------

    // A section a click brings into view stops this far under the header.
    var HEADER_GAP = 8;
    // The header's lines when the stylesheet's --travel-pdpa-sticky-line1 /
    // --travel-pdpa-sticky-line2 cannot be read.
    var LINE1_FALLBACK = 56;
    var LINE2_FALLBACK = 48;
    // The hero has scrolled off once its end is this far above the window's
    // top: a scroll that lands exactly on it (the inline search lands 20 px
    // over its results, right on the bar's end) keeps the header away.
    var HERO_SLACK = 2;
    // The room options a visitor chooses from: Design A's room cards, the
    // classic offer cards, a package's room types, a circuit's rooms.
    var ROOM_OPTIONS = '[data-travel-room], .travel-offer-card, .travel-package-room, .travel-circuit-rooms .travel-circuit-offer';
    // A search's room list (Design A): its filters may hide every room.
    var ROOM_RESULTS = '[data-travel-results]';
    // The booking box's popups count as the booking box while open.
    var BOOKING_POPUPS = '.nvt-calendar-popup, .nvt-guest-popup';
    // A tab's scroll that has not arrived by then was cut short (ms).
    var LOCK_WAIT = 1500;

    /**
     * What the sticky header shows, from where things are on screen (px from
     * the window's top, as getBoundingClientRect gives them).
     *
     *  - Line 1 only once the hero (title row, photos, booking box) has
     *    scrolled off; line 2 only once the page's tab strip has gone under
     *    the header (its end where line 2 ends, or higher): line 2 takes its
     *    place, never with a part of it showing.
     *  - The button only while what it leads to is off screen: the room
     *    options after a search ("Alege cameră"), the booking box before it
     *    ("Alege datele").
     *  - The active tab, while line 2 shows: the one clicked last until the
     *    visitor scrolls by hand (locked), else the section crossing the
     *    middle of the visible area under the header, the last one at the very
     *    bottom of the page, none above the first section.
     *
     * @param {{heroBottom: number, stripBottom: (number|null), line1: number, line2: number,
     *          viewport: number, searched: boolean,
     *          rooms: Array<{top: number, bottom: number}>,
     *          booking: Array<{top: number, bottom: number}>,
     *          panels: Array<{id: string, top: number}>,
     *          atBottom: boolean, locked: (string|null)}} m
     * @return {{show: boolean, dock: boolean, cta: boolean, active: (string|null)}}
     */
    function headerState(m) {
        // no hero measured (the block is not displayed): no header
        var show = isFinite(m.heroBottom) && m.heroBottom < -HERO_SLACK;
        var dock = show && m.stripBottom !== null && m.stripBottom <= m.line1 + m.line2;
        var from = show ? m.line1 + (dock ? m.line2 : 0) : 0;
        var onScreen = function (box) {
            return box.bottom > from && box.top < m.viewport;
        };
        var cta = show && !(m.searched ? m.rooms : m.booking).some(onScreen);
        var active = null;
        if (dock && m.panels.length > 0) {
            if (m.locked !== null) {
                active = m.locked;
            } else if (m.atBottom) {
                active = m.panels[m.panels.length - 1].id;
            } else {
                var middle = from + (m.viewport - from) / 2;
                m.panels.forEach(function (panel) {
                    if (panel.top <= middle) {
                        active = panel.id;
                    }
                });
            }
        }
        return { show: show, dock: dock, cta: cta, active: active };
    }

    /**
     * The window scroll (document px) that brings an element's top into view
     * under the sticky header as it will be there: no header while the hero
     * shows (SCROLL_GAP, as before the header), line 1 above the page's tab
     * strip, both lines past it, never above where line 2 docks (a section
     * right under the strip then sits right under line 2). An element too
     * close under the hero to sit under line 1 lands with the hero's end
     * still on screen, so no header covers it.
     *
     * @param {{top: number, heroEnd: number, stripBottom: (number|null), line1: number, line2: number}} m
     * @return {number}
     */
    function landingY(m) {
        var y = m.top - SCROLL_GAP;
        if (y <= m.heroEnd + HERO_SLACK) {
            return Math.max(0, y);
        }
        y = m.top - m.line1 - HEADER_GAP;
        var dockAt = m.stripBottom === null ? Infinity : m.stripBottom - m.line1 - m.line2;
        if (y >= dockAt) {
            // rounded up: a window scrolled in whole pixels still docks line 2
            y = Math.max(m.top - m.line1 - m.line2 - HEADER_GAP, Math.ceil(dockAt));
        }
        return y <= m.heroEnd + HERO_SLACK ? Math.max(0, m.heroEnd) : y;
    }

    /** The heights of the header's two lines (the stylesheet's, read on the page). */
    function headerLines(page) {
        var style = typeof window.getComputedStyle === 'function' ? window.getComputedStyle(page) : null;
        var line1 = style ? parseFloat(style.getPropertyValue('--travel-pdpa-sticky-line1')) : NaN;
        var line2 = style ? parseFloat(style.getPropertyValue('--travel-pdpa-sticky-line2')) : NaN;
        return {
            line1: line1 > 0 ? line1 : LINE1_FALLBACK,
            line2: line2 > 0 ? line2 : LINE2_FALLBACK,
        };
    }

    /** Where the elements matching a selector are (px from the window's top); hidden ones left out. */
    function boxes(root, selector) {
        var list = [];
        each(root, selector, function (el) {
            var rect = el.getBoundingClientRect();
            if (rect.width > 0 || rect.height > 0) {
                list.push({ top: rect.top, bottom: rect.bottom });
            }
        });
        return list;
    }

    /**
     * The booking box: the mount's wrapper (the bar; a stay's results come
     * after it). A package or a circuit renders its offers inside the same
     * wrapper, after the departure chips: there the box ends with the chips.
     */
    function bookingBoxes(page) {
        var wrapper = page.querySelector('.travel-pdp-results .travel-search-form-wrapper');
        var rect = wrapper ? wrapper.getBoundingClientRect() : null;
        if (!rect || (rect.width === 0 && rect.height === 0)) {
            return [];
        }
        var chips = boxes(wrapper, '.travel-circuit-dates');
        return [{
            top: rect.top,
            bottom: chips.length > 0 ? Math.max.apply(null, chips.map(function (box) { return box.bottom; })) : rect.bottom,
        }];
    }

    /** The room options on show, else the room lists a filter has emptied. */
    function roomTargets(page) {
        var rooms = [];
        each(page, ROOM_OPTIONS, function (el) {
            rooms.push(el);
        });
        each(page, ROOM_RESULTS, function (el) {
            rooms.push(el);
        });
        var shown = rooms.filter(function (el) {
            var rect = el.getBoundingClientRect();
            return rect.width > 0 || rect.height > 0;
        });
        var options = shown.filter(function (el) {
            return !el.matches(ROOM_RESULTS);
        });
        return options.length > 0 ? options : shown;
    }

    /** The booking box with its calendar or guests popup while one is open. */
    function bookingArea(page) {
        return bookingBoxes(page).concat(boxes(page, BOOKING_POPUPS));
    }

    /** The hero's end, px from the window's top: the title row, the photos, the booking box. */
    function heroBottom(page) {
        return boxes(page, '.travel-pdpa-head, .travel-pdpa-gallery').concat(bookingArea(page))
            .reduce(function (end, box) { return Math.max(end, box.bottom); }, -Infinity);
    }

    // The sticky header of each Design A block on the page.
    var headers = [];

    function headerOf(page) {
        for (var i = 0; i < headers.length; i += 1) {
            if (headers[i].page === page) {
                return headers[i];
            }
        }
        return null;
    }

    /** landingY for an element of a Design A block, in this window's scroll. */
    function landingFor(page, el) {
        var scroll = window.pageYOffset;
        var top = el.getBoundingClientRect().top + scroll;
        var h = page ? headerOf(page) : null;
        if (!h) {
            return top - SCROLL_GAP;
        }
        var lines = h.lines || headerLines(page);
        var strip = h.tabs ? h.tabs.strip.getBoundingClientRect() : null;
        return landingY({
            top: top,
            heroEnd: heroBottom(page) + scroll,
            stripBottom: strip && strip.height > 0 ? strip.bottom + scroll : null,
            line1: lines.line1,
            line2: lines.line2,
        });
    }

    /** Brings an element into view under the sticky header; returns where the window stops. */
    function scrollToElement(el) {
        var page = el.closest ? el.closest('.travel-pdpa') : null;
        var top = Math.max(0, landingFor(page, el));
        window.scrollTo({ top: top, behavior: prefersReducedMotion() ? 'auto' : 'smooth' });
        var end = document.documentElement.scrollHeight - (window.innerHeight || 0);
        return end > 0 ? Math.min(top, end) : top;
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
        // A package mode (board 16.2) has its own bar: the dates are its chips.
        if (root.closest('.travel-pdp-results--package')) {
            return true;
        }
        var trigger = root.querySelector('.nvt-field--date .nvt-field-input');
        if (trigger && trigger.getAttribute('aria-expanded') !== 'true') {
            trigger.click();
        }
        return true;
    }

    /**
     * The product's CS-Cart tabs inside [data-travel-pdpa-tabs]: their strip
     * (the theme's tab list) and, for each tab it lists, its title and panel
     * (#content_ + the tab's id). CS-Cart lists only the tabs with content.
     */
    function productTabs(page) {
        var box = page.querySelector('[data-travel-pdpa-tabs]');
        var strip = box ? box.querySelector('.ty-tabs, .cm-j-tabs') : null;
        if (!strip) {
            return null;
        }
        var tabs = [];
        each(strip, 'li[id]', function (li) {
            // a tab the theme loads on demand (none of a product's, as CS-Cart
            // ships them) stays the theme's: its panel is empty until clicked
            if (li.classList.contains('cm-ajax') || li.classList.contains('cm-ajax-onclick')) {
                return;
            }
            var panel = document.getElementById('content_' + li.id);
            if (!panel || !box.contains(panel)) {
                return;
            }
            var link = li.querySelector('a') || li;
            tabs.push({ id: li.id, title: link.textContent.trim(), li: li, panel: panel });
        });
        return tabs.length > 0 ? { strip: strip, tabs: tabs } : null;
    }

    /**
     * Every panel shows, one under another, under its tab's title, so line 2
     * can follow them; a click on the page's own strip brings the panel into
     * view instead of switching to it. Clicks this script or the theme
     * sends, and clicks on a tab line 2 leaves out, still reach CS-Cart. On
     * a phone the theme would turn the strip into an accordion (responsive.js,
     * after the page has loaded): its opt-out classes keep the strip and the
     * panels.
     */
    function stackTabs(page, info) {
        info.strip.classList.add('cm-j-tabs-disable-convertation');
        // the strip's own panels box: the first one after it, as the theme finds it
        for (var next = info.strip.nextElementSibling; next; next = next.nextElementSibling) {
            if (next.classList.contains('cm-tabs-content')) {
                next.classList.add('cm-j-content-disable-convertation');
                break;
            }
        }
        info.tabs.forEach(function (tab) {
            tab.panel.setAttribute('data-travel-pdpa-panel', tab.id);
            if (!tab.panel.querySelector('.travel-pdpa-tabs__title')) {
                var title = document.createElement('h2');
                title.className = 'travel-pdpa-tabs__title';
                title.textContent = tab.title;
                tab.panel.insertBefore(title, tab.panel.firstChild);
            }
        });
        page.classList.add('travel-pdpa--stacked');
        info.strip.addEventListener('click', function (event) {
            var li = event.isTrusted && event.target instanceof Element ? event.target.closest('li[id]') : null;
            var ours = info.tabs.some(function (tab) {
                return tab.li === li;
            });
            if (!ours) {
                return;
            }
            event.preventDefault();
            event.stopPropagation();
            goTab(page, li.id);
        }, true);
    }

    /** Line 2: one button per tab of the page's strip, in its order and with its title. */
    function buildTabs(h) {
        h.tabs.tabs.forEach(function (tab) {
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'travel-pdpa-sticky__tab';
            button.setAttribute('data-travel-pdpa-tab', tab.id);
            button.textContent = tab.title;
            h.list.appendChild(button);
        });
        h.list.addEventListener('scroll', function () {
            fadeTabs(h);
        }, { passive: true });
    }

    /** A phone's line 2 scrolls sideways: its edges fade while more tabs hide there. */
    function fadeTabs(h) {
        var list = h.list;
        var more = list.scrollWidth - list.clientWidth;
        h.nav.classList.toggle('is-fade-start', list.scrollLeft > 2);
        h.nav.classList.toggle('is-fade-end', more > 2 && list.scrollLeft < more - 2);
    }

    function markTabs(h, active) {
        each(h.list, '[data-travel-pdpa-tab]', function (button) {
            var on = button.getAttribute('data-travel-pdpa-tab') === active;
            button.classList.toggle('is-active', on);
            if (on) {
                button.setAttribute('aria-current', 'true');
                // bring it into view on a phone's sideways line
                var left = button.offsetLeft - (h.list.clientWidth - button.offsetWidth) / 2;
                h.list.scrollLeft = Math.max(0, left);
            } else {
                button.removeAttribute('aria-current');
            }
        });
        // The page's own strip shows the same tab, its first one above the sections.
        var shown = active || h.tabs.tabs[0].id;
        h.tabs.tabs.forEach(function (tab) {
            tab.li.classList.toggle('active', tab.id === shown);
        });
        fadeTabs(h);
    }

    function wishlistButton(page) {
        return page.querySelector('.travel-pdpa-head__actions .ty-add-to-wish, .travel-pdpa-head__actions [id^="button_wishlist_"]');
    }

    /**
     * The tab clicked last holds line 2 while its scroll runs and once it has
     * arrived, until the page moves on: by the scrollbar here, by a wheel, a
     * touch or a key in releaseTabs. A scroll cut short lets go too.
     */
    function holdTab(h) {
        if (h.locked === null) {
            return;
        }
        if (Math.abs(window.pageYOffset - h.lockY) <= 2) {
            h.lockArrived = true;
        } else if (h.lockArrived || Date.now() - h.lockAt > LOCK_WAIT) {
            h.locked = null;
        }
    }

    function updateHeader(h) {
        var page = h.page;
        var view = window.innerHeight || document.documentElement.clientHeight || 0;
        holdTab(h);
        // read once per window size (the phone's lines are shorter)
        var lines = h.lines || (h.lines = headerLines(page));
        var strip = h.tabs ? h.tabs.strip.getBoundingClientRect() : null;
        var rooms = roomTargets(page).map(function (el) {
            var rect = el.getBoundingClientRect();
            return { top: rect.top, bottom: rect.bottom };
        });
        var state = headerState({
            heroBottom: heroBottom(page),
            stripBottom: strip && strip.height > 0 ? strip.bottom : null,
            line1: lines.line1,
            line2: lines.line2,
            viewport: view,
            searched: rooms.length > 0,
            rooms: rooms,
            booking: bookingArea(page),
            panels: h.tabs ? h.tabs.tabs.map(function (tab) {
                return { id: tab.id, top: tab.panel.getBoundingClientRect().top };
            }) : [],
            atBottom: window.pageYOffset + view >= document.documentElement.scrollHeight - 4,
            locked: h.locked,
        });
        var before = h.state;
        state.searched = rooms.length > 0;
        h.state = state;
        h.header.classList.toggle('is-shown', state.show);
        h.header.classList.toggle('is-docked', state.dock);
        if (h.nav && h.nav.hidden === state.dock) {
            h.nav.hidden = !state.dock;
        }
        if (h.cta) {
            // a package by coach or plane (board 16.2) chooses a departure, as a circuit does
            var key = state.searched ? 'data-label-room'
                : (page.querySelector('.travel-pdp-results--package') ? 'data-label-departure' : 'data-label-dates');
            var label = h.cta.getAttribute(key) || '';
            if (label !== '' && h.cta.textContent !== label) {
                h.cta.textContent = label;
            }
            h.cta.classList.toggle('is-away', !state.cta);
        }
        if (h.tabs && h.list && (!before || before.active !== state.active || before.dock !== state.dock)) {
            markTabs(h, state.active);
        }
    }

    function updateHeaders() {
        headers.forEach(updateHeader);
    }

    var headersFrame = 0;

    /** One update per frame, whatever asks for it (scroll, resize, new results). */
    function scheduleHeaders() {
        if (headersFrame !== 0 || headers.length === 0) {
            return;
        }
        var run = function () {
            headersFrame = 0;
            updateHeaders();
        };
        headersFrame = typeof window.requestAnimationFrame === 'function'
            ? window.requestAnimationFrame(run)
            : setTimeout(run, 16);
    }

    function initHeader(page) {
        var header = page.querySelector('[data-travel-pdpa-sticky]');
        if (!header) {
            return;
        }
        var h = {
            page: page,
            header: header,
            nav: header.querySelector('[data-travel-pdpa-sticky-tabs]'),
            list: header.querySelector('[data-travel-pdpa-sticky-tablist]'),
            cta: header.querySelector('[data-travel-pdpa-cta]'),
            fav: header.querySelector('[data-travel-pdpa-fav]'),
            tabs: productTabs(page),
            // the tab clicked last, where its scroll stops, when, and whether it got there
            locked: null,
            lockY: 0,
            lockAt: 0,
            lockArrived: false,
            lines: null,
            state: null,
        };
        if (h.tabs && h.nav && h.list) {
            stackTabs(page, h.tabs);
            buildTabs(h);
        } else {
            h.tabs = null;
        }
        // No booking form on this product: no dates to choose, no rooms.
        if (h.cta && !document.getElementById('travel-booking-root')) {
            h.cta.hidden = true;
        }
        // The heart clicks the title row's own wishlist button: none, no heart.
        if (h.fav && !wishlistButton(page)) {
            h.fav.hidden = true;
        }
        headers.push(h);
        header.hidden = false;
        updateHeader(h);
        // The booking box grows as the engine draws it (mode switch, chips),
        // the results come and go: the hero's end moves without a scroll.
        if (typeof window.ResizeObserver === 'function') {
            new window.ResizeObserver(scheduleHeaders).observe(page);
        }
    }

    /** A tab of line 2 or of the page's strip: its section, under the header. */
    function goTab(page, id) {
        var panel = page.querySelector('[data-travel-pdpa-panel="' + id + '"]');
        var h = headerOf(page);
        if (!panel || !h) {
            return false;
        }
        h.locked = id;
        h.lockY = scrollToElement(panel);
        h.lockAt = Date.now();
        h.lockArrived = false;
        updateHeader(h);
        return true;
    }

    /** "Alege cameră": the first room option (or the emptied list), under line 1. */
    function goRooms(page) {
        var first = roomTargets(page)[0];
        if (!first) {
            return false;
        }
        scrollToElement(first);
        return true;
    }

    function addFavourite(page, fav) {
        var button = wishlistButton(page);
        if (!button) {
            return;
        }
        button.click();
        fav.setAttribute('aria-pressed', 'true');
        fav.classList.add('is-saved');
    }

    /** A click in the sticky header; true when it was the header's to handle. */
    function stickyClick(page, target) {
        var h = headerOf(page);
        if (!h || !target.closest('[data-travel-pdpa-sticky]')) {
            return false;
        }
        if (target.closest('[data-travel-pdpa-top]')) {
            h.locked = null;
            window.scrollTo({ top: 0, behavior: prefersReducedMotion() ? 'auto' : 'smooth' });
            return true;
        }
        if (target.closest('[data-travel-pdpa-cta]')) {
            if (!(h.state && h.state.searched && goRooms(page))) {
                openDates();
            }
            return true;
        }
        var fav = target.closest('[data-travel-pdpa-fav]');
        if (fav) {
            addFavourite(page, fav);
            return true;
        }
        var tab = target.closest('[data-travel-pdpa-tab]');
        if (tab) {
            goTab(page, tab.getAttribute('data-travel-pdpa-tab'));
            return true;
        }
        return false;
    }

    /** The visitor scrolls by hand: the clicked tab no longer holds line 2. */
    function releaseTabs() {
        var released = false;
        headers.forEach(function (h) {
            if (h.locked !== null) {
                h.locked = null;
                released = true;
            }
        });
        if (released) {
            scheduleHeaders();
        }
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

    /**
     * A toggletip opens under its ⓘ; where the window leaves no room under
     * it (its bottom, or the bar of the rooms chosen held there), above it,
     * so the bar never covers it.
     */
    function placeTip(pop) {
        pop.classList.remove('travel-pdpa-tip__pop--up');
        var bottom = window.innerHeight || document.documentElement.clientHeight;
        var bar = document.querySelector('[data-travel-bar]:not([hidden])');
        if (bar && !bar.contains(pop)) {
            var top = bar.getBoundingClientRect().top;
            if (top > 0 && top < bottom) {
                bottom = top;
            }
        }
        if (pop.getBoundingClientRect().bottom > bottom - 8) {
            pop.classList.add('travel-pdpa-tip__pop--up');
        }
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
        if (open) {
            placeTip(pop);
        }
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
     * "Condiții de plată și anulare" on a phone (design_a_terms.tpl, board
     * 14.6): the panel is a bottom sheet over the page (CSS under 768 px).
     * Opened, the page under it stops scrolling, the focus goes to its ×
     * and its button says what the card's does ("Rezervă · 785 €"); the ×,
     * a tap beside it or Esc close it, its button closes it and presses the
     * card's own (the booking link, or "Alege pentru …" with several rooms).
     */
    function isPhone() {
        return typeof window.matchMedia === 'function' && window.matchMedia('(max-width: 767px)').matches;
    }

    /** The button of the till the card shows (its chosen option). */
    function cardButton(details) {
        var room = details.closest('[data-travel-room]');
        var till = room ? room.querySelector('.travel-pdpa-till:not([hidden])') : null;

        return till ? till.querySelector('.travel-pdpa-till__btn') : null;
    }

    function openSheet(details) {
        document.documentElement.classList.add('travel-pdpa-sheet-open');
        var cta = details.querySelector('[data-travel-terms-cta]');
        if (cta) {
            var button = cardButton(details);
            var till = button ? button.closest('.travel-pdpa-till') : null;
            var total = till ? till.querySelector('.travel-pdpa-till__total') : null;
            cta.textContent = button ? button.textContent.trim() + (total ? ' · ' + total.textContent.trim() : '') : '';
            cta.disabled = !button || button.disabled === true;
        }
        var close = details.querySelector('[data-travel-terms-close]');
        if (close) {
            close.focus();
        }
    }

    function sheetClosed(details) {
        if (!document.querySelector('.travel-pdpa-terms[open]')) {
            document.documentElement.classList.remove('travel-pdpa-sheet-open');
        }
        var summary = details.querySelector('summary');
        if (summary && isPhone()) {
            summary.focus();
        }
    }

    function sheetClick(target) {
        var close = target.closest('[data-travel-terms-close]');
        if (close) {
            close.closest('.travel-pdpa-terms').open = false;
            return true;
        }
        var cta = target.closest('[data-travel-terms-cta]');
        if (cta) {
            var details = cta.closest('.travel-pdpa-terms');
            var button = cardButton(details);
            details.open = false;
            if (button) {
                button.click();
            }
            return true;
        }
        // Beside the sheet: the backdrop is the open <details> itself
        if (target.matches('.travel-pdpa-terms[open]') && isPhone()) {
            target.open = false;
            return true;
        }
        return false;
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
            initHeader(page);
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
        if (sheetClick(target) || resultsClick(target)) {
            return;
        }
        if (stickyClick(page, target)) {
            if (!target.closest('a[href]')) {
                event.preventDefault();
            }
            return;
        }
        var tile = target.closest('[data-travel-gallery-index]');
        if (tile) {
            if (openPhoto(page, toInt(tile.getAttribute('data-travel-gallery-index')))) {
                event.preventDefault();
            }
            return;
        }
        if (target.closest('[data-travel-open-dates]') && openDates()) {
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
        var sheet = isPhone() ? document.querySelector('.travel-pdpa .travel-pdpa-terms[open]') : null;
        if (sheet) {
            sheet.open = false;
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
        if (details && details.matches('.travel-pdpa .travel-pdpa-terms')) {
            if (details.open && isPhone()) {
                openSheet(details);
            } else if (!details.open) {
                sheetClosed(details);
            }
        }
    }, true);

    // Grown past a phone with a sheet open: the panel is inline again, the page scrolls.
    window.addEventListener('resize', function () {
        if (!isPhone()) {
            document.documentElement.classList.remove('travel-pdpa-sheet-open');
        }
    });

    // Results swapped in by the inline search may keep arriving.
    document.addEventListener('travel:results-swapped', armLiveResults);

    // The sticky header follows the scroll, the window's size and the results.
    window.addEventListener('scroll', scheduleHeaders, { passive: true });
    window.addEventListener('resize', function () {
        headers.forEach(function (h) {
            h.lines = null;
        });
        scheduleHeaders();
    });
    window.addEventListener('load', scheduleHeaders);
    document.addEventListener('travel:results-swapped', scheduleHeaders);
    document.addEventListener('travel:live-done', scheduleHeaders);
    // Scrolling by hand releases a tab clicked on line 2.
    window.addEventListener('wheel', releaseTabs, { passive: true });
    window.addEventListener('touchstart', releaseTabs, { passive: true });
    document.addEventListener('keydown', function (event) {
        if (/^(ArrowUp|ArrowDown|PageUp|PageDown|Home|End| )$/.test(event.key)
            && !(event.target instanceof Element && event.target.closest('input, textarea, select, [contenteditable="true"]'))) {
            releaseTabs();
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
        headerState: headerState,
        landingY: landingY,
        updateHeaders: updateHeaders,
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
