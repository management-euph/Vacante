{*
    What a Design A option, or all of a room's options, says about
    cancelling (RoomResultsBuilder Cancel), from the period holding today:
    free until a day, with its hour when the supplier gives one ("până pe
    4 nov. 2026, ora 23:59"; "până mâine (4 nov.), ora 23:59" and "până azi
    la ora 23:59" when the day is near); already costing something today
    (amber, with the amount and what it is: the first N nights, N average
    nights with "≈", a percent); free with the day in the terms (Sphinx,
    before they load); or non-refundable.
    Params: cancel
*}
{if $cancel.kind == "free"}
    <span class="travel-pdpa-free">{include file="addons/travel_core/components/design_a_icon.tpl" icon="check" size=14}{if $cancel.time && $cancel.rel == "today"}{__("travel_core.pdpa_r_free_today", ["[time]" => $cancel.time|escape:html])}{elseif $cancel.time && $cancel.rel == "tomorrow"}{__("travel_core.pdpa_r_free_tomorrow", ["[date]" => $cancel.until|escape:html, "[time]" => $cancel.time|escape:html])}{elseif $cancel.time}{__("travel_core.pdpa_r_free_until_time", ["[date]" => $cancel.until|escape:html, "[time]" => $cancel.time|escape:html])}{else}{__("travel_core.pdpa_r_free_until", ["[date]" => $cancel.until|escape:html])}{/if}</span>
{elseif $cancel.kind == "penalty"}
    {if $cancel.nights > 0 && $cancel.approx}
        {$travel_pdpa_what = __("travel_core.pdpa_r_what_nights_avg", [$cancel.nights])}
    {elseif $cancel.nights > 0}
        {$travel_pdpa_what = __("travel_core.pdpa_r_what_first_nights", [$cancel.nights])}
    {elseif $cancel.percent}
        {$travel_pdpa_what = __("travel_core.pdpa_r_what_percent", ["[percent]" => $cancel.percent|escape:html])}
    {else}
        {$travel_pdpa_what = ""}
    {/if}
    {if $cancel.approx}{$travel_pdpa_amount = "&asymp;&nbsp;`$cancel.amount`"}{else}{$travel_pdpa_amount = $cancel.amount}{/if}
    <span class="travel-pdpa-pen">{include file="addons/travel_core/components/design_a_icon.tpl" icon="alert" size=14}<span>{if $travel_pdpa_what}{__("travel_core.pdpa_r_penalty_now", ["[amount]" => $travel_pdpa_amount, "[what]" => $travel_pdpa_what])}{else}{__("travel_core.pdpa_r_penalty_now_plain", ["[amount]" => $travel_pdpa_amount])}{/if}</span></span>
{elseif $cancel.kind == "free_open"}
    <span class="travel-pdpa-free">{include file="addons/travel_core/components/design_a_icon.tpl" icon="check" size=14}{__("travel_core.pdpa_r_free")}</span>
{elseif $cancel.kind == "non_refundable"}
    <span class="travel-pdpa-nrf">{include file="addons/travel_core/components/design_a_icon.tpl" icon="calx" size=14}{__("travel_core.pdpa_r_non_refundable")}</span>
{/if}
