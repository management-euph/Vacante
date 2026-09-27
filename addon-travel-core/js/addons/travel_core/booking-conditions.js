/**
 * "What are my booking conditions?" modal — shared by every provider's
 * booking form (components/booking_conditions_modal.tpl), plus the other
 * small behaviours of the shared booking page: the facility "+N more"
 * toggle and the mobile bottom bar's submit proxy.
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

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { armFeatureLists(document); });
    } else {
        armFeatureLists(document);
    }
    window.TravelConditions.armFeatureLists = armFeatureLists;
})();
