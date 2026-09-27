/**
 * Travel Core - Booking Form Validation & Price Recalculation
 * Version: 3.1.0
 *
 * Features:
 * - DOB input masking (DD/MM/YYYY)
 * - Age validation at check-in date
 * - Price recalculation via AJAX
 * - Room change warning modal
 *
 * Provider-agnostic: reads config from TravelBookingConfig with
 * NovotonConfig fallback. Public API on window.TravelBooking with
 * window.Novoton alias for backwards compatibility.
 *
 * @package TravelCore
 * @since 1.0.0
 */

(function() {
    'use strict';

    // =========================================================================
    // NAMESPACE SETUP
    // =========================================================================

    window.TravelBooking = window.TravelBooking || {};
    window.Novoton = window.TravelBooking; // Backwards compatibility

    // =========================================================================
    // CONFIGURATION
    // =========================================================================

    function _getConfig() {
        return window.TravelBookingConfig || window.NovotonConfig || {};
    }

    function _getTranslations() {
        return window.TravelTranslations || window.NovotonTranslations || {};
    }

    function _getUtils() {
        return window.TravelUtils || window.NovotonUtils || {};
    }

    var CONFIG = {
        debug: !!(_getConfig().debug),
        selectors: {
            priceDisplay: '.price-total, .total-price-value, .booking-total-value, .price-amount',
            priceSection: '.booking-price-box, .price-summary, .booking-summary'
        }
    };

    // =========================================================================
    // LOGGING UTILITIES
    // =========================================================================

    function log(message, data) {
        if (CONFIG.debug && console && console.log) {
            if (data !== undefined) {
                console.log('[TravelBooking] ' + message, data);
            } else {
                console.log('[TravelBooking] ' + message);
            }
        }
    }

    function logError(message, error) {
        if (console && console.error) {
            console.error('[TravelBooking ERROR] ' + message, error);
        }
    }

    // =========================================================================
    // HTML ESCAPING UTILITY
    // =========================================================================

    function escapeHtml(str) {
        var utils = _getUtils();
        return (utils && utils.escapeHtml)
            ? utils.escapeHtml(str)
            : (str || '');
    }

    // =========================================================================
    // DOB MASKING (DD/MM/YYYY)
    // =========================================================================

    var dobLastKeyWasBackspace = false;

    function handleDobKeydown(e) {
        dobLastKeyWasBackspace = (e.key === 'Backspace' || e.key === 'Delete');
    }

    function formatDobDigits(digits) {
        var masked = '';
        if (digits.length > 0) {
            masked = digits.substring(0, Math.min(2, digits.length));
        }
        if (digits.length > 2) {
            masked = digits.substring(0, 2) + '/' + digits.substring(2, Math.min(4, digits.length));
        }
        if (digits.length > 4) {
            masked = digits.substring(0, 2) + '/' + digits.substring(2, 4) + '/' + digits.substring(4, Math.min(8, digits.length));
        }
        return masked;
    }

    function applyDobMask(input) {
        var cursorPos = input.selectionStart;
        var oldValue = input.value;
        var oldLen = oldValue.length;
        var digits = oldValue.replace(/\D/g, '');

        if (dobLastKeyWasBackspace) {
            dobLastKeyWasBackspace = false;
            var masked = formatDobDigits(digits);
            input.value = masked;
            var newPos = Math.min(cursorPos, masked.length);
            try { input.setSelectionRange(newPos, newPos); } catch(e) {}
            return;
        }

        masked = '';
        if (digits.length > 0) masked = digits.substring(0, Math.min(2, digits.length));
        if (digits.length >= 2) masked = digits.substring(0, 2) + '/';
        if (digits.length > 2) masked = digits.substring(0, 2) + '/' + digits.substring(2, Math.min(4, digits.length));
        if (digits.length >= 4) masked = digits.substring(0, 2) + '/' + digits.substring(2, 4) + '/';
        if (digits.length > 4) masked = digits.substring(0, 2) + '/' + digits.substring(2, 4) + '/' + digits.substring(4, Math.min(8, digits.length));

        input.value = masked;

        var newLen = masked.length;
        newPos = cursorPos;
        if (newLen > oldLen) {
            if (cursorPos === 2 || cursorPos === 3) newPos = 3;
            else if (cursorPos === 5 || cursorPos === 6) newPos = 6;
            else newPos = newLen;
        }
        try { input.setSelectionRange(newPos, newPos); } catch(e) {}
    }

    function parseDobMasked(dobString) {
        if (!dobString || dobString.length !== 10) return null;
        var parts = dobString.split('/');
        if (parts.length !== 3) return null;

        var day = parseInt(parts[0], 10);
        var month = parseInt(parts[1], 10);
        var year = parseInt(parts[2], 10);

        if (isNaN(day) || isNaN(month) || isNaN(year)) return null;
        return { day: day, month: month, year: year };
    }

    function calculateAgeAtDate(birthDate, targetDate) {
        var utils = _getUtils();
        if (utils && utils.calculateAge) {
            return utils.calculateAge(birthDate, targetDate);
        }
        var age = targetDate.getFullYear() - birthDate.getFullYear();
        var m = targetDate.getMonth() - birthDate.getMonth();
        if (m < 0 || (m === 0 && targetDate.getDate() < birthDate.getDate())) {
            age--;
        }
        return age;
    }

    // =========================================================================
    // PRICE DISPLAY UPDATES
    // =========================================================================

    function updatePriceDisplay(newPrice, formattedPrice, difference) {
        log('updatePriceDisplay', { newPrice: newPrice, formattedPrice: formattedPrice, difference: difference });

        var priceElements = document.querySelectorAll(CONFIG.selectors.priceDisplay);
        log('Found price elements: ' + priceElements.length);

        if (priceElements.length === 0) {
            logError('No price elements found! Selector: ' + CONFIG.selectors.priceDisplay);
            return;
        }

        // Use server-formatted price (includes currency symbol and rounding)
        // Fallback: format with currency from NovotonTranslations
        var displayValue = formattedPrice;
        if (!displayValue) {
            var currency = _getTranslations().currency || '€';
            displayValue = (newPrice ? parseFloat(newPrice).toFixed(2) : '0.00') + ' ' + currency;
        }

        priceElements.forEach(function(el) {
            log('Updating: ' + el.className + ' from "' + el.textContent + '" to "' + displayValue + '"');
            // Use innerHTML since formatted_price may contain <sup> for decimals
            el.innerHTML = displayValue;
        });

        // Hide recalc notice
        var recalcNotice = document.getElementById('price-recalc-notice');
        if (recalcNotice) recalcNotice.style.display = 'none';

        // Show price change notification above guest details heading
        if (difference && difference !== 0) {
            var notif = document.getElementById('price-change-notification');
            if (!notif) {
                notif = document.createElement('div');
                notif.id = 'price-change-notification';
                notif.className = 'novoton-price-change-notif';
                var heading = document.querySelector('.guest-names-section h3');
                if (heading && heading.parentNode) {
                    heading.parentNode.insertBefore(notif, heading);
                }
            }
            var changeText = difference > 0 ? '+' + difference.toFixed(2) : difference.toFixed(2);
            var changeColor = difference > 0 ? 'var(--nvt-danger, #dc3545)' : 'var(--nvt-success, #28a745)';
            var t = _getTranslations();
            notif.textContent = '';
            notif.appendChild(document.createTextNode(
                t.priceUpdated + ': '
            ));
            var strong = document.createElement('strong');
            strong.style.color = changeColor;
            strong.textContent = changeText + ' €';
            notif.appendChild(strong);
            notif.style.display = 'block';
        }
    }

    function showPriceRecalculationNotice(message) {
        var notif = document.getElementById('price-recalc-notice');
        if (!notif) {
            notif = document.createElement('div');
            notif.id = 'price-recalc-notice';
            notif.className = 'novoton-recalc-notice';
            var priceSection = document.querySelector(CONFIG.selectors.priceSection);
            if (priceSection && priceSection.parentNode) {
                priceSection.parentNode.insertBefore(notif, priceSection.nextSibling);
            }
        }
        // Use DOM methods to avoid innerHTML with message text
        notif.textContent = '';
        var icon = document.createElement('i');
        icon.className = 'icon-info-sign';
        notif.appendChild(icon);
        notif.appendChild(document.createTextNode(' ' + message));
        notif.style.display = 'block';
    }

    // =========================================================================
    // ROOM CHANGE WARNING MODAL
    // =========================================================================

    // Internal state for room change flow
    var _roomChangeState = {
        data: null,
        isActive: false,

        set: function(newData) {
            this.data = newData;
            this.isActive = true;
        },

        clear: function() {
            this.data = null;
            this.isActive = false;
        }
    };

    function showRoomChangeWarning(data) {
        log('showRoomChangeWarning', data);

        var existing = document.getElementById('room-change-warning');
        if (existing) existing.remove();

        var priceDiff = parseFloat(data.price_difference) || 0;
        var newPrice = parseFloat(data.new_price) || 0;
        var originalPrice = parseFloat(data.original_price) || 0;
        var originalRoom = escapeHtml(data.original_room || '');
        var newRoom = escapeHtml(data.new_room || '');

        var priceDiffText = '', priceDiffClass = '';
        if (priceDiff > 0) {
            priceDiffText = '+' + priceDiff.toFixed(2) + ' €';
            priceDiffClass = 'color: var(--nvt-danger, #dc3545); font-weight: bold;';
        } else if (priceDiff < 0) {
            priceDiffText = priceDiff.toFixed(2) + ' €';
            priceDiffClass = 'color: var(--nvt-success, #28a745); font-weight: bold;';
        }

        var t = _getTranslations();

        // Build modal HTML — all translations escaped
        var html = '<div id="room-change-warning" class="novoton-modal-overlay">' +
            '<div class="novoton-modal-content">' +
            '<div class="novoton-modal-header">' +
                '<div class="novoton-modal-icon"><i class="icon-warning-sign"></i></div>' +
                '<h3 class="novoton-modal-title">' + escapeHtml(t.roomChangedTitle) + '</h3>' +
            '</div>' +
            '<p class="novoton-modal-body">' + escapeHtml(t.roomChangedDueToAge) + '</p>' +
            '<div class="novoton-room-change-info">' +
                '<div class="novoton-room-change-compare">' +
                    '<div style="text-align:center;">' +
                        '<div class="novoton-room-change-label">' + escapeHtml(t.originalRoom) + '</div>' +
                        '<div class="novoton-room-change-old">' + originalRoom + '</div>' +
                    '</div>' +
                    '<div class="novoton-room-change-arrow"><i class="icon-arrow-right"></i></div>' +
                    '<div style="text-align:center;">' +
                        '<div class="novoton-room-change-label">' + escapeHtml(t.newRoom) + '</div>' +
                        '<div class="novoton-room-change-new">' + newRoom + '</div>' +
                    '</div>' +
                '</div>' +
            '</div>' +
            '<div class="novoton-price-change-box">' +
                '<div class="novoton-price-change-label">' + escapeHtml(t.priceChange) + '</div>' +
                '<div class="novoton-price-change-values">' +
                    '<span style="text-decoration:line-through;color:#999;">' + originalPrice.toFixed(2) + ' €</span> ' +
                    '<span style="' + priceDiffClass + '">(' + priceDiffText + ')</span> ' +
                    '<span style="font-weight:bold;color:var(--nvt-primary, #003580);">' + newPrice.toFixed(2) + ' €</span>' +
                '</div>' +
            '</div>' +
            '<div class="novoton-modal-actions">' +
                '<button type="button" onclick="TravelBooking.goBackToSearch()" class="novoton-modal-btn-secondary"><i class="icon-arrow-left"></i> ' + escapeHtml(t.goBackToSearch) + '</button>' +
                '<button type="button" onclick="TravelBooking.acceptRoomChange()" class="novoton-modal-btn-primary">' + escapeHtml(t.continueWithNewRoom) + ' <i class="icon-arrow-right"></i></button>' +
            '</div>' +
            '</div></div>';

        _roomChangeState.set(data);

        // Error boundary: if modal fails to render, fall back to alert
        try {
            var wrapper = document.createElement('div');
            wrapper.innerHTML = html;
            var modal = wrapper.firstChild;
            if (!modal) throw new Error('Modal rendering failed');
            document.body.appendChild(modal);
            log('Modal displayed');
        } catch (e) {
            logError('Modal render error', e);
            _roomChangeState.clear();
            alert((t.roomChangedTitle || 'Room changed') + ': ' + (data.original_room || '') + ' → ' + (data.new_room || '') +
                  '\n' + (t.priceChange || 'Price') + ': ' + (data.new_price || 0).toFixed(2) + ' €');
        }
    }

    function acceptRoomChange() {
        var data = _roomChangeState.data || {};
        log('acceptRoomChange', data);

        var warning = document.getElementById('room-change-warning');
        if (warning) warning.remove();
        _roomChangeState.clear();

        document.querySelectorAll('.room-name, .selected-room-name, [data-room-name]').forEach(function(el) {
            el.textContent = data.new_room || '';
        });

        var roomIdInput = document.querySelector('input[name="room_id"]');
        if (roomIdInput) roomIdInput.value = data.new_room || '';

        if (window.bookingData) {
            window.bookingData.roomId = data.new_room || '';
            window.bookingData.currentPrice = parseFloat(data.new_price) || 0;
        }

        // Show confirmation
        var t = _getTranslations();
        var notif = document.createElement('div');
        notif.id = 'room-change-confirmation';
        notif.className = 'novoton-room-confirmed';
        // Build safely with DOM methods
        var icon = document.createElement('i');
        icon.className = 'icon-ok';
        notif.appendChild(icon);
        notif.appendChild(document.createTextNode(' '));
        var strong = document.createElement('strong');
        strong.textContent = t.roomUpdated || 'Room updated';
        notif.appendChild(strong);
        notif.appendChild(document.createTextNode(' ' + (data.new_room || '') + ' - ' + (parseFloat(data.new_price) || 0).toFixed(2) + ' €'));

        var section = document.querySelector('.guest-names-section h3, .booking-form-header');
        if (section && section.parentNode) {
            section.parentNode.insertBefore(notif, section.nextSibling);
        }

        setTimeout(function() {
            if (notif.parentNode) {
                notif.style.opacity = '0';
                setTimeout(function() { notif.remove(); }, 300);
            }
        }, 10000);
    }

    function goBackToSearch() {
        log('goBackToSearch');
        var warning = document.getElementById('room-change-warning');
        if (warning) warning.remove();
        _roomChangeState.clear();

        var cfg = _getConfig();
        var searchDispatch = (cfg && cfg.searchDispatch) || 'novoton_booking.search';
        var backBtn = document.querySelector('.btn-back, a[href*="' + searchDispatch + '"]');
        if (backBtn) backBtn.click();
        else window.history.back();
    }

    // =========================================================================
    // PRICE RECALCULATION (AJAX)
    // =========================================================================

    function triggerPriceRecalculation(childrenAges, roomNum) {
        roomNum = roomNum || 1;
        log('triggerPriceRecalculation room ' + roomNum, childrenAges);

        if (!window.bookingData) {
            logError('bookingData not defined');
            return;
        }

        var isMultiRoom = window.bookingData.numRooms > 1 && window.bookingData.roomsData && window.bookingData.roomsData.length > 0;
        var roomIdx = roomNum - 1;

        // Get room-specific data for multi-room, or use single room data
        var roomData;
        if (isMultiRoom && window.bookingData.roomsData[roomIdx]) {
            roomData = window.bookingData.roomsData[roomIdx];
        } else {
            roomData = {
                room_id: window.bookingData.roomId,
                board_id: window.bookingData.boardId,
                adults: window.bookingData.adults,
                price: window.bookingData.currentPrice
            };
        }

        var priceDisplay = document.querySelector(CONFIG.selectors.priceDisplay);
        var loadingIndicator = document.getElementById('price-loading-indicator');

        if (loadingIndicator) loadingIndicator.style.display = 'inline-block';
        if (priceDisplay) priceDisplay.style.opacity = '0.5';

        var requestData = {
            hotel_id: window.bookingData.hotelId,
            room_id: roomData.room_id || window.bookingData.roomId,
            board_id: roomData.board_id || window.bookingData.boardId,
            check_in: window.bookingData.checkIn,
            nights: window.bookingData.nights,
            adults: roomData.adults || window.bookingData.adults,
            children_ages: childrenAges,
            package_name: roomData.package_name || window.bookingData.packageName,
            original_price: roomData.price || window.bookingData.currentPrice,
            room_num: roomNum,
            is_multi_room: isMultiRoom
        };

        log('AJAX request', requestData);

        // Clean AJAX URL — only dispatch param. All booking data goes in JSON body.
        // Do NOT inherit parent page URL params (children_ages[], etc.) as they
        // cause PHP warnings in CS-Cart's init that corrupt the JSON response.
        // Use pre-built URL from template (includes storefront_id) with fallback
        var cfg = _getConfig();
        var ajaxUrl = cfg.ajaxRecalcUrl
            || ((window.Tygh && window.Tygh.current_location) || window.location.origin) + '/index.php?dispatch=' + (cfg.ajaxRecalcDispatch || 'novoton_booking.ajax_recalculate_price');
        log('AJAX URL', ajaxUrl);

        fetch(ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(requestData)
        })
        .then(function(response) {
            log('Response status: ' + response.status);
            return response.text();
        })
        .then(function(text) {
            log('Raw response', text.substring(0, 200));
            try {
                return JSON.parse(text);
            } catch (e) {
                logError('JSON parse error: ' + e.message);
                throw e;
            }
        })
        .then(function(data) {
            log('AJAX response', data);

            if (loadingIndicator) loadingIndicator.style.display = 'none';
            if (priceDisplay) priceDisplay.style.opacity = '1';

            if (data.success) {
                updatePriceDisplay(data.new_price, data.formatted_price, data.price_difference);

                if (data.room_changed) {
                    try {
                        showRoomChangeWarning(data);
                    } catch (e) {
                        logError('Modal error', e);
                        var t2 = _getTranslations();
                        alert((t2.roomChangedTitle || 'Room changed') + ': ' + (data.original_room || '') + ' → ' + (data.new_room || '') +
                              '\n' + (t2.priceChange || 'Price') + ': ' + (data.new_price || 0).toFixed(2) + ' €');
                    }
                }

                updateFormWithNewPricing(data);
            } else {
                var t3 = _getTranslations();
                showPriceRecalculationNotice(data.message || t3.priceWillBeVerified || 'Price will be verified at checkout.');
            }
        })
        .catch(function(error) {
            logError('AJAX error', error);
            if (loadingIndicator) loadingIndicator.style.display = 'none';
            if (priceDisplay) priceDisplay.style.opacity = '1';
            var t3 = _getTranslations();
            showPriceRecalculationNotice(t3.priceWillBeVerified || 'Price will be verified at checkout.');
        });
    }

    function updateFormWithNewPricing(data) {
        var priceInput = document.querySelector('input[name="total_price"]');
        if (priceInput && data.new_price !== undefined) priceInput.value = data.new_price;

        var adultsInput = document.querySelector('input[name="adults"]');
        if (adultsInput && data.new_adults) adultsInput.value = data.new_adults;

        var childrenInput = document.querySelector('input[name="children"]');
        if (childrenInput && data.new_children !== undefined) childrenInput.value = data.new_children;

        if (window.bookingData) {
            if (data.new_price !== undefined) window.bookingData.currentPrice = data.new_price;
            if (data.new_adults) window.bookingData.adults = data.new_adults;
        }
    }

    // =========================================================================
    // EXPECTED-AGE GUARD (fixed-price providers)
    // =========================================================================
    // Child DOB inputs rendered with data-expected-age carry the age the offer
    // was SEARCHED/PRICED for (sphinx: price is fixed per searched occupancy —
    // no live re-pricing). If the typed DOB implies a different age at
    // check-in, flag the field immediately and hold the form back; the server
    // enforces the same rule authoritatively in add_to_cart. Novoton's form
    // does not use data-expected-age (it re-prices on DOB change instead), so
    // this path is inert there.

    // The form's check-in, or the page's normalised copy when the hidden
    // field is not ISO (novoton passes the URL value through as-is).
    function _checkInDate(checkInEl) {
        return _parseIsoDate(checkInEl ? checkInEl.value : '')
            || _parseIsoDate((window.bookingData && window.bookingData.checkIn) || '');
    }

    function _parseIsoDate(iso) {
        if (!iso) return null;
        var p = String(iso).split('-');
        if (p.length !== 3) return null;
        var d = new Date(parseInt(p[0], 10), parseInt(p[1], 10) - 1, parseInt(p[2], 10));
        return isNaN(d.getTime()) ? null : d;
    }

    function _ageMismatchMessage(expected, actual) {
        var t = _getTranslations();
        var pattern = t.childAgeMismatch ||
            'The child [guest] will be [actual] years old at check-in, but the offer was priced for age [declared]. The search was re-run with the correct ages — please choose an offer again.';
        return pattern
            .replace(/\s*\[guest]/, '')
            .replace('[actual]', String(actual))
            .replace('[declared]', String(expected));
    }

    function validateExpectedAge(input) {
        var expectedRaw = input.getAttribute('data-expected-age');
        if (expectedRaw === null || expectedRaw === '') return true;

        var form = input.form || input.closest('form');
        var checkInEl = form ? form.querySelector('input[name="check_in"]') : null;
        var checkIn = _checkInDate(checkInEl);

        var msgEl = input.parentElement ? input.parentElement.querySelector('.js-age-mismatch-msg') : null;
        var clear = function () {
            // Remove only OUR message; the red state stays while any other
            // message (required, DOB format) still stands for this field.
            if (msgEl) msgEl.remove();
            _syncInvalid(input);
        };

        var dob = parseDobMasked(input.value);
        if (!dob || !checkIn) {
            // Incomplete DOB (still typing) or no check-in on the form —
            // nothing to judge yet; other validators own format errors.
            clear();
            return true;
        }

        var actual = calculateAgeAtDate(new Date(dob.year, dob.month - 1, dob.day), checkIn);
        var expected = parseInt(expectedRaw, 10);
        if (actual === expected) {
            clear();
            return true;
        }

        input.setAttribute('aria-invalid', 'true');
        if (!msgEl) {
            msgEl = document.createElement('span');
            msgEl.className = 'travel-field-error-message js-age-mismatch-msg';
            msgEl.setAttribute('role', 'alert');
            input.parentElement.appendChild(msgEl);
        }
        msgEl.textContent = _ageMismatchMessage(expected, actual);
        return false;
    }

    document.addEventListener('focusout', function (e) {
        var el = e.target;
        if (el && el.matches && el.matches('input[data-expected-age]')) {
            validateExpectedAge(el);
        }
    });

    document.addEventListener('input', function (e) {
        var el = e.target;
        // Re-judge as soon as the mask is complete so a correction clears the
        // error without waiting for blur.
        if (el && el.matches && el.matches('input[data-expected-age]') && el.value.length === 10) {
            validateExpectedAge(el);
        }
    });

    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form || !form.querySelectorAll) return;
        var guarded = form.querySelectorAll('input[data-expected-age]');
        var firstBad = null;
        for (var i = 0; i < guarded.length; i++) {
            if (!validateExpectedAge(guarded[i]) && !firstBad) {
                firstBad = guarded[i];
            }
        }
        // Every shared DOB field is re-checked too: a half-typed "12/05"
        // passed `required` and reached the server (the on-blur check only
        // runs if the guest leaves the field).
        var dobs = form.querySelectorAll('input.js-dob-basics');
        for (var j = 0; j < dobs.length; j++) {
            if (!validateDobBasics(dobs[j]) && !firstBad) {
                firstBad = dobs[j];
            }
        }
        if (firstBad) {
            e.preventDefault();
            try { firstBad.focus(); } catch (err) {}
        }
    });

    // =========================================================================
    // BASIC DOB VALIDATION (shared guest cards)
    // =========================================================================
    // Inputs carrying .js-dob-basics (every DOB rendered by the shared
    // booking_guest_room_body.tpl — sphinx, novoton and eurosite) get the
    // on-blur checks: complete mask, real calendar date, plausible year
    // range, not in the future, and for CHILD inputs an under-18-at-check-in
    // rule (the rule the server enforces authoritatively via
    // GuestDataService). Novoton's own validateAndCheckAge only re-prices on
    // child age now; the messages are these.

    function validateDobBasics(input) {
        var t = _getTranslations();
        var msgEl = input.parentElement ? input.parentElement.querySelector('.js-dob-basics-msg') : null;
        var clear = function () {
            if (msgEl) msgEl.remove();
            _syncInvalid(input);
        };
        var fail = function (text) {
            input.setAttribute('aria-invalid', 'true');
            if (!msgEl) {
                msgEl = document.createElement('span');
                msgEl.className = 'travel-field-error-message js-dob-basics-msg';
                msgEl.setAttribute('role', 'alert');
                input.parentElement.appendChild(msgEl);
            }
            msgEl.textContent = text;
            return false;
        };

        var value = input.value || '';
        if (value === '') {
            // Empty is the `required` attribute's business, not ours.
            clear();
            return true;
        }

        var parsed = parseDobMasked(value);
        if (value.length < 10 || !parsed) {
            return fail(t.invalidDobFormat || 'Format invalid. Folositi ZZ/LL/AAAA');
        }
        if (parsed.day < 1 || parsed.day > 31) {
            return fail(t.invalidDay || 'Ziua trebuie sa fie intre 1 si 31');
        }
        if (parsed.month < 1 || parsed.month > 12) {
            return fail(t.invalidMonth || 'Luna trebuie sa fie intre 1 si 12');
        }
        var currentYear = new Date().getFullYear();
        if (parsed.year < 1925 || parsed.year > currentYear) {
            return fail(t.invalidYear || 'An invalid');
        }
        var birth = new Date(parsed.year, parsed.month - 1, parsed.day);
        if (birth.getDate() !== parsed.day || birth.getMonth() !== parsed.month - 1) {
            // Impossible calendar date (31/02/…) silently rolled over by Date.
            return fail(t.invalidDobFormat || 'Format invalid. Folositi ZZ/LL/AAAA');
        }
        var today = new Date();
        today.setHours(0, 0, 0, 0);
        if (birth > today) {
            return fail(t.futureDate || 'Data nu poate fi in viitor');
        }

        // Children must still be under 18 at check-in.
        if (/child_\d+]/.test(input.name || '')) {
            var form = input.form || input.closest('form');
            var checkInEl = form ? form.querySelector('input[name="check_in"]') : null;
            var checkIn = _checkInDate(checkInEl);
            if (checkIn) {
                var age = calculateAgeAtDate(birth, checkIn);
                if (age >= 18) {
                    return fail(
                        (t.notChild || 'La check-in, copilul va avea') + ' ' + age + ' '
                        + (t.ageLabel || 'ani') + '. '
                        + (t.mustBeUnder18 || 'Trebuie sa fie sub 18 ani.')
                    );
                }
            }
        }

        clear();
        return true;
    }

    document.addEventListener('focusout', function (e) {
        var el = e.target;
        if (el && el.matches && el.matches('input.js-dob-basics')) {
            validateDobBasics(el);
        }
    });

    document.addEventListener('input', function (e) {
        var el = e.target;
        // Re-judge a flagged field as soon as the mask completes so the
        // correction clears without waiting for blur.
        if (el && el.matches && el.matches('input.js-dob-basics[aria-invalid]') && el.value.length === 10) {
            validateDobBasics(el);
        }
    });

    // =========================================================================
    // PUBLIC API — namespaced under window.TravelBooking (with Novoton alias)
    // =========================================================================

    window.TravelBooking.validateExpectedAge = validateExpectedAge;
    window.TravelBooking.validateDobBasics = validateDobBasics;
    window.TravelBooking.handleDobKeydown = handleDobKeydown;
    window.TravelBooking.applyDobMask = applyDobMask;
    window.TravelBooking.parseDobMasked = parseDobMasked;
    window.TravelBooking.calculateAgeAtDate = calculateAgeAtDate;
    window.TravelBooking.updatePriceDisplay = updatePriceDisplay;
    window.TravelBooking.showRoomChangeWarning = showRoomChangeWarning;
    window.TravelBooking.acceptRoomChange = acceptRoomChange;
    window.TravelBooking.goBackToSearch = goBackToSearch;
    window.TravelBooking.triggerPriceRecalculation = triggerPriceRecalculation;
    window.TravelBooking.updateFormWithNewPricing = updateFormWithNewPricing;
    window.TravelBooking.showPriceRecalculationNotice = showPriceRecalculationNotice;

    // Backwards compatibility: expose on window for existing onblur/onclick handlers in TPL
    window.handleDobKeydown = handleDobKeydown;
    window.applyDobMask = applyDobMask;
    window.parseDobMasked = parseDobMasked;
    window.calculateAgeAtDate = calculateAgeAtDate;
    window.updatePriceDisplay = updatePriceDisplay;
    window.showRoomChangeWarning = showRoomChangeWarning;
    window.acceptRoomChange = acceptRoomChange;
    window.goBackToSearch = goBackToSearch;
    window.triggerPriceRecalculation = triggerPriceRecalculation;
    window.updateFormWithNewPricing = updateFormWithNewPricing;
    window.showPriceRecalculationNotice = showPriceRecalculationNotice;

    // =========================================================================
    // INITIALIZATION
    // =========================================================================

    log('Travel booking form validation loaded');


    // =========================================================================
    // INLINE REQUIRED-FIELD MESSAGES (shared booking page)
    // =========================================================================
    // The browser's own "Please fill in this field" bubble vanishes on the
    // next click and never says which guest it meant; novoton used alert().
    // On the shared booking page every missing name / gender / DOB gets an
    // inline message under its field instead, cleared as soon as it is filled.
    // Format errors stay with the DOB validators above.

    var _invalidFocused = false;

    function _fieldHost(el) {
        return (el.closest && el.closest('.travel-guest-field')) || el.parentElement;
    }

    function _setGroupInvalid(el, on) {
        var targets = el.type === 'radio' && el.name && el.form
            ? el.form.querySelectorAll('input[type="radio"][name="' + el.name.replace(/"/g, '\\"') + '"]')
            : [el];
        for (var i = 0; i < targets.length; i++) {
            if (on) {
                targets[i].setAttribute('aria-invalid', 'true');
            } else {
                targets[i].removeAttribute('aria-invalid');
            }
        }
    }

    /** A translation, or the fallback when it is missing / unseeded ("_key"). */
    function _t(key, fallback) {
        var v = _getTranslations()[key];
        return (typeof v === 'string' && v !== '' && v.charAt(0) !== '_') ? v : fallback;
    }

    /** The field's visible label text, without the required asterisk. */
    function _labelText(node) {
        if (!node) return '';
        var copy = node.cloneNode(true);
        var marks = copy.querySelectorAll('.travel-guest-required, .travel-muted-note');
        for (var i = 0; i < marks.length; i++) marks[i].remove();
        return String(copy.textContent || '').replace(/\s+/g, ' ').replace(/[\s*:]+$/, '').trim();
    }

    function _fieldLabel(el) {
        if (el.labels && el.labels.length) return _labelText(el.labels[0]);
        if (el.id && el.form) {
            var byFor = el.form.querySelector('label[for="' + el.id.replace(/"/g, '\\"') + '"]');
            if (byFor) return _labelText(byFor);
        }
        return '';
    }

    /**
     * "Please fill in the Last Name field." / "Please choose Male or
     * Female." — the message names what is missing, so the guest does not
     * have to work out which of eight fields it meant.
     */
    function _requiredMessage(el) {
        if (el.type === 'radio') {
            var options = [];
            var group = el.form && el.name
                ? el.form.querySelectorAll('input[type="radio"][name="' + el.name.replace(/"/g, '\\"') + '"]')
                : [el];
            for (var i = 0; i < group.length; i++) {
                var text = _labelText(group[i].closest ? group[i].closest('label') : null);
                if (text) options.push(text);
            }
            if (options.length === 2) {
                return _t('chooseBetween', 'Please choose [a] or [b].')
                    .replace('[a]', options[0]).replace('[b]', options[1]);
            }
            return _t('chooseOption', 'Please choose one option.');
        }
        var label = _fieldLabel(el);
        if (label) {
            return _t('fieldRequiredNamed', 'Please fill in the [field] field.').replace('[field]', label);
        }
        return _t('fieldRequired', 'Please fill in this field.');
    }

    /**
     * aria-invalid follows the messages actually shown for a field: several
     * validators (required, DOB format, age at check-in) can speak about the
     * same input, and one clearing its own message must not wipe the red
     * state while another still stands.
     */
    function _syncInvalid(input) {
        var host = _fieldHost(input);
        if (host && host.querySelector('.travel-field-error-message')) {
            input.setAttribute('aria-invalid', 'true');
        } else {
            input.removeAttribute('aria-invalid');
        }
    }

    document.addEventListener('invalid', function (e) {
        var el = e.target;
        if (!el || !el.closest || !el.closest('.travel-booking-page')) return;
        var v = el.validity || {};
        // Missing value, or only spaces (pattern on the name inputs).
        if (!v.valueMissing && !v.patternMismatch) return;
        e.preventDefault(); // inline message instead of the browser bubble
        var host = _fieldHost(el);
        if (!host) return;
        var msg = host.querySelector('.js-required-msg');
        if (!msg) {
            msg = document.createElement('span');
            msg.className = 'travel-field-error-message js-required-msg';
            msg.setAttribute('role', 'alert');
            host.appendChild(msg);
        }
        msg.textContent = _requiredMessage(el);
        _setGroupInvalid(el, true);
        if (!_invalidFocused) {
            // `invalid` fires once per bad field; focus only the first.
            _invalidFocused = true;
            try { el.focus(); } catch (err) {}
            setTimeout(function () { _invalidFocused = false; }, 0);
        }
    }, true);

    function _clearRequired(e) {
        var el = e.target;
        if (!el || !el.closest || !el.closest('.travel-booking-page')) return;
        var host = _fieldHost(el);
        var msg = host ? host.querySelector('.js-required-msg') : null;
        if (!msg) return;
        var filled = el.type === 'radio' ? el.checked : String(el.value || '').trim() !== '';
        if (filled) {
            msg.remove();
            if (el.type === 'radio') {
                _setGroupInvalid(el, false);
            } else {
                _syncInvalid(el);
            }
        }
    }

    document.addEventListener('input', _clearRequired);
    document.addEventListener('change', _clearRequired);

    window.TravelBooking.requiredMessage = _requiredMessage;
    window.TravelBooking.syncInvalid = _syncInvalid;

})();
