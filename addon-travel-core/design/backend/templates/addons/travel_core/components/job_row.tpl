{* One scheduled job on a provider's dashboard: name, schedule, last run, Run and ⋯.

   Params: job (a CronJobPlan row), has_key, run_dispatch (the add-on's
   run_job action).

   The key is never printed: the ⋯ menu's copy items carry the real
   commands in data-copy (cron-jobs.js copies them), and Run posts to the
   add-on's run_job action, which needs no key. *}
{if $job.labelled}
    {$_job_name = __($job.name_key)}
{else}
    {$_job_name = $job.mode}
{/if}
<tr class="travel-cron-job{if $job.recommended} travel-cron-job--recommended{/if}">
    <td>
        <strong>{$_job_name}</strong>
        {if $job.recommended}<span class="travel-cron-job__badge">{__("travel_core.jobs_recommended")}</span>{/if}
        <div class="travel-cron-job__desc">{if $job.labelled}{__($job.desc_key)}{else}{$job.description|escape:html}{/if}</div>
        <code class="travel-cron-job__mode">{$job.mode|escape:html}</code>
    </td>
    <td>
        {__($job.schedule_key, $job.schedule_params)}
        {if $job.cron}<div><code class="travel-cron-job__cron">{$job.cron|escape:html}</code></div>{/if}
    </td>
    <td>
        {$_at = $job.at|date_format:"`$settings.Appearance.date_format`, `$settings.Appearance.time_format`"}
        {if $job.state == "ok"}
            <span class="travel-cron-pill travel-cron-pill--ok"><i class="icon-ok" aria-hidden="true"></i> {__("travel_core.jobs_state_ok")}</span>
            <div class="travel-cron-hint">{__("travel_core.jobs_finished_at", ["[date]" => $_at])}</div>
        {elseif $job.state == "late"}
            <span class="travel-cron-pill travel-cron-pill--warn"><i class="icon-time" aria-hidden="true"></i> {__("travel_core.jobs_state_late")}</span>
            <div class="travel-cron-hint">{__("travel_core.jobs_finished_at", ["[date]" => $_at])}</div>
        {elseif $job.state == "failed"}
            <span class="travel-cron-pill travel-cron-pill--bad"><i class="icon-remove" aria-hidden="true"></i> {__("travel_core.jobs_state_failed")}</span>
            <div class="travel-cron-hint">{__("travel_core.jobs_finished_at", ["[date]" => $_at])}</div>
            {if $job.error}<div class="travel-cron-hint travel-cron-hint--bad">{$job.error|escape:html}</div>{/if}
        {elseif $job.state == "running"}
            <span class="travel-cron-pill travel-cron-pill--none"><i class="icon-refresh" aria-hidden="true"></i> {__("travel_core.jobs_state_running")}</span>
            <div class="travel-cron-hint">{__("travel_core.jobs_started_at", ["[date]" => $_at])}</div>
        {elseif $job.state == "stalled"}
            <span class="travel-cron-pill travel-cron-pill--bad"><i class="icon-warning-sign" aria-hidden="true"></i> {__("travel_core.jobs_state_stalled")}</span>
            <div class="travel-cron-hint travel-cron-hint--bad">{__("travel_core.jobs_stalled_hint", ["[date]" => $_at])}</div>
        {else}
            <span class="travel-cron-pill travel-cron-pill--none">{__("travel_core.jobs_state_never")}</span>
        {/if}
    </td>
    <td class="right travel-cron-table__actions">
        <div class="travel-cron-job__actions">
            {include file="addons/travel_core/components/run_job.tpl" run_dispatch=$run_dispatch job=$job.mode label=__("travel_core.jobs_run") class="btn btn-primary"}
            <div class="travel-cron-menu-wrap">
                <button type="button" class="btn travel-cron-menu-toggle"
                        aria-haspopup="true" aria-expanded="false"
                        aria-controls="travel-cron-menu-{$job.mode}"
                        aria-label="{__("travel_core.jobs_more_actions")|escape:html}: {$_job_name|escape:html}">&#8943;</button>
                <div class="travel-cron-menu" id="travel-cron-menu-{$job.mode}" role="menu" hidden>
                    {if $has_key}
                        {if $job.crontab_line}
                            <button type="button" role="menuitem" class="travel-cron-menu__item travel-cron-copy" data-copy="{$job.crontab_line|escape:html}">{__("travel_core.jobs_copy_crontab_line")}</button>
                        {/if}
                        <button type="button" role="menuitem" class="travel-cron-menu__item travel-cron-copy" data-copy="{$job.url|escape:html}">{__("travel_core.jobs_copy_url")}</button>
                        <button type="button" role="menuitem" class="travel-cron-menu__item travel-cron-copy" data-copy="{$job.cli|escape:html}">{__("travel_core.jobs_copy_cli")}</button>
                    {else}
                        <div class="travel-cron-menu__note">{__("travel_core.jobs_menu_needs_key")}</div>
                    {/if}
                    {if $job.has_status || $job.redo_all || $job.force_full || $job.resettable || $job.extras}
                        <div class="travel-cron-menu__divider" role="separator"></div>
                    {/if}
                    {if $job.has_status}
                        {include file="addons/travel_core/components/run_job.tpl" run_dispatch=$run_dispatch job=$job.mode action="status" label=__("travel_core.jobs_check_status") class="travel-cron-menu__item" menuitem=true}
                    {/if}
                    {if $job.redo_all}
                        {include file="addons/travel_core/components/run_job.tpl" run_dispatch=$run_dispatch job=$job.mode action="force" label=__("travel_core.jobs_redo_all") class="travel-cron-menu__item" menuitem=true confirm=__("travel_core.jobs_redo_all_confirm", ["[job]" => $_job_name])}
                    {/if}
                    {if $job.force_full}
                        {include file="addons/travel_core/components/run_job.tpl" run_dispatch=$run_dispatch job=$job.mode action="force_full" label=__("travel_core.jobs_force_full") class="travel-cron-menu__item" menuitem=true confirm=__("travel_core.jobs_force_full_confirm", ["[job]" => $_job_name])}
                    {/if}
                    {* The add-on's own items (CronJobPlan::EXTRA_ACTIONS), labelled from its packs. *}
                    {foreach from=$job.extras item=_extra}
                        {$_confirm = ""}
                        {if isset($_extra.confirm)}{$_confirm = __($_extra.confirm, ["[job]" => $_job_name])}{/if}
                        {include file="addons/travel_core/components/run_job.tpl" run_dispatch=$run_dispatch job=$job.mode action=$_extra.action label=__($_extra.label) class="travel-cron-menu__item" menuitem=true confirm=$_confirm}
                    {/foreach}
                    {if $job.resettable}
                        {include file="addons/travel_core/components/run_job.tpl" run_dispatch=$run_dispatch job=$job.mode action="reset" label=__("travel_core.jobs_reset_progress") class="travel-cron-menu__item travel-cron-menu__item--danger" menuitem=true confirm=__("travel_core.jobs_reset_confirm", ["[job]" => $_job_name])}
                    {/if}
                </div>
            </div>
        </div>
    </td>
</tr>
