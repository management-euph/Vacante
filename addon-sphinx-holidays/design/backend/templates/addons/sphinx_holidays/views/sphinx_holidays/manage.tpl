{capture name="mainbox"}

<div class="travel-admin-panel">

    {* API Configuration Warning *}
    {if !$is_configured}
        <div class="alert alert-warning">
            <i class="icon-warning-sign"></i>
            {__("sphinx_holidays.api_not_configured")}
        </div>
    {/if}

    {* ── Sync controls: each job's Run, in Scheduled jobs below (a log window
       instead of a page that waits for the sync). Circuits and Package routes
       keep their own Sync buttons on their pages. ── *}
    {if !$selected_countries}
        <div class="travel-update-section">
            <span class="text-warning">
                <i class="icon-warning-sign"></i> {__("sphinx_holidays.no_sync_targets")}
            </span>
            <a href="{"sphinx_holidays.whitelist"|fn_url}" class="btn btn-micro">
                <i class="icon-cog"></i> {__("sphinx_holidays.destination_whitelist")}
            </a>
        </div>
    {/if}

    {* ── Destinations: what we sell, per country (Travel Core's shared card) ── *}
    {if $sphinx_dest_card}
        {include file="addons/travel_core/components/destinations_card.tpl" card=$sphinx_dest_card}
    {/if}

    {* ── Hotel availability: its hotels per state (Travel Core's shared card) ── *}
    {if $sphinx_availability_card}
        {include file="addons/travel_core/components/availability_card.tpl" card=$sphinx_availability_card}
    {/if}

    {* ── Destination Stats ── *}
    <h4>{__("sphinx_holidays.destinations")}</h4>
    <div class="sync-stats">
        <div class="stat-card info">
            <div class="stat-value">{$total_destinations|default:0}</div>
            <div class="stat-label">{__("sphinx_holidays.total_destinations")}</div>
        </div>
        <div class="stat-card">
            <div class="stat-value">{$counts_by_type.continent|default:0}</div>
            <div class="stat-label">{__("sphinx_holidays.continents")}</div>
        </div>
        <div class="stat-card success">
            <div class="stat-value">{$counts_by_type.country|default:0}</div>
            <div class="stat-label">{__("sphinx_holidays.countries")}</div>
        </div>
        <div class="stat-card">
            <div class="stat-value">{$counts_by_type.region|default:0}</div>
            <div class="stat-label">{__("sphinx_holidays.regions")}</div>
        </div>
        <div class="stat-card warning">
            <div class="stat-value">{math equation="x+y" x=$counts_by_type.city|default:0 y=$counts_by_type.destination|default:0}</div>
            <div class="stat-label">{__("sphinx_holidays.cities")}</div>
        </div>
    </div>

    {if $dest_last_synced}
        <p class="muted">{__("sphinx_holidays.last_synced")}: {$dest_last_synced}</p>
    {/if}

    {* ── Hotel Stats ── *}
    <h4>{__("sphinx_holidays.hotels")}</h4>
    <div class="sync-stats">
        <div class="stat-card info">
            <div class="stat-value">{$total_hotels|default:0}</div>
            <div class="stat-label">{__("sphinx_holidays.total_hotels")}</div>
        </div>
        {foreach from=$hotels_by_country key=cc item=cnt name=hbc}
            {if $smarty.foreach.hbc.index < 5}
            <div class="stat-card">
                <div class="stat-value">{$cnt}</div>
                <div class="stat-label">{$cc}</div>
            </div>
            {/if}
        {/foreach}
    </div>

    {if $hotel_last_synced}
        <p class="muted">{__("sphinx_holidays.last_synced")}: {$hotel_last_synced}</p>
    {else}
        <p class="muted">{__("sphinx_holidays.never_synced")}</p>
    {/if}

    {* ── Product Stats ── *}
    <h4>{__("sphinx_holidays.products")}</h4>
    <div class="sync-stats">
        <div class="stat-card success">
            <div class="stat-value">{$linked_products|default:0}</div>
            <div class="stat-label">{__("sphinx_holidays.linked_products")}</div>
        </div>
        <div class="stat-card warning">
            <div class="stat-value">{$unlinked_hotels|default:0}</div>
            <div class="stat-label">{__("sphinx_holidays.unlinked_hotels")}</div>
        </div>
        {if $waiting_hotels > 0}
        <div class="stat-card">
            <div class="stat-value"><a href="{"sphinx_holidays.hotels?availability=waiting"|fn_url}">{$waiting_hotels}</a></div>
            <div class="stat-label">{__("sphinx_holidays.availability_waiting")}</div>
        </div>
        {/if}
        {if $skipped_hotels > 0}
        <div class="stat-card" style="border-top: 3px solid #dc3545;">
            <div class="stat-value" style="color: #dc3545;">{$skipped_hotels}</div>
            <div class="stat-label">{__("sphinx_holidays.skipped_hotels")}</div>
        </div>
        {/if}
    </div>

    {if $unlinked_hotels > 0}
        <form action="{""|fn_url}" method="post" style="display:inline;">
            <input type="hidden" name="dispatch" value="sphinx_holidays.add_products" />
            <button type="submit" class="btn btn-primary"
                    onclick="return confirm('{__("sphinx_holidays.create_products_confirm")|escape:javascript}');">
                <i class="icon-plus"></i> {__("sphinx_holidays.create_products")}
            </button>
        </form>
    {/if}
    {if $skipped_hotels > 0}
        <form action="{""|fn_url}" method="post" style="display:inline; margin-left: 8px;">
            <input type="hidden" name="dispatch" value="sphinx_holidays.retry_skipped" />
            <button type="submit" class="btn btn-warning"
                    onclick="return confirm('{__("sphinx_holidays.retry_skipped_confirm")|escape:javascript}');">
                <i class="icon-refresh"></i> {__("sphinx_holidays.retry_skipped")}
            </button>
        </form>
    {/if}
    {* Gated on products with NO hotel row linked — the breakage relink
       repairs (it re-fetches each hotel from the API and re-inserts it
       linked). The old gate used the orphan count (hotels whose product was
       deleted), which relink cannot fix, so the button stayed hidden exactly
       when it was needed. POST-only by design: the action mutates. *}
    {if $unlinked_sphinx_products > 0}
        <form action="{""|fn_url}" method="post" style="display:inline; margin-left: 8px;">
            <input type="hidden" name="dispatch" value="sphinx_holidays.relink_products" />
            <button type="submit" class="btn btn-info" {if !$is_configured}disabled{/if}
                    onclick="return confirm('{__("sphinx_holidays.relink_confirm")|escape:javascript}');">
                <i class="icon-link"></i> {__("sphinx_holidays.relink_existing_products")} ({$unlinked_sphinx_products})
            </button>
        </form>
    {/if}

    {* ── Browse Links ── *}
    <div class="travel-action-buttons">
        {if $total_destinations > 0}
            <a href="{"sphinx_holidays.destinations"|fn_url}" class="btn">
                <i class="icon-globe"></i> {__("sphinx_holidays.destinations")}
            </a>
        {/if}
        {if $total_hotels > 0}
            <a href="{"sphinx_holidays.hotels"|fn_url}" class="btn">
                <i class="icon-building"></i> {__("sphinx_holidays.hotels")}
            </a>
        {/if}
        {* Always reachable: a store whose hotel rows were lost shows 0
           unlinked here only until the audit page counts them. *}
        <a href="{"sphinx_holidays.product_links"|fn_url}" class="btn">
            <i class="icon-link"></i> {__("sphinx_holidays.product_links")}{if $unlinked_sphinx_products > 0} ({$unlinked_sphinx_products}){/if}
        </a>
    </div>

    {** Scheduled jobs: Travel Core's shared card, from CronPlan's rows.
       card_id is the target of the "Open Sphinx cron" link on Travel Core ->
       Tools. No command with the cron key is ever printed: the ⋯ menus copy
       the real ones, and Run posts to sphinx_holidays.run_job. **}
    {include file="addons/travel_core/components/jobs_card.tpl"
        card_id="sphinx-cron-commands"
        stages=$sphinx_job_stages
        on_demand=$sphinx_on_demand_jobs
        on_demand_intro=__("sphinx_holidays.jobs_on_demand_intro")
        has_key=$sphinx_cron_has_key
        run_dispatch="sphinx_holidays.run_job"
    }

    {* Queue images for one scope, and the one-hotel checks: runs with a few
       choices, through the same run action. *}
    {include file="addons/sphinx_holidays/components/image_scope.tpl"}
    {include file="addons/sphinx_holidays/components/check_hotel.tpl"}

    {if $sphinx_cron_has_key}
        {** Every job's command (Travel Core's shared card). Shown masked; the
           real text rides only in data-* for Copy. **}
        {include file="addons/travel_core/components/commands_card.tpl"
            card_id="sphinx-crontab"
            cli=$sphinx_crontab_cli
            url=$sphinx_crontab_url
            masked=$sphinx_crontab_masked
            key=$sphinx_cron_key
        }
    {/if}

    {* ── Recent Sync Logs ── *}
    {if $sync_logs}
        <h4>{__("sphinx_holidays.sync_log")}</h4>
        {foreach from=$sync_logs item=log}
            <div class="sync-log-entry">
                <div class="sync-log-header">
                    <span class="sync-log-date">{$log.started_at}</span>
                    <span class="status-badge status-{if $log.status == 'completed'}ok{elseif $log.status == 'failed'}cancelled{else}pending{/if}">
                        {$log.sync_type|escape:html}: {$log.status|escape:html}
                    </span>
                    {if $log.sync_mode == 'incremental'}
                        <span class="status-badge status-ok">{__("sphinx_holidays.sync_mode_incremental")}</span>
                    {elseif $log.sync_mode == 'full'}
                        <span class="status-badge">{__("sphinx_holidays.sync_mode_full")}</span>
                    {/if}
                </div>
                <div class="sync-log-stats">
                    <span>{__("sphinx_holidays.items_synced")}: {$log.items_synced}/{$log.items_total}</span>
                    {if $log.duration_ms}
                        <span>{__("sphinx_holidays.duration")}: {($log.duration_ms/1000)|string_format:"%.1f"}s</span>
                    {/if}
                    {if $log.rate_limit_hits > 0}
                        <span class="text-warning">{__("sphinx_holidays.rate_limited_requests")}: {$log.rate_limit_hits}</span>
                    {/if}
                    {if $log.error_message}
                        <span class="text-error">{$log.error_message|escape:html|truncate:100}</span>
                    {/if}
                </div>
            </div>
        {/foreach}
    {/if}

</div>

{/capture}

{capture name="buttons"}{/capture}

{include file="common/mainbox.tpl" title=__("sphinx_holidays.sphinx_dashboard") content=$smarty.capture.mainbox
    buttons=$smarty.capture.buttons}
