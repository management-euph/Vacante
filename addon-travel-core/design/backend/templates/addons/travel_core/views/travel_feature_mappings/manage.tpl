{*
 * Travel Core - Feature Mappings Management Page
 *
 * Two modes:
 *   - Dashboard (no feature_type): cards per feature type with stats
 *   - List (feature_type selected): paginated, searchable table
 *}

{capture name="mainbox"}

{if $view_mode == 'dashboard'}

    {* ══════════════════════════ DASHBOARD MODE ══════════════════════════ *}

    {* Global Stats Bar *}
    <div class="well well-small" style="margin-bottom: 15px;">
        <div class="row-fluid">
            <div class="span3">
                <strong>{__("travel_core.fm_total")}:</strong> {$mapping_stats.total}
            </div>
            <div class="span3">
                <strong>{__("travel_core.fm_active")}:</strong>
                <span class="label label-success">{$mapping_stats.active}</span>
            </div>
            <div class="span3">
                <strong>{__("travel_core.fm_unmapped")}:</strong>
                <span class="label {if $mapping_stats.unmapped > 0}label-important{/if}">{$mapping_stats.unmapped}</span>
            </div>
            <div class="span3">
                <strong>{__("travel_core.fm_aliases")}:</strong>
                <span class="label label-info">{$mapping_stats.aliases}</span>
            </div>
        </div>
    </div>

    {* Action Buttons *}
    <div style="margin-bottom: 15px;">
        <form action="{"travel_feature_mappings.reseed"|fn_url}" method="post" class="form-inline" style="display: inline-block;">
            <input type="hidden" name="security_hash" value="{$security_hash}">
            <button type="submit" class="btn btn-default" onclick="return confirm('{__("travel_core.fm_reseed_confirm")}');">
                <i class="icon-refresh"></i> {__("travel_core.fm_reseed")}
            </button>
        </form>
        <form action="{"travel_feature_mappings.resolve_variants"|fn_url}" method="post" class="form-inline" style="display: inline-block; margin-left: 5px;">
            <input type="hidden" name="security_hash" value="{$security_hash}">
            <button type="submit" class="btn btn-success" onclick="return confirm('{__("travel_core.fm_resolve_confirm")}');">
                <i class="icon-magic"></i> {__("travel_core.fm_resolve_variants")}
            </button>
        </form>
    </div>

    {* Providers with NO alias for a type they need: their hotels never get
       that feature (AliasCoverage). The provider self-heals re-seed their
       aliases on the next admin page load after an update. *}
    {if $alias_gaps}
        <div class="alert alert-block" style="margin-bottom: 15px;">
            <strong>{__("travel_core.fm_alias_gaps_title")}</strong>
            <ul style="margin: 6px 0 6px 18px;">
                {foreach from=$alias_gaps item=gap}
                    {$gap_type = $gap.feature_type}
                    <li>{__("travel_core.fm_alias_gap", ["[provider]" => $gap.provider|capitalize, "[feature]" => $type_labels.$gap_type|default:$gap_type])}
                        &middot; <a href="{"travel_feature_mappings.manage?feature_type=`$gap_type`"|fn_url}">{__("travel_core.fm_alias_gap_open")}</a></li>
                {/foreach}
            </ul>
            <span class="muted">{__("travel_core.fm_alias_gaps_hint")}</span>
        </div>
    {/if}

    {* Feature Type Cards *}
    <div class="row-fluid" style="margin-bottom: 15px;">
        {assign var="card_count" value=0}
        {foreach from=$type_stats key=ft item=stat}
            {if $card_count > 0 and $card_count % 3 == 0}
                </div><div class="row-fluid" style="margin-bottom: 15px;">
            {/if}
            <div class="span4">
                <div class="well well-small" style="min-height: 120px; position: relative;">
                    {* Status indicator *}
                    {if $stat.feature_id <= 0}
                        <span class="label label-important" style="position: absolute; top: 8px; right: 8px;" title="CS-Cart feature not configured">
                            <i class="icon-warning-sign"></i>
                        </span>
                    {elseif $stat.unmapped > 0}
                        <span class="label label-warning" style="position: absolute; top: 8px; right: 8px;" title="{$stat.unmapped} unmapped">
                            {$stat.unmapped}
                        </span>
                    {else}
                        <span class="label label-success" style="position: absolute; top: 8px; right: 8px;">
                            <i class="icon-ok"></i>
                        </span>
                    {/if}

                    <h5 style="margin-top: 0;">
                        <a href="{"travel_feature_mappings.manage?feature_type=`$ft`"|fn_url}" style="text-decoration: none;">
                            {$type_labels.$ft|default:$ft}
                        </a>
                    </h5>

                    <div style="font-size: 12px; color: #666; line-height: 1.6;">
                        <span title="Total mappings"><strong>{$stat.total}</strong> mapped</span>
                        {if $stat.unmapped > 0}
                            &middot; <span class="text-error" title="Missing CS-Cart variant"><strong>{$stat.unmapped}</strong> unmapped</span>
                        {/if}
                        {if $stat.auto_registered > 0}
                            &middot; <span class="text-warning" title="Auto-discovered from API">{$stat.auto_registered} auto</span>
                        {/if}
                    </div>

                    {* Provider (source) badges — full names, same markup as the
                       list view's Sources column. The old one-letter pill
                       ({$src|upper|truncate:1:"":true}) rendered EMPTY under
                       Smarty 5, hiding the sources entirely. *}
                    {if $stat.providers}
                        <div style="margin-top: 6px;">
                            {foreach from=","|explode:$stat.providers item=src}
                                {if $src|trim}
                                <span class="label {if $src|trim == 'sphinx'}label-info{elseif $src|trim == 'novoton'}label-warning{else}label-default{/if}" style="font-size: 9px;">{$src|trim|escape:'html'}</span>
                                {/if}
                            {/foreach}
                        </div>
                    {/if}

                    {* Feature ID *}
                    <div style="margin-top: 6px; font-size: 11px; color: #999;">
                        {if $stat.feature_id > 0}
                            Feature #{$stat.feature_id}
                        {else}
                            <span class="text-error">Not configured</span>
                        {/if}
                    </div>

                    <a href="{"travel_feature_mappings.manage?feature_type=`$ft`"|fn_url}" class="btn btn-mini btn-primary" style="margin-top: 8px;">
                        View &rarr;
                    </a>
                </div>
            </div>
            {assign var="card_count" value=$card_count+1}
        {/foreach}
    </div>

    {* Scan Facilities + Unmapped Values *}
    <div class="well well-small">
        <div class="row-fluid">
            <div class="span6">
                <strong>{__("travel_core.fm_scan_facilities")}:</strong>
                <form action="{"travel_feature_mappings.scan_facilities"|fn_url}" method="post" class="form-inline" style="display: inline;">
                    <input type="hidden" name="security_hash" value="{$security_hash}">
                    <select name="scan_provider" style="width: 120px; margin: 0 5px;">
                        {foreach from=$scan_providers item=sp}
                            <option value="{$sp|escape:'html'}">{$sp|upper|escape:'html'}</option>
                        {/foreach}
                    </select>
                    <select name="batch_size" style="width: 80px; margin-right: 5px;">
                        <option value="250">250</option>
                        <option value="500" selected>500</option>
                        <option value="1000">1000</option>
                    </select>
                    <button type="submit" class="btn btn-mini btn-info" onclick="return confirm('{__("travel_core.fm_scan_confirm")}');">
                        <i class="icon-search"></i> Scan
                    </button>
                </form>
            </div>
            <div class="span6 text-right">
                {if $unmapped_count > 0}
                    <i class="icon-exclamation-sign text-warning"></i>
                    <strong>{$unmapped_count}</strong> unmapped values
                    <a href="{"travel_feature_mappings.unmapped"|fn_url}" class="btn btn-mini btn-warning" style="margin-left: 5px;">
                        View &rarr;
                    </a>
                {else}
                    <span class="text-success"><i class="icon-ok"></i> All facilities mapped</span>
                {/if}
            </div>
        </div>
    </div>

{else}

    {* ══════════════════════════ LIST MODE (paginated) ══════════════════════════ *}

    {* Back link + Header *}
    <div style="margin-bottom: 15px;">
        <a href="{"travel_feature_mappings.manage"|fn_url}" class="btn btn-default btn-mini">
            <i class="icon-arrow-left"></i> {__("travel_core.fm_back_dashboard")}
        </a>
    </div>

    <div class="well well-small" style="margin-bottom: 15px;">
        <div class="row-fluid">
            <div class="span6">
                <h5 style="margin: 0;">{$type_label}
                    <span class="muted">({$search.total_items} {__("travel_core.fm_entries")})</span>
                </h5>
            </div>
            <div class="span6 text-right">
                {if $type_stats.unmapped > 0}
                    <span class="label label-important">{$type_stats.unmapped} unmapped</span>
                {/if}
                {if $configured_feature_id > 0}
                    <span class="label label-info">Feature #{$configured_feature_id}</span>
                {else}
                    <span class="label label-important"><i class="icon-warning-sign"></i> Feature not configured</span>
                {/if}
            </div>
        </div>
    </div>

    {* Search & Filter Form *}
    <form action="{""|fn_url}" method="get" class="form-horizontal form-inline" style="margin-bottom: 10px;">
        <input type="hidden" name="dispatch" value="travel_feature_mappings.manage" />
        <input type="hidden" name="feature_type" value="{$search.feature_type}" />

        <input type="text" name="q" value="{$search.q|escape:'html'}" placeholder="Search code or name..." class="input-medium" style="margin-right: 8px;" />

        <select name="status" style="width: 100px; margin-right: 8px;">
            <option value="">{__("status")}: {__("all")}</option>
            <option value="A" {if $search.status == 'A'}selected{/if}>{__("active")}</option>
            <option value="D" {if $search.status == 'D'}selected{/if}>{__("disabled")}</option>
        </select>

        <select name="mapping_source" style="width: 100px; margin-right: 8px;">
            <option value="">Source: {__("all")}</option>
            <option value="seed" {if $search.mapping_source == 'seed'}selected{/if}>Seed</option>
            <option value="auto" {if $search.mapping_source == 'auto'}selected{/if}>Auto</option>
            <option value="manual" {if $search.mapping_source == 'manual'}selected{/if}>Manual</option>
        </select>

        <button type="submit" class="btn btn-primary btn-mini">{__("search")}</button>
        {if $search.q || $search.status || $search.mapping_source}
            <a href="{"travel_feature_mappings.manage?feature_type=`$search.feature_type`"|fn_url}" class="btn btn-mini">{__("reset")}</a>
        {/if}
    </form>

    {* Top Pagination *}
    {include file="common/pagination.tpl" save_current_url=true}

    {* Mappings Table *}
    {if $mappings}
    <form action="{"travel_feature_mappings.bulk_update"|fn_url}" method="post" name="bulk_form">
        <input type="hidden" name="security_hash" value="{$security_hash}">
        <input type="hidden" name="feature_type" value="{$search.feature_type}">

        <table class="table table-striped table-hover table-condensed">
            <thead>
                <tr>
                    <th width="30"><input type="checkbox" onclick="toggleAllCheckboxes(this)"></th>
                    <th>{__("travel_core.fm_canonical_code")}</th>
                    <th>{__("travel_core.fm_display_en")}</th>
                    <th>{__("travel_core.fm_display_ro")}</th>
                    <th width="90">{__("travel_core.fm_variant")}</th>
                    <th width="80">{__("travel_core.fm_sources")}</th>
                    <th width="60">{__("status")}</th>
                    <th width="60">{__("tools")}</th>
                </tr>
            </thead>
            <tbody>
                {foreach from=$mappings item=m}
                <tr class="{if $m.status == 'D'}muted{/if}">
                    <td><input type="checkbox" name="map_ids[]" value="{$m.map_id}" class="mapping-cb"></td>
                    <td>
                        <code>{$m.canonical_code}</code>
                        {if $m.mapping_source == 'auto'}
                            <span class="label label-warning" style="font-size: 9px; vertical-align: middle;">auto</span>
                        {/if}
                    </td>
                    <td>{$m.display_name_en|truncate:30:"..."|default:'-'}</td>
                    <td>{$m.display_name_ro|truncate:30:"..."|default:'-'}</td>
                    <td>
                        {if $m.cscart_variant_id > 0}
                            <span class="label label-success" title="{$m.variant_name|escape:'html'}">
                                {if $m.variant_source == 'manual'}<i class="icon-lock" title="Admin locked"></i>{/if}
                                #{$m.cscart_variant_id}
                            </span>
                        {else}
                            <span class="label label-important" title="Not mapped to CS-Cart variant">&times;</span>
                        {/if}
                    </td>
                    <td>
                        {if $m.api_sources}
                            {foreach from=","|explode:$m.api_sources item=src}
                                {if $src|trim}
                                <span class="label {if $src == 'sphinx'}label-info{elseif $src == 'novoton'}label-warning{else}label-default{/if}" style="font-size: 9px;" title="{$src|escape:'html'}">{$src|escape:'html'}</span>
                                {/if}
                            {/foreach}
                        {else}
                            <span class="muted">&mdash;</span>
                        {/if}
                    </td>
                    <td>
                        {if $m.status == 'A'}
                            <span class="label label-success">{__("active")}</span>
                        {else}
                            <span class="label">{__("disabled")}</span>
                        {/if}
                    </td>
                    <td>
                        <a href="{"travel_feature_mappings.edit?map_id=`$m.map_id`"|fn_url}" class="btn btn-mini btn-default" title="{__("edit")}">
                            <i class="icon-pencil"></i>
                        </a>
                    </td>
                </tr>
                {/foreach}
            </tbody>
        </table>

        {* Bulk Action Buttons *}
        <div class="well well-small" style="margin-top: 10px;">
            <strong>{__("travel_core.fm_with_selected")}:</strong>
            <a href="#" class="btn btn-mini btn-success" onclick="document.bulk_form.action = '{"travel_feature_mappings.bulk_update.activate"|fn_url}'; document.bulk_form.submit(); return false;">
                <i class="icon-ok"></i> {__("travel_core.fm_activate")}
            </a>
            <a href="#" class="btn btn-mini btn-warning" onclick="document.bulk_form.action = '{"travel_feature_mappings.bulk_update.deactivate"|fn_url}'; document.bulk_form.submit(); return false;">
                <i class="icon-ban-circle"></i> {__("travel_core.fm_deactivate")}
            </a>
            <a href="#" class="btn btn-mini btn-danger" onclick="if(confirm('{__("travel_core.fm_delete_confirm")}')) {ldelim} document.bulk_form.action = '{"travel_feature_mappings.bulk_update.delete"|fn_url}'; document.bulk_form.submit(); {rdelim} return false;">
                <i class="icon-trash"></i> {__("delete")}
            </a>
        </div>
    </form>

    {else}
    <p class="no-items">{__("no_data")}</p>
    {/if}

    {* Bottom Pagination *}
    {include file="common/pagination.tpl"}

{/if}

<script>
function toggleAllCheckboxes(source) {
    var checkboxes = document.querySelectorAll('.mapping-cb');
    for (var i = 0; i < checkboxes.length; i++) {
        checkboxes[i].checked = source.checked;
    }
}
</script>

{/capture}

{capture name="buttons"}
    <a href="{"addons.update?addon=travel_core&selected_section=general"|fn_url}" class="btn">
        <i class="icon-cog"></i> {__("settings")}
    </a>
{/capture}

{include file="common/mainbox.tpl"
    title=__("travel_core.feature_mappings")
    content=$smarty.capture.mainbox
    buttons=$smarty.capture.buttons
}
