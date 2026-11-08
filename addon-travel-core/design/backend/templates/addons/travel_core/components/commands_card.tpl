{** Every scheduled job's command, for the server crontab (CLI) or a cron
   service (URL). Shared by the providers' dashboards.

   Params:
     card_id  the section's id
     cli      CronJobPlan::crontab($modes, 'cli') — the REAL text
     url      CronJobPlan::crontab($modes, 'url') — the REAL text
     masked   the CLI text with the key masked, as shown on load
     key      the cron key, so cron-jobs.js masks the URL form too
     extra    HTML the add-on adds at the end of the card (optional)

   The key is masked in the DISPLAY only: a masked command pasted into a
   crontab is a refused run at 04:30 nobody is awake to see, so Copy all
   copies the real text (cron-jobs.js). The real text rides only in data-*. **}
<section id="{$card_id}" class="travel-cron-card travel-cron-commands" aria-labelledby="{$card_id}-title"
         data-travel-cron-commands
         data-crontab-cli="{$cli|escape:html}"
         data-crontab-url="{$url|escape:html}"
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
    <pre class="travel-cron-commands__text" data-travel-cron-text>{$masked|escape:html}</pre>
    <p class="travel-cron-foot travel-cron-commands__note">
        <i class="icon-lock" aria-hidden="true"></i> {__("travel_core.jobs_key_hidden_note")}
        <a href="{"travel_tools.manage"|fn_url}">{__("travel_core.jobs_open_tools")}</a>
    </p>
    {if $extra}{$extra}{/if}
</section>
{script src="js/addons/travel_core/cron-jobs.js"}
