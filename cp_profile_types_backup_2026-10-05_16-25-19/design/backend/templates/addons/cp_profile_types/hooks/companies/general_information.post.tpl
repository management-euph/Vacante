{if !$runtime.company_id}
    <input type="hidden" name="company_data[cp_old_profile_type]" value="{$company_data.cp_profile_type}">
    {include file="addons/cp_profile_types/components/select_profile_type.tpl" user_type="UserTypes::VENDOR"|enum input_name="company_data"}
{else}
    {$cp_profile_type = $company_data.cp_profile_type|default:$cp_default_profile_type}
    {if $cp_profile_type && $cp_profile_types[$cp_profile_type]}
        <div class="control-group">
            <label for="elm_cp_profile_type" class="control-label">{__("cp_profile_type")}:</label>
            <div class="controls">
                {$cp_profile_types[$cp_profile_type]}
            </div>
        </div>
    {/if}
{/if}