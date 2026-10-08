{if $smarty.request.dispatch == 'profile_fields.manage'}

    {if $s_id == "C"}
        <h6>{__("cp_user_profile_types")}:</h6>
    {elseif $s_id == "V"}
        <li class="divider"></li>
        <br>
        <h6>{__("cp_vendor_profile_types")}:</h6>
    {/if}
    {foreach $m as $key => $profile_type}
        {if is_array($profile_type)}
            {if $profile_type.href|fn_check_view_permissions:{$method|default:"GET"}}
                <li 
                    class="{if $profile_type.js == true}cm-js{/if}{if $smarty.foreach.first_level.last} last-item{/if}{if $navigation.dynamic.active_section == $key} active{/if}">
                        <a href="{$profile_type.href|fn_url}">{$profile_type.title}</a>
                </li>
            {/if}
        {/if}
    {/foreach}
    {if defined('ADVANCED_PROFILE_FIELDS_SECTIONS') && $s_id == $smarty.const.ADVANCED_PROFILE_FIELDS_SECTIONS}
        <li class="divider"></li>
        <br>
        <h6>{__("cp_advanced_profile_fields.registration_and_login_system_fields")}:</h6>
        <li> 
            <a href="{$m.href|fn_url}">{$m.title}</a>
        </li>
    {/if}
{else}
    {if $m.type == "divider"}
        <li class="divider"></li>
    {else}
        {if $m.href|fn_check_view_permissions:{$method|default:"GET"}}
            <li class="{if $m.js == true}cm-js{/if}{if $smarty.foreach.first_level.last} last-item{/if}{if $navigation.dynamic.active_section == $s_id} active{/if}"><a href="{$m.href|fn_url}">{$m.title}</a></li>
        {/if}
    {/if}
{/if}
