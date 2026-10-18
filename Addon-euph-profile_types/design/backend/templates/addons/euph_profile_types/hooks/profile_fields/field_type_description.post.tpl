{*
    CS-Cart's list of profile fields, the Type column: while one type is
    shown, what the line of a field stands for when it is not set per type.
*}
{if $euph_pt_list && $euph_pt_list.fields[$field.field_id]}
    {$euph_pt_mode = $euph_pt_list.fields[$field.field_id].mode}
    <span class="euph-pt-list-type-tag">
        {if $euph_pt_mode == "locked"}
            {__("euph_profile_types.tag_always")}
        {else}
            {__("euph_profile_types.tag_general")}
        {/if}
    </span>
{/if}
