{*
    The type card: General, Rules. The fields of the type are set in the
    Profile fields list, on the view of the type (linked from General).
*}
{$type = $euph_pt_type}
{$id = $type.type_id|default:0}
{$is_vendor = ($euph_pt_kind == "V")}
{$is_standard = ($type.is_standard == "Y")}

{capture name="mainbox"}
<form action="{""|fn_url}" method="post" name="euph_profile_type_form" id="euph_profile_type_form" class="form-horizontal form-edit euph-pt-card{if ""|fn_check_form_permissions} cm-hide-inputs{/if}">
    <input type="hidden" name="type_id" value="{$id}" />
    <input type="hidden" name="user_type" value="{$euph_pt_kind}" />
    <input type="hidden" name="type_data[user_type]" value="{$euph_pt_kind}" />
    <input type="hidden" name="selected_section" id="selected_section" value="{$smarty.request.selected_section}" />

    {capture name="tabsbox"}
        {* ── General ─────────────────────────────────────────────── *}
        <div id="content_general">
            <div class="control-group">
                <label class="control-label">{__("euph_profile_types.for")}:</label>
                <div class="controls">
                    <span class="shift-input">{if $is_vendor}{__("euph_profile_types.for_vendors")}{else}{__("euph_profile_types.for_customers")}{/if}</span>
                </div>
            </div>

            <div class="control-group">
                <label for="elm_euph_pt_name" class="control-label cm-required">{__("name")}:</label>
                <div class="controls">
                    <input type="text" id="elm_euph_pt_name" name="type_data[name]" value="{$type.name}" class="input-large" maxlength="255" />
                    {if $is_standard}<p class="muted description">{__("euph_profile_types.standard_type_note")}</p>{/if}
                </div>
            </div>

            <div class="control-group">
                <label for="elm_euph_pt_code" class="control-label{if !$is_standard} cm-required{/if}">{__("code")}:</label>
                <div class="controls">
                    {if $is_standard}
                        <span class="shift-input euph-pt-code euph-pt-code--large">{$type.code}</span>
                    {else}
                        <input type="text" id="elm_euph_pt_code" name="type_data[code]" value="{$type.code}" class="input-small euph-pt-code-input" maxlength="32" pattern="[A-Za-z0-9_]+" />
                    {/if}
                    <p class="muted description">{__("euph_profile_types.code_hint")}</p>
                </div>
            </div>

            {if $id}
                {if $is_vendor}{$euph_pt_fields_type = "S"}{else}{$euph_pt_fields_type = "U"}{/if}
                <div class="control-group">
                    <label class="control-label">{__("euph_profile_types.tab_fields")}:</label>
                    <div class="controls">
                        <a class="shift-input euph-pt-fields-link" href="{"profile_fields.manage?profile_type=`$euph_pt_fields_type`&euph_view=`$id`"|fn_url}">{__("euph_profile_types.open_in_profile_fields")}&nbsp;&rsaquo;</a>
                    </div>
                </div>
            {/if}

            <div class="control-group">
                <label for="elm_euph_pt_description" class="control-label">{__("description")}:</label>
                <div class="controls">
                    <textarea id="elm_euph_pt_description" name="type_data[description]" cols="55" rows="3" class="input-large">{$type.description}</textarea>
                    <p class="muted description">{__("euph_profile_types.description_hint")}</p>
                </div>
            </div>

            <div class="control-group">
                <label for="elm_euph_pt_position" class="control-label">{__("position")}:</label>
                <div class="controls">
                    <input type="text" id="elm_euph_pt_position" name="type_data[position]" value="{$type.position}" size="3" class="input-micro" />
                </div>
            </div>

            <div class="control-group">
                <label for="elm_euph_pt_default" class="control-label">{__("euph_profile_types.default_type")}:</label>
                <div class="controls">
                    <input type="hidden" name="type_data[is_default]" value="N" />
                    <input type="checkbox" id="elm_euph_pt_default" name="type_data[is_default]" value="Y" {if $type.is_default == "Y"}checked="checked"{/if} />
                    <p class="muted description">{if $is_vendor}{__("euph_profile_types.default_type_vendor_hint")}{else}{__("euph_profile_types.default_type_hint")}{/if}</p>
                </div>
            </div>

            {if $is_standard}
                <div class="control-group">
                    <label class="control-label">{__("status")}:</label>
                    <div class="controls"><span class="shift-input">{__("active")}</span></div>
                </div>
            {else}
                {include file="common/select_status.tpl" input_name="type_data[status]" id="elm_euph_pt_status" obj=$type hidden=true}
                <div class="control-group">
                    <div class="controls">
                        <p class="muted description">{__("euph_profile_types.status_hint")}</p>
                    </div>
                </div>
            {/if}

            {if $euph_pt_links}
                {include file="common/subheader.tpl" title=__("euph_profile_types.registration_links") target="#euph_pt_links"}
                <div id="euph_pt_links" class="in collapse">
                    <p class="muted">{if $is_vendor}{__("euph_profile_types.application_links_hint")}{else}{__("euph_profile_types.registration_links_hint")}{/if}</p>
                    {foreach ["open", "locked"] as $link_kind}
                        {$link = $euph_pt_links[$link_kind]|fn_url:"C"}
                        <div class="control-group">
                            <label class="control-label" for="elm_euph_pt_link_{$link_kind}">{__("euph_profile_types.link_`$link_kind`")}:</label>
                            <div class="controls">
                                <div class="input-append euph-pt-link">
                                    <input type="text" id="elm_euph_pt_link_{$link_kind}" value="{$link}" readonly="readonly" class="input-xxlarge cm-select-text" />
                                    <button type="button" class="btn cm-euph-pt-copy" data-ca-euph-pt-target="#elm_euph_pt_link_{$link_kind}">{__("euph_profile_types.copy")}</button>
                                </div>
                            </div>
                        </div>
                    {/foreach}
                    <div class="control-group">
                        <label class="control-label" for="elm_euph_pt_new_links">{__("euph_profile_types.new_links")}:</label>
                        <div class="controls">
                            <input type="hidden" name="type_data[new_link_key]" value="N" />
                            <input type="checkbox" name="type_data[new_link_key]" id="elm_euph_pt_new_links" value="Y" />
                            <p class="muted description">{__("euph_profile_types.new_links_hint")}</p>
                        </div>
                    </div>
                </div>
            {/if}
        </div>

        {* ── Rules ───────────────────────────────────────────────── *}
        <div id="content_rules" class="hidden">
            {include file="common/subheader.tpl" title=__("usergroups") target="#euph_pt_usergroups"}
            <div id="euph_pt_usergroups" class="in collapse">
                <div class="control-group">
                    <label class="control-label">{__("euph_profile_types.grants_usergroups")}:</label>
                    <div class="controls">
                        <input type="hidden" name="type_data[usergroup_ids]" value="" />
                        {foreach $euph_pt_usergroups as $usergroup_id => $usergroup}
                            <label class="checkbox" for="elm_euph_pt_ug_{$usergroup_id}">
                                <input type="checkbox" id="elm_euph_pt_ug_{$usergroup_id}" name="type_data[usergroup_ids][]" value="{$usergroup_id}" {if $usergroup_id|in_array:$type.usergroup_ids}checked="checked"{/if} />
                                {$usergroup}
                            </label>
                        {foreachelse}
                            <p class="muted shift-input">{__("euph_profile_types.no_usergroups", ["[url]" => "usergroups.manage"|fn_url]) nofilter}</p>
                        {/foreach}
                        <p class="muted description">{__("euph_profile_types.usergroups_hint")}</p>
                    </div>
                </div>
            </div>

            {if !$is_vendor}
                {include file="common/subheader.tpl" title=__("euph_profile_types.activation") target="#euph_pt_activation"}
                <div id="euph_pt_activation" class="in collapse">
                    <div class="control-group">
                        <label class="control-label">{__("euph_profile_types.new_accounts")}:</label>
                        <div class="controls">
                            {foreach ["S" => "activation_store", "A" => "activation_instant", "P" => "activation_approval"] as $activation => $label}
                                <label class="radio" for="elm_euph_pt_activation_{$activation}">
                                    <input type="radio" id="elm_euph_pt_activation_{$activation}" name="type_data[activation]" value="{$activation}" {if $type.activation == $activation}checked="checked"{/if} />
                                    {__("euph_profile_types.`$label`")}
                                </label>
                            {/foreach}
                            <p class="muted description">{__("euph_profile_types.activation_hint")}</p>
                        </div>
                    </div>
                </div>

                {include file="common/subheader.tpl" title=__("euph_profile_types.invoicing") target="#euph_pt_invoicing"}
                <div id="euph_pt_invoicing" class="in collapse">
                    <div class="control-group">
                        <label class="control-label">{__("euph_profile_types.invoice_as")}:</label>
                        <div class="controls">
                            {foreach ["" => "invoice_as_order_data", "C" => "invoice_as_company", "P" => "invoice_as_person"] as $invoice_as => $label}
                                <label class="radio" for="elm_euph_pt_invoice_as_{$invoice_as|default:"D"}">
                                    <input type="radio" id="elm_euph_pt_invoice_as_{$invoice_as|default:"D"}" name="type_data[invoice_as]" value="{$invoice_as}" {if $type.invoice_as|default:"" == $invoice_as}checked="checked"{/if} />
                                    {__("euph_profile_types.`$label`")}
                                </label>
                            {/foreach}
                            <p class="muted description">{__("euph_profile_types.invoice_as_hint")}</p>
                        </div>
                    </div>
                </div>
            {else}
                <input type="hidden" name="type_data[activation]" value="S" />
            {/if}

            {if $euph_pt_storefronts|count > 1}
                {include file="common/subheader.tpl" title=__("storefronts") target="#euph_pt_storefronts"}
                <div id="euph_pt_storefronts" class="in collapse">
                    <div class="control-group">
                        <label class="control-label">{__("euph_profile_types.works_on")}:</label>
                        <div class="controls">
                            <input type="hidden" name="type_data[storefront_ids]" value="" />
                            {foreach $euph_pt_storefronts as $storefront_id => $storefront}
                                <label class="checkbox" for="elm_euph_pt_sf_{$storefront_id}">
                                    <input type="checkbox" id="elm_euph_pt_sf_{$storefront_id}" name="type_data[storefront_ids][]" value="{$storefront_id}" {if $storefront_id|in_array:$type.storefront_ids}checked="checked"{/if} />
                                    {$storefront}
                                </label>
                            {/foreach}
                            <p class="muted description">{__("euph_profile_types.storefronts_hint")}</p>
                        </div>
                    </div>
                </div>
            {/if}

            {if $is_vendor && $euph_pt_vendor_plans}
                {include file="common/subheader.tpl" title=__("euph_profile_types.vendor_plans") target="#euph_pt_vendor_plans"}
                <div id="euph_pt_vendor_plans" class="in collapse">
                    <div class="control-group">
                        <label class="control-label">{__("euph_profile_types.offers_plans")}:</label>
                        <div class="controls">
                            <input type="hidden" name="type_data[vendor_plan_ids]" value="" />
                            {foreach $euph_pt_vendor_plans as $plan_id => $plan}
                                <label class="checkbox" for="elm_euph_pt_plan_{$plan_id}">
                                    <input type="checkbox" id="elm_euph_pt_plan_{$plan_id}" name="type_data[vendor_plan_ids][]" value="{$plan_id}" {if $plan_id|in_array:$type.vendor_plan_ids}checked="checked"{/if} />
                                    {$plan}
                                </label>
                            {/foreach}
                            <p class="muted description">{__("euph_profile_types.vendor_plans_hint")}</p>
                        </div>
                    </div>
                </div>
            {/if}
        </div>

    {/capture}
    {include file="common/tabsbox.tpl" content=$smarty.capture.tabsbox active_tab=$smarty.request.selected_section track=true}
</form>

{capture name="buttons"}
    {include file="buttons/save_cancel.tpl" but_name="dispatch[euph_profile_types.update]" but_target_form="euph_profile_type_form" save=$id}
{/capture}
{/capture}

{if $id}
    {capture name="euph_pt_title"}{__("euph_profile_types.profile_type")}: {$type.name}{/capture}
    {$title = $smarty.capture.euph_pt_title}
{elseif $is_vendor}
    {$title = __("euph_profile_types.new_vendor_type")}
{else}
    {$title = __("euph_profile_types.new_customer_type")}
{/if}
{include file="common/mainbox.tpl" title=$title content=$smarty.capture.mainbox buttons=$smarty.capture.buttons select_languages=true}
