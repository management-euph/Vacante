/**
 * Travel Core — the booking card on the admin order page
 * (components/order_booking_card.tpl): its copy buttons put the supplier
 * reference or the balance pay link on the clipboard and say so on the
 * button for two seconds. Where the clipboard is unavailable (plain-http
 * admin, old browser) the text is offered in a prompt to copy by hand.
 *
 * One listener on the document: cards that arrive later (admin AJAX
 * navigation) work without re-initialising.
 */
(function () {
    'use strict';

    if (window.__travelOrderCardCopy) {
        return;
    }
    window.__travelOrderCardCopy = true;

    function copy(text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text);
        }

        return new Promise(function (resolve, reject) {
            var field = document.createElement('textarea');
            field.value = text;
            field.setAttribute('readonly', '');
            field.style.position = 'fixed';
            field.style.opacity = '0';
            document.body.appendChild(field);
            field.select();
            var copied;
            try {
                copied = document.execCommand('copy');
            } catch (e) {
                copied = false;
            }
            document.body.removeChild(field);
            if (copied) {
                resolve();
            } else {
                reject(new Error('copy failed'));
            }
        });
    }

    document.addEventListener('click', function (event) {
        var button = event.target instanceof Element ? event.target.closest('[data-ca-travel-copy]') : null;
        if (!button) {
            return;
        }
        event.preventDefault();
        var text = button.getAttribute('data-ca-travel-copy') || '';
        var label = button.getAttribute('data-ca-travel-label') || button.textContent;
        button.setAttribute('data-ca-travel-label', label);
        copy(text).then(function () {
            button.textContent = button.getAttribute('data-ca-travel-copied') || label;
            setTimeout(function () {
                button.textContent = label;
            }, 2000);
        }, function () {
            window.prompt('', text);
        });
    });
}());
