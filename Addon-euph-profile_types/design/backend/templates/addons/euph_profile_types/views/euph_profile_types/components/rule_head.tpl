{* Column heads of rule_cells.tpl. kind: C customers, V vendors. *}
<th class="center euph-pt-head">{__("euph_profile_types.area_profile")}<br /><small class="muted">{__("euph_profile_types.show")}&nbsp;/&nbsp;{__("euph_profile_types.required")}</small></th>
{if $kind == "V"}
    <th class="center euph-pt-head">{__("euph_profile_types.area_application")}<br /><small class="muted">{__("euph_profile_types.show")}</small></th>
    <th class="center euph-pt-head">{__("euph_profile_types.area_storefront")}<br /><small class="muted">{__("euph_profile_types.show")}</small></th>
{else}
    <th class="center euph-pt-head">{__("euph_profile_types.area_registration")}<br /><small class="muted">{__("euph_profile_types.show")}</small></th>
    <th class="center euph-pt-head">{__("euph_profile_types.area_checkout")}<br /><small class="muted">{__("euph_profile_types.show")}&nbsp;/&nbsp;{__("euph_profile_types.required")}</small></th>
{/if}
