{*
    CS-Cart's list of profile fields, each Show / Required cell: the
    checkboxes of the field per type (controllers/backend/profile_fields.post.php).
*}
{if $euph_pt_list && $euph_pt_list.fields[$field.field_id] && $euph_pt_list.areas[$area]}
    {include file="addons/euph_profile_types/views/profile_fields/components/list_cell.tpl"
        list=$euph_pt_list
        item=$euph_pt_list.fields[$field.field_id]
        area=$area
        field_id=$field.field_id
    }
{/if}
