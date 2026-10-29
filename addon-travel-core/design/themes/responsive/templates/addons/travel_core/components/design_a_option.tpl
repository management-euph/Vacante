{*
    One option of a Design A room (RoomResultsBuilder OptionView): a meal
    plan on its terms. Several: a radio, the meal and only what differs from
    the room's other options, its total for comparing (the till shows the
    chosen one's). One: the blue tick in the radio's place and the same
    lines, no price (the till is right next to it).
    Included by design_a_room.tpl with $travel_pdpa_room and
    $travel_pdpa_option set. The data attributes drive the filters
    (pdp-design-a.js).
*}
{if $travel_pdpa_room.single}{$travel_pdpa_option_tag = "div"}{else}{$travel_pdpa_option_tag = "label"}{/if}
<{$travel_pdpa_option_tag} class="travel-pdpa-opt{if $travel_pdpa_room.single} travel-pdpa-opt--only{/if}"
    data-travel-option
    data-index="{$travel_pdpa_option.index}"
    data-key="{$travel_pdpa_option.key}"
    data-meal="{$travel_pdpa_option.meal_key}"
    data-free="{if $travel_pdpa_option.free}1{else}0{/if}"
    data-immediate="{if $travel_pdpa_option.on_request}0{else}1{/if}"
    data-special="{if $travel_pdpa_option.special}1{else}0{/if}"
    data-amount="{$travel_pdpa_option.amount}">
    {if $travel_pdpa_room.single}
        <span class="travel-pdpa-opt__mark">{include file="addons/travel_core/components/design_a_icon.tpl" icon="check" size=20}</span>
    {else}
        <input type="radio" class="travel-pdpa-opt__radio" name="travel-pdpa-{$travel_pdpa_room.key}-option" value="{$travel_pdpa_option.index}" data-travel-pick{if $travel_pdpa_option.chosen} checked{/if}>
    {/if}
    <span class="travel-pdpa-opt__what">
        <span class="travel-pdpa-rmeal">{include file="addons/travel_core/components/design_a_icon.tpl" icon="meal"}{$travel_pdpa_option.meal|escape:html}</span>
        {if $travel_pdpa_option.name}<span class="travel-pdpa-rname">{$travel_pdpa_option.name|escape:html}</span>{/if}
        {include file="addons/travel_core/components/design_a_package.tpl" package=$travel_pdpa_option.package deal=$travel_pdpa_option.deal shared=false}
        {foreach $travel_pdpa_option.perks as $travel_pdpa_perk}
            <span class="travel-pdpa-incl">{include file="addons/travel_core/components/design_a_icon.tpl" icon="check" size=14}{$travel_pdpa_perk|escape:html}</span>
        {/foreach}
        {if $travel_pdpa_option.on_request && !$travel_pdpa_room.on_request}
            <span class="travel-pdpa-pill travel-pdpa-pill--info">{include file="addons/travel_core/components/design_a_icon.tpl" icon="clock" size=14}{__("travel_core.pdpa_r_on_request_tag")}</span>
        {/if}
        {if $travel_pdpa_option.cancel}{include file="addons/travel_core/components/design_a_cancel.tpl" cancel=$travel_pdpa_option.cancel}{/if}
        {if $travel_pdpa_option.payment}{include file="addons/travel_core/components/design_a_payment.tpl" payment=$travel_pdpa_option.payment}{/if}
    </span>
    {if !$travel_pdpa_room.single}
        <span class="travel-pdpa-opt__price">{$travel_pdpa_option.price|escape:html}</span>
    {/if}
</{$travel_pdpa_option_tag}>
