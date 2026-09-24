{* Recent sync activity on the dashboard, CS-Cart paginated.

   common/pagination.tpl is a PAIR: the first include opens the
   cm-pagination-container, the second draws « ‹ [from - to / total] › » and
   closes it; both read $search (page, items_per_page, total_items), which
   the controller assigns. *}
<section class="travel-cron-card travel-cron-card--flush novoton-activity" aria-labelledby="novoton-activity-title">
    <div class="travel-cron-card__head travel-cron-card__head--pad">
        <h3 id="novoton-activity-title">{__("novoton_holidays.dash_activity_title")}</h3>
        <div class="btn-group" role="group">
            <a class="btn{if !$search.activity} active{/if}" href="{"novoton_holidays.manage"|fn_url}#novoton-activity-title" aria-current="{if !$search.activity}true{else}false{/if}">{__("novoton_holidays.dash_activity_all", ["[n]" => $novoton_activity_all])}</a>
            <a class="btn{if $search.activity} active{/if}" href="{"novoton_holidays.manage?activity=failed"|fn_url}#novoton-activity-title" aria-current="{if $search.activity}true{else}false{/if}">{__("novoton_holidays.dash_activity_failed", ["[n]" => $novoton_activity_failed])}</a>
        </div>
    </div>
    {include file="common/pagination.tpl" save_current_page=true}
    {if $recent_syncs}
        <table class="table table-middle travel-cron-table">
            <thead>
                <tr>
                    <th>{__("novoton_holidays.dash_col_date")}</th>
                    <th>{__("novoton_holidays.dash_col_job")}</th>
                    <th class="right">{__("novoton_holidays.dash_col_total")}</th>
                    <th class="right">{__("novoton_holidays.dash_col_updated")}</th>
                    <th class="right">{__("novoton_holidays.dash_col_failed")}</th>
                    <th>{__("novoton_holidays.dash_col_status")}</th>
                    <th class="right">{__("novoton_holidays.dash_col_duration")}</th>
                </tr>
            </thead>
            <tbody>
                {foreach from=$recent_syncs item=log}
                    <tr>
                        <td>{$log.sync_date|date_format:"`$settings.Appearance.date_format`, `$settings.Appearance.time_format`"}</td>
                        <td><code>{$log.sync_type|escape:html}</code></td>
                        <td class="right">{$log.products_total}</td>
                        <td class="right">{$log.products_updated}</td>
                        <td class="right{if $log.products_failed > 0} travel-cron-text--bad{/if}">{$log.products_failed}</td>
                        <td>
                            {if $log.status == 'completed'}
                                <span class="travel-cron-pill travel-cron-pill--ok"><i class="icon-ok" aria-hidden="true"></i> {__("novoton_holidays.dash_activity_ok")}</span>
                            {elseif $log.status == 'failed'}
                                <span class="travel-cron-pill travel-cron-pill--bad"><i class="icon-remove" aria-hidden="true"></i> {__("novoton_holidays.dash_run_failed")}</span>
                                {if $log.error_message}<div class="travel-cron-hint travel-cron-hint--bad">{$log.error_message|escape:html|truncate:160}</div>{/if}
                            {elseif $log.status == 'running'}
                                <span class="travel-cron-pill travel-cron-pill--none"><i class="icon-refresh" aria-hidden="true"></i> {__("novoton_holidays.dash_run_running")}</span>
                            {else}
                                <span class="travel-cron-pill travel-cron-pill--none">{$log.status|escape:html}</span>
                            {/if}
                        </td>
                        <td class="right">{$log.duration_seconds|default:0} s</td>
                    </tr>
                {/foreach}
            </tbody>
        </table>
    {else}
        <p class="novoton-activity__empty muted">{__("novoton_holidays.dash_activity_none")}</p>
    {/if}
    {include file="common/pagination.tpl" save_current_page=true}
</section>
