{assign var="allow_save" value=true}

{capture name="mainbox"}
    {if !$id}
        {assign var="id" value=$type.type_id|default:0}
    {/if}
    {assign var="c_url" value=$config.current_url|fn_query_remove}
    {assign var="r_url" value=$c_url|escape:"url"}
    
    {if $id}
        {$user_type = $type.user_type|default:"C"}
    {else}
        {$user_type = $smarty.request.user_type|default:"C"}
    {/if}

    <form action="{""|fn_url}" method="post" name="type_datas_form_{$id}" enctype="multipart/form-data" class="conditions-tree form-horizontal form-edit {if !$allow_save}cm-hide-inputs{/if}">
        <input type="hidden" name="type_id" value="{$id}" />
        <input type="hidden" name="type_data[company_id]" value="{$runtime.company_id}"/>
        <input type="hidden" name="selected_section" value="{$smarty.request.selected_section}" />
        <input type="hidden" name="return_url" value="{$r_url}" />
        {capture name="tabsbox"}
        <div id="content_general">
            <div class="control-group">
                <label for="user_type" class="control-label cm-required">{__("account_type")}:</label>
                <div class="controls">
                    <span class="strong shift-input">{if $user_type == "C"}{__("customer")}{elseif $user_type == "V"}{__("vendor")}{/if}</span>
                    <input type="hidden" name="type_data[user_type]" value="{$user_type}">
                </div>
            </div>
            <div class="control-group">
                <label for="cp_type_data_name_{$id}" class="cm-required control-label cp-profile-types-name">{__("name")}:</label>
                <div class="controls">
                    <input id="cp_type_data_name_{$id}" type="text" class="input-large" name="type_data[name]" value="{$type.name}">
                </div>
            </div>
            <div class="control-group">
                <label class="control-label" for="elm_type_position">{__("position")}:</label>
                <div class="controls">
                    <input type="text" name="type_data[position]" id="elm_type_position" size="10" value="{$type.position|default:0}" class="input-short" />
                </div>
            </div>
            
           {if $type.code !== "ProfileTypes::CODE_SELLER"|enum && $type.code !== "ProfileTypes::CODE_USER"|enum}
            {include file="common/select_status.tpl" input_name="type_data[status]" id="elm_type_data_status_`$id`" obj_id=$id obj=$type}
           {/if}
            {if $id}
                {if $usergroups}
                    {assign var="ug_ids" value=","|explode:$usergroup_ids}
                    {$default_usergroups = $default_usergroups|default:[]}
                    <div class="control-group">
                        <label class="control-label">{__("usergroups")}:</label>
                        <div class="controls">
                            {foreach from=$usergroups item=usergroup}

                                {$ug_id = $usergroup.usergroup_id}
                                {$is_default = $ug_id|in_array:$default_usergroups}
                                
                                <label class="checkbox inline" for="elm_usergroup_id_{$ug_id}">
                                    {if $is_default}
                                        <input type="hidden" name="type_data[usergroup_ids][]" value="{$ug_id}" />
                                    {/if}

                                    <input type="checkbox" name="type_data[usergroup_ids][]" id="elm_usergroup_id_{$ug_id}" {if $ug_id|in_array:$type.usergroup_ids || $is_default} checked="checked"{/if} value="{$ug_id}" {if $is_default}disabled="disabled"{/if} />
                                        {$usergroup.usergroup}
                                </label>
                            {/foreach}
                        </div>
                    </div>
                {/if}

                {if $plans}
                    <div class="control-group">
                        <label class="control-label">{__("vendor_plans.plan")}:</label>
                        <div class="controls">
                            {foreach $plans as $plan}
                                {if $plan.status == "A"}
                                    {$ug_id = $plan.plan_id}
                                    {$is_default = $ug_id|in_array:$default_vendor_plans}
                                    <label class="checkbox inline" for="elm_vendor_plans_{$ug_id}">
                                    {if $is_default}
                                        <input type="hidden" name="type_data[vendor_plans][]" value="{$ug_id}" />
                                    {/if}
                                    <input type="checkbox" name="type_data[vendor_plans][]" id="elm_vendor_plans_{$ug_id}" {if $ug_id|in_array:$type.id_plans || $is_default} checked="checked"{/if} value="{$ug_id}" {if $is_default}disabled="disabled"{/if} />
                                        {$plan.plan}
                                    </label>
                                {/if}
                            {/foreach}
                        </div>
                        <span class="muted description cp-profile-types-description">{__("cp_profile_types.choosing_tariff_plan")}</span>
                    </div>
                {/if}  

                <div class="control-group">
                    <label for="cp_type_data_name_{$id}" class="control-label">{__("code")}:</label>
                    <div class="controls">
                        <span class="shift-input">{$type.code}</span>
                    </div>
                </div>

                <div class="control-group">
                    <label for="cp_type_data_name_{$id}" class="control-label">{__("profile_fields")}:</label>
                    <div class="controls">
                        <a class="shift-input" target="_blank" href={"profile_fields.manage?profile_type=`$type.code`"|fn_url}>{__("cp_edit_profile_types")}</a>
                    </div>
                </div>
            {/if}
        <!--content_general--></div>
        
        <div id="content_addons" class="hidden clearfix">
            {hook name="cp_profile_types:detailed_content"}
            {/hook}
        <!--content_addons--></div>

        {hook name="cp_profile_types:tabs_content"}
        {/hook}

        {/capture}

        {include file="common/tabsbox.tpl" content=$smarty.capture.tabsbox group_name=$runtime.controller active_tab=$smarty.request.selected_section track=true}
    </form>
{/capture}

{capture name="buttons"}
    {if $type.code !== "ProfileTypes::CODE_SELLER"|enum && $type.code !== "ProfileTypes::CODE_USER"|enum}
        {capture name="tools_list"}
            {if $id}
                <li>{btn type="delete" href="cp_profile_types.delete?type_id=`$id`&user_type=`$user_type`&return_url=`$r_url`" class="cm-post"}</li>
            {/if}
        {/capture}
    {/if}
    {dropdown content=$smarty.capture.tools_list}

    {include file="buttons/save_cancel.tpl" but_role="submit-link" but_target_form="type_datas_form_`$id`" but_name="dispatch[cp_profile_types.update]" save=$id}
{/capture}

{capture name="title"}{if $user_type == "V"}{__("cp_vendor_profile_type")}{else}{__("cp_user_profile_type")}{/if}{if $id}: {$type.name}{/if}{/capture}

{include file="common/mainbox.tpl" title=$smarty.capture.title content=$smarty.capture.mainbox buttons=$smarty.capture.buttons sidebar_position="right" select_languages=$id}
