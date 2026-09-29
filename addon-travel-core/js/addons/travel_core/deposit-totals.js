/**
 * Deposit bookings (components/deposit_totals.tpl): the store's own total
 * line holds the amount the payment method charges — the deposit — so it is
 * relabelled "Deposit — paid now" beside the full order total, and the
 * place-order button names the amount charged now. Idempotent: CS-Cart
 * re-renders checkout blocks over AJAX, so it runs again on every commoninit.
 */
(function () {
    'use strict';

    var TOTAL_TITLES = '.ty-cart-statistic__total-title, .ty-checkout-summary__total-title';
    var PLACE_ORDER = 'button[name="dispatch[checkout.place_order]"], #litecheckout_place_order';

    function apply() {
        var box = document.querySelector('[data-travel-deposit-totals]');
        if (!box) return;
        var nowLabel = box.getAttribute('data-now-label') || '';
        var payLabel = box.getAttribute('data-pay-label') || '';

        document.querySelectorAll(TOTAL_TITLES).forEach(function (el) {
            if (nowLabel) el.textContent = nowLabel;
        });
        document.querySelectorAll(PLACE_ORDER).forEach(function (btn) {
            if (!payLabel || btn.querySelector('.travel-deposit-pay-now')) return;
            var span = document.createElement('span');
            span.className = 'travel-deposit-pay-now';
            span.textContent = ' · ' + payLabel;
            btn.appendChild(span);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', apply);
    } else {
        apply();
    }
    if (window.jQuery && jQuery.ceEvent) {
        jQuery.ceEvent('on', 'ce.commoninit', apply);
    }
})();
