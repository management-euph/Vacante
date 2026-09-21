{capture name="mainbox"}

<div class="travel-admin-panel">

    {* ── API status ── *}
    {if !$eurosite_is_configured}
        <div class="alert alert-warning">
            <i class="icon-warning-sign"></i>
            {__("eurosite.api_not_configured", ["[default]" => "Eurosite API credentials are not configured — enter them in the addon settings. All catalogs and searches will fail with error -1000 until then."])}
        </div>
    {/if}

    <div class="control-group">
        <h4>{__("eurosite.api_connection", ["[default]" => "API connection"])}</h4>
        <p>
            <code>{$eurosite_api_url}</code>
            &nbsp; {__("eurosite.api_user", ["[default]" => "API user"])}: <code>{$eurosite_api_user|escape:html}</code>
        </p>
        <form action="{""|fn_url}" method="post" style="display:inline;">
            <input type="hidden" name="dispatch" value="eurosite.test_connection" />
            <button type="submit" class="btn">
                <i class="icon-ok"></i> {__("eurosite.test_connection", ["[default]" => "Test API connection"])}
            </button>
        </form>
        <a href="{"addons.update&addon=eurosite"|fn_url}" class="btn btn-micro">
            <i class="icon-cog"></i> {__("eurosite.addon_settings", ["[default]" => "Addon settings"])}
        </a>
    </div>

    {* ── Catalog counts ── *}
    <h4>{__("eurosite.catalogs", ["[default]" => "Static-data catalogs"])}</h4>
    <table class="table table-middle" style="max-width: 720px;">
        <thead>
            <tr>
                <th>{__("eurosite.catalog", ["[default]" => "Catalog"])}</th>
                <th>{__("eurosite.rows", ["[default]" => "Rows"])}</th>
                <th>{__("eurosite.last_sync", ["[default]" => "Last sync"])}</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            {foreach from=$eurosite_catalog_rows item=row}
                <tr>
                    <td><code>{$row.key}</code></td>
                    <td>{$row.count}</td>
                    <td>
                        {if $row.last}
                            <span class="label {if $row.last.status == 'completed'}label-success{elseif $row.last.status == 'failed'}label-important{else}label-warning{/if}">{$row.last.status}</span>
                            {$row.last.synced}/{$row.last.total} &middot; {$row.last.started_at} ({$row.last.duration_s}s)
                            {if $row.last.error}<div class="text-error">{$row.last.error|escape:html|truncate:120}</div>{/if}
                        {else}
                            <span class="muted">{__("eurosite.never_synced", ["[default]" => "never"])}</span>
                        {/if}
                    </td>
                    <td>
                        {if $row.syncable}
                            <form action="{""|fn_url}" method="post" style="display:inline;">
                                <input type="hidden" name="dispatch" value="eurosite.run_sync" />
                                <input type="hidden" name="sync_type" value="{$row.key}" />
                                <button type="submit" class="btn btn-micro" {if !$eurosite_is_configured}disabled{/if}>
                                    <i class="icon-refresh"></i> {__("eurosite.sync_now", ["[default]" => "Sync now"])}
                                </button>
                            </form>
                        {/if}
                    </td>
                </tr>
            {/foreach}
        </tbody>
    </table>

    <form action="{""|fn_url}" method="post" style="display:inline;">
        <input type="hidden" name="dispatch" value="eurosite.run_sync" />
        <input type="hidden" name="sync_type" value="full" />
        <button type="submit" class="btn btn-primary" {if !$eurosite_is_configured}disabled{/if}
                onclick="return confirm('{__("eurosite.sync_full_confirm", ["[default]" => "Run the full static-data sync now? This makes many API calls."])|escape:javascript}');">
            <i class="icon-refresh"></i> {__("eurosite.sync_full", ["[default]" => "Run full sync"])}
        </button>
    </form>
    <a href="{"eurosite.whitelist"|fn_url}" class="btn">
        <i class="icon-map-marker"></i> {__("eurosite.destination_whitelist", ["[default]" => "Destination whitelist"])}
        ({$eurosite_counts.whitelist})
    </a>
    <form action="{""|fn_url}" method="post" style="display:inline;">
        <input type="hidden" name="dispatch" value="eurosite.seed_menu" />
        <button type="submit" class="btn">
            <i class="icon-list"></i> {__("eurosite.seed_menu", ["[default]" => "Seed storefront menu"])}
        </button>
    </form>

    {* ── Recent bookings ── *}
    <h4 style="margin-top: 20px;">{__("eurosite.recent_bookings", ["[default]" => "Recent bookings"])} ({$eurosite_counts.bookings})</h4>
    {if $eurosite_recent_bookings}
        <table class="table table-middle" style="max-width: 900px;">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>{__("eurosite.hotel", ["[default]" => "Hotel"])}</th>
                    <th>{__("eurosite.check_in", ["[default]" => "Check-in"])}</th>
                    <th>{__("eurosite.total", ["[default]" => "Total"])}</th>
                    <th>{__("eurosite.status", ["[default]" => "Status"])}</th>
                    <th>{__("eurosite.order", ["[default]" => "Order"])}</th>
                    <th>{__("eurosite.created", ["[default]" => "Created"])}</th>
                </tr>
            </thead>
            <tbody>
                {foreach from=$eurosite_recent_bookings item=b}
                    <tr>
                        <td><a href="{"travel_bookings.manage&provider=eurosite"|fn_url}">#{$b.booking_id}</a></td>
                        <td>{$b.hotel_name|escape:html}</td>
                        <td>{$b.check_in}</td>
                        <td>{$b.total}</td>
                        <td><span class="label {if $b.status == 'confirmed'}label-success{elseif $b.status == 'cancelled' || $b.status == 'failed'}label-important{else}label-warning{/if}">{$b.status}</span></td>
                        <td>{if $b.order_id}<a href="{"orders.details&order_id=`$b.order_id`"|fn_url}">#{$b.order_id}</a>{else}&mdash;{/if}</td>
                        <td>{$b.created_at}</td>
                    </tr>
                {/foreach}
            </tbody>
        </table>
        <a href="{"travel_bookings.manage&provider=eurosite"|fn_url}" class="btn btn-micro">
            {__("eurosite.all_bookings", ["[default]" => "All Eurosite bookings"])}
        </a>
    {else}
        <p class="muted">{__("eurosite.no_bookings", ["[default]" => "No bookings yet."])}</p>
    {/if}

    {* ── Cron commands (sphinx dashboard layout) ── *}
    <h4 style="margin-top: 20px;">{__("eurosite.cron_commands", ["[default]" => "Cron commands"])}</h4>
    <p class="muted">
        {__("eurosite.cron_hint", ["[default]" => "Schedule these from the server crontab / cPanel. CLI equivalent:"])}
        <code id="eurosite-cron-cli">{$eurosite_cron_cli}</code>
        <button type="button" class="btn btn-micro eurosite-copy"
                data-copy="{$eurosite_cron_cli|escape:html}"
                data-txt-copied="{__("eurosite.copied", ["[default]" => "Copied"])|escape:html}"
                data-txt-copy-failed="{__("eurosite.copy_failed", ["[default]" => "Copy failed"])|escape:html}">
            <i class="icon-file"></i> {__("eurosite.copy", ["[default]" => "Copy"])}
        </button>
    </p>
    <table class="table table-middle" style="max-width: 1100px;">
        <thead>
            <tr>
                <th style="width: 120px;">{__("eurosite.cron_col_mode", ["[default]" => "Mode"])}</th>
                <th>{__("eurosite.cron_col_description", ["[default]" => "What it does"])}</th>
                <th style="width: 110px;">{__("eurosite.cron_col_schedule", ["[default]" => "Schedule"])}</th>
                <th>{__("eurosite.cron_col_url", ["[default]" => "URL"])}</th>
                <th style="width: 190px;">{__("eurosite.cron_col_actions", ["[default]" => "Actions"])}</th>
            </tr>
        </thead>
        <tbody>
            {foreach from=$eurosite_cron_rows item=cron}
                <tr>
                    <td><code>{$cron.mode|escape:html}</code></td>
                    <td class="muted">{$cron.description|escape:html}</td>
                    <td><code>{$cron.schedule|escape:html}</code></td>
                    <td style="word-break: break-all; font-size: 11px; overflow: hidden;"><code>{$cron.url|escape:html}</code></td>
                    <td>
                        <a href="{$cron.url}" target="_blank" rel="noopener" class="btn btn-micro">
                            <i class="icon-play"></i> {__("eurosite.run", ["[default]" => "Run"])}
                        </a>
                        <button type="button" class="btn btn-micro eurosite-copy"
                                data-copy="{$cron.url|escape:html}"
                                data-txt-copied="{__("eurosite.copied", ["[default]" => "Copied"])|escape:html}"
                                data-txt-copy-failed="{__("eurosite.copy_failed", ["[default]" => "Copy failed"])|escape:html}">
                            <i class="icon-file"></i> {__("eurosite.copy_url", ["[default]" => "Copy URL"])}
                        </button>
                        <button type="button" class="btn btn-micro eurosite-copy"
                                data-copy="{$cron.cli|escape:html}"
                                data-txt-copied="{__("eurosite.copied", ["[default]" => "Copied"])|escape:html}"
                                data-txt-copy-failed="{__("eurosite.copy_failed", ["[default]" => "Copy failed"])|escape:html}">
                            <i class="icon-file"></i> {__("eurosite.copy_cli", ["[default]" => "Copy CLI"])}
                        </button>
                    </td>
                </tr>
            {/foreach}
        </tbody>
    </table>
    <p class="muted" style="font-size: 11px;">
        {__("eurosite.cron_access_key_note", ["[default]" => "The access key in these URLs is the addon's cron key — treat them as secrets."])}
    </p>

</div>

{* Inside the capture on purpose: this page is reached through the admin
   top-nav (an AJAX navigation) whose response carries only the captured
   mainbox, so a {script src=} placed outside it is dropped and the copy
   buttons never wire up. *}
{script src="js/addons/eurosite/dashboard.js"}

{/capture}

{capture name="buttons"}{/capture}

{include file="common/mainbox.tpl" title=__("eurosite.dashboard_title", ["[default]" => "Eurosite Touring"]) content=$smarty.capture.mainbox buttons=$smarty.capture.buttons}
