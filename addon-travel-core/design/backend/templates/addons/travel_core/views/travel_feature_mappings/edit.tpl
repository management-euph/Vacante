{*
 * Travel Core - Feature Mapping Edit Page
 * Left: what the shop shows (names, CS-Cart feature + variant, AJAX variant
 * loading). Right: one card per provider with the values it sends for this
 * mapping (its aliases), flagged when a provider that needs one has none.
 *}

{capture name="mainbox"}

<div style="margin-bottom: 10px;">
    <a href="{"travel_feature_mappings.manage?feature_type=`$mapping.feature_type`"|fn_url}">&larr; {$type_label|escape:'html'}</a>
</div>

<p style="margin: 0 0 14px;">
    <span class="muted">{$type_label|escape:'html'} &middot; {__("travel_core.fm_canonical_code")} <code>{$mapping.canonical_code|escape:'html'}</code></span>
    <span class="label label-info" style="margin-left: 8px;">{__("travel_core.fm_created_by")}: {__($created_by_key)}</span>
</p>

<div class="travel-fm-edit">

    {* ── What the shop shows ── *}
    <form action="{"travel_feature_mappings.update"|fn_url}" method="post" class="travel-fm-panel">
        <input type="hidden" name="security_hash" value="{$security_hash}">
        <input type="hidden" name="map_id" value="{$mapping.map_id}">
        <input type="hidden" name="feature_type_filter" value="{$mapping.feature_type|escape:'html'}">

        <h4>{__("travel_core.fm_edit_shop_title")}</h4>

        <label class="travel-fm-field" for="display_name_en">{__("travel_core.fm_display_en")}
            <input type="text" name="mapping_data[display_name_en]" id="display_name_en" value="{$mapping.display_name_en|escape:'html'}" />
        </label>
        <label class="travel-fm-field" for="display_name_ro">{__("travel_core.fm_display_ro")}
            <input type="text" name="mapping_data[display_name_ro]" id="display_name_ro" value="{$mapping.display_name_ro|escape:'html'}" />
        </label>

        <label class="travel-fm-field" for="cscart_feature_id">{__("travel_core.fm_cs_feature")}
            <select name="mapping_data[cscart_feature_id]" id="cscart_feature_id" onchange="loadVariants(this.value)">
                <option value="0">-- {__("travel_core.fm_not_mapped")} --</option>
                {foreach from=$all_features item=f}
                    {assign var="type_label_f" value=""}
                    {if $f.feature_type == 'M'}{assign var="type_label_f" value="Multi"}
                    {elseif $f.feature_type == 'S'}{assign var="type_label_f" value="Select"}
                    {elseif $f.feature_type == 'C'}{assign var="type_label_f" value="Checkbox"}
                    {elseif $f.feature_type == 'T'}{assign var="type_label_f" value="Text"}
                    {elseif $f.feature_type == 'N'}{assign var="type_label_f" value="Number"}
                    {elseif $f.feature_type == 'O'}{assign var="type_label_f" value="Date"}
                    {else}{assign var="type_label_f" value=$f.feature_type}{/if}
                    <option value="{$f.feature_id}" {if $mapping.cscart_feature_id == $f.feature_id}selected{/if}>
                        {$f.description|escape:'html'|default:"Feature"} #{$f.feature_id} ({$type_label_f})
                    </option>
                {/foreach}
            </select>
            <span class="muted" style="font-weight: normal;">{__("travel_core.fm_feature_hint")}</span>
        </label>

        <label class="travel-fm-field" for="cscart_variant_id">{__("travel_core.fm_variant")}
            <select name="mapping_data[cscart_variant_id]" id="cscart_variant_id">
                <option value="0">-- {__("travel_core.fm_not_mapped")} --</option>
                {if $feature_variants}
                    {foreach from=$feature_variants item=v}
                        <option value="{$v.variant_id}" {if $mapping.cscart_variant_id == $v.variant_id}selected{/if}>
                            #{$v.variant_id} &mdash; {$v.name|escape:'html'}
                        </option>
                    {/foreach}
                {/if}
            </select>
            <span class="muted" style="font-weight: normal;">{__("travel_core.fm_variant_hint")}</span>
        </label>

        <input type="hidden" name="mapping_data[variant_lock]" value="N">
        <label class="checkbox" for="variant_lock">
            <input type="checkbox" name="mapping_data[variant_lock]" id="variant_lock" value="Y" {if $mapping.variant_source == 'manual'}checked{/if}>
            {__("travel_core.fm_keep_variant")}
        </label>

        <input type="hidden" name="mapping_data[status]" value="D">
        <label class="checkbox" for="mapping_active">
            <input type="checkbox" name="mapping_data[status]" id="mapping_active" value="A" {if $mapping.status == 'A'}checked{/if}>
            {__("active")}
        </label>

        <details>
            <summary>{__("travel_core.fm_advanced")}</summary>
            <label class="travel-fm-field" for="position" style="margin-top: 8px;">{__("position")}
                <input type="text" name="mapping_data[position]" id="position" value="{$mapping.position}" style="width: 80px;" />
            </label>
            <div>{__("travel_core.fm_map_id")}: {$mapping.map_id} &middot; {__("travel_core.fm_feature_type")}: <code>{$mapping.feature_type|escape:'html'}</code></div>
            {if $mapping.last_used_at}
                <div>{__("travel_core.fm_last_used", ["[date]" => $mapping.last_used_at|date_format:"%Y-%m-%d %H:%M"])}</div>
            {/if}
        </details>

        <button type="submit" class="btn btn-primary"><i class="icon-ok"></i> {__("save")}</button>
        <a href="{"travel_feature_mappings.manage?feature_type=`$mapping.feature_type`"|fn_url}" class="btn">{__("cancel")}</a>
    </form>

    {* ── What each provider sends ── *}
    {$mapping_name = $mapping.display_name_en|default:$mapping.canonical_code}
    <div>
        <h4 style="margin: 0 0 4px;">{__("travel_core.fm_edit_providers_title", ["[name]" => $mapping_name|escape:'html'])}</h4>
        <p class="muted" style="margin: 0 0 12px;">{__("travel_core.fm_edit_providers_hint")}</p>

        {foreach from=$alias_cards item=card}
            <div class="travel-fm-card travel-fm-card--{$card.state}">
                <div class="travel-fm-card__head">
                    <span class="travel-fm-card__name">{$card.label|escape:'html'}</span>
                    <span class="travel-fm-card__state">
                        {if $card.state == 'ok'}
                            {__("travel_core.fm_card_count", ["[count]" => $card.aliases|count])}
                        {elseif $card.state == 'missing'}
                            {__("travel_core.fm_card_missing", ["[provider]" => $card.label|escape:'html'])}
                        {else}
                            {__("travel_core.fm_card_unused", ["[feature]" => $type_label|escape:'html'])}
                        {/if}
                    </span>
                </div>

                {foreach from=$card.aliases item=alias}
                    <div class="travel-fm-alias">
                        <code>{$alias.api_value|escape:'html'}</code>
                        <span class="travel-fm-alias__match">{if $alias.match_type == 'prefix'}{__("travel_core.fm_match_prefix")}{elseif $alias.match_type == 'contains'}{__("travel_core.fm_match_contains")}{else}{__("travel_core.fm_match_exact")}{/if}</span>
                        <form action="{"travel_feature_mappings.delete_alias"|fn_url}" method="post">
                            <input type="hidden" name="security_hash" value="{$security_hash}">
                            <input type="hidden" name="alias_id" value="{$alias.alias_id}">
                            <input type="hidden" name="map_id" value="{$mapping.map_id}">
                            <button type="submit" class="btn btn-mini" onclick="return confirm('{__("travel_core.fm_alias_delete_confirm")|escape:'javascript'}');" aria-label="{__("travel_core.fm_remove_alias", ["[value]" => $alias.api_value|escape:'html'])}">{__("travel_core.fm_remove")}</button>
                        </form>
                    </div>
                {/foreach}

                {if $card.state == 'unused'}<details><summary class="muted">{__("travel_core.fm_add_anyway")}</summary>{/if}
                <form action="{"travel_feature_mappings.add_alias"|fn_url}" method="post" class="travel-fm-card__add">
                    <input type="hidden" name="security_hash" value="{$security_hash}">
                    <input type="hidden" name="map_id" value="{$mapping.map_id}">
                    <input type="hidden" name="api_source" value="{$card.provider|escape:'html'}">
                    <input type="text" name="api_value" required class="input-medium"
                        placeholder="{__("travel_core.fm_provider_value", ["[provider]" => $card.label|escape:'html'])}"
                        aria-label="{__("travel_core.fm_provider_value", ["[provider]" => $card.label|escape:'html'])}">
                    <select name="match_type" class="input-small" aria-label="{__("travel_core.fm_match_type")}">
                        <option value="exact">{__("travel_core.fm_match_exact")}</option>
                        <option value="prefix">{__("travel_core.fm_match_prefix")}</option>
                        <option value="contains">{__("travel_core.fm_match_contains")}</option>
                    </select>
                    <button type="submit" class="btn {if $card.state == 'missing'}btn-warning{else}btn-default{/if}">{__("travel_core.fm_add_provider_alias", ["[provider]" => $card.label|escape:'html'])}</button>
                </form>
                {if $card.state == 'unused'}</details>{/if}
            </div>
        {/foreach}

        {* Any other source (a provider that is not installed yet) *}
        <details class="travel-fm-card travel-fm-card--unused">
            <summary class="muted">{__("travel_core.fm_other_source")}</summary>
            <form action="{"travel_feature_mappings.add_alias"|fn_url}" method="post" class="travel-fm-card__add">
                <input type="hidden" name="security_hash" value="{$security_hash}">
                <input type="hidden" name="map_id" value="{$mapping.map_id}">
                <input type="text" name="api_source" placeholder="{__("travel_core.fm_api_source")}" aria-label="{__("travel_core.fm_api_source")}" class="input-small" required />
                <input type="text" name="api_value" placeholder="{__("travel_core.fm_api_value")}" aria-label="{__("travel_core.fm_api_value")}" class="input-medium" required />
                <select name="match_type" class="input-small" aria-label="{__("travel_core.fm_match_type")}">
                    <option value="exact">{__("travel_core.fm_match_exact")}</option>
                    <option value="prefix">{__("travel_core.fm_match_prefix")}</option>
                    <option value="contains">{__("travel_core.fm_match_contains")}</option>
                </select>
                <button type="submit" class="btn btn-default"><i class="icon-plus"></i> {__("travel_core.fm_add_alias")}</button>
            </form>
        </details>
    </div>
</div>

{/capture}

{assign var="_fm_title" value=$mapping.display_name_en|default:$mapping.canonical_code}

{capture name="buttons"}{/capture}

{include file="common/mainbox.tpl"
    title=$_fm_title
    content=$smarty.capture.mainbox
    buttons=$smarty.capture.buttons
}

<script>
function loadVariants(featureId) {
    var select = document.getElementById('cscart_variant_id');
    select.innerHTML = '<option value="0">Loading...</option>';

    if (!featureId || featureId == '0') {
        select.innerHTML = '<option value="0">-- {__("travel_core.fm_not_mapped")} --</option>';
        return;
    }

    var xhr = new XMLHttpRequest();
    xhr.open('GET', '{"travel_feature_mappings.get_variants"|fn_url}' + '&feature_id=' + featureId, true);
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4 && xhr.status === 200) {
            var variants = JSON.parse(xhr.responseText);
            select.innerHTML = '';
            var defaultOpt = document.createElement('option');
            defaultOpt.value = '0';
            defaultOpt.textContent = '-- {__("travel_core.fm_not_mapped")|escape:'javascript'} --';
            select.appendChild(defaultOpt);
            for (var i = 0; i < variants.length; i++) {
                var opt = document.createElement('option');
                opt.value = variants[i].variant_id;
                opt.textContent = '#' + variants[i].variant_id + ' \u2014 ' + variants[i].name;
                select.appendChild(opt);
            }
        }
    };
    xhr.send();
}
</script>
