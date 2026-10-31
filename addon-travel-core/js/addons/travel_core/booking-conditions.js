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
    // A family's guests take the first guest's last name, kept in step
    // while the box stays ticked; typing another guest's own last name
    // unticks it. Shown only where the form has two guests or more.
    var SURNAME = 'input[autocomplete="family-name"]';
    var copyingSurname = false;

    function surnames(form) {
        return Array.prototype.slice.call(form.querySelectorAll(SURNAME));
    }

    function sameSurnameBox(form) {
        return form.querySelector('[data-travel-same-surname] input[type="checkbox"]');
    }

    function copySurname(form) {
        var inputs = surnames(form);
        copyingSurname = true;
        for (var i = 1; i < inputs.length; i++) {
            if (inputs[i].value !== inputs[0].value) {
                inputs[i].value = inputs[0].value;
                // The validation script clears a "required" message on these.
                inputs[i].dispatchEvent(new Event('input', { bubbles: true }));
                inputs[i].dispatchEvent(new Event('change', { bubbles: true }));
            }
        }
        copyingSurname = false;
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
        if (box.matches && box.matches('[data-travel-same-surname] input[type="checkbox"]') && box.checked && box.form) {
            copySurname(box.form);
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
            box.checked = false;
        }
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
})();
