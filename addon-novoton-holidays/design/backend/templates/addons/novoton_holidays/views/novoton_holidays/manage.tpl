{** Novoton Holidays Dashboard **}

{capture name="mainbox"}

<div class="novoton-dashboard">

    {** Statistics Cards **}
    <div class="novoton-stats">
        <div class="novoton-stat-card">
            <h3>[H] Hotels</h3>
            <div class="novoton-stat-number">{$stats.hotels.total|default:0}</div>
            <div class="novoton-stat-row">
                <span>Real-time (room_price) available</span>
                <span class="novoton-badge novoton-badge-success">{$stats.hotels.with_prices|default:0}</span>
            </div>
            <div class="novoton-stat-row">
                <span>Season prices (priceinfo) available</span>
                <span class="novoton-badge novoton-badge-success">{$stats.hotels.with_packages|default:0}</span>
            </div>
            <div class="novoton-stat-row">
                <span>As Products</span>
                <span class="novoton-badge novoton-badge-info">{$stats.hotels.with_products|default:0}</span>
            </div>
        </div>

        <div class="novoton-stat-card">
            <h3>[L] Bookings</h3>
            <div class="novoton-stat-number">{$stats.bookings.total|default:0}</div>
            <div class="novoton-stat-row">
                <span>Pending</span>
                <span class="novoton-badge novoton-badge-warning">{$stats.bookings.pending|default:0}</span>
            </div>
            <div class="novoton-stat-row">
                <span>Confirmed</span>
                <span class="novoton-badge novoton-badge-success">{$stats.bookings.confirmed|default:0}</span>
            </div>
            <div class="novoton-stat-row">
                <span>Cancelled</span>
                <span class="novoton-badge novoton-badge-danger">{$stats.bookings.cancelled|default:0}</span>
            </div>
        </div>

        <div class="novoton-stat-card">
            <h3>[S] Last Sync</h3>
            <div class="novoton-stat-row">
                <span>Hotel List</span>
                <span>{if $last_syncs.hotellist}{$last_syncs.hotellist|date_format:"%d.%m %H:%M"}{else}Never{/if}</span>
            </div>
            <div class="novoton-stat-row">
                <span>Hotel Info</span>
                <span>{if $last_syncs.hotelinfo}{$last_syncs.hotelinfo|date_format:"%d.%m %H:%M"}{else}Never{/if}</span>
            </div>
            <div class="novoton-stat-row">
                <span>Prices</span>
                <span>{if $last_syncs.prices}{$last_syncs.prices|date_format:"%d.%m %H:%M"}{else}Never{/if}</span>
            </div>
            <div class="novoton-stat-row">
                <span>Offers Update</span>
                <span>{if $last_syncs.offers_update}{$last_syncs.offers_update|date_format:"%d.%m %H:%M"}{else}Never{/if}</span>
            </div>
            <div class="novoton-stat-row">
                <span>Facilities</span>
                <span>{if $last_syncs.facilities}{$last_syncs.facilities|date_format:"%d.%m %H:%M"}{else}Never{/if}</span>
            </div>
            <div class="novoton-stat-row">
                <span>Resort List</span>
                <span>{if $last_syncs.resort_list}{$last_syncs.resort_list|date_format:"%d.%m %H:%M"}{else}Never{/if}</span>
            </div>
        </div>
    </div>

    {** Quick Actions **}
    <div class="novoton-actions">
        <h3>Actions</h3>
        <div class="novoton-btn-group">
            <a href="{"novoton_holidays.check_prices"|fn_url}" class="novoton-btn novoton-btn-info" target="_blank">$ Check Prices</a>
            <a href="{"novoton_holidays.check_prices_hotel"|fn_url}" class="novoton-btn novoton-btn-info" target="_blank">$ Check Prices (Per-Hotel)</a>
            <a href="{"novoton_holidays.check_packages"|fn_url}" class="novoton-btn novoton-btn-info" target="_blank">[P] Check Packages</a>
            <a href="{"novoton_bookings.manage"|fn_url}" class="novoton-btn novoton-btn-info">[L] Manage Bookings</a>
            <a href="{"novoton_alternatives.manage"|fn_url}" class="novoton-btn novoton-btn-info">[R] Alternative Requests</a>
            <a href="{"novoton_holidays.export_hotel_features_csv"|fn_url}" class="novoton-btn novoton-btn-info">[CSV] Download Hotel Features</a>
            <a href="{"novoton_holidays.test_api"|fn_url}" class="novoton-btn novoton-btn-info" target="_blank">[T] Test API</a>
            <a href="{"novoton_diagnostic.health"|fn_url}" class="novoton-btn novoton-btn-info" target="_blank">[H] Health Check</a>
            <a href="{"novoton_price_compare.manage"|fn_url}" class="novoton-btn novoton-btn-info">[C] Price Comparison Tool</a>
            <a href="{"novoton_holidays.recompute_calendar_prices"|fn_url}" class="novoton-btn novoton-btn-info">[Cal] Recompute Calendar Prices</a>
        </div>
    </div>

    {** Country Statistics **}
    {if $stats.by_country}
    <div class="novoton-section">
        <h3>[W] Statistics by Country</h3>
        <div class="novoton-country-grid">
            {foreach from=$stats.by_country key=country item=country_stats}
            {if $country_stats.with_prices > 0 || $country_stats.with_packages > 0}
            <div class="novoton-country-card">
                <h4>{$country}</h4>
                <div class="novoton-stat-row">
                    <span>Total Hotels</span>
                    <span><strong>{$country_stats.total}</strong></span>
                </div>
                <div class="novoton-stat-row">
                    <span>Real-time (room_price) available</span>
                    <span class="novoton-badge novoton-badge-success">{$country_stats.with_prices}</span>
                </div>
                <div class="novoton-stat-row">
                    <span>Season prices (priceinfo) available</span>
                    <span class="novoton-badge novoton-badge-success">{$country_stats.with_packages}</span>
                </div>
                <div class="novoton-stat-row">
                    <span>As Products</span>
                    <span class="novoton-badge novoton-badge-info">{$country_stats.with_products}</span>
                </div>
            </div>
            {/if}
            {/foreach}
        </div>
    </div>
    {/if}

    {** Scheduled jobs — id: the target of the "Open Novoton cron" link on Travel Core -> Tools.
       One row per job, in the order the jobs run (CronPlanBuilder). No
       command with the cron key is ever printed: they are shown masked and
       Copy puts the real one on the clipboard; Run is an admin POST. **}
    <section id="novoton-cron-jobs" class="travel-cron-card travel-cron-card--flush novoton-jobs" aria-labelledby="novoton-jobs-title">
        <div class="travel-cron-card__head travel-cron-card__head--pad">
            <div>
                <h3 id="novoton-jobs-title">{__("novoton_holidays.dash_jobs_title")}</h3>
                <p class="muted">{__("novoton_holidays.dash_jobs_intro")}</p>
            </div>
        </div>

        {if !$novoton_cron_has_key}
            <div class="alert alert-warning novoton-jobs__nokey">
                <strong>{__("novoton_holidays.dash_no_key_title")}</strong>
                {* Travel Core, not novoton: the cron secret authenticates OUR
                   endpoint, so it lives once in Core and is shared by all three
                   providers. *}
                <p>{__("novoton_holidays.dash_no_key_body")} <a href="{"addons.update?addon=travel_core"|fn_url}">{__("novoton_holidays.dash_set_key")}</a></p>
            </div>
        {/if}

        <table class="table table-middle travel-cron-table novoton-jobs__table">
            <thead>
                <tr>
                    <th>{__("novoton_holidays.dash_col_job")}</th>
                    <th>{__("novoton_holidays.dash_col_schedule")}</th>
                    <th>{__("novoton_holidays.dash_col_last_run")}</th>
                    <th class="right">{__("novoton_holidays.dash_col_actions")}</th>
                </tr>
            </thead>
            {foreach from=$novoton_job_stages item=stage}
                <tbody>
                    <tr class="novoton-jobs__stage">
                        <th colspan="4" scope="colgroup">{$stage.number}. {__("novoton_holidays.dash_stage_`$stage.stage`")}</th>
                    </tr>
                    {foreach from=$stage.jobs item=job}
                        {include file="addons/novoton_holidays/components/job_row.tpl" job=$job has_key=$novoton_cron_has_key}
                    {/foreach}
                </tbody>
            {/foreach}
        </table>

        {if $novoton_on_demand_jobs}
            <details class="novoton-jobs__ondemand">
                <summary>{__("novoton_holidays.dash_on_demand_title", ["[n]" => $novoton_on_demand_jobs|count])}</summary>
                <p class="muted">{__("novoton_holidays.dash_on_demand_intro")}</p>
                <table class="table table-middle travel-cron-table novoton-jobs__table">
                    <tbody>
                        {foreach from=$novoton_on_demand_jobs item=job}
                            {include file="addons/novoton_holidays/components/job_row.tpl" job=$job has_key=$novoton_cron_has_key}
                        {/foreach}
                    </tbody>
                </table>
            </details>
        {/if}
    </section>

    {if $novoton_cron_has_key}
        {* Every job's command, for the server crontab (CLI) or a cron service
           (URL). The key is masked in the DISPLAY only: a masked command pasted
           into a crontab is a refused run at 04:30 nobody is awake to see, so
           Copy all copies the real text (dashboard.js). *}
        <section id="novoton-crontab" class="travel-cron-card novoton-crontab" aria-labelledby="novoton-crontab-title"
                 data-crontab-cli="{$novoton_crontab_cli|escape:html}"
                 data-crontab-url="{$novoton_crontab_url|escape:html}"
                 data-key="{$novoton_cron_key|escape:html}"
                 data-txt-copied="{__("novoton_holidays.dash_copied")|escape:html}"
                 data-txt-copy-failed="{__("novoton_holidays.dash_copy_failed")|escape:html}">
            <div class="travel-cron-card__head">
                <div>
                    <h3 id="novoton-crontab-title">{__("novoton_holidays.dash_commands_title")}</h3>
                    <p class="muted">{__("novoton_holidays.dash_commands_hint")}</p>
                </div>
                <div class="novoton-crontab__tools">
                    <div class="btn-group" role="group" aria-label="{__("novoton_holidays.dash_format_label")|escape:html}">
                        <button type="button" class="btn" data-novoton-format="cli" aria-pressed="true">{__("novoton_holidays.dash_format_cli")}</button>
                        <button type="button" class="btn" data-novoton-format="url" aria-pressed="false">{__("novoton_holidays.dash_format_url")}</button>
                    </div>
                    <button type="button" class="btn btn-primary" id="novoton-crontab-copy">{__("novoton_holidays.dash_copy_all")}</button>
                </div>
            </div>
            <pre id="novoton-crontab-text" class="novoton-crontab__text">{$novoton_crontab_masked|escape:html}</pre>
            <p class="travel-cron-foot novoton-crontab__note">
                <i class="icon-lock" aria-hidden="true"></i> {__("novoton_holidays.dash_key_hidden_note")}
                <a href="{"travel_tools.manage"|fn_url}">{__("novoton_holidays.dash_open_tools")}</a>
            </p>

            <div class="novoton-crontab__feed">
                <strong>{__("novoton_holidays.dash_xml_feed_title")}</strong>
                <p class="muted">{__("novoton_holidays.dash_xml_feed_hint")}</p>
                <div class="novoton-crontab__feedrow">
                    <code class="travel-cron-cmd">{$novoton_xml_feed_masked|escape:html}</code>
                    <button type="button" class="btn novoton-copy" data-copy="{$novoton_xml_feed_url|escape:html}">{__("novoton_holidays.dash_copy_url")}</button>
                </div>
            </div>
        </section>
    {/if}

    {** Excluded Resorts Management **}
    <details class="novoton-section">
        <summary style="cursor: pointer; font-weight: bold; font-size: 16px; padding: 8px 0; user-select: none;">[E] Excluded Resorts</summary>
        <p class="muted">Select resorts to EXCLUDE when adding hotels as products. Hotels from excluded resorts will be skipped.</p>

        {if $resorts_by_country}
        <form action="{"novoton_holidays.save_excluded_resorts"|fn_url}" method="post" id="excluded-resorts-form">
            <input type="hidden" name="security_hash" value="{$security_hash}">

            {* Search and Filter Controls *}
            <div class="novoton-resorts-toolbar">
                <div class="novoton-resorts-search">
                    <input type="text" id="resort-search" placeholder="Search resorts...">
                </div>

                <div class="novoton-resorts-filter">
                    <select id="country-filter">
                        <option value="">All Countries</option>
                        {foreach from=$resorts_by_country key=country item=resorts}
                        <option value="{$country|escape:'html'}">{$country} ({$resorts|count})</option>
                        {/foreach}
                    </select>
                </div>

                <div class="novoton-resorts-actions">
                    <button type="button" class="btn btn-small" id="btn-select-all-visible">Select Visible</button>
                    <button type="button" class="btn btn-small" id="btn-deselect-all-visible">Deselect Visible</button>
                </div>
            </div>

            {* Resorts List *}
            <div id="resorts-container" class="novoton-resorts-container">
                {foreach from=$resorts_by_country key=country item=resorts}
                <div class="novoton-country-group" data-country="{$country|escape:'html'}">
                    <h4>
                        <span>
                            {$country}
                            <span class="country-count">({$resorts|count} resorts)</span>
                        </span>
                        <span class="country-links">
                            <a href="javascript:void(0)" data-select-country="{$country|escape:'javascript'}">Select all</a> |
                            <a href="javascript:void(0)" data-deselect-country="{$country|escape:'javascript'}" class="deselect-link">Deselect all</a>
                        </span>
                    </h4>
                    <div class="novoton-resort-items">
                        {foreach from=$resorts item=resort}
                        <label class="resort-item" data-resort="{$resort|lower|escape:'html'}" data-country="{$country|escape:'html'}">
                            <input type="checkbox" name="excluded_resorts[]" value="{$resort|escape:'html'}"
                                   {if in_array($resort, $excluded_resorts)}checked{/if}>
                            {$resort}
                        </label>
                        {/foreach}
                    </div>
                </div>
                {/foreach}
            </div>

            {* No results message *}
            <div id="no-results" class="novoton-no-results">
                No resorts match your search criteria.
            </div>

            {* Summary and Save *}
            <div class="novoton-resorts-footer">
                <button type="submit" class="btn btn-primary">Save Excluded Resorts</button>
                <span class="excluded-info">
                    Currently excluded: <strong id="excluded-count">{$excluded_resorts|count}</strong> resort(s)
                </span>
                <span id="visible-count" class="visible-info"></span>
            </div>
        </form>

        {else}
        <div class="novoton-alert novoton-alert-warning">
            <strong>No resorts found.</strong><br>
            Run the "Hotel List Sync" cron job first to load resort data from the API.
        </div>
        {/if}
    </details>

    {** Recent Sync Logs **}
    {if $recent_syncs}
    <details class="novoton-section">
        <summary style="cursor: pointer; font-weight: bold; font-size: 16px; padding: 8px 0; user-select: none;">[A] Recent Sync Activity</summary>
        <table class="novoton-table">
            <tr>
                <th>Date</th>
                <th>Type</th>
                <th>Total</th>
                <th>Updated</th>
                <th>Failed</th>
                <th>Status</th>
                <th>Duration</th>
            </tr>
            {foreach from=$recent_syncs item=log}
            <tr>
                <td>{$log.sync_date|date_format:"%d.%m.%Y %H:%M"}</td>
                <td><span class="novoton-badge novoton-badge-info">{$log.sync_type}</span></td>
                <td>{$log.products_total}</td>
                <td>{$log.products_updated}</td>
                <td class="{if $log.products_failed > 0}novoton-sync-log sync-error{else}muted{/if}">{$log.products_failed}</td>
                <td>
                    {if $log.status == 'completed'}
                        <span class="novoton-badge novoton-badge-success">OK</span>
                    {elseif $log.status == 'failed'}
                        <span class="novoton-badge novoton-badge-danger">Failed</span>
                    {elseif $log.status == 'running'}
                        <span class="novoton-badge novoton-badge-warning">Running</span>
                    {else}
                        <span class="novoton-badge">{$log.status}</span>
                    {/if}
                </td>
                <td>{$log.duration_seconds}s</td>
            </tr>
            {/foreach}
        </table>
    </details>
    {/if}

</div>

{* Inside the capture on purpose: admin top-nav navigation is AJAX and runs
   only the scripts inside the mainbox capture. *}
{script src="js/addons/novoton_holidays/dashboard.js"}

{/capture}

{capture name="buttons"}
    <a class="btn btn-primary" href="{"addons.update?addon=novoton_holidays"|fn_url}">
        {__("settings")}
    </a>
{/capture}

{include file="common/mainbox.tpl"
    title="Novoton Holidays Dashboard{if $addon_version} v{$addon_version}{/if}"
    content=$smarty.capture.mainbox
    buttons=$smarty.capture.buttons
}
