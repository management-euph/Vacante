{if $cp_profile_types}
    {assign var="r_url" value=$config.current_url|fn_query_remove:"cp_profile_type"}
    {if $smarty.request.cp_profile_type}
        {$cp_profile_type = $smarty.request.cp_profile_type}
    {elseif $user_data.cp_profile_type}
        {$cp_profile_type = $user_data.cp_profile_type}
    {elseif $company_data.cp_profile_type}
        {$cp_profile_type = $company_data.cp_profile_type}
    {else}
        {$cp_profile_type = $user_type|fn_cp_get_default_profile_type}
    {/if}
    <div class="control-group">
        <label class="control-label" for="cp_profile_type">{__("cp_profile_type")}:</label>
        <div class="controls">
            {foreach from=$cp_profile_types key="cp_type_id" item="cp_type_name" name="cp_profile_types"}
                {$i = $smarty.foreach.cp_profile_types.iteration}
                <label for="elm_cp_profile_type_select_{$i}" class="radio inline">
                    <input class="cm-change-profile-type" type="radio" id="elm_cp_profile_type_select_{$i}" name="{$input_name|default:"user_data"}[cp_profile_type]" value="{$cp_type_id}" {if $cp_profile_type == $cp_type_id}checked="checked"{/if} data-ca-target-url="{$r_url}" {if $target_id}data-ca-target-id="{$target_id}"{/if}>
                    {$cp_type_name}
                </label>
            {/foreach}
        </div>
    </div>
{/if}
