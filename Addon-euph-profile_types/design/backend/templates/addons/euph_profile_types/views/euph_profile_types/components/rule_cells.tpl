{*
    The checkboxes of one field for one type: a row of the field card's
    "Profile types" block or of the type card's "Fields" tab.

    name     input name prefix, e.g. euph_field_rules[12]
    rule     ProfileType field rule: profile_show, profile_required,
             registration_show, checkout_show, checkout_required, storefront_show (Y/N)
    baseline the checkboxes as drawn ("YNYNNN"): an untouched row saves nothing
    kind     C customers, V vendors
    id       unique id prefix
*}
{$is_vendor = ($kind == "V")}
<td class="center euph-pt-cell" data-th="{__("euph_profile_types.area_profile")}">
    <input type="hidden" name="{$name}[baseline]" value="{$baseline}" />
    <input type="hidden" name="{$name}[profile_show]" value="N" />
    <label class="checkbox inline euph-pt-check" title="{__("euph_profile_types.show")}">
        <input type="checkbox" name="{$name}[profile_show]" value="Y" class="cm-euph-pt-show" data-ca-euph-pt-required="#{$id}_pr" {if $rule.profile_show == "Y"}checked="checked"{/if} />
    </label>
    <span class="euph-pt-slash">/</span>
    <input type="hidden" name="{$name}[profile_required]" value="N" />
    <label class="checkbox inline euph-pt-check" title="{__("euph_profile_types.required")}">
        <input type="checkbox" id="{$id}_pr" name="{$name}[profile_required]" value="Y" {if $rule.profile_required == "Y"}checked="checked"{/if} {if $rule.profile_show != "Y"}disabled="disabled"{/if} />
    </label>
</td>
<td class="center euph-pt-cell" data-th="{if $is_vendor}{__("euph_profile_types.area_application")}{else}{__("euph_profile_types.area_registration")}{/if}">
    <input type="hidden" name="{$name}[registration_show]" value="N" />
    <label class="checkbox inline euph-pt-check" title="{__("euph_profile_types.show")}">
        <input type="checkbox" name="{$name}[registration_show]" value="Y" {if $rule.registration_show == "Y"}checked="checked"{/if} />
    </label>
</td>
{if $is_vendor}
    <td class="center euph-pt-cell" data-th="{__("euph_profile_types.area_storefront")}">
        <input type="hidden" name="{$name}[storefront_show]" value="N" />
        <label class="checkbox inline euph-pt-check" title="{__("euph_profile_types.show")}">
            <input type="checkbox" name="{$name}[storefront_show]" value="Y" {if $rule.storefront_show == "Y"}checked="checked"{/if} />
        </label>
    </td>
{else}
    <td class="center euph-pt-cell" data-th="{__("euph_profile_types.area_checkout")}">
        <input type="hidden" name="{$name}[checkout_show]" value="N" />
        <label class="checkbox inline euph-pt-check" title="{__("euph_profile_types.show")}">
            <input type="checkbox" name="{$name}[checkout_show]" value="Y" class="cm-euph-pt-show" data-ca-euph-pt-required="#{$id}_cr" {if $rule.checkout_show == "Y"}checked="checked"{/if} />
        </label>
        <span class="euph-pt-slash">/</span>
        <input type="hidden" name="{$name}[checkout_required]" value="N" />
        <label class="checkbox inline euph-pt-check" title="{__("euph_profile_types.required")}">
            <input type="checkbox" id="{$id}_cr" name="{$name}[checkout_required]" value="Y" {if $rule.checkout_required == "Y"}checked="checked"{/if} {if $rule.checkout_show != "Y"}disabled="disabled"{/if} />
        </label>
    </td>
{/if}
