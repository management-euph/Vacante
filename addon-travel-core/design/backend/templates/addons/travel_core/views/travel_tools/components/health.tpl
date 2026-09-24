{** One add-on's cron health, as CronHealth::assess() judged it.
    Params: $health (the assessment), $lists (failed_list / stalled_list / running_list).
    Every state is told by icon AND words AND a lightness difference, never by colour alone. **}
{if $health.state == "refused"}
    <span class="travel-cron-pill travel-cron-pill--bad"><i class="icon-remove" aria-hidden="true"></i> {__("travel_core.tools_health_refused")}</span>
    <div class="travel-cron-hint travel-cron-hint--bad">{__("travel_core.tools_health_refused_hint")}</div>
{elseif $health.state == "failed"}
    <span class="travel-cron-pill travel-cron-pill--bad"><i class="icon-remove" aria-hidden="true"></i> {__("travel_core.tools_health_failed")}</span>
    <div class="travel-cron-hint travel-cron-hint--bad">{__("travel_core.tools_health_failed_hint", ["[modes]" => $lists.failed_list|escape:html])}</div>
{elseif $health.state == "stalled"}
    <span class="travel-cron-pill travel-cron-pill--warn"><i class="icon-warning-sign" aria-hidden="true"></i> {__("travel_core.tools_health_stalled")}</span>
    <div class="travel-cron-hint travel-cron-hint--warn">{__("travel_core.tools_health_stalled_hint", ["[modes]" => $lists.stalled_list|escape:html])}</div>
{elseif $health.state == "quiet"}
    <span class="travel-cron-pill travel-cron-pill--warn"><i class="icon-warning-sign" aria-hidden="true"></i> {__("travel_core.tools_health_quiet")}</span>
    <div class="travel-cron-hint travel-cron-hint--warn">{__("travel_core.tools_health_quiet_hint")}</div>
{elseif $health.state == "never"}
    <span class="travel-cron-pill travel-cron-pill--none"><i class="icon-minus" aria-hidden="true"></i> {__("travel_core.tools_health_never")}</span>
    <div class="travel-cron-hint">{__("travel_core.tools_health_never_hint")}</div>
{else}
    <span class="travel-cron-pill travel-cron-pill--ok"><i class="icon-ok" aria-hidden="true"></i> {__("travel_core.tools_health_ok")}</span>
{/if}
{if $lists.running_list}
    <div class="travel-cron-hint">{__("travel_core.tools_health_running", ["[modes]" => $lists.running_list|escape:html])}</div>
{/if}
