/**
 * Novoton Holidays — Destinations (novoton_destinations.manage).
 *
 * The page works without this file: each country's mode is a radio group and
 * each resort a checkbox. This adds:
 *   - the search, as on the Sphinx whitelist: a list under the box with the
 *     matching countries and resorts, grouped by country, the match in bold;
 *     picking a resort opens its country and ticks it (a country not sold
 *     switches to "Only selected"); the country list below follows the search;
 *   - "Sold only", "Show new resorts", per-country "Select shown" / "Clear shown";
 *   - the badges, the summary and the "[n] changes not saved" line, with Undo,
 *     and a question before leaving with unsaved changes;
 *   - the in-page confirmation for "Disable products outside the whitelist".
 *
 * Saved state is each input's defaultChecked, so Undo needs no extra markup.
 * Loaded inside the mainbox capture (admin AJAX navigation), hence the guard.
 *
 * @package NovotonHolidays
 */
(function () {
    'use strict';

    function init() {
        var form = document.getElementById('novoton-dest-form');
        if (!form || form.__novotonDest) {
            return;
        }
        form.__novotonDest = true;

        var search = document.getElementById('dest-search');
        var results = document.getElementById('dest-search-results');
        var onlySold = document.getElementById('dest-only-sold');
        var showNew = document.getElementById('dest-show-new');
        var visibleCount = document.getElementById('dest-visible-count');
        var noResults = document.getElementById('dest-no-results');
        var pending = document.getElementById('dest-pending');
        var undo = document.getElementById('dest-undo');
        var summaryList = document.getElementById('dest-summary-list');
        var txt = function (name, fallback) { return form.getAttribute('data-txt-' + name) || fallback; };
        var state = { onlyNew: false };
        var submitting = false;

        function arr(list) { return Array.prototype.slice.call(list); }
        function countries() { return arr(form.querySelectorAll('.novoton-dest-country')); }
        function resorts(country) { return arr(country.querySelectorAll('.novoton-dest-resort')); }
        function box(item) { return item.querySelector('input[type="checkbox"]'); }
        function num(el, attr) { return parseInt(el.getAttribute(attr) || '0', 10) || 0; }
        function query() { return search ? search.value.trim().toLowerCase() : ''; }

        function mode(country) {
            var on = country.querySelector('.novoton-dest-mode input:checked');
            return on ? on.value : 'off';
        }

        function savedMode(country) {
            var saved = arr(country.querySelectorAll('.novoton-dest-mode input')).filter(function (i) { return i.defaultChecked; })[0];
            return saved ? saved.value : 'off';
        }

        function setMode(country, value) {
            var input = country.querySelector('.novoton-dest-mode input[value="' + value + '"]');
            if (input && !input.checked) {
                input.checked = true;
                onModeChange(country, value);
            }
        }

        function open(country, isOpen) {
            var toggle = country.querySelector('.novoton-dest-country__toggle');
            var body = country.querySelector('.novoton-dest-country__body');
            if (!toggle || !body) { return; }
            body.hidden = !isOpen;
            toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            var arrow = toggle.querySelector('.novoton-dest-country__arrow');
            if (arrow) { arrow.textContent = isOpen ? '▼' : '▶'; }
        }

        function isSold(country, item) {
            var m = mode(country);
            if (item.hasAttribute('data-gone')) { return false; }
            return m === 'all' || (m === 'specific' && box(item).checked);
        }

        // All resorts -> Only selected keeps selling what was sold: every resort ticked.
        function onModeChange(country, value) {
            if (value === 'specific' && country.__lastMode === 'all') {
                resorts(country).forEach(function (item) {
                    if (!item.hasAttribute('data-gone')) { box(item).checked = true; }
                });
            }
            country.__lastMode = value;
        }

        function matchesResort(item, q) {
            if (state.onlyNew && !item.hasAttribute('data-new')) { return false; }
            return !q || (item.getAttribute('data-resort') || '').indexOf(q) !== -1;
        }

        function render() {
            var q = query();
            var shown = 0;
            var totals = { countries: 0, resorts: 0, hotels: 0 };
            var lines = [];
            var changes = 0;

            countries().forEach(function (country) {
                var m = mode(country);
                var items = resorts(country);
                var countryHit = !q || (country.getAttribute('data-name') || '').indexOf(q) !== -1;
                var anyResort = false;
                var sold = 0;
                var total = 0;
                var hotels = 0;

                country.setAttribute('data-mode', m);
                // A tick is a change of its own only while the country stays
                // "Only selected"; a changed mode counts once, whatever it ticks.
                var ticksCount = m === 'specific' && savedMode(country) === 'specific';
                items.forEach(function (item) {
                    var hit = matchesResort(item, countryHit && !state.onlyNew ? '' : q);
                    item.hidden = !hit;
                    anyResort = anyResort || hit;
                    var cb = box(item);
                    var dot = item.querySelector('.novoton-dest-resort__dot');
                    var changed = ticksCount && cb.checked !== cb.defaultChecked;
                    if (dot) { dot.hidden = !changed; }
                    if (changed) { changes++; }
                    var on = isSold(country, item);
                    item.classList.toggle('is-sold', on);
                    if (!item.hasAttribute('data-gone')) { total++; }
                    if (on) { sold++; hotels += num(item, 'data-hotels'); }
                });

                var radio = country.querySelector('.novoton-dest-mode input:checked');
                if (radio && !radio.defaultChecked) { changes++; }

                var visible = (countryHit || anyResort)
                    && !(onlySold && onlySold.checked && m === 'off')
                    && !(state.onlyNew && !anyResort);
                country.hidden = !visible;
                if (visible) { shown++; }
                if ((q && anyResort && !countryHit) || (state.onlyNew && anyResort)) { open(country, true); }

                arr(country.querySelectorAll('[data-hint]')).forEach(function (h) {
                    h.hidden = h.getAttribute('data-hint') !== m;
                });
                country.classList.toggle('is-locked', m !== 'specific');

                var badge = country.querySelector('[data-badge]');
                if (badge) {
                    badge.className = 'novoton-dest-badge novoton-dest-badge--' + m;
                    badge.textContent = m === 'all' ? txt('all', 'ALL')
                        : m === 'off' ? txt('off', 'NOT SOLD')
                            : txt('some', '[sold] of [total]').split('[sold]').join(String(sold)).split('[total]').join(String(total));
                }

                if (m !== 'off') {
                    totals.countries++;
                    totals.resorts += sold;
                    totals.hotels += hotels;
                    lines.push([country.getAttribute('data-label') || '', m === 'all' ? txt('all', 'ALL') + ' (' + total + ')' : sold + ' / ' + total]);
                }
            });

            arr(document.querySelectorAll('[data-dest-total]')).forEach(function (el) {
                el.textContent = String(totals[el.getAttribute('data-dest-total')] || 0);
            });
            if (summaryList) {
                summaryList.innerHTML = '';
                lines.forEach(function (l) {
                    var row = document.createElement('div');
                    var a = document.createElement('span');
                    var b = document.createElement('span');
                    a.textContent = l[0];
                    b.textContent = l[1];
                    row.appendChild(a);
                    row.appendChild(b);
                    summaryList.appendChild(row);
                });
            }
            if (visibleCount) { visibleCount.textContent = shown + ' / ' + countries().length; }
            if (noResults) { noResults.hidden = shown !== 0; }
            if (pending) {
                pending.textContent = changes === 0 ? txt('none', '') : txt('pending', '[n] changes not saved').split('[n]').join(String(changes));
            }
            if (undo) { undo.disabled = changes === 0; }
            form.classList.toggle('is-dirty', changes > 0);
        }

        // ─── Search: the Sphinx whitelist's list under the box, client-side ───

        function esc(t) {
            var d = document.createElement('div');
            d.appendChild(document.createTextNode(t));
            return d.innerHTML;
        }

        function highlight(text, q) {
            var i = text.toLowerCase().indexOf(q);
            if (i === -1) { return esc(text); }
            return esc(text.slice(0, i)) + '<strong>' + esc(text.slice(i, i + q.length)) + '</strong>' + esc(text.slice(i + q.length));
        }

        function closeResults() {
            if (!results) { return; }
            results.hidden = true;
            results.innerHTML = '';
            if (search) { search.setAttribute('aria-expanded', 'false'); }
        }

        function showResults() {
            var q = query();
            if (!results || q.length < 2) { closeResults(); return; }
            var html = '';
            var hits = 0;
            countries().forEach(function (country, ci) {
                var label = country.getAttribute('data-label') || '';
                var countryHit = (country.getAttribute('data-name') || '').indexOf(q) !== -1;
                var rows = '';
                resorts(country).forEach(function (item, ri) {
                    if ((item.getAttribute('data-resort') || '').indexOf(q) === -1) { return; }
                    hits++;
                    rows += '<button type="button" class="novoton-dest__hit" role="option" data-hit-country="' + ci + '" data-hit-resort="' + ri + '">'
                        + '<span class="novoton-dest-badge novoton-dest-badge--resort">' + esc(txt('resort', 'Resort')) + '</span>'
                        + '<span class="novoton-dest__hitname">' + highlight(item.getAttribute('data-name') || '', q) + '</span>'
                        + '<span class="muted">' + esc(isSold(country, item) ? txt('sold', 'sold') : txt('not-sold', 'not sold')) + '</span>'
                        + '</button>';
                });
                if (!countryHit && rows === '') { return; }
                if (countryHit) { hits++; }
                html += '<div class="novoton-dest__hitgroup">'
                    + '<button type="button" class="novoton-dest__hit novoton-dest__hit--country" role="option" data-hit-country="' + ci + '">'
                    + '<span class="novoton-dest-badge novoton-dest-badge--country">' + esc(txt('country', 'Country')) + '</span>'
                    + '<span class="novoton-dest__hitname">' + highlight(label, q) + '</span></button>'
                    + rows + '</div>';
            });
            results.innerHTML = hits ? html : '<div class="novoton-dest__nohit">' + esc(txt('no-match', 'No match')) + '</div>';
            results.hidden = false;
            if (search) { search.setAttribute('aria-expanded', 'true'); }
        }

        function pick(hit) {
            var country = countries()[parseInt(hit.getAttribute('data-hit-country'), 10)];
            if (!country) { return; }
            var target = country;
            if (hit.hasAttribute('data-hit-resort')) {
                var item = resorts(country)[parseInt(hit.getAttribute('data-hit-resort'), 10)];
                if (item) {
                    if (mode(country) === 'off') { setMode(country, 'specific'); }
                    if (mode(country) === 'specific' && !item.hasAttribute('data-gone')) { box(item).checked = true; }
                    target = item;
                }
            }
            closeResults();
            if (search) { search.value = ''; }
            open(country, true);
            render();
            target.classList.add('is-flash');
            setTimeout(function () { target.classList.remove('is-flash'); }, 1600);
            if (target.scrollIntoView) { target.scrollIntoView({ block: 'center' }); }
        }

        if (search) {
            search.addEventListener('input', function () { render(); showResults(); });
            // Enter picks the first hit instead of submitting (saving) the form.
            search.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    var first = results && !results.hidden ? results.querySelector('.novoton-dest__hit') : null;
                    if (first) { pick(first); }
                } else if (e.key === 'Escape') {
                    closeResults();
                }
            });
        }
        if (results) {
            results.addEventListener('click', function (e) {
                var hit = e.target && e.target.closest ? e.target.closest('.novoton-dest__hit') : null;
                if (hit) { pick(hit); }
            });
        }
        document.addEventListener('click', function (e) {
            if (results && search && !results.contains(e.target) && e.target !== search) { closeResults(); }
        });

        // ─── Filters, modes, bulk ticks, undo ───

        if (onlySold) { onlySold.addEventListener('change', render); }
        if (showNew) {
            showNew.addEventListener('click', function () {
                state.onlyNew = !state.onlyNew;
                showNew.setAttribute('aria-pressed', state.onlyNew ? 'true' : 'false');
                render();
            });
        }

        form.addEventListener('click', function (e) {
            var t = e.target && e.target.closest ? e.target.closest('button') : null;
            if (!t) { return; }
            var country = t.closest('.novoton-dest-country');
            if (!country) { return; }
            if (t.classList.contains('novoton-dest-country__toggle')) {
                open(country, t.getAttribute('aria-expanded') !== 'true');
            } else if (t.hasAttribute('data-select-shown') || t.hasAttribute('data-clear-shown')) {
                var on = t.hasAttribute('data-select-shown');
                resorts(country).forEach(function (item) {
                    if (!item.hidden && !item.hasAttribute('data-gone')) { box(item).checked = on; }
                });
                render();
            }
        });

        form.addEventListener('change', function (e) {
            var t = e.target;
            if (t && t.type === 'radio') {
                var country = t.closest('.novoton-dest-country');
                if (country) {
                    onModeChange(country, t.value);
                    if (t.value !== 'off') { open(country, true); }
                }
            }
            render();
        });

        if (undo) {
            undo.addEventListener('click', function () {
                arr(form.querySelectorAll('input[type="checkbox"], input[type="radio"]')).forEach(function (i) {
                    i.checked = i.defaultChecked;
                });
                countries().forEach(function (c) { c.__lastMode = mode(c); });
                render();
            });
        }

        // Unsaved changes are lost on any link away; ask first. Saving is not leaving.
        form.addEventListener('submit', function () { submitting = true; });
        window.addEventListener('beforeunload', function (e) {
            if (!submitting && document.body.contains(form) && form.classList.contains('is-dirty')) {
                e.preventDefault();
                e.returnValue = '';
            }
        });

        // ─── Disable products outside the whitelist: confirm on the page ───

        var outsideOpen = document.getElementById('dest-outside-open');
        var outsideForm = document.getElementById('dest-outside-confirm');
        var outsideCancel = document.getElementById('dest-outside-cancel');
        function toggleOutside(show) {
            if (!outsideForm || !outsideOpen) { return; }
            outsideForm.hidden = !show;
            outsideOpen.hidden = show;
            outsideOpen.setAttribute('aria-expanded', show ? 'true' : 'false');
        }
        if (outsideOpen) { outsideOpen.addEventListener('click', function () { toggleOutside(true); }); }
        if (outsideCancel) { outsideCancel.addEventListener('click', function () { toggleOutside(false); }); }

        countries().forEach(function (c) { c.__lastMode = mode(c); });
        render();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
