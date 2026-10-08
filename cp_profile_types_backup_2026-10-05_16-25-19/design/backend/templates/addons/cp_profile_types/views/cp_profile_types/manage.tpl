{capture name="mainbox"}
    {assign var="c_icon" value="<i class=\"icon-`$search.sort_order_rev`\"></i>"}
    {assign var="c_dummy" value="<i class=\"icon-dummy\"></i>"}

    <form action="{""|fn_url}" method="post" name="cp_profile_types_list_form">
        {include file="common/pagination.tpl" save_current_page=true save_current_url=true div_id=$smarty.request.content_id}

        {assign var="c_url" value=$config.current_url|fn_query_remove:"sort_by":"sort_order"}
        {assign var="r_url" value=$c_url|escape:"url"}
        {assign var="rev" value=$smarty.request.content_id|default:"pagination_contents"}
        <input type="hidden" name="user_type" value="{$cp_profile_type}"/>

        <div class="items-container" id="cp_profile_types_list">
        {if $profile_types}
            <div class="table-wrapper">
                <table class="table table-middle table-responsive">
                    <thead>
                        <tr>
                            <th width="1%" class="center mobile-hide">{include file="common/check_items.tpl"}</th>
                            <th width="10%">
                                <a class="cm-ajax" href="{"`$c_url`&sort_by=position&sort_order=`$search.sort_order_rev`"|fn_url}" data-ca-target-id={$rev}>{__("position")}{if $search.sort_by == "position"}{$c_icon nofilter}{else}{$c_dummy nofilter}{/if}</a>
                            </th>
                            <th width="40%">
                                <a class="cm-ajax" href="{"`$c_url`&sort_by=name&sort_order=`$search.sort_order_rev`"|fn_url}" data-ca-target-id={$rev}>{__("name")}{if $search.sort_by == "name"}{$c_icon nofilter}{else}{$c_dummy nofilter}{/if}</a>
                            </th>
                            
                            <th width="20%" class="hidden">
                                <a class="cm-ajax" href="{"`$c_url`&sort_by=user_type&sort_order=`$search.sort_order_rev`"|fn_url}" data-ca-target-id={$rev}>{__("user_type")}{if $search.sort_by == "user_type"}{$c_icon nofilter}{else}{$c_dummy nofilter}{/if}</a>
                            </th>
                            
                            <th width="10%" class="center">
                               {__("code")}
                            </th>
                            <th width="10%" class="center nowrap">{__("cp_profile_types_make_default")}
                            </th>
                            <th width="10%" class="center">
                               {if $smarty.request.user_type == "V"}{__("vendors")}{else}{__("users")}{/if}
                            </th>

                                <th class="right">&nbsp;</th>
                                <th width="10%" class="right">
                                    <a class="cm-ajax" href="{"`$c_url`&sort_by=status&sort_order=`$search.sort_order_rev`"|fn_url}" data-ca-target-id={$rev}>{__("status")}{if $search.sort_by == "status"}{$c_icon nofilter}{else}{$c_dummy nofilter}{/if}</a>
                                </th>

                        </tr>
                    </thead>
                    <tbody>
                        {foreach from=$profile_types item=type name="cp_profile_types"}
                            {assign var="edit_href" value="cp_profile_types.update?type_id=`$type.type_id`"|fn_url}
                            {assign var="id" value=$type.type_id}

                            <tr class="cm-row-item cm-row-status-{$type.status|lower}">
                                <td width="1%" class="center mobile-hide">
                                    <input type="checkbox" name="type_ids[]" value="{$type.type_id}" class="cm-item" />
                                </td>
                                <td data-th="{__("position")}">
                                    <input type="text" name="types_data[{$type.type_id}][position]" value="{$type.position|default:0}" size="3" class="input-micro input-hidden" /></td>
                                </td>
                                <td data-th="{__("name")}">
                                    <div class="object-group-link-wrap">
                                        <a href="{$edit_href}" class="row-status">{$type.name}</a>
                                    </div>
                                </td>
                                
                                    <td data-th="{__("user_type")}" class = "hidden">
                                        {if $type.user_type == "V"}{__("vendor")}{else}{__("customer")}{/if}
                                    </td>
                                
                                <td data-th="{__("code")}" class="center">
                                    {$type.code}
                                </td>
                                <td class="center" data-th="{__("cp_profile_types_make_default")}">
                                    <input type="radio" name="is_default" value="{$type.type_id}"{if $type.is_default} checked="checked"{/if}/>
          
                                </td>
                                <td data-th="{if $smarty.request.user_type == "V"}{__("vendors")}{else}{__("users")}{/if}" class="center">
                                    {if $type.user_type == "V"}
                                        {$count_link = "companies.manage?cp_profile_type=`$type.code`"|fn_url}
                                    {else}
                                        {$count_link = "profiles.manage?cp_profile_type=`$type.code`"|fn_url}
                                    {/if}
                                    <a href="{$count_link}" target="_blank" class="badge">{$type.count}</a>
                                </td>
                                <td class="right nowrap">
                                    <div class="pull-right hidden-tools">
                                        {capture name="items_tools"}
                                            <li>{btn type="list" text=__("edit") href="cp_profile_types.update?type_id=`$type.type_id`"}</li>
                                            {if $type.code !== "ProfileTypes::CODE_SELLER"|enum && $type.code !== "ProfileTypes::CODE_USER"|enum}
                                                <li>{btn type="text" text=__("delete") href="cp_profile_types.delete?type_id=`$type.type_id`&user_type=`$type.user_type`" class="cm-confirm cm-ajax cm-ajax-full-render" data=["data-ca-target-id" => cp_profile_types_list] method="POST"}</li>
                                            {/if}
                                        {/capture}
                                        {dropdown content=$smarty.capture.items_tools class="dropleft"}
                                    </div>
                                </td>

                                    <td data-th="{__("status")}" width="10%">
                                        <div class="pull-right nowrap">
                                            {$items_status = ['A' => __("active")]}
                                            {if $type.code !== "ProfileTypes::CODE_SELLER"|enum && $type.code !== "ProfileTypes::CODE_USER"|enum}
                                                {$items_status = null}
                                            {/if}
                                            {include file="common/select_popup.tpl" items_status=$items_status popup_additional_class="dropleft" id=$id status=$type.status hidden=false object_id_name="type_id" table="cp_profile_types"}
                                        </div>
                                    </td>
                            </tr>
                        {/foreach}
                    </tbody>
                </table>
            </div>
        {else}
            <p class="no-items">{__("no_data")}</p>
        {/if}
        <!--cp_profile_types_list--></div>
        {include file="common/pagination.tpl" div_id=$smarty.request.content_id}

        {capture name="buttons"}
            {if $profile_types}
                {capture name="tools_list"}
                    <li>{btn type="delete_selected" dispatch="dispatch[cp_profile_types.m_delete]" form="cp_profile_types_list_form"}</li>
                {/capture}
                {dropdown content=$smarty.capture.tools_list}

                {include file="buttons/save.tpl" but_name="dispatch[cp_profile_types.m_update]" but_role="action" but_target_form="cp_profile_types_list_form" but_meta="btn-primary cm-submit"}
            {/if}
        {/capture}

        {capture name="adv_buttons"}
            {include file="common/tools.tpl" tool_href="cp_profile_types.add?user_type=`$smarty.request.user_type`" prefix="top" hide_tools="true" title=__("add") icon="icon-plus"}
        {/capture}

        {capture name="sidebar"}
            {include file="addons/cp_profile_types/views/cp_profile_types/components/search_form.tpl" dispatch="cp_profile_types.manage"}
        {/capture}
    </form>
{/capture}

{$title = __("cp_user_profile_types")}
{if $smarty.request.user_type == "V"}
    {$title = __("cp_vendor_profile_types")}
{/if}

{include file="common/mainbox.tpl" title=$title content=$smarty.capture.mainbox select_languages=true buttons=$smarty.capture.buttons adv_buttons=$smarty.capture.adv_buttons sidebar=$smarty.capture.sidebar}
