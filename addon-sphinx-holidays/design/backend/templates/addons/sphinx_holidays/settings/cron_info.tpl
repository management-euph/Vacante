{* Sphinx Holidays — CLI PHP cron command reference (tools_cron settings tab) *}
{assign var="cron_bin" value="php `$smarty.const.DIR_ROOT`/app/addons/sphinx_holidays/cron.php"}
<div class="control-group">
    <div class="well">
        <h4>{__("sphinx_holidays.quick_actions")}</h4>
        <p>
            <a href="{"sphinx_holidays.manage"|fn_url}" class="btn btn-primary">
                {__("sphinx_holidays.open_dashboard")}
            </a>
        </p>

        <h4>{__("sphinx_holidays.cron_cli_title")}</h4>
        <p class="muted">{__("sphinx_holidays.cron_cli_intro")}</p>
        <p class="muted">{__("sphinx_holidays.cron_full_note")}</p>

        {* ---- Recurring sync jobs ---- *}
        <h5>{__("sphinx_holidays.cron_recurring_title")}</h5>
        <table class="table table-condensed">
            <thead>
                <tr>
                    <th>{__("sphinx_holidays.cron_col_mode")}</th>
                    <th>{__("sphinx_holidays.cron_col_command")}</th>
                    <th>{__("sphinx_holidays.cron_col_schedule")}</th>
                    <th>{__("sphinx_holidays.cron_col_description")}</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong>full</strong></td>
                    <td><code>{$cron_bin} access_key=YOUR_KEY mode=full</code></td>
                    <td><code>0 1 * * *</code></td>
                    <td>Run the full pipeline (all recurring jobs below, in order)</td>
                </tr>
                <tr>
                    <td><strong>destinations</strong></td>
                    <td><code>{$cron_bin} access_key=YOUR_KEY mode=destinations</code></td>
                    <td>Weekly (<code>0 1 * * 0</code>)</td>
                    <td>Sync countries / regions / cities from the Sphinx API</td>
                </tr>
                <tr>
                    <td><strong>hotels</strong></td>
                    <td><code>{$cron_bin} access_key=YOUR_KEY mode=hotels</code></td>
                    <td>Daily (<code>0 2 * * *</code>)</td>
                    <td>Sync hotels for whitelisted destinations</td>
                </tr>
                <tr>
                    <td><strong>assign_boards</strong></td>
                    <td><code>{$cron_bin} access_key=YOUR_KEY mode=assign_boards</code></td>
                    <td>Daily (<code>30 2 * * *</code>)</td>
                    <td>Assign discovered board / meal codes as product features</td>
                </tr>
                <tr>
                    <td><strong>package_routes</strong></td>
                    <td><code>{$cron_bin} access_key=YOUR_KEY mode=package_routes</code></td>
                    <td>Daily (<code>0 3 * * *</code>)</td>
                    <td>Sync flight / bus package routes</td>
                </tr>
                <tr>
                    <td><strong>circuits</strong></td>
                    <td><code>{$cron_bin} access_key=YOUR_KEY mode=circuits</code></td>
                    <td>Daily (<code>15 3 * * *</code>)</td>
                    <td>Sync circuits</td>
                </tr>
                <tr>
                    <td><strong>experiences</strong></td>
                    <td><code>{$cron_bin} access_key=YOUR_KEY mode=experiences</code></td>
                    <td>Daily (<code>30 3 * * *</code>)</td>
                    <td>Sync experiences</td>
                </tr>
                <tr>
                    <td><strong>add_products</strong></td>
                    <td><code>{$cron_bin} access_key=YOUR_KEY mode=add_products</code></td>
                    <td>Daily (<code>0 4 * * *</code>)</td>
                    <td>Create CS-Cart products from unlinked hotels (<code>country=TR</code>, <code>limit=N</code>, <code>retry_skipped=1</code> optional)</td>
                </tr>
                <tr>
                    <td><strong>update_products</strong></td>
                    <td><code>{$cron_bin} access_key=YOUR_KEY mode=update_products</code></td>
                    <td>Daily (<code>30 4 * * *</code>)</td>
                    <td>Update existing products with changed hotel data</td>
                </tr>
                <tr>
                    <td><strong>sync_images</strong></td>
                    <td><code>{$cron_bin} access_key=YOUR_KEY mode=sync_images</code></td>
                    <td>Daily (<code>0 5 * * *</code>)</td>
                    <td>Populate the image download queue from hotel records</td>
                </tr>
                <tr>
                    <td><strong>process_image_queue</strong></td>
                    <td><code>{$cron_bin} access_key=YOUR_KEY mode=process_image_queue</code></td>
                    <td>Every 10 min (<code>*/10 * * * *</code>)</td>
                    <td>Download and attach queued images to products</td>
                </tr>
                <tr>
                    <td><strong>order_status</strong></td>
                    <td><code>{$cron_bin} access_key=YOUR_KEY mode=order_status</code></td>
                    <td>Every 15 min (<code>*/15 * * * *</code>)</td>
                    <td>Sync booking / order statuses with Sphinx</td>
                </tr>
                <tr>
                    <td><strong>cleanup</strong></td>
                    <td><code>{$cron_bin} access_key=YOUR_KEY mode=cleanup</code></td>
                    <td>Daily (<code>0 7 * * *</code>)</td>
                    <td>Remove stale state, expired cache and old logs</td>
                </tr>
            </tbody>
        </table>

        {* ---- On-demand / maintenance jobs ---- *}
        <h5>{__("sphinx_holidays.cron_ondemand_title")}</h5>
        <table class="table table-condensed">
            <thead>
                <tr>
                    <th>{__("sphinx_holidays.cron_col_mode")}</th>
                    <th>{__("sphinx_holidays.cron_col_command")}</th>
                    <th>{__("sphinx_holidays.cron_col_description")}</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong>discover_boards</strong></td>
                    <td><code>{$cron_bin} access_key=YOUR_KEY mode=discover_boards</code></td>
                    <td>Discover available board / meal types per hotel via live search (run separately — uses live API calls)</td>
                </tr>
                <tr>
                    <td><strong>sync_and_upload_images</strong></td>
                    <td><code>{$cron_bin} access_key=YOUR_KEY mode=sync_and_upload_images</code></td>
                    <td>Combined image sync + queue processing in one pass</td>
                </tr>
                <tr>
                    <td><strong>reassign_features</strong></td>
                    <td><code>{$cron_bin} access_key=YOUR_KEY mode=reassign_features</code></td>
                    <td>Re-evaluate and update hotel features on existing products</td>
                </tr>
                <tr>
                    <td><strong>enrich_hotel_data</strong></td>
                    <td><code>{$cron_bin} access_key=YOUR_KEY mode=enrich_hotel_data</code></td>
                    <td>Enrich hotel records with additional details from the API</td>
                </tr>
                <tr>
                    <td><strong>deduplicate</strong></td>
                    <td><code>{$cron_bin} access_key=YOUR_KEY mode=deduplicate</code></td>
                    <td>Remove duplicate hotel records from the database</td>
                </tr>
                <tr>
                    <td><strong>audit_facilities</strong></td>
                    <td><code>{$cron_bin} access_key=YOUR_KEY mode=audit_facilities</code></td>
                    <td>Audit hotel amenities / facilities against the API schema</td>
                </tr>
            </tbody>
        </table>

        {* ---- Diagnostics ---- *}
        <h5>{__("sphinx_holidays.cron_diagnostics_title")}</h5>
        <table class="table table-condensed">
            <thead>
                <tr>
                    <th>{__("sphinx_holidays.cron_col_mode")}</th>
                    <th>{__("sphinx_holidays.cron_col_command")}</th>
                    <th>{__("sphinx_holidays.cron_col_description")}</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong>diagnose_search</strong></td>
                    <td><code>{$cron_bin} access_key=YOUR_KEY mode=diagnose_search</code></td>
                    <td>Test API search functionality</td>
                </tr>
                <tr>
                    <td><strong>diagnose_images</strong></td>
                    <td><code>{$cron_bin} access_key=YOUR_KEY mode=diagnose_images</code></td>
                    <td>Check image sync state and identify issues</td>
                </tr>
                <tr>
                    <td><strong>diagnose_product_features</strong></td>
                    <td><code>{$cron_bin} access_key=YOUR_KEY mode=diagnose_product_features</code></td>
                    <td>Audit product feature assignments</td>
                </tr>
                <tr>
                    <td><strong>diagnose_seo</strong></td>
                    <td><code>{$cron_bin} access_key=YOUR_KEY mode=diagnose_seo</code></td>
                    <td>Verify SEO template data on products</td>
                </tr>
            </tbody>
        </table>
    </div>
</div>
