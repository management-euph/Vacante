{*
    What a Design A option, or all of a room's options, says about
    cancelling (RoomResultsBuilder Cancel): free until a day, free with the
    day in the terms (Sphinx, before they load), or non-refundable.
    Params: cancel
*}
{if $cancel.kind == "free"}
    <span class="travel-pdpa-free">{include file="addons/travel_core/components/design_a_icon.tpl" icon="check" size=14}{__("travel_core.pdpa_r_free_until", ["[date]" => $cancel.until|escape:html])}</span>
{elseif $cancel.kind == "free_open"}
    <span class="travel-pdpa-free">{include file="addons/travel_core/components/design_a_icon.tpl" icon="check" size=14}{__("travel_core.pdpa_r_free")}</span>
{elseif $cancel.kind == "non_refundable"}
    <span class="travel-pdpa-nrf">{include file="addons/travel_core/components/design_a_icon.tpl" icon="calx" size=14}{__("travel_core.pdpa_r_non_refundable")}</span>
{/if}
