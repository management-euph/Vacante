{** Sphinx Holidays dashboard.

   Top to bottom: at a glance, what needs attention, the tools, the
   destinations sold (Travel Core's shared cards; picked on the Destination
   whitelist page), the scheduled jobs in the order they run with Queue
   images for a scope, every command, recent sync activity (paged). Built on Travel
   Core's travel-cron-* styles, as Novoton's dashboard is, so the providers'
   dashboards read alike. No inline scripts: the jobs card loads Travel
   Core's cron-jobs.js inside this capture, and it serves every Run here. **}

{capture name="mainbox"}

<div class="sphinx-dashboard travel-cron">

    {if !$is_configured}
        <div class="alert alert-warning">
            <i class="icon-warning-sign"></i>
            {__("sphinx_holidays.api_not_configured")}
        </div>
    {/if}
    {if !$selected_countries}
        <div class="alert alert-warning">
            <i class="icon-warning-sign"></i> {__("sphinx_holidays.no_sync_targets")}
            <a href="{"sphinx_holidays.whitelist"|fn_url}" class="btn btn-micro">
                <i class="icon-cog"></i> {__("sphinx_holidays.destination_whitelist")}
            </a>
        </div>
    {/if}

    {$_date = "`$settings.Appearance.date_format`, `$settings.Appearance.time_format`"}

    {** At a glance: each tile says whether its figure is a problem
       (Services\DashboardSummary::glance). They replace eleven coloured
       numbers whose colours meant nothing. **}
    {$_g = $sphinx_glance}
    <div class="travel-cron-tiles travel-cron-tiles--four">
        <div class="travel-cron-tile{if $_g.hotels.warn} travel-cron-tile--warn{/if}">
            <div class="travel-cron-tile__label">{__("sphinx_holidays.dash_tile_hotels")}</div>
            <div class="travel-cron-tile__value">{$_g.hotels.in_scope|number_format}</div>
            <div class="travel-cron-tile__note">
                {if $_g.hotels.where}{$_g.hotels.where|escape:html} · {/if}{__("sphinx_holidays.dash_tile_hotels_cities", ["[n]" => $_g.hotels.cities|number_format])}
                <br>{if $hotel_last_synced}{__("sphinx_holidays.dash_tile_hotels_synced", ["[date]" => $hotel_last_synced|date_format:$_date])}{else}{__("sphinx_holidays.never_synced")}{/if}
                <br><a href="{"sphinx_holidays.hotels"|fn_url}">{__("sphinx_holidays.dash_tile_hotels_open")}</a>
            </div>
        </div>
        <div class="travel-cron-tile">
            <div class="travel-cron-tile__label">{__("sphinx_holidays.dash_tile_products")}</div>
            <div class="travel-cron-tile__value">{$_g.products.linked|number_format}</div>
            <div class="travel-cron-tile__note">
                {__("sphinx_holidays.dash_tile_products_note", ["[n]" => $_g.products.live|number_format])}
                <br><a href="{"sphinx_holidays.hotels?link_status=linked"|fn_url}">{__("sphinx_holidays.dash_tile_products_open")}</a>
            </div>
        </div>
        <div class="travel-cron-tile{if $_g.unlinked.warn} travel-cron-tile--warn{/if}">
            <div class="travel-cron-tile__label">{__("sphinx_holidays.dash_tile_unlinked")}</div>
            <div class="travel-cron-tile__value">{__("sphinx_holidays.dash_tile_unlinked_value", ["[n]" => $_g.unlinked.count|number_format, "[total]" => $_g.unlinked.total|number_format])}</div>
            <div class="travel-cron-tile__note">
                <a href="{"sphinx_holidays.hotels?availability=waiting"|fn_url}">{__("sphinx_holidays.dash_tile_unlinked_waiting", ["[n]" => $_g.unlinked.waiting|number_format])}</a>
                · {if $_g.unlinked.warn}<strong class="travel-cron-hint--warn">{/if}{__("sphinx_holidays.dash_tile_unlinked_skipped", ["[n]" => $_g.unlinked.skipped|number_format])}{if $_g.unlinked.warn}</strong>{/if}
                {if $_g.unlinked.ready > 0}· {__("sphinx_holidays.dash_tile_unlinked_ready", ["[n]" => $_g.unlinked.ready|number_format])}{/if}
                <br><a href="{"sphinx_holidays.hotels?link_status=orphan"|fn_url}">{__("sphinx_holidays.dash_tile_unlinked_open")}</a>
            </div>
        </div>
        {include file="addons/travel_core/components/jobs_tile.tpl" health=$_g.jobs card_id="sphinx-cron-commands"}
    </div>

    {** Needs attention: only real problems, each with the one action that
       fixes it (Services\DashboardSummary::attention). **}
    {if $sphinx_attention}
        <section class="travel-cron-card travel-cron-attention" aria-labelledby="sphinx-attention-title">
            <h3 id="sphinx-attention-title">{__("sphinx_holidays.dash_attention_title", ["[n]" => $sphinx_attention|count])}</h3>
            <ul class="travel-cron-attention__list">
                {foreach from=$sphinx_attention item=item}
                    {$_at = $item.at|date_format:$_date}
                    <li class="travel-cron-attention__item">
                        {if $item.kind == "refused"}
                            <span class="travel-cron-attention__text">{__("sphinx_holidays.dash_attention_refused", ["[date]" => $_at])}</span>
                            <a class="btn btn-primary" href="#sphinx-crontab">{__("sphinx_holidays.dash_attention_copy_commands")}</a>
                        {elseif $item.kind == "skipped"}
                            <span class="travel-cron-attention__text">{__("sphinx_holidays.dash_attention_skipped", ["[n]" => $item.n|number_format])}</span>
                            <form action="{""|fn_url}" method="post" class="travel-cron-tools__form">
                                <input type="hidden" name="dispatch" value="sphinx_holidays.retry_skipped" />
                                <input type="hidden" name="security_hash" value="{$security_hash}" />
                                <button type="submit" class="btn btn-primary cm-confirm" data-ca-confirm-text="{__("sphinx_holidays.retry_skipped_confirm")|escape:html}">{__("sphinx_holidays.retry_skipped")}</button>
                            </form>
                        {else}
                            {$_name = __($item.name_key)}
                            <span class="travel-cron-attention__text">
                                {if $item.kind == "failed"}
                                    {__("sphinx_holidays.dash_attention_failed", ["[job]" => $_name, "[date]" => $_at])}
                                    {if $item.error}<span class="travel-cron-hint--bad">{$item.error|escape:html}</span>{/if}
                                {elseif $item.kind == "stalled"}
                                    {__("sphinx_holidays.dash_attention_stalled", ["[job]" => $_name, "[date]" => $_at])}
                                {elseif $item.kind == "never"}
                                    {__("sphinx_holidays.dash_attention_never", ["[job]" => $_name])}
                                {else}
                                    {__("sphinx_holidays.dash_attention_late", ["[job]" => $_name, "[date]" => $_at, "[schedule]" => __($item.schedule_key, $item.schedule_params)])}
                                {/if}
                            </span>
                            {include file="addons/travel_core/components/run_job.tpl" run_dispatch="sphinx_holidays.run_job" job=$item.mode label=__("sphinx_holidays.dash_attention_run", ["[job]" => $_name]) class="btn btn-primary"}
                        {/if}
                    </li>
                {/foreach}
            </ul>
        </section>
    {/if}

    {** Tools. Syncing is each job's Run, under Scheduled jobs: the four Sync
       buttons that stood here went in step 2. **}
    <section class="travel-cron-card travel-cron-tools" aria-labelledby="sphinx-tools-title">
        <h3 id="sphinx-tools-title">{__("sphinx_holidays.dash_tools_title")}</h3>
        <p class="travel-cron-hint">{__("sphinx_holidays.dash_tools_intro")}</p>
        <div class="travel-cron-tools__groups travel-cron-tools__groups--two">
            <div class="travel-cron-tools__group" role="group" aria-labelledby="sphinx-tools-fix-title">
                <h4 id="sphinx-tools-fix-title">{__("sphinx_holidays.dash_tools_fix")}</h4>
                {if $skipped_hotels > 0}
                    <form action="{""|fn_url}" method="post" class="travel-cron-tools__form">
                        <input type="hidden" name="dispatch" value="sphinx_holidays.retry_skipped" />
                        <input type="hidden" name="security_hash" value="{$security_hash}" />
                        <button type="submit" class="btn cm-confirm" data-ca-confirm-text="{__("sphinx_holidays.retry_skipped_confirm")|escape:html}">
                            <i class="icon-refresh"></i> {__("sphinx_holidays.retry_skipped")} ({$skipped_hotels|number_format})
                        </button>
                    </form>
                {/if}
                {if $unlinked_hotels > 0}
                    <form action="{""|fn_url}" method="post" class="travel-cron-tools__form">
                        <input type="hidden" name="dispatch" value="sphinx_holidays.add_products" />
                        <input type="hidden" name="security_hash" value="{$security_hash}" />
                        <button type="submit" class="btn btn-primary cm-confirm" data-ca-confirm-text="{__("sphinx_holidays.create_products_confirm")|escape:html}">
                            <i class="icon-plus"></i> {__("sphinx_holidays.create_products")} ({$unlinked_hotels|number_format})
                        </button>
                    </form>
                {/if}
                {* Gated on products with NO hotel row linked — the breakage relink
                   repairs (it re-fetches each hotel from the API and re-inserts it
                   linked). The old gate used the orphan count (hotels whose product
                   was deleted), which relink cannot fix, so the button stayed hidden
                   exactly when it was needed. POST-only by design: the action mutates. *}
                {if $unlinked_sphinx_products > 0}
                    <form action="{""|fn_url}" method="post" class="travel-cron-tools__form">
                        <input type="hidden" name="dispatch" value="sphinx_holidays.relink_products" />
                        <input type="hidden" name="security_hash" value="{$security_hash}" />
                        <button type="submit" class="btn cm-confirm" {if !$is_configured}disabled{/if} data-ca-confirm-text="{__("sphinx_holidays.relink_confirm")|escape:html}">
                            <i class="icon-link"></i> {__("sphinx_holidays.relink_existing_products")} ({$unlinked_sphinx_products})
                        </button>
                    </form>
                {/if}
                {* Always reachable: a store whose hotel rows were lost shows 0
                   unlinked here only until the audit page counts them. *}
                <a href="{"sphinx_holidays.product_links"|fn_url}" class="btn">
                    <i class="icon-link"></i> {__("sphinx_holidays.product_links")}{if $unlinked_sphinx_products > 0} ({$unlinked_sphinx_products}){/if}
                </a>
                <p class="travel-cron-hint">{__("sphinx_holidays.dash_tools_fix_hint")}</p>
            </div>
            {include file="addons/sphinx_holidays/components/check_hotel.tpl"}
        </div>
    </section>

    {** Destinations: what we sell, per country (Travel Core's shared card),
       with Sphinx's whole catalogue on one line under it. **}
    {if $sphinx_dest_card}
        {include file="addons/travel_core/components/destinations_card.tpl" card=$sphinx_dest_card}
    {/if}
    {math equation="x+y" x=$counts_by_type.city|default:0 y=$counts_by_type.destination|default:0 assign="_catalog_cities"}
    <p class="travel-cron-hint">
        {__("sphinx_holidays.dest_catalog", [
            "[continents]" => $counts_by_type.continent|default:0|number_format,
            "[countries]" => $counts_by_type.country|default:0|number_format,
            "[regions]" => $counts_by_type.region|default:0|number_format,
            "[cities]" => $_catalog_cities|number_format
        ])}{if $dest_last_synced} · {__("sphinx_holidays.dest_last_synced", ["[at]" => $dest_last_synced|date_format:$_date])}{/if}
    </p>

    {** Hotel availability: its hotels per state (Travel Core's shared card). **}
    {if $sphinx_availability_card}
        {include file="addons/travel_core/components/availability_card.tpl" card=$sphinx_availability_card}
    {/if}

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

    {* Queue images for one scope: one run of sync_images, through the same
       run action. *}
    {include file="addons/sphinx_holidays/components/image_scope.tpl"}

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

    {** Recent sync activity (components/activity.tpl, its own template: the
       pagination pair wraps exactly one list). **}
    {include file="addons/sphinx_holidays/components/activity.tpl"}

</div>

{/capture}

{capture name="buttons"}{/capture}

{include file="common/mainbox.tpl" title=__("sphinx_holidays.sphinx_dashboard") content=$smarty.capture.mainbox
    buttons=$smarty.capture.buttons}
