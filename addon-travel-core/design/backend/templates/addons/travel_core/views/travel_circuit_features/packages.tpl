{* Feature Mappings › Packages — nothing to map yet. *}
{capture name="mainbox"}
    {include file="addons/travel_core/views/travel_feature_mappings/tabs.tpl"}
    <p class="no-items">{__("travel_core.fmc_packages_soon")}</p>
{/capture}

{include file="common/mainbox.tpl"
    title=__("travel_core.feature_mappings")
    content=$smarty.capture.mainbox
}
