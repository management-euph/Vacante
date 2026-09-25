{capture name="mainbox"}

<div class="travel-admin-panel" id="eurosite-whitelist">

    {if !$eurosite_countries_synced}
        <div class="alert alert-warning">
            <i class="icon-warning-sign"></i>
            {__("eurosite.whitelist_needs_countries", ["[default]" => "The country catalog has not been synced yet — run the 'countries' sync from the dashboard first. City lists fall back to live API calls."])}
        </div>
    {elseif $eurosite_country_names_missing}
        {* The catalog holds codes but no names: the rows render as bare "TT" /
           "VC" and the search box (which matches on name) finds nothing. The
           controller already tried to refill it from the API on this request. *}
        <div class="alert alert-warning">
            <i class="icon-warning-sign"></i>
            {__("eurosite.whitelist_names_missing", ["[default]" => "The country catalog has codes but no names. Run the 'countries' sync to refill it."])}
            {if $eurosite_country_heal_error}
                <br/><span class="muted">{$eurosite_country_heal_error|escape:html}</span>
            {/if}
        </div>
    {/if}

    {* ── Stats bar (sphinx whitelist look) ── *}
    <div class="sync-stats" style="margin-bottom: 20px; display: flex; gap: 12px;">
        <div class="stat-card" style="border: 1px solid #ddd; border-radius: 6px; padding: 10px 18px; text-align: center;">
            <div class="stat-value" style="font-size: 20px; font-weight: bold;">{$eurosite_wl_country_count}</div>
            <div class="stat-label" style="font-size: 11px; color: #888;">{__("eurosite.countries", ["[default]" => "Countries"])}</div>
        </div>
        <div class="stat-card" style="border: 1px solid #ddd; border-radius: 6px; padding: 10px 18px; text-align: center;">
            <div class="stat-value" style="font-size: 20px; font-weight: bold;">{$eurosite_wl_city_count}</div>
            <div class="stat-label" style="font-size: 11px; color: #888;">{__("eurosite.cities", ["[default]" => "Cities"])}</div>
        </div>
        <div class="stat-card" style="border: 1px solid #ddd; border-radius: 6px; padding: 10px 18px; text-align: center;">
            <div class="stat-value" style="font-size: 20px; font-weight: bold;">{$eurosite_wl_own_city_count}</div>
            <div class="stat-label" style="font-size: 11px; color: #888;">{__("eurosite.own_offer_cities", ["[default]" => "Own-offer cities"])}</div>
        </div>
    </div>

    <p class="muted" style="margin-bottom: 15px;">
        {__("eurosite.whitelist_hint", ["[default]" => "Only whitelisted destinations are synced and searchable on the storefront. Tick a country to include all of its cities, or expand it and pick specific cities."])}
    </p>

    <div style="margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
        <form action="{""|fn_url}" method="post" style="display:inline; margin:0;">
            <input type="hidden" name="dispatch" value="eurosite.run_sync" />
            <input type="hidden" name="sync_type" value="countries" />
            <input type="hidden" name="return_to" value="whitelist" />
            <button type="submit" class="btn btn-micro">
                <i class="icon-refresh"></i> {__("eurosite.sync_countries", ["[default]" => "Sync countries"])}
            </button>
        </form>
        {if $eurosite_countries_last_synced}
            <span class="muted" style="font-size: 11px;">
                {__("eurosite.last_synced", ["[default]" => "Last synced"])}: {$eurosite_countries_last_synced|escape:html}
            </span>
        {/if}
    </div>

    {* All values the page script needs ride as data attributes; the script
       itself is a real JS file (js/addons/eurosite/whitelist.js) — inline
       scripts go through the backend's inline_script/CSP rewriter and
       Smarty-parse their braces, both of which have broken this page. *}
    <div id="eurosite-whitelist-data"
         data-whitelist="{$eurosite_whitelist_json|escape:html}"
         data-cities-url="{"eurosite.get_cities"|fn_url}"
         data-search-url="{"eurosite.search_destinations"|fn_url}"
         data-txt-loading="{__("loading", ["[default]" => "Loading..."])|escape:html}"
         data-txt-no-cities="{__("eurosite.no_cities", ["[default]" => "No cities found."])|escape:html}"
         data-txt-hotels="{__("eurosite.hotels_word", ["[default]" => "hotels"])|escape:html}"
         data-txt-failed="{__("eurosite.request_failed", ["[default]" => "Request failed."])|escape:html}"
         data-txt-all-badge="{__("eurosite.all_cities_included", ["[default]" => "ALL CITIES"])|escape:html}"
         data-txt-selected="{__("eurosite.selected", ["[default]" => "selected"])|escape:html}"
         data-txt-shown="{__("eurosite.shown", ["[default]" => "shown"])|escape:html}"
         data-txt-confirm-remove="{__("eurosite.confirm_remove_all", ["[default]" => "Remove all whitelisted destinations? Click Save to persist."])|escape:html}"
         data-txt-no-results="{__("eurosite.no_search_results", ["[default]" => "No matches."])|escape:html}"
         data-txt-countries="{__("eurosite.countries", ["[default]" => "Countries"])|escape:html}"
         data-txt-cities="{__("eurosite.cities", ["[default]" => "Cities"])|escape:html}"></div>

    <div style="display: flex; gap: 30px; align-items: flex-start;">

        {* ── Left: country list ── *}
        <div style="flex: 1; min-width: 0;">

            {* Search over countries + cities. A plain input with its own
               dropdown (the sphinx whitelist pattern) — no select2, so the page
               does not depend on a library the kit may not ship. *}
            <div style="margin-bottom: 12px; position: relative;">
                <input type="text" id="eurosite-wl-search" class="input-large" style="width: 100%;"
                       autocomplete="off"
                       placeholder="{__("eurosite.search_destinations", ["[default]" => "Search country or city..."])|escape:html}" />
                <div id="eurosite-wl-search-results"
                     style="display:none; position:absolute; z-index:100; left:0; right:0; top:100%;
                            background:#fff; border:1px solid #ddd; border-top:0; max-height:320px;
                            overflow-y:auto; box-shadow:0 4px 10px rgba(0,0,0,.08);"></div>
            </div>

            {* Whitelisted-only filter *}
            <div style="margin-bottom: 12px; display: flex; align-items: center; gap: 10px;">
                <label style="display: inline-flex; align-items: center; gap: 6px; cursor: pointer; font-size: 13px; color: #555; user-select: none;">
                    <input type="checkbox" id="eurosite-wl-filter" />
                    <span>{__("eurosite.show_whitelisted_only", ["[default]" => "Show only whitelisted"])}</span>
                </label>
                {* Own-offer destinations: the only ones where the hotels sync finds
                   hotels, so the ones worth whitelisting. *}
                <label style="display: inline-flex; align-items: center; gap: 6px; cursor: pointer; font-size: 13px; color: #555; user-select: none;">
                    <input type="checkbox" id="eurosite-wl-own-filter" />
                    <span>{__("eurosite.show_own_only", ["[default]" => "Show only destinations with own hotels"])}</span>
                </label>
                <span id="eurosite-wl-filter-count" style="font-size: 11px; color: #888;"></span>
            </div>

            <form action="{""|fn_url}" method="post" id="eurosite-whitelist-form">
                <input type="hidden" name="dispatch" value="eurosite.save_whitelist" />
                <input type="hidden" name="security_hash" value="{$security_hash}" />
                <input type="hidden" name="whitelist_json" id="eurosite-whitelist-json" value="" />

                <div id="eurosite-country-list">
                    {foreach from=$eurosite_countries item=country}
                        {assign var="cc" value=$country.country_code}
                        {assign var="wl" value=$eurosite_whitelist_map.$cc}
                        {assign var="own_n" value=$country.own_cities}
                        <div class="eurosite-country-row" id="eurosite-wl-row-{$cc}" data-country="{$cc}" data-own="{$own_n}" style="border-bottom: 1px solid #eee;">
                            <div style="display: flex; align-items: center; gap: 8px; padding: 6px 0;">
                                <span class="eurosite-expand" data-country="{$cc}" style="cursor: pointer; width: 20px; text-align: center; color: #999; user-select: none;">&#9654;</span>
                                <input type="checkbox" class="eurosite-country-all" data-country="{$cc}"
                                       {if $wl && ($wl.all || $wl.cities)}checked{/if} />
                                <span class="eurosite-country-name" data-country="{$cc}"
                                      style="font-weight: bold; cursor: pointer;">{$country.name|default:$cc|escape:html}</span>
                                <code>{$cc}</code>
                                {if $own_n}<span class="label label-info" title="{__("eurosite.own_offer_cities", ["[default]" => "Own-offer cities"])|escape:html}">{__("eurosite.own_n", ["[n]" => $own_n, "[default]" => "[n] own"])}</span>{/if}
                                <span class="eurosite-wl-badge" data-country="{$cc}" style="font-size: 11px;">
                                    {if $wl && $wl.all}
                                        <span style="background: #28a745; color: #fff; padding: 2px 8px; border-radius: 3px;">{__("eurosite.all_cities_included", ["[default]" => "ALL CITIES"])}</span>
                                    {elseif $wl && $wl.cities}
                                        <span style="background: #e67e22; color: #fff; padding: 2px 8px; border-radius: 3px;">{$wl.cities|count} {__("eurosite.selected", ["[default]" => "selected"])}</span>
                                    {/if}
                                </span>
                            </div>
                            <div class="eurosite-city-box" data-country="{$cc}" style="display: none; padding: 8px 0 8px 28px; background: #fafafa; border-top: 1px solid #eee;">
                                <div style="margin-bottom: 8px;">
                                    <label style="cursor: pointer; font-size: 12px; color: #555;">
                                        <input type="checkbox" class="eurosite-select-all" data-country="{$cc}"
                                               {if $wl && $wl.all}checked{/if} />
                                        <strong>{__("eurosite.select_all_cities", ["[default]" => "Select all cities"])}</strong>
                                    </label>
                                </div>
                                <div class="eurosite-city-grid" data-country="{$cc}" style="display: flex; flex-wrap: wrap; gap: 3px 14px;"></div>
                            </div>
                        </div>
                    {/foreach}
                </div>
            </form>
        </div>

        {* ── Right: summary panel ── *}
        <div style="width: 300px; flex-shrink: 0; border: 1px solid #ddd; border-radius: 6px; padding: 20px; background: #fafafa; position: sticky; top: 80px;">
            <h4 style="margin-top: 0;">{__("eurosite.whitelist_summary", ["[default]" => "Whitelist summary"])}</h4>

            <div style="margin-bottom: 8px;">
                <strong>{__("eurosite.whitelisted_countries", ["[default]" => "Whitelisted countries"])}:</strong>
                <span id="eurosite-wl-summary-countries">0</span>
            </div>
            <div style="margin-bottom: 8px;">
                <strong>{__("eurosite.whitelisted_cities", ["[default]" => "Whitelisted cities"])}:</strong>
                <span id="eurosite-wl-summary-cities">0</span>
            </div>

            <div id="eurosite-wl-summary-detail" style="margin-bottom: 12px; max-height: 300px; overflow-y: auto;"></div>

            <button type="button" class="btn btn-primary" id="eurosite-wl-save" style="width: 100%;">
                <i class="icon-ok"></i> {__("eurosite.save_whitelist", ["[default]" => "Save whitelist"])}
            </button>
            <button type="button" class="btn" id="eurosite-wl-remove-all" style="width: 100%; margin-top: 8px;">
                <i class="icon-trash"></i> {__("eurosite.remove_all", ["[default]" => "Remove all"])}
            </button>
            <a href="{"eurosite.manage"|fn_url}" class="btn" style="width: 100%; margin-top: 8px; box-sizing: border-box; text-align: center;">
                {__("eurosite.back_to_dashboard", ["[default]" => "Back to dashboard"])}
            </a>
        </div>

    </div>
</div>

{script src="js/addons/eurosite/whitelist.js"}

{/capture}

{capture name="buttons"}{/capture}

{include file="common/mainbox.tpl" title=__("eurosite.whitelist_title", ["[default]" => "Eurosite — Destination whitelist"]) content=$smarty.capture.mainbox buttons=$smarty.capture.buttons}
