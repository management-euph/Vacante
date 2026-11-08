{** Recent sync activity: one row per run, All or only the Problems, CS-Cart
   paginated (Services\SyncActivity). It replaces one tall card per run, in
   which a run that synced 2 of 3 items showed a green COMPLETED.

   common/pagination.tpl is a PAIR: the first include opens the
   cm-pagination-container, the second draws « ‹ [from - to / total] › » and
   closes it; both read $search (page, items_per_page, total_items), which the
   controller assigns. **}
{$_a = $sphinx_activity}
<section id="sphinx-activity" class="travel-cron-card travel-cron-card--flush travel-cron-activity" aria-labelledby="sphinx-activity-title">
    <div class="travel-cron-card__head travel-cron-card__head--pad">
        <h3 id="sphinx-activity-title">{__("sphinx_holidays.dash_activity_title")}</h3>
        <div class="btn-group" role="group" aria-label="{__("sphinx_holidays.dash_activity_filter")|escape:html}">
            <a class="btn{if !$_a.problems} active{/if}" href="{"sphinx_holidays.manage"|fn_url}#sphinx-activity" aria-current="{if !$_a.problems}true{else}false{/if}">{__("sphinx_holidays.dash_activity_all", ["[n]" => $_a.counts.all|number_format])}</a>
            <a class="btn{if $_a.problems} active{/if}" href="{"sphinx_holidays.manage?activity=problems"|fn_url}#sphinx-activity" aria-current="{if $_a.problems}true{else}false{/if}">{__("sphinx_holidays.dash_activity_problems", ["[n]" => $_a.counts.problems|number_format])}</a>
        </div>
    </div>
    {include file="common/pagination.tpl" save_current_page=true}
    {if $_a.rows}
        <table class="table table-middle travel-cron-table">
            <thead>
                <tr>
                    <th scope="col">{__("sphinx_holidays.dash_activity_col_date")}</th>
                    <th scope="col">{__("sphinx_holidays.dash_activity_col_job")}</th>
                    <th scope="col">{__("sphinx_holidays.dash_activity_col_run")}</th>
                    <th scope="col" class="right">{__("sphinx_holidays.dash_activity_col_items")}</th>
                    <th scope="col" class="right">{__("sphinx_holidays.dash_activity_col_not_synced")}</th>
                    <th scope="col">{__("sphinx_holidays.dash_activity_col_status")}</th>
                    <th scope="col" class="right">{__("sphinx_holidays.dash_activity_col_duration")}</th>
                </tr>
            </thead>
            <tbody>
                {foreach from=$_a.rows item=run}
                    <tr>
                        <td class="nowrap">{if $run.at}{$run.at|date_format:"`$settings.Appearance.date_format`, `$settings.Appearance.time_format`"}{else}—{/if}</td>
                        <td><code>{$run.job|escape:html}</code></td>
                        <td>{if $run.mode == "full"}{__("sphinx_holidays.dash_activity_full")}{elseif $run.mode == "incremental"}{__("sphinx_holidays.dash_activity_incremental")}{else}—{/if}</td>
                        <td class="right">{$run.items|number_format}</td>
                        <td class="right{if $run.not_synced > 0} travel-cron-text--bad{/if}">{$run.not_synced|number_format}</td>
                        <td>
                            {if $run.state == "ok"}
                                <span class="travel-cron-pill travel-cron-pill--ok"><i class="icon-ok" aria-hidden="true"></i> {__("sphinx_holidays.dash_activity_ok")}</span>
                            {elseif $run.state == "partial"}
                                <span class="travel-cron-pill travel-cron-pill--warn"><i class="icon-warning-sign" aria-hidden="true"></i> {__("sphinx_holidays.dash_activity_partial")}</span>
                            {elseif $run.state == "failed"}
                                <span class="travel-cron-pill travel-cron-pill--bad"><i class="icon-remove" aria-hidden="true"></i> {__("sphinx_holidays.dash_activity_failed")}</span>
                            {elseif $run.state == "stalled"}
                                <span class="travel-cron-pill travel-cron-pill--bad"><i class="icon-time" aria-hidden="true"></i> {__("sphinx_holidays.dash_activity_stalled")}</span>
                            {elseif $run.state == "running"}
                                <span class="travel-cron-pill travel-cron-pill--none"><i class="icon-refresh" aria-hidden="true"></i> {__("sphinx_holidays.dash_activity_running")}</span>
                            {else}
                                <span class="travel-cron-pill travel-cron-pill--none">—</span>
                            {/if}
                            {if $run.rate_limited > 0}
                                <div class="travel-cron-hint travel-cron-hint--warn">{__("sphinx_holidays.dash_activity_rate_limited", ["[n]" => $run.rate_limited|number_format])}</div>
                            {/if}
                            {if $run.error}
                                <div class="travel-cron-hint travel-cron-hint--bad">{$run.error|escape:html}</div>
                                {if $run.error_full}
                                    <details class="travel-cron-activity__error">
                                        <summary>{__("sphinx_holidays.dash_activity_show_error")}</summary>
                                        <pre>{$run.error_full|escape:html}</pre>
                                    </details>
                                {/if}
                            {/if}
                        </td>
                        <td class="right nowrap">{if $run.seconds}{$run.seconds|string_format:"%.1f"} s{else}—{/if}</td>
                    </tr>
                {/foreach}
            </tbody>
        </table>
    {else}
        <p class="travel-cron-activity__empty muted">{if $_a.problems}{__("sphinx_holidays.dash_activity_no_problems")}{else}{__("sphinx_holidays.dash_activity_none")}{/if}</p>
    {/if}
    {include file="common/pagination.tpl" save_current_page=true}
</section>
