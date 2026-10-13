{*
    Customers → Profile types (Vendors → Vendor types in Multi-Vendor).
*}
{$is_vendor = ($euph_pt_kind == "V")}

{capture name="mainbox"}
    {if !$euph_pt_kind_enabled}
        <div class="alert alert-warning">
            {__("euph_profile_types.kind_disabled_note", ["[url]" => "addons.update?addon=euph_profile_types"|fn_url]) nofilter}
        </div>
    {/if}

    {if $euph_pt_legacy_available}
        <div class="alert alert-info euph-pt-legacy">
            <form action="{""|fn_url}" method="post" name="euph_pt_legacy_form" class="euph-pt-legacy__form">
                <input type="hidden" name="user_type" value="{$euph_pt_kind}" />
                <span>{__("euph_profile_types.legacy_found")}</span>
                {include file="buttons/button.tpl" but_text=__("euph_profile_types.legacy_import") but_name="dispatch[euph_profile_types.import_legacy]" but_role="action" but_meta="btn cm-confirm"}
            </form>
        </div>
    {/if}

    <form action="{""|fn_url}" method="post" name="euph_profile_types_form" id="euph_profile_types_form" class="{if ""|fn_check_form_permissions}cm-hide-inputs{/if}">
        <input type="hidden" name="user_type" value="{$euph_pt_kind}" />

        {if $euph_pt_types}
            <div class="table-responsive-wrapper">
                <table class="table table-middle table--relative table-responsive euph-pt-types">
                    <thead>
                        <tr>
                            <th class="mobile-hide" width="1%">{include file="common/check_items.tpl"}</th>
                            <th width="8%">{__("position_short")}</th>
                            <th width="30%">{__("name")}</th>
                            <th width="8%" class="center">{__("euph_profile_types.default")}</th>
                            <th width="10%" class="center">{if $is_vendor}{__("vendors")}{else}{__("customers")}{/if}</th>
                            <th width="18%">{__("usergroups")}</th>
                            {if $euph_pt_storefronts|count > 1}<th width="12%">{__("storefronts")}</th>{/if}
                            {if !$is_vendor}<th width="12%">{__("euph_profile_types.activation")}</th>{/if}
                            <th width="5%" class="mobile-hide">&nbsp;</th>
                            <th width="10%" class="right">{__("status")}</th>
                        </tr>
                    </thead>
                    <tbody>
                    {foreach $euph_pt_types as $type}
                        {$edit_url = "euph_profile_types.update?type_id=`$type.type_id`"|fn_url}
                        <tr class="cm-row-status-{$type.status|lower}">
                            <td class="mobile-hide">
                                {if $type.is_standard != "Y"}
                                    <input type="checkbox" name="type_ids[]" value="{$type.type_id}" class="cm-item" />
                                {/if}
                            </td>
                            <td data-th="{__("position_short")}">
                                <input type="text" name="types_data[{$type.type_id}][position]" value="{$type.position}" size="3" class="input-micro input-hidden" />
                            </td>
                            <td data-th="{__("name")}">
                                <a href="{$edit_url}" class="row-status euph-pt-types__name">{$type.name}</a>
                                <span class="euph-pt-code" title="{__("code")}">{$type.code}</span>
                                {if $type.is_standard == "Y"}<span class="label">{__("euph_profile_types.standard")}</span>{/if}
                                {if $type.description}<p class="muted euph-pt-types__description">{$type.description|truncate:110}</p>{/if}
                            </td>
                            <td class="center" data-th="{__("euph_profile_types.default")}">
                                <input type="radio" name="default_type_id" value="{$type.type_id}" {if $type.is_default == "Y"}checked="checked"{/if} />
                            </td>
                            <td class="center" data-th="{if $is_vendor}{__("vendors")}{else}{__("customers")}{/if}">
                                {$count = $euph_pt_usage[$type.code]|default:0}
                                {if $is_vendor}
                                    <a href="{"companies.manage?euph_profile_type=`$type.code`"|fn_url}" class="badge">{$count}</a>
                                {else}
                                    <a href="{"profiles.manage?user_type=C&euph_profile_type=`$type.code`"|fn_url}" class="badge">{$count}</a>
                                {/if}
                            </td>
                            <td data-th="{__("usergroups")}">
                                {foreach $type.usergroup_ids as $usergroup_id}
                                    {if $euph_pt_usergroups[$usergroup_id]}<span class="label label-info euph-pt-chip">{$euph_pt_usergroups[$usergroup_id]}</span>{/if}
                                {foreachelse}
                                    <span class="muted">—</span>
                                {/foreach}
                            </td>
                            {if $euph_pt_storefronts|count > 1}
                                <td data-th="{__("storefronts")}">
                                    {foreach $type.storefront_ids as $storefront_id}
                                        <span class="euph-pt-chip">{$euph_pt_storefronts[$storefront_id]|default:"#`$storefront_id`"}</span>
                                    {foreachelse}
                                        <span class="muted">{__("euph_profile_types.all_storefronts")}</span>
                                    {/foreach}
                                </td>
                            {/if}
                            {if !$is_vendor}
                                <td data-th="{__("euph_profile_types.activation")}">
                                    {if $type.activation == "A"}{__("euph_profile_types.activation_instant")}
                                    {elseif $type.activation == "P"}{__("euph_profile_types.activation_approval")}
                                    {else}<span class="muted">{__("euph_profile_types.activation_store")}</span>{/if}
                                </td>
                            {/if}
                            <td class="nowrap mobile-hide">
                                <div class="hidden-tools">
                                    {capture name="tools_list"}
                                        <li>{btn type="list" text=__("edit") href=$edit_url}</li>
                                        {if $type.is_standard != "Y"}
                                            <li>{btn type="list" text=__("delete") class="cm-confirm" href="euph_profile_types.delete?type_id=`$type.type_id`&user_type=`$euph_pt_kind`" method="POST"}</li>
                                        {/if}
                                    {/capture}
                                    {dropdown content=$smarty.capture.tools_list}
                                </div>
                            </td>
                            <td class="right" data-th="{__("status")}">
                                {if $type.is_standard == "Y"}
                                    <span class="euph-pt-status">{__("active")}</span>
                                {else}
                                    {include file="common/select_popup.tpl" popup_additional_class="dropleft" id=$type.type_id status=$type.status hidden=true object_id_name="type_id" table="euph_profile_types"}
                                {/if}
                            </td>
                        </tr>
                    {/foreach}
                    </tbody>
                </table>
            </div>
        {else}
            <p class="no-items">{__("no_data")}</p>
        {/if}
    </form>

    {capture name="buttons"}
        {if $euph_pt_types}
            {capture name="tools_list"}
                <li>{btn type="delete_selected" dispatch="dispatch[euph_profile_types.m_delete]" form="euph_profile_types_form"}</li>
            {/capture}
            {dropdown content=$smarty.capture.tools_list}
            {include file="buttons/save.tpl" but_name="dispatch[euph_profile_types.m_update]" but_role="action" but_target_form="euph_profile_types_form" but_meta="cm-submit btn-primary"}
        {/if}
    {/capture}

    {capture name="adv_buttons"}
        {include file="common/tools.tpl" tool_href="euph_profile_types.add?user_type=`$euph_pt_kind`" prefix="top" hide_tools=true title=__("euph_profile_types.add_type") icon="icon-plus"}
    {/capture}

    {capture name="sidebar"}
        <div class="sidebar-row euph-pt-help">
            <h6>{__("euph_profile_types.how_it_works")}</h6>
            <p>{__("euph_profile_types.how_it_works_text")}</p>
            <ul class="unstyled euph-pt-help__links">
                {if $is_vendor}
                    <li><a href="{"profile_fields.manage?profile_type=S"|fn_url}">{__("euph_profile_types.go_profile_fields")}</a></li>
                {else}
                    <li><a href="{"profile_fields.manage?profile_type=U"|fn_url}">{__("euph_profile_types.go_profile_fields")}</a></li>
                {/if}
                <li><a href="{"addons.update?addon=euph_profile_types"|fn_url}">{__("euph_profile_types.go_settings")}</a></li>
                {if $is_vendor}
                    <li><a href="{"usergroups.manage?type=V"|fn_url}">{__("usergroups")}</a></li>
                {else}
                    <li><a href="{"usergroups.manage?type=C"|fn_url}">{__("usergroups")}</a></li>
                {/if}
            </ul>
        </div>
    {/capture}
{/capture}

{if $is_vendor}
    {$title = __("euph_profile_types.vendor_types")}
{else}
    {$title = __("euph_profile_types.customer_types")}
{/if}
{include file="common/mainbox.tpl" title=$title content=$smarty.capture.mainbox buttons=$smarty.capture.buttons adv_buttons=$smarty.capture.adv_buttons sidebar=$smarty.capture.sidebar select_languages=true}
