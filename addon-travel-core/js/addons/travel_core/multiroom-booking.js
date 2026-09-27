/**
 * Novoton Multi-Room Booking
 *
 * Handles room selection, price formatting, total calculation, the
 * selection summary, button enable/disable and form submission for
 * multi-room searches (up to 12 rooms, the guest picker's maximum).
 *
 * All configuration is read from data attributes on #multi-room-selection:
 *   data-num-rooms      Number of rooms in the search
 *   data-rooms-data     JSON array of room occupancy objects
 *   data-currency       Display currency symbol (e.g. "RON", "€")
 *   data-coefficient    Price display coefficient (default 1)
 *   data-round-prices   "true" to round prices to integers
 *   data-stepper        "true": one room open at a time (4+ rooms) — a
 *                       choice closes the room and opens the next one
 *   data-label-*        Translated labels with [chosen]/[total]/[count]/
 *                       [amount] placeholders (optional)
 *   data-search-key     Identifies the search (hotel, dates, rooms). The
 *                       chosen rooms are kept per key in sessionStorage and
 *                       restored when the same results render again — e.g.
 *                       after switching language, which reloads the page.
 *
 * Booking is blocked until EVERY room has a choice: the button stays
 * disabled, and a click that still gets through opens the first room left.
 *
 * Uses document-level event delegation so it works with AJAX-replaced content
 * without re-initialization.
 */
(function() {
    'use strict';

    // -----------------------------------------------------------------------
    // State — scoped to the current container instance
    // -----------------------------------------------------------------------

    var selectedRooms = {};
    var lastContainer = null;

    // -----------------------------------------------------------------------
    // Config — read fresh from the DOM on each interaction
    // -----------------------------------------------------------------------

    function getConfig() {
        var container = document.getElementById('multi-room-selection');
        if (!container) return null;

        // Reset selections when the container element changes (AJAX reload)
        if (container !== lastContainer) {
            selectedRooms = {};
            lastContainer = container;
        }

        var roomsData;
        try {
            roomsData = JSON.parse(container.dataset.roomsData || '[]');
        } catch (e) {
            roomsData = [];
        }

        return {
            container:   container,
            numRooms:    parseInt(container.dataset.numRooms, 10) || 0,
            roomsData:   roomsData,
            currency:    container.dataset.currency || '',
            coefficient: parseFloat(container.dataset.coefficient) || 1,
            roundPrices: container.dataset.roundPrices === 'true',
            stepper:     container.dataset.stepper === 'true',
            searchKey:   container.dataset.searchKey || '',
            labels: {
                progress:  container.dataset.labelProgress || '',
                left:      container.dataset.labelLeft || '',
                book:      container.dataset.labelBook || '',
                same:      container.dataset.labelSame || '',
                savings:   container.dataset.labelSavings || '',
                notChosen: container.dataset.labelNotChosen || ''
            }
        };
    }

    function label(template, values) {
        return template.replace(/\[(\w+)]/g, function(match, key) {
            return key in values ? String(values[key]) : match;
        });
    }

    // -----------------------------------------------------------------------
    // Price formatting — mirrors fn_novoton_holidays_format_price
    // -----------------------------------------------------------------------

    function groupThousands(n) {
        return n.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    }

    /** {whole: "1.250", decimals: "50" | ""} for the display amount. */
    function priceParts(amount, cfg) {
        var display = amount * cfg.coefficient;
        var rounded = Math.round(display);

        if (!cfg.roundPrices && Math.abs(display - rounded) >= 0.005) {
            var intPart = Math.floor(display);
            var decPart = Math.round((display - intPart) * 100);
            return { whole: groupThousands(intPart), decimals: (decPart < 10 ? '0' : '') + decPart };
        }

        return { whole: groupThousands(rounded), decimals: '' };
    }

    function formatPriceText(amount, cfg) {
        var p = priceParts(amount, cfg);
        return p.whole + (p.decimals ? ',' + p.decimals : '') + ' ' + cfg.currency;
    }

    /** Writes the price into el as DOM nodes (decimals in a <sup>), never HTML. */
    function setPrice(el, amount, cfg) {
        var p = priceParts(amount, cfg);
        el.textContent = p.whole;
        if (p.decimals) {
            var sup = document.createElement('sup');
            sup.className = 'price-decimal';
            sup.textContent = p.decimals;
            el.appendChild(sup);
        }
        el.appendChild(document.createTextNode(' ' + cfg.currency));
    }

    // -----------------------------------------------------------------------
    // Chosen rooms survive a reload of the same search (sessionStorage)
    // -----------------------------------------------------------------------

    var STORE_PREFIX = 'travel_mr_selection:';

    function saveSelections(cfg) {
        if (!cfg.searchKey) return;
        var values = {};
        for (var n in selectedRooms) {
            var radio = cfg.container.querySelector('input[name="room_' + n + '_selection"]:checked');
            if (radio) values[n] = radio.value;
        }
        try {
            window.sessionStorage.setItem(STORE_PREFIX + cfg.searchKey, JSON.stringify(values));
        } catch (e) { /* storage unavailable: choices just are not kept */ }
    }

    /**
     * Re-selects the saved rooms of this search. A saved choice whose option
     * no longer renders with the same value (room, meal plan AND price) is
     * dropped: the customer chooses that room again at the current price.
     */
    function restoreSelections() {
        var cfg = getConfig();
        if (!cfg || !cfg.searchKey || cfg.container.dataset.mrRestored === '1') return;
        cfg.container.dataset.mrRestored = '1';

        var values;
        try {
            values = JSON.parse(window.sessionStorage.getItem(STORE_PREFIX + cfg.searchKey) || '{}');
        } catch (e) {
            return;
        }
        if (!values || typeof values !== 'object') return;

        var restored = 0;
        Object.keys(values).forEach(function(n) {
            var match = null;
            cfg.container.querySelectorAll('input[name="room_' + n + '_selection"]').forEach(function(r) {
                if (!match && r.value === values[n]) match = r;
            });
            if (match) {
                match.checked = true;
                handleRoomSelection(match, true);
                restored++;
            }
        });
        if (restored === 0) return;

        if (cfg.stepper) {
            var pending = firstPendingRoom(cfg);
            cfg.container.querySelectorAll('[data-room]').forEach(function(section) {
                setRoomOpen(section, parseInt(section.getAttribute('data-room'), 10) === pending);
            });
        }
    }

    // -----------------------------------------------------------------------
    // Rooms: open / close, lookups
    // -----------------------------------------------------------------------

    function roomSection(cfg, roomNum) {
        return cfg.container.querySelector('[data-room="' + roomNum + '"]');
    }

    function setRoomOpen(section, open) {
        if (!section) return;
        section.classList.toggle('is-open', open);
        var toggle = section.querySelector('.novoton-mr-room__toggle');
        if (toggle) toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        var options = section.querySelector('.novoton-mr-options');
        if (options) options.hidden = !open;
    }

    function firstPendingRoom(cfg, after) {
        for (var pass = 0; pass < 2; pass++) {
            for (var i = 1; i <= cfg.numRooms; i++) {
                if (pass === 0 && after && i <= after) continue;
                if (!selectedRooms[i]) return i;
            }
        }
        return null;
    }

    function scrollToRoom(section) {
        if (!section || typeof section.scrollIntoView !== 'function') return;
        var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        section.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'start' });
    }

    /** Opens room roomNum (closing the others in stepper mode) and brings it into view. */
    function goToRoom(cfg, roomNum) {
        var target = roomSection(cfg, roomNum);
        if (!target) return;
        if (cfg.stepper) {
            cfg.container.querySelectorAll('[data-room]').forEach(function(section) {
                setRoomOpen(section, section === target);
            });
        } else {
            setRoomOpen(target, true);
        }
        scrollToRoom(target);
    }

    function hideNextActions(section) {
        var next = section && section.querySelector('.novoton-mr-next');
        if (next) next.hidden = true;
    }

    /**
     * Rooms still to choose with the same guests as roomNum that offer the
     * same room + meal plan — capped by the offer's remaining quota.
     */
    function sameGuestTargets(cfg, roomNum, radio) {
        var section = roomSection(cfg, roomNum);
        var occupancy = section ? section.getAttribute('data-occupancy') : null;
        if (!occupancy) return [];

        var roomId = radio.getAttribute('data-room-id');
        var boardId = radio.getAttribute('data-board-id');
        var quota = parseInt(radio.getAttribute('data-quota'), 10);
        var taken = 0;
        for (var n in selectedRooms) {
            if (selectedRooms[n].room_id === roomId && selectedRooms[n].board_id === boardId) taken++;
        }
        var left = quota > 0 ? quota - taken : Infinity;

        var targets = [];
        cfg.container.querySelectorAll('[data-room]').forEach(function(other) {
            var num = parseInt(other.getAttribute('data-room'), 10);
            if (targets.length >= left || num === roomNum || selectedRooms[num]) return;
            if (other.getAttribute('data-occupancy') !== occupancy) return;
            var match = null;
            other.querySelectorAll('input[type="radio"]').forEach(function(r) {
                if (!match && r.getAttribute('data-room-id') === roomId && r.getAttribute('data-board-id') === boardId) {
                    match = r;
                }
            });
            if (match) targets.push(match);
        });
        return targets;
    }

    /** After a choice: offer "same guests" / "next room", or move on by itself. */
    function afterChoice(cfg, roomNum, radio) {
        var section = roomSection(cfg, roomNum);
        var next = section && section.querySelector('.novoton-mr-next');
        var targets = sameGuestTargets(cfg, roomNum, radio);
        var pending = firstPendingRoom(cfg, roomNum);

        if (next && targets.length > 0) {
            var same = next.querySelector('.novoton-mr-next__same');
            if (same) {
                same.hidden = targets.length === 0;
                if (targets.length > 0 && cfg.labels.same) {
                    same.textContent = label(cfg.labels.same, { count: targets.length });
                }
            }
            var go = next.querySelector('.novoton-mr-next__go');
            if (go) go.hidden = !(cfg.stepper && pending);
            next.hidden = false;
            return;
        }

        hideNextActions(section);
        if (cfg.stepper && pending) {
            goToRoom(cfg, pending);
        }
    }

    // -----------------------------------------------------------------------
    // Room selection handler
    // -----------------------------------------------------------------------

    function handleRoomSelection(radio, quiet) {
        var cfg = getConfig();
        if (!cfg) return;

        var roomNum     = parseInt(radio.getAttribute('data-room-num'), 10);
        var price       = parseFloat(radio.getAttribute('data-price')) || 0;
        var roomId      = radio.getAttribute('data-room-id');
        var boardId     = radio.getAttribute('data-board-id');
        var roomDisplay = radio.getAttribute('data-room-display');
        var boardName   = radio.getAttribute('data-board-name');
        var packageName = radio.getAttribute('data-package-name') || '';

        var occupancy = cfg.roomsData[roomNum - 1] || { adults: 2, children: 0, childrenAges: [] };

        selectedRooms[roomNum] = {
            room_id:      roomId,
            board_id:     boardId,
            price:        price,
            was_price:    parseFloat(radio.getAttribute('data-was-price')) || 0,
            room_display: roomDisplay,
            board_name:   boardName,
            package_name: packageName,
            adults:       occupancy.adults || 2,
            children:     occupancy.children || 0,
            childrenAges: occupancy.childrenAges || []
        };
        // Novoton's offer behind the price (the booking page shows the "was"
        // price the search card struck through). Only providers that render
        // these attributes send them.
        if (radio.hasAttribute('data-standard-price')) {
            selectedRooms[roomNum].standard_price = parseFloat(radio.getAttribute('data-standard-price')) || 0;
            selectedRooms[roomNum].early_booking = parseFloat(radio.getAttribute('data-early-booking')) || 0;
            selectedRooms[roomNum].extras = radio.getAttribute('data-extras') || '';
        }

        // Room header: the chosen room and its price
        var priceEl = document.getElementById('room-' + roomNum + '-price');
        if (priceEl) setPrice(priceEl, price, cfg);
        var choiceEl = document.getElementById('room-' + roomNum + '-choice');
        if (choiceEl) choiceEl.textContent = roomDisplay || '';
        var sumEl = document.getElementById('mr-sum-' + roomNum);
        if (sumEl) {
            setPrice(sumEl, price, cfg);
            var sumItem = sumEl.closest('.novoton-mr__list-item');
            if (sumItem) sumItem.classList.remove('is-pending');
        }

        // Highlight the selected option
        var roomContainer = radio.closest('[data-room]');
        if (roomContainer) {
            roomContainer.classList.add('is-chosen');
            roomContainer.querySelectorAll('.room-option').forEach(function(opt) {
                opt.classList.remove('is-selected');
            });
            var selected = radio.closest('.room-option');
            if (selected) selected.classList.add('is-selected');
        }

        updateTotalPrice(cfg);
        saveSelections(cfg);

        if (!quiet) afterChoice(cfg, roomNum, radio);
    }

    function applyToSameGuests(fromRoom) {
        var cfg = getConfig();
        if (!cfg) return;
        var source = roomSection(cfg, fromRoom);
        var radio = source && source.querySelector('input[type="radio"]:checked');
        if (!radio) return;

        sameGuestTargets(cfg, fromRoom, radio).forEach(function(target) {
            target.checked = true;
            handleRoomSelection(target, true);
            if (cfg.stepper) setRoomOpen(target.closest('[data-room]'), false);
        });

        hideNextActions(source);
        var pending = firstPendingRoom(cfg, fromRoom);
        if (pending) {
            goToRoom(cfg, pending);
        } else if (cfg.stepper) {
            setRoomOpen(source, false);
        }
    }

    // -----------------------------------------------------------------------
    // Total price + summary + button state
    // -----------------------------------------------------------------------

    function updateTotalPrice(cfg) {
        if (!cfg) cfg = getConfig();
        if (!cfg) return;

        var totalPrice    = 0;
        var savings       = 0;
        var selectedCount = 0;

        for (var i = 1; i <= cfg.numRooms; i++) {
            if (selectedRooms[i] && selectedRooms[i].price) {
                totalPrice += selectedRooms[i].price;
                if (selectedRooms[i].was_price > selectedRooms[i].price) {
                    savings += selectedRooms[i].was_price - selectedRooms[i].price;
                }
                selectedCount++;
            }
        }
        var allChosen = selectedCount === cfg.numRooms && cfg.numRooms > 0;

        // Total display
        var totalEl = document.getElementById('total-combined-price');
        if (totalEl) {
            if (totalPrice > 0) {
                setPrice(totalEl, totalPrice, cfg);
            } else {
                totalEl.textContent = '-- ' + cfg.currency;
            }
        }

        // Progress
        var progressText = document.getElementById('mr-progress-text');
        if (progressText && cfg.labels.progress) {
            progressText.textContent = label(cfg.labels.progress, { chosen: selectedCount, total: cfg.numRooms });
        }
        var progressBar = document.getElementById('mr-progress-bar');
        if (progressBar && cfg.numRooms > 0) {
            progressBar.style.width = Math.round(selectedCount / cfg.numRooms * 100) + '%';
        }

        // Savings, once every room is chosen
        var savingsEl = document.getElementById('mr-savings');
        if (savingsEl) {
            savingsEl.hidden = !(allChosen && savings > 0 && cfg.labels.savings);
            if (!savingsEl.hidden) {
                savingsEl.textContent = label(cfg.labels.savings, { amount: formatPriceText(savings, cfg) });
            }
        }

        // Book button: disabled until every room has a choice
        var bookBtn = document.getElementById('book-multi-room-btn');
        if (bookBtn) {
            bookBtn.disabled = !allChosen;
            var text = allChosen ? cfg.labels.book : cfg.labels.left;
            if (text) {
                bookBtn.textContent = label(text, { count: allChosen ? cfg.numRooms : cfg.numRooms - selectedCount });
            }
        }
    }

    // -----------------------------------------------------------------------
    // Form submission
    // -----------------------------------------------------------------------

    function submitBooking() {
        var cfg = getConfig();
        if (!cfg) return;

        // Every room must have a choice — never submit a partial booking.
        var pending = firstPendingRoom(cfg);
        if (pending) {
            updateTotalPrice(cfg);
            goToRoom(cfg, pending);
            return;
        }

        var roomsData = [];
        var total = 0;

        for (var i = 1; i <= cfg.numRooms; i++) {
            if (selectedRooms[i]) {
                var roomEntry = {
                    room_num:     i,
                    room_id:      selectedRooms[i].room_id,
                    board_id:     selectedRooms[i].board_id,
                    price:        selectedRooms[i].price,
                    room_display: selectedRooms[i].room_display,
                    board_name:   selectedRooms[i].board_name,
                    package_name: selectedRooms[i].package_name,
                    adults:       selectedRooms[i].adults,
                    children:     selectedRooms[i].children,
                    childrenAges: selectedRooms[i].childrenAges
                };
                if ('standard_price' in selectedRooms[i]) {
                    roomEntry.standard_price = selectedRooms[i].standard_price;
                    roomEntry.early_booking = selectedRooms[i].early_booking;
                    roomEntry.extras = selectedRooms[i].extras;
                }
                roomsData.push(roomEntry);
                total += selectedRooms[i].price;
            }
        }

        var hiddenRoomsData = document.getElementById('hidden_rooms_data');
        if (hiddenRoomsData) hiddenRoomsData.value = JSON.stringify(roomsData);

        var hiddenTotal = document.getElementById('hidden_total_price');
        if (hiddenTotal) hiddenTotal.value = total;

        var form = document.getElementById('multi-room-booking-form');
        if (form) form.submit();
    }

    // -----------------------------------------------------------------------
    // Event delegation — works with AJAX-replaced content
    // -----------------------------------------------------------------------

    document.addEventListener('change', function(e) {
        var target = e.target;
        if (target.type === 'radio' && target.name && /^room_\d+_selection$/.test(target.name)) {
            handleRoomSelection(target);
        }
    });

    document.addEventListener('click', function(e) {
        var el = e.target instanceof Element ? e.target : null;
        if (!el || !el.closest('#multi-room-selection')) return;

        var book = el.closest('#book-multi-room-btn');
        if (book) {
            if (!book.disabled) submitBooking();
            return;
        }

        var cfg;
        var toggle = el.closest('.novoton-mr-room__toggle');
        if (toggle) {
            var section = toggle.closest('[data-room]');
            setRoomOpen(section, !section.classList.contains('is-open'));
            return;
        }

        var same = el.closest('.novoton-mr-next__same');
        if (same) {
            applyToSameGuests(parseInt(same.getAttribute('data-same-from'), 10));
            return;
        }

        var go = el.closest('.novoton-mr-next__go');
        if (go) {
            cfg = getConfig();
            var from = parseInt(go.getAttribute('data-next-from'), 10);
            hideNextActions(roomSection(cfg, from));
            var pending = firstPendingRoom(cfg, from);
            if (pending) goToRoom(cfg, pending);
            return;
        }

        var gotoBtn = el.closest('[data-goto-room]');
        if (gotoBtn) {
            cfg = getConfig();
            goToRoom(cfg, parseInt(gotoBtn.getAttribute('data-goto-room'), 10));
        }
    });

    // Restore on first render and whenever the booking engine swaps fresh
    // results into the product page.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', restoreSelections);
    } else {
        restoreSelections();
    }
    document.addEventListener('travel:results-swapped', restoreSelections);

})();
