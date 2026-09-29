/**
 * Travel Core — the destination picker (components/destination_picker.tpl).
 *
 * One page for "what we sell" from each supplier: Novoton (country › resort),
 * Eurosite (country › city) and Sphinx (country › region › city). Each country
 * has a mode, a radio group whose options say what they sell (data-sells):
 *   none      — nothing (Not sold)
 *   all       — every item, including ones the supplier adds later
 *   flag:own  — every item carrying data-flag-own (Eurosite "Own cities")
 *   ticked    — the ticked items, and the whole of every ticked group
 *               (a ticked Sphinx region also takes cities added to it later)
 *
 * The page works without this file: modes are radios, items and groups are
 * checkboxes named dest[COUNTRY][mode|items][]|groups][]. This adds:
 *   - the search: a list under the box, grouped by country; picking an item
 *     opens its country and ticks it. Countries whose body is loaded on open
 *     (data-body-url) are also searched on the server (data-search-url);
 *   - bodies loaded when a country opens (Eurosite, Sphinx: too many cities
 *     to render up front), the country filters, the pager, folding the
 *     countries that are not sold and have nothing synced;
 *   - inside a country: chips (All · Sold · Not sold · New · flags), a filter,
 *     a sort, "Show more", Select shown / Clear shown, fold / unfold groups;
 *   - badges, tiles, the summary, "[n] changes not saved" with Undo, the
 *     sticky save bar and a question before leaving with unsaved changes;
 *   - the in-page confirmation for "Disable products outside the whitelist".
 *
 * Saved state is each input's defaultChecked, so Undo needs no extra markup.
 * On submit the choices go as one JSON field (dest_json): a Sphinx whitelist
 * can tick more cities than PHP's max_input_vars allows as separate fields.
 * Loaded inside the mainbox capture (admin AJAX navigation), hence the guard.
 *
 * @package TravelCore
 */
(function () {
    'use strict';

    function arr(list) { return Array.prototype.slice.call(list || []); }
    function num(el, attr) { return parseInt((el && el.getAttribute(attr)) || '0', 10) || 0; }
    function esc(t) {
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(t == null ? '' : String(t)));
        return d.innerHTML;
    }
    // "[n] change|[n] changes": the first form for one, the other for the rest.
    function fill(text, values) {
        var out = String(text || '');
        if (out.indexOf('|') !== -1) {
            var forms = out.split('|');
            var n = values.n !== undefined ? values.n : values.shown;
            out = Number(n) === 1 ? forms[0] : forms[forms.length > 2 && Number(n) !== 0 ? 2 : 1];
            if (forms.length > 2 && Number(n) >= 2 && Number(n) % 100 < 20) { out = forms[1]; }
        }
        Object.keys(values).forEach(function (k) { out = out.split('[' + k + ']').join(String(values[k])); });
        return out;
    }
    function highlight(text, q) {
        var i = String(text).toLowerCase().indexOf(q);
        if (!q || i === -1) { return esc(text); }
        return esc(text.slice(0, i)) + '<strong>' + esc(text.slice(i, i + q.length)) + '</strong>' + esc(text.slice(i + q.length));
    }

    function initPicker(root) {
        if (!root || root.__travelDest) {
            return;
        }
        root.__travelDest = true;

        var form = root.querySelector('[data-dest-form]');
        if (!form) {
            return;
        }
        var words = {};
        try { words = JSON.parse(root.getAttribute('data-dest-words') || '{}') || {}; } catch (e) { words = {}; }
        var w = function (key, fallback) { return words[key] || fallback || ''; };

        var bodyUrl = form.getAttribute('data-body-url') || '';
        var searchUrl = form.getAttribute('data-search-url') || '';
        var perPage = parseInt(form.getAttribute('data-per-page') || '0', 10) || 0;
        var leafLimit = parseInt(form.getAttribute('data-leaf-limit') || '0', 10) || 0;

        var search = root.querySelector('[data-dest-search]');
        var results = root.querySelector('[data-dest-results]');
        var onlySold = root.querySelector('[data-dest-only-sold]');
        var facet = root.querySelector('[data-dest-facet]');
        var filters = arr(root.querySelectorAll('[data-dest-filter]'));
        var countEl = root.querySelector('[data-dest-count]');
        var none = root.querySelector('[data-dest-none]');
        var foldLine = root.querySelector('[data-dest-fold]');
        var pager = root.querySelector('[data-dest-pager]');
        var summaryList = root.querySelector('[data-dest-summary-list]');
        var jsonField = form.querySelector('[data-dest-json]');

        var state = { onlyNew: false, showEmpty: false, page: 1, serverHits: null, serverQuery: '' };
        var submitting = false;
        var searchTimer = null;
        var searchSeq = 0;

        function countries() { return arr(form.querySelectorAll('.travel-dest-country')); }
        function items(country) { return arr(country.querySelectorAll('.travel-dest-item')); }
        function groups(country) { return arr(country.querySelectorAll('.travel-dest-group')); }
        function box(item) { return item.querySelector('input[type="checkbox"]'); }
        function groupOf(item) { return item.closest('.travel-dest-group'); }
        function groupBox(group) { return group ? group.querySelector('[data-dest-group-cb]') : null; }
        function body(country) { return country.querySelector('[data-dest-body]'); }
        function loaded(country) { return !country.hasAttribute('data-lazy') || country.hasAttribute('data-loaded'); }
        function query() { return search ? search.value.trim().toLowerCase() : ''; }
        function view(country) {
            if (!country.__view) {
                country.__view = { chip: 'all', filter: '', limit: leafLimit, sort: '' };
            }
            return country.__view;
        }

        function radio(country) { return country.querySelector('.travel-dest-mode input:checked'); }
        function savedRadio(country) {
            return arr(country.querySelectorAll('.travel-dest-mode input')).filter(function (i) { return i.defaultChecked; })[0] || null;
        }
        function sells(country) { var r = radio(country); return r ? r.getAttribute('data-sells') || 'none' : 'none'; }
        function savedSells(country) { var r = savedRadio(country); return r ? r.getAttribute('data-sells') || 'none' : 'none'; }

        function soldUnder(rule, item) {
            if (item.hasAttribute('data-gone')) { return false; }
            if (rule === 'none') { return false; }
            if (rule === 'all') { return true; }
            if (rule.indexOf('flag:') === 0) { return item.hasAttribute('data-flag-' + rule.slice(5)); }
            var cb = box(item);
            var g = groupBox(groupOf(item));
            return !!(cb && cb.checked) || !!(g && g.checked);
        }
        function isSold(country, item) { return soldUnder(sells(country), item); }

        // Totals for a country whose body is not loaded yet: the server's
        // figures for the saved choice (nothing in it can have changed).
        function stats(country) {
            var rule = sells(country);
            var s = { total: 0, sold: 0, hotels: 0, priced: 0, instant: 0, live: 0, groupsSold: 0, groupsTotal: 0 };
            if (!loaded(country)) {
                s.total = num(country, 'data-total');
                s.groupsTotal = num(country, 'data-groups');
                var key = rule === 'none' ? 'none' : rule === 'all' ? 'all' : rule === savedSells(country) ? 'saved' : rule.indexOf('flag:') === 0 ? rule.slice(5) : 'saved';
                if (key === 'none') { return s; }
                ['sold', 'hotels', 'priced', 'instant', 'live', 'groups-sold'].forEach(function (k) {
                    var v = num(country, 'data-' + key + '-' + k);
                    if (k === 'groups-sold') { s.groupsSold = v; } else { s[k] = v; }
                });
                return s;
            }
            items(country).forEach(function (item) {
                if (!item.hasAttribute('data-gone')) { s.total++; }
                if (isSold(country, item)) {
                    s.sold++;
                    s.hotels += num(item, 'data-hotels');
                    s.priced += num(item, 'data-priced');
                    s.instant += num(item, 'data-instant');
                    s.live += num(item, 'data-live');
                }
            });
            groups(country).forEach(function (g) {
                if (!g.hasAttribute('data-group')) { return; }
                s.groupsTotal++;
                if (items(g).some(function (i) { return isSold(country, i); })) { s.groupsSold++; }
            });
            return s;
        }

        // ─── Mode changes ───

        // Leaving "All" or a flag mode for "Only selected" keeps selling what
        // was sold: those items are ticked (and, from "All", whole groups).
        function onModeChange(country, from) {
            var to = sells(country);
            if (to !== 'ticked' || from === 'ticked' || from === 'none') {
                country.__lastSells = to;
                return;
            }
            if (!loaded(country)) {
                country.__pendingFrom = from;
                load(country);
                return;
            }
            items(country).forEach(function (item) {
                if (soldUnder(from, item)) { box(item).checked = true; }
            });
            if (from === 'all') {
                groups(country).forEach(function (g) { var cb = groupBox(g); if (cb) { cb.checked = true; } });
            }
            country.__lastSells = to;
        }

        // ─── Bodies loaded when a country opens ───

        function load(country) {
            if (loaded(country)) { return Promise.resolve(country); }
            if (country.__loading) { return country.__loading; }
            var b = body(country);
            if (b) { b.innerHTML = '<p class="muted">' + esc(w('loading', 'Loading…')) + '</p>'; }
            var url = bodyUrl + (bodyUrl.indexOf('?') === -1 ? '?' : '&') + 'country=' + encodeURIComponent(country.getAttribute('data-country') || '');
            country.__loading = fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data || typeof data.html !== 'string') { throw new Error('no html'); }
                    if (b) { b.innerHTML = data.html; }
                    country.setAttribute('data-loaded', '1');
                    country.__loading = null;
                    if (country.__pendingFrom) {
                        var from = country.__pendingFrom;
                        country.__pendingFrom = null;
                        onModeChange(country, from);
                    }
                    render();
                    return country;
                })
                .catch(function () {
                    country.__loading = null;
                    if (b) {
                        b.innerHTML = '<p class="travel-dest__error">' + esc(w('load_failed', 'Could not load. Open the country again to retry.')) + '</p>';
                    }
                    return country;
                });
            return country.__loading;
        }

        function open(country, isOpen) {
            var toggle = country.querySelector('.travel-dest-country__toggle');
            var b = body(country);
            if (!toggle || !b) { return; }
            b.hidden = !isOpen;
            toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            var arrow = toggle.querySelector('.travel-dest-country__arrow');
            if (arrow) { arrow.textContent = isOpen ? '▼' : '▶'; }
            if (isOpen && !loaded(country)) { load(country); }
        }
        function isOpen(country) {
            var b = body(country);
            return !!b && !b.hidden;
        }

        function openGroup(group, show) {
            var grid = group.querySelector('[data-dest-grid]');
            var btn = group.querySelector('[data-dest-fold-group]');
            if (!grid || !btn) { return; }
            grid.hidden = !show;
            btn.setAttribute('aria-expanded', show ? 'true' : 'false');
            btn.textContent = show ? '−' : '+';
        }

        // ─── Rendering ───

        function itemMatches(country, item, v, q) {
            var chip = v.chip;
            if (chip === 'sold' && !isSold(country, item)) { return false; }
            if (chip === 'unsold' && isSold(country, item)) { return false; }
            if (chip === 'new' && !item.hasAttribute('data-new')) { return false; }
            if (chip.indexOf('flag:') === 0 && !item.hasAttribute('data-flag-' + chip.slice(5))) { return false; }
            var f = v.filter.trim().toLowerCase();
            if (f && (item.getAttribute('data-search') || '').indexOf(f) === -1) { return false; }
            if (q) {
                var g = groupOf(item);
                var groupHit = g && (g.getAttribute('data-search') || '').indexOf(q) !== -1;
                if ((item.getAttribute('data-search') || '').indexOf(q) === -1 && !groupHit) { return false; }
            }
            return true;
        }

        function sortItems(country, v) {
            if (!v.sort || v.sort === v.sorted) { return; }
            v.sorted = v.sort;
            arr(country.querySelectorAll('[data-dest-grid]')).forEach(function (grid) {
                var list = arr(grid.querySelectorAll('.travel-dest-item'));
                list.sort(function (a, b) {
                    var la = a.getAttribute('data-label') || '';
                    var lb = b.getAttribute('data-label') || '';
                    if (v.sort === 'hotels') {
                        return (num(b, 'data-hotels') - num(a, 'data-hotels')) || la.localeCompare(lb);
                    }
                    if (v.sort.indexOf('flag:') === 0) {
                        var fl = 'data-flag-' + v.sort.slice(5);
                        return ((b.hasAttribute(fl) ? 1 : 0) - (a.hasAttribute(fl) ? 1 : 0)) || la.localeCompare(lb);
                    }
                    return la.localeCompare(lb);
                });
                list.forEach(function (i) { grid.appendChild(i); });
            });
        }

        function renderBody(country, q, nameHit) {
            var v = view(country);
            var rule = sells(country);
            var ticks = rule === 'ticked' && savedSells(country) === 'ticked';
            var changes = 0;
            var list = items(country);
            var counts = { all: 0, sold: 0, unsold: 0, new: 0 };
            var shown = 0;
            var matches = 0;
            sortItems(country, v);

            list.forEach(function (item) {
                var cb = box(item);
                var sold = isSold(country, item);
                item.classList.toggle('is-sold', sold);
                cb.disabled = rule !== 'ticked';
                var changed = ticks && cb.checked !== cb.defaultChecked;
                if (changed) { changes++; }
                var dot = item.querySelector('[data-dest-dot]');
                if (dot) { dot.hidden = !changed; }
                counts.all++;
                counts[sold ? 'sold' : 'unsold']++;
                if (item.hasAttribute('data-new')) { counts.new++; }
                arr(item.attributes).forEach(function (a) {
                    if (a.name.indexOf('data-flag-') === 0) {
                        var k = 'flag:' + a.name.slice(10);
                        counts[k] = (counts[k] || 0) + 1;
                    }
                });
                var hit = itemMatches(country, item, v, nameHit ? '' : q);
                if (hit) { matches++; }
                var visible = hit && (!v.limit || v.filter || q || matches <= v.limit);
                item.hidden = !visible;
                if (visible) { shown++; }
            });

            groups(country).forEach(function (g) {
                var cb = groupBox(g);
                var inG = items(g);
                if (cb) {
                    var gChanged = ticks && cb.checked !== cb.defaultChecked;
                    if (gChanged) { changes++; }
                    cb.disabled = rule !== 'ticked';
                    var ticked = inG.filter(function (i) { return box(i).checked; }).length;
                    cb.indeterminate = !cb.checked && ticked > 0;
                }
                var soldN = inG.filter(function (i) { return isSold(country, i); }).length;
                var total = inG.filter(function (i) { return !i.hasAttribute('data-gone'); }).length;
                var badge = g.querySelector('[data-group-badge]');
                if (badge) {
                    if (rule === 'all' || (cb && cb.checked && rule === 'ticked')) {
                        badge.className = 'travel-dest-badge travel-dest-badge--all travel-dest-badge--s';
                        badge.textContent = rule === 'all' ? w('badge_all', 'ALL') : w('group_whole', 'WHOLE REGION');
                    } else if (soldN > 0) {
                        badge.className = 'travel-dest-badge travel-dest-badge--specific travel-dest-badge--s';
                        badge.textContent = fill(w('group_some', '[sold] of [total]'), { sold: soldN, total: total });
                    } else {
                        badge.className = 'travel-dest-badge travel-dest-badge--off travel-dest-badge--s';
                        badge.textContent = w('group_none', 'none');
                    }
                }
                var anyShown = inG.some(function (i) { return !i.hidden; });
                var filtering = v.chip !== 'all' || v.filter || q;
                g.hidden = !!filtering && !anyShown && g.hasAttribute('data-group');
                if (filtering && anyShown && g.hasAttribute('data-group')) { openGroup(g, true); }
            });

            arr(country.querySelectorAll('[data-dest-chip]')).forEach(function (chip) {
                var key = chip.getAttribute('data-dest-chip');
                chip.setAttribute('aria-pressed', v.chip === key ? 'true' : 'false');
                var n = chip.querySelector('[data-chip-n]');
                if (n) { n.textContent = String(counts[key] || 0); }
                if (key === 'new') { chip.hidden = !counts.new && v.chip !== 'new'; }
            });
            var more = country.querySelector('[data-dest-more]');
            if (more) {
                var rest = matches - shown;
                more.hidden = rest <= 0;
                var btn = more.querySelector('button');
                var label = more.querySelector('[data-dest-more-text]');
                if (btn) { btn.textContent = fill(w('more', 'Show [n] more'), { n: Math.min(rest, leafLimit || rest) }); }
                if (label) { label.textContent = fill(w('showing', 'Showing [shown] of [total]'), { shown: shown, total: matches }); }
            }
            var emptyMsg = country.querySelector('[data-dest-nomatch]');
            if (emptyMsg) { emptyMsg.hidden = matches > 0 || list.length === 0; }

            arr(country.querySelectorAll('[data-hint]')).forEach(function (h) {
                var want = h.getAttribute('data-hint');
                h.hidden = want === 'ticked' ? rule !== 'ticked' : want !== radioValue(country);
            });
            return { changes: changes, matches: matches };
        }

        function radioValue(country) { var r = radio(country); return r ? r.value : ''; }

        function render() {
            var q = query();
            var searching = q.length >= 2;
            var totals = { countries: 0, items: 0, hotels: 0, priced: 0, instant: 0, live: 0, groups: 0 };
            var lines = [];
            var changes = 0;
            var eligible = [];
            var folded = 0;
            var activeFilters = filters.filter(function (f) { return f.checked; });

            countries().forEach(function (country) {
                var rule = sells(country);
                var r = radio(country);
                if (r && !r.defaultChecked) { changes++; }
                country.setAttribute('data-mode', r ? r.value : '');
                country.classList.toggle('is-locked', rule !== 'ticked');

                var nameHit = !searching || (country.getAttribute('data-search') || '').indexOf(q) !== -1;
                var inner = { changes: 0, matches: 0 };
                if (loaded(country) && body(country) && body(country).querySelector('.travel-dest-item, [data-dest-hint-row]')) {
                    inner = renderBody(country, searching ? q : '', nameHit);
                }
                changes += inner.changes;
                var serverHit = searching && state.serverHits && state.serverHits[country.getAttribute('data-country')];
                var searchOk = !searching || nameHit || inner.matches > 0 || !!serverHit;
                var visible = searchOk
                    && !(onlySold && onlySold.checked && rule === 'none')
                    && !(facet && facet.value && country.getAttribute('data-facet') !== facet.value)
                    && activeFilters.every(function (f) { return num(country, 'data-flag-' + f.getAttribute('data-dest-filter')) > 0; })
                    && !(state.onlyNew && num(country, 'data-new') === 0);
                var foldable = country.hasAttribute('data-empty') && rule === 'none' && savedSells(country) === 'none';
                if (visible && foldable && !state.showEmpty && !searching) {
                    folded++;
                    visible = false;
                }
                country.__eligible = visible;
                if (visible) { eligible.push(country); }
                if (searching && !nameHit && (inner.matches > 0 || serverHit) && !isOpen(country)) { open(country, true); }

                var s = stats(country);
                var badge = country.querySelector('[data-dest-badge]');
                if (badge && r) {
                    var cls = rule === 'none' ? 'off' : rule === 'ticked' ? 'specific' : 'all';
                    badge.className = 'travel-dest-badge travel-dest-badge--' + cls;
                    badge.textContent = fill(r.getAttribute('data-badge') || '', { sold: s.sold, total: s.total, groups_sold: s.groupsSold, groups: s.groupsTotal });
                }
                if (rule !== 'none') {
                    totals.countries++;
                    totals.items += s.sold;
                    totals.hotels += s.hotels;
                    totals.priced += s.priced;
                    totals.instant += s.instant;
                    totals.live += s.live;
                    totals.groups += s.groupsSold;
                    lines.push([country.getAttribute('data-label') || '', rule === 'all'
                        ? w('badge_all', 'ALL') + ' (' + s.total + ')'
                        : s.sold + ' / ' + s.total]);
                }
            });

            // The pager windows the eligible countries; nothing else is paged.
            var pages = perPage ? Math.max(1, Math.ceil(eligible.length / perPage)) : 1;
            state.page = Math.min(Math.max(1, state.page), pages);
            countries().forEach(function (c) { c.hidden = true; });
            eligible.forEach(function (c, i) {
                c.hidden = !!perPage && (i < (state.page - 1) * perPage || i >= state.page * perPage);
            });
            if (pager) {
                pager.hidden = !perPage || eligible.length <= perPage;
                var info = pager.querySelector('[data-dest-page-info]');
                if (info) {
                    var start = (state.page - 1) * perPage + 1;
                    info.textContent = eligible.length ? start + '–' + Math.min(state.page * perPage, eligible.length) + ' / ' + eligible.length : '0';
                }
                arr(pager.querySelectorAll('[data-dest-page]')).forEach(function (b) {
                    var p = b.getAttribute('data-dest-page');
                    b.disabled = (p === 'first' || p === 'prev') ? state.page <= 1 : state.page >= pages;
                });
            }

            if (foldLine) {
                var total = countries().filter(function (c) { return c.hasAttribute('data-empty') && sells(c) === 'none' && savedSells(c) === 'none'; }).length;
                foldLine.hidden = total === 0 || searching;
                var t = foldLine.querySelector('[data-dest-fold-text]');
                var showBtn = foldLine.querySelector('[data-dest-fold-show]');
                var hideBtn = foldLine.querySelector('[data-dest-fold-hide]');
                if (t) { t.textContent = state.showEmpty ? '' : fill(w('fold', '[n] more countries have nothing synced and are not sold.'), { n: folded }); }
                if (showBtn) { showBtn.hidden = state.showEmpty || folded === 0; }
                if (hideBtn) { hideBtn.hidden = !state.showEmpty; }
                if (!state.showEmpty && folded === 0) { foldLine.hidden = true; }
            }

            arr(root.querySelectorAll('[data-dest-total]')).forEach(function (el) {
                var v = totals[el.getAttribute('data-dest-total')] || 0;
                el.textContent = Number(v).toLocaleString('en-US');
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
            if (countEl) { countEl.textContent = fill(w('visible', '[shown] / [total]'), { shown: eligible.length, total: countries().length }); }
            if (none) { none.hidden = eligible.length !== 0 || folded !== 0; }

            var pendingText = changes === 0 ? w('no_pending', '') : fill(w('pending', '[n] changes not saved'), { n: changes });
            arr(root.querySelectorAll('[data-dest-pending]')).forEach(function (el) {
                el.textContent = pendingText;
                el.classList.toggle('is-clean', changes === 0);
            });
            arr(root.querySelectorAll('[data-dest-undo]')).forEach(function (b) { b.disabled = changes === 0; });
            var bar = root.querySelector('[data-dest-savebar]');
            if (bar) { bar.hidden = changes === 0; }
            root.classList.toggle('is-dirty', changes > 0);
        }

        // ─── Search: the list under the box ───

        function closeResults() {
            if (!results) { return; }
            results.hidden = true;
            results.innerHTML = '';
            if (search) { search.setAttribute('aria-expanded', 'false'); }
        }

        function countryByKey(key) {
            return countries().filter(function (c) { return c.getAttribute('data-country') === key; })[0] || null;
        }

        function showResults() {
            var q = query();
            if (!results || q.length < 2) { closeResults(); return; }
            var html = '';
            var hits = 0;
            countries().forEach(function (country) {
                var key = country.getAttribute('data-country') || '';
                var label = country.getAttribute('data-label') || '';
                var countryHit = (country.getAttribute('data-search') || '').indexOf(q) !== -1;
                var rows = '';
                var seen = {};
                function row(kind, value, name, side, path) {
                    hits++;
                    return '<button type="button" class="travel-dest__hit travel-dest__hit--' + kind + '" role="option" data-hit-country="' + esc(key) + '"'
                        + (kind === 'group' ? ' data-hit-group="' + esc(value) + '"' : ' data-hit-item="' + esc(value) + '"') + '>'
                        + '<span class="travel-dest-type travel-dest-type--' + kind + '">' + esc(kind === 'group' ? w('group_type', 'Region') : w('item_type', 'Item')) + '</span>'
                        + '<span class="travel-dest__hitname">' + highlight(name, q) + (path ? ' <span class="muted">' + esc(path) + '</span>' : '') + '</span>'
                        + (side ? '<span class="muted travel-dest__hitside">' + esc(side) + '</span>' : '') + '</button>';
                }
                groups(country).forEach(function (g) {
                    if (!g.hasAttribute('data-group')) { return; }
                    if ((g.getAttribute('data-search') || '').indexOf(q) !== -1) {
                        seen['g:' + g.getAttribute('data-group')] = true;
                        rows += row('group', g.getAttribute('data-group'), g.getAttribute('data-label') || '', '', label);
                    }
                });
                items(country).forEach(function (item) {
                    if ((item.getAttribute('data-search') || '').indexOf(q) === -1) { return; }
                    var value = box(item).value;
                    seen['i:' + value] = true;
                    var g = groupOf(item);
                    var path = g && g.hasAttribute('data-group') ? label + ' › ' + (g.getAttribute('data-label') || '') : '';
                    rows += row('item', value, item.getAttribute('data-label') || '', isSold(country, item) ? w('sold', 'sold') : w('not_sold', 'not sold'), path);
                });
                ((state.serverHits && state.serverHits[key]) || []).forEach(function (h) {
                    var k = (h.group && !h.item ? 'g:' + h.group : 'i:' + h.item);
                    if (seen[k]) { return; }
                    seen[k] = true;
                    rows += h.group && !h.item
                        ? row('group', h.group, h.label || '', '', label)
                        : row('item', h.item, h.label || '', '', h.path || '');
                });
                if (!countryHit && rows === '') { return; }
                if (countryHit) { hits++; }
                html += '<div class="travel-dest__hitgroup">'
                    + '<button type="button" class="travel-dest__hit travel-dest__hit--country" role="option" data-hit-country="' + esc(key) + '">'
                    + '<span class="travel-dest-type travel-dest-type--country">' + esc(w('country_type', 'Country')) + '</span>'
                    + '<span class="travel-dest__hitname">' + highlight(label, q) + '</span></button>'
                    + rows + '</div>';
            });
            results.innerHTML = hits ? html : '<div class="travel-dest__nohit">' + esc(w('no_match', 'No match.')) + '</div>';
            results.hidden = false;
            if (search) { search.setAttribute('aria-expanded', 'true'); }
        }

        function serverSearch() {
            var q = query();
            if (!searchUrl || q.length < 2) { state.serverHits = null; return; }
            var seq = ++searchSeq;
            fetch(searchUrl + (searchUrl.indexOf('?') === -1 ? '?' : '&') + 'q=' + encodeURIComponent(q), { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (seq !== searchSeq || query() !== q) { return; }
                    var byCountry = {};
                    ((data && data.hits) || []).forEach(function (h) {
                        if (!h || !h.country) { return; }
                        (byCountry[h.country] = byCountry[h.country] || []).push(h);
                    });
                    state.serverHits = byCountry;
                    render();
                    showResults();
                })
                .catch(function () { /* the local hits stay */ });
        }

        function flash(el) {
            el.classList.add('is-flash');
            setTimeout(function () { el.classList.remove('is-flash'); }, 1600);
            if (el.scrollIntoView) { el.scrollIntoView({ block: 'center' }); }
        }

        function setMode(country, value) {
            var input = country.querySelector('.travel-dest-mode input[value="' + value + '"]');
            if (input && !input.checked) {
                var from = sells(country);
                input.checked = true;
                onModeChange(country, from);
            }
        }

        function pick(hit) {
            var country = countryByKey(hit.getAttribute('data-hit-country'));
            if (!country) { return; }
            var itemValue = hit.getAttribute('data-hit-item');
            var groupValue = hit.getAttribute('data-hit-group');
            closeResults();
            if (search) { search.value = ''; }
            state.serverHits = null;
            state.onlyNew = false;
            if (facet && facet.value && country.getAttribute('data-facet') !== facet.value) { facet.value = ''; }
            filters.forEach(function (f) {
                if (f.checked && num(country, 'data-flag-' + f.getAttribute('data-dest-filter')) === 0) { f.checked = false; }
            });
            if (country.hasAttribute('data-empty')) { state.showEmpty = true; }
            open(country, true);
            load(country).then(function () {
                var target = country;
                if (itemValue || groupValue) {
                    var rule = sells(country);
                    if (rule === 'none' || (rule.indexOf('flag:') === 0)) {
                        var item0 = itemValue ? items(country).filter(function (i) { return box(i).value === itemValue; })[0] : null;
                        if (rule === 'none' || !item0 || !soldUnder(rule, item0) || groupValue) { setMode(country, specificValue(country)); }
                    }
                    var v = view(country);
                    v.chip = 'all';
                    v.filter = '';
                    var filterInput = country.querySelector('[data-dest-leaf-filter]');
                    if (filterInput) { filterInput.value = ''; }
                    if (groupValue) {
                        var g = groups(country).filter(function (x) { return x.getAttribute('data-group') === groupValue; })[0];
                        if (g) {
                            if (sells(country) === 'ticked') {
                                var gcb = groupBox(g);
                                if (gcb) { gcb.checked = true; }
                                items(g).forEach(function (i) { if (!i.hasAttribute('data-gone')) { box(i).checked = true; } });
                            }
                            openGroup(g, true);
                            target = g;
                        }
                    } else {
                        var item = items(country).filter(function (i) { return box(i).value === itemValue; })[0];
                        if (item) {
                            if (sells(country) === 'ticked' && !item.hasAttribute('data-gone')) { box(item).checked = true; }
                            var gi = groupOf(item);
                            if (gi && gi.hasAttribute('data-group')) { openGroup(gi, true); }
                            if (leafLimit) {
                                var idx = items(country).filter(function (i) { return itemMatches(country, i, v, ''); }).indexOf(item);
                                if (idx >= v.limit) { v.limit = idx + 1; }
                            }
                            target = item;
                        }
                    }
                }
                if (perPage) {
                    render();
                    var idxC = countries().filter(function (c) { return c.__eligible; }).indexOf(country);
                    if (idxC >= 0) { state.page = Math.floor(idxC / perPage) + 1; }
                }
                render();
                flash(target);
            });
        }

        function specificValue(country) {
            var r = arr(country.querySelectorAll('.travel-dest-mode input')).filter(function (i) { return i.getAttribute('data-sells') === 'ticked'; })[0];
            return r ? r.value : 'specific';
        }

        if (search) {
            search.addEventListener('input', function () {
                state.page = 1;
                state.serverHits = null;
                render();
                showResults();
                clearTimeout(searchTimer);
                searchTimer = setTimeout(serverSearch, 250);
            });
            // Enter picks the first hit instead of submitting (saving) the form.
            search.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    var first = results && !results.hidden ? results.querySelector('.travel-dest__hit') : null;
                    if (first) { pick(first); }
                } else if (e.key === 'Escape') {
                    closeResults();
                } else if (e.key === 'ArrowDown' && results && !results.hidden) {
                    var f = results.querySelector('.travel-dest__hit');
                    if (f) { e.preventDefault(); f.focus(); }
                }
            });
        }
        if (results) {
            results.addEventListener('click', function (e) {
                var hit = e.target && e.target.closest ? e.target.closest('.travel-dest__hit') : null;
                if (hit) { pick(hit); }
            });
            results.addEventListener('keydown', function (e) {
                var list = arr(results.querySelectorAll('.travel-dest__hit'));
                var i = list.indexOf(document.activeElement);
                if (e.key === 'ArrowDown' && i < list.length - 1) { e.preventDefault(); list[i + 1].focus(); }
                if (e.key === 'ArrowUp') { e.preventDefault(); (i > 0 ? list[i - 1] : search).focus(); }
                if (e.key === 'Escape') { closeResults(); if (search) { search.focus(); } }
            });
        }
        document.addEventListener('click', function (e) {
            if (results && search && !results.contains(e.target) && e.target !== search) { closeResults(); }
        });

        // ─── Toolbar, country list, bulk ticks, pager, undo ───

        function rerenderFromTop() { state.page = 1; render(); }
        if (onlySold) { onlySold.addEventListener('change', rerenderFromTop); }
        if (facet) { facet.addEventListener('change', rerenderFromTop); }
        filters.forEach(function (f) { f.addEventListener('change', rerenderFromTop); });

        root.addEventListener('click', function (e) {
            var t = e.target && e.target.closest ? e.target.closest('button') : null;
            if (!t || !root.contains(t)) { return; }
            if (t.hasAttribute('data-dest-show-new')) {
                state.onlyNew = !state.onlyNew;
                t.setAttribute('aria-pressed', state.onlyNew ? 'true' : 'false');
                countries().forEach(function (c) {
                    if (num(c, 'data-new') > 0) {
                        view(c).chip = state.onlyNew ? 'new' : 'all';
                        if (state.onlyNew) { open(c, true); }
                    }
                });
                rerenderFromTop();
                return;
            }
            if (t.hasAttribute('data-dest-fold-show')) { state.showEmpty = true; render(); return; }
            if (t.hasAttribute('data-dest-fold-hide')) { state.showEmpty = false; render(); return; }
            if (t.hasAttribute('data-dest-page')) {
                var p = t.getAttribute('data-dest-page');
                state.page = p === 'first' ? 1 : p === 'prev' ? state.page - 1 : p === 'next' ? state.page + 1 : 1e9;
                render();
                if (form.scrollIntoView) { form.scrollIntoView({ block: 'start' }); }
                return;
            }
            if (t.hasAttribute('data-dest-undo')) {
                arr(form.querySelectorAll('input[type="checkbox"], input[type="radio"]')).forEach(function (i) {
                    i.checked = i.defaultChecked;
                });
                countries().forEach(function (c) { c.__lastSells = sells(c); });
                render();
                return;
            }
            if (t.hasAttribute('data-dest-outside-open') || t.hasAttribute('data-dest-outside-cancel')) {
                var show = t.hasAttribute('data-dest-outside-open');
                var conf = root.querySelector('[data-dest-outside-confirm]');
                var opener = root.querySelector('[data-dest-outside-open]');
                if (conf) { conf.hidden = !show; }
                if (opener) { opener.hidden = show; opener.setAttribute('aria-expanded', show ? 'true' : 'false'); }
                return;
            }

            var country = t.closest('.travel-dest-country');
            if (!country) { return; }
            var v = view(country);
            if (t.classList.contains('travel-dest-country__toggle')) {
                open(country, t.getAttribute('aria-expanded') !== 'true');
            } else if (t.hasAttribute('data-dest-chip')) {
                v.chip = t.getAttribute('data-dest-chip');
                v.limit = leafLimit;
            } else if (t.closest('[data-dest-more]')) {
                v.limit += leafLimit || 60;
            } else if (t.hasAttribute('data-dest-fold-group')) {
                var g = t.closest('.travel-dest-group');
                openGroup(g, t.getAttribute('aria-expanded') !== 'true');
            } else if (t.hasAttribute('data-dest-expand') || t.hasAttribute('data-dest-collapse')) {
                var on = t.hasAttribute('data-dest-expand');
                groups(country).forEach(function (x) { if (x.hasAttribute('data-group')) { openGroup(x, on); } });
            } else if (t.hasAttribute('data-dest-select-shown') || t.hasAttribute('data-dest-clear-shown')) {
                var tick = t.hasAttribute('data-dest-select-shown');
                items(country).forEach(function (item) {
                    var g2 = groupOf(item);
                    var folded = g2 && g2.querySelector('[data-dest-grid]') && g2.querySelector('[data-dest-grid]').hidden;
                    if (!item.hidden && !folded && !item.hasAttribute('data-gone')) {
                        box(item).checked = tick;
                        if (!tick) { var gcb = groupBox(g2); if (gcb) { gcb.checked = false; } }
                    }
                });
            } else if (t.hasAttribute('data-dest-select-flag')) {
                var flag = 'data-flag-' + t.getAttribute('data-dest-select-flag');
                items(country).forEach(function (item) {
                    if (item.hasAttribute(flag) && !item.hasAttribute('data-gone')) { box(item).checked = true; }
                });
            } else {
                return;
            }
            render();
        });

        form.addEventListener('change', function (e) {
            var t = e.target;
            if (!t) { return; }
            var country = t.closest ? t.closest('.travel-dest-country') : null;
            if (t.type === 'radio' && country) {
                onModeChange(country, country.__lastSells || savedSells(country));
                if (sells(country) !== 'none') { open(country, true); }
            } else if (t.hasAttribute && t.hasAttribute('data-dest-group-cb')) {
                var g = t.closest('.travel-dest-group');
                items(g).forEach(function (i) { if (!i.hasAttribute('data-gone')) { box(i).checked = t.checked; } });
                if (t.checked) { openGroup(g, true); }
            } else if (t.type === 'checkbox' && t.closest && t.closest('.travel-dest-item')) {
                var gcb = groupBox(groupOf(t.closest('.travel-dest-item')));
                if (gcb && !t.checked) { gcb.checked = false; }
            } else if (t.hasAttribute && t.hasAttribute('data-dest-sort') && country) {
                view(country).sort = t.value;
            }
            render();
        });

        form.addEventListener('input', function (e) {
            var t = e.target;
            if (!t || !t.hasAttribute || !t.hasAttribute('data-dest-leaf-filter')) { return; }
            var country = t.closest('.travel-dest-country');
            if (!country) { return; }
            view(country).filter = t.value;
            render();
        });
        // Enter in a country's filter box must not submit (save) the form.
        form.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && e.target && e.target.hasAttribute && e.target.hasAttribute('data-dest-leaf-filter')) {
                e.preventDefault();
            }
        });

        // ─── Save: one JSON field ───

        function payload() {
            var out = {};
            countries().forEach(function (country) {
                var key = country.getAttribute('data-country');
                var r = radio(country);
                var entry = { mode: r ? r.value : '', loaded: loaded(country), items: [], groups: [] };
                if (loaded(country)) {
                    items(country).forEach(function (item) { var cb = box(item); if (cb.checked) { entry.items.push(cb.value); } });
                    groups(country).forEach(function (g) { var cb = groupBox(g); if (cb && cb.checked) { entry.groups.push(cb.value); } });
                }
                out[key] = entry;
            });
            return out;
        }

        function destFields() {
            return arr(form.elements).filter(function (el) { return (el.name || '').indexOf('dest[') === 0; });
        }

        form.addEventListener('submit', function () {
            submitting = true;
            if (!jsonField) { return; }
            jsonField.value = JSON.stringify(payload());
            jsonField.disabled = false;
            destFields().forEach(function (i) { i.disabled = true; });
        });
        // The save bar's and the summary's buttons sit outside the form (form= attribute).
        window.addEventListener('pageshow', function () {
            submitting = false;
            destFields().forEach(function (i) { i.disabled = false; });
            if (jsonField) { jsonField.disabled = true; }
            render();
        });

        // Unsaved changes are lost on any link away; ask first. Saving is not leaving.
        window.addEventListener('beforeunload', function (e) {
            if (!submitting && document.body.contains(root) && root.classList.contains('is-dirty')) {
                e.preventDefault();
                e.returnValue = '';
            }
        });

        countries().forEach(function (c) {
            c.__lastSells = sells(c);
            var b = body(c);
            if (b && !b.hidden && !loaded(c)) { load(c); }
        });
        render();
    }

    function init() {
        arr(document.querySelectorAll('[data-travel-dest]')).forEach(initPicker);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
