{*
 * Feature Mappings › Circuits — which CS-Cart feature each circuit fact is
 * written to. The feature keeps its own style (number, text, list), so the
 * storefront filters stay CS-Cart's. Transport and month are linked to the
 * values the admin created; countries, departure cities and tags get a
 * value created when it is missing.
 *}
{capture name="mainbox"}

{include file="addons/travel_core/views/travel_feature_mappings/tabs.tpl"}

<p class="travel-fm-intro">{__("travel_core.fmc_intro")}</p>

<form action="{"travel_circuit_features.update"|fn_url}" method="post" name="circuit_features_form" class="form-horizontal">
    <input type="hidden" name="security_hash" value="{$security_hash}">

    <div class="control-group">
        <label class="control-label" for="fmc_group">{__("travel_core.fmc_group")}</label>
        <div class="controls">
            <select id="fmc_group" name="circuit_features[group_id]">
                <option value="0">{__("travel_core.fmc_group_any")}</option>
                {foreach $circuit_groups as $group_id => $group_name}
                    <option value="{$group_id}"{if $circuit_config.group_id == $group_id} selected="selected"{/if}>{$group_name}</option>
                {/foreach}
            </select>
            <p class="muted description">{__("travel_core.fmc_group_hint")}</p>
        </div>
    </div>

    <div class="table-responsive-wrapper">
    <table class="table table-middle table--relative table-responsive">
        <thead>
            <tr>
                <th width="18%">{__("travel_core.fmc_col_fact")}</th>
                <th width="24%">{__("travel_core.fmc_col_feature")}</th>
                <th width="16%">{__("travel_core.fmc_col_type")}</th>
                <th>{__("travel_core.fmc_col_rule")}</th>
            </tr>
        </thead>
        <tbody>
        {foreach $circuit_fields as $field}
            {$selected = $circuit_config.features.$field}
            <tr>
                <td data-th="{__("travel_core.fmc_col_fact")}"><strong>{__("travel_core.fmc_field_`$field`")}</strong></td>
                <td data-th="{__("travel_core.fmc_col_feature")}">
                    <select name="circuit_features[features][{$field}]" aria-label="{__("travel_core.fmc_field_`$field`")}">
                        <option value="0">{__("travel_core.fmc_not_mapped")}</option>
                        {foreach $circuit_feature_list as $feature_id => $feature}
                            <option value="{$feature_id}"{if $selected == $feature_id} selected="selected"{/if}>{$feature.name} {if $feature.group}– {$feature.group}{else}({__("travel_core.fmc_ungrouped")}){/if}</option>
                        {/foreach}
                    </select>
                </td>
                <td data-th="{__("travel_core.fmc_col_type")}">
                    {if $selected && $circuit_feature_list.$selected}{$feature_style = $circuit_feature_list.$selected.style}{__("travel_core.fmc_type_`$feature_style`")}{else}<span class="muted">—</span>{/if}
                </td>
                <td data-th="{__("travel_core.fmc_col_rule")}">{__("travel_core.fmc_rule_`$field`")}</td>
            </tr>
        {/foreach}
        </tbody>
    </table>
    </div>

    <h4>{__("travel_core.fmc_transport_values")}</h4>
    {if $transport_variants}
        {foreach $circuit_transports as $code}
            <div class="control-group">
                <label class="control-label" for="fmc_transport_{$code}"><code>{$code}</code></label>
                <div class="controls">
                    <select id="fmc_transport_{$code}" name="circuit_features[values][transport][{$code}]">
                        <option value="0">{__("travel_core.fmc_not_linked")}</option>
                        {foreach $transport_variants as $variant_id => $variant_name}
                            <option value="{$variant_id}"{if $circuit_config.values.transport.$code == $variant_id} selected="selected"{/if}>{$variant_name}</option>
                        {/foreach}
                    </select>
                </div>
            </div>
        {/foreach}
    {else}
        <p class="muted">{__("travel_core.fmc_values_first")}</p>
    {/if}

    <h4>{__("travel_core.fmc_month_values")}</h4>
    {if $month_variants}
        <p class="muted">{__("travel_core.fmc_month_hint")}</p>
        {for $month = 1 to 12}
            <div class="control-group">
                <label class="control-label" for="fmc_month_{$month}">{__("travel_core.fmc_month_`$month`")}</label>
                <div class="controls">
                    <select id="fmc_month_{$month}" name="circuit_features[values][month][{$month}]">
                        <option value="0">{__("travel_core.fmc_not_linked")}</option>
                        {foreach $month_variants as $variant_id => $variant_name}
                            <option value="{$variant_id}"{if $circuit_config.values.month.$month == $variant_id} selected="selected"{/if}>{$variant_name}</option>
                        {/foreach}
                    </select>
                </div>
            </div>
        {/for}
    {else}
        <p class="muted">{__("travel_core.fmc_values_first")}</p>
    {/if}

    <div class="buttons-container">
        <button type="submit" class="btn btn-primary">{__("save")}</button>
    </div>
</form>

<form action="{"travel_circuit_features.apply"|fn_url}" method="post" class="form-inline" style="margin-top: 15px;">
    <input type="hidden" name="security_hash" value="{$security_hash}">
    <button type="submit" class="btn" onclick="return confirm('{__("travel_core.fmc_apply_confirm")|escape:"javascript"}');">
        <i class="icon-magic"></i> {__("travel_core.fmc_apply")}
    </button>
    <span class="muted">{__("travel_core.fmc_apply_hint")}</span>
</form>

{/capture}

{include file="common/mainbox.tpl"
    title=__("travel_core.feature_mappings")
    content=$smarty.capture.mainbox
}
