{** Check one hotel: the four one-hotel checks through sphinx_holidays.run_job,
   which takes only these fields (CronPlan::runParams). It replaces the
   Diagnostic commands table, whose URLs carried the cron key and hotel 89812.

   The pressed button names the check: cron-jobs.js sends it along (a plain
   submit does too). The two boxes that make a check write ask first, each
   only for its own check. **}
<section id="sphinx-check-hotel" class="travel-cron-card" aria-labelledby="sphinx-check-hotel-title">
    <div class="travel-cron-card__head">
        <div>
            <h3 id="sphinx-check-hotel-title">{__("sphinx_holidays.jobs_check_title")}</h3>
            <p class="muted">{__("sphinx_holidays.jobs_check_intro")}</p>
        </div>
    </div>
    <form action="{""|fn_url}" method="post" target="_blank" class="travel-cron-run-form travel-cron-form">
        <input type="hidden" name="dispatch" value="sphinx_holidays.run_job" />
        <input type="hidden" name="security_hash" value="{$security_hash}" />
        <div class="travel-cron-form__field">
            <label for="sphinx-check-hotel-input">{__("sphinx_holidays.jobs_check_hotel")}</label>
            <input type="text" id="sphinx-check-hotel-input" name="hotel" required="required" placeholder="89812" />
        </div>
        <div class="travel-cron-form__actions">
            <button type="submit" name="job" value="diagnose_search" class="btn">{__("sphinx_holidays.jobs_check_search")}</button>
            <button type="submit" name="job" value="diagnose_images" class="btn">{__("sphinx_holidays.jobs_check_images")}</button>
            <button type="submit" name="job" value="diagnose_seo" class="btn">{__("sphinx_holidays.jobs_check_seo")}</button>
            <button type="submit" name="job" value="diagnose_product_features" class="btn">{__("sphinx_holidays.jobs_check_features")}</button>
        </div>
        <span class="travel-cron-form__break"></span>
        <label class="travel-cron-form__check"><input type="checkbox" name="attach" value="Y" data-travel-cron-confirm="{__("sphinx_holidays.jobs_check_attach_confirm")|escape:html}" data-travel-cron-confirm-for="diagnose_images" /> {__("sphinx_holidays.jobs_check_attach")}</label>
        <label class="travel-cron-form__check"><input type="checkbox" name="apply" value="Y" data-travel-cron-confirm="{__("sphinx_holidays.jobs_check_apply_confirm")|escape:html}" data-travel-cron-confirm-for="diagnose_seo" /> {__("sphinx_holidays.jobs_check_apply")}</label>
    </form>
    <p class="travel-cron-hint">{__("sphinx_holidays.jobs_check_hotel_hint")}</p>
</section>
