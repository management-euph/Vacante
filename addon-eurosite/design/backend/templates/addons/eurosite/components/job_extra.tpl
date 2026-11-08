{** Eurosite's part of a job's row on Travel Core's jobs card (its row_extra),
   under the job's name and description: what is Eurosite's own.

   Params: job (a CronPlanBuilder row, with count and count_key from
   CronPlanBuilder::withCounts()). Reads $eurosite_counts for the whitelist.

     - how big the job's catalog is (count, null for a job without one);
     - the hotel list, for the jobs that fill or read it, and the
       availability probe beside the availability check;
     - why a job has nothing to do, where the page can tell: no Immediate
       hotel on the configured dates, or no destination whitelisted. **}
{if isset($job.count)}
    <div class="travel-cron-hint">{__($job.count_key, ["[n]" => $job.count])}</div>
{/if}
{if $job.mode == "hotels" || $job.mode == "availability" || $job.mode == "add_products"}
    <div class="travel-cron-hint">
        <a href="{"eurosite.hotels"|fn_url}">{__("eurosite.open_hotel_list")} &rarr;</a>
        {if $job.mode == "availability"}
            &middot; <a href="#eurosite-probe">{__("eurosite.probe_title")} &rarr;</a>
        {/if}
    </div>
{/if}
{if $job.mode == "availability" && isset($job.count) && $job.count == 0}
    {* The configured dates found no Immediate hotel: the probe says which dates would. *}
    <div class="travel-cron-hint travel-cron-hint--warn">{__("eurosite.probe_none_hint")}</div>
{/if}
{if $job.mode == "hotels" && $eurosite_counts.whitelist == 0}
    {* This job reads the whitelist, and the whitelist is empty. *}
    <div class="travel-cron-hint travel-cron-hint--warn">
        {__("eurosite.hotels_need_whitelist")}
        <a href="{"eurosite.whitelist"|fn_url}">{__("eurosite.open_whitelist")}</a>
    </div>
{/if}
