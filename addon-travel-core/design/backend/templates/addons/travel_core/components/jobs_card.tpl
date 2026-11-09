{** A provider's scheduled jobs: one row per job, in the order the jobs run.
   Shared by the providers' dashboards; the add-on hands over its plan.

   Params:
     card_id          the section's id (Travel Core -> Tools links to it)
     stages           CronJobPlan::stages()
     on_demand        CronJobPlan::onDemandRows()
     on_demand_intro  the add-on's note above its on-demand list (optional)
     has_key          FALSE: the "no cron key" warning, and the ⋯ menus say why nothing can be copied
     run_dispatch     the add-on's run_job action, e.g. novoton_holidays.run_job
     row_extra        a template of the add-on's, shown under each job's name and
                      description with that job's row as $job (optional): what is
                      its own, such as a catalog's size or a link to its list

   No command with the cron key is ever printed: the ⋯ menus copy the real
   ones (cron-jobs.js) and Run is an admin POST. The script is loaded here,
   inside the page's mainbox capture, so it runs after the admin's AJAX
   navigation too; it serves every Run form and Copy button on the page. **}
<section id="{$card_id}" class="travel-cron-card travel-cron-card--flush travel-cron-jobs" aria-labelledby="{$card_id}-title"
         data-txt-copied="{__("travel_core.jobs_copied")|escape:html}"
         data-txt-copy-failed="{__("travel_core.jobs_copy_failed")|escape:html}">
    <div class="travel-cron-card__head travel-cron-card__head--pad">
        <div>
            <h3 id="{$card_id}-title">{__("travel_core.jobs_title")}</h3>
            <p class="muted">{__("travel_core.jobs_intro")}</p>
        </div>
    </div>

    {if !$has_key}
        <div class="alert alert-warning travel-cron-jobs__nokey">
            <strong>{__("travel_core.jobs_no_key_title")}</strong>
            {* The cron secret authenticates the providers' own endpoints, so it
               lives once in Travel Core and is shared by all of them. The link
               goes to its card on Travel Core -> Tools, where Generate mints a
               strong one in one click: a key typed by hand into the settings
               form is how a store ended up with a weak or blank one. *}
            <p>{__("travel_core.jobs_no_key_body")} <a href="{"travel_tools.manage"|fn_url}#travel-cron-key-title">{__("travel_core.jobs_set_key")}</a></p>
        </div>
    {/if}

    <table class="table table-middle travel-cron-table travel-cron-jobs__table">
        <thead>
            <tr>
                <th>{__("travel_core.jobs_col_job")}</th>
                <th>{__("travel_core.jobs_col_schedule")}</th>
                <th>{__("travel_core.jobs_col_last_run")}</th>
                <th class="right">{__("travel_core.jobs_col_actions")}</th>
            </tr>
        </thead>
        {foreach from=$stages item=stage}
            <tbody>
                <tr class="travel-cron-jobs__stage">
                    <th colspan="4" scope="colgroup">{$stage.number}. {__($stage.title_key)}</th>
                </tr>
                {foreach from=$stage.jobs item=job}
                    {include file="addons/travel_core/components/job_row.tpl" job=$job has_key=$has_key run_dispatch=$run_dispatch row_extra=$row_extra|default:""}
                {/foreach}
            </tbody>
        {/foreach}
    </table>

    {if $on_demand}
        <details class="travel-cron-jobs__ondemand">
            <summary>{__("travel_core.jobs_on_demand_title", ["[n]" => $on_demand|count])}</summary>
            {if $on_demand_intro}<p class="muted">{$on_demand_intro}</p>{/if}
            <table class="table table-middle travel-cron-table travel-cron-jobs__table">
                <tbody>
                    {foreach from=$on_demand item=job}
                        {include file="addons/travel_core/components/job_row.tpl" job=$job has_key=$has_key run_dispatch=$run_dispatch row_extra=$row_extra|default:""}
                    {/foreach}
                </tbody>
            </table>
        </details>
    {/if}
</section>
{script src="js/addons/travel_core/cron-jobs.js"}
