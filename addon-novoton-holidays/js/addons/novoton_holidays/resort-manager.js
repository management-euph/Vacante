/**
 * Novoton Holidays — excluded resorts on the dashboard (novoton_holidays.manage).
 *
 * The list shows every resort with its hotel and product counts. This file
 * filters it (search, country, "excluded only"), sorts it (A–Z or most
 * hotels first), ticks or clears the resorts shown per country, and keeps
 * the bottom bar saying what the unsaved ticks would affect:
 * "2 not saved · … · affects 50 hotels (3 already products)".
 *
 * Saved state is each checkbox's defaultChecked (what the page was rendered
 * with), so "Undo changes" and the "not saved" dots need no extra markup.
 *
 * Loaded inside the dashboard's mainbox capture — admin AJAX navigation
 * runs only the scripts inside it — hence the per-form guard.
 *
 * @package NovotonHolidays
 */
(function () {
    'use strict';

    function init() {
        var form = document.getElementById('excluded-resorts-form');
        if (!form || form.__novotonResorts) {
            return;
        }
        form.__novotonResorts = true;

        var search = document.getElementById('resort-search');
        var onlyBtn = document.getElementById('resort-only-excluded');
        var noResults = document.getElementById('resort-no-results');
        var visibleCount = document.getElementById('resort-visible-count');
        var pending = document.getElementById('resort-pending');
        var undo = document.getElementById('resort-undo');
        var txtPending = form.getAttribute('data-txt-pending') || '[changes] not saved: [add] to exclude, [remove] to include again · affects [hotels] hotels ([products] already products; they stay)';
        var txtSaved = form.getAttribute('data-txt-saved') || '';

        var state = { country: '', only: false, sort: 'name' };

        function items() {
            return Array.prototype.slice.call(form.querySelectorAll('.novoton-resort'));
        }

        function box(item) {
            return item.querySelector('input[type="checkbox"]');
        }

        function num(item, attr) {
            return parseInt(item.getAttribute(attr) || '0', 10) || 0;
        }

        function matches(item) {
            var q = search ? search.value.trim().toLowerCase() : '';
            if (q && (item.getAttribute('data-resort') || '').indexOf(q) === -1) {
                return false;
            }
            if (state.country && item.getAttribute('data-country') !== state.country) {
                return false;
            }
            return !(state.only && !box(item).checked);
        }

        function pressed(selector, attr, value) {
            Array.prototype.slice.call(form.querySelectorAll(selector)).forEach(function (b) {
                b.setAttribute('aria-pressed', b.getAttribute(attr) === value ? 'true' : 'false');
            });
        }

        function sortGroups() {
            Array.prototype.slice.call(form.querySelectorAll('.novoton-resorts__grid')).forEach(function (grid) {
                var list = Array.prototype.slice.call(grid.querySelectorAll('.novoton-resort'));
                list.sort(function (a, b) {
                    if (state.sort === 'hotels') {
                        var d = num(b, 'data-hotels') - num(a, 'data-hotels');
                        if (d !== 0) { return d; }
                    }
                    return (a.getAttribute('data-name') || '').localeCompare(b.getAttribute('data-name') || '');
                });
                list.forEach(function (item) { grid.appendChild(item); });
            });
        }

        function render() {
            var shown = 0;
            var all = items();
            all.forEach(function (item) {
                var show = matches(item);
                item.hidden = !show;
                if (show) { shown++; }
                var cb = box(item);
                item.classList.toggle('is-excluded', cb.checked);
                var dot = item.querySelector('.novoton-resort__dot');
                if (dot) { dot.hidden = cb.checked === cb.defaultChecked; }
            });

            Array.prototype.slice.call(form.querySelectorAll('.novoton-resorts__group')).forEach(function (group) {
                var groupItems = Array.prototype.slice.call(group.querySelectorAll('.novoton-resort'));
                group.hidden = groupItems.every(function (i) { return i.hidden; });
                var excl = group.querySelector('[data-group-excluded]');
                if (excl) {
                    excl.textContent = String(groupItems.filter(function (i) { return box(i).checked; }).length);
                }
            });

            if (noResults) { noResults.hidden = shown !== 0; }
            if (visibleCount) { visibleCount.textContent = shown + ' / ' + all.length; }

            var excludedNow = all.filter(function (i) { return box(i).checked; }).length;
            var countEl = document.getElementById('resort-excluded-count');
            if (countEl) { countEl.textContent = String(excludedNow); }

            // What the unsaved ticks affect.
            var add = 0;
            var remove = 0;
            var hotels = 0;
            var products = 0;
            all.forEach(function (item) {
                var cb = box(item);
                if (cb.checked && !cb.defaultChecked) {
                    add++;
                    hotels += num(item, 'data-hotels');
                    products += num(item, 'data-products');
                } else if (!cb.checked && cb.defaultChecked) {
                    remove++;
                }
            });
            if (pending) {
                pending.textContent = add + remove === 0
                    ? txtSaved
                    : txtPending
                        .split('[changes]').join(String(add + remove))
                        .split('[add]').join(String(add))
                        .split('[remove]').join(String(remove))
                        .split('[hotels]').join(String(hotels))
                        .split('[products]').join(String(products));
            }
            form.classList.toggle('is-dirty', add + remove > 0);

            pressed('[data-resort-country]', 'data-resort-country', state.country);
            pressed('[data-resort-sort]', 'data-resort-sort', state.sort);
            if (onlyBtn) { onlyBtn.setAttribute('aria-pressed', state.only ? 'true' : 'false'); }
        }

        function setShown(country, on) {
            items().forEach(function (item) {
                if (!item.hidden && item.getAttribute('data-country') === country) {
                    box(item).checked = on;
                }
            });
            render();
        }

        if (search) {
            search.addEventListener('input', render);
        }
        if (onlyBtn) {
            onlyBtn.addEventListener('click', function () {
                state.only = !state.only;
                render();
            });
        }
        if (undo) {
            undo.addEventListener('click', function () {
                items().forEach(function (item) {
                    var cb = box(item);
                    cb.checked = cb.defaultChecked;
                });
                render();
            });
        }

        form.addEventListener('click', function (e) {
            var t = e.target && e.target.closest ? e.target.closest('button') : null;
            if (!t) { return; }
            if (t.hasAttribute('data-resort-country')) {
                state.country = t.getAttribute('data-resort-country') || '';
                render();
            } else if (t.hasAttribute('data-resort-sort')) {
                state.sort = t.getAttribute('data-resort-sort') === 'hotels' ? 'hotels' : 'name';
                sortGroups();
                render();
            } else if (t.hasAttribute('data-exclude-shown')) {
                setShown(t.getAttribute('data-exclude-shown'), true);
            } else if (t.hasAttribute('data-include-shown')) {
                setShown(t.getAttribute('data-include-shown'), false);
            }
        });

        form.addEventListener('change', function (e) {
            if (e.target && e.target.name === 'excluded_resorts[]') {
                render();
            }
        });

        render();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
