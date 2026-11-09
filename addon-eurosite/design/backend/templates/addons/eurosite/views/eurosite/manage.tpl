{** Eurosite Touring dashboard.

   Top to bottom: at a glance, what needs attention, the tools (the API
   connection, the probe for dates with offers), the destinations sold and
   the hotels' availability (Travel Core's shared cards), the scheduled jobs
   in the order they run, every command, recent sync activity (paged),
   recent bookings. Built on Travel Core's travel-cron-* styles, as Sphinx's
   and Novoton's dashboards are, so the providers' dashboards read alike. No
   inline scripts: the jobs card loads Travel Core's cron-jobs.js inside this
   capture, and it serves every Run here. **}

{capture name="mainbox"}

<div class="eurosite-dashboard travel-cron">

    {if !$eurosite_is_configured}
        <div class="alert alert-warning">
            <i class="icon-warning-sign"></i>
            {__("eurosite.api_not_configured", ["[default]" => "Eurosite API credentials are not configured — enter them in the addon settings. All catalogs and searches will fail with error -1000 until then."])}
        </div>
    {/if}

    {$_date = "`$settings.Appearance.date_format`, `$settings.Appearance.time_format`"}

    {** At a glance: each tile says whether its figure is a problem
       (Services\DashboardSummary::glance). **}
    {$_g = $eurosite_glance}
    <div class="travel-cron-tiles travel-cron-tiles--four">
        <div class="travel-cron-tile{if $_g.hotels.warn} travel-cron-tile--warn{/if}">
            <div class="travel-cron-tile__label">{__("eurosite.dash_tile_hotels")}</div>
            <div class="travel-cron-tile__value">{$_g.hotels.listed|number_format}</div>
            <div class="travel-cron-tile__note">
                {if $_g.hotels.where}{$_g.hotels.where|escape:html} · {/if}{__("eurosite.dash_tile_hotels_cities", ["[n]" => $_g.hotels.cities|number_format])}
                <br>{if $_g.hotels.synced}{__("eurosite.dash_tile_hotels_synced", ["[date]" => $_g.hotels.synced|date_format:$_date])}{else}{__("eurosite.dash_tile_hotels_never")}{/if}
                <br><a href="{"eurosite.hotels"|fn_url}">{__("eurosite.open_hotel_list")}</a>
            </div>
        </div>
        <div class="travel-cron-tile{if $_g.offers.warn} travel-cron-tile--warn{/if}">
            <div class="travel-cron-tile__label">{__("eurosite.dash_tile_offers")}</div>
            <div class="travel-cron-tile__value">{$_g.offers.immediate|number_format}</div>
            <div class="travel-cron-tile__note">
                {__("eurosite.dash_tile_offers_note", ["[or]" => $_g.offers.on_request|number_format, "[st]" => $_g.offers.stop_sale|number_format, "[none]" => $_g.offers.no_offer|number_format])}
                {if $_g.offers.unchecked > 0}<br>{__("eurosite.dash_tile_offers_unchecked", ["[n]" => $_g.offers.unchecked|number_format])}{/if}
                <br>{if $_g.offers.checked}{__("eurosite.dash_tile_offers_checked", ["[date]" => $_g.offers.checked|date_format:$_date])}{else}{__("eurosite.dash_tile_offers_never")}{/if}
                <br><a href="{"eurosite.hotels?availability=IM"|fn_url}">{__("eurosite.dash_tile_offers_open")}</a>{if $_g.offers.warn} · <a href="#eurosite-probe">{__("eurosite.probe_title")}</a>{/if}
            </div>
        </div>
        <div class="travel-cron-tile">
            <div class="travel-cron-tile__label">{__("eurosite.dash_tile_products")}</div>
            <div class="travel-cron-tile__value">{$_g.products.linked|number_format}</div>
            <div class="travel-cron-tile__note">
                {__("eurosite.dash_tile_products_live", ["[n]" => $_g.products.live|number_format])}
                <br>{__("eurosite.dash_tile_products_hidden", ["[n]" => $_g.products.hidden|number_format])}
                {if $_g.products.waiting > 0}<br><a href="{"eurosite.hotels?availability=IM&product=no"|fn_url}">{__("eurosite.dash_tile_products_waiting", ["[n]" => $_g.products.waiting|number_format])}</a>{/if}
                <br><a href="{"eurosite.hotels?product=yes"|fn_url}">{__("eurosite.dash_tile_products_open")}</a>
            </div>
        </div>
        {$_jh = $_g.jobs}
        <div class="travel-cron-tile{if $_jh.tone == "bad"} travel-cron-tile--bad{elseif $_jh.tone == "warn"} travel-cron-tile--warn{/if}">
            <div class="travel-cron-tile__label">{__("travel_core.jobs_title")}</div>
            <div class="travel-cron-tile__value">
                {if $_jh.counts.failed + $_jh.counts.stalled > 0}
                    {__("eurosite.dash_tile_jobs_failing", ["[n]" => $_jh.counts.failed + $_jh.counts.stalled])}
                {elseif $_jh.counts.late > 0}
                    {__("eurosite.dash_tile_jobs_late", ["[n]" => $_jh.counts.late])}
                {elseif $_jh.counts.never > 0}
                    {__("eurosite.dash_tile_jobs_never", ["[n]" => $_jh.counts.never])}
                {else}
                    {__("eurosite.dash_tile_jobs_ok")}
                {/if}
            </div>
            <div class="travel-cron-tile__note">
                {__("eurosite.dash_tile_jobs_note", ["[ok]" => $_jh.counts.ok, "[late]" => $_jh.counts.late, "[never]" => $_jh.counts.never])}
                <br><a href="#eurosite-scheduled-jobs">{__("eurosite.dash_tile_jobs_open")}</a>
            </div>
        </div>
    </div>

    {** Needs attention: only real problems, each with the one action that
       fixes it (Services\DashboardSummary::attention). **}
    {if $eurosite_attention}
        <section class="travel-cron-card travel-cron-attention" aria-labelledby="eurosite-attention-title">
            <h3 id="eurosite-attention-title">{__("eurosite.dash_attention_title", ["[n]" => $eurosite_attention|count])}</h3>
            <ul class="travel-cron-attention__list">
                {foreach from=$eurosite_attention item=item}
                    {$_at = $item.at|date_format:$_date}
                    <li class="travel-cron-attention__item">
                        {if $item.kind == "refused"}
                            <span class="travel-cron-attention__text">{__("eurosite.dash_attention_refused", ["[date]" => $_at])}</span>
                            <a class="btn btn-primary" href="#eurosite-crontab">{__("eurosite.dash_attention_copy_commands")}</a>
                        {elseif $item.kind == "nothing_sold"}
                            <span class="travel-cron-attention__text">{__("eurosite.dash_attention_nothing_sold")}</span>
                            <a class="btn btn-primary" href="{"eurosite.whitelist"|fn_url}">{__("eurosite.open_whitelist")}</a>
                        {elseif $item.kind == "no_immediate"}
                            <span class="travel-cron-attention__text">{__("eurosite.dash_attention_no_immediate")}</span>
                            <a class="btn btn-primary" href="#eurosite-probe">{__("eurosite.probe_title")}</a>
                        {else}
                            {$_name = __($item.name_key)}
                            <span class="travel-cron-attention__text">
                                {if $item.kind == "failed"}
                                    {__("eurosite.dash_attention_failed", ["[job]" => $_name, "[date]" => $_at])}
                                    {if $item.error}<span class="travel-cron-hint--bad">{$item.error|escape:html}</span>{/if}
                                {elseif $item.kind == "stalled"}
                                    {__("eurosite.dash_attention_stalled", ["[job]" => $_name, "[date]" => $_at])}
                                {elseif $item.kind == "never"}
                                    {__("eurosite.dash_attention_never", ["[job]" => $_name])}
                                {else}
                                    {__("eurosite.dash_attention_late", ["[job]" => $_name, "[date]" => $_at, "[schedule]" => __($item.schedule_key, $item.schedule_params)])}
                                {/if}
                            </span>
                            {include file="addons/travel_core/components/run_job.tpl" run_dispatch="eurosite.run_job" job=$item.mode label=__("eurosite.dash_attention_run", ["[job]" => $_name]) class="btn btn-primary"}
                        {/if}
                    </li>
                {/foreach}
            </ul>
        </section>
    {/if}

    {** Tools: the API connection, and the probe for dates with Immediate
       offers. Syncing is each job's Run, under Scheduled jobs. **}
    <section class="travel-cron-card travel-cron-tools" aria-labelledby="eurosite-tools-title">
        <h3 id="eurosite-tools-title">{__("eurosite.dash_tools_title")}</h3>
        <p class="travel-cron-hint">{__("eurosite.dash_tools_intro")}</p>
        <div class="travel-cron-tools__groups travel-cron-tools__groups--two">
            <div class="travel-cron-tools__group" role="group" aria-labelledby="eurosite-api-title">
                <h4 id="eurosite-api-title">{__("eurosite.api_connection")}</h4>
                <p class="travel-cron-hint">
                    <code>{$eurosite_api_url|escape:html}</code>
                    <br>{__("eurosite.api_user")}: <code>{$eurosite_api_user|escape:html}</code>
                </p>
                <form action="{""|fn_url}" method="post" class="travel-cron-tools__form">
                    <input type="hidden" name="dispatch" value="eurosite.test_connection" />
                    <input type="hidden" name="security_hash" value="{$security_hash}" />
                    <button type="submit" class="btn"><i class="icon-ok"></i> {__("eurosite.test_connection")}</button>
                </form>
                <a href="{"addons.update&addon=eurosite"|fn_url}" class="btn"><i class="icon-cog"></i> {__("eurosite.addon_settings")}</a>
            </div>
            {* Read-only: asks the API, across the next months, which
               destinations and dates have offers. Runs in the admin
               (eurosite.probe_availability, no cron key needed) and prints its
               report in a new window. Never scheduled. *}
            <div id="eurosite-probe" class="travel-cron-tools__group" role="group" aria-labelledby="eurosite-probe-title">
                <h4 id="eurosite-probe-title">{__("eurosite.probe_title")}</h4>
                <p class="travel-cron-hint">{__("eurosite.probe_hint")}</p>
                <form action="{""|fn_url}" method="get" target="_blank" class="travel-cron-form cm-disable-check-changes">
                    <input type="hidden" name="dispatch" value="eurosite.probe_availability" />
                    <div class="travel-cron-form__field">
                        <label for="eurosite-probe-country">{__("eurosite.probe_country")}</label>
                        <input type="text" id="eurosite-probe-country" name="country" value="GR" class="input-mini" maxlength="4" />
                    </div>
                    <div class="travel-cron-form__field">
                        <label for="eurosite-probe-cities">{__("eurosite.probe_cities")}</label>
                        <input type="text" id="eurosite-probe-cities" name="city" value="" class="input-medium" placeholder="{__("eurosite.probe_cities_all")|escape:html}" />
                    </div>
                    <div class="travel-cron-form__field">
                        <label for="eurosite-probe-from">{__("eurosite.probe_from")}</label>
                        <input type="date" id="eurosite-probe-from" name="from" value="" class="input-medium" />
                    </div>
                    <div class="travel-cron-form__field">
                        <label for="eurosite-probe-nights">{__("eurosite.probe_nights")}</label>
                        <input type="number" id="eurosite-probe-nights" name="nights" value="" min="1" max="30" class="input-mini" />
                    </div>
                    <div class="travel-cron-form__field">
                        <label for="eurosite-probe-months">{__("eurosite.probe_months")}</label>
                        <input type="number" id="eurosite-probe-months" name="months" value="12" min="1" max="18" class="input-mini" />
                    </div>
                    <div class="travel-cron-form__actions">
                        <button type="submit" class="btn btn-primary"><i class="icon-search"></i> {__("eurosite.probe_run")}</button>
                    </div>
                </form>
            </div>
        </div>
    </section>

    {* ── Destinations: what we sell, per country (Travel Core's shared card) ── *}
    {if $eurosite_dest_card}
        {include file="addons/travel_core/components/destinations_card.tpl" card=$eurosite_dest_card}
    {/if}

    {* ── Hotel availability: its hotels per state (Travel Core's shared card) ── *}
    {if $eurosite_availability_card}
        {include file="addons/travel_core/components/availability_card.tpl" card=$eurosite_availability_card}
    {/if}

    {** Scheduled jobs: Travel Core's shared card, from CronPlanBuilder's rows,
       in the order they run. card_id is the target of the "Open Eurosite
       cron" link on Travel Core -> Tools. No command with the cron key is
       ever printed: the ⋯ menus copy the real ones, and Run posts to
       eurosite.run_job, its log in a new window. Under each job's name, what
       is Eurosite's own (components/job_extra.tpl): its catalog's size, the
       hotel list, why it has nothing to do. **}
    {include file="addons/travel_core/components/jobs_card.tpl"
        card_id="eurosite-scheduled-jobs"
        stages=$eurosite_job_stages
        on_demand=$eurosite_on_demand_jobs
        on_demand_intro=__("eurosite.jobs_on_demand_intro")
        has_key=$eurosite_cron_has_key
        run_dispatch="eurosite.run_job"
        row_extra="addons/eurosite/components/job_extra.tpl"
    }

    {if $eurosite_cron_has_key}
        {** Every job's command (Travel Core's shared card), in Eurosite's two
           plans: the catalogs in one nightly run, or each on its own line;
           one shown at a time, as installing both runs every catalog twice.
           Shown masked; the real text rides only in data-* for Copy. **}
        {include file="addons/travel_core/components/commands_card.tpl"
            card_id="eurosite-crontab"
            plans=$eurosite_cron_plans
            key=$eurosite_cron_key
        }
    {/if}

    {** Recent sync activity (components/activity.tpl, its own template: the
       pagination pair wraps exactly one list). **}
    {include file="addons/eurosite/components/activity.tpl"}

    {** Recent bookings: the last ten, each linked to Travel Core's bookings
       list and its order. **}
    <section class="travel-cron-card travel-cron-card--flush" aria-labelledby="eurosite-bookings-title">
        <div class="travel-cron-card__head travel-cron-card__head--pad">
            <h3 id="eurosite-bookings-title">{__("eurosite.recent_bookings", ["[default]" => "Recent bookings"])} ({$eurosite_counts.bookings|number_format})</h3>
            <a href="{"travel_bookings.manage&provider=eurosite"|fn_url}" class="btn">{__("eurosite.all_bookings", ["[default]" => "All Eurosite bookings"])}</a>
        </div>
        {if $eurosite_recent_bookings}
            <table class="table table-middle travel-cron-table">
                <thead>
                    <tr>
                        <th scope="col">ID</th>
                        <th scope="col">{__("eurosite.hotel", ["[default]" => "Hotel"])}</th>
                        <th scope="col">{__("eurosite.check_in", ["[default]" => "Check-in"])}</th>
                        <th scope="col" class="right">{__("eurosite.total", ["[default]" => "Total"])}</th>
                        <th scope="col">{__("eurosite.status", ["[default]" => "Status"])}</th>
                        <th scope="col">{__("eurosite.order", ["[default]" => "Order"])}</th>
                        <th scope="col">{__("eurosite.created", ["[default]" => "Created"])}</th>
                    </tr>
                </thead>
                <tbody>
                    {foreach from=$eurosite_recent_bookings item=b}
                        <tr>
                            <td><a href="{"travel_bookings.manage&provider=eurosite"|fn_url}">#{$b.booking_id}</a></td>
                            <td>{$b.hotel_name|escape:html}</td>
                            <td class="nowrap">{$b.check_in|escape:html}</td>
                            <td class="right nowrap">{$b.total|escape:html}</td>
                            <td><span class="travel-cron-pill {if $b.status == "confirmed"}travel-cron-pill--ok{elseif $b.status == "cancelled" || $b.status == "failed"}travel-cron-pill--bad{else}travel-cron-pill--warn{/if}">{$b.status|escape:html}</span></td>
                            <td>{if $b.order_id}<a href="{"orders.details&order_id=`$b.order_id`"|fn_url}">#{$b.order_id}</a>{else}&mdash;{/if}</td>
                            <td class="nowrap">{$b.created_at|escape:html}</td>
                        </tr>
                    {/foreach}
                </tbody>
            </table>
        {else}
            <p class="travel-cron-foot">{__("eurosite.no_bookings", ["[default]" => "No bookings yet."])}</p>
        {/if}
    </section>

</div>

{/capture}

{capture name="buttons"}{/capture}

{include file="common/mainbox.tpl" title=__("eurosite.dashboard_title", ["[default]" => "Eurosite Touring"]) content=$smarty.capture.mainbox buttons=$smarty.capture.buttons}
