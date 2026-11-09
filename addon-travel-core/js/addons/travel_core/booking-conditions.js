/**
 * "What are my booking conditions?" modal — shared by every provider's
 * booking form (components/booking_conditions_modal.tpl), plus the other
 * small behaviours of the shared booking page: the facility "+N more"
 * toggle, the mobile bottom bar's submit proxy, the "same last name as
 * Adult 1" shortcut and the guest form kept in view beside a tall summary.
 *
 * Open/close only. The CONTENT is either server-rendered (sphinx, whose terms
 * arrive with the verified offer) or appended per room by the provider's own
 * script (novoton, whose terms only come back with a price quote) — see
 * window.TravelConditions.setRoomSection below, which novoton's
 * booking-form.js calls once per re-priced room.
 *
 * Listeners are delegated on `document`, so the modal keeps working after any
 * markup swap (the PDP inline-results flow replaces whole regions).
 */
(function () {
    'use strict';

    var MODAL_ID = 'travel-conditions-modal';

    function modal() {
        return document.getElementById(MODAL_ID);
    }

    function open(el) {
        if (!el) return;
        el.classList.remove('travel-is-hidden');
        el.setAttribute('aria-hidden', 'false');
        document.body.classList.add('travel-conditions-open');
    }

    function close(el) {
        if (!el) return;
        el.classList.add('travel-is-hidden');
        el.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('travel-conditions-open');
    }

    document.addEventListener('click', function (e) {
        var opener = e.target.closest ? e.target.closest('[data-travel-conditions-open]') : null;
        if (opener) {
            e.preventDefault();
            open(modal());
            return;
        }
        var closer = e.target.closest ? e.target.closest('[data-travel-conditions-close]') : null;
        if (closer) {
            e.preventDefault();
            close(modal());
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            var el = modal();
            if (el && !el.classList.contains('travel-is-hidden')) {
                close(el);
            }
        }
    });

    /**
     * Add or replace one room's conditions section.
     *
     * Keyed by room number so re-pricing the same room updates its section
     * instead of stacking duplicates, while a multi-room booking accumulates
     * one section per room — which is the whole point of the modal.
     *
     * @param {number|string} roomNum
     * @param {{title?: string, paymentLabel?: string, payment?: string[],
     *          cancellationLabel?: string, cancellation?: string[]}} data
     */
    function setRoomSection(roomNum, data) {
        var host = document.getElementById('travel-conditions-rooms');
        if (!host) return;

        var payment = (data && data.payment) || [];
        var cancellation = (data && data.cancellation) || [];
        var key = String(roomNum || 1);
        var existing = host.querySelector('[data-room="' + key + '"]');

        if (!payment.length && !cancellation.length) {
            if (existing) existing.remove();
            toggleEmptyNote(host);
            return;
        }

        var section = document.createElement('section');
        section.className = 'travel-conditions-room';
        section.setAttribute('data-room', key);

        if (data.title) {
            var h4 = document.createElement('h4');
            h4.className = 'travel-conditions-room__title';
            h4.textContent = data.title;
            section.appendChild(h4);
        }
        appendGroup(section, data.paymentLabel, payment);
        appendGroup(section, data.cancellationLabel, cancellation);

        if (existing) {
            host.replaceChild(section, existing);
        } else {
            insertOrdered(host, section, key);
        }
        toggleEmptyNote(host);
    }

    function appendGroup(section, label, lines) {
        if (!lines.length) return;
        var group = document.createElement('div');
        group.className = 'travel-conditions-group';
        if (label) {
            var strong = document.createElement('strong');
            strong.textContent = label;
            group.appendChild(strong);
        }
        var ul = document.createElement('ul');
        lines.forEach(function (line) {
            var li = document.createElement('li');
            li.textContent = line;
            ul.appendChild(li);
        });
        group.appendChild(ul);
        section.appendChild(group);
    }

    /** Keep sections in room order however late a given room's quote lands. */
    function insertOrdered(host, section, key) {
        var siblings = host.querySelectorAll('.travel-conditions-room');
        for (var i = 0; i < siblings.length; i++) {
            if (parseInt(siblings[i].getAttribute('data-room'), 10) > parseInt(key, 10)) {
                host.insertBefore(section, siblings[i]);
                return;
            }
        }
        host.appendChild(section);
    }

    function toggleEmptyNote(host) {
        var note = document.getElementById('travel-conditions-empty');
        if (!note) return;
        note.classList.toggle('travel-is-hidden', host.children.length > 0);
    }

    /**
     * Replace one room's section with server-rendered HTML (novoton: the
     * price re-check returns the shared booking_terms_timeline partial, so
     * the modal shows the same timeline the sidebar card does).
     *
     * @param {number|string} roomNum
     * @param {string} title
     * @param {string} html trusted server markup ('' removes the section)
     */
    function setRoomHtml(roomNum, title, html) {
        var host = document.getElementById('travel-conditions-rooms');
        if (!host) return;
        var key = String(roomNum || 1);
        var existing = host.querySelector('[data-room="' + key + '"]');
        if (!html) {
            if (existing) existing.remove();
            toggleEmptyNote(host);
            return;
        }
        var section = document.createElement('section');
        section.className = 'travel-conditions-room';
        section.setAttribute('data-room', key);
        if (title) {
            var h4 = document.createElement('h4');
            h4.className = 'travel-conditions-room__title';
            h4.textContent = title;
            section.appendChild(h4);
        }
        var body = document.createElement('div');
        body.innerHTML = html;
        section.appendChild(body);
        if (existing) {
            host.replaceChild(section, existing);
        } else {
            insertOrdered(host, section, key);
        }
        toggleEmptyNote(host);
    }

    window.TravelConditions = { setRoomSection: setRoomSection, setRoomHtml: setRoomHtml };

    // ── Facility chips: "+N more" (booking_sidebar.tpl) ──────────────────
    // Without JS every chip shows; the collapse is armed only here.
    function armFeatureLists(root) {
        var lists = (root || document).querySelectorAll('[data-travel-features]');
        for (var i = 0; i < lists.length; i++) {
            if (lists[i].querySelector('[data-travel-features-more]')) {
                lists[i].classList.add('is-collapsible');
            }
        }
    }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('[data-travel-features-more]') : null;
        if (!btn) return;
        e.preventDefault();
        var list = btn.closest('[data-travel-features]');
        if (!list) return;
        var expanded = list.classList.toggle('is-expanded');
        btn.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        var more = btn.getAttribute('data-label-more') || btn.textContent;
        var less = btn.getAttribute('data-label-less') || more;
        if (!btn.getAttribute('data-label-more')) btn.setAttribute('data-label-more', more);
        btn.textContent = expanded ? less : more;
    });

    // ── Mobile bottom bar: its button submits the real form ─────────────
    // Clicking the form's own submit button (instead of form.submit()) keeps
    // every guard on it: native validation, the inline messages, novoton's
    // disabled state while a price is unverified.
    document.addEventListener('click', function (e) {
        var proxy = e.target.closest ? e.target.closest('[data-travel-submit-proxy]') : null;
        if (!proxy) return;
        e.preventDefault();
        var form = document.getElementById(proxy.getAttribute('data-travel-submit-proxy'));
        var submit = form ? form.querySelector('button[type="submit"], input[type="submit"]') : null;
        if (submit && !submit.disabled) {
            submit.click();
        }
    });

    // ── "Same last name as Adult 1" (booking_guest_hint.tpl) ─────────────
    // Ticked: every other guest takes the first guest's last name, kept in
    // step while the box stays ticked. Unticked: the copies are taken back,
    // and each guest gets what they had before the tick (usually nothing).
    // Typing another guest's own last name unticks the box and leaves every
    // name as it is. Shown only where the form has two guests or more.
    var SURNAME = 'input[autocomplete="family-name"]';
    // On a guest's last-name input while the box holds it: the name copied
    // in (data-travel-surname-copy) and the one it replaced
    // (data-travel-surname-before).
    var COPY = 'travelSurnameCopy';
    var BEFORE = 'travelSurnameBefore';
    var copyingSurname = false;

    function surnames(form) {
        return Array.prototype.slice.call(form.querySelectorAll(SURNAME));
    }

    function sameSurnameBox(form) {
        return form.querySelector('[data-travel-same-surname] input[type="checkbox"]');
    }

    function setSurname(input, value) {
        input.value = value;
        // The validation script clears a "required" message on these.
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function copySurname(form) {
        var inputs = surnames(form);
        copyingSurname = true;
        for (var i = 1; i < inputs.length; i++) {
            if (inputs[i].dataset[COPY] === undefined) {
                inputs[i].dataset[BEFORE] = inputs[i].value;
            }
            inputs[i].dataset[COPY] = inputs[0].value;
            if (inputs[i].value !== inputs[0].value) {
                setSurname(inputs[i], inputs[0].value);
            }
        }
        copyingSurname = false;
    }

    // The box unticked by the guest: each copy still standing goes back to
    // what the guest had before the tick.
    function uncopySurname(form) {
        var inputs = surnames(form);
        copyingSurname = true;
        for (var i = 1; i < inputs.length; i++) {
            var copied = inputs[i].dataset[COPY];
            if (copied === undefined) continue;
            var before = inputs[i].dataset[BEFORE] || '';
            forgetCopy(inputs[i]);
            if (inputs[i].value === copied && before !== copied) {
                setSurname(inputs[i], before);
            }
        }
        copyingSurname = false;
    }

    function forgetCopy(input) {
        delete input.dataset[COPY];
        delete input.dataset[BEFORE];
    }

    function armSameSurname(root) {
        var boxes = (root || document).querySelectorAll('[data-travel-same-surname]');
        for (var i = 0; i < boxes.length; i++) {
            var form = boxes[i].closest('form');
            boxes[i].hidden = !form || surnames(form).length < 2;
        }
    }

    document.addEventListener('change', function (e) {
        var box = e.target;
        if (!box.matches || !box.matches('[data-travel-same-surname] input[type="checkbox"]') || !box.form) return;
        if (box.checked) {
            copySurname(box.form);
        } else {
            uncopySurname(box.form);
        }
    });

    document.addEventListener('input', function (e) {
        var el = e.target;
        if (copyingSurname || !el.matches || !el.matches(SURNAME) || !el.form) return;
        var box = sameSurnameBox(el.form);
        if (!box || !box.checked) return;
        if (surnames(el.form)[0] === el) {
            copySurname(el.form);
        } else {
            // A last name of their own: the names no longer all match, and
            // each stays as it is now.
            box.checked = false;
            surnames(el.form).forEach(forgetCopy);
        }
    });

    // ── The cancellation card's folded line (booking_sidebar.tpl) ────────
    // The card is folded to its title and one line saying what cancelling
    // today would cost. Novoton fills the card's body after its price
    // re-check; the terms it brings carry their own line (a <template> in
    // booking_terms_timeline.tpl), copied up into the title here.
    function syncCancelPreview() {
        var slot = document.getElementById('travel-cancel-preview');
        var body = document.getElementById('travel-cancel-body');
        var src = body ? body.querySelector('template.travel-cancel-preview-src') : null;
        if (!slot || !src) return;
        slot.replaceChildren(src.content.cloneNode(true));
    }

    function armCancelPreview() {
        var body = document.getElementById('travel-cancel-body');
        if (!body || !window.MutationObserver) return;
        new window.MutationObserver(syncCancelPreview).observe(body, { childList: true });
    }

    // ── Phone: the summary folded into one sticky bar (booking_sidebar.tpl) ──
    // At 900 px and less the bar (name, stay, guests, total) stays at the top
    // and the summary's cards fold under it; a tap opens or closes them. The
    // CSS folds only on a phone, so a wide screen always shows the column.
    function armSummaryBar(root) {
        var toggles = (root || document).querySelectorAll('[data-travel-sumbar]');
        for (var i = 0; i < toggles.length; i++) {
            var layout = toggles[i].closest('.travel-booking-layout');
            if (!layout) continue;
            layout.classList.add('travel-booking-layout--folded');
            toggles[i].setAttribute('aria-expanded', 'false');
        }
    }

    document.addEventListener('click', function (e) {
        var toggle = e.target.closest ? e.target.closest('[data-travel-sumbar]') : null;
        var layout = toggle ? toggle.closest('.travel-booking-layout') : null;
        if (!layout) return;
        var open = toggle.getAttribute('aria-expanded') !== 'true';
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        layout.classList.toggle('travel-booking-layout--folded', !open);
    });

    // ── A trip's extras move the total (Sphinx's package and circuit pages) ──
    // Ticking a service adds its price to the total; unticking one the page
    // opened with ticked takes it off (the page's total includes those). Every
    // .price-total (summary, bars, button) counts to the new figure, "+130 €"
    // shows beside "Preț total" for a moment, and screen readers hear the
    // new total once. A guest who asked for reduced motion gets the figure at
    // once. The server prices the booking again on submit; this only shows it.
    function moneyOf(section) {
        return {
            symbol: section.getAttribute('data-money-symbol') || '',
            after: section.getAttribute('data-money-after') === '1',
            decimals: Math.max(0, parseInt(section.getAttribute('data-money-decimals') || '0', 10) || 0),
            decimal: section.getAttribute('data-money-decimal') || '.',
            thousands: section.getAttribute('data-money-thousands') || ''
        };
    }

    function formatMoney(amount, money) {
        var parts = Math.abs(amount).toFixed(money.decimals).split('.');
        var whole = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, money.thousands);
        var number = (amount < 0 ? '-' : '') + (money.decimals > 0 ? whole + money.decimal + parts[1] : whole);
        if (!money.symbol) return number;
        return money.after ? number + ' ' + money.symbol : money.symbol + number;
    }

    function extrasTotal(section) {
        var total = parseFloat(section.getAttribute('data-base')) || 0;
        var inputs = section.querySelectorAll('input[data-amount]');
        for (var i = 0; i < inputs.length; i++) {
            var amount = parseFloat(inputs[i].getAttribute('data-amount')) || 0;
            if (inputs[i].checked && !inputs[i].defaultChecked) total += amount;
            if (!inputs[i].checked && inputs[i].defaultChecked) total -= amount;
        }
        return Math.round(total * 100) / 100;
    }

    function showTotals(text) {
        var totals = document.querySelectorAll('.travel-booking-page .price-total');
        for (var i = 0; i < totals.length; i++) totals[i].textContent = text;
    }

    function reducedMotion() {
        return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    }

    function moveTotal(section) {
        var money = moneyOf(section);
        var to = extrasTotal(section);
        var from = typeof section._travelShown === 'number' ? section._travelShown : (parseFloat(section.getAttribute('data-base')) || 0);
        var delta = Math.round((to - from) * 100) / 100;
        section._travelShown = to;
        if (section._travelFrame && window.cancelAnimationFrame) window.cancelAnimationFrame(section._travelFrame);

        var chip = document.querySelector('[data-travel-total-delta]');
        if (chip && delta !== 0) {
            chip.textContent = (delta > 0 ? '+' : '\u2212') + formatMoney(Math.abs(delta), money);
            chip.className = 'travel-total-delta ' + (delta > 0 ? 'travel-total-delta--up' : 'travel-total-delta--down');
            void chip.offsetWidth; // restart the animation
            chip.classList.add('travel-total-delta--show');
        }
        var flash = document.querySelectorAll('.travel-booking-page .travel-bsidebar-total');
        for (var f = 0; f < flash.length; f++) {
            flash[f].classList.remove('travel-total--flash');
            void flash[f].offsetWidth;
            flash[f].classList.add('travel-total--flash');
        }
        var live = section.querySelector('[data-travel-extras-live]');
        if (live) live.textContent = (section.getAttribute('data-total-label') || '') + ': ' + formatMoney(to, money);

        if (reducedMotion() || !window.requestAnimationFrame || delta === 0) {
            showTotals(formatMoney(to, money));
            return;
        }
        var start = null;
        var step = function (now) {
            if (start === null) start = now;
            var k = Math.min(1, (now - start) / 450);
            var eased = 1 - Math.pow(1 - k, 3);
            showTotals(formatMoney(k < 1 ? Math.round(from + (to - from) * eased) : to, money));
            if (k < 1) section._travelFrame = window.requestAnimationFrame(step);
        };
        section._travelFrame = window.requestAnimationFrame(step);
    }

    document.addEventListener('change', function (e) {
        var input = e.target;
        if (!input || !input.matches || !input.matches('[data-travel-extras] input[data-amount]')) return;
        moveTotal(input.closest('[data-travel-extras]'));
    });

    // ── The guest form beside a tall summary ──────────────────────────────
    // A short form stays in view, with its button, while the summary
    // scrolls past it (booking-pages.css: sticky). Only while the whole
    // form fits on the screen: a taller one would hide its own end.
    function fitStickyForm() {
        var col = document.querySelector('.travel-booking-page .travel-booking-col-main');
        var side = document.querySelector('.travel-booking-page .travel-bsidebar');
        if (!col || !side) return;
        var fits = window.innerWidth > 900
            && col.offsetHeight + 40 <= window.innerHeight
            && col.offsetHeight < side.offsetHeight;
        col.classList.toggle('travel-booking-col-main--sticky', fits);
    }

    function armStickyForm() {
        fitStickyForm();
        var pending = false;
        var refit = function () {
            if (pending) return;
            pending = true;
            window.requestAnimationFrame(function () { pending = false; fitStickyForm(); });
        };
        window.addEventListener('resize', refit);
        // Re-priced rooms, notices and messages change both columns' height.
        if (window.ResizeObserver) {
            var observer = new window.ResizeObserver(refit);
            var parts = document.querySelectorAll('.travel-booking-page .travel-booking-col-main, .travel-booking-page .travel-bsidebar');
            for (var i = 0; i < parts.length; i++) observer.observe(parts[i]);
        }
    }

    function ready() {
        armFeatureLists(document);
        armSameSurname(document);
        armCancelPreview();
        armSummaryBar(document);
        armStickyForm();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', ready);
    } else {
        ready();
    }
    window.TravelConditions.armFeatureLists = armFeatureLists;
    window.TravelConditions.armSameSurname = armSameSurname;
    window.TravelConditions.fitStickyForm = fitStickyForm;
    window.TravelConditions.armCancelPreview = armCancelPreview;
    window.TravelConditions.armSummaryBar = armSummaryBar;
    window.TravelConditions.formatMoney = formatMoney;
    window.TravelConditions.extrasTotal = extrasTotal;
    window.TravelConditions.syncCancelPreview = syncCancelPreview;
})();
