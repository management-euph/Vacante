{*
    One room card of the Design A results (RoomResultsBuilder RoomView): the
    room's facts on the left — what its rates share is said here once —
    and its rates on the right, grouped by meal plan when the hotel has
    several. A collapsed room shows its cheapest rate until "see all".
    Included by design_a_results.tpl with $travel_pdpa_room set.
*}
{$travel_pdpa_room_rates_text = __("travel_core.pdpa_r_rates_`$travel_pdpa_room.rate_form`", ["[n]" => $travel_pdpa_room.rate_count])}
<li class="travel-pdpa-rcard" id="travel-pdpa-{$travel_pdpa_room.key}" data-travel-room data-from="{$travel_pdpa_room.from}">
    <div class="travel-pdpa-rcard__info">
        <div class="travel-pdpa-rcard__head">
            <span class="travel-pdpa-icontile">{include file="addons/travel_core/components/design_a_icon.tpl" icon="bed" size=18}</span>
            <h3 class="travel-pdpa-rcard__name">{$travel_pdpa_room.name|escape:html}</h3>
        </div>
        <div class="travel-pdpa-rcard__who">
            {if $travel_pdpa_room.detail}<span class="travel-pdpa-attr">{$travel_pdpa_room.detail|escape:html}</span>{/if}
            <span class="travel-pdpa-for">{include file="addons/travel_core/components/design_a_icon.tpl" icon="users"}{__("travel_core.pdpa_r_for", ["[guests]" => $travel_pdpa_guests|escape:html])}</span>
            {if $travel_pdpa_room.status == "immediate"}
                <span class="travel-pdpa-pill travel-pdpa-pill--ok">{include file="addons/travel_core/components/design_a_icon.tpl" icon="check" size=14}{__("travel_core.pdpa_r_immediate")}</span>
            {elseif $travel_pdpa_room.status == "on_request"}
                <span class="travel-pdpa-pill travel-pdpa-pill--muted">{include file="addons/travel_core/components/design_a_icon.tpl" icon="clock" size=14}{__("travel_core.pdpa_r_on_request")}</span>
            {/if}
            {if $travel_pdpa_room.left == 1}
                <span class="travel-pdpa-pill travel-pdpa-pill--warn">{include file="addons/travel_core/components/design_a_icon.tpl" icon="alert" size=14}{__("travel_core.pdpa_r_last_room")}</span>
            {elseif $travel_pdpa_room.left > 1}
                <span class="travel-pdpa-pill travel-pdpa-pill--warn">{include file="addons/travel_core/components/design_a_icon.tpl" icon="alert" size=14}{__("travel_core.pdpa_r_few_left", ["[n]" => $travel_pdpa_room.left])}</span>
            {/if}
        </div>
        {if $travel_pdpa_room.cancellation == "free"}
            <p class="travel-pdpa-hoist">{include file="addons/travel_core/components/design_a_icon.tpl" icon="check"}<span>{__("travel_core.pdpa_r_cancel_free", ["[date]" => $travel_pdpa_room.free_until|escape:html])}</span></p>
        {elseif $travel_pdpa_room.cancellation == "non_refundable"}
            <p class="travel-pdpa-hoist travel-pdpa-hoist--var">{include file="addons/travel_core/components/design_a_icon.tpl" icon="info"}<span>{__("travel_core.pdpa_r_cancel_non_refundable")}</span></p>
        {elseif $travel_pdpa_room.cancellation == "differs"}
            <p class="travel-pdpa-hoist travel-pdpa-hoist--var">{include file="addons/travel_core/components/design_a_icon.tpl" icon="info"}<span>{__("travel_core.pdpa_r_cancel_differs")}<span class="travel-pdpa-hoist__sub">{__("travel_core.pdpa_r_cancel_differs_sub")}</span></span></p>
        {/if}
        {if $travel_pdpa_room.payment}
            <p class="travel-pdpa-hoist travel-pdpa-hoist--pay">{include file="addons/travel_core/components/design_a_icon.tpl" icon="card"}<span>{include file="addons/travel_core/components/design_a_payment.tpl" payment=$travel_pdpa_room.payment}<span class="travel-pdpa-pay">{__("travel_core.pdpa_r_every_rate")}</span></span></p>
        {/if}
        {if $travel_pdpa_room.facilities || $travel_pdpa_room.terms}
            <div class="travel-pdpa-rcard__facrow">
                {if $travel_pdpa_room.facilities}<p class="travel-pdpa-rcard__fac">{$travel_pdpa_room.facilities|escape:html}</p>{/if}
                {if $travel_pdpa_room.terms}
                    {include file="addons/travel_core/components/design_a_terms.tpl" terms=$travel_pdpa_room.terms label=$travel_pdpa_room.name}
                {/if}
            </div>
        {/if}
    </div>
    <div class="travel-pdpa-rcard__rates">
        {if $travel_pdpa_room.status == "on_request"}
            <div class="travel-pdpa-rcard__note"><p class="travel-pdpa-note">{include file="addons/travel_core/components/design_a_icon.tpl" icon="info"}<span>{__("travel_core.pdpa_r_on_request_note")}</span></p></div>
        {/if}
        {if $travel_pdpa_room.collapsed}
            <div class="travel-pdpa-rcard__compact" data-travel-compact>
                {include file="addons/travel_core/components/design_a_rate.tpl" travel_pdpa_rate=$travel_pdpa_room.cheapest_rate travel_pdpa_compact=true}
            </div>
        {/if}
        <div class="travel-pdpa-rcard__list" id="travel-pdpa-{$travel_pdpa_room.key}-rates" data-travel-rates{if $travel_pdpa_room.collapsed} hidden{/if}>
            {foreach $travel_pdpa_room.groups as $travel_pdpa_group name=travel_pdpa_groups}
                <div class="travel-pdpa-rgrp"
                     data-travel-group
                     {if $travel_pdpa_group.meal}role="group" aria-labelledby="travel-pdpa-{$travel_pdpa_room.key}-m{$smarty.foreach.travel_pdpa_groups.index}"{/if}
                     {if !$travel_pdpa_group.shown}data-travel-more hidden{/if}>
                    {if $travel_pdpa_group.meal}
                        <p class="travel-pdpa-rcard__meal" id="travel-pdpa-{$travel_pdpa_room.key}-m{$smarty.foreach.travel_pdpa_groups.index}">{include file="addons/travel_core/components/design_a_icon.tpl" icon="meal" size=14}<span>{$travel_pdpa_group.meal|escape:html}</span>{if $travel_pdpa_group.count > 1}<span class="travel-pdpa-rcard__mealm">· {__("travel_core.pdpa_r_rates_`$travel_pdpa_group.form`", ["[n]" => $travel_pdpa_group.count])}</span>{/if}</p>
                    {/if}
                    {foreach $travel_pdpa_group.rates as $travel_pdpa_rate}
                        {include file="addons/travel_core/components/design_a_rate.tpl" travel_pdpa_compact=false}
                    {/foreach}
                </div>
            {/foreach}
        </div>
        {if $travel_pdpa_room.hidden > 0}
            <button type="button" class="travel-pdpa-linkbtn travel-pdpa-rcard__all" data-travel-see-all aria-expanded="false" aria-controls="travel-pdpa-{$travel_pdpa_room.key}-rates">
                {if $travel_pdpa_room.collapsed}
                    {__("travel_core.pdpa_r_see_all", ["[rates]" => $travel_pdpa_room_rates_text, "[range]" => $travel_pdpa_room.range|escape:html])}
                {else}
                    {__("travel_core.pdpa_r_see_all_room", ["[rates]" => $travel_pdpa_room_rates_text, "[range]" => $travel_pdpa_room.range|escape:html])}
                {/if}
            </button>
        {/if}
    </div>
</li>
