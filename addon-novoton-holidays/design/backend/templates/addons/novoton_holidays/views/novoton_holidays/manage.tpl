{** Novoton Holidays dashboard.

   Top to bottom: what needs attention, the tools, the destinations we sell
   (a summary; they are chosen on Novoton -> Destinations), the scheduled
   jobs (in the order they run), every command, recent sync activity. Built
   on Travel Core's travel-cron-* styles so the three providers' dashboards
   read alike; behaviour in dashboard.js, no inline scripts. **}

{capture name="mainbox"}

<div class="novoton-dashboard travel-cron">

    {** At a glance: each tile says whether its figure is a problem. **}
    {$_hotels = $novoton_stats.hotels}
    <div class="travel-cron-tiles novoton-tiles">
        <div class="travel-cron-tile{if $_hotels.total > 0 && $_hotels.with_packages == 0} travel-cron-tile--warn{/if}">
            <div class="travel-cron-tile__label">{__("novoton_holidays.dash_tile_hotels")}</div>
            <div class="travel-cron-tile__value">{$_hotels.total|default:0}</div>
            <div class="travel-cron-tile__note">
                {__("novoton_holidays.dash_tile_hotels_realtime", ["[n]" => $_hotels.with_prices|default:0])}
                <br>{if $_hotels.total > 0 && $_hotels.with_packages == 0}<strong class="travel-cron-hint--warn">{/if}{__("novoton_holidays.dash_tile_hotels_season", ["[n]" => $_hotels.with_packages|default:0])}{if $_hotels.total > 0 && $_hotels.with_packages == 0}</strong>{/if}
            </div>
        </div>
        <div class="travel-cron-tile">
            <div class="travel-cron-tile__label">{__("novoton_holidays.dash_tile_products")}</div>
            <div class="travel-cron-tile__value">{$_hotels.with_products|default:0}</div>
            <div class="travel-cron-tile__note">
                {__("novoton_holidays.dash_tile_products_note")}
                <br><a href="{"products.manage?pcode=NVT"|fn_url}">{__("novoton_holidays.dash_tile_products_open")}</a>
            </div>
        </div>
        <div class="travel-cron-tile{if $novoton_stats.bookings.pending > 0} travel-cron-tile--warn{/if}">
            <div class="travel-cron-tile__label">{__("novoton_holidays.dash_tile_bookings")}</div>
            <div class="travel-cron-tile__value">{__("novoton_holidays.dash_tile_bookings_pending", ["[n]" => $novoton_stats.bookings.pending|default:0])}</div>
            <div class="travel-cron-tile__note">
                {__("novoton_holidays.dash_tile_bookings_note", ["[confirmed]" => $novoton_stats.bookings.confirmed|default:0, "[cancelled]" => $novoton_stats.bookings.cancelled|default:0])}
                <br><a href="{"travel_bookings.manage?provider=novoton"|fn_url}">{__("novoton_holidays.dash_tile_bookings_open")}</a>
            </div>
        </div>
        {$_jh = $novoton_job_health}
        <div class="travel-cron-tile{if $_jh.tone == "bad"} novoton-tile--bad{elseif $_jh.tone == "warn"} travel-cron-tile--warn{/if}">
            <div class="travel-cron-tile__label">{__("novoton_holidays.dash_jobs_title")}</div>
            <div class="travel-cron-tile__value">
                {if $_jh.counts.failed + $_jh.counts.stalled > 0}
                    {__("novoton_holidays.dash_tile_jobs_failing", ["[n]" => $_jh.counts.failed + $_jh.counts.stalled])}
                {elseif $_jh.counts.late > 0}
                    {__("novoton_holidays.dash_tile_jobs_late", ["[n]" => $_jh.counts.late])}
                {elseif $_jh.counts.never > 0}
                    {__("novoton_holidays.dash_tile_jobs_never", ["[n]" => $_jh.counts.never])}
                {else}
                    {__("novoton_holidays.dash_tile_jobs_ok")}
                {/if}
            </div>
            <div class="travel-cron-tile__note">
                {__("novoton_holidays.dash_tile_jobs_note", ["[ok]" => $_jh.counts.ok, "[late]" => $_jh.counts.late, "[never]" => $_jh.counts.never])}
                <br><a href="#novoton-cron-jobs">{__("novoton_holidays.dash_tile_jobs_open")}</a>
            </div>
        </div>
    </div>

    {** Needs attention: only real problems, each with the one action that fixes it. **}
    {if $novoton_attention || $novoton_dest_alerts}
        <section class="travel-cron-card novoton-attention" aria-labelledby="novoton-attention-title">
            <h3 id="novoton-attention-title">{__("novoton_holidays.dash_attention_title", ["[n]" => $novoton_attention|count + $novoton_dest_alerts|count])}</h3>
            <ul class="novoton-attention__list">
                {foreach from=$novoton_dest_alerts item=alert}
                    <li class="novoton-attention__item">
                        <span class="novoton-attention__text">
                            {if $alert.kind == "new"}
                                {__("novoton_holidays.dash_dest_alert_new", ["[n]" => $alert.n, "[country]" => $alert.country])}
                            {elseif $alert.kind == "gone"}
                                {__("novoton_holidays.dash_dest_alert_gone", ["[n]" => $alert.n])}
                            {else}
                                {__("novoton_holidays.dash_dest_alert_outside", ["[n]" => $alert.n])}
                            {/if}
                        </span>
                        <a class="btn" href="{"novoton_destinations.manage"|fn_url}">{__("novoton_holidays.dash_dest_review")}</a>
                    </li>
                {/foreach}
                {foreach from=$novoton_attention item=item}
                    {$_name = __("novoton_holidays.dash_job_`$item.mode`")}
                    {$_at = $item.at|date_format:"`$settings.Appearance.date_format`, `$settings.Appearance.time_format`"}
                    <li class="novoton-attention__item">
                        <span class="novoton-attention__text">
                            {if $item.kind == "failed"}
                                {__("novoton_holidays.dash_attention_failed", ["[job]" => $_name, "[date]" => $_at])}
                                {if $item.error}<span class="travel-cron-hint--bad">{$item.error|escape:html}</span>{/if}
                            {elseif $item.kind == "stalled"}
                                {__("novoton_holidays.dash_attention_stalled", ["[job]" => $_name, "[date]" => $_at])}
                            {elseif $item.kind == "never" && $item.mode == "sync_priceinfo_batched" && $item.hotels_with_season == 0 && $item.hotels_total > 0}
                                {__("novoton_holidays.dash_attention_never_prices", ["[job]" => $_name, "[with]" => $item.hotels_with_season, "[total]" => $item.hotels_total])}
                            {elseif $item.kind == "never"}
                                {__("novoton_holidays.dash_attention_never", ["[job]" => $_name])}
                            {elseif $item.kind == "late"}
                                {__("novoton_holidays.dash_attention_late", ["[job]" => $_name, "[date]" => $_at])}
                            {else}
                                {__("novoton_holidays.dash_attention_no_season", ["[with]" => $item.hotels_with_season, "[total]" => $item.hotels_total])}
                            {/if}
                        </span>
                        {include file="addons/novoton_holidays/components/run_job.tpl" job=$item.mode label=__("novoton_holidays.dash_attention_run", ["[job]" => $_name]) class="btn btn-primary"}
                    </li>
                {/foreach}
            </ul>
        </section>
    {/if}

    {** Tools, grouped by what they do. Diagnostics open in a new tab. **}
    <section class="travel-cron-card novoton-tools" aria-labelledby="novoton-tools-title">
        <h3 id="novoton-tools-title">{__("novoton_holidays.dash_tools_title")}</h3>
        <div class="novoton-tools__groups">
            <div class="novoton-tools__group">
                <h4>{__("novoton_holidays.dash_tools_check")}</h4>
                <a class="btn" href="{"novoton_holidays.check_prices"|fn_url}" target="_blank" rel="noopener">{__("novoton_holidays.dash_tool_check_prices")}</a>
                <a class="btn" href="{"novoton_holidays.check_prices_hotel"|fn_url}" target="_blank" rel="noopener">{__("novoton_holidays.dash_tool_check_prices_hotel")}</a>
                <a class="btn" href="{"novoton_holidays.check_packages"|fn_url}" target="_blank" rel="noopener">{__("novoton_holidays.dash_tool_check_packages")}</a>
                <a class="btn" href="{"novoton_holidays.test_api"|fn_url}" target="_blank" rel="noopener">{__("novoton_holidays.dash_tool_test_api")}</a>
                <a class="btn" href="{"novoton_diagnostic.health"|fn_url}" target="_blank" rel="noopener">{__("novoton_holidays.dash_tool_health")}</a>
            </div>
            <div class="novoton-tools__group">
                <h4>{__("novoton_holidays.dash_tools_open")}</h4>
                <a class="btn" href="{"novoton_bookings.manage"|fn_url}">{__("novoton_holidays.dash_tool_bookings")}</a>
                <a class="btn" href="{"novoton_alternatives.manage"|fn_url}">{__("novoton_holidays.dash_tool_alternatives")}</a>
                <a class="btn" href="{"novoton_price_compare.manage"|fn_url}">{__("novoton_holidays.dash_tool_price_compare")}</a>
            </div>
            <div class="novoton-tools__group">
                <h4>{__("novoton_holidays.dash_tools_export")}</h4>
                <a class="btn" href="{"novoton_holidays.export_hotel_features_csv"|fn_url}">{__("novoton_holidays.dash_tool_features_csv")}</a>
                <a class="btn" href="{"novoton_holidays.export_hotel_features_xml"|fn_url}">{__("novoton_holidays.dash_tool_features_xml")}</a>
            </div>
            <div class="novoton-tools__group">
                <h4>{__("novoton_holidays.dash_tools_maintenance")}</h4>
                {include file="addons/novoton_holidays/components/run_job.tpl" job="recompute_calendar_prices" label=__("novoton_holidays.dash_tool_recompute_calendar") class="btn" confirm=__("novoton_holidays.dash_tool_recompute_calendar_confirm")}
                <p class="travel-cron-hint">{__("novoton_holidays.dash_tool_recompute_calendar_hint")}</p>
            </div>
        </div>
    </section>

    {** Destinations: what we sell, per country (Travel Core's shared card). Chosen on Novoton -> Destinations. **}
    {include file="addons/travel_core/components/destinations_card.tpl" card=$novoton_dest_card}

    {** Scheduled jobs — id: the target of the "Open Novoton cron" link on Travel Core -> Tools.
       One row per job, in the order the jobs run (CronPlanBuilder). No
       command with the cron key is ever printed: they are shown masked and
       Copy puts the real one on the clipboard; Run is an admin POST. **}
    <section id="novoton-cron-jobs" class="travel-cron-card travel-cron-card--flush novoton-jobs" aria-labelledby="novoton-jobs-title">
        <div class="travel-cron-card__head travel-cron-card__head--pad">
            <div>
                <h3 id="novoton-jobs-title">{__("novoton_holidays.dash_jobs_title")}</h3>
                <p class="muted">{__("novoton_holidays.dash_jobs_intro")}</p>
            </div>
        </div>

        {if !$novoton_cron_has_key}
            <div class="alert alert-warning novoton-jobs__nokey">
                <strong>{__("novoton_holidays.dash_no_key_title")}</strong>
                {* Travel Core, not novoton: the cron secret authenticates OUR
                   endpoint, so it lives once in Core and is shared by all three
                   providers. *}
                <p>{__("novoton_holidays.dash_no_key_body")} <a href="{"addons.update?addon=travel_core"|fn_url}">{__("novoton_holidays.dash_set_key")}</a></p>
            </div>
        {/if}

        <table class="table table-middle travel-cron-table novoton-jobs__table">
            <thead>
                <tr>
                    <th>{__("novoton_holidays.dash_col_job")}</th>
                    <th>{__("novoton_holidays.dash_col_schedule")}</th>
                    <th>{__("novoton_holidays.dash_col_last_run")}</th>
                    <th class="right">{__("novoton_holidays.dash_col_actions")}</th>
                </tr>
            </thead>
            {foreach from=$novoton_job_stages item=stage}
                <tbody>
                    <tr class="novoton-jobs__stage">
                        <th colspan="4" scope="colgroup">{$stage.number}. {__("novoton_holidays.dash_stage_`$stage.stage`")}</th>
                    </tr>
                    {foreach from=$stage.jobs item=job}
                        {include file="addons/novoton_holidays/components/job_row.tpl" job=$job has_key=$novoton_cron_has_key}
                    {/foreach}
                </tbody>
            {/foreach}
        </table>

        {if $novoton_on_demand_jobs}
            <details class="novoton-jobs__ondemand">
                <summary>{__("novoton_holidays.dash_on_demand_title", ["[n]" => $novoton_on_demand_jobs|count])}</summary>
                <p class="muted">{__("novoton_holidays.dash_on_demand_intro")}</p>
                <table class="table table-middle travel-cron-table novoton-jobs__table">
                    <tbody>
                        {foreach from=$novoton_on_demand_jobs item=job}
                            {include file="addons/novoton_holidays/components/job_row.tpl" job=$job has_key=$novoton_cron_has_key}
                        {/foreach}
                    </tbody>
                </table>
            </details>
        {/if}
    </section>

    {if $novoton_cron_has_key}
        {* Every job's command, for the server crontab (CLI) or a cron service
           (URL). The key is masked in the DISPLAY only: a masked command pasted
           into a crontab is a refused run at 04:30 nobody is awake to see, so
           Copy all copies the real text (dashboard.js). *}
        <section id="novoton-crontab" class="travel-cron-card novoton-crontab" aria-labelledby="novoton-crontab-title"
                 data-crontab-cli="{$novoton_crontab_cli|escape:html}"
                 data-crontab-url="{$novoton_crontab_url|escape:html}"
                 data-key="{$novoton_cron_key|escape:html}"
                 data-txt-copied="{__("novoton_holidays.dash_copied")|escape:html}"
                 data-txt-copy-failed="{__("novoton_holidays.dash_copy_failed")|escape:html}">
            <div class="travel-cron-card__head">
                <div>
                    <h3 id="novoton-crontab-title">{__("novoton_holidays.dash_commands_title")}</h3>
                    <p class="muted">{__("novoton_holidays.dash_commands_hint")}</p>
                </div>
                <div class="novoton-crontab__tools">
                    <div class="btn-group" role="group" aria-label="{__("novoton_holidays.dash_format_label")|escape:html}">
                        <button type="button" class="btn" data-novoton-format="cli" aria-pressed="true">{__("novoton_holidays.dash_format_cli")}</button>
                        <button type="button" class="btn" data-novoton-format="url" aria-pressed="false">{__("novoton_holidays.dash_format_url")}</button>
                    </div>
                    <button type="button" class="btn btn-primary" id="novoton-crontab-copy">{__("novoton_holidays.dash_copy_all")}</button>
                </div>
            </div>
            <pre id="novoton-crontab-text" class="novoton-crontab__text">{$novoton_crontab_masked|escape:html}</pre>
            <p class="travel-cron-foot novoton-crontab__note">
                <i class="icon-lock" aria-hidden="true"></i> {__("novoton_holidays.dash_key_hidden_note")}
                <a href="{"travel_tools.manage"|fn_url}">{__("novoton_holidays.dash_open_tools")}</a>
            </p>

            <div class="novoton-crontab__feed">
                <strong>{__("novoton_holidays.dash_xml_feed_title")}</strong>
                <p class="muted">{__("novoton_holidays.dash_xml_feed_hint")}</p>
                <div class="novoton-crontab__feedrow">
                    <code class="travel-cron-cmd">{$novoton_xml_feed_masked|escape:html}</code>
                    <button type="button" class="btn novoton-copy" data-copy="{$novoton_xml_feed_url|escape:html}">{__("novoton_holidays.dash_copy_url")}</button>
                </div>
            </div>
        </section>
    {/if}

    {** Recent sync activity, CS-Cart paginated (its own template: the
       pagination pair wraps exactly one list). **}
    {include file="addons/novoton_holidays/components/activity.tpl"}

</div>

{* Inside the capture on purpose: admin top-nav navigation is AJAX and runs
   only the scripts inside the mainbox capture. *}
{script src="js/addons/novoton_holidays/dashboard.js"}

{/capture}

{capture name="buttons"}
    <a class="btn btn-primary" href="{"addons.update?addon=novoton_holidays"|fn_url}">
        {__("settings")}
    </a>
{/capture}

{include file="common/mainbox.tpl"
    title=__("novoton_holidays.dash_title", ["[version]" => $addon_version])
    content=$smarty.capture.mainbox
    buttons=$smarty.capture.buttons
}
