{if $cp_profile_types}
<div class="control-group">
    <label class="control-label" for="elm_cp_profile_type">{__("cp_profile_type")}</label>
    <div class="controls">
        <select name="cp_profile_type" id="elm_cp_profile_type">
            <option value="">--</option>
            {foreach from=$cp_profile_types key="type_key" item="type_name" name="cp_profile_types"}
            <option {if $smarty.request.cp_profile_type == $type_key}selected="selected"{/if} value="{$type_key}">{$type_name}</option>
            {/foreach}
        </select>
    </div>
</div>
{/if}