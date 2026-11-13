/**
 * Novoton → Hotels (novoton_hotels.manage): the bulk "Disable products"
 * button asks first, saying how many hotels are checked and how many of
 * their products are on sale now (data-nvt-on-sale on each row's checkbox).
 * Delegated on the document, so it also works after CS-Cart's AJAX
 * navigation redraws the list.
 */
(function () {
    'use strict';

    document.addEventListener('click', function (event) {
        var btn = event.target instanceof Element ? event.target.closest('#nvt_disable_btn') : null;
        if (!btn) {
            return;
        }
        var checked = document.querySelectorAll('.nvt-row-check:checked');
        if (!checked.length) {
            event.preventDefault();
            window.alert(btn.getAttribute('data-nvt-none') || '');
            return;
        }
        var onSale = 0;
        for (var i = 0; i < checked.length; i++) {
            if (checked[i].getAttribute('data-nvt-on-sale') === '1') {
                onSale++;
            }
        }
        var text = (btn.getAttribute('data-nvt-confirm') || '')
            .replace('[n]', String(checked.length))
            .replace('[on_sale]', String(onSale));
        if (!window.confirm(text)) {
            event.preventDefault();
        }
    }, true);
}());
