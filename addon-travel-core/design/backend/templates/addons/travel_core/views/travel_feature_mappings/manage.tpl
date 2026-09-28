{*
 * Travel Core - Feature Mappings Management Page
 *
 * Two modes:
 *   - Dashboard (no feature_type): tiles, needs-attention list and one row
 *     per feature type with each provider's alias coverage
 *   - List (feature_type selected): paginated, searchable table with the
 *     values each provider sends
 *}

{capture name="mainbox"}

{if $view_mode == 'dashboard'}

    {* ══════════════════════════ DASHBOARD MODE ══════════════════════════ *}

    <p class="travel-fm-intro">{__("travel_core.fm_intro")}</p>

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
            <button type="submit" class="btn btn-primary" onclick="return confirm('{__("travel_core.fm_resolve_confirm")}');">
                <i class="icon-magic"></i> {__("travel_core.fm_resolve_variants")}
            </button>
        </form>
    </div>

    {* Tiles *}
    <div class="travel-fm-tiles">
        <div class="travel-fm-tile">
            <span class="travel-fm-tile__label">{__("travel_core.fm_tile_mappings")}</span>
            <span class="travel-fm-tile__value">{$mapping_stats.total}</span>
            <span class="travel-fm-tile__hint">{__("travel_core.fm_tile_mappings_hint", ["[count]" => $mapping_stats.active])}</span>
        </div>
        <div class="travel-fm-tile{if $mapping_stats.unmapped > 0} travel-fm-tile--bad{/if}">
            <span class="travel-fm-tile__label">{__("travel_core.fm_tile_no_variant")}</span>
            <span class="travel-fm-tile__value">{$mapping_stats.unmapped}</span>
            <span class="travel-fm-tile__hint">{__("travel_core.fm_tile_no_variant_hint")}</span>
        </div>
        <div class="travel-fm-tile">
            <span class="travel-fm-tile__label">{__("travel_core.fm_tile_aliases")}</span>
            <span class="travel-fm-tile__value">{$mapping_stats.aliases}</span>
            <span class="travel-fm-tile__hint">{__("travel_core.fm_tile_aliases_hint", ["[count]" => $alias_providers])}</span>
        </div>
        <a class="travel-fm-tile{if $unmapped_count > 0} travel-fm-tile--warn{/if}" href="{"travel_feature_mappings.unmapped"|fn_url}">
            <span class="travel-fm-tile__label">{__("travel_core.fm_tile_raw")}</span>
            <span class="travel-fm-tile__value">{$unmapped_count}</span>
            <span class="travel-fm-tile__hint">{__("travel_core.fm_tile_raw_hint")}</span>
        </a>
    </div>

    {* Needs attention: providers with NO alias for a type they need (their
       hotels never get that feature, AliasCoverage) and feature types with
       no CS-Cart feature selected (their values never reach a product). *}
    {$attention = $alias_gaps|count}
    {foreach from=$type_stats item=stat}{if $stat.feature_id <= 0 && $stat.total > 0}{$attention = $attention + 1}{/if}{/foreach}
    {if $attention > 0}
        <div class="travel-fm-attention">
            <div class="travel-fm-attention__title">{__("travel_core.fm_needs_attention")}</div>
            <ul>
                {foreach from=$alias_gaps item=gap}
                    {$gap_type = $gap.feature_type}
                    <li>{__("travel_core.fm_alias_gap", ["[provider]" => $gap.provider|capitalize, "[feature]" => $type_labels.$gap_type|default:$gap_type])}
                        &middot; <a href="{"travel_feature_mappings.manage?feature_type=`$gap_type`&provider=!`$gap.provider`"|fn_url}">{__("travel_core.fm_alias_gap_open")} &rarr;</a></li>
                {/foreach}
                {foreach from=$type_stats key=ft item=stat}
                    {if $stat.feature_id <= 0 && $stat.total > 0}
                        <li>{__("travel_core.fm_attention_no_feature", ["[feature]" => $type_labels.$ft|default:$ft, "[count]" => $stat.total])}
                            &middot; <a href="{"addons.update?addon=travel_core&selected_section=general"|fn_url}">{__("travel_core.fm_attention_choose_feature")} &rarr;</a></li>
                    {/if}
                {/foreach}
            </ul>
            {if $alias_gaps}<span class="muted">{__("travel_core.fm_alias_gaps_hint")}</span>{/if}
        </div>
    {/if}

    {* One row per feature type (a grid, not a <table>: the page's only
       table is the paginated list below) *}
    <div class="travel-fm-grid" role="table" aria-label="{__("travel_core.feature_mappings")}">
        <div class="travel-fm-grid__row travel-fm-grid__head" role="row">
            <span role="columnheader">{__("travel_core.fm_col_feature")}</span>
            <span role="columnheader">{__("travel_core.fm_col_mapped")}</span>
            <span role="columnheader">{__("travel_core.fm_col_no_variant")}</span>
            <span role="columnheader">{__("travel_core.fm_col_auto")}</span>
            <span role="columnheader">{__("travel_core.fm_col_cs_feature")}</span>
            <span role="columnheader">{__("travel_core.fm_col_provider_aliases")}</span>
            <span role="columnheader"></span>
        </div>
        {foreach from=$type_stats key=ft item=stat}
            <div class="travel-fm-grid__row" role="row">
                <span role="cell"><a href="{"travel_feature_mappings.manage?feature_type=`$ft`"|fn_url}"><strong>{$type_labels.$ft|default:$ft}</strong></a></span>
                <span role="cell">{$stat.total}</span>
                <span role="cell">{if $stat.unmapped > 0}<span class="travel-fm-bad" title="{__("travel_core.fm_tile_no_variant_hint")}">{$stat.unmapped}</span>{else}<span class="muted">&ndash;</span>{/if}</span>
                <span role="cell">{if $stat.auto_registered > 0}<span title="{__("travel_core.fm_auto_hint")}">{$stat.auto_registered}</span>{else}<span class="muted">&ndash;</span>{/if}</span>
                <span role="cell">
                    {if $stat.feature_id > 0}
                        {$stat.feature_name|escape:'html'} <span class="muted">#{$stat.feature_id}</span>
                    {else}
                        <span class="travel-fm-bad">{__("travel_core.fm_not_configured")}</span>
                    {/if}
                </span>
                <span role="cell" class="travel-fm-chips">
                    {foreach from=$stat.coverage item=chip}
                        {if $chip.state == 'ok'}
                            <span class="travel-fm-chip travel-fm-chip--ok" title="{__("travel_core.fm_chip_count", ["[count]" => $chip.count])}">{$chip.label|escape:'html'}</span>
                        {elseif $chip.state == 'missing'}
                            <span class="travel-fm-chip travel-fm-chip--missing">{__("travel_core.fm_chip_none", ["[provider]" => $chip.label|escape:'html'])}</span>
                        {else}
                            <span class="travel-fm-chip">{__("travel_core.fm_chip_unused", ["[provider]" => $chip.label|escape:'html'])}</span>
                        {/if}
                    {foreachelse}
                        <span class="travel-fm-chip">{__("travel_core.fm_derived")}</span>
                    {/foreach}
                </span>
                <span role="cell" class="right"><a href="{"travel_feature_mappings.manage?feature_type=`$ft`"|fn_url}">{__("travel_core.fm_alias_gap_open")} &rarr;</a></span>
            </div>
        {/foreach}
    </div>

    {* Raw values the providers send that match no alias *}
    <div class="well well-small">
        <div class="row-fluid">
            <div class="span6">
                <strong>{__("travel_core.fm_raw_title")}</strong><br>
                {if $unmapped_count > 0}
                    <span class="muted">{__("travel_core.fm_raw_text", ["[count]" => $unmapped_count])}</span>
                    <a href="{"travel_feature_mappings.unmapped"|fn_url}" class="btn btn-mini btn-warning" style="margin-left: 5px;">{__("travel_core.fm_review", ["[count]" => $unmapped_count])} &rarr;</a>
                {else}
                    <span class="text-success"><i class="icon-ok"></i> {__("travel_core.fm_raw_none")}</span>
                {/if}
            </div>
            <div class="span6 text-right">
                <form action="{"travel_feature_mappings.scan_facilities"|fn_url}" method="post" class="form-inline" style="display: inline;">
                    <input type="hidden" name="security_hash" value="{$security_hash}">
                    <label>{__("travel_core.fm_scan_facilities")}:
                        <select name="scan_provider" style="width: 120px; margin: 0 5px;">
                            {foreach from=$scan_providers item=sp}
                                <option value="{$sp|escape:'html'}">{$sp|capitalize|escape:'html'}</option>
                            {/foreach}
                        </select>
                    </label>
                    <select name="batch_size" style="width: 80px; margin-right: 5px;" aria-label="{__("travel_core.fm_batch_size")}">
                        <option value="250">250</option>
                        <option value="500" selected>500</option>
                        <option value="1000">1000</option>
                    </select>
                    <button type="submit" class="btn btn-mini btn-info" onclick="return confirm('{__("travel_core.fm_scan_confirm")}');">
                        <i class="icon-search"></i> {__("travel_core.fm_scan")}
                    </button>
                </form>
            </div>
        </div>
    </div>

{else}

    {* ══════════════════════════ LIST MODE (paginated) ══════════════════════════ *}

    <div style="margin-bottom: 12px;">
        <a href="{"travel_feature_mappings.manage"|fn_url}">&larr; {__("travel_core.fm_back_dashboard")}</a>
    </div>

    <h4 style="margin: 0 0 12px;">{$type_label}
        <span class="muted" style="font-weight: normal;">
            &middot; {__("travel_core.fm_list_count", ["[count]" => $type_stats.total|default:$search.total_items])}
            &middot;
            {if $configured_feature_id > 0}
                {__("travel_core.fm_list_assigned", ["[feature]" => "`$configured_feature_name` #`$configured_feature_id`"|escape:'html'])}
            {else}
                <span class="travel-fm-bad">{__("travel_core.fm_not_configured")}</span>
            {/if}
            {if $type_stats.unmapped > 0}
                &middot; <span class="travel-fm-bad">{__("travel_core.fm_list_no_variant", ["[count]" => $type_stats.unmapped])}</span>
            {/if}
        </span>
    </h4>

    {* Providers that map through aliases but have none for this type:
       FeatureMapper never gives their hotels this feature. *}
    {foreach from=$missing_providers item=gap}
        <div class="travel-fm-banner">
            <span class="travel-fm-banner__text">
                <strong>{__("travel_core.fm_list_missing_title", ["[provider]" => $gap.label|escape:'html'])}</strong>
                {__("travel_core.fm_list_missing_text", ["[provider]" => $gap.label|escape:'html', "[feature]" => $type_label])}
            </span>
            {if $gap.reseedable}
                <form action="{"travel_feature_mappings.reseed_aliases"|fn_url}" method="post">
                    <input type="hidden" name="security_hash" value="{$security_hash}">
                    <input type="hidden" name="provider" value="{$gap.provider|escape:'html'}">
                    <input type="hidden" name="feature_type" value="{$search.feature_type|escape:'html'}">
                    <button type="submit" class="btn btn-warning">{__("travel_core.fm_reseed_provider", ["[provider]" => $gap.label|escape:'html'])}</button>
                </form>
            {else}
                <span class="muted">{__("travel_core.fm_list_missing_manual")}</span>
            {/if}
        </div>
    {/foreach}

    {* Search & Filter Form *}
    <form action="{""|fn_url}" method="get" class="form-inline" style="margin-bottom: 10px;">
        <input type="hidden" name="dispatch" value="travel_feature_mappings.manage" />
        <input type="hidden" name="feature_type" value="{$search.feature_type|escape:'html'}" />

        <input type="search" name="q" value="{$search.q|escape:'html'}" placeholder="{__("travel_core.fm_search_placeholder")}" aria-label="{__("search")}" class="input-xlarge" style="margin-right: 6px;" />

        <select name="provider" aria-label="{__("travel_core.fm_filter_provider")}" style="width: 190px; margin-right: 6px;">
            <option value="">{__("travel_core.fm_filter_all_providers")}</option>
            {foreach from=$providers key=pname item=plabel}
                <option value="{$pname|escape:'html'}" {if $search.provider == $pname}selected{/if}>{__("travel_core.fm_filter_has", ["[provider]" => $plabel|escape:'html'])}</option>
                <option value="!{$pname|escape:'html'}" {if $search.provider == "!`$pname`"}selected{/if}>{__("travel_core.fm_filter_missing", ["[provider]" => $plabel|escape:'html'])}</option>
            {/foreach}
        </select>

        <select name="status" aria-label="{__("status")}" style="width: 120px; margin-right: 6px;">
            <option value="">{__("travel_core.fm_filter_any_status")}</option>
            <option value="A" {if $search.status == 'A'}selected{/if}>{__("active")}</option>
            <option value="D" {if $search.status == 'D'}selected{/if}>{__("disabled")}</option>
        </select>

        <select name="mapping_source" aria-label="{__("travel_core.fm_created_by")}" style="width: 170px; margin-right: 6px;">
            <option value="">{__("travel_core.fm_created_by")}: {__("all")}</option>
            <option value="seed" {if $search.mapping_source == 'seed'}selected{/if}>{__("travel_core.fm_created_by_seed")}</option>
            <option value="auto" {if $search.mapping_source == 'auto'}selected{/if}>{__("travel_core.fm_created_by_auto")}</option>
            <option value="manual" {if $search.mapping_source == 'manual'}selected{/if}>{__("travel_core.fm_created_by_manual")}</option>
        </select>

        <button type="submit" class="btn btn-primary">{__("search")}</button>
        {if $search.q || $search.status || $search.mapping_source || $search.provider}
            <a href="{"travel_feature_mappings.manage?feature_type=`$search.feature_type`"|fn_url}" class="btn">{__("reset")}</a>
        {/if}
    </form>

    {* Top Pagination *}
    {include file="common/pagination.tpl" save_current_url=true}

    {* Mappings Table *}
    {if $mappings}
    <form action="{"travel_feature_mappings.bulk_update"|fn_url}" method="post" name="bulk_form">
        <input type="hidden" name="security_hash" value="{$security_hash}">
        <input type="hidden" name="feature_type" value="{$search.feature_type|escape:'html'}">

        <table class="table table-striped table-hover table-condensed travel-fm-table">
            <thead>
                <tr>
                    <th width="30"><input type="checkbox" onclick="toggleAllCheckboxes(this)" aria-label="{__("travel_core.fm_select_all")}"></th>
                    <th>{__("travel_core.fm_canonical_code")}</th>
                    <th>{__("travel_core.fm_display_en")}</th>
                    <th>{__("travel_core.fm_display_ro")}</th>
                    <th>{__("travel_core.fm_variant")}</th>
                    <th>{__("travel_core.fm_col_values")}</th>
                    <th width="60">{__("status")}</th>
                    <th width="50"></th>
                </tr>
            </thead>
            <tbody>
                {foreach from=$mappings item=m}
                <tr class="{if $m.status == 'D'}muted{/if}">
                    <td><input type="checkbox" name="map_ids[]" value="{$m.map_id}" class="mapping-cb" aria-label="{$m.display_name_en|default:$m.canonical_code|escape:'html'}"></td>
                    <td>
                        <code>{$m.canonical_code|escape:'html'}</code>
                        {if $m.mapping_source == 'auto'}
                            <span class="label label-warning" style="font-size: 9px; vertical-align: middle;" title="{__("travel_core.fm_created_by_auto")}">auto</span>
                        {/if}
                    </td>
                    <td>{$m.display_name_en|truncate:30:"..."|default:'-'|escape:'html'}</td>
                    <td>{$m.display_name_ro|truncate:30:"..."|default:'-'|escape:'html'}</td>
                    <td>
                        {if $m.cscart_variant_id > 0}
                            <span class="label label-success">
                                {if $m.variant_source == 'manual'}<i class="icon-lock" title="{__("travel_core.fm_variant_kept")}"></i>{/if}
                                #{$m.cscart_variant_id}
                            </span>
                            {$m.variant_name|truncate:24:"..."|escape:'html'}
                        {else}
                            <span class="travel-fm-bad" title="{__("travel_core.fm_tile_no_variant_hint")}">&times; {__("travel_core.fm_no_variant")}</span>
                        {/if}
                    </td>
                    <td>
                        <span class="travel-fm-values">
                            {if $coverage}
                                {foreach from=$coverage item=chip}
                                    {$vals = $m.provider_values[$chip.provider]|default:''}
                                    {if $vals}
                                        <span class="travel-fm-values__row">
                                            <span class="travel-fm-values__provider">{$chip.label|escape:'html'}</span>
                                            {foreach from=$vals item=val}<code>{$val|escape:'html'}</code>{/foreach}
                                        </span>
                                    {elseif $chip.state == 'missing'}
                                        <span class="travel-fm-values__row travel-fm-values__row--missing">
                                            <span class="travel-fm-values__provider">{$chip.label|escape:'html'}</span>
                                            <span class="travel-fm-values__none">{__("travel_core.fm_no_alias")}</span>
                                        </span>
                                    {/if}
                                {/foreach}
                            {else}
                                {foreach from=$m.provider_values key=src item=vals}
                                    <span class="travel-fm-values__row">
                                        <span class="travel-fm-values__provider">{$src|capitalize|escape:'html'}</span>
                                        {foreach from=$vals item=val}<code>{$val|escape:'html'}</code>{/foreach}
                                    </span>
                                {foreachelse}
                                    <span class="muted">&mdash;</span>
                                {/foreach}
                            {/if}
                        </span>
                    </td>
                    <td>
                        {if $m.status == 'A'}
                            <span class="label label-success">{__("active")}</span>
                        {else}
                            <span class="label">{__("disabled")}</span>
                        {/if}
                    </td>
                    <td>
                        <a href="{"travel_feature_mappings.edit?map_id=`$m.map_id`"|fn_url}" class="btn btn-mini btn-default" title="{__("edit")}" aria-label="{__("edit")} {$m.display_name_en|default:$m.canonical_code|escape:'html'}">
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
