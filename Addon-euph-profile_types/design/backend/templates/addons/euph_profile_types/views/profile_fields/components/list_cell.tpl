{*
    One Show / Required cell of CS-Cart's list of profile fields, for one
    field (hooks/profile_fields/field_settings.post.tpl): the general line,
    where the script moves CS-Cart's own checkboxes of the cell, and a row of
    checkboxes per type. The script shows the general line or the rows, for
    all types or for one type (js/addons/euph_profile_types/func.js).

    list     fn_euph_profile_types_fields_list(): types, areas, first_area
    item     the field: mode (types, general, locked), governed, rows
             (type id => flags + baseline)
    area     the area of the cell: profile or checkout
    field_id the field
*}
{$flags = $list.areas[$area]}
{$is_first = ($area == $list.first_area)}
{$mode = $item.mode}
{$can_split = ($mode == "general")}
{$name = "euph_pt_list[`$field_id`]"}
<div class="euph-pt-list-cell" data-ca-euph-pt-field="{$field_id}" data-ca-euph-pt-area="{$area}" data-ca-euph-pt-mode="{$mode}" data-ca-euph-pt-first="{if $is_first}Y{else}N{/if}" style="--euph-pt-cols: {$flags|count};">
    {if $is_first && ($can_split || $mode == "types")}
        <input type="hidden" name="{$name}[split]" value="N" />
    {/if}
    <div class="euph-pt-list-row euph-pt-list-row--general">
        {if $is_first}
            <span class="euph-pt-list-label">
                {__("euph_profile_types.all_types")}
                {if $mode == "locked"}
                    <span class="euph-pt-list-tag">{__("euph_profile_types.tag_always")}</span>
                {else}
                    <span class="euph-pt-list-tag" title="{__("euph_profile_types.general_row_hint")}">{__("euph_profile_types.tag_general")}</span>
                    {if $can_split}
                        <a class="euph-pt-list-split cm-euph-pt-split-field" data-ca-euph-pt-field="{$field_id}" title="{__("euph_profile_types.general_row_hint")}">{__("euph_profile_types.set_per_type")}&nbsp;›</a>
                    {/if}
                {/if}
            </span>
        {/if}
        {foreach $flags as $flag}
            <span class="euph-pt-list-slot" data-ca-euph-pt-slot="{$flag}"{if $flag == "registration_show"} title="{__("euph_profile_types.registration_follows_show")}"{/if}></span>
        {/foreach}
    </div>
    {if $mode != "locked"}
        {foreach $list.types as $type}
            {$row = $item.rows[$type.id]}
            {$id = "euph_pt_l_`$field_id`_`$type.id`"}
            <div class="euph-pt-list-row euph-pt-list-row--type{if $type.status == "D"} euph-pt-list-row--disabled{/if}" data-ca-euph-pt-type="{$type.id}">
                {if $is_first}
                    <span class="euph-pt-list-label">
                        <span class="euph-pt-dot euph-pt-dot--{$type.tone}"></span>{$type.name}
                    </span>
                    <input type="hidden" name="{$name}[{$type.id}][baseline]" value="{$row.baseline}" />
                {/if}
                {foreach $flags as $flag}
                    {$required_flag = $flag|replace:"_show":"_required"}
                    {$show_flag = $flag|replace:"_required":"_show"}
                    <span class="euph-pt-list-slot">
                        <input type="hidden" name="{$name}[{$type.id}][{$flag}]" value="N" />
                        <input type="checkbox" name="{$name}[{$type.id}][{$flag}]" value="Y" id="{$id}_{$flag}" data-ca-euph-pt-flag="{$flag}" title="{$type.name}"
                            {if $required_flag != $flag && in_array($required_flag, $flags)}class="cm-euph-pt-show" data-ca-euph-pt-required="#{$id}_{$required_flag}"{/if}
                            {if $row.flags[$flag] == "Y"}checked="checked"{/if}
                            {if $show_flag != $flag && in_array($show_flag, $flags) && $row.flags[$show_flag] != "Y"}disabled="disabled"{/if} />
                    </span>
                {/foreach}
            </div>
        {/foreach}
    {/if}
</div>
