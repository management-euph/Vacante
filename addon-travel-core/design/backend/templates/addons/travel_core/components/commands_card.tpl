{** Every scheduled job's command, for the server crontab (CLI) or a cron
   service (URL). Shared by the providers' dashboards.

   Params:
     card_id  the section's id
     cli      CronJobPlan::crontab($modes, 'cli') — the REAL text
     url      CronJobPlan::crontab($modes, 'url') — the REAL text
     masked   the CLI text with the key masked, as shown on load
     key      the cron key, so cron-jobs.js masks the URL form too
     plans    for an add-on with more than one way to schedule its jobs
              (optional): a list of {key, label, hint, cli, url, masked}, in
              the order the switch offers them, the first one shown on load.
              With plans, cli, url and masked come from them. key is a short
              [a-z0-9_] name; hint, one sentence on when to pick it.
     extra    HTML the add-on adds at the end of the card (optional), printed
              as HTML: the add-on escapes what it puts in it

   The key is masked in the DISPLAY only: a masked command pasted into a
   crontab is a refused run at 04:30 nobody is awake to see, so Copy all
   copies the real text (cron-jobs.js). The real text rides only in data-*. **}
{$_plan_key = ""}
{if $plans}
    {foreach from=$plans item=_plan name=plans}
        {if $smarty.foreach.plans.first}
            {$_plan_key = $_plan.key}
            {$masked = $_plan.masked}
        {/if}
    {/foreach}
{/if}
<section id="{$card_id}" class="travel-cron-card travel-cron-commands" aria-labelledby="{$card_id}-title"
         data-travel-cron-commands
{if $plans}
         data-plan="{$_plan_key|escape:html}"
    {foreach from=$plans item=_plan}
         data-crontab-{$_plan.key|escape:html}-cli="{$_plan.cli|escape:html}"
         data-crontab-{$_plan.key|escape:html}-url="{$_plan.url|escape:html}"
    {/foreach}
{else}
         data-crontab-cli="{$cli|escape:html}"
         data-crontab-url="{$url|escape:html}"
{/if}
         data-key="{$key|escape:html}"
         data-txt-copied="{__("travel_core.jobs_copied")|escape:html}"
         data-txt-copy-failed="{__("travel_core.jobs_copy_failed")|escape:html}">
    <div class="travel-cron-card__head">
        <div>
            <h3 id="{$card_id}-title">{__("travel_core.jobs_commands_title")}</h3>
            <p class="muted">{__("travel_core.jobs_commands_hint")}</p>
        </div>
        <div class="travel-cron-commands__tools">
            <div class="btn-group" role="group" aria-label="{__("travel_core.jobs_format_label")|escape:html}">
                <button type="button" class="btn" data-travel-cron-format="cli" aria-pressed="true">{__("travel_core.jobs_format_cli")}</button>
                <button type="button" class="btn" data-travel-cron-format="url" aria-pressed="false">{__("travel_core.jobs_format_url")}</button>
            </div>
            <button type="button" class="btn btn-primary" data-travel-cron-copy-all>{__("travel_core.jobs_copy_all")}</button>
        </div>
    </div>
    {if $plans}
        {** Its own row, under the heading: a plan's name can be long, and here
           the buttons wrap instead of running over each other. Installing
           two plans runs every job twice, so the card shows one at a time. **}
        <div class="travel-cron-commands__plans" role="group" aria-labelledby="{$card_id}-plans">
            <span id="{$card_id}-plans" class="travel-cron-commands__plans-label">{__("travel_core.jobs_plan_label")}</span>
            {foreach from=$plans item=_plan name=plans}
                <button type="button" class="btn" data-travel-cron-plan="{$_plan.key|escape:html}" aria-pressed="{if $smarty.foreach.plans.first}true{else}false{/if}">{$_plan.label|escape:html}</button>
            {/foreach}
        </div>
        {foreach from=$plans item=_plan name=plans}
            {if $_plan.hint}
                <p class="muted travel-cron-commands__plan-hint" data-travel-cron-plan-hint="{$_plan.key|escape:html}"{if !$smarty.foreach.plans.first} hidden{/if}>{$_plan.hint|escape:html}</p>
            {/if}
        {/foreach}
    {/if}
    <pre class="travel-cron-commands__text" data-travel-cron-text>{$masked|escape:html}</pre>
    <p class="travel-cron-foot travel-cron-commands__note">
        <i class="icon-lock" aria-hidden="true"></i> {__("travel_core.jobs_key_hidden_note")}
        <a href="{"travel_tools.manage"|fn_url}">{__("travel_core.jobs_open_tools")}</a>
    </p>
    {** nofilter: CS-Cart escapes every {$…} it prints, and escaped, the
       add-on's block showed as markup, with the real key in its data-copy
       in plain sight (Novoton's XML feed). **}
    {if $extra}{$extra nofilter}{/if}
</section>
{script src="js/addons/travel_core/cron-jobs.js"}
