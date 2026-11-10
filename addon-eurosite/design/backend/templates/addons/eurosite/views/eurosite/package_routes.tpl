{* Eurosite → Package routes: where the packages go, the cities they leave
   from and their departure dates (?:eurosite_package_routes, kept by the
   weekly package_routes job). Laid out as Sphinx's Package routes: the
   filters in the sidebar, the freshness line and Sync now, then the routes,
   paged and sortable. CS-Cart's own admin pieces only.

   Eurosite's routes give no length of stay: the Duration column shows the
   stays the store offers ("Packages: nights offered"). *}

{capture name="sidebar"}
<div class="sidebar-row">
    <h6>{__("search")}</h6>
    <form action="{""|fn_url}" method="get" name="es_routes_filter">
        <input type="hidden" name="dispatch" value="eurosite.package_routes" />

        <div class="sidebar-field">
            <label for="es_routes_transport">{__("eurosite.transport")}:</label>
            <select name="transport" id="es_routes_transport">
                <option value="">{__("eurosite.all_transports")}</option>
                <option value="plane" {if $search.transport == "plane"}selected{/if}>{__("eurosite.transport_plane")}</option>
                <option value="bus" {if $search.transport == "bus"}selected{/if}>{__("eurosite.transport_bus")}</option>
            </select>
        </div>

        <div class="sidebar-field">
            <label for="es_routes_departure">{__("eurosite.departure")}:</label>
            <input type="text" id="es_routes_departure" name="departure" value="{$search.departure|escape:html}" />
        </div>

        <div class="sidebar-field">
            <label for="es_routes_arrival">{__("eurosite.arrival")}:</label>
            <input type="text" id="es_routes_arrival" name="arrival" value="{$search.arrival|escape:html}" />
        </div>

        <div class="sidebar-field">
            <input type="submit" class="btn" value="{__("search")}" />
        </div>
    </form>
</div>
{/capture}

{capture name="mainbox"}

{* The freshness line, and Sync now: it runs the package_routes job and
   comes back here. *}
<div style="display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:10px; margin-bottom:10px;">
    <span class="muted">
        {if $eurosite_routes_last_synced}{__("eurosite.last_synced")}: {$eurosite_routes_last_synced} &middot; {/if}{__("eurosite.routes_count", ["[n]" => $eurosite_routes_total])}
    </span>
    <form action="{""|fn_url}" method="post">
        <input type="hidden" name="dispatch" value="eurosite.run_sync" />
        <input type="hidden" name="sync_type" value="package_routes" />
        <input type="hidden" name="return_to" value="package_routes" />
        <input type="hidden" name="security_hash" value="{$security_hash}" />
        <button type="submit" class="btn btn-primary"><i class="icon-refresh"></i> {__("eurosite.sync_now")}</button>
    </form>
</div>

{if $eurosite_package_routes}
    {include file="common/pagination.tpl" save_current_url=true}

    <div class="table-responsive-wrapper">
    <table class="table table-middle table-striped table-responsive">
        <thead>
            <tr>
                {foreach from=["transport" => "eurosite.transport", "departure" => "eurosite.departure", "arrival" => "eurosite.arrival"] key=column item=heading}
                    <th>
                        <a href="{"`$eurosite_routes_url`&sort_by=`$column`&sort_order=`$search.sort_order_rev`"|fn_url}">{__($heading)}{if $search.sort_by == $column} {if $search.sort_order == "asc"}&#9650;{else}&#9660;{/if}{/if}</a>
                    </th>
                {/foreach}
                <th>{__("eurosite.duration")}</th>
                <th>
                    <a href="{"`$eurosite_routes_url`&sort_by=dates&sort_order=`$search.sort_order_rev`"|fn_url}">{__("eurosite.available_dates")}{if $search.sort_by == "dates"} {if $search.sort_order == "asc"}&#9650;{else}&#9660;{/if}{/if}</a>
                </th>
                <th>
                    <a href="{"`$eurosite_routes_url`&sort_by=last_synced_at&sort_order=`$search.sort_order_rev`"|fn_url}">{__("eurosite.last_synced")}{if $search.sort_by == "last_synced_at"} {if $search.sort_order == "asc"}&#9650;{else}&#9660;{/if}{/if}</a>
                </th>
            </tr>
        </thead>
        <tbody>
            {foreach from=$eurosite_package_routes item=route}
                <tr>
                    <td data-th="{__("eurosite.transport")}">
                        {if $route.transport == "bus"}{__("eurosite.transport_bus")}{else}{__("eurosite.transport_plane")}{/if}
                    </td>
                    <td data-th="{__("eurosite.departure")}">
                        {$route.departure|default:$route.departure_code|escape:html}
                        <code style="font-size:11px;">{$route.departure_code|escape:html}</code>
                    </td>
                    <td data-th="{__("eurosite.arrival")}">
                        {$route.arrival|default:$route.arrival_code|escape:html}
                        <code style="font-size:11px;">{$route.arrival_code|escape:html}</code>
                        {if $route.arrival_place}<br><span class="muted">{$route.arrival_place|escape:html}</span>{/if}
                    </td>
                    <td data-th="{__("eurosite.duration")}">
                        {__("eurosite.routes_nights", ["[nights]" => $route.nights])}
                        <br><a class="muted" href="{"addons.update&addon=eurosite"|fn_url}">{__("eurosite.routes_nights_from_setting")}</a>
                    </td>
                    <td data-th="{__("eurosite.available_dates")}">
                        {if $route.dates}
                            {$route.dates|escape:html}
                            {if $route.more_dates} <span class="muted">{__("eurosite.routes_more_dates", ["[n]" => $route.more_dates])}</span>{/if}
                        {else}
                            <span class="muted">{__("eurosite.routes_no_dates_ahead")}</span>
                        {/if}
                    </td>
                    <td data-th="{__("eurosite.last_synced")}">{$route.last_synced_at|default:"-"|escape:html}</td>
                </tr>
            {/foreach}
        </tbody>
    </table>
    </div>

    {include file="common/pagination.tpl"}
{else}
    <p class="no-items">{if $search.transport || $search.departure || $search.arrival}{__("eurosite.no_routes_match")}{else}{__("eurosite.no_package_routes")}{/if}</p>
{/if}

{/capture}

{include file="common/mainbox.tpl"
    title=__("eurosite.package_routes_title")
    content=$smarty.capture.mainbox
    sidebar=$smarty.capture.sidebar
}
