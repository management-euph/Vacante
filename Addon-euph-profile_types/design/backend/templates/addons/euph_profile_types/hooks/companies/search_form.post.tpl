{if $euph_pt_filter_types}
    <div class="control-group">
        <label class="control-label" for="elm_euph_profile_type">{__("euph_profile_types.profile_type")}</label>
        <div class="controls">
            <select name="euph_profile_type" id="elm_euph_profile_type">
                <option value="">--</option>
                {foreach $euph_pt_filter_types as $code => $name}
                    <option value="{$code}" {if $search.euph_profile_type == $code || $smarty.request.euph_profile_type == $code}selected="selected"{/if}>{$name}</option>
                {/foreach}
            </select>
        </div>
    </div>
{/if}
