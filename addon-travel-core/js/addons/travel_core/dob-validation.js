/**
 * Travel Core - DOB Validation + Price Recalculation + Desktop/Mobile Fix
 * Version: 3.1.0
 *
 * Features:
 * 1. Prevents Date of Birth from being set in the future
 * 2. Recalculates price when child age changes based on DOB
 * 3. Enforces desktop/mobile visibility as failsafe
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

    // Namespace setup
    window.TravelBooking = window.TravelBooking || {};
    window.Novoton = window.TravelBooking; // Backwards compatibility

    // Provider-agnostic config/translation helpers
    function _getConfig() {
        return window.TravelBookingConfig || window.NovotonConfig || {};
    }

    function _getTranslations() {
        return window.TravelTranslations || window.NovotonTranslations || {};
    }

    function _getUtils() {
        return window.TravelUtils || window.NovotonUtils || {};
    }

    // Debug mode - only log when explicitly enabled
    var DEBUG = !!(_getConfig().debug);

    function log(message, data) {
        if (DEBUG && console && console.log) {
            if (data !== undefined) {
                console.log('[TravelBooking] ' + message, data);
            } else {
                console.log('[TravelBooking] ' + message);
            }
        }
    }

    // =========================================================================
    // SHARED DATE PARSING — delegates to TravelUtils when available
    // =========================================================================

    function parseDate(value) {
        var utils = _getUtils();
        if (utils && utils.parseDate) {
            return utils.parseDate(value);
        }
        // Inline fallback (same logic as TravelUtils.parseDate)
        if (!value) return null;
        if (/^\d{4}-\d{2}-\d{2}$/.test(value)) {
            return new Date(value + 'T00:00:00');
        }
        var parts = value.split(/[\/.]/);
        if (parts.length === 3) {
            return new Date(parts[2], parts[1] - 1, parts[0]);
        }
        return new Date(value);
    }

    function calculateAge(dob, targetDate) {
        var utils = _getUtils();
        if (utils && utils.calculateAge) {
            return utils.calculateAge(dob, targetDate);
        }
        var age = targetDate.getFullYear() - dob.getFullYear();
        var m = targetDate.getMonth() - dob.getMonth();
        if (m < 0 || (m === 0 && targetDate.getDate() < dob.getDate())) {
            age--;
        }
        return age;
    }

    // Debounce timer for price recalculation
    var priceRecalcTimer = null;
    var isRecalculating = false;

    // Run on DOM ready
    document.addEventListener('DOMContentLoaded', function() {
        log('Initializing DOB validation, price recalc, and visibility fix');
        initDOBValidation();
        fixDesktopMobileVisibility();
        observeGuestChanges();
    });

    // Also run on window load
    window.addEventListener('load', function() {
        fixDesktopMobileVisibility();
    });

    /**
     * Initialize Date of Birth validation
     * Prevents dates in the future from being selected
     */
    function initDOBValidation() {
        // Find all DOB input fields
        var dobFields = document.querySelectorAll(
            'input[name*="dob"], input[name*="date_of_birth"], input[name*="birth_date"], ' +
            'input.guest-dob-input, input[data-field="dob"], input.dob-masked-input'
        );

        var today = new Date().toISOString().split('T')[0];

        dobFields.forEach(function(field) {
            // The shared booking-page guest cards (.js-dob-basics) are
            // validated by booking-form-validation.js — future dates
            // included, with the inline message + red state. Checking them
            // here too duplicated the message and EMPTIED the field, so the
            // next submit also said "Please fill in …".
            if (field.classList && field.classList.contains('js-dob-basics')) {
                return;
            }

            // Set max date to today
            if (field.type === 'date') {
                field.setAttribute('max', today);
            }

            // Add validation on change
            field.addEventListener('change', function() {
                validateDOBField(this);
            });

            // Add validation on blur
            field.addEventListener('blur', function() {
                validateDOBField(this);
            });
        });

        log('DOB validation initialized for ' + dobFields.length + ' fields');
    }

    /**
     * Validate a single DOB field
     */
    function validateDOBField(field) {
        var value = field.value;

        // Skip if empty
        if (!value) {
            clearDOBError(field);
            return true;
        }

        // Parse date using shared utility
        var selectedDate = parseDate(value);
        var today = new Date();

        // Set time to midnight for accurate comparison
        today.setHours(0, 0, 0, 0);
        if (selectedDate) {
            selectedDate.setHours(0, 0, 0, 0);
        }

        // Check if date is in the future
        if (selectedDate && selectedDate > today) {
            showDOBError(field);
            return false;
        }

        // Valid date - clear any error
        clearDOBError(field);

        // A73: Trigger price recalculation if this is a child field on booking form
        if (isChildDOBField(field)) {
            triggerPriceRecalculation(field);
        }

        return true;
    }

    /**
     * Show DOB error message
     */
    function showDOBError(field) {
        // Get error message from translations or use default
        var tr = _getTranslations();
        var errorMessage = tr.dobCannotBeFuture || 'Date of birth cannot be in the future';

        // Mark field as invalid (the shared error style keys on both)
        field.classList.add('novoton-dob-error');
        field.setAttribute('aria-invalid', 'true');

        // Find or create error message element
        var fieldId = field.id || field.name || ('dob-field-' + Math.random().toString(36).substr(2, 9));
        var errorId = fieldId + '-dob-error';
        var existingError = document.getElementById(errorId);

        if (!existingError && field.parentNode) {
            var errorElement = document.createElement('div');
            errorElement.id = errorId;
            errorElement.className = 'novoton-dob-error-message';
            // Build safely with DOM methods
            var icon = document.createElement('i');
            icon.className = 'icon-warning-sign';
            errorElement.appendChild(icon);
            errorElement.appendChild(document.createTextNode(' ' + errorMessage));

            // Insert after the field
            field.parentNode.insertBefore(errorElement, field.nextSibling);
        } else if (existingError) {
            existingError.style.display = 'flex';
        }

        // Set custom validity for form validation
        if (field.setCustomValidity) {
            field.setCustomValidity(errorMessage);
        }

        // Clear field and refocus
        field.value = '';
        field.focus();

        log('DOB validation error: Date is in the future');
    }

    /**
     * Clear DOB error message
     */
    function clearDOBError(field) {
        field.classList.remove('novoton-dob-error');
        field.removeAttribute('aria-invalid');

        // Hide error message if exists
        var errorId = (field.id || field.name);
        if (errorId) {
            var errorElement = document.getElementById(errorId + '-dob-error');
            if (errorElement) {
                errorElement.style.display = 'none';
            }
        } else {
            // Fallback: find error element by adjacency (matches random ID from showDOBError)
            var nextEl = field.nextElementSibling;
            if (nextEl && nextEl.className === 'novoton-dob-error-message') {
                nextEl.style.display = 'none';
            }
        }

        // Clear custom validity
        if (field.setCustomValidity) {
            field.setCustomValidity('');
        }
    }

    /**
     * A73: Check if field is a child DOB field on booking form
     */
    function isChildDOBField(field) {
        var name = field.name || '';
        var id = field.id || '';

        // Skip if field has an inline onblur handler (booking_form.tpl's
        // validateAndCheckAge already handles price recalculation for these
        // fields — triggering here too would cause duplicate AJAX calls)
        if (field.getAttribute('onblur')) return false;

        // Check if it's a child DOB field (matches pattern like guests[room1_child_1][dob])
        return (name.includes('child') && name.includes('dob')) ||
               (id.includes('child') && id.includes('dob'));
    }

    /**
     * A73: Trigger price recalculation (debounced, with race condition guard)
     */
    function triggerPriceRecalculation(changedField) {
        // Clear any pending recalculation
        if (priceRecalcTimer) {
            clearTimeout(priceRecalcTimer);
        }

        // Debounce - wait 500ms after last change before recalculating
        // Race condition fix: check isRecalculating INSIDE the timeout,
        // before initiating the request
        priceRecalcTimer = setTimeout(function() {
            if (isRecalculating) {
                log('Price recalculation already in progress, re-queuing');
                triggerPriceRecalculation(changedField);
                return;
            }
            recalculatePrice(changedField);
        }, 500);
    }

    /**
     * A73: Recalculate price based on current DOB values
     */
    function recalculatePrice(changedField) {
        // Find the booking form
        var form = document.querySelector('.novoton-reservation-form form, .novoton-booking-form form, #novoton-booking-form');
        if (!form) {
            log('Booking form not found');
            return;
        }

        // Get booking data from form hidden fields (no optional chaining for browser compat)
        var _el;
        _el = form.querySelector('input[name="hotel_id"]'); var hotelId = (_el && _el.value) ? _el.value : '';
        _el = form.querySelector('input[name="room_id"]'); var roomId = (_el && _el.value) ? _el.value : '';
        _el = form.querySelector('input[name="board_id"]'); var boardId = (_el && _el.value) ? _el.value : '';
        _el = form.querySelector('input[name="check_in"]'); var checkIn = (_el && _el.value) ? _el.value : '';
        _el = form.querySelector('input[name="nights"]'); var nights = parseInt((_el && _el.value) ? _el.value : '7', 10);
        _el = form.querySelector('input[name="adults"]'); var adults = parseInt((_el && _el.value) ? _el.value : '2', 10);
        _el = form.querySelector('input[name="total_price"]'); var originalPrice = parseFloat((_el && _el.value) ? _el.value : '0');
        _el = form.querySelector('input[name="package_name"]'); var packageName = (_el && _el.value) ? _el.value : '';

        if (!hotelId || !checkIn) {
            log('Missing hotel_id or check_in for price recalculation');
            return;
        }

        // Collect all children ages from DOB fields
        var childrenAges = collectChildrenAges(form, checkIn);

        // Update the hidden children_ages field
        var childrenAgesField = form.querySelector('input[name="children_ages"]');
        if (childrenAgesField) {
            childrenAgesField.value = childrenAges.join(',');
        }

        // Update the age display for the changed field
        updateAgeDisplay(changedField, checkIn);

        log('Recalculating price with ages:', childrenAges);

        // Show loading indicator
        showPriceLoading();
        isRecalculating = true;

        // Make AJAX request
        var requestData = {
            hotel_id: hotelId,
            room_id: roomId,
            board_id: boardId,
            check_in: checkIn,
            nights: nights,
            adults: adults,
            children_ages: childrenAges,
            package_name: packageName,
            original_price: originalPrice
        };

        // Build clean AJAX URL — only dispatch param, all data in JSON body.
        // Do NOT inherit parent page URL params (children_ages[], etc.) as they
        // cause PHP warnings in CS-Cart's init that corrupt the JSON response.
        // Use pre-built URL from template (includes storefront_id) with fallback
        var cfg = _getConfig();
        var ajaxUrl = cfg.ajaxRecalcUrl
            || ((window.Tygh && window.Tygh.current_location) || window.location.origin) + '/index.php?dispatch=' + (cfg.ajaxRecalcDispatch || 'novoton_booking.ajax_recalculate_price');

        fetch(ajaxUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify(requestData)
        })
        .then(function(response) {
            return response.json();
        })
        .then(function(data) {
            isRecalculating = false;
            hidePriceLoading();

            if (data.success) {
                updatePriceDisplay(data.new_price, data.formatted_price, data.price_difference);

                // Update the hidden total_price field
                var totalPriceField = form.querySelector('input[name="total_price"]');
                if (totalPriceField) {
                    totalPriceField.value = data.new_price;
                }

                log('Price updated: ' + data.new_price + ' EUR');
            } else {
                log('Price recalculation failed: ' + (data.message || ''));
                showPriceError(data.message || 'Price calculation error');
            }
        })
        .catch(function(error) {
            isRecalculating = false;
            hidePriceLoading();
            log('Price recalculation error: ' + error);
        });
    }

    /**
     * A73: Collect all children ages from DOB fields
     */
    function collectChildrenAges(form, checkIn) {
        var ages = [];
        var checkInDate = parseDate(checkIn);
        if (!checkInDate) return ages;

        // Find all child DOB fields
        var childDobFields = form.querySelectorAll(
            'input[name*="child"][name*="dob"], input[id*="child"][id*="dob"]'
        );

        childDobFields.forEach(function(field) {
            var dobValue = field.value;
            if (!dobValue) {
                // If no DOB, try to get the original age from data attribute
                var guestEntry = field.closest('.guest-entry-child, .guest-entry');
                var originalAge = guestEntry ? parseInt(guestEntry.dataset.originalAge || '0', 10) : 0;
                if (originalAge > 0 && originalAge <= 17) {
                    ages.push(originalAge);
                }
                return;
            }

            // Parse DOB using shared utility
            var dob = parseDate(dobValue);
            if (!dob || isNaN(dob.getTime())) return;

            // Calculate age at check-in using shared utility
            var age = calculateAge(dob, checkInDate);

            // Only include valid child ages (0-17)
            if (age >= 0 && age <= 17) {
                ages.push(age);
            }
        });

        return ages;
    }

    /**
     * A73: Update the age display next to the child DOB field
     */
    function updateAgeDisplay(field, checkIn) {
        var dobValue = field.value;
        if (!dobValue) return;

        // Parse DOB using shared utility
        var dob = parseDate(dobValue);
        if (!dob || isNaN(dob.getTime())) return;

        var checkInDate = parseDate(checkIn);
        if (!checkInDate) return;

        // Calculate age using shared utility
        var age = calculateAge(dob, checkInDate);

        // Find the age display element - try different selectors
        var fieldId = field.id || '';
        var roomMatch = fieldId.match(/r(\d+)/);
        var childMatch = fieldId.match(/c(\d+)/);

        if (roomMatch && childMatch) {
            var ageDisplayId = 'child_age_display_r' + roomMatch[1] + '_c' + childMatch[1];
            var ageDisplay = document.getElementById(ageDisplayId);

            if (ageDisplay) {
                // Use singular/plural form (Romanian: "1 an", "2 ani")
                var ageLabel;
                var tr = _getTranslations();
                if (age === 1) {
                    ageLabel = tr.ageLabelSingular || 'year';
                } else {
                    ageLabel = tr.ageLabel || 'years';
                }
                ageDisplay.textContent = '(' + age + ' ' + ageLabel + ')';

                // Show warning if age is 18+
                if (age >= 18) {
                    ageDisplay.style.color = 'var(--nvt-danger, #dc3545)';
                    // Build safely with DOM methods
                    var icon = document.createElement('i');
                    icon.className = 'icon-warning-sign';
                    ageDisplay.appendChild(document.createTextNode(' '));
                    ageDisplay.appendChild(icon);
                    ageDisplay.appendChild(document.createTextNode(' ' + (tr.childMustBeUnder18 || 'Must be under 18')));
                } else {
                    ageDisplay.style.color = '';
                }
            }
        }

        // Update hidden age field
        var guestEntry = field.closest('.guest-entry-child, .guest-entry');
        if (guestEntry) {
            var ageInput = guestEntry.querySelector('input[name*="[age]"]');
            if (ageInput) {
                ageInput.value = age;
            }
        }
    }

    /**
     * A73: Show loading indicator on price
     */
    function showPriceLoading() {
        var priceElements = document.querySelectorAll(
            '.price-total, .booking-price-box .price-total, .novoton-total-price'
        );

        priceElements.forEach(function(el) {
            el.dataset.originalHtml = el.innerHTML;
            el.innerHTML = '<i class="icon-refresh"></i>';
            el.classList.add('novoton-price-loading');
        });
    }

    /**
     * A73: Hide loading indicator and update price
     */
    function hidePriceLoading() {
        var priceElements = document.querySelectorAll(
            '.price-total, .booking-price-box .price-total, .novoton-total-price'
        );

        priceElements.forEach(function(el) {
            el.classList.remove('novoton-price-loading');
        });
    }

    /**
     * A73: Update the displayed price
     */
    function updatePriceDisplay(newPrice, formattedPrice, priceDifference) {
        var priceElements = document.querySelectorAll(
            '.price-total, .booking-price-box .price-total, .novoton-total-price'
        );

        // Use server-formatted price (includes currency symbol and rounding)
        // Fallback: format with currency from TravelTranslations
        if (!formattedPrice) {
            var currency = _getTranslations().currency || '€';
            formattedPrice = newPrice.toFixed(2) + ' ' + currency;
        }

        priceElements.forEach(function(el) {
            // Use innerHTML since formatted_price may contain <sup> for decimals
            el.innerHTML = formattedPrice;

            // Show price change indicator
            if (priceDifference !== 0) {
                el.style.transition = 'color 0.3s';
                if (priceDifference > 0) {
                    el.style.color = 'var(--nvt-danger, #dc3545)';
                } else {
                    el.style.color = 'var(--nvt-success, #28a745)';
                }

                // Reset color after 3 seconds
                setTimeout(function() {
                    el.style.color = '';
                }, 3000);
            }
        });

    }

    /**
     * A73: Show price change notification
     */
    // eslint-disable-next-line no-unused-vars -- A73 feature helper, retained; caller wired separately
    function showPriceChangeNotification(text, type) {
        // Remove existing notification
        var existing = document.getElementById('novoton-price-notification');
        if (existing) {
            existing.remove();
        }

        var notification = document.createElement('div');
        notification.id = 'novoton-price-notification';
        notification.className = 'novoton-toast novoton-toast--' + type;

        // Build safely with DOM methods
        var icon = document.createElement('i');
        icon.className = (type === 'increase') ? 'icon-arrow-up' : 'icon-arrow-down';
        notification.appendChild(icon);

        var tr = _getTranslations();
        var label = (type === 'increase')
            ? (tr.priceIncreased || 'Price increased')
            : (tr.priceDecreased || 'Price decreased');
        notification.appendChild(document.createTextNode(' ' + label + ': ' + text));

        document.body.appendChild(notification);

        // Remove after 5 seconds
        setTimeout(function() {
            notification.style.opacity = '0';
            setTimeout(function() {
                notification.remove();
            }, 300);
        }, 5000);
    }

    /**
     * A73: Show price calculation error
     */
    function showPriceError(message) {
        var priceElements = document.querySelectorAll(
            '.price-total, .booking-price-box .price-total'
        );

        priceElements.forEach(function(el) {
            if (el.dataset.originalHtml) {
                el.innerHTML = el.dataset.originalHtml;
            }
        });

        log('Price error: ' + message);
    }

    /**
     * Observe for dynamically added guest fields
     */
    var observeRetries = 0;
    var MAX_OBSERVE_RETRIES = 10;

    function observeGuestChanges() {
        var containers = document.querySelectorAll(
            '.novoton-guests-container, .guest-details-container, #guest-details, ' +
            '.booking-guests, .novoton-booking-form, #booking-form'
        );

        if (containers.length === 0) {
            // Try again later if not found yet, with a retry limit
            if (observeRetries++ < MAX_OBSERVE_RETRIES) {
                setTimeout(observeGuestChanges, 2000);
            }
            return;
        }

        containers.forEach(function(container) {
            var observer = new MutationObserver(function(mutations) {
                mutations.forEach(function(mutation) {
                    if (mutation.addedNodes.length > 0) {
                        setTimeout(initDOBValidation, 100);
                    }
                });
            });

            observer.observe(container, { childList: true, subtree: true });
        });

        log('Guest observer initialized for ' + containers.length + ' containers');
    }

    /**
     * Fix desktop/mobile visibility as JavaScript failsafe.
     * Uses matchMedia for consistent breakpoint with CSS.
     * Uses CSS classes instead of inline !important styles.
     */
    function fixDesktopMobileVisibility() {
        var mobileQuery = window.matchMedia('(max-width: 768px)');
        var isMobile = mobileQuery.matches;

        // Find mobile-only elements
        var mobileElements = document.querySelectorAll(
            '.novoton-mobile-only, .novoton-mobile-only.novoton-room-card'
        );

        // Find desktop-only elements
        var desktopElements = document.querySelectorAll(
            '.novoton-desktop-only, .novoton-table-header.novoton-desktop-only, ' +
            '.result-row.novoton-desktop-only'
        );

        if (isMobile) {
            // MOBILE: Show mobile, hide desktop
            mobileElements.forEach(function(el) {
                el.classList.add('novoton-mobile-visible');
                el.classList.remove('novoton-mobile-hidden');
            });
            desktopElements.forEach(function(el) {
                el.classList.add('novoton-desktop-hidden');
                el.classList.remove('novoton-desktop-visible');
            });
        } else {
            // DESKTOP: Show desktop, hide mobile
            mobileElements.forEach(function(el) {
                el.classList.add('novoton-mobile-hidden');
                el.classList.remove('novoton-mobile-visible');
            });
            desktopElements.forEach(function(el) {
                el.classList.add('novoton-desktop-visible');
                el.classList.remove('novoton-desktop-hidden');
            });
        }

        log('Visibility fix applied. Mobile: ' + isMobile + ' | Mobile els: ' + mobileElements.length + ' | Desktop els: ' + desktopElements.length);
    }

    // Re-run visibility fix on resize (debounced)
    var resizeTimer;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(fixDesktopMobileVisibility, 250);
    });

    /**
     * Global function to validate all DOB fields before form submission
     */
    function validateAllDOBFields() {
        var dobFields = document.querySelectorAll(
            'input[name*="dob"], input[name*="date_of_birth"], input[name*="birth_date"], ' +
            'input.guest-dob-input, input[data-field="dob"], input.dob-masked-input'
        );
        var allValid = true;

        dobFields.forEach(function(field) {
            if (!validateDOBField(field)) {
                allValid = false;
            }
        });

        if (!allValid) {
            var firstError = document.querySelector('.novoton-dob-error');
            if (firstError) {
                firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }

        return allValid;
    }

    /**
     * Validate DOB for check-in age calculation
     */
    function validateDOBForCheckIn(dobValue, checkInDate) {
        var result = { valid: true, age: 0, error: null };

        if (!dobValue) return result;

        var dob = parseDate(dobValue);
        var today = new Date();
        var checkIn = checkInDate ? parseDate(checkInDate) : today;
        if (!checkIn) checkIn = today;

        today.setHours(0, 0, 0, 0);
        if (dob) dob.setHours(0, 0, 0, 0);

        if (!dob || dob > today) {
            result.valid = false;
            result.error = _getTranslations().dobCannotBeFuture
                || 'Date of birth cannot be in the future';
            return result;
        }

        // Calculate age at check-in using shared utility
        result.age = calculateAge(dob, checkIn);
        return result;
    }

    // Expose functions globally via namespace
    window.TravelBooking.initDOBValidation = initDOBValidation;
    window.TravelBooking.fixVisibility = fixDesktopMobileVisibility;
    window.TravelBooking.validateAllDOBFields = validateAllDOBFields;
    window.TravelBooking.validateDOBForCheckIn = validateDOBForCheckIn;

    // Backwards compatibility
    window.initNovotonDOBValidation = initDOBValidation;
    window.novotonFixVisibility = fixDesktopMobileVisibility;
    window.validateAllDOBFields = validateAllDOBFields;
    window.validateDOBForCheckIn = validateDOBForCheckIn;

})();
