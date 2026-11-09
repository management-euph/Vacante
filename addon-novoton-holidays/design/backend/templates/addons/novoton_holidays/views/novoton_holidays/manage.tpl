{** Novoton Holidays dashboard.

   Top to bottom: what needs attention, the tools, the destinations we sell
   (a summary; they are chosen on Novoton -> Destinations), the scheduled
   jobs (in the order they run), every command, recent sync activity. Built
   on Travel Core's travel-cron-* styles so the three providers' dashboards
   read alike. The scheduled jobs and All commands are Travel Core's shared
   cards (components/jobs_card.tpl, commands_card.tpl), driven by its
   cron-jobs.js; no inline scripts. **}

{capture name="mainbox"}

<div class="novoton-dashboard travel-cron">

    {** At a glance: each tile says whether its figure is a problem. **}
    {$_hotels = $novoton_stats.hotels}
    <div class="travel-cron-tiles travel-cron-tiles--four">
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
        {include file="addons/travel_core/components/jobs_tile.tpl" health=$novoton_job_health card_id="novoton-cron-jobs"}
    </div>

    {** Needs attention: only real problems, each with the one action that fixes it. **}
    {if $novoton_attention || $novoton_dest_alerts}
        <section class="travel-cron-card travel-cron-attention" aria-labelledby="novoton-attention-title">
            <h3 id="novoton-attention-title">{__("novoton_holidays.dash_attention_title", ["[n]" => $novoton_attention|count + $novoton_dest_alerts|count])}</h3>
            <ul class="travel-cron-attention__list">
                {foreach from=$novoton_dest_alerts item=alert}
                    <li class="travel-cron-attention__item">
                        <span class="travel-cron-attention__text">
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
                    <li class="travel-cron-attention__item">
                        <span class="travel-cron-attention__text">
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
                        {include file="addons/travel_core/components/run_job.tpl" run_dispatch="novoton_holidays.run_job" job=$item.mode label=__("novoton_holidays.dash_attention_run", ["[job]" => $_name]) class="btn btn-primary"}
                    </li>
                {/foreach}
            </ul>
        </section>
    {/if}

    {** Tools, grouped by what they do. Diagnostics open in a new tab. **}
    <section class="travel-cron-card travel-cron-tools" aria-labelledby="novoton-tools-title">
        <h3 id="novoton-tools-title">{__("novoton_holidays.dash_tools_title")}</h3>
        <div class="travel-cron-tools__groups">
            <div class="travel-cron-tools__group">
                <h4>{__("novoton_holidays.dash_tools_check")}</h4>
                <a class="btn" href="{"novoton_holidays.check_prices"|fn_url}" target="_blank" rel="noopener">{__("novoton_holidays.dash_tool_check_prices")}</a>
                <a class="btn" href="{"novoton_holidays.check_prices_hotel"|fn_url}" target="_blank" rel="noopener">{__("novoton_holidays.dash_tool_check_prices_hotel")}</a>
                <a class="btn" href="{"novoton_holidays.check_packages"|fn_url}" target="_blank" rel="noopener">{__("novoton_holidays.dash_tool_check_packages")}</a>
                <a class="btn" href="{"novoton_holidays.test_api"|fn_url}" target="_blank" rel="noopener">{__("novoton_holidays.dash_tool_test_api")}</a>
                <a class="btn" href="{"novoton_diagnostic.health"|fn_url}" target="_blank" rel="noopener">{__("novoton_holidays.dash_tool_health")}</a>
            </div>
            <div class="travel-cron-tools__group">
                <h4>{__("novoton_holidays.dash_tools_open")}</h4>
                <a class="btn" href="{"novoton_bookings.manage"|fn_url}">{__("novoton_holidays.dash_tool_bookings")}</a>
                <a class="btn" href="{"novoton_alternatives.manage"|fn_url}">{__("novoton_holidays.dash_tool_alternatives")}</a>
                <a class="btn" href="{"novoton_price_compare.manage"|fn_url}">{__("novoton_holidays.dash_tool_price_compare")}</a>
            </div>
            <div class="travel-cron-tools__group">
                <h4>{__("novoton_holidays.dash_tools_export")}</h4>
                <a class="btn" href="{"novoton_holidays.export_hotel_features_csv"|fn_url}">{__("novoton_holidays.dash_tool_features_csv")}</a>
                <a class="btn" href="{"novoton_holidays.export_hotel_features_xml"|fn_url}">{__("novoton_holidays.dash_tool_features_xml")}</a>
            </div>
            <div class="travel-cron-tools__group">
                <h4>{__("novoton_holidays.dash_tools_maintenance")}</h4>
                {include file="addons/travel_core/components/run_job.tpl" run_dispatch="novoton_holidays.run_job" job="recompute_calendar_prices" label=__("novoton_holidays.dash_tool_recompute_calendar") class="btn" confirm=__("novoton_holidays.dash_tool_recompute_calendar_confirm")}
                <p class="travel-cron-hint">{__("novoton_holidays.dash_tool_recompute_calendar_hint")}</p>
            </div>
        </div>
    </section>

    {** Destinations: what we sell, per country (Travel Core's shared card). Chosen on Novoton -> Destinations. **}
    {include file="addons/travel_core/components/destinations_card.tpl" card=$novoton_dest_card}

    {** Hotel availability: its hotels per state (Travel Core's shared card). **}
    {if $novoton_availability_card}
        {include file="addons/travel_core/components/availability_card.tpl" card=$novoton_availability_card}
    {/if}

    {** Scheduled jobs: Travel Core's shared card, from CronPlanBuilder's rows.
       card_id is the target of the "Open Novoton cron" link on Travel Core ->
       Tools. No command with the cron key is ever printed: the ⋯ menus copy
       the real ones, and Run posts to novoton_holidays.run_job. **}
    {include file="addons/travel_core/components/jobs_card.tpl"
        card_id="novoton-cron-jobs"
        stages=$novoton_job_stages
        on_demand=$novoton_on_demand_jobs
        on_demand_intro=__("novoton_holidays.dash_on_demand_intro")
        has_key=$novoton_cron_has_key
        run_dispatch="novoton_holidays.run_job"
    }

    {if $novoton_cron_has_key}
        {** Every job's command (Travel Core's shared card), with Novoton's
           hotel-features XML feed under it. Shown masked; the real text rides
           only in data-* for Copy. **}
        {capture name="novoton_xml_feed"}
            <div class="novoton-crontab__feed">
                <strong>{__("novoton_holidays.dash_xml_feed_title")}</strong>
                <p class="muted">{__("novoton_holidays.dash_xml_feed_hint")}</p>
                <div class="novoton-crontab__feedrow">
                    <code class="travel-cron-cmd">{$novoton_xml_feed_masked|escape:html}</code>
                    <button type="button" class="btn travel-cron-copy" data-copy="{$novoton_xml_feed_url|escape:html}">{__("travel_core.jobs_copy_url")}</button>
                </div>
            </div>
        {/capture}
        {include file="addons/travel_core/components/commands_card.tpl"
            card_id="novoton-crontab"
            cli=$novoton_crontab_cli
            url=$novoton_crontab_url
            masked=$novoton_crontab_masked
            key=$novoton_cron_key
            extra=$smarty.capture.novoton_xml_feed
        }
    {/if}

    {** Recent sync activity, CS-Cart paginated (its own template: the
       pagination pair wraps exactly one list). **}
    {include file="addons/novoton_holidays/components/activity.tpl"}

</div>

{* No script here: the jobs card loads Travel Core's cron-jobs.js inside this
   capture (admin top-nav navigation is AJAX and runs only the scripts inside
   the mainbox capture), and it serves the Run buttons above it too. *}

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
