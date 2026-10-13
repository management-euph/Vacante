{*
    Customers → Profile fields → a field: the "Profile types" block, a row
    per type. While anything is ticked here, the general checkboxes above do
    not decide where the field shows; a field with nothing ticked for any
    type works by them.
*}
{if $euph_pt_field_card && $euph_pt_field_card.types}
    {$card = $euph_pt_field_card}
    <div class="euph-pt-field-card" id="euph_pt_field_card" data-ca-euph-pt-kind="{$card.kind}">
        {include file="common/subheader.tpl" title=__("euph_profile_types.profile_types") target="#euph_pt_field_card_body"}
        <div id="euph_pt_field_card_body" class="in collapse">
            <div class="alert alert-info euph-pt-note">
                {if $card.governed}
                    {__("euph_profile_types.field_governed_note")}
                {else}
                    {__("euph_profile_types.field_general_note")}
                {/if}
            </div>
            <div class="table-responsive-wrapper">
                <table class="table table-middle table--relative table-responsive euph-pt-rules">
                    <thead>
                        <tr>
                            <th width="34%">{__("euph_profile_types.profile_type")}</th>
                            {include file="addons/euph_profile_types/views/euph_profile_types/components/rule_head.tpl" kind=$card.kind}
                        </tr>
                    </thead>
                    <tbody>
                        {foreach $card.types as $type}
                            <tr class="euph-pt-row{if $type.status == "D"} euph-pt-row--disabled{/if}">
                                <td data-th="{__("euph_profile_types.profile_type")}">
                                    <a href="{"euph_profile_types.update?type_id=`$type.type_id`&selected_section=fields"|fn_url}" class="euph-pt-row__name">{$type.name}</a>
                                    <span class="euph-pt-code">{$type.code}</span>
                                    {if $type.is_standard == "Y"}<span class="label">{__("euph_profile_types.standard")}</span>{/if}
                                    {if $type.status == "H"}<span class="label label-info">{__("hidden")}</span>{elseif $type.status == "D"}<span class="label">{__("disabled")}</span>{/if}
                                </td>
                                {include file="addons/euph_profile_types/views/euph_profile_types/components/rule_cells.tpl"
                                    name="euph_field_rules[`$type.type_id`]"
                                    id="euph_pt_fc_`$type.type_id`"
                                    rule=$type.rule
                                    baseline=$type.baseline
                                    kind=$card.kind
                                }
                            </tr>
                        {/foreach}
                    </tbody>
                </table>
            </div>
            <div class="euph-pt-actions">
                <a class="btn cm-euph-pt-fill-general" data-ca-euph-pt-scope="#euph_pt_field_card_body">{__("euph_profile_types.fill_from_general")}</a>
                <a class="btn cm-euph-pt-clear" data-ca-euph-pt-scope="#euph_pt_field_card_body">{__("euph_profile_types.clear_types")}</a>
                <p class="muted description">{__("euph_profile_types.field_card_hint")}</p>
            </div>
        </div>
    </div>
{/if}
