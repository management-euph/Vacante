{*
 * Travel Core - Unmapped API Values
 *
 * The raw values providers sent that match no alias of theirs: their hotels
 * get nothing for them. Each row links the value to an existing mapping (an
 * alias of its provider), creates a new mapping for it, or dismisses it.
 * Row actions are separate forms; the bulk checkboxes join the dismiss form
 * through the form= attribute (forms cannot nest).
 *}

{capture name="mainbox"}

{capture name="unmapped_filters"}
    <input type="hidden" name="api_source" value="{$search.api_source|escape:'html'}">
    <input type="hidden" name="feature_type" value="{$search.feature_type|escape:'html'}">
    <input type="hidden" name="q" value="{$search.q|escape:'html'}">
    <input type="hidden" name="page" value="{$search.page|escape:'html'}">
{/capture}

<div class="travel-unmapped-values">

    <div style="margin-bottom: 12px;">
        <a href="{"travel_feature_mappings.manage"|fn_url}">&larr; {__("travel_core.fm_back_dashboard")}</a>
    </div>

    <p class="travel-fm-intro">{__("travel_core.fm_unmapped_intro")}</p>

    {* What is waiting, per provider and type (click to filter) *}
    {if $unmapped_summary}
        <div class="travel-fm-chips" style="margin-bottom: 14px;">
            {foreach from=$unmapped_summary item=chip}
                {$is_current = $search.api_source == $chip.provider && $search.feature_type == $chip.feature_type}
                <a class="travel-fm-chip{if $is_current} travel-fm-chip--ok{/if}" href="{"travel_feature_mappings.unmapped?api_source=`$chip.provider`&feature_type=`$chip.feature_type`"|fn_url}">
                    {$chip.label|escape:'html'} &middot; {$chip.type_label|escape:'html'}: {$chip.values}
                </a>
            {/foreach}
        </div>
    {/if}

    {* Filters *}
    <form action="{""|fn_url}" method="get" class="form-inline" style="margin-bottom: 10px;">
        <input type="hidden" name="dispatch" value="travel_feature_mappings.unmapped" />

        <input type="search" name="q" value="{$search.q|escape:'html'}" placeholder="{__("travel_core.fm_unmapped_search")}" aria-label="{__("search")}" class="input-large" style="margin-right: 6px;" />

        <select name="api_source" aria-label="{__("travel_core.fm_filter_provider")}" style="width: 160px; margin-right: 6px;">
            <option value="">{__("travel_core.fm_filter_all_providers")}</option>
            {foreach from=$unmapped_providers key=pname item=plabel}
                <option value="{$pname|escape:'html'}" {if $search.api_source == $pname}selected{/if}>{$plabel|escape:'html'}</option>
            {/foreach}
        </select>

        <select name="feature_type" aria-label="{__("travel_core.fm_col_feature")}" style="width: 180px; margin-right: 6px;">
            <option value="">{__("travel_core.fm_unmapped_any_type")}</option>
            <option value="facility" {if $search.feature_type == 'facility'}selected{/if}>{__("travel_core.fm_unmapped_any_facility")}</option>
            {foreach from=$type_labels key=tname item=tlabel}
                <option value="{$tname|escape:'html'}" {if $search.feature_type == $tname}selected{/if}>{$tlabel|escape:'html'}</option>
            {/foreach}
        </select>

        <button type="submit" class="btn btn-primary">{__("search")}</button>
        {if $search.api_source || $search.feature_type || $search.q}
            <a href="{"travel_feature_mappings.unmapped"|fn_url}" class="btn">{__("reset")}</a>
        {/if}
    </form>

    {* Pagination *}
    {include file="common/pagination.tpl" save_current_url=true}

    {if $unmapped_values}
    <table class="table table-striped table-hover table-condensed travel-fm-table">
        <thead>
            <tr>
                <th width="30"><input type="checkbox" onclick="var on = this.checked; document.querySelectorAll('.mapping-cb').forEach(function (cb) {ldelim} cb.checked = on; {rdelim});" aria-label="{__("travel_core.fm_select_all")}"></th>
                <th>{__("travel_core.fm_filter_provider")}</th>
                <th>{__("travel_core.fm_col_feature")}</th>
                <th>{__("travel_core.fm_unmapped_col_value")}</th>
                <th width="60">{__("travel_core.fm_unmapped_col_hotels")}</th>
                <th width="90">{__("travel_core.fm_unmapped_col_last_seen")}</th>
                <th>{__("travel_core.fm_unmapped_col_link")}</th>
                <th width="150"></th>
            </tr>
        </thead>
        <tbody>
            {foreach from=$unmapped_values item=u}
            {$u_type = $u.feature_type}
            <tr>
                <td><input type="checkbox" name="unmapped_ids[]" value="{$u.unmapped_id}" class="mapping-cb" form="unmapped_bulk" aria-label="{$u.api_value|escape:'html'}"></td>
                <td><span class="travel-fm-chip travel-fm-chip--ok">{$unmapped_providers[$u.api_source]|default:$u.api_source|escape:'html'}</span></td>
                <td>{$type_labels.$u_type|default:$u_type|escape:'html'}</td>
                <td>
                    <code>{$u.api_value|escape:'html'}</code>
                    {if $u.api_label && $u.api_label != $u.api_value}<br><span class="muted">{$u.api_label|escape:'html'}</span>{/if}
                </td>
                <td>{$u.hotel_count}</td>
                <td class="muted" title="{__("travel_core.fm_unmapped_first_seen", ["[date]" => $u.first_seen_at|date_format:"%Y-%m-%d"])}">{$u.last_seen_at|date_format:"%Y-%m-%d"}</td>
                <td>
                    <form action="{"travel_feature_mappings.link_unmapped"|fn_url}" method="post" class="form-inline" style="margin: 0;">
                        <input type="hidden" name="security_hash" value="{$security_hash}">
                        <input type="hidden" name="unmapped_id" value="{$u.unmapped_id}">
                        {$smarty.capture.unmapped_filters nofilter}
                        <select name="map_id" required class="input-large" style="margin: 0;" aria-label="{__("travel_core.fm_unmapped_link_to", ["[value]" => $u.api_value|escape:'html'])}">
                            <option value="">{__("travel_core.fm_unmapped_choose")}</option>
                            {foreach from=$u.link_types item=lt}
                                {if $link_options.$lt}
                                    <optgroup label="{$type_labels.$lt|default:$lt|escape:'html'}">
                                        {foreach from=$link_options.$lt item=opt}
                                            <option value="{$opt.map_id}">{$opt.label|escape:'html'}</option>
                                        {/foreach}
                                    </optgroup>
                                {/if}
                            {/foreach}
                        </select>
                        <button type="submit" class="btn btn-primary">{__("travel_core.fm_unmapped_link")}</button>
                    </form>
                </td>
                <td class="nowrap">
                    <form action="{"travel_feature_mappings.map_unmapped"|fn_url}" method="post" style="display: inline; margin: 0;">
                        <input type="hidden" name="security_hash" value="{$security_hash}">
                        <input type="hidden" name="unmapped_id" value="{$u.unmapped_id}">
                        <button type="submit" class="btn btn-mini" title="{__("travel_core.fm_unmapped_create_hint")}" onclick="return confirm('{__("travel_core.fm_unmapped_create_confirm", ["[value]" => $u.api_value])|escape:'javascript'}');">{__("travel_core.fm_unmapped_create")}</button>
                    </form>
                    <form action="{"travel_feature_mappings.dismiss_unmapped"|fn_url}" method="post" style="display: inline; margin: 0;">
                        <input type="hidden" name="security_hash" value="{$security_hash}">
                        <input type="hidden" name="unmapped_ids[]" value="{$u.unmapped_id}">
                        {$smarty.capture.unmapped_filters nofilter}
                        <button type="submit" class="btn btn-mini" title="{__("travel_core.fm_unmapped_dismiss_hint")}">{__("travel_core.fm_unmapped_dismiss")}</button>
                    </form>
                </td>
            </tr>
            {/foreach}
        </tbody>
    </table>

    <form id="unmapped_bulk" action="{"travel_feature_mappings.dismiss_unmapped"|fn_url}" method="post" class="well well-small" style="margin-top: 10px;">
        <input type="hidden" name="security_hash" value="{$security_hash}">
        {$smarty.capture.unmapped_filters nofilter}
        <strong>{__("travel_core.fm_with_selected")}:</strong>
        <button type="submit" class="btn btn-mini" onclick="return confirm('{__("travel_core.fm_unmapped_dismiss_confirm")|escape:'javascript'}');">{__("travel_core.fm_unmapped_dismiss")}</button>
        <span class="muted" style="margin-left: 8px;">{__("travel_core.fm_unmapped_dismiss_hint")}</span>
    </form>

    {else}
    <p class="no-items">
        {if $search.api_source || $search.feature_type || $search.q}
            {__("travel_core.fm_unmapped_none_filtered")}
        {else}
            <i class="icon-ok text-success"></i> {__("travel_core.fm_unmapped_none")}
        {/if}
    </p>
    {/if}

    {* Bottom Pagination *}
    {include file="common/pagination.tpl"}

</div>

{/capture}

{capture name="buttons"}
    <a href="{"travel_feature_mappings.manage"|fn_url}" class="btn">
        <i class="icon-th-large"></i> {__("travel_core.feature_mappings")}
    </a>
{/capture}

{include file="common/mainbox.tpl"
    title=__("travel_core.fm_unmapped_values")
    content=$smarty.capture.mainbox
    buttons=$smarty.capture.buttons
}
