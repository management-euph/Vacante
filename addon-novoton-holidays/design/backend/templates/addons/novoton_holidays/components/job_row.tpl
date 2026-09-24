{* One scheduled job on the dashboard: name, schedule, last run, Run and ⋯.

   Params: job (a CronPlanBuilder row), has_key.

   The key is never printed: the ⋯ menu's copy items carry the real
   commands in data-copy (dashboard.js copies them), and Run posts to
   novoton_holidays.run_job, which needs no key. *}
{if $job.labelled}
    {$_job_name = __("novoton_holidays.dash_job_`$job.mode`")}
{else}
    {$_job_name = $job.mode}
{/if}
<tr class="novoton-job{if $job.recommended} novoton-job--recommended{/if}">
    <td>
        <strong>{$_job_name}</strong>
        {if $job.recommended}<span class="novoton-job__badge">{__("novoton_holidays.dash_recommended")}</span>{/if}
        <div class="novoton-job__desc">{if $job.labelled}{__("novoton_holidays.dash_job_`$job.mode`_desc")}{else}{$job.description|escape:html}{/if}</div>
        <code class="novoton-job__mode">{$job.mode|escape:html}</code>
    </td>
    <td>
        {__($job.schedule_key, $job.schedule_params)}
        {if $job.cron}<div><code class="novoton-job__cron">{$job.cron|escape:html}</code></div>{/if}
    </td>
    <td>
        {$_at = $job.at|date_format:"`$settings.Appearance.date_format`, `$settings.Appearance.time_format`"}
        {if $job.state == "ok"}
            <span class="travel-cron-pill travel-cron-pill--ok"><i class="icon-ok" aria-hidden="true"></i> {__("novoton_holidays.dash_run_ok")}</span>
            <div class="travel-cron-hint">{__("novoton_holidays.dash_run_finished_at", ["[date]" => $_at])}</div>
        {elseif $job.state == "late"}
            <span class="travel-cron-pill travel-cron-pill--warn"><i class="icon-time" aria-hidden="true"></i> {__("novoton_holidays.dash_run_late")}</span>
            <div class="travel-cron-hint">{__("novoton_holidays.dash_run_finished_at", ["[date]" => $_at])}</div>
        {elseif $job.state == "failed"}
            <span class="travel-cron-pill travel-cron-pill--bad"><i class="icon-remove" aria-hidden="true"></i> {__("novoton_holidays.dash_run_failed")}</span>
            <div class="travel-cron-hint">{__("novoton_holidays.dash_run_finished_at", ["[date]" => $_at])}</div>
            {if $job.error}<div class="travel-cron-hint travel-cron-hint--bad">{$job.error|escape:html}</div>{/if}
        {elseif $job.state == "running"}
            <span class="travel-cron-pill travel-cron-pill--none"><i class="icon-refresh" aria-hidden="true"></i> {__("novoton_holidays.dash_run_running")}</span>
            <div class="travel-cron-hint">{__("novoton_holidays.dash_run_started_at", ["[date]" => $_at])}</div>
        {elseif $job.state == "stalled"}
            <span class="travel-cron-pill travel-cron-pill--bad"><i class="icon-warning-sign" aria-hidden="true"></i> {__("novoton_holidays.dash_run_stalled")}</span>
            <div class="travel-cron-hint travel-cron-hint--bad">{__("novoton_holidays.dash_run_stalled_hint", ["[date]" => $_at])}</div>
        {else}
            <span class="travel-cron-pill travel-cron-pill--none">{__("novoton_holidays.dash_run_never")}</span>
        {/if}
    </td>
    <td class="right travel-cron-table__actions">
        <div class="novoton-job__actions">
            {include file="addons/novoton_holidays/components/run_job.tpl" job=$job.mode label=__("novoton_holidays.dash_run") class="btn btn-primary"}
            <div class="novoton-menu-wrap">
                <button type="button" class="btn novoton-menu-toggle"
                        aria-haspopup="true" aria-expanded="false"
                        aria-controls="novoton-menu-{$job.mode}"
                        aria-label="{__("novoton_holidays.dash_more_actions")|escape:html}: {$_job_name|escape:html}">&#8943;</button>
                <div class="novoton-menu" id="novoton-menu-{$job.mode}" role="menu" hidden>
                    {if $has_key}
                        {if $job.crontab_line}
                            <button type="button" role="menuitem" class="novoton-menu__item novoton-copy" data-copy="{$job.crontab_line|escape:html}">{__("novoton_holidays.dash_copy_crontab_line")}</button>
                        {/if}
                        <button type="button" role="menuitem" class="novoton-menu__item novoton-copy" data-copy="{$job.url|escape:html}">{__("novoton_holidays.dash_copy_url")}</button>
                        <button type="button" role="menuitem" class="novoton-menu__item novoton-copy" data-copy="{$job.cli|escape:html}">{__("novoton_holidays.dash_copy_cli")}</button>
                    {else}
                        <div class="novoton-menu__note">{__("novoton_holidays.dash_menu_needs_key")}</div>
                    {/if}
                    {if $job.has_status}
                        <div class="novoton-menu__divider" role="separator"></div>
                        {include file="addons/novoton_holidays/components/run_job.tpl" job=$job.mode action="status" label=__("novoton_holidays.dash_check_status") class="novoton-menu__item" menuitem=true}
                    {/if}
                    {if $job.batched}
                        {include file="addons/novoton_holidays/components/run_job.tpl" job=$job.mode action="force_full" label=__("novoton_holidays.dash_force_full") class="novoton-menu__item" menuitem=true confirm=__("novoton_holidays.dash_force_full_confirm", ["[job]" => $_job_name])}
                        {include file="addons/novoton_holidays/components/run_job.tpl" job=$job.mode action="reset" label=__("novoton_holidays.dash_reset_progress") class="novoton-menu__item novoton-menu__item--danger" menuitem=true confirm=__("novoton_holidays.dash_reset_confirm", ["[job]" => $_job_name])}
                    {/if}
                </div>
            </div>
        </div>
    </td>
</tr>
