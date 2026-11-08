{** Queue images for a scope: one run of sync_images for fewer hotels, through
   sphinx_holidays.run_job, which takes only these fields (CronPlan::runParams).

   It replaces the four scoped rows of the old Image sync table, whose URLs
   carried the cron key and ran with sample values (country=HR, destination
   1234, region 5678). Run opens the log in a new window (cron-jobs.js, loaded
   by the jobs card); a crontab entry for a scope is Queue images' URL plus
   its parameter (the hint says which). **}
<section id="sphinx-image-scope" class="travel-cron-card" aria-labelledby="sphinx-image-scope-title">
    <div class="travel-cron-card__head">
        <div>
            <h3 id="sphinx-image-scope-title">{__("sphinx_holidays.jobs_scope_title")}</h3>
            <p class="muted">{__("sphinx_holidays.jobs_scope_intro")}</p>
        </div>
    </div>
    <form action="{""|fn_url}" method="post" target="_blank" class="travel-cron-run-form travel-cron-form">
        <input type="hidden" name="dispatch" value="sphinx_holidays.run_job" />
        <input type="hidden" name="security_hash" value="{$security_hash}" />
        <input type="hidden" name="job" value="sync_images" />
        <div class="travel-cron-form__field">
            <label for="sphinx-scope-which">{__("sphinx_holidays.jobs_scope_which")}</label>
            <select id="sphinx-scope-which" name="scope">
                <option value="all">{__("sphinx_holidays.jobs_scope_all")}</option>
                <option value="strict">{__("sphinx_holidays.jobs_scope_strict")}</option>
                <option value="country">{__("sphinx_holidays.jobs_scope_country")}</option>
                <option value="destination">{__("sphinx_holidays.jobs_scope_destination")}</option>
                <option value="region">{__("sphinx_holidays.jobs_scope_region")}</option>
            </select>
        </div>
        <div class="travel-cron-form__field">
            <label for="sphinx-scope-country">{__("sphinx_holidays.jobs_scope_country_label")}</label>
            <select id="sphinx-scope-country" name="country">
                {foreach from=$sphinx_scope_countries item=_country}
                    <option value="{$_country.code|escape:html}">{$_country.name|escape:html}</option>
                {/foreach}
            </select>
        </div>
        <div class="travel-cron-form__field">
            <label for="sphinx-scope-id">{__("sphinx_holidays.jobs_scope_id")}</label>
            <input type="number" id="sphinx-scope-id" name="scope_id" min="1" step="1" class="input-small" />
        </div>
        <div class="travel-cron-form__field">
            <label for="sphinx-scope-limit">{__("sphinx_holidays.jobs_scope_limit")}</label>
            <input type="number" id="sphinx-scope-limit" name="limit" min="1" step="1" class="input-small" />
        </div>
        <label class="travel-cron-form__check"><input type="checkbox" name="force" value="Y" /> {__("sphinx_holidays.jobs_scope_force")}</label>
        <div class="travel-cron-form__actions">
            <button type="submit" class="btn btn-primary">{__("travel_core.jobs_run")}</button>
        </div>
    </form>
    <p class="travel-cron-hint">{__("sphinx_holidays.jobs_scope_schedule_hint")}</p>

    <details class="sphinx-image-flow">
        <summary>{__("sphinx_holidays.jobs_images_how")}</summary>
        <ol>
            <li>{__("sphinx_holidays.jobs_images_step_enrich")}</li>
            <li>{__("sphinx_holidays.jobs_images_step_queue")}</li>
            <li>{__("sphinx_holidays.jobs_images_step_download")}</li>
        </ol>
    </details>
</section>
