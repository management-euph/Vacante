{*
    The "Fields" tab of a type card: every field of the common list with
    this type's checkboxes. A billing address field carries its shipping
    twin: the two always work alike.
*}
<div class="alert alert-info euph-pt-note">{__("euph_profile_types.fields_tab_note")}</div>
{if $table}
    <div class="table-responsive-wrapper">
        <table class="table table-middle table--relative table-responsive euph-pt-rules euph-pt-rules--fields">
            <thead>
                <tr>
                    <th width="40%">{__("euph_profile_types.field")}</th>
                    {include file="addons/euph_profile_types/views/euph_profile_types/components/rule_head.tpl" kind=$kind}
                </tr>
            </thead>
            {foreach $table as $group}
                <tbody>
                    <tr class="euph-pt-section">
                        <td colspan="4">
                            <h5>
                                {if $group.section == "C"}{if $kind == "V"}{__("euph_profile_types.section_vendor")}{else}{__("contact_information")}{/if}
                                {elseif $group.section == "B"}{__("billing_address")} / {__("shipping_address")}
                                {else}{__("shipping_address")}{/if}
                            </h5>
                        </td>
                    </tr>
                    {foreach $group.fields as $field}
                        <tr class="euph-pt-row">
                            <td data-th="{__("euph_profile_types.field")}">
                                <a href="{"profile_fields.update?field_id=`$field.field_id`"|fn_url}" class="euph-pt-row__name">{$field.description|default:$field.field_name}</a>
                                {if $field.field_name}<span class="euph-pt-code">{$field.field_name}</span>{/if}
                                {if !$field.governed}<span class="label euph-pt-general" title="{__("euph_profile_types.general_settings_tooltip")}">{__("euph_profile_types.general_settings")}</span>{/if}
                            </td>
                            {include file="addons/euph_profile_types/views/euph_profile_types/components/rule_cells.tpl"
                                name="type_fields[`$field.field_id`]"
                                id="euph_pt_tf_`$field.field_id`"
                                rule=$field.rule
                                baseline=$field.baseline
                                kind=$kind
                            }
                        </tr>
                    {/foreach}
                </tbody>
            {/foreach}
        </table>
    </div>
{else}
    <p class="no-items">{__("no_data")}</p>
{/if}
