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

    {* ── Catalogs & schedules: ONE row per job, in pipeline order ── *}
    <h4>{__("eurosite.catalogs_and_schedules", ["[default]" => "Catalogs & schedules"])}</h4>
    <p class="muted" style="font-size: 12px;">
        {__("eurosite.catalogs_hint", ["[default]" => "One row per job, in the order the full pipeline runs them. Times are server time."])}
    </p>
    <table class="table table-middle" style="max-width: 1100px;">
        <thead>
            <tr>
                <th>{__("eurosite.catalog", ["[default]" => "Catalog"])}</th>
                <th style="text-align: right;">{__("eurosite.rows", ["[default]" => "Rows"])}</th>
                <th>{__("eurosite.last_sync", ["[default]" => "Last sync"])}</th>
                <th>{__("eurosite.cron_col_schedule", ["[default]" => "Schedule"])}</th>
                <th style="width: 210px;">{__("eurosite.cron_col_actions", ["[default]" => "Actions"])}</th>
            </tr>
        </thead>
        <tbody>
            {foreach from=$eurosite_cron_rows item=job}
                <tr>
                    <td>
                        <code>{$job.mode|escape:html}</code>
                        <div class="muted" style="font-size: 11px;">{$job.description|escape:html}</div>
                        {if $job.mode == 'hotels' && $eurosite_counts.whitelist == 0}
                            {* The one state the page can diagnose on its own: this job
                               reads the whitelist, and the whitelist is empty. *}
                            <div style="font-size: 11px; color: #9a6700; margin-top: 3px;">
                                {__("eurosite.hotels_need_whitelist", ["[default]" => "Nothing to fetch: no destinations are whitelisted."])}
                                <a href="{"eurosite.whitelist"|fn_url}">{__("eurosite.open_whitelist", ["[default]" => "Open the whitelist"])}</a>
                            </div>
                        {/if}
                    </td>
                    <td style="text-align: right;">{if $job.count === null}&mdash;{else}{$job.count}{/if}</td>
                    <td>
                        {if $job.state == 'ok'}
                            <span class="label label-success">{$job.state_label|escape:html}</span>
                        {elseif $job.state == 'failed'}
                            <span class="label label-important">{$job.state_label|escape:html}</span>
                        {elseif $job.state == 'never'}
                            <span class="muted">{$job.state_label|escape:html}</span>
                        {else}
                            <span class="label label-warning">{$job.state_label|escape:html}</span>
                        {/if}
                        {if $job.last}
                            <div class="muted" style="font-size: 11px;">
                                {$job.last.synced}/{$job.last.total} &middot; {$job.last.started_at} ({$job.last.duration_s}s)
                            </div>
                            {if $job.last.error}<div class="text-error" style="font-size: 11px;">{$job.last.error|escape:html|truncate:120}</div>{/if}
                        {/if}
                    </td>
                    <td>
                        {$job.schedule_human|escape:html}
                        <div class="muted" style="font-size: 11px;"><code>{$job.schedule_cron|escape:html}</code></div>
                    </td>
                    <td style="white-space: nowrap;">
                        <form action="{""|fn_url}" method="post" style="display:inline; margin:0;">
                            <input type="hidden" name="dispatch" value="eurosite.run_sync" />
                            <input type="hidden" name="sync_type" value="{$job.mode}" />
                            <button type="submit" class="btn btn-micro" {if !$eurosite_is_configured}disabled{/if}>
                                <i class="icon-refresh"></i> {__("eurosite.sync_now", ["[default]" => "Sync now"])}
                            </button>
                        </form>
                        {* Overflow menu: three copy actions behind one glyph, so the
                           column stays one button wide however many we add. Rendered
                           for every row — with no cron key it explains why the copies
                           are unavailable instead of handing out commands that 403. *}
                        <div class="eurosite-menu-wrap" style="position: relative; display: inline-block; margin-left: 2px;">
                            <button type="button" class="btn btn-micro eurosite-menu-toggle"
                                    data-menu="{$job.mode}"
                                    aria-haspopup="true" aria-expanded="false"
                                    aria-controls="eurosite-menu-{$job.mode}"
                                    aria-label="{__("eurosite.more_actions", ["[default]" => "More actions"])|escape:html}: {$job.mode|escape:html}">
                                &#8943;
                            </button>
                            <div class="eurosite-menu" id="eurosite-menu-{$job.mode}" role="menu" hidden
                                 style="position: absolute; right: 0; top: 100%; z-index: 100; min-width: 210px;
                                        padding: 4px; margin-top: 2px; text-align: left; background: #fff;
                                        border: 1px solid #d5dae1; border-radius: 6px; box-shadow: 0 6px 18px rgba(16,24,40,0.14);">
                                {if $eurosite_cron_has_key}
                                    <button type="button" role="menuitem" class="eurosite-menu-item eurosite-copy"
                                            data-copy="{$job.crontab_line|escape:html}"
                                            data-txt-copied="{__("eurosite.copied", ["[default]" => "Copied"])|escape:html}"
                                            data-txt-copy-failed="{__("eurosite.copy_failed", ["[default]" => "Copy failed"])|escape:html}"
                                            style="display: block; width: 100%; padding: 6px 10px; border: 0; border-radius: 4px; background: none; text-align: left; white-space: nowrap; cursor: pointer;">
                                        {__("eurosite.copy_crontab_line", ["[default]" => "Copy crontab line"])}
                                    </button>
                                    <button type="button" role="menuitem" class="eurosite-menu-item eurosite-copy"
                                            data-copy="{$job.url|escape:html}"
                                            data-txt-copied="{__("eurosite.copied", ["[default]" => "Copied"])|escape:html}"
                                            data-txt-copy-failed="{__("eurosite.copy_failed", ["[default]" => "Copy failed"])|escape:html}"
                                            style="display: block; width: 100%; padding: 6px 10px; border: 0; border-radius: 4px; background: none; text-align: left; white-space: nowrap; cursor: pointer;">
                                        {__("eurosite.copy_url", ["[default]" => "Copy URL"])}
                                    </button>
                                    <button type="button" role="menuitem" class="eurosite-menu-item eurosite-copy"
                                            data-copy="{$job.cli|escape:html}"
                                            data-txt-copied="{__("eurosite.copied", ["[default]" => "Copied"])|escape:html}"
                                            data-txt-copy-failed="{__("eurosite.copy_failed", ["[default]" => "Copy failed"])|escape:html}"
                                            style="display: block; width: 100%; padding: 6px 10px; border: 0; border-radius: 4px; background: none; text-align: left; white-space: nowrap; cursor: pointer;">
                                        {__("eurosite.copy_cli", ["[default]" => "Copy CLI command"])}
                                    </button>
                                {else}
                                    <div class="muted" style="padding: 6px 10px; font-size: 11px; white-space: normal;">
                                        {__("eurosite.menu_needs_key", ["[default]" => "Set a cron security key to copy scheduled commands."])}
                                        <a href="#eurosite-scheduled-jobs">{__("eurosite.generate_cron_key", ["[default]" => "Generate a key"])}</a>
                                    </div>
                                {/if}
                            </div>
                        </div>
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

    {* ── Scheduled jobs: the crontab you actually paste, not nine URLs ── *}
    <h4 id="eurosite-scheduled-jobs" style="margin-top: 20px;">{__("eurosite.cron_commands", ["[default]" => "Scheduled jobs"])}</h4>

    {if !$eurosite_cron_has_key}
        {* No key means eurosite_cron.run answers 403 to everything, so there is
           nothing worth printing. The page used to render a full table of
           commands with an empty access_key= — every one of them dead. *}
        <div class="alert alert-error" style="max-width: 900px;">
            <h5 style="margin-top: 0;">
                <i class="icon-warning-sign"></i>
                {__("eurosite.cron_no_key_title", ["[default]" => "No cron security key is set"])}
            </h5>
            <p style="margin-bottom: 6px;">
                {__("eurosite.cron_no_key_body", ["[default]" => "Scheduled syncs answer 403 until a key exists, so there are no commands to schedule yet. Nothing is exposed — the endpoint refuses every request while the key is blank."])}
            </p>
            <form action="{""|fn_url}" method="post" style="display:inline;">
                <input type="hidden" name="dispatch" value="eurosite.generate_cron_key" />
                {* CSRF token. This button now rotates the SHARED Travel Core
                   key, so an unprotected POST would break the scheduled jobs of
                   all three providers, not just this one. *}
                <input type="hidden" name="security_hash" value="{$security_hash}" />
                <button type="submit" class="btn btn-primary">
                    <i class="icon-key"></i> {__("eurosite.generate_cron_key", ["[default]" => "Generate a key"])}
                </button>
            </form>
            <a href="{"addons.update&addon=travel_core"|fn_url}" class="btn">
                <i class="icon-cog"></i> {__("eurosite.set_key_in_settings", ["[default]" => "Set it in Travel Core settings"])}
            </a>
        </div>
    {else}
        <p class="muted" style="font-size: 12px;">
            {__("eurosite.cron_paste_hint", ["[default]" => "Paste this into the server crontab or cPanel → Cron Jobs."])}
        </p>

        <div id="eurosite-crontab" data-crontabs="{$eurosite_crontabs_json|escape:html}"
             data-key="{$eurosite_cron_key|escape:html}"
             data-txt-copied="{__("eurosite.copied", ["[default]" => "Copied"])|escape:html}"
             data-txt-copy-failed="{__("eurosite.copy_failed", ["[default]" => "Copy failed"])|escape:html}"
             data-txt-copy-all="{__("eurosite.copy_all", ["[default]" => "Copy all"])|escape:html}"
             data-txt-reveal="{__("eurosite.reveal_key", ["[default]" => "Reveal access key"])|escape:html}"
             data-txt-hide="{__("eurosite.hide_key", ["[default]" => "Hide access key"])|escape:html}"
             data-txt-hidden-note="{__("eurosite.key_hidden_note", ["[default]" => "Access key hidden — copying still copies the real value"])|escape:html}"
             data-txt-shown-note="{__("eurosite.key_shown_note", ["[default]" => "Access key shown"])|escape:html}"
             style="max-width: 1100px;">

            <div style="display: flex; align-items: center; gap: 18px; flex-wrap: wrap; margin-bottom: 10px;">
                <span class="muted" style="font-size: 12px;">{__("eurosite.cron_plan", ["[default]" => "Plan"])}</span>
                <label style="display: inline-flex; align-items: center; gap: 6px; margin: 0; cursor: pointer;">
                    <input type="radio" name="eurosite_cron_plan" value="full" checked style="margin: 0;" />
                    {__("eurosite.cron_plan_full", ["[default]" => "One nightly full sync"])}
                    <span class="muted">({__("eurosite.recommended", ["[default]" => "recommended"])})</span>
                </label>
                <label style="display: inline-flex; align-items: center; gap: 6px; margin: 0; cursor: pointer;">
                    <input type="radio" name="eurosite_cron_plan" value="per" style="margin: 0;" />
                    {__("eurosite.cron_plan_per", ["[default]" => "Per-catalog schedule"])}
                </label>

                <span style="flex-grow: 1;"></span>

                <span class="muted" style="font-size: 12px;">{__("eurosite.cron_format", ["[default]" => "Format"])}</span>
                <label style="display: inline-flex; align-items: center; gap: 6px; margin: 0; cursor: pointer;">
                    <input type="radio" name="eurosite_cron_format" value="url" checked style="margin: 0;" />
                    {__("eurosite.cron_format_url", ["[default]" => "URL (curl)"])}
                </label>
                <label style="display: inline-flex; align-items: center; gap: 6px; margin: 0; cursor: pointer;">
                    <input type="radio" name="eurosite_cron_format" value="cli" style="margin: 0;" />
                    {__("eurosite.cron_format_cli", ["[default]" => "CLI (php)"])}
                </label>
            </div>

            <pre id="eurosite-crontab-text" style="padding: 14px 16px; background: #10151c; color: #e6edf6; border: 0; border-radius: 6px; font-size: 12px; line-height: 1.9; overflow-x: auto; white-space: pre;"></pre>

            <div style="display: flex; align-items: center; gap: 8px; margin-top: 8px; flex-wrap: wrap;">
                <button type="button" class="btn btn-primary" id="eurosite-crontab-copy">
                    <i class="icon-file"></i> {__("eurosite.copy_all", ["[default]" => "Copy all"])}
                </button>
                <button type="button" class="btn" id="eurosite-crontab-reveal">
                    <i class="icon-eye-open"></i> {__("eurosite.reveal_key", ["[default]" => "Reveal access key"])}
                </button>
                <span class="muted" style="font-size: 11px;" id="eurosite-crontab-note"></span>
            </div>
        </div>

        <div class="muted" style="font-size: 11px; max-width: 1100px; margin-top: 10px;">
            <i class="icon-warning-sign"></i>
            {__("eurosite.cron_access_key_note", ["[default]" => "These commands carry the shared Travel Core cron key. Copying puts it on your clipboard; anyone holding it can trigger a sync, and rotating it re-issues the Travel Core, Sphinx and Novoton commands too."])}
            <form action="{""|fn_url}" method="post" style="display:inline;">
                <input type="hidden" name="dispatch" value="eurosite.generate_cron_key" />
                {* CSRF token. This button now rotates the SHARED Travel Core
                   key, so an unprotected POST would break the scheduled jobs of
                   all three providers, not just this one. *}
                <input type="hidden" name="security_hash" value="{$security_hash}" />
                <button type="submit" class="btn btn-micro"
                        onclick="return confirm('{__("eurosite.rotate_key_confirm", ["[default]" => "Generate a new shared cron key? Every scheduled job of every travel addon must be updated with the new URL."])|escape:javascript}');">
                    {__("eurosite.rotate_key", ["[default]" => "Rotate key"])}
                </button>
            </form>
        </div>
    {/if}

</div>{* /.travel-admin-panel — opened at the top and never closed until now;
        the browser recovered by closing it at the end of the capture, which
        also swallowed anything appended after it. *}

{* Inside the capture on purpose: this page is reached through the admin
   top-nav (an AJAX navigation) whose response carries only the captured
   mainbox, so a {script src=} placed outside it is dropped and the copy
   buttons never wire up. *}
{script src="js/addons/eurosite/dashboard.js"}

{/capture}

{capture name="buttons"}{/capture}

{include file="common/mainbox.tpl" title=__("eurosite.dashboard_title", ["[default]" => "Eurosite Touring"]) content=$smarty.capture.mainbox buttons=$smarty.capture.buttons}
