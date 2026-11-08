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

    {** Scheduled jobs: Travel Core's shared card, from CronPlanBuilder's rows,
       in the order they run. card_id is the target of the "Open Eurosite
       cron" link on Travel Core -> Tools. No command with the cron key is
       ever printed: the ⋯ menus copy the real ones, and Run posts to
       eurosite.run_job, its log in a new window. Under each job's name, what
       is Eurosite's own (components/job_extra.tpl): its catalog's size, the
       hotel list, why it has nothing to do. **}
    {include file="addons/travel_core/components/jobs_card.tpl"
        card_id="eurosite-scheduled-jobs"
        stages=$eurosite_job_stages
        on_demand=$eurosite_on_demand_jobs
        on_demand_intro=__("eurosite.jobs_on_demand_intro")
        has_key=$eurosite_cron_has_key
        run_dispatch="eurosite.run_job"
        row_extra="addons/eurosite/components/job_extra.tpl"
    }

    {if $eurosite_cron_has_key}
        {** Every job's command (Travel Core's shared card), in Eurosite's two
           plans: the catalogs in one nightly run, or each on its own line;
           one shown at a time, as installing both runs every catalog twice.
           Shown masked; the real text rides only in data-* for Copy. **}
        {include file="addons/travel_core/components/commands_card.tpl"
            card_id="eurosite-crontab"
            plans=$eurosite_cron_plans
            key=$eurosite_cron_key
        }
    {/if}

    {* ── Destinations: what we sell, per country (Travel Core's shared card) ── *}
    {if $eurosite_dest_card}
        {include file="addons/travel_core/components/destinations_card.tpl" card=$eurosite_dest_card}
    {/if}

    {* ── Hotel availability: its hotels per state (Travel Core's shared card) ── *}
    {if $eurosite_availability_card}
        {include file="addons/travel_core/components/availability_card.tpl" card=$eurosite_availability_card}
    {/if}

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

    {* Read-only probe: when the configured dates find no Immediate hotel,
       ask the API across the next months which destinations and dates do
       have offers. Runs in the admin (eurosite.probe_availability, no cron
       key needed) and prints its report in a new window. Never scheduled. *}
    <div id="eurosite-probe" class="well well-small" style="max-width: 1100px; margin-top: 16px;">
        <h5 style="margin-top: 0;"><i class="icon-search"></i> {__("eurosite.probe_title")}</h5>
        <p class="muted" style="font-size: 12px;">{__("eurosite.probe_hint")}</p>
        <form action="{""|fn_url}" method="get" target="_blank" class="form-inline cm-disable-check-changes" style="margin: 0;">
            <input type="hidden" name="dispatch" value="eurosite.probe_availability" />
            <label>{__("eurosite.probe_country")} <input type="text" name="country" value="GR" class="input-mini" maxlength="4" /></label>
            <label style="margin-left: 8px;">{__("eurosite.probe_cities")} <input type="text" name="city" value="" class="input-medium" placeholder="{__("eurosite.probe_cities_all")|escape:html}" /></label>
            <label style="margin-left: 8px;">{__("eurosite.probe_from")} <input type="date" name="from" value="" class="input-medium" /></label>
            <label style="margin-left: 8px;">{__("eurosite.probe_nights")} <input type="number" name="nights" value="" min="1" max="30" class="input-mini" /></label>
            <label style="margin-left: 8px;">{__("eurosite.probe_months")} <input type="number" name="months" value="12" min="1" max="18" class="input-mini" /></label>
            <button type="submit" class="btn btn-primary" style="margin-left: 8px;"><i class="icon-search"></i> {__("eurosite.probe_run")}</button>
        </form>
    </div>

</div>{* /.travel-admin-panel — opened at the top and never closed until now;
        the browser recovered by closing it at the end of the capture, which
        also swallowed anything appended after it. *}

{/capture}

{capture name="buttons"}{/capture}

{include file="common/mainbox.tpl" title=__("eurosite.dashboard_title", ["[default]" => "Eurosite Touring"]) content=$smarty.capture.mainbox buttons=$smarty.capture.buttons}
