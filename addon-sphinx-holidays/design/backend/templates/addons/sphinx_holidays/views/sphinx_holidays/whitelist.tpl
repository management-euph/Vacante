{capture name="mainbox"}

<div class="travel-admin-panel">

    {if $total_destinations == 0}
        <div class="alert alert-warning">
            <i class="icon-warning-sign"></i> {__("sphinx_holidays.no_destinations_synced")}
        </div>
    {else}

    {* ── Stats Bar ── *}
    <div class="sync-stats" style="margin-bottom: 20px;">
        <div class="stat-card">
            <div class="stat-value">{$counts_by_type.continent|default:0}</div>
            <div class="stat-label">{__("sphinx_holidays.continents")}</div>
        </div>
        <div class="stat-card success">
            <div class="stat-value">{$counts_by_type.country|default:0}</div>
            <div class="stat-label">{__("sphinx_holidays.countries")}</div>
        </div>
        <div class="stat-card">
            <div class="stat-value">{$counts_by_type.region|default:0}</div>
            <div class="stat-label">{__("sphinx_holidays.regions")}</div>
        </div>
        <div class="stat-card warning">
            <div class="stat-value">{math equation="x+y" x=$counts_by_type.city|default:0 y=$counts_by_type.destination|default:0}</div>
            <div class="stat-label">{__("sphinx_holidays.cities")}</div>
        </div>
    </div>

    <p class="muted" style="margin-bottom: 15px;">{__("sphinx_holidays.whitelist_description")}</p>

    <div style="display: flex; gap: 30px; align-items: flex-start;">

        {* ── Left: Country Tree ── *}
        <div style="flex: 1; min-width: 0;">

            {* Search *}
            <div style="margin-bottom: 12px; position: relative;">
                <input type="text" id="wl_search" class="input-large"
                       placeholder="{__("sphinx_holidays.search_destinations")}"
                       style="width: 100%; box-sizing: border-box;" autocomplete="off" />
                <div id="wl_search_results" style="display:none; position:absolute; z-index:100; left:0; right:0; top:100%;
                     background:#fff; border:1px solid #ccc; border-top:none; border-radius:0 0 6px 6px;
                     max-height:400px; overflow-y:auto; box-shadow:0 4px 12px rgba(0,0,0,0.15);"></div>
            </div>

            {* ── View Mode Toggle ── *}
            <div style="margin-bottom: 12px; display: flex; align-items: center; gap: 10px;">
                <label style="display: inline-flex; align-items: center; gap: 6px; cursor: pointer; font-size: 13px; color: #555; user-select: none;">
                    <input type="checkbox" id="wl_filter_whitelisted" onchange="toggleWhitelistFilter(this.checked)" />
                    <span>{__("sphinx_holidays.show_whitelisted_only")}</span>
                </label>
                <span id="wl_filter_count" style="font-size: 11px; color: #888;"></span>
            </div>

            {* Country List *}
            <form id="whitelist_form" method="post" action="{"sphinx_holidays.save_whitelist"|fn_url}">
            <input type="hidden" name="security_hash" value="{$security_hash}" />

            <div id="wl_country_list">
                {foreach from=$sphinx_whitelist_countries item=c name=cloop}
                <div class="wl-country" data-country-id="{$c.destination_id}" data-cc="{$c.country_code}" data-name="{$c.name|lower}">
                    <div style="display: flex; align-items: center; padding: 6px 0; border-bottom: 1px solid #eee;">
                        {* Expand arrow *}
                        <span class="wl-expand" style="cursor:pointer; width:20px; text-align:center; color:#999; user-select:none;"
                              onclick="toggleCountryExpand({$c.destination_id})">&#9654;</span>

                        {* Country checkbox *}
                        <label style="margin: 0 8px 0 0; font-weight: bold; cursor: pointer; display: flex; align-items: center; gap: 6px;">
                            <input type="checkbox" class="wl-country-cb" value="{$c.destination_id}"
                                   {if $c.is_whitelisted}checked{/if}
                                   onchange="onCountryToggle(this, {$c.destination_id})" />
                            {$c.name}
                        </label>

                        {* Badge *}
                        <span class="wl-badge" id="wl_badge_{$c.destination_id}" style="font-size:11px; padding:2px 8px; border-radius:3px; font-weight:bold;">
                            {if $c.is_whitelisted && $c.selection_type === 'all'}
                                <span style="background:#28a745; color:#fff; padding:2px 8px; border-radius:3px;">{__("sphinx_holidays.select_all_children")|upper}</span>
                            {elseif $c.is_whitelisted && $c.whitelisted_child_count > 0}
                                <span style="background:#e67e22; color:#fff; padding:2px 8px; border-radius:3px;">{$c.whitelisted_child_count} {__("sphinx_holidays.specific_regions")}</span>
                            {/if}
                        </span>
                    </div>

                    {* Expandable children area (hidden by default) *}
                    <div class="wl-children" id="wl_children_{$c.destination_id}" style="display:none; padding: 8px 0 8px 28px; background: #fafafa; border-bottom: 1px solid #eee;">
                        <div style="margin-bottom: 8px;">
                            <label style="cursor:pointer; font-size:12px; color:#555;">
                                <input type="checkbox" class="wl-select-all-children" data-country="{$c.destination_id}"
                                       {if $c.is_whitelisted && $c.selection_type === 'all'}checked{/if}
                                       onchange="onSelectAllChildren(this, {$c.destination_id})" />
                                <strong>{__("sphinx_holidays.select_all")}</strong>
                            </label>
                        </div>
                        <div class="wl-children-grid" id="wl_grid_{$c.destination_id}">
                            <span class="muted" style="font-size:12px;">Loading...</span>
                        </div>
                    </div>
                </div>
                {/foreach}
            </div>

            {* Hidden inputs will be built by JS before submit *}
            <div id="wl_hidden_inputs"></div>

            </form>

            {* ── Pagination (client-side) ──
               The country list is a STATEFUL editor: unsaved check/uncheck
               selections live only in JS `state` until Save. A server-side
               pager (like countries.manage) reloads the whole page per page
               and would discard those in-progress edits — so we window the
               already-rendered list client-side instead. Search, the
               whitelisted-only filter and the summary panel all keep working
               across the full set; only what's DISPLAYED is paged. *}
            <div id="wl_pager" class="wl-pager" style="display:none; align-items:center; gap:8px; margin-top:14px; padding-top:12px; border-top:1px solid #eee; font-size:13px; color:#555;">
                <button type="button" class="btn btn-small" id="wl_pg_first" title="{__("sphinx_holidays.first_page")}" onclick="wlFirst()">&laquo;</button>
                <button type="button" class="btn btn-small" id="wl_pg_prev" title="{__("sphinx_holidays.prev_page")}" onclick="wlPrev()">&lsaquo;</button>
                <span id="wl_pg_info" style="min-width:110px; text-align:center;"></span>
                <button type="button" class="btn btn-small" id="wl_pg_next" title="{__("sphinx_holidays.next_page")}" onclick="wlNext()">&rsaquo;</button>
                <button type="button" class="btn btn-small" id="wl_pg_last" title="{__("sphinx_holidays.last_page")}" onclick="wlLast()">&raquo;</button>
                <label style="margin-left:auto; display:inline-flex; align-items:center; gap:6px; font-size:12px; color:#888;">
                    {__("sphinx_holidays.per_page")}
                    <select id="wl_pg_perpage" class="input-small" style="width:auto; margin:0;" onchange="wlSetPerPage(this.value)">
                        <option value="25">25</option>
                        <option value="50" selected>50</option>
                        <option value="100">100</option>
                    </select>
                </label>
            </div>
        </div>

        {* ── Right: Summary Panel ── *}
        <div style="width: 300px; flex-shrink: 0; border: 1px solid #ddd; border-radius: 6px; padding: 20px; background: #fafafa; position: sticky; top: 80px;">
            <h4 style="margin-top: 0;">{__("sphinx_holidays.whitelist_summary")}</h4>

            <div style="margin-bottom: 8px;">
                <strong>{__("sphinx_holidays.whitelisted_countries")}:</strong>
                <span id="wl_summary_countries">{$whitelisted_country_count|default:0}</span>
            </div>

            <div style="margin-bottom: 8px;">
                <strong>{__("sphinx_holidays.whitelisted_regions")}:</strong>
                <span id="wl_summary_regions">{$whitelisted_region_count|default:0}</span>
            </div>

            {* Per-country detail — server-rendered initially, rebuilt by JS on changes *}
            <div id="wl_summary_country_names" style="margin-bottom: 12px; max-height: 300px; overflow-y: auto;">
                {if $whitelist_summary}
                    <div style="margin-bottom: 6px; font-size: 12px; color: #555;">
                        <strong>{__("sphinx_holidays.countries")}:</strong>
                        {foreach from=$whitelist_summary item=wc name=wcs}{$wc.name}{if !$smarty.foreach.wcs.last}, {/if}{/foreach}
                    </div>
                    {foreach from=$whitelist_summary item=wc}
                        <div style="margin: 6px 0; padding: 6px 0; border-bottom: 1px solid #eee; font-size: 12px;">
                            <strong>{$wc.name}</strong><br/>
                            <span style="color: #888;">regions:
                                {if $wc.full_regions > 0}<span style="color: #28a745; font-weight: 600;">{$wc.full_regions} (full)</span>{/if}
                                {if $wc.full_regions > 0 && $wc.partial_regions > 0} {/if}
                                {if $wc.partial_regions > 0}<span style="color: #e67e22; font-weight: 600;">{$wc.partial_regions} (partial)</span>{/if}
                                {if $wc.full_regions == 0 && $wc.partial_regions == 0}0{/if}
                            </span><br/>
                            <span style="color: #888;">cities: {$wc.total_cities}</span>
                        </div>
                    {/foreach}
                {/if}
            </div>

            <button type="button" class="btn btn-primary" onclick="submitWhitelist()" style="width: 100%;">
                <i class="icon-ok"></i> {__("sphinx_holidays.save_whitelist")}
            </button>

            <button type="button" class="btn" onclick="removeAllWhitelist()" style="width: 100%; margin-top: 8px;">
                <i class="icon-trash"></i> {__("sphinx_holidays.remove_all_whitelist")}
            </button>
        </div>

    </div>

    {/if}
</div>

<script>
(function() {
    // State: { countryId: { type: 'all'|'specific', children: Set<int> } }
    var state = {ldelim}{rdelim};
    var loadedTree = {ldelim}{rdelim};    // countryId => [ { destination_id, name, type, children: [...] } ]
    var loadedFlat = {ldelim}{rdelim};    // countryId => [ { destination_id, name, type } ] (flat list)
    var treeUrl = '{"sphinx_holidays.get_destinations_tree"|fn_url}';
    var searchUrl = '{"sphinx_holidays.search_destinations"|fn_url}';
    var childrenUrl = '{"sphinx_holidays.get_whitelist_children"|fn_url}';
    var searchTimeout = null;

    // Initialize state from server data
    {foreach from=$sphinx_whitelist_countries item=c}
    {if $c.is_whitelisted}
    state[{$c.destination_id}] = {ldelim}
        type: '{$c.selection_type}',
        children: new Set()
    {rdelim};
    {/if}
    {/foreach}

    {literal}

    // ─── Whitelisted-only filter ───

    var whitelistFilterActive = false;

    window.toggleWhitelistFilter = function(checked) {
        whitelistFilterActive = checked;
        applyWhitelistFilter();
    };

    // ─── Client-side pagination ───
    // The country list is paged in the browser (see the pager markup note).
    // applyWhitelistFilter() computes which countries are ELIGIBLE (pass the
    // current search + whitelisted-only toggle); wlRenderPage() then shows only
    // the current page's slice of that eligible set and hides the rest.

    window.wlPage = 1;
    window.wlPerPage = 50;
    window.wlPageCount = 1;
    var wlEligible = [];

    function wlComputeEligible() {
        var searchVal = document.getElementById('wl_search').value.toLowerCase().trim();
        wlEligible = [];
        document.querySelectorAll('.wl-country').forEach(function(el) {
            var countryId = parseInt(el.dataset.countryId);
            var isWhitelisted = !!state[countryId];
            var name = el.dataset.name || '';
            var ok = true;
            if (whitelistFilterActive && !isWhitelisted) ok = false;
            if (ok && searchVal && name.indexOf(searchVal) === -1) ok = false;
            if (ok) wlEligible.push(el);
        });
    }

    function wlRenderPage() {
        window.wlPageCount = Math.max(1, Math.ceil(wlEligible.length / wlPerPage));
        if (wlPage > wlPageCount) window.wlPage = wlPageCount;
        if (wlPage < 1) window.wlPage = 1;

        var start = (wlPage - 1) * wlPerPage;
        var end = start + wlPerPage;

        // Hide every country, then reveal only this page's slice of eligibles.
        document.querySelectorAll('.wl-country').forEach(function(el) { el.style.display = 'none'; });
        wlEligible.forEach(function(el, idx) {
            if (idx >= start && idx < end) el.style.display = '';
        });

        var total = wlEligible.length;
        var info = document.getElementById('wl_pg_info');
        if (info) {
            info.textContent = total === 0
                ? '0'
                : ((start + 1) + '–' + Math.min(end, total) + ' / ' + total);
        }
        var pager = document.getElementById('wl_pager');
        if (pager) pager.style.display = total > 0 ? 'flex' : 'none';

        [['wl_pg_first', wlPage <= 1], ['wl_pg_prev', wlPage <= 1],
         ['wl_pg_next', wlPage >= wlPageCount], ['wl_pg_last', wlPage >= wlPageCount]]
            .forEach(function(pair) {
                var b = document.getElementById(pair[0]);
                if (b) b.disabled = pair[1];
            });
    }

    window.wlGotoPage = function(p) {
        window.wlPage = p;
        wlRenderPage();
        var list = document.getElementById('wl_country_list');
        if (list) list.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };
    window.wlFirst = function() { wlGotoPage(1); };
    window.wlPrev = function() { wlGotoPage(wlPage - 1); };
    window.wlNext = function() { wlGotoPage(wlPage + 1); };
    window.wlLast = function() { wlGotoPage(wlPageCount); };
    window.wlSetPerPage = function(v) {
        window.wlPerPage = parseInt(v, 10) || 50;
        window.wlPage = 1;
        wlRenderPage();
    };

    function applyWhitelistFilter() {
        // A filter/search change resets to page 1 over the new eligible set.
        wlComputeEligible();
        window.wlPage = 1;
        wlRenderPage();

        var countEl = document.getElementById('wl_filter_count');
        if (whitelistFilterActive) {
            var whitelistedTotal = Object.keys(state).length;
            countEl.textContent = whitelistedTotal + ' / ' + document.querySelectorAll('.wl-country').length;
        } else {
            countEl.textContent = '';
        }
    }

    // ─── Country expand/collapse ───

    window.toggleCountryExpand = function(countryId) {
        var el = document.getElementById('wl_children_' + countryId);
        var arrow = el.previousElementSibling.querySelector('.wl-expand');
        if (el.style.display === 'none') {
            el.style.display = 'block';
            arrow.innerHTML = '&#9660;';
            loadChildren(countryId);
        } else {
            el.style.display = 'none';
            arrow.innerHTML = '&#9654;';
        }
    };

    function loadChildren(countryId) {
        if (loadedTree[countryId]) {
            renderChildren(countryId);
            return;
        }
        var countryEl = document.querySelector('[data-country-id="' + countryId + '"]');
        var cc = countryEl ? countryEl.dataset.cc : '';
        if (!cc) return;

        var xhr = new XMLHttpRequest();
        xhr.open('GET', treeUrl + '&country_code=' + encodeURIComponent(cc));
        xhr.onload = function() {
            if (xhr.status === 200) {
                var data = JSON.parse(xhr.responseText);
                var tree = [];
                var flat = [];
                (data.tree || []).forEach(function(region) {
                    var regionItem = {
                        destination_id: parseInt(region.destination_id),
                        name: region.name,
                        type: region.type || 'region',
                        children: []
                    };
                    flat.push({ destination_id: regionItem.destination_id, name: regionItem.name, type: regionItem.type });
                    (region.children || []).forEach(function(city) {
                        var cityItem = {
                            destination_id: parseInt(city.destination_id),
                            name: city.name,
                            type: city.type || 'city'
                        };
                        regionItem.children.push(cityItem);
                        flat.push(cityItem);
                    });
                    tree.push(regionItem);
                });
                loadedTree[countryId] = tree;
                loadedFlat[countryId] = flat;
                renderChildren(countryId);
            }
        };
        xhr.send();
    }

    // ─── Hierarchical render: regions as headers, cities grouped beneath ───

    function renderChildren(countryId) {
        var grid = document.getElementById('wl_grid_' + countryId);
        var tree = loadedTree[countryId] || [];
        if (tree.length === 0) {
            grid.innerHTML = '<span class="muted" style="font-size:12px;">No regions/cities found</span>';
            return;
        }
        var isAll = state[countryId] && state[countryId].type === 'all';
        var selectedChildren = state[countryId] ? state[countryId].children : new Set();

        var html = '';
        tree.forEach(function(region) {
            var regionId = region.destination_id;
            // Determine if all cities in this region are selected
            var allCitiesSelected = isAll;
            if (!isAll && region.children.length > 0) {
                allCitiesSelected = region.children.every(function(c) {
                    return selectedChildren.has(c.destination_id);
                }) && selectedChildren.has(regionId);
            }
            var regionChecked = isAll || selectedChildren.has(regionId) ? ' checked' : '';

            // Auto-expand regions that have selected cities
            var shouldExpand = isAll;
            if (!shouldExpand && region.children.length > 0) {
                shouldExpand = region.children.some(function(c) {
                    return selectedChildren.has(c.destination_id);
                });
            }

            // Region header
            html += '<div class="wl-region-block" style="margin-bottom:12px; width:100%;">';
            html += '<div style="display:flex; align-items:center; gap:6px; padding:4px 8px; background:#e8f4fd; border-left:3px solid #2196F3; border-radius:0 4px 4px 0; margin-bottom:4px;">';
            // Toggle arrow for expanding/collapsing cities
            if (region.children.length > 0) {
                html += '<span id="wl_arrow_' + countryId + '_' + regionId + '" ' +
                    'onclick="toggleRegionCities(' + countryId + ',' + regionId + ')" ' +
                    'style="cursor:pointer; font-size:13px; font-weight:bold; width:18px; height:18px; line-height:18px; display:inline-block; text-align:center; user-select:none; color:#1565C0; background:#d0e8f7; border-radius:3px;">' +
                    (shouldExpand ? '\u2212' : '+') + '</span>';
            } else {
                html += '<span style="width:14px; display:inline-block;"></span>';
            }
            html += '<input type="checkbox" class="wl-child-cb wl-region-cb" value="' + regionId + '" ' +
                'data-country="' + countryId + '" data-region-id="' + regionId + '"' + regionChecked +
                ' onchange="onRegionToggle(this,' + countryId + ',' + regionId + ')" />';
            html += '<span style="font-weight:600; font-size:13px; color:#1565C0;">' + region.name + '</span>';
            html += '<span style="font-size:10px; padding:1px 6px; background:#2196F3; color:#fff; border-radius:3px; text-transform:uppercase;">Region</span>';
            if (region.children.length > 0) {
                html += '<span onclick="toggleRegionCities(' + countryId + ',' + regionId + ')" ' +
                    'style="font-size:11px; color:#888; margin-left:auto; cursor:pointer;">' + region.children.length + ' cities</span>';
            }
            html += '</div>';

            // Cities under this region (collapsed by default, auto-expanded if any selected)
            if (region.children.length > 0) {
                var displayStyle = shouldExpand ? 'flex' : 'none';
                html += '<div id="wl_cities_' + countryId + '_' + regionId + '" style="display:' + displayStyle + '; flex-wrap:wrap; gap:3px 14px; padding-left:24px;">';
                region.children.forEach(function(city) {
                    var cityChecked = isAll || selectedChildren.has(city.destination_id) ? ' checked' : '';
                    html += '<label style="display:inline-flex; align-items:center; gap:3px; min-width:160px; font-size:12px; color:#444; cursor:pointer;">' +
                        '<input type="checkbox" class="wl-child-cb wl-city-cb" value="' + city.destination_id + '" ' +
                        'data-country="' + countryId + '" data-region="' + regionId + '"' + cityChecked +
                        ' onchange="onChildToggle(this,' + countryId + ',' + city.destination_id + ')" />' +
                        '<span style="color:#666;">' + city.name + '</span></label>';
                });
                html += '</div>';
            }

            html += '</div>';
        });
        grid.innerHTML = html;
    }

    // ─── Toggle region cities visibility ───

    window.toggleRegionCities = function(countryId, regionId) {
        var el = document.getElementById('wl_cities_' + countryId + '_' + regionId);
        var arrow = document.getElementById('wl_arrow_' + countryId + '_' + regionId);
        if (!el) return;
        if (el.style.display === 'none') {
            el.style.display = 'flex';
            if (arrow) arrow.textContent = '\u2212';
        } else {
            el.style.display = 'none';
            if (arrow) arrow.textContent = '+';
        }
    };

    // ─── Region toggle: select/deselect all cities in region ───

    window.onRegionToggle = function(cb, countryId, regionId) {
        if (!state[countryId]) {
            state[countryId] = { type: 'specific', children: new Set() };
            document.querySelector('.wl-country-cb[value="' + countryId + '"]').checked = true;
        }

        var region = findRegionInTree(countryId, regionId);
        if (!region) return;

        if (cb.checked) {
            // Add region + all its cities
            state[countryId].children.add(regionId);
            region.children.forEach(function(city) {
                state[countryId].children.add(city.destination_id);
            });
            // Check all city checkboxes and auto-expand
            var cityCbs = document.querySelectorAll('.wl-city-cb[data-region="' + regionId + '"]');
            cityCbs.forEach(function(c) { c.checked = true; });
            var citiesDiv = document.getElementById('wl_cities_' + countryId + '_' + regionId);
            var arrow = document.getElementById('wl_arrow_' + countryId + '_' + regionId);
            if (citiesDiv) { citiesDiv.style.display = 'flex'; }
            if (arrow) { arrow.textContent = '\u2212'; }
        } else {
            // Remove region + all its cities
            state[countryId].children.delete(regionId);
            region.children.forEach(function(city) {
                state[countryId].children.delete(city.destination_id);
            });
            state[countryId].type = 'specific';
            var selectAllCb = document.querySelector('.wl-select-all-children[data-country="' + countryId + '"]');
            if (selectAllCb) selectAllCb.checked = false;
            // Uncheck all city checkboxes
            var cityCbs = document.querySelectorAll('.wl-city-cb[data-region="' + regionId + '"]');
            cityCbs.forEach(function(c) { c.checked = false; });
        }

        checkAllChildrenSelected(countryId);
        updateBadge(countryId);
        updateSummary();
    };

    function findRegionInTree(countryId, regionId) {
        var tree = loadedTree[countryId] || [];
        for (var i = 0; i < tree.length; i++) {
            if (tree[i].destination_id === regionId) return tree[i];
        }
        return null;
    }

    function updateRegionCheckboxState(countryId, regionId) {
        var region = findRegionInTree(countryId, regionId);
        if (!region || region.children.length === 0) return;
        var selectedChildren = state[countryId] ? state[countryId].children : new Set();
        var allSelected = region.children.every(function(c) {
            return selectedChildren.has(c.destination_id);
        });
        var regionCb = document.querySelector('.wl-region-cb[data-region-id="' + regionId + '"]');
        if (regionCb) {
            regionCb.checked = allSelected;
            if (allSelected) {
                state[countryId].children.add(regionId);
            }
        }
    }

    // ─── Country toggle ───

    window.onCountryToggle = function(cb, countryId) {
        if (cb.checked) {
            state[countryId] = { type: 'all', children: new Set() };
            var selectAllCb = document.querySelector('.wl-select-all-children[data-country="' + countryId + '"]');
            if (selectAllCb) selectAllCb.checked = true;
            if (loadedTree[countryId]) {
                var childCbs = document.querySelectorAll('.wl-child-cb[data-country="' + countryId + '"]');
                childCbs.forEach(function(c) { c.checked = true; });
            }
        } else {
            delete state[countryId];
            var selectAllCb = document.querySelector('.wl-select-all-children[data-country="' + countryId + '"]');
            if (selectAllCb) selectAllCb.checked = false;
            var childCbs = document.querySelectorAll('.wl-child-cb[data-country="' + countryId + '"]');
            childCbs.forEach(function(c) { c.checked = false; });
        }
        updateBadge(countryId);
        updateSummary();
        applyWhitelistFilter();
    };

    window.onSelectAllChildren = function(cb, countryId) {
        var countryCb = document.querySelector('.wl-country-cb[value="' + countryId + '"]');
        var grid = document.getElementById('wl_grid_' + countryId);
        if (cb.checked) {
            countryCb.checked = true;
            state[countryId] = { type: 'all', children: new Set() };
            var childCbs = document.querySelectorAll('.wl-child-cb[data-country="' + countryId + '"]');
            childCbs.forEach(function(c) { c.checked = true; });
            // Expand all region cities
            if (grid) {
                grid.querySelectorAll('[id^="wl_cities_' + countryId + '_"]').forEach(function(d) { d.style.display = 'flex'; });
                grid.querySelectorAll('[id^="wl_arrow_' + countryId + '_"]').forEach(function(a) { a.textContent = '\u2212'; });
            }
        } else {
            state[countryId] = { type: 'specific', children: new Set() };
            var childCbs = document.querySelectorAll('.wl-child-cb[data-country="' + countryId + '"]');
            childCbs.forEach(function(c) { c.checked = false; });
            // Collapse all region cities
            if (grid) {
                grid.querySelectorAll('[id^="wl_cities_' + countryId + '_"]').forEach(function(d) { d.style.display = 'none'; });
                grid.querySelectorAll('[id^="wl_arrow_' + countryId + '_"]').forEach(function(a) { a.textContent = '+'; });
            }
        }
        updateBadge(countryId);
        updateSummary();
    };

    // ─── City toggle ───

    window.onChildToggle = function(cb, countryId, childId) {
        if (!state[countryId]) {
            state[countryId] = { type: 'specific', children: new Set() };
            document.querySelector('.wl-country-cb[value="' + countryId + '"]').checked = true;
        }

        if (cb.checked) {
            state[countryId].children.add(childId);
        } else {
            state[countryId].children.delete(childId);
            state[countryId].type = 'specific';
            var selectAllCb = document.querySelector('.wl-select-all-children[data-country="' + countryId + '"]');
            if (selectAllCb) selectAllCb.checked = false;
        }

        // Update region checkbox state based on whether all its cities are checked
        var regionAttr = cb.getAttribute('data-region');
        if (regionAttr) {
            updateRegionCheckboxState(countryId, parseInt(regionAttr));
        }

        checkAllChildrenSelected(countryId);

        // If no children selected, uncheck country
        if (state[countryId].type === 'specific' && state[countryId].children.size === 0) {
            delete state[countryId];
            document.querySelector('.wl-country-cb[value="' + countryId + '"]').checked = false;
        }

        updateBadge(countryId);
        updateSummary();
    };

    function checkAllChildrenSelected(countryId) {
        var flat = loadedFlat[countryId] || [];
        if (flat.length > 0 && state[countryId] && state[countryId].type === 'specific') {
            if (state[countryId].children.size >= flat.length) {
                state[countryId].type = 'all';
                state[countryId].children = new Set();
                var selectAllCb = document.querySelector('.wl-select-all-children[data-country="' + countryId + '"]');
                if (selectAllCb) selectAllCb.checked = true;
            }
        }
    }

    // ─── Badge & Summary ───

    function updateBadge(countryId) {
        var badge = document.getElementById('wl_badge_' + countryId);
        if (!state[countryId]) {
            badge.innerHTML = '';
            return;
        }
        if (state[countryId].type === 'all') {
            badge.innerHTML = '<span style="background:#28a745; color:#fff; padding:2px 8px; border-radius:3px; font-size:11px;">ALL CITIES INCLUDED</span>';
        } else {
            var count = state[countryId].children.size;
            badge.innerHTML = '<span style="background:#e67e22; color:#fff; padding:2px 8px; border-radius:3px; font-size:11px;">' + count + ' SELECTED</span>';
        }
    }

    function updateSummary() {
        var countryCount = Object.keys(state).length;
        var totalRegions = 0;
        var countryNames = [];
        var detailHtml = '';

        for (var cid in state) {
            var countryEl = document.querySelector('[data-country-id="' + cid + '"]');
            var countryName = '?';
            if (countryEl) {
                var label = countryEl.querySelector('.wl-country-cb');
                countryName = label ? label.parentNode.textContent.trim() : cid;
            }
            countryNames.push(countryName);

            var tree = loadedTree[cid] || [];
            var isAll = state[cid].type === 'all';
            var selectedChildren = state[cid].children;
            var fullRegions = 0;
            var partialRegions = 0;
            var cityCount = 0;

            if (tree.length > 0) {
                tree.forEach(function(region) {
                    if (isAll) {
                        fullRegions++;
                        cityCount += region.children.length;
                    } else {
                        var selectedInRegion = 0;
                        region.children.forEach(function(city) {
                            if (selectedChildren.has(city.destination_id)) {
                                selectedInRegion++;
                                cityCount++;
                            }
                        });
                        if (selectedInRegion > 0) {
                            if (selectedInRegion >= region.children.length) {
                                fullRegions++;
                            } else {
                                partialRegions++;
                            }
                        }
                    }
                });
                totalRegions += fullRegions + partialRegions;
            } else {
                // Tree not loaded yet — show basic info
                if (isAll) {
                    totalRegions += (loadedFlat[cid] || []).length;
                } else {
                    totalRegions += selectedChildren.size;
                }
            }

            detailHtml += '<div style="margin:6px 0; padding:6px 0; border-bottom:1px solid #eee; font-size:12px;">' +
                '<strong>' + escapeHtml(countryName) + '</strong><br/>';

            if (tree.length > 0) {
                detailHtml += '<span style="color:#888;">regions: ';
                if (fullRegions > 0) detailHtml += '<span style="color:#28a745; font-weight:600;">' + fullRegions + ' (full)</span>';
                if (fullRegions > 0 && partialRegions > 0) detailHtml += ' ';
                if (partialRegions > 0) detailHtml += '<span style="color:#e67e22; font-weight:600;">' + partialRegions + ' (partial)</span>';
                if (fullRegions === 0 && partialRegions === 0) detailHtml += '0';
                detailHtml += '</span><br/>';
                detailHtml += '<span style="color:#888;">cities: ' + cityCount + '</span>';
            } else {
                // Tree not loaded — show simplified
                var count = isAll ? 'all' : selectedChildren.size;
                detailHtml += '<span style="color:#888;">regions/cities: ' + count + '</span>';
            }

            detailHtml += '</div>';
        }

        // Countries header line
        var headerHtml = '';
        if (countryNames.length > 0) {
            headerHtml = '<div style="margin-bottom:6px; font-size:12px; color:#555;"><strong>Countries:</strong> ' +
                countryNames.map(function(n) { return escapeHtml(n); }).join(', ') + '</div>';
        }

        document.getElementById('wl_summary_countries').textContent = countryCount;
        document.getElementById('wl_summary_regions').textContent = totalRegions;
        document.getElementById('wl_summary_country_names').innerHTML = headerHtml + detailHtml;
    }

    // ─── Submit ───

    window.submitWhitelist = function() {
        var form = document.getElementById('whitelist_form');
        var container = document.getElementById('wl_hidden_inputs');
        // Send as single JSON string to avoid PHP max_input_vars limit
        // (selecting a region with 500+ cities would create 1000+ POST variables)
        var entries = [];
        for (var countryId in state) {
            entries.push({ destination_id: parseInt(countryId), selection_type: state[countryId].type });
            if (state[countryId].type === 'specific') {
                state[countryId].children.forEach(function(childId) {
                    entries.push({ destination_id: parseInt(childId), selection_type: 'specific' });
                });
            }
        }
        container.innerHTML = '<input type="hidden" name="whitelist_json" value="" />';
        container.querySelector('input').value = JSON.stringify(entries);
        form.submit();
    };

    // ─── Remove All Whitelist ───

    window.removeAllWhitelist = function() {
        if (!confirm('Remove all whitelisted destinations? You must click Save to persist.')) return;

        document.querySelectorAll('.wl-country-cb').forEach(function(cb) { cb.checked = false; });
        document.querySelectorAll('.wl-select-all-children').forEach(function(cb) { cb.checked = false; });
        document.querySelectorAll('.wl-child-cb').forEach(function(cb) { cb.checked = false; });

        state = {};

        document.querySelectorAll('[id^="wl_badge_"]').forEach(function(el) { el.innerHTML = ''; });

        updateSummary();
        applyWhitelistFilter();
    };

    // ─── Search: countries + regions/cities via AJAX ───

    var searchInput = document.getElementById('wl_search');
    var searchResultsEl = document.getElementById('wl_search_results');

    if (searchInput) {
        searchInput.addEventListener('input', function() {
            var q = this.value.toLowerCase().trim();

            // Filter countries (respects both search and whitelisted-only toggle)
            applyWhitelistFilter();

            // AJAX search for regions/cities (debounced)
            clearTimeout(searchTimeout);
            if (q.length < 2) {
                searchResultsEl.style.display = 'none';
                searchResultsEl.innerHTML = '';
                return;
            }

            searchTimeout = setTimeout(function() {
                var xhr = new XMLHttpRequest();
                xhr.open('GET', searchUrl + '&q=' + encodeURIComponent(q));
                xhr.onload = function() {
                    if (xhr.status === 200) {
                        var data = JSON.parse(xhr.responseText);
                        var results = (data.results || []).filter(function(r) {
                            return r.type === 'region' || r.type === 'city' || r.type === 'destination';
                        });
                        renderSearchResults(results, q);
                    }
                };
                xhr.send();
            }, 300);
        });

        // Close search results when clicking outside
        document.addEventListener('click', function(e) {
            if (!searchInput.contains(e.target) && !searchResultsEl.contains(e.target)) {
                searchResultsEl.style.display = 'none';
            }
        });
    }

    function renderSearchResults(results, query) {
        if (results.length === 0) {
            searchResultsEl.innerHTML = '<div style="padding:12px; color:#999; font-size:13px;">No regions or cities found for "' + escapeHtml(query) + '"</div>';
            searchResultsEl.style.display = 'block';
            return;
        }

        // Group by country
        var grouped = {};
        results.forEach(function(r) {
            var cc = r.country_code || '??';
            if (!grouped[cc]) grouped[cc] = { regions: [], cities: [] };
            if (r.type === 'region') {
                grouped[cc].regions.push(r);
            } else {
                grouped[cc].cities.push(r);
            }
        });

        var html = '';
        for (var cc in grouped) {
            var group = grouped[cc];
            // Find country name from the page
            var countryEl = document.querySelector('[data-cc="' + cc + '"]');
            var countryName = countryEl ? countryEl.dataset.name : cc;
            countryName = countryName.charAt(0).toUpperCase() + countryName.slice(1);
            var countryId = countryEl ? parseInt(countryEl.dataset.countryId) : 0;

            html += '<div style="padding:4px 12px 2px; background:#f0f0f0; font-weight:600; font-size:12px; color:#555; border-top:1px solid #eee;">' +
                countryName + ' (' + cc + ')</div>';

            // Regions
            group.regions.forEach(function(r) {
                html += '<div class="wl-search-item" style="display:flex; align-items:center; gap:8px; padding:6px 12px 6px 20px; cursor:pointer; border-bottom:1px solid #f5f5f5;" ' +
                    'onmouseover="this.style.background=\'#f0f7ff\'" onmouseout="this.style.background=\'\'" ' +
                    'onclick="onSearchResultClick(' + countryId + ',' + r.destination_id + ',\'region\')">' +
                    '<span style="font-size:10px; padding:1px 6px; background:#2196F3; color:#fff; border-radius:3px; text-transform:uppercase; flex-shrink:0;">Region</span>' +
                    '<span style="font-weight:600; font-size:13px;">' + highlightMatch(r.name, query) + '</span>' +
                    '<span style="font-size:11px; color:#999; margin-left:auto;">' + (r.full_path || '') + '</span>' +
                    '</div>';
            });

            // Cities
            group.cities.forEach(function(r) {
                html += '<div class="wl-search-item" style="display:flex; align-items:center; gap:8px; padding:5px 12px 5px 28px; cursor:pointer; border-bottom:1px solid #f5f5f5;" ' +
                    'onmouseover="this.style.background=\'#f0f7ff\'" onmouseout="this.style.background=\'\'" ' +
                    'onclick="onSearchResultClick(' + countryId + ',' + r.destination_id + ',\'city\')">' +
                    '<span style="font-size:10px; padding:1px 6px; background:#8BC34A; color:#fff; border-radius:3px; text-transform:uppercase; flex-shrink:0;">City</span>' +
                    '<span style="font-size:13px; color:#444;">' + highlightMatch(r.name, query) + '</span>' +
                    '<span style="font-size:11px; color:#999; margin-left:auto;">' + (r.full_path || '') + '</span>' +
                    '</div>';
            });
        }

        searchResultsEl.innerHTML = html;
        searchResultsEl.style.display = 'block';
    }

    function highlightMatch(text, query) {
        var idx = text.toLowerCase().indexOf(query.toLowerCase());
        if (idx === -1) return escapeHtml(text);
        return escapeHtml(text.substring(0, idx)) + '<strong>' + escapeHtml(text.substring(idx, idx + query.length)) + '</strong>' + escapeHtml(text.substring(idx + query.length));
    }

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(text));
        return div.innerHTML;
    }

    // ─── Search result click: expand country, load tree, select item ───

    window.onSearchResultClick = function(countryId, destId, type) {
        if (!countryId) return;
        searchResultsEl.style.display = 'none';

        // Show the country in the list
        var countryEl = document.querySelector('[data-country-id="' + countryId + '"]');
        if (countryEl) countryEl.style.display = '';

        // Expand country if not already
        var childrenEl = document.getElementById('wl_children_' + countryId);
        if (childrenEl && childrenEl.style.display === 'none') {
            toggleCountryExpand(countryId);
        }

        // After tree loads, select the item
        function doSelect() {
            if (!state[countryId]) {
                state[countryId] = { type: 'specific', children: new Set() };
                document.querySelector('.wl-country-cb[value="' + countryId + '"]').checked = true;
            }

            if (type === 'region') {
                // Select region + all its cities
                var region = findRegionInTree(countryId, destId);
                if (region) {
                    state[countryId].children.add(destId);
                    region.children.forEach(function(c) {
                        state[countryId].children.add(c.destination_id);
                    });
                }
            } else {
                state[countryId].children.add(destId);
            }

            checkAllChildrenSelected(countryId);
            renderChildren(countryId);
            updateBadge(countryId);
            updateSummary();

            // Scroll to the country
            if (countryEl) countryEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        // Wait for tree to load if needed
        if (loadedTree[countryId]) {
            doSelect();
        } else {
            var checkInterval = setInterval(function() {
                if (loadedTree[countryId]) {
                    clearInterval(checkInterval);
                    doSelect();
                }
            }, 100);
            // Safety timeout
            setTimeout(function() { clearInterval(checkInterval); }, 5000);
        }
    };

    {/literal}

    // ─── Load whitelisted children state from server (ONE batch call) ───
    // Previously this fired TWO XHRs per whitelisted country on load (a full
    // destination-tree preload + a children fetch), each backed by multiple
    // queries — hundreds of round-trips at real whitelist sizes. The tree
    // still loads lazily when a country is expanded; the whitelisted-children
    // state for badges/summary/save comes from a single batch endpoint.
    {if $whitelist_map}
    {literal}
    (function() {
        var xhr = new XMLHttpRequest();
        xhr.open('GET', childrenUrl);
        xhr.onload = function() {
            if (xhr.status === 200) {
                var data = JSON.parse(xhr.responseText);
                var map = data.children_by_country || {};
                for (var cid in map) {
                    var cidNum = parseInt(cid);
                    if (state[cidNum] && state[cidNum].type === 'specific') {
                        state[cidNum].children = new Set(map[cid].map(Number));
                        updateBadge(cidNum);
                    }
                }
                updateSummary();
            }
        };
        xhr.send();
    })();
    {/literal}
    {/if}

    {literal}
    // Initial summary + first paginated render of the country list.
    updateSummary();
    applyWhitelistFilter();
    {/literal}
})();
</script>

{/capture}

{include file="common/mainbox.tpl"
    title=__("sphinx_holidays.destination_whitelist")
    content=$smarty.capture.mainbox
}
