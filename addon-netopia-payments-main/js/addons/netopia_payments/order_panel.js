// NETOPIA Payments — the order page's NETOPIA panel (hooks/orders/details.post.tpl).
//
// The refund form asks before it sends: its submit button sits inside a
// <details> ("Refund 1.850,00 RON" → "Refund 1.850,00 RON to the card?").
// This keeps both labels in step with the amount typed, says whether the
// refund is full or partial, refuses an amount over what is left, and
// disables the submit after the first click (a second POST would be a
// second refund attempt; RefundAttemptStore is the server-side guard).
(function () {
    // "1850", "1850.5", "1.850,50", "1850,50" → 1850.5; NaN when not a number.
    function parseAmount(raw) {
        var s = String(raw || '').replace(/\s/g, '');
        if (s.indexOf(',') !== -1 && s.indexOf('.') !== -1) {
            s = s.replace(/\./g, '').replace(',', '.');
        } else {
            s = s.replace(',', '.');
        }
        return /^\d+(\.\d+)?$/.test(s) ? parseFloat(s) : NaN;
    }

    // Same shape as IpnHandler::formatAmount: 1.850,50 RON.
    function formatAmount(value, currency) {
        var fixed = (Math.round(value * 100) / 100).toFixed(2).split('.');
        var whole = fixed[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        return whole + ',' + fixed[1] + (currency ? ' ' + currency : '');
    }

    function setup(form) {
        if (form.getAttribute('data-np-ready') === '1') {
            return;
        }
        form.setAttribute('data-np-ready', '1');

        var input = form.querySelector('#netopia_refund_amount');
        var review = form.querySelector('[data-np-refund-review]');
        var open = form.querySelector('[data-np-refund-open]');
        var title = form.querySelector('[data-np-refund-confirm-title]');
        var text = form.querySelector('[data-np-refund-confirm-text]');
        var error = form.querySelector('[data-np-refund-error]');
        var submit = form.querySelector('[name="dispatch[netopia_refund.process]"]');
        var remaining = parseFloat(form.getAttribute('data-np-remaining') || '0');
        var currency = form.getAttribute('data-np-currency') || '';
        if (!input) {
            return;
        }

        var sync = function () {
            var value = parseAmount(input.value);
            var valid = !isNaN(value) && value > 0 && value <= remaining + 0.001;
            var shown = formatAmount(isNaN(value) ? 0 : value, currency);
            if (open) {
                open.textContent = (form.getAttribute('data-txt-button') || '').replace('{amount}', shown);
            }
            if (title) {
                title.textContent = (form.getAttribute('data-txt-confirm') || '').replace('{amount}', shown);
            }
            if (text) {
                text.textContent = form.getAttribute(value >= remaining - 0.001 ? 'data-txt-full' : 'data-txt-partial') || '';
            }
            if (error) {
                error.hidden = valid || input.value.trim() === '';
                error.textContent = value > remaining + 0.001 ? form.getAttribute('data-txt-too-much') || '' : '';
                if (!error.textContent) {
                    error.hidden = true;
                }
            }
            if (submit) {
                submit.disabled = !valid;
            }
            if (!valid && review) {
                review.open = false;
            }
            return valid;
        };

        input.addEventListener('input', sync);
        var allBtn = form.querySelector('[data-np-refund-all]');
        if (allBtn) {
            allBtn.addEventListener('click', function () {
                input.value = remaining.toFixed(2);
                sync();
            });
        }
        var cancel = form.querySelector('[data-np-refund-cancel]');
        if (cancel && review) {
            cancel.addEventListener('click', function () {
                review.open = false;
            });
        }
        if (open && review) {
            open.addEventListener('click', function (e) {
                if (!review.open && !sync()) {
                    e.preventDefault();
                }
            });
        }
        form.addEventListener('submit', function (e) {
            if (!sync()) {
                e.preventDefault();
                return;
            }
            // The controller reads a plain decimal ("1850.50"), not "1.850,50".
            input.value = parseAmount(input.value).toFixed(2);
            if (submit) {
                // After the browser has captured the button's name/value.
                setTimeout(function () {
                    submit.disabled = true;
                    submit.classList.add('disabled');
                }, 0);
            }
        });
        sync();
    }

    function init(context) {
        var scope = context && context.querySelectorAll ? context : document;
        Array.prototype.slice.call(scope.querySelectorAll('form.netopia-order__refund')).forEach(setup);
    }

    window.NetopiaOrderPanel = { init: init, parseAmount: parseAmount, formatAmount: formatAmount };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            init(document);
        });
    } else {
        init(document);
    }
    var jq = window.jQuery;
    if (jq && typeof jq.ceEvent === 'function') {
        jq.ceEvent('on', 'ce.commoninit', function (context) {
            init(context && context[0] ? context[0] : document);
        });
    }
})();
