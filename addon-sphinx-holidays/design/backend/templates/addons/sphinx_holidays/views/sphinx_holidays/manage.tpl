{capture name="mainbox"}

<div class="travel-admin-panel">

    {* API Configuration Warning *}
    {if !$is_configured}
        <div class="alert alert-warning">
            <i class="icon-warning-sign"></i>
            {__("sphinx_holidays.api_not_configured")}
        </div>
    {/if}

    {* ── Sync Controls ── *}
    <div class="travel-update-section">
        <form action="{""|fn_url}" method="post" style="display:inline;">
            <input type="hidden" name="dispatch" value="sphinx_holidays.sync_destinations" />
            <button type="submit" class="btn btn-primary" {if !$is_configured}disabled{/if}
                    onclick="return confirm('{__("sphinx_holidays.sync_destinations_confirm")|escape:javascript}');">
                <i class="icon-refresh"></i> {__("sphinx_holidays.sync_destinations")}
            </button>
        </form>

        <form action="{""|fn_url}" method="post" style="display:inline;">
            <input type="hidden" name="dispatch" value="sphinx_holidays.sync_hotels" />
            <button type="submit" class="btn btn-primary" {if !$is_configured}disabled{/if}
                    onclick="return confirm('{__("sphinx_holidays.sync_hotels_confirm")|escape:javascript}');">
                <i class="icon-refresh"></i> {__("sphinx_holidays.sync_hotels")}
            </button>
        </form>

        <form action="{""|fn_url}" method="post" style="display:inline;">
            <input type="hidden" name="dispatch" value="sphinx_holidays.sync_circuits" />
            <button type="submit" class="btn btn-primary" {if !$is_configured}disabled{/if}
                    onclick="return confirm('{__("sphinx_holidays.sync_circuits_confirm")|escape:javascript}');">
                <i class="icon-refresh"></i> {__("sphinx_holidays.sync_circuits")}
            </button>
        </form>

        <form action="{""|fn_url}" method="post" style="display:inline;">
            <input type="hidden" name="dispatch" value="sphinx_holidays.sync_package_routes" />
            <button type="submit" class="btn btn-primary" {if !$is_configured}disabled{/if}
                    onclick="return confirm('{__("sphinx_holidays.sync_package_routes_confirm")|escape:javascript}');">
                <i class="icon-refresh"></i> {__("sphinx_holidays.sync_package_routes")}
            </button>
        </form>

        {if $selected_countries}
            <span class="muted">
                {__("sphinx_holidays.sync_targets")}: <code>{', '|implode:$selected_countries}</code>
            </span>
            <a href="{"sphinx_holidays.whitelist"|fn_url}" class="btn btn-micro">
                <i class="icon-cog"></i> {__("sphinx_holidays.destination_whitelist")}
            </a>
        {else}
            <span class="text-warning">
                <i class="icon-warning-sign"></i> {__("sphinx_holidays.no_sync_targets")}
            </span>
            <a href="{"sphinx_holidays.whitelist"|fn_url}" class="btn btn-micro">
                <i class="icon-cog"></i> {__("sphinx_holidays.destination_whitelist")}
            </a>
        {/if}
    </div>

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

    {* ── Cron Commands ── *}
    {* id: the target of the "Open Sphinx cron" link on Travel Core -> Tools. *}
    <h4 id="sphinx-cron-commands">{__("sphinx_holidays.cron_commands")}</h4>
    <div class="well">
        <table class="table table-condensed table-hover" style="table-layout:fixed; width:100%;">
            <colgroup>
                <col style="width:120px;" />
                <col style="width:260px;" />
                <col style="width:180px;" />
                <col />
                <col style="width:120px;" />
            </colgroup>
            <thead>
                <tr>
                    <th>Mode</th>
                    <th>Description</th>
                    <th>Schedule</th>
                    <th>URL</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong>destinations</strong></td>
                    <td>Sync all destinations from Sphinx API (incremental; <strong>Run (full)</strong> re-fetches &amp; rewrites all rows)</td>
                    <td><code>0 2 * * 0</code> (weekly)</td>
                    <td style="word-break:break-all; font-size:11px; overflow:hidden;"><code>{$cron_urls.destinations}</code></td>
                    <td><a href="{$cron_urls.destinations}" target="_blank" class="btn btn-mini">Run</a> <a href="{$cron_urls.destinations}&full=1" target="_blank" class="btn btn-mini">Run (full)</a></td>
                </tr>
                <tr>
                    <td><strong>hotels</strong></td>
                    <td>Sync hotels for selected destinations (incremental; <strong>Run (full)</strong> re-fetches &amp; rewrites all rows, incl. lat/lng)</td>
                    <td><code>0 3 * * *</code> (daily)</td>
                    <td style="word-break:break-all; font-size:11px; overflow:hidden;"><code>{$cron_urls.hotels}</code></td>
                    <td><a href="{$cron_urls.hotels}" target="_blank" class="btn btn-mini">Run</a> <a href="{$cron_urls.hotels}&full=1" target="_blank" class="btn btn-mini">Run (full)</a></td>
                </tr>
                <tr>
                    <td><strong>add_products</strong></td>
                    <td>Create CS-Cart products for unlinked hotels</td>
                    <td><code>30 3 * * *</code> (daily after hotels)</td>
                    <td style="word-break:break-all; font-size:11px; overflow:hidden;"><code>{$cron_urls.add_products}</code></td>
                    <td><a href="{$cron_urls.add_products}" target="_blank" class="btn btn-mini">Run</a></td>
                </tr>
                <tr>
                    <td><strong>package_routes</strong></td>
                    <td>Sync package routes from Sphinx API</td>
                    <td><code>0 4 * * 1</code> (weekly)</td>
                    <td style="word-break:break-all; font-size:11px; overflow:hidden;"><code>{$cron_urls.package_routes}</code></td>
                    <td><a href="{$cron_urls.package_routes}" target="_blank" class="btn btn-mini">Run</a></td>
                </tr>
                <tr>
                    <td><strong>circuits</strong></td>
                    <td>Sync circuit catalog from Sphinx API (incremental; add <code>&amp;full=1</code> for a full re-fetch)</td>
                    <td><code>15 3 * * *</code> (daily)</td>
                    <td style="word-break:break-all; font-size:11px; overflow:hidden;"><code>{$cron_urls.circuits}</code></td>
                    <td><a href="{$cron_urls.circuits}" target="_blank" class="btn btn-mini">Run</a></td>
                </tr>
                <tr>
                    <td><strong>order_status</strong></td>
                    <td>Sync booking statuses with Sphinx API</td>
                    <td><code>*/15 * * * *</code> (every 15 min)</td>
                    <td style="word-break:break-all; font-size:11px; overflow:hidden;"><code>{$cron_urls.order_status}</code></td>
                    <td><a href="{$cron_urls.order_status}" target="_blank" class="btn btn-mini">Run</a></td>
                </tr>
                {* ── City/Region repair chain ──
                   The ladder resolves a hotel's City/Region; update_products
                   is what carries a CHANGED location onto the product's
                   features (the repair jobs flag it, they never write
                   features themselves). Schedule update_products and the
                   chain completes itself. *}
                <tr>
                    <td><strong>backfill_hotel_locations</strong></td>
                    <td>Re-resolve City/Region for stored hotels from the destination tree (no API calls)</td>
                    <td><code>30 4 * * *</code> (daily)</td>
                    <td style="word-break:break-all; font-size:11px; overflow:hidden;"><code>{$cron_urls.backfill_hotel_locations}</code></td>
                    <td><a href="{$cron_urls.backfill_hotel_locations}" target="_blank" class="btn btn-mini">Run</a></td>
                </tr>
                <tr>
                    <td><strong>geocode_hotels</strong></td>
                    <td>Fill City/Region/street from coordinates via OpenStreetMap when the tree and the address cannot (1 req/sec; enable geocoding in Travel Core settings first)</td>
                    <td><code>*/15 * * * *</code> with <code>&amp;limit=100</code></td>
                    <td style="word-break:break-all; font-size:11px; overflow:hidden;"><code>{$cron_urls.geocode_hotels}&amp;limit=100</code></td>
                    <td>
                        <a href="{$cron_urls.geocode_hotels}&amp;limit=100" target="_blank" class="btn btn-mini">Run</a>
                        <a href="{$cron_urls.geocode_hotels}&amp;status=1" target="_blank" class="btn btn-mini">Backlog</a>
                        <a href="{$cron_urls.geocode_hotels}&amp;force=1&amp;limit=100" target="_blank" class="btn btn-mini">Re-do</a>
                    </td>
                </tr>
                <tr>
                    <td><strong>reassign_features</strong></td>
                    <td>Re-assign ALL product features (stars, City, Region, boards…) for every linked hotel — the catch-all when something looks stale</td>
                    <td>on demand</td>
                    <td style="word-break:break-all; font-size:11px; overflow:hidden;"><code>{$cron_urls.reassign_features}</code></td>
                    <td><a href="{$cron_urls.reassign_features}" target="_blank" class="btn btn-mini">Run</a></td>
                </tr>
                <tr>
                    <td><strong>cache_refresh</strong></td>
                    <td>Refresh cached search results</td>
                    <td><code>*/30 * * * *</code> (every 30 min)</td>
                    <td style="word-break:break-all; font-size:11px; overflow:hidden;"><code>{$cron_urls.cache_refresh}</code></td>
                    <td><a href="{$cron_urls.cache_refresh}" target="_blank" class="btn btn-mini">Run</a></td>
                </tr>
                <tr>
                    <td><strong>cleanup</strong></td>
                    <td>Clean expired cache and old sync logs</td>
                    <td><code>0 5 * * *</code> (daily)</td>
                    <td style="word-break:break-all; font-size:11px; overflow:hidden;"><code>{$cron_urls.cleanup}</code></td>
                    <td><a href="{$cron_urls.cleanup}" target="_blank" class="btn btn-mini">Run</a></td>
                </tr>
                <tr>
                    <td><strong>discover_boards</strong></td>
                    <td>Discover meal plans from cache API</td>
                    <td><code>0 3 * * *</code> (daily)</td>
                    <td style="max-width:350px; word-break:break-all; font-size:11px;"><code>{$cron_urls.discover_boards}</code></td>
                    <td><a href="{$cron_urls.discover_boards}" target="_blank" class="btn btn-mini">Run</a></td>
                </tr>
                <tr>
                    <td><strong>assign_boards</strong></td>
                    <td>Assign board codes to hotels</td>
                    <td><code>30 3 * * *</code> (daily, after discover)</td>
                    <td style="max-width:350px; word-break:break-all; font-size:11px;"><code>{$cron_urls.assign_boards}</code></td>
                    <td><a href="{$cron_urls.assign_boards}" target="_blank" class="btn btn-mini">Run</a></td>
                </tr>
                <tr>
                    <td><strong>update_products</strong></td>
                    <td>Push changed hotel data to CS-Cart products, re-assigning their features (stars, City, Region, boards…). This is what carries a repaired City/Region onto the product.</td>
                    <td><code>0 6 * * *</code> (daily, after hotels) — or <code>*/30 * * * *</code> to pick up location repairs promptly; it costs nothing when nothing changed</td>
                    <td style="max-width:350px; word-break:break-all; font-size:11px;"><code>{$cron_urls.update_products}</code></td>
                    <td><a href="{$cron_urls.update_products}" target="_blank" class="btn btn-mini">Run</a></td>
                </tr>
            </tbody>
        </table>

        <p class="muted" style="font-size:11px; margin-top:4px;">
            Image sync crons (<code>enrich_hotel_data</code>, <code>sync_images</code>,
            <code>process_image_queue</code>) are listed with their schedules and scoped
            options in the <strong>Image sync</strong> section below.
        </p>

        {if !$cron_key}
            <div class="alert alert-warning">
                <i class="icon-warning-sign"></i>
                {__("sphinx_holidays.cron_key_not_set")}
            </div>
        {/if}
    </div>

    {* ── Image Sync — Scoped Options ── *}
    <h4>Image sync — scoped options <small class="muted">(run on demand or schedule; safe to combine with &amp;force=Y or &amp;limit=N)</small></h4>
    <div class="well">
        <table class="table table-condensed table-hover" style="table-layout:fixed; width:100%;">
            <colgroup>
                <col style="width:200px;" />
                <col style="width:280px;" />
                <col />
                <col style="width:40px;" />
            </colgroup>
            <thead>
                <tr>
                    <th>Scope</th>
                    <th>What it syncs</th>
                    <th>URL</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <tr class="success">
                    <td><strong>whitelist=strict</strong></td>
                    <td>Only hotels whose <code>destination_id</code> is in the whitelist table — ignores all other hotels in the same country. Use this to sync exactly the destinations you selected.</td>
                    <td style="word-break:break-all; font-size:11px;"><code>{$cron_urls.sync_images}&amp;whitelist=strict</code></td>
                    <td><a href="{$cron_urls.sync_images}&amp;whitelist=strict" target="_blank" class="btn btn-mini btn-success">Run</a></td>
                </tr>
                <tr>
                    <td><strong>country=XX</strong></td>
                    <td>All hotels with a product in one country (e.g. HR, MT, IT). Useful when you've whitelisted an entire country.</td>
                    <td style="word-break:break-all; font-size:11px;"><code>{$cron_urls.sync_images}&amp;country=HR</code></td>
                    <td><a href="{$cron_urls.sync_images}&amp;country=HR" target="_blank" class="btn btn-mini">Run</a></td>
                </tr>
                <tr>
                    <td><strong>destination_id=X</strong></td>
                    <td>Hotels in one specific destination. Replace the ID with a value from the whitelist admin. Useful for re-syncing after an API image update.</td>
                    <td style="word-break:break-all; font-size:11px;"><code>{$cron_urls.sync_images}&amp;destination_id=1234</code></td>
                    <td><a href="{$cron_urls.sync_images}&amp;destination_id=1234" target="_blank" class="btn btn-mini">Run</a></td>
                </tr>
                <tr>
                    <td><strong>region_id=X</strong></td>
                    <td>Hotels in one specific region. Narrower than destination; useful for targeting a single resort area.</td>
                    <td style="word-break:break-all; font-size:11px;"><code>{$cron_urls.sync_images}&amp;region_id=5678</code></td>
                    <td><a href="{$cron_urls.sync_images}&amp;region_id=5678" target="_blank" class="btn btn-mini">Run</a></td>
                </tr>
                <tr>
                    <td><strong>sync_images</strong> (default)</td>
                    <td>All hotels in all whitelisted countries. Schedule <code>0 4 * * *</code> (daily, after add_products). Populates the queue from <code>images_json</code> in DB (no API calls); only queues hotels that already have images.</td>
                    <td style="word-break:break-all; font-size:11px;"><code>{$cron_urls.sync_images}</code></td>
                    <td><a href="{$cron_urls.sync_images}" target="_blank" class="btn btn-mini">Run</a></td>
                </tr>
                <tr class="info">
                    <td><strong>process_image_queue</strong></td>
                    <td>Schedule <code>*/2 * * * *</code> (every 2 min). Process next 50 pending queue rows (download + attach). Add <code>&amp;reset_failed=Y</code> to retry all failed rows.</td>
                    <td style="word-break:break-all; font-size:11px;"><code>{$cron_urls.process_image_queue}</code></td>
                    <td><a href="{$cron_urls.process_image_queue}" target="_blank" class="btn btn-mini btn-primary">Run</a></td>
                </tr>
                <tr class="warning">
                    <td><strong>enrich_hotel_data</strong></td>
                    <td>Schedule <code>*/5 * * * *</code> (every 5 min, until drained). Back-fill <code>images_json</code> for hotels missing images (calls detail API). Run repeatedly until output shows 0 scanned.</td>
                    <td style="word-break:break-all; font-size:11px;"><code>{$cron_urls.enrich_hotel_data}</code></td>
                    <td><a href="{$cron_urls.enrich_hotel_data}" target="_blank" class="btn btn-mini btn-warning">Run</a></td>
                </tr>
            </tbody>
        </table>
        <p class="muted" style="font-size:11px; margin-top:4px;">
            Add <code>&amp;force=Y</code> to re-queue images for hotels that already have them. Add <code>&amp;limit=N</code> to cap the number of hotels processed. Filters are mutually exclusive: destination_id &gt; region_id &gt; country &gt; whitelist=strict &gt; default.
            For <code>process_image_queue</code>: add <code>&amp;reset_failed=Y</code> to reset failed rows back to pending for retry.
        </p>

        {* ── Image Sync Architecture ── *}
        <details style="margin-top:12px;">
            <summary style="cursor:pointer; font-weight:bold; color:#555;">Image sync architecture (click to expand)</summary>
            <div style="margin-top:10px; font-size:12px; line-height:1.6;">
                <p><strong>Three-step async flow:</strong></p>
                <ol>
                    <li><strong>Step 0 — enrich_hotel_data</strong> — finds hotels with empty <code>images_json</code> and calls <code>GET /api/v1/static/hotels/{ldelim}id{rdelim}</code> for each. Writes the result back to <code>sphinx_hotels.images_json</code>. Run this until no more hotels are missing images. Safe to run repeatedly — it only processes hotels that still have empty data.</li>
                    <li><strong>Step 1 — sync_images</strong> — reads <code>images_json</code> from DB (single level, no API calls). For each image URL inserts a row into <code>sphinx_image_sync_queue</code> with <code>status=pending</code>. Hotels with empty <code>images_json</code> are logged and skipped. Deduplicates by <code>(hotel_id, image_url)</code> so safe to re-run.</li>
                    <li><strong>Step 2 — process_image_queue</strong> — fetches <code>N</code> pending rows, atomically marks them <code>processing</code>, downloads each image to a temp file, and attaches via CS-Cart <code>fn_update_image_pairs()</code>. Marks each row <code>completed</code> or <code>failed</code>. Designed to run every 1–2 minutes — no timeout risk, concurrent-safe.</li>
                </ol>
                <p><strong>Key tables:</strong> <code>sphinx_hotels</code> (images_json) → <code>sphinx_image_sync_queue</code> (pending/processing/completed/failed) → <code>images</code> + <code>images_links</code> (CS-Cart image store).</p>
                <p><strong>To diagnose a specific hotel:</strong> use <code>cron_mode=diagnose_images&amp;hotel_id=X</code>. Add <code>&amp;attach=Y</code> to also attach and see any attach error.</p>
            </div>
        </details>
    </div>

    {* ── Diagnostic Commands ── *}
    <h4>Diagnostic commands <small class="muted">(run on demand, not scheduled)</small></h4>
    <div class="well">
        <table class="table table-condensed table-hover" style="table-layout:fixed; width:100%;">
            <colgroup>
                <col style="width:160px;" />
                <col style="width:280px;" />
                <col />
                <col style="width:40px;" />
            </colgroup>
            <thead>
                <tr>
                    <th>Mode</th>
                    <th>Description</th>
                    <th>Example URL</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong>diagnose_search</strong></td>
                    <td>Check API connectivity, auth, search request/response and poll results for a specific hotel. Add <code>&amp;hotel_name=kazbek</code> or <code>&amp;hotel_id=X</code>.</td>
                    <td style="word-break:break-all; font-size:11px;"><code>{$cron_urls.diagnose_search}&amp;hotel_id=89812</code></td>
                    <td><a href="{$cron_urls.diagnose_search}&amp;hotel_id=89812" target="_blank" class="btn btn-mini">Run</a></td>
                </tr>
                <tr>
                    <td><strong>diagnose_images</strong></td>
                    <td>Show image URLs for a hotel, test HTTP reachability, and optionally attach them. Add <code>&amp;hotel_id=X</code> or <code>&amp;attach=Y</code>.</td>
                    <td style="word-break:break-all; font-size:11px;"><code>{$cron_urls.diagnose_images}&amp;hotel_id=89812</code></td>
                    <td><a href="{$cron_urls.diagnose_images}&amp;hotel_id=89812" target="_blank" class="btn btn-mini">Run</a></td>
                </tr>
                <tr>
                    <td><strong>diagnose_seo</strong></td>
                    <td>Audit SEO field population — registry state, placeholder values, rendered output vs DB. Add <code>&amp;hotel_id=X</code> or <code>&amp;apply=Y</code> to write.</td>
                    <td style="word-break:break-all; font-size:11px;"><code>{$cron_urls.diagnose_seo}&amp;hotel_id=89812</code></td>
                    <td><a href="{$cron_urls.diagnose_seo}&amp;hotel_id=89812" target="_blank" class="btn btn-mini">Run</a></td>
                </tr>
            </tbody>
        </table>
        <p class="muted" style="font-size:11px; margin-top:4px;">
            Replace <code>89812</code> with any hotel ID. Use <code>&amp;hotel_name=kazbek+dubrovnik</code> to look up by name instead.
        </p>
    </div>

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
