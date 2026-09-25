// Eurosite — destination whitelist page (sphinx whitelist UX).
//
// Lives under <addon>/js/addons/eurosite/ — i.e. the DOCROOT /js/ tree — because
// whitelist.tpl loads it as {script src="js/addons/eurosite/whitelist.js"}. In a
// backend template a leading "js/" resolves to the docroot /js/, while a path
// with no prefix resolves to design/backend/js/. This file used to sit in
// design/backend/js/ while the template asked for "js/…", so it 404'd and the
// whole page was inert: no search, no expand, no save. Keep the two in step.
//
// It is a real file rather than an inline <script> because CS-Cart's backend
// rewrites inline scripts into {inline_script} Smarty blocks for CSP nonces,
// and that rewrite chokes on a body wrapped in {literal}. All server-side
// values ride as data attributes on #eurosite-whitelist-data.
//
// Model: state[cc] = { all: bool, cities: Set<code> } — mirrors the saved
// whitelist on load and is the single source of truth for badges, the
// summary panel, the whitelisted-only filter and the Save payload.
(function () {
    function init() {
        var dataEl = document.getElementById('eurosite-whitelist-data');
        if (!dataEl) {
            return; // not on the whitelist page
        }

        var citiesUrl = dataEl.getAttribute('data-cities-url') || '';
        var searchUrl = dataEl.getAttribute('data-search-url') || '';
        var txt = {
            loading: dataEl.getAttribute('data-txt-loading') || 'Loading...',
            noCities: dataEl.getAttribute('data-txt-no-cities') || 'No cities found.',
            hotels: dataEl.getAttribute('data-txt-hotels') || 'hotels',
            failed: dataEl.getAttribute('data-txt-failed') || 'Request failed.',
            allBadge: dataEl.getAttribute('data-txt-all-badge') || 'ALL CITIES',
            selected: dataEl.getAttribute('data-txt-selected') || 'selected',
            shown: dataEl.getAttribute('data-txt-shown') || 'shown',
            confirmRemove: dataEl.getAttribute('data-txt-confirm-remove') || 'Remove all whitelisted destinations?',
            noResults: dataEl.getAttribute('data-txt-no-results') || 'No matches.',
            countries: dataEl.getAttribute('data-txt-countries') || 'Countries',
            cities: dataEl.getAttribute('data-txt-cities') || 'Cities'
        };

        // ── State ──
        var state = {};          // cc -> { all: bool, cities: Set }
        var cityLists = {};      // cc -> [ { code, name, is_own } ] once loaded
        var filterOn = false;    // "Show only whitelisted"
        var ownOn = false;       // "Show only countries with own hotels" (countries only)
        var ownCitiesOnly = {};  // cc -> true: that country's "Show only own cities"

        var saved = {};
        try { saved = JSON.parse(dataEl.getAttribute('data-whitelist') || '{}'); } catch (e) { saved = {}; }
        Object.keys(saved).forEach(function (cc) {
            state[cc] = {
                all: !!saved[cc].all,
                cities: new Set(saved[cc].all ? [] : (saved[cc].cities || []))
            };
        });

        function esc(text) {
            var div = document.createElement('div');
            div.appendChild(document.createTextNode(text == null ? '' : String(text)));
            return div.innerHTML;
        }

        function row(cc) { return document.getElementById('eurosite-wl-row-' + cc); }
        function q(sel) { return document.querySelector(sel); }
        function qa(sel) { return Array.prototype.slice.call(document.querySelectorAll(sel)); }
        function countryCb(cc) { return q('.eurosite-country-all[data-country="' + cc + '"]'); }
        function selectAllCb(cc) { return q('.eurosite-select-all[data-country="' + cc + '"]'); }
        function selectOwnCb(cc) { return q('.eurosite-select-own[data-country="' + cc + '"]'); }
        function ownCodes(cc) {
            return (cityLists[cc] || []).filter(function (c) { return !!c.is_own; }).map(function (c) { return c.code; });
        }
        function cityBox(cc) { return q('.eurosite-city-box[data-country="' + cc + '"]'); }
        function cityGrid(cc) { return q('.eurosite-city-grid[data-country="' + cc + '"]'); }
        function arrow(cc) { return q('.eurosite-expand[data-country="' + cc + '"]'); }

        function countryName(cc) {
            var r = row(cc);
            if (!r) { return cc; }
            var nameEl = r.querySelector('.eurosite-country-name');
            var name = nameEl ? nameEl.textContent.trim() : '';
            return name || cc;
        }

        // ── Badges / summary / filter ──
        // Ticked exactly when every own-offer city of the country is selected
        // (by "Select all cities", the country tick, or one by one).
        function syncSelectOwn(cc) {
            var cb = selectOwnCb(cc);
            if (!cb) { return; }
            var own = ownCodes(cc);
            cb.checked = own.length > 0 && !!state[cc]
                && own.every(function (code) { return state[cc].all || state[cc].cities.has(code); });
        }

        function updateBadge(cc) {
            syncSelectOwn(cc);
            var badge = q('.eurosite-wl-badge[data-country="' + cc + '"]');
            if (!badge) { return; }
            if (!state[cc]) {
                badge.innerHTML = '';
            } else if (state[cc].all) {
                badge.innerHTML = '<span style="background:#28a745; color:#fff; padding:2px 8px; border-radius:3px;">' + esc(txt.allBadge) + '</span>';
            } else {
                badge.innerHTML = '<span style="background:#e67e22; color:#fff; padding:2px 8px; border-radius:3px;">' + state[cc].cities.size + ' ' + esc(txt.selected) + '</span>';
            }
        }

        function updateSummary() {
            var ccs = Object.keys(state);
            var cityTotal = 0;
            var detail = '';
            ccs.sort().forEach(function (cc) {
                var line;
                if (state[cc].all) {
                    line = cityLists[cc] ? cityLists[cc].length + ' (' + txt.allBadge.toLowerCase() + ')' : txt.allBadge.toLowerCase();
                    if (cityLists[cc]) { cityTotal += cityLists[cc].length; }
                } else {
                    line = String(state[cc].cities.size);
                    cityTotal += state[cc].cities.size;
                }
                detail += '<div style="margin:6px 0; padding:6px 0; border-bottom:1px solid #eee; font-size:12px;">'
                    + '<strong>' + esc(countryName(cc)) + '</strong><br/>'
                    + '<span style="color:#888;">' + esc(line) + '</span></div>';
            });
            var elC = document.getElementById('eurosite-wl-summary-countries');
            var elCi = document.getElementById('eurosite-wl-summary-cities');
            var elD = document.getElementById('eurosite-wl-summary-detail');
            if (elC) { elC.textContent = String(ccs.length); }
            if (elCi) { elCi.textContent = String(cityTotal); }
            if (elD) { elD.innerHTML = detail; }
        }

        // The two page filters combine on countries. Inside an open country,
        // every city shows unless "Show only whitelisted" is on (ticked ones
        // only) or that country's own "Show only own cities" is ticked.
        function applyFilter() {
            var rows = qa('.eurosite-country-row');
            var shown = 0;
            rows.forEach(function (r) {
                var cc = r.getAttribute('data-country');
                var hasOwn = parseInt(r.getAttribute('data-own') || '0', 10) > 0;
                var visible = (!filterOn || !!state[cc]) && (!ownOn || hasOwn);
                r.style.display = visible ? '' : 'none';
                if (visible) { shown++; }
                qa('.eurosite-city[data-country="' + cc + '"]').forEach(function (cb) {
                    var cityVisible = (!filterOn || cb.checked) && (!ownCitiesOnly[cc] || cb.getAttribute('data-own') === '1');
                    cb.closest('label').style.display = !visible || cityVisible ? '' : 'none';
                });
            });
            var countEl = document.getElementById('eurosite-wl-filter-count');
            if (countEl) {
                countEl.textContent = filterOn || ownOn ? shown + ' / ' + rows.length + ' ' + txt.shown : '';
            }
        }

        // ── City grid (lazy) ──
        function renderCities(cc) {
            var grid = cityGrid(cc);
            if (!grid) { return; }
            var st = state[cc];
            var html = '';
            (cityLists[cc] || []).forEach(function (city) {
                var checked = st && (st.all || st.cities.has(city.code)) ? ' checked' : '';
                var own = city.is_own ? ' <span class="label label-info" title="own offers">own</span>' : '';
                // Synced hotels in this destination: what whitelisting it brings in.
                var hotels = city.hotels > 0 ? ' <span class="muted">· ' + esc(String(city.hotels)) + ' ' + esc(txt.hotels) + '</span>' : '';
                html += '<label style="display:inline-flex; align-items:center; gap:3px; min-width:200px; font-size:12px; color:#444; cursor:pointer;">'
                    + '<input type="checkbox" class="eurosite-city" data-country="' + esc(cc) + '" data-own="' + (city.is_own ? '1' : '0') + '" value="' + esc(city.code) + '"' + checked + '> '
                    + '<span>' + esc(city.name || city.code) + ' <code>' + esc(city.code) + '</code>' + own + hotels + '</span></label>';
            });
            grid.innerHTML = html || '<span class="muted" style="font-size:12px;">' + esc(txt.noCities) + '</span>';
            qa('.eurosite-city[data-country="' + cc + '"]').forEach(function (cb) {
                cb.addEventListener('change', function () { onCityToggle(cc, cb); });
            });
            syncSelectOwn(cc);
            applyFilter();
        }

        function loadCities(cc, done) {
            if (cityLists[cc]) { if (done) { done(); } return; }
            var grid = cityGrid(cc);
            if (grid) { grid.innerHTML = '<span class="muted" style="font-size:12px;">' + esc(txt.loading) + '</span>'; }
            fetch(citiesUrl + '&country=' + encodeURIComponent(cc), { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data.success) {
                        if (grid) { grid.innerHTML = '<span class="text-error">' + esc(data.error || 'error') + '</span>'; }
                        return;
                    }
                    cityLists[cc] = data.cities || [];
                    renderCities(cc);
                    updateSummary();
                    if (done) { done(); }
                })
                .catch(function () {
                    if (grid) { grid.innerHTML = '<span class="text-error">' + esc(txt.failed) + '</span>'; }
                });
        }

        // forceOpen=true never collapses — used by the search picker, which must
        // leave the country open whether or not it already was.
        function expand(cc, forceOpen) {
            var box = cityBox(cc);
            if (!box) { return; }
            var isOpen = box.style.display !== 'none';
            var open = forceOpen || !isOpen;
            box.style.display = open ? 'block' : 'none';
            var a = arrow(cc);
            if (a) { a.innerHTML = open ? '&#9660;' : '&#9654;'; }
            if (open) { loadCities(cc); }
        }

        // ── Selection handlers (sphinx semantics) ──
        function onCountryToggle(cc, cb) {
            if (cb.checked) {
                state[cc] = { all: true, cities: new Set() };
                if (selectAllCb(cc)) { selectAllCb(cc).checked = true; }
                qa('.eurosite-city[data-country="' + cc + '"]').forEach(function (c) { c.checked = true; });
            } else {
                delete state[cc];
                if (selectAllCb(cc)) { selectAllCb(cc).checked = false; }
                qa('.eurosite-city[data-country="' + cc + '"]').forEach(function (c) { c.checked = false; });
            }
            updateBadge(cc);
            updateSummary();
            applyFilter();
        }

        function onSelectAllToggle(cc, cb) {
            if (cb.checked) {
                state[cc] = { all: true, cities: new Set() };
                if (countryCb(cc)) { countryCb(cc).checked = true; }
                qa('.eurosite-city[data-country="' + cc + '"]').forEach(function (c) { c.checked = true; });
            } else {
                state[cc] = { all: false, cities: new Set() };
                qa('.eurosite-city[data-country="' + cc + '"]').forEach(function (c) { c.checked = false; });
                delete state[cc];
                if (countryCb(cc)) { countryCb(cc).checked = false; }
            }
            updateBadge(cc);
            updateSummary();
            applyFilter();
        }

        // "Select all own cities": adds (or removes) every own-offer city of the
        // country to the selection, leaving its other cities as they are.
        function onSelectOwnToggle(cc, cb) {
            var own = ownCodes(cc);
            if (own.length === 0) { cb.checked = false; return; }
            var list = cityLists[cc] || [];
            if (!state[cc]) {
                state[cc] = { all: false, cities: new Set() };
            } else if (state[cc].all) {
                // "all" becomes the explicit list, so own cities can leave it
                state[cc] = { all: false, cities: new Set(list.map(function (c) { return c.code; })) };
            }
            own.forEach(function (code) {
                if (cb.checked) { state[cc].cities.add(code); } else { state[cc].cities.delete(code); }
            });
            if (list.length > 0 && state[cc].cities.size >= list.length) {
                state[cc] = { all: true, cities: new Set() };
            } else if (state[cc].cities.size === 0) {
                delete state[cc];
            }
            qa('.eurosite-city[data-country="' + cc + '"]').forEach(function (c) {
                c.checked = !!state[cc] && (state[cc].all || state[cc].cities.has(c.value));
            });
            if (selectAllCb(cc)) { selectAllCb(cc).checked = !!state[cc] && state[cc].all; }
            if (countryCb(cc)) { countryCb(cc).checked = !!state[cc]; }
            updateBadge(cc);
            updateSummary();
            applyFilter();
        }

        function onCityToggle(cc, cb) {
            if (!state[cc]) {
                state[cc] = { all: false, cities: new Set() };
            }
            if (state[cc].all) {
                // Leaving "all": keep exactly what is checked in the grid now.
                state[cc] = { all: false, cities: new Set() };
                qa('.eurosite-city[data-country="' + cc + '"]:checked').forEach(function (c) {
                    state[cc].cities.add(c.value);
                });
                if (selectAllCb(cc)) { selectAllCb(cc).checked = false; }
            } else if (cb.checked) {
                state[cc].cities.add(cb.value);
            } else {
                state[cc].cities.delete(cb.value);
            }
            // Everything checked -> promote to "all" (sphinx behaviour)
            if (cityLists[cc] && cityLists[cc].length > 0 && state[cc].cities.size >= cityLists[cc].length) {
                state[cc] = { all: true, cities: new Set() };
                if (selectAllCb(cc)) { selectAllCb(cc).checked = true; }
            }
            // Nothing left -> country drops off the whitelist
            if (!state[cc].all && state[cc].cities.size === 0) {
                delete state[cc];
            }
            if (countryCb(cc)) { countryCb(cc).checked = !!state[cc]; }
            updateBadge(cc);
            updateSummary();
            applyFilter();
        }

        // ── Wire static elements ──
        // The arrow AND the country name both expand: a 20px glyph is a small
        // target, and clicking the name is what everyone tries first.
        qa('.eurosite-expand').forEach(function (el) {
            el.addEventListener('click', function (e) {
                e.preventDefault();
                expand(el.getAttribute('data-country'), false);
            });
        });
        qa('.eurosite-country-name').forEach(function (el) {
            el.addEventListener('click', function (e) {
                e.preventDefault();
                expand(el.getAttribute('data-country'), false);
            });
        });
        qa('.eurosite-country-all').forEach(function (cb) {
            cb.addEventListener('change', function () { onCountryToggle(cb.getAttribute('data-country'), cb); });
        });
        qa('.eurosite-select-all').forEach(function (cb) {
            cb.addEventListener('change', function () { onSelectAllToggle(cb.getAttribute('data-country'), cb); });
        });

        qa('.eurosite-select-own').forEach(function (cb) {
            cb.addEventListener('change', function () { onSelectOwnToggle(cb.getAttribute('data-country'), cb); });
        });
        qa('.eurosite-own-cities').forEach(function (cb) {
            cb.addEventListener('change', function () {
                ownCitiesOnly[cb.getAttribute('data-country')] = cb.checked;
                applyFilter();
            });
        });

        var ownCb = document.getElementById('eurosite-wl-own-filter');
        if (ownCb) {
            ownCb.addEventListener('change', function () {
                ownOn = ownCb.checked;
                applyFilter();
            });
        }

        var filterCb = document.getElementById('eurosite-wl-filter');
        if (filterCb) {
            filterCb.addEventListener('change', function () {
                filterOn = filterCb.checked;
                applyFilter();
            });
        }

        // ── Save / Remove all ──
        var saveBtn = document.getElementById('eurosite-wl-save');
        if (saveBtn) {
            saveBtn.addEventListener('click', function () {
                var entries = [];
                Object.keys(state).forEach(function (cc) {
                    if (state[cc].all) {
                        entries.push({ country_code: cc, city_code: '', selection_type: 'all' });
                    } else {
                        state[cc].cities.forEach(function (code) {
                            entries.push({ country_code: cc, city_code: code, selection_type: 'specific' });
                        });
                    }
                });
                document.getElementById('eurosite-whitelist-json').value = JSON.stringify(entries);
                document.getElementById('eurosite-whitelist-form').submit();
            });
        }

        var removeBtn = document.getElementById('eurosite-wl-remove-all');
        if (removeBtn) {
            removeBtn.addEventListener('click', function () {
                if (!window.confirm(txt.confirmRemove)) { return; }
                state = {};
                qa('.eurosite-country-all, .eurosite-select-all, .eurosite-select-own, .eurosite-city').forEach(function (cb) { cb.checked = false; });
                qa('.eurosite-wl-badge').forEach(function (b) { b.innerHTML = ''; });
                updateSummary();
                applyFilter();
            });
        }

        // ── Search (country + city), sphinx-style ──
        //
        // A plain input plus an own dropdown, NOT select2: the whitelist page
        // must not depend on a library shipped by the CS-Cart kit. The sphinx
        // whitelist — the page this one is modelled on — does exactly this.
        var searchInput = document.getElementById('eurosite-wl-search');
        var searchBox = document.getElementById('eurosite-wl-search-results');

        function hideResults() {
            if (searchBox) {
                searchBox.style.display = 'none';
                searchBox.innerHTML = '';
            }
        }

        function jumpToCountry(cc, flash) {
            var r = row(cc);
            if (!r) { return; }
            if (r.style.display === 'none') {
                // a search hit is shown even when a filter hides its country
                r.style.display = '';
            }
            r.scrollIntoView({ behavior: 'smooth', block: 'center' });
            if (flash) {
                var prev = r.style.backgroundColor;
                r.style.backgroundColor = '#fff3cd';
                setTimeout(function () { r.style.backgroundColor = prev; }, 1600);
            }
        }

        // A search hit only navigates — it never ticks a box. Checking on click
        // would make a mistyped search silently whitelist a destination.
        function handleSearchPick(item) {
            if (!item || !item.country_code) { return; }
            var cc = item.country_code;
            hideResults();
            if (searchInput) { searchInput.value = ''; }
            if (item.type === 'city' && item.city_code) {
                expand(cc, true);
                loadCities(cc, function () {
                    var cb = q('.eurosite-city[data-country="' + cc + '"][value="' + item.city_code + '"]');
                    if (cb) {
                        var label = cb.closest('label');
                        if (label) {
                            label.style.outline = '2px solid #f0ad4e';
                            label.scrollIntoView({ behavior: 'smooth', block: 'center' });
                            setTimeout(function () { label.style.outline = ''; }, 1600);
                        }
                    } else {
                        jumpToCountry(cc, true);
                    }
                });
            } else {
                expand(cc, true);
                jumpToCountry(cc, true);
            }
        }

        function renderResults(results) {
            if (!searchBox) { return; }
            if (!results.length) {
                searchBox.innerHTML = '<div style="padding:8px 12px; color:#888; font-size:12px;">' + esc(txt.noResults) + '</div>';
                searchBox.style.display = 'block';
                return;
            }
            var groups = { country: [], city: [] };
            results.forEach(function (r) { (groups[r.type] || groups.city).push(r); });

            var html = '';
            [['country', txt.countries], ['city', txt.cities]].forEach(function (pair) {
                var items = groups[pair[0]];
                if (!items.length) { return; }
                html += '<div style="padding:4px 12px; background:#f5f5f5; font-size:11px; text-transform:uppercase; color:#888;">'
                    + esc(pair[1]) + '</div>';
                items.forEach(function (item) {
                    html += '<div class="eurosite-wl-hit" data-type="' + esc(item.type) + '"'
                        + ' data-country="' + esc(item.country_code) + '"'
                        + ' data-city="' + esc(item.city_code || '') + '"'
                        + ' style="padding:6px 12px; cursor:pointer; border-bottom:1px solid #f5f5f5; font-size:13px;">'
                        + esc(item.text) + '</div>';
                });
            });
            searchBox.innerHTML = html;
            searchBox.style.display = 'block';
            qa('.eurosite-wl-hit').forEach(function (el) {
                el.addEventListener('mouseenter', function () { el.style.background = '#f8f9fa'; });
                el.addEventListener('mouseleave', function () { el.style.background = ''; });
                el.addEventListener('click', function () {
                    handleSearchPick({
                        type: el.getAttribute('data-type'),
                        country_code: el.getAttribute('data-country'),
                        city_code: el.getAttribute('data-city')
                    });
                });
            });
        }

        if (searchInput && searchBox) {
            var searchTimer = null;
            var lastQuery = '';

            searchInput.addEventListener('input', function () {
                var term = searchInput.value.trim();
                if (searchTimer) { clearTimeout(searchTimer); }
                if (term.length < 2) { hideResults(); return; }
                searchTimer = setTimeout(function () {
                    lastQuery = term;
                    fetch(searchUrl + '&q=' + encodeURIComponent(term), { credentials: 'same-origin' })
                        .then(function (r) { return r.json(); })
                        .then(function (data) {
                            if (term !== lastQuery) { return; } // a newer keystroke won
                            renderResults(data.results || []);
                        })
                        .catch(function () {
                            searchBox.innerHTML = '<div style="padding:8px 12px;" class="text-error">' + esc(txt.failed) + '</div>';
                            searchBox.style.display = 'block';
                        });
                }, 250);
            });

            searchInput.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') { hideResults(); }
            });

            document.addEventListener('click', function (e) {
                if (!searchBox.contains(e.target) && e.target !== searchInput) { hideResults(); }
            });
        }

        // ── Initial paint ──
        updateSummary();
        Object.keys(state).forEach(updateBadge);
    }

    // The admin panel may inject this page after DOMContentLoaded (AJAX
    // navigation) — run immediately when the DOM is already there.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
