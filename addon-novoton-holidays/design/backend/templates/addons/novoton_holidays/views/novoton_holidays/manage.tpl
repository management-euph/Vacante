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

    {** Cron URLs — id: the target of the "Open Novoton cron" link on Travel Core -> Tools **}
    <div class="novoton-section" id="novoton-cron-jobs">
        <h3>[C] Cron Job URLs</h3>
        {if $cron_key}
        <p class="novoton-alert novoton-alert-info">Copy these URLs for use in cPanel cron jobs or external cron services:</p>

        {* Recommended Batched Sync - Highlighted *}
        <div class="novoton-cron-recommended cron-hotel-info">
            <div class="cron-header">
                <span class="cron-tag">RECOMMENDED</span>
                <span class="cron-title">Hotel Info Batched</span>
            </div>
            <p class="cron-description">
                Smart sync with resume capability. First run syncs all hotels, then daily syncs only new/changed hotels.
                Automatically does full re-sync every 6 months.
            </p>
            <div class="novoton-cron-url cron-url-box" style="position: relative;">
                <code style="display: block; padding: 8px 70px 8px 10px; background: #f5f5f5; border: 1px solid #ddd; border-radius: 4px; font-size: 12px; word-break: break-all; cursor: text;" onclick="this.ownerDocument.defaultView.getSelection().selectAllChildren(this)">{$cron_urls.hotel_info_batched}</code>
                <button type="button" class="novoton-btn" style="position: absolute; right: 5px; top: 50%; transform: translateY(-50%); font-size: 11px; padding: 3px 8px;" onclick="navigator.clipboard.writeText(this.previousElementSibling.textContent.trim()).then(function(){ldelim}var b=event.target;b.textContent='Copied!';setTimeout(function(){ldelim}b.textContent='Copy';{rdelim},1500);{rdelim})">Copy</button>
            </div>
            <div class="cron-actions">
                <a href="{$cron_urls.hotel_info_batched}" target="_blank" class="novoton-btn novoton-btn-success">Run Now</a>
                <a href="{$cron_urls.hotel_info_batched}&status=1" target="_blank" class="novoton-btn">Check Status</a>
                <a href="{$cron_urls.hotel_info_batched}&force_full=1" target="_blank" class="novoton-btn">Force Full Sync</a>
                <a href="{$cron_urls.hotel_info_batched}&reset=1" target="_blank" class="novoton-btn novoton-btn-danger">Reset</a>
            </div>
            <p class="cron-hint">
                <strong>cPanel:</strong> <code>*/5 * * * *</code> (every 5 min) |
                <strong>Shared hosting:</strong> Add <code>&batch_size=50&max_time=120</code>
            </p>
        </div>

        {* Recommended Batched Priceinfo Sync - Highlighted *}
        <div class="novoton-cron-recommended cron-price-info">
            <div class="cron-header">
                <span class="cron-tag">RECOMMENDED</span>
                <span class="cron-title">Price Info Batched</span>
            </div>
            <p class="cron-description">
                Smart price sync with resume capability. Syncs all package prices in batches.
                Automatically re-syncs stale packages (older than 24h) and does full re-sync every 7 days.
            </p>
            <div class="novoton-cron-url cron-url-box">{$cron_urls.sync_priceinfo_batched}</div>
            <div class="cron-actions">
                <a href="{$cron_urls.sync_priceinfo_batched}" target="_blank" class="novoton-btn" style="background: #0066cc;">Run Now</a>
                <a href="{$cron_urls.sync_priceinfo_batched}&status=1" target="_blank" class="novoton-btn">Check Status</a>
                <a href="{$cron_urls.sync_priceinfo_batched}&force_full=1" target="_blank" class="novoton-btn">Force Full Sync</a>
                <a href="{$cron_urls.sync_priceinfo_batched}&reset=1" target="_blank" class="novoton-btn novoton-btn-danger">Reset</a>
            </div>
            <p class="cron-hint">
                <strong>cPanel:</strong> <code>*/5 * * * *</code> (every 5 min) |
                <strong>Shared hosting:</strong> Add <code>&batch_size=30&max_time=120</code>
            </p>
        </div>

        {* Hotel Features XML Feed *}
        {if $xml_feed_url}
        <div class="novoton-cron-recommended" style="border-left: 4px solid #17a2b8; background: #f0f9ff;">
            <div class="cron-header">
                <span class="cron-tag" style="background: #17a2b8;">XML FEED</span>
                <span class="cron-title">Hotel Features XML</span>
            </div>
            <p class="cron-description">
                Live XML feed with all hotel features. Use this URL in CS-Cart Advanced Import "Link to file" or open directly in browser.
            </p>
            <div class="novoton-cron-url cron-url-box">{$xml_feed_url}</div>
            <div class="cron-actions">
                <a href="{$xml_feed_url}" target="_blank" class="novoton-btn novoton-btn-info">Open XML</a>
            </div>
        </div>
        {/if}

        {* Cron Jobs Table *}
        <details>
        <summary style="cursor: pointer; font-weight: bold; padding: 8px 0; user-select: none;">Cron Jobs</summary>
        <table class="novoton-table">
            <tr>
                <th style="width: 180px;">Job</th>
                <th>URL</th>
                <th style="width: 100px;">Schedule</th>
                <th style="width: 60px;">Run</th>
            </tr>
            <tr>
                <td><strong>Hotel List</strong><br><small class="muted">Basic hotel data</small></td>
                <td><div class="novoton-cron-url">{$cron_urls.hotel_list}</div></td>
                <td>Every 3&ndash;7 days</td>
                <td><a href="{$cron_urls.hotel_list}" target="_blank" class="novoton-btn novoton-btn-sm">Run</a></td>
            </tr>
            <tr>
                <td><strong>Resort List</strong><br><small class="muted">Sync resort names</small></td>
                <td><div class="novoton-cron-url">{$cron_urls.resort_list}</div></td>
                <td>Weekly</td>
                <td><a href="{$cron_urls.resort_list}" target="_blank" class="novoton-btn novoton-btn-sm">Run</a></td>
            </tr>
            <tr>
                <td><strong>Facilities</strong><br><small class="muted">Sync facilities list</small></td>
                <td><div class="novoton-cron-url">{$cron_urls.list_facilities}</div></td>
                <td>Weekly</td>
                <td><a href="{$cron_urls.list_facilities}" target="_blank" class="novoton-btn novoton-btn-sm">Run</a></td>
            </tr>
            <tr>
                <td><strong>Hotel Facilities</strong><br><small class="muted">Assign facilities to hotels</small></td>
                <td><div class="novoton-cron-url">{$cron_urls.hotel_facilities_batched}</div></td>
                <td>Weekly</td>
                <td><a href="{$cron_urls.hotel_facilities_batched}" target="_blank" class="novoton-btn novoton-btn-sm">Run</a></td>
            </tr>
            <tr>
                <td><strong>Offers Update</strong><br><small class="muted">Check new offers</small></td>
                <td><div class="novoton-cron-url">{$cron_urls.offers_update}</div></td>
                <td>Every 2 hours</td>
                <td><a href="{$cron_urls.offers_update}" target="_blank" class="novoton-btn novoton-btn-sm">Run</a></td>
            </tr>
            <tr>
                <td><strong>Room Price</strong><br><small class="muted">Sets has_room_price gate</small></td>
                <td><div class="novoton-cron-url">{$cron_urls.room_price}</div></td>
                <td>Before Add Products</td>
                <td><a href="{$cron_urls.room_price}" target="_blank" class="novoton-btn novoton-btn-sm">Run</a></td>
            </tr>
            <tr>
                <td><strong>Add Products</strong><br><small class="muted">Create CS-Cart products</small></td>
                <td><div class="novoton-cron-url">{$cron_urls.add_products}</div></td>
                <td>After Room Price</td>
                <td><a href="{$cron_urls.add_products}" target="_blank" class="novoton-btn novoton-btn-sm">Run</a></td>
            </tr>
            <tr>
                <td><strong>Reassign Features</strong><br><small class="muted">Re-apply product features</small></td>
                <td><div class="novoton-cron-url">{$cron_urls.reassign_features}</div></td>
                <td>After Add Products</td>
                <td><a href="{$cron_urls.reassign_features}" target="_blank" class="novoton-btn novoton-btn-sm">Run</a></td>
            </tr>
            <tr>
                <td><strong>Compute Prices</strong><br><small class="muted">min_price, seasons, early booking</small></td>
                <td><div class="novoton-cron-url">{$cron_urls.compute_prices}</div></td>
                <td>Every 5 min</td>
                <td><a href="{$cron_urls.compute_prices}" target="_blank" class="novoton-btn novoton-btn-sm">Run</a></td>
            </tr>
            <tr>
                <td><strong>Recompute Calendar</strong><br><small class="muted">Rebuild calendar_prices_raw</small></td>
                <td><div class="novoton-cron-url">{$cron_urls.recompute_calendar_prices}</div></td>
                <td>After sync or daily</td>
                <td><a href="{$cron_urls.recompute_calendar_prices}" target="_blank" class="novoton-btn novoton-btn-sm">Run</a></td>
            </tr>
            <tr>
                <td><strong>Booking Status</strong><br><small class="muted">Check ASK bookings</small></td>
                <td><div class="novoton-cron-url">{$cron_urls.resinfo}</div></td>
                <td>Every 2 hours</td>
                <td><a href="{$cron_urls.resinfo}" target="_blank" class="novoton-btn novoton-btn-sm">Run</a></td>
            </tr>
            <tr>
                <td><strong>Geocode Streets</strong><br><small class="muted">street_address from coordinates (OSM Nominatim, needs geocoding enabled in settings)</small></td>
                <td><div class="novoton-cron-url">{$cron_urls.geocode_addresses}</div></td>
                <td>Hourly until backlog drains</td>
                <td>
                    <a href="{$cron_urls.geocode_addresses}" target="_blank" class="novoton-btn novoton-btn-sm">Run</a>
                    <a href="{$cron_urls.geocode_addresses}&status=1" target="_blank" class="novoton-btn novoton-btn-sm">Status</a>
                </td>
            </tr>
            <tr>
                <td><strong>Backfill Images</strong><br><small class="muted">Re-attach images to products that synced without them (recovery)</small></td>
                <td><div class="novoton-cron-url">{$cron_urls.backfill_images}</div></td>
                <td>Hourly until backlog drains</td>
                <td>
                    <a href="{$cron_urls.backfill_images}" target="_blank" class="novoton-btn novoton-btn-sm">Run</a>
                    <a href="{$cron_urls.backfill_images}&status=1" target="_blank" class="novoton-btn novoton-btn-sm">Status</a>
                </td>
            </tr>
            <tr>
                <td><strong>Cleanup</strong><br><small class="muted">Orphans, logs, cache</small></td>
                <td><div class="novoton-cron-url">{$cron_urls.cleanup}</div></td>
                <td>Daily</td>
                <td><a href="{$cron_urls.cleanup}" target="_blank" class="novoton-btn novoton-btn-sm">Run</a></td>
            </tr>
        </table>
        {else}
        <div class="novoton-alert novoton-alert-warning">
            <strong>[!] Cron Security Key Not Set</strong><br>
            {* Travel Core, not novoton: the cron secret authenticates OUR
               endpoint, so it lives once in Core and is shared by all three
               providers. This addon's settings page no longer has the field —
               linking there would send the operator somewhere they cannot fix
               it. api_login / api_password stay on that page. *}
            Please set the <strong>Cron security key</strong> in <a href="{"addons.update?addon=travel_core"|fn_url}">Travel Core settings</a> to enable cron jobs. It is shared by every travel addon.
        </div>
        {/if}
    </details>
    </div>

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
