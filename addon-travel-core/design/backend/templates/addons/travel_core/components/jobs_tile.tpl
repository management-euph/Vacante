{** The scheduled jobs' tile in a provider's At a glance row: how many jobs
   are failing, late or have never run (Cron\JobHealth::counts()), red when
   one failed or stalled, amber when one is late or never ran, and a link down
   to the jobs card. Shared by the providers' dashboards, so a job's state
   reads the same on each.

   Params: health (JobHealth::counts()), card_id (the jobs card's id). **}
<div class="travel-cron-tile{if $health.tone == "bad"} travel-cron-tile--bad{elseif $health.tone == "warn"} travel-cron-tile--warn{/if}">
    <div class="travel-cron-tile__label">{__("travel_core.jobs_title")}</div>
    <div class="travel-cron-tile__value">
        {if $health.counts.failed + $health.counts.stalled > 0}
            {__("travel_core.jobs_tile_failing", ["[n]" => $health.counts.failed + $health.counts.stalled])}
        {elseif $health.counts.late > 0}
            {__("travel_core.jobs_tile_late", ["[n]" => $health.counts.late])}
        {elseif $health.counts.never > 0}
            {__("travel_core.jobs_tile_never", ["[n]" => $health.counts.never])}
        {else}
            {__("travel_core.jobs_tile_ok")}
        {/if}
    </div>
    <div class="travel-cron-tile__note">
        {__("travel_core.jobs_tile_note", ["[ok]" => $health.counts.ok, "[late]" => $health.counts.late, "[never]" => $health.counts.never])}
        <br><a href="#{$card_id|escape:html}">{__("travel_core.jobs_tile_open")}</a>
    </div>
</div>
