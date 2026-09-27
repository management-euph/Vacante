/**
 * Novoton Multi-Room Booking
 *
 * Handles room selection, price formatting, total calculation,
 * button enable/disable, and form submission for multi-room searches.
 *
 * All configuration is read from data attributes on #multi-room-selection:
 *   data-num-rooms      Number of rooms in the search
 *   data-rooms-data     JSON array of room occupancy objects
 *   data-currency       Display currency symbol (e.g. "RON", "€")
 *   data-coefficient    Price display coefficient (default 1)
 *   data-round-prices   "true" to round prices to integers
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
            roundPrices: container.dataset.roundPrices === 'true'
        };
    }

    // -----------------------------------------------------------------------
    // Price formatting — mirrors fn_novoton_holidays_format_price
    // -----------------------------------------------------------------------

    function formatPrice(amount, cfg) {
        var display = amount * cfg.coefficient;
        var rounded = Math.round(display);

        if (cfg.roundPrices) {
            return rounded.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.') + ' ' + cfg.currency;
        }

        var hasDec = Math.abs(display - rounded) >= 0.005;
        if (hasDec) {
            var intPart  = Math.floor(display);
            var decPart  = Math.round((display - intPart) * 100);
            return intPart.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.')
                + '<sup class="price-decimal">'
                + (decPart < 10 ? '0' : '') + decPart
                + '</sup> ' + cfg.currency;
        }

        return rounded.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.') + ' ' + cfg.currency;
    }

    // -----------------------------------------------------------------------
    // Room selection handler
    // -----------------------------------------------------------------------

    function handleRoomSelection(radio) {
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

        // Update per-room price header
        var priceEl = document.getElementById('room-' + roomNum + '-price');
        if (priceEl) {
            priceEl.textContent = formatPrice(price, cfg);
            priceEl.style.color = '#ffc107';
        }

        // Highlight selected option, reset siblings
        var roomContainer = radio.closest('[data-room]');
        if (roomContainer) {
            roomContainer.querySelectorAll('.room-option').forEach(function(opt) {
                opt.style.borderColor = '#e0e0e0';
                opt.style.background  = '#fff';
            });
            var selected = radio.closest('.room-option');
            if (selected) {
                selected.style.borderColor = '#003580';
                selected.style.background  = '#e8f4fd';
            }
        }

        updateTotalPrice(cfg);
    }

    // -----------------------------------------------------------------------
    // Total price + button state + hidden form fields
    // -----------------------------------------------------------------------

    function updateTotalPrice(cfg) {
        if (!cfg) cfg = getConfig();
        if (!cfg) return;

        var totalPrice    = 0;
        var selectedCount = 0;

        for (var i = 1; i <= cfg.numRooms; i++) {
            if (selectedRooms[i] && selectedRooms[i].price) {
                totalPrice += selectedRooms[i].price;
                selectedCount++;
            }
        }

        // Total display
        var totalEl = document.getElementById('total-combined-price');
        if (totalEl) {
            totalEl.textContent = totalPrice > 0
                ? formatPrice(totalPrice, cfg)
                : '-- ' + cfg.currency;
        }

        // Book button
        var bookBtn = document.getElementById('book-multi-room-btn');
        if (bookBtn) {
            if (selectedCount === cfg.numRooms) {
                bookBtn.disabled = false;
                bookBtn.style.opacity = '1';
            } else {
                bookBtn.disabled = true;
                bookBtn.style.opacity = '0.5';
            }
        }
    }

    // -----------------------------------------------------------------------
    // Form submission
    // -----------------------------------------------------------------------

    function submitBooking() {
        var cfg = getConfig();
        if (!cfg) return;

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
        if (e.target.id === 'book-multi-room-btn' && !e.target.disabled) {
            submitBooking();
        }
    });

})();
