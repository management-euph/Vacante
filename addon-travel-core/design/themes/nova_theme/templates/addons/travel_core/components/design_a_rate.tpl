{*
    One rate row of a Design A room card (RoomResultsBuilder RateView):
    what it is, its own conditions when the room's rates differ, the price,
    the button. Included by design_a_room.tpl with $travel_pdpa_room and
    $travel_pdpa_rate set; $travel_pdpa_compact = the collapsed room's one
    row, titled "<meal plan> · <rate>".

    Data attributes drive the filters and the sort (pdp-design-a.js).
*}
{if $travel_pdpa_rate.name}
    {$travel_pdpa_rate_title = $travel_pdpa_rate.name}
{elseif $travel_pdpa_rate.discount}
    {$travel_pdpa_rate_title = $travel_pdpa_rate.discount}
{else}
    {$travel_pdpa_rate_title = __("travel_core.pdpa_r_standard_rate")}
{/if}
{if $travel_pdpa_compact && $travel_pdpa_results.grouped}
    {$travel_pdpa_rate_title = "`$travel_pdpa_rate.meal` · `$travel_pdpa_rate_title`"}
{/if}
{if $travel_pdpa_rate.on_request}
    {$travel_pdpa_rate_action = __("travel_core.pdpa_r_request")}
{else}
    {$travel_pdpa_rate_action = __("travel_core.pdpa_r_book")}
{/if}
<div class="travel-pdpa-rrow{if $travel_pdpa_rate.cheapest} travel-pdpa-rrow--best{/if}"
     data-travel-rate
     data-meal="{$travel_pdpa_rate.meal_key}"
     data-free="{if $travel_pdpa_rate.free}1{else}0{/if}"
     data-immediate="{if $travel_pdpa_rate.on_request}0{else}1{/if}"
     data-special="{if $travel_pdpa_rate.special}1{else}0{/if}"
     {if !$travel_pdpa_compact && !$travel_pdpa_rate.shown}data-travel-more hidden{/if}>
    <div class="travel-pdpa-rrow__what">
        {if $travel_pdpa_rate.cheapest}<span class="travel-pdpa-cue">{__("travel_core.pdpa_r_cheapest")}</span>{/if}
        <span class="travel-pdpa-rrow__title">{$travel_pdpa_rate_title|escape:html}</span>
        {if $travel_pdpa_rate.name && $travel_pdpa_rate.discount}<span class="travel-pdpa-cue travel-pdpa-cue--deal">{$travel_pdpa_rate.discount|escape:html}</span>{/if}
        {foreach $travel_pdpa_rate.perks as $travel_pdpa_perk}
            <span class="travel-pdpa-incl">{include file="addons/travel_core/components/design_a_icon.tpl" icon="check" size=14}{$travel_pdpa_perk|escape:html}</span>
        {/foreach}
        {if $travel_pdpa_rate.on_request && $travel_pdpa_room.status != "on_request"}
            <span class="travel-pdpa-pill travel-pdpa-pill--muted">{include file="addons/travel_core/components/design_a_icon.tpl" icon="clock" size=14}{__("travel_core.pdpa_r_on_request_tag")}</span>
        {/if}
        {if $travel_pdpa_room.cancellation == "differs" || $travel_pdpa_room.cancellation == ""}
            {if $travel_pdpa_rate.non_refundable}
                {if !$travel_pdpa_rate.name}<span class="travel-pdpa-rrow__cond">{__("travel_core.pdpa_r_non_refundable")}</span>{/if}
            {elseif $travel_pdpa_rate.free_until}
                <span class="travel-pdpa-incl">{include file="addons/travel_core/components/design_a_icon.tpl" icon="check" size=14}{__("travel_core.pdpa_r_free_until", ["[date]" => $travel_pdpa_rate.free_until|escape:html])}</span>
            {/if}
        {/if}
        {if !$travel_pdpa_room.payment}
            <span class="travel-pdpa-rrow__cond">{include file="addons/travel_core/components/design_a_payment.tpl" payment=$travel_pdpa_rate.payment}</span>
        {/if}
        {if !$travel_pdpa_room.terms && !$travel_pdpa_room.timeline && ($travel_pdpa_rate.terms || $travel_pdpa_rate.timeline)}
            {include file="addons/travel_core/components/design_a_terms.tpl" timeline=$travel_pdpa_rate.timeline terms=$travel_pdpa_rate.terms label="`$travel_pdpa_room.name`, `$travel_pdpa_rate_title`"}
        {/if}
    </div>
    <p class="travel-pdpa-rrow__price">
        <span class="travel-pdpa-price">{$travel_pdpa_rate.price|escape:html}</span>
        {if $travel_pdpa_rate.per_night}<span class="travel-pdpa-unit">{__("travel_core.pdpa_r_per_night", ["[price]" => $travel_pdpa_rate.per_night|escape:html])}</span>{/if}
        {if $travel_pdpa_rate.saving}<span class="travel-pdpa-delta">{__("travel_core.pdpa_r_saving", ["[amount]" => $travel_pdpa_rate.saving|escape:html])}</span>{/if}
    </p>
    <a href="{$travel_pdpa_rate.book_url|escape:html}"
       class="travel-pdpa-btn{if !$travel_pdpa_rate.primary} travel-pdpa-btn--sec{/if} travel-pdpa-rrow__btn"
       aria-label="{$travel_pdpa_rate_action|escape:html}: {$travel_pdpa_room.name|escape:html}{if $travel_pdpa_rate.meal}, {$travel_pdpa_rate.meal|escape:html}{/if}, {$travel_pdpa_rate_title|escape:html}, {$travel_pdpa_rate.price|escape:html}">{$travel_pdpa_rate_action}</a>
</div>
