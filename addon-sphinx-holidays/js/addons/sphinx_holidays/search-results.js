/**
 * Sphinx search results page — async result polling + terms modal.
 *
 * Extracted from search.tpl's inline <script> blocks so vitest can import
 * and exercise the REAL code (tests/js/sphinx-search-results.test.mjs).
 * The template keeps only the config prelude:
 *   window.__sphinxSearchParams  — searched dates/party (Smarty-interpolated)
 *   window.__sphinxConfig        — poll settings + translated labels
 * and loads this file via {script src="js/addons/sphinx_holidays/search-results.js"}.
 *
 * Both IIFEs bail out early on pages without their DOM (results root /
 * terms modal), but always attach their pure helpers to window.SphinxSearch
 * first — that namespace is the vitest seam.
 */

window.SphinxSearch = window.SphinxSearch || {};

// "N rooms (M offers)" line under the availability pill — singular/plural
// picked per count, labels from __sphinxConfig (CS-Cart translations).
window.SphinxSearch.badgeRoomsLine = function (roomCount, offerCount, labels) {
    var l = labels || {};
    var roomLabel = (roomCount === 1) ? (l.room || 'room') : (l.rooms || 'rooms');
    var offerLabel = (offerCount === 1) ? (l.offer || 'offer') : (l.offers || 'offers');
    return roomCount + ' ' + roomLabel + ' (' + offerCount + ' ' + offerLabel + ')';
};

// The inline originals ran exactly where the markup already existed; as an
// external file the placement is the loader's choice, so run at DOM-ready.
// Parse a JSON data-attribute; null on absence or bad JSON. The search
// params + poll config ride #sphinx-results-container attributes so they
// travel WITH the node through the engine's inline swap (the old inline
// <script> globals did not reliably re-execute after a swap).
window.SphinxSearch.readJsonAttr = function (el, name) {
    if (!el) return null;
    var raw = el.getAttribute(name);
    if (!raw) return null;
    try { return JSON.parse(raw); } catch (e) { return null; }
};

window.SphinxSearch.onReady = function (fn) {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', fn);
    } else {
        fn();
    }
};

// ── Async result polling ────────────────────────────────────────────────
// ── Async result polling ────────────────────────────────────────────────
// Armed at DOM-ready for the server-rendered results page AND re-armed on
// travel:results-swapped, fired by the booking engine after it swaps a fresh
// inline search into the page (product pages included). The search id/status
// handshake lives on #sphinx-results-container — INSIDE the swap region — so
// every swap delivers a fresh id; arming twice for one id is a no-op.
(function () {
    var armedSearchId = null;

    function armPoll() {
    var container = document.getElementById('sphinx-results-container');
    if (!container) return;

    var searchId = container.getAttribute('data-search-id');
    var status = container.getAttribute('data-search-status');
    if (!searchId || status !== 'pending') return;
    if (searchId === armedSearchId) return;
    armedSearchId = searchId;

    var skeleton = document.querySelector('.sphinx-loading-skeleton');
    var noResults = document.getElementById('sphinx-no-results');

    if (skeleton) skeleton.style.display = 'block';
    if (noResults) noResults.style.display = 'none';

    var accumulated = 0;
    var revealed = false;
    var cursor = null;
    var pollCount = 0;
    var lastAlternatives = [];
    var cfg = window.SphinxSearch.readJsonAttr(container, 'data-client-config')
        || window.__sphinxConfig || {};
    var maxPolls = cfg.maxPolls || 30;
    var pollInterval = cfg.pollInterval || 250;
    var searchParams = window.SphinxSearch.readJsonAttr(container, 'data-client-params')
        || window.__sphinxSearchParams || {};

    function renderCard(result) {
        var stars = '';
        if (result.star_rating) {
            for (var i = 0; i < parseInt(result.star_rating, 10); i++) stars += '★';
        }
        var labels = cfg.labels || {};
        var price = parseFloat(result.price || 0).toFixed(2).replace('.', ',');
        var perNight = searchParams.nights > 0
            ? '<span class="travel-price-per-night sphinx-price-per-night">' +
              (parseFloat(result.price) / searchParams.nights).toFixed(2).replace('.', ',') +
              ' / ' + (labels.perNight || 'night') + '</span>'
            : '';

        var bookingUrl = 'index.php?dispatch=sphinx_booking.booking_form' +
            '&offer_id=' + encodeURIComponent(result.offer_id || '') +
            '&hotel_id=' + encodeURIComponent(result.hotel_id || '') +
            '&product_id=' + encodeURIComponent(result.product_id || searchParams.product_id || '') +
            '&check_in=' + encodeURIComponent(searchParams.check_in) +
            '&check_out=' + encodeURIComponent(searchParams.check_out) +
            '&adults=' + searchParams.adults +
            '&children=' + searchParams.children +
            '&children_ages=' + encodeURIComponent(searchParams.children_ages) +
            '&rooms=' + searchParams.rooms +
            // Offer room/board names: booking form + add_to_cart fall back to
            // these when the verify response omits the names (display-only).
            '&room_name=' + encodeURIComponent(result.room_name || result.room_type || '') +
            '&board_name=' + encodeURIComponent(result.board_name || result.board_type || '');

        var datesLine = searchParams.check_in && searchParams.check_out
            ? formatAltDate(searchParams.check_in) + '.' + searchParams.check_in.slice(0, 4) +
              ' - ' + formatAltDate(searchParams.check_out) + '.' + searchParams.check_out.slice(0, 4) +
              (searchParams.nights > 0 ? ' (' + searchParams.nights + ' ' + (labels.nights || 'nights') + ')' : '')
            : '';

        var starsLabel = stars
            ? (labels.starsRating || '%s-star rating').replace('%s', String(parseInt(result.star_rating, 10)))
            : '';

        // A multi-room search returns ONE offer for the whole party: list
        // every room with its guests (room_lines, SearchOfferNormalizer).
        var roomLines = Array.isArray(result.room_lines) ? result.room_lines : [];
        var multiRoom = roomLines.length > 1;

        var card = document.createElement('div');
        card.className = 'travel-offer-card sphinx-offer-card';
        card.setAttribute('data-offer-id', result.offer_id || '');
        card.innerHTML =
            '<div class="travel-offer-hotel sphinx-offer-hotel">' +
                (result.hotel_image
                    ? '<img src="' + result.hotel_image + '" alt="" class="travel-offer-image sphinx-offer-image" width="88" height="64" loading="lazy">'
                    : '') +
                '<div class="sphinx-offer-hotel-info">' +
                    '<h3 class="travel-offer-hotel-name sphinx-offer-hotel-name"></h3>' +
                    (stars ? '<span class="travel-hotel-stars sphinx-stars" role="img" aria-label="' + starsLabel + '">' + stars + '</span>' : '') +
                    (result.destination ? '<span class="travel-offer-location sphinx-offer-location"></span>' : '') +
                '</div>' +
            '</div>' +
            '<div class="travel-offer-details sphinx-offer-details">' +
                (multiRoom
                    ? '<ol class="travel-offer-rooms sphinx-offer-rooms"></ol>'
                    : '<div class="travel-offer-room sphinx-offer-room"><span class="sx-room"></span></div>') +
                '<div class="travel-offer-board sphinx-offer-board sx-board"></div>' +
                (datesLine ? '<div class="travel-offer-dates sphinx-offer-dates">' + datesLine + '</div>' : '') +
                (result.confirmation === 'immediate'
                    ? '<span class="travel-offer-badge--instant">✓ ' + (labels.instantConfirmation || 'Instant confirmation') + '</span>'
                    : '') +
                '<a href="#" class="travel-terms-link sphinx-terms-link">' + (labels.cancellationAndPaymentTerms || 'Condiții de Plată și Anulare') + '</a>' +
            '</div>' +
            '<div class="travel-offer-price-action sphinx-offer-price-action">' +
                '<div class="travel-offer-price sphinx-offer-price">' +
                    '<span class="travel-price-amount sphinx-price-amount">' + price + '</span> ' +
                    '<span class="travel-price-currency sphinx-price-currency">' + (result.currency || searchParams.currency) + '</span>' +
                    perNight +
                    (multiRoom
                        ? '<span class="travel-price-rooms sphinx-price-rooms"></span>'
                        : '') +
                    '<span class="travel-price-includes">' + (labels.includesTaxes || 'Includes taxes and commissions') + '</span>' +
                '</div>' +
                '<a href="' + bookingUrl + '" class="travel-offer-book-btn sphinx-offer-book-btn">' + (labels.bookNow || 'Book now') + '</a>' +
            '</div>';

        // Set text nodes safely to avoid XSS
        card.querySelector('.sphinx-offer-hotel-name').textContent = result.hotel_name || '';
        if (result.destination) card.querySelector('.sphinx-offer-location').textContent = result.destination;
        if (multiRoom) {
            var list = card.querySelector('.sphinx-offer-rooms');
            roomLines.forEach(function (line, i) {
                var li = document.createElement('li');
                li.className = 'travel-offer-rooms__item';
                [
                    ['travel-offer-rooms__label', fill(labels.roomN || 'Room [num]', { num: i + 1 })],
                    ['travel-offer-rooms__name', line.name || result.room_name || ''],
                    ['travel-offer-rooms__guests', guestsText(line, labels)]
                ].forEach(function (part) {
                    var span = document.createElement('span');
                    span.className = part[0];
                    span.textContent = part[1];
                    li.appendChild(span);
                });
                list.appendChild(li);
            });
            card.querySelector('.sphinx-price-rooms').textContent =
                fill(labels.totalForRooms || 'Total for [count] rooms', { count: roomLines.length });
        } else {
            card.querySelector('.sx-room').textContent = result.room_name || result.room_type || '';
        }
        card.querySelector('.sx-board').textContent = result.board_name || result.board_type || '';

        return card;
    }

    function fill(template, values) {
        return String(template).replace(/\[(\w+)]/g, function (match, key) {
            return key in values ? String(values[key]) : match;
        });
    }

    function guestsText(line, labels) {
        var adults = parseInt(line.adults, 10) || 0;
        var children = parseInt(line.children, 10) || 0;
        var text = adults === 1
            ? (labels.adultsOne || '1 adult')
            : fill(labels.adultsMany || '[count] adults', { count: adults });
        if (children === 1) {
            text += ' + ' + (labels.childrenOne || '1 child');
        } else if (children > 1) {
            text += ' + ' + fill(labels.childrenMany || '[count] children', { count: children });
        }
        return text;
    }

    function appendResults(results) {
        if (!results || !results.length) return;
        for (var i = 0; i < results.length; i++) {
            container.appendChild(renderCard(results[i]));
            accumulated++;
        }
    }

    function reveal() {
        // First offers in: drop the skeleton so the user sees results
        // immediately, while the poll loop keeps draining in the background.
        if (revealed) return;
        revealed = true;
        if (skeleton) skeleton.style.display = 'none';
    }

    function formatAltDate(iso) {
        var p = (iso || '').split('-');
        return p.length === 3 ? p[2] + '.' + p[1] : iso;
    }

    function renderAlternatives() {
        var box = document.getElementById('sphinx-alt-dates');
        if (!box || !noResults || !lastAlternatives.length) return;
        var heading = noResults.getAttribute('data-alt-heading') || 'Try nearby dates:';
        var fromLabel = noResults.getAttribute('data-alt-from') || 'from';
        var html = '<strong>' + heading + '</strong><div class="travel-alt-date-list">';
        for (var i = 0; i < lastAlternatives.length; i++) {
            var alt = lastAlternatives[i];
            if (!alt || !alt.check_in || !alt.check_out) continue;
            var link = new URL(window.location.href);
            link.searchParams.set('check_in', alt.check_in);
            link.searchParams.set('check_out', alt.check_out);
            var label = formatAltDate(alt.check_in) + ' – ' + formatAltDate(alt.check_out);
            if (alt.price > 0) {
                label += ' · ' + fromLabel + ' ' + Number(alt.price).toFixed(0) + ' ' + (alt.currency === 'EUR' ? '€' : (alt.currency || ''));
            }
            html += '<a class="ty-btn travel-alt-date-chip" href="' + link.toString() + '">' + label + '</a>';
        }
        html += '</div>';
        box.innerHTML = html;
        box.style.display = '';
    }

    function finish() {
        if (skeleton) skeleton.style.display = 'none';
        if (accumulated === 0 && noResults) {
            noResults.style.display = '';
            renderAlternatives();
        }
    }

    function poll() {
        if (pollCount >= maxPolls) {
            finish();
            return;
        }
        pollCount++;

        var url = 'index.php?dispatch=sphinx_booking.search_poll' +
                  '&search_id=' + encodeURIComponent(searchId) +
                  (cursor ? '&cursor=' + encodeURIComponent(cursor) : '');

        fetch(url, { credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.status === 'error') {
                    finish();
                    return;
                }
                if (data.alternatives && data.alternatives.length) {
                    lastAlternatives = data.alternatives;
                }
                appendResults(data.results || []);

                // Render early: as soon as the hotel has offers, show them and
                // drop the skeleton for a fast first paint. Do NOT stop — keep
                // polling so search_poll drains the stream and caches the
                // COMPLETE set (also catches offers split across pages).
                // docs/adr/0001-availability-early-render-and-metrics.md
                if (accumulated > 0) {
                    reveal();
                }

                // Keep polling while a cursor remains (the continuation signal);
                // maxPolls bounds the loop as a safety net. On a maxPolls bail we
                // do NOT finalize, so a partial set is never cached as complete.
                cursor = data.next_cursor || null;
                if (!cursor) {
                    finish();
                    return;
                }
                setTimeout(poll, pollInterval);
            })
            .catch(function() {
                finish();
            });
    }

    // Kick off polling
    poll();
    }

    window.SphinxSearch.onReady(armPoll);
    document.addEventListener('travel:results-swapped', armPoll);
})();

// ── Payment/cancellation terms modal ────────────────────────────────────
window.SphinxSearch.onReady(function () {
    // Everything is looked up LAZILY (at click/render time): on a product
    // page the modal, the offer cards and the __sphinxConfig labels only
    // enter the DOM with the first inline-results swap, AFTER this script
    // has run — an element captured here would be null forever, and an
    // early return would never bind the delegated handlers below.
    function labels() {
        var cfg = window.SphinxSearch.readJsonAttr(
            document.getElementById('sphinx-results-container'),
            'data-client-config'
        ) || window.__sphinxConfig || {};
        return cfg.labels || {};
    }
    function modalEl() { return document.getElementById('sphinx-terms-modal'); }
    function bodyEl() { return document.getElementById('sphinx-terms-modal-body'); }
    // Test seam: the pure display helpers (hoisted declarations), reachable
    // even on pages without the modal markup.
    window.SphinxSearch.terms = {
        esc: esc,
        lbl: lbl,
        fmtAmount: fmtAmount,
        fmtPct: fmtPct,
        renderTerms: renderTerms
    };
    var lastFocus = null;
    var cache = {};

    function esc(v) {
        var d = document.createElement('div');
        d.textContent = (v == null) ? '' : String(v);
        return d.innerHTML;
    }
    // CS-Cart returns the literal "_key.name" for a MISSING language var —
    // a truthy string, so `cfg.x || fallback` never fires. Treat empty or
    // "_"-prefixed values as missing so a raw key can never render.
    function lbl(v, fb) {
        v = (v == null) ? '' : String(v);
        return (v === '' || v.charAt(0) === '_') ? fb : v;
    }
    function openModal() {
        var modal = modalEl();
        if (!modal) return;
        lastFocus = document.activeElement;
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        var c = modal.querySelector('.travel-terms-modal__close');
        if (c) c.focus();
    }
    function closeModal() {
        var modal = modalEl();
        if (modal) modal.style.display = 'none';
        document.body.style.overflow = '';
        if (lastFocus && lastFocus.focus) { try { lastFocus.focus(); } catch (e) {} }
    }
    function section(title, lines) {
        if (!lines || !lines.length) return '';
        var items = '';
        for (var i = 0; i < lines.length; i++) items += '<li>' + esc(lines[i]) + '</li>';
        return '<div class="travel-terms-modal__section"><strong>' + esc(title) + '</strong><ul>' + items + '</ul></div>';
    }
    function fmtAmount(a, cur) {
        var n = parseFloat(a || 0);
        var s = (n % 1 === 0) ? String(Math.round(n)) : n.toFixed(2);
        return cur ? s + ' ' + cur : s;
    }
    function pctNum(p) {
        if (p == null || isNaN(parseFloat(p))) return '';
        var n = Math.round(parseFloat(p) * 10) / 10;
        return (n % 1 === 0) ? String(Math.round(n)) : String(n);
    }
    function fmtPct(p) {
        var s = pctNum(p);
        return s === '' ? '' : ' <span class="travel-terms-timeline__pct">(' + esc(s) + '%)</span>';
    }
    // One timeline PER schedule (payment vs cancellation) \u2014 deliberately NOT
    // merged: a reader who only cares about payment shouldn't have to parse
    // cancellation nodes, and vice versa. Rules arrive date-sorted from the
    // endpoint. Copy is strictly "due by" (checkout charges once; nothing is
    // auto-charged).
    function cap(s) {
        s = String(s || '');
        return s.charAt(0).toUpperCase() + s.slice(1);
    }
    function renderTrack(rules, heading, kind, total, cur) {
        if (!rules || !rules.length) return '';
        var cfg = labels();
        // Each node is headed by ITS DATE at the timeline dot ("P\u00e2n\u0103 la
        // 20.06.2026" / "De la 01.07.2026"), with a terse amount row below.
        var dateLabel = kind === 'pay' ? lbl(cfg.termsUntil, 'p\u00e2n\u0103 la') : lbl(cfg.termsFrom, 'de la');
        var html = '<p class="travel-terms-timeline__heading">' + esc(heading) + '</p>';
        html += '<ul class="travel-terms-timeline">';
        for (var i = 0; i < rules.length; i++) {
            var r = rules[i];
            html += '<li class="travel-terms-timeline__node">';
            // Date gutter LEFT of the rail (tracker style): small preposition
            // over a bold date, right-aligned against the dot.
            html += '<div class="travel-terms-timeline__date">' + esc(cap(dateLabel)) + ' <strong>' + esc(r.date) + '</strong></div>';
            if (kind === 'pay') {
                html += '<div class="travel-terms-timeline__row travel-terms-timeline__row--pay">'
                    + esc(lbl(cfg.termsDueBy, 'De achitat')) + ': <strong>'
                    + esc(fmtAmount(r.amount, cur)) + '</strong>' + fmtPct(r.percent) + '</div>';
            } else {
                var nonref = total > 0 && parseFloat(r.amount) >= total
                    ? ' <span class="travel-terms-timeline__tag--nonref">' + esc(lbl(cfg.termsNonRefundable, 'Nerambursabil')) + '</span>'
                    : '';
                // Penalty severity leads: "Penalizare 20%: 763 EUR" — the
                // percent is the instantly comparable signal; the amount is
                // the consequence detail (payment rows stay amount-first).
                var pctLead = pctNum(r.percent);
                html += '<div class="travel-terms-timeline__row travel-terms-timeline__row--cancel">'
                    + esc(lbl(cfg.termsPenalty, 'Penalizare')) + (pctLead !== '' ? ' <strong>' + esc(pctLead) + '%</strong>' : '') + ': <strong>'
                    + esc(fmtAmount(r.amount, cur)) + '</strong>' + nonref + '</div>';
            }
            html += '</li>';
        }
        html += '</ul>';
        return html;
    }
    function renderTerms(data) {
        var cfg = labels();
        var body = bodyEl();
        if (!body) return;
        var html = '';
        // status 'partial': verify is down, so the detailed schedule is
        // genuinely unavailable, but the search result carried is_free and
        // that fact is accurate. Say which part is missing, then show what we
        // do know — strictly better than a blanket "nothing can be shown".
        if (data.status === 'partial' && data.notice) {
            html += '<p class="travel-terms-modal__notice">' + esc(data.notice) + '</p>';
        }
        if (data.is_free && !data.free_until) {
            html += '<div class="travel-terms-modal__free">\u2713 ' + esc(lbl(cfg.freeCancellation, 'Anulare gratuit\u0103')) + '</div>';
        } else if (data.free_until) {
            // free_until IS the first penalty date (earliest `since`), so the
            // copy must read "before <date>" \u2014 "until" would wrongly imply
            // the date itself is still free.
            html += '<div class="travel-terms-modal__free">\u2713 ' + esc(lbl(cfg.freeCancellationUntil, 'Anulare gratuit\u0103 \u00eenainte de')) + ' <strong>' + esc(data.free_until) + '</strong></div>';
        }
        // PER-TRACK shape mapping. The API is binary per block \u2014 structured
        // `rules` XOR prose `text` (populated only "when we cannot parse the
        // fees"), never partial. A track with rules renders as a timeline; a
        // track without renders the API's parsed text lines. Never decide
        // globally: a prose payment track must not be dropped just because
        // the cancellation track happens to be structured.
        var total = parseFloat(data.schedule_total || 0);
        var cur = data.currency || '';
        var payHeading = lbl(cfg.paymentTerms, 'Termeni de plat\u0103');
        var cancelHeading = lbl(cfg.cancellationPolicy, 'Politica de anulare');
        html += (data.payment_rules && data.payment_rules.length)
            ? renderTrack(data.payment_rules, payHeading, 'pay', total, cur)
            : section(payHeading, data.payment_terms);
        html += (data.cancellation_rules && data.cancellation_rules.length)
            ? renderTrack(data.cancellation_rules, cancelHeading, 'cancel', total, cur)
            : section(cancelHeading, data.cancellation_fees);
        if (html === '') html = '<p>' + esc(lbl(cfg.noTermsInfo, 'Nu exist\u0103 condi\u021bii specifice pentru aceast\u0103 ofert\u0103.')) + '</p>';
        body.innerHTML = html + debugPanel(data);
    }
    // Raw provider payload, present only when the addon's "Enable debug
    // logging" setting is on (the server omits the key entirely otherwise).
    // Shows payment_terms / cancellation_fees exactly as the API sent them,
    // so an empty modal can be read as "is_loaded: false" rather than guessed
    // at. Escaped and rendered as text \u2014 never parsed, never executed.
    function debugPanel(data) {
        if (!data || !data.debug) return '';
        var json;
        try {
            json = JSON.stringify(data.debug, null, 4);
        } catch (e) {
            return '';
        }
        return '<details class="travel-terms-modal__debug" open>'
            + '<summary>API response (debug)</summary>'
            + '<pre>' + esc(json) + '</pre>'
            + '</details>';
    }
    function showUnavailable(data) {
        var body = bodyEl();
        if (!body) return;
        body.innerHTML = '<p class="travel-terms-modal__unavailable">' + esc(lbl(labels().termsUnavailable, 'Condi\u021biile nu sunt disponibile. V\u0103 rug\u0103m c\u0103uta\u021bi din nou.')) + '</p>' + debugPanel(data);
    }
    // Verify SERVICE down (5xx/transport) \u2014 a re-search cannot help, so the
    // copy must not suggest one.
    function showOutage(data) {
        var body = bodyEl();
        if (!body) return;
        body.innerHTML = '<p class="travel-terms-modal__unavailable">' + esc(lbl(labels().termsOutage, 'Condi\u021biile nu pot fi afi\u0219ate momentan. V\u0103 rug\u0103m \u00eencerca\u021bi din nou \u00een c\u00e2teva minute.')) + '</p>' + debugPanel(data);
    }
    function loadTerms(offerId) {
        if (cache[offerId]) { renderTerms(cache[offerId]); return; }
        var body = bodyEl();
        if (body) {
            body.innerHTML = '<div class="travel-terms-modal__loading"><span class="travel-spinner"></span> ' + esc(lbl(labels().termsLoading, 'Se \u00eencarc\u0103 condi\u021biile...')) + '</div>';
        }
        fetch('index.php?dispatch=sphinx_booking.offer_terms&offer_id=' + encodeURIComponent(offerId), { credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data || (data.status !== 'ok' && data.status !== 'partial')) {
                    if (data && data.status === 'outage') {
                        showOutage(data);
                    } else {
                        showUnavailable(data);
                    }
                    return;
                }
                // A partial answer is NOT cached: it is missing the schedule
                // only because verify happens to be down, and the next click
                // should try again rather than replay the degraded copy.
                if (data.status === 'ok') {
                    cache[offerId] = data;
                }
                renderTerms(data);
            })
            // Wrapped: .catch hands the Error to its callback, and showOutage
            // now takes a response payload — passing the Error straight in
            // would feed a non-response object to the debug panel.
            .catch(function () { showOutage(null); });
    }

    document.addEventListener('click', function(e) {
        var link = e.target.closest ? e.target.closest('.sphinx-terms-link') : null;
        if (link) {
            e.preventDefault();
            var cardEl = link.closest('[data-offer-id]');
            var offerId = cardEl ? cardEl.getAttribute('data-offer-id') : '';
            if (!offerId) return;
            openModal();
            loadTerms(offerId);
            return;
        }
        if (e.target === modalEl() || (e.target.closest && e.target.closest('.travel-terms-modal__close'))) {
            closeModal();
        }
    });
    document.addEventListener('keydown', function(e) {
        var modal = modalEl();
        if (e.key === 'Escape' && modal && modal.style.display === 'flex') closeModal();
    });
});
