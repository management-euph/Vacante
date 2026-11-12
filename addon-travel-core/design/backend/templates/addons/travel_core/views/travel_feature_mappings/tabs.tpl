{*
 * Feature Mappings — one page, a tab per product type (each type writes to
 * its own CS-Cart feature group). $fm_tab: hotels (default) | circuits | packages.
 *}
{$fm_tab = $fm_tab|default:"hotels"}
<ul class="nav nav-tabs travel-fm-tabs" style="margin-bottom: 15px;">
    <li{if $fm_tab == "hotels"} class="active"{/if}><a href="{"travel_feature_mappings.manage"|fn_url}">{__("travel_core.fmc_tab_hotels")}</a></li>
    <li{if $fm_tab == "circuits"} class="active"{/if}><a href="{"travel_circuit_features.manage"|fn_url}">{__("travel_core.fmc_tab_circuits")}</a></li>
    <li{if $fm_tab == "packages"} class="active"{/if}><a href="{"travel_circuit_features.packages"|fn_url}">{__("travel_core.fmc_tab_packages")}</a></li>
</ul>
