{*
    The till of a Design A room for one of its options (board 13b): the
    meal, the stay and guests, the struck price with its badge (an empty
    line of the same height without one, so the totals and the buttons of
    all cards line up), the total, per night, the button and one short line
    under it: "Nu plătești încă", or on request "Răspuns în max. 48 h" with
    an ⓘ that opens how a request works (on click or tap, not on hover).
    The room shows its chosen option's till; pdp-design-a.js switches them
    with the radios. Included by design_a_room.tpl with $travel_pdpa_room
    and $travel_pdpa_option set.

    Several rooms ($travel_pdpa_group set, boards 13c): the till chooses for
    its group instead of booking. A group of several rooms gets a room count
    (at most the group's, at most the rooms left of the type), the total for
    that count and the price of one room; the button names what it covers
    ("Alege pentru Camera 2", "Alege pentru 8 camere") and turns into
    "Aleasă" once chosen; a room on request explains that only it waits for
    the hotel. pdp-design-a-rooms.js counts, chooses and books (the bar);
    room by room (board 13c.9) it hides the count and the price per room and
    chooses for the room open ("Alege pentru Camera 3").

    Past the free cancellation (Cancel "penalty") or non-refundable, a
    short word over the button says so before booking ("Fără anulare
    gratuită de azi.", "Nerambursabil: …"); the card's line has the amount.
*}
{if $travel_pdpa_option.on_request}
    {$travel_pdpa_action = __("travel_core.pdpa_r_request")}
{else}
    {$travel_pdpa_action = __("travel_core.pdpa_r_book")}
{/if}
{$travel_pdpa_tip_id = "travel-pdpa-`$travel_pdpa_room.key`-tip`$travel_pdpa_option.index`"}
{* What cancelling costs today, the option's own or the room's (design_a_cancel.tpl): said again over the button *}
{$travel_pdpa_tcancel = $travel_pdpa_option.cancel|default:$travel_pdpa_room.cancel}
{if $travel_pdpa_group}
    {$travel_pdpa_size = $travel_pdpa_group.size}
    {$travel_pdpa_qty = $travel_pdpa_room.qty}
    {$travel_pdpa_qty_text = __("travel_core.pdpa_m_rooms_`$travel_pdpa_room.qty_form`", ["[n]" => $travel_pdpa_qty])}
    {if $travel_pdpa_size > 1}
        {$travel_pdpa_choose = __("travel_core.pdpa_m_choose_rooms", ["[rooms]" => $travel_pdpa_qty_text])}
    {else}
        {$travel_pdpa_choose = __("travel_core.pdpa_m_choose_room", ["[n]" => $travel_pdpa_group.rooms[0]])}
    {/if}
    <div class="travel-pdpa-till travel-pdpa-till--group" data-travel-for="{$travel_pdpa_option.index}"
         data-travel-say="{$travel_pdpa_option.meal|escape:html}, {__("travel_core.pdpa_r_total_short")} {$travel_pdpa_option.qty_price|escape:html}"
         data-unit="{$travel_pdpa_option.unit}" data-unit-was="{$travel_pdpa_option.unit_was}" data-off="{$travel_pdpa_option.off|escape:html}"
         data-meal-name="{$travel_pdpa_option.meal|escape:html}" data-meal-key="{$travel_pdpa_option.meal_key}"
         data-on-request="{if $travel_pdpa_option.on_request}1{else}0{/if}" data-room-json="{$travel_pdpa_option.room_json|escape:html}"{if !$travel_pdpa_option.chosen} hidden{/if}>
        {if $travel_pdpa_size > 1}
            <div class="travel-pdpa-qty" data-travel-qty-box>
                <span class="travel-pdpa-qty__l" id="{$travel_pdpa_tip_id}-q">{__("travel_core.pdpa_m_qty")}</span>
                <div class="travel-pdpa-qty__c" role="group" aria-labelledby="{$travel_pdpa_tip_id}-q">
                    <button type="button" class="travel-pdpa-qty__b" data-travel-qty-step="-1" aria-label="{__("travel_core.pdpa_m_qty_less")}"{if $travel_pdpa_qty <= 1} disabled{/if}>{include file="addons/travel_core/components/design_a_icon.tpl" icon="minus"}</button>
                    <span class="travel-pdpa-qty__v" data-travel-qty aria-live="polite">{$travel_pdpa_qty}</span>
                    <button type="button" class="travel-pdpa-qty__b" data-travel-qty-step="1" aria-label="{__("travel_core.pdpa_m_qty_more")}"{if $travel_pdpa_qty >= $travel_pdpa_size || ($travel_pdpa_room.left > 0 && $travel_pdpa_qty >= $travel_pdpa_room.left)} disabled{/if}>{include file="addons/travel_core/components/design_a_icon.tpl" icon="plus"}</button>
                </div>
                <span class="travel-pdpa-qty__of">{__("travel_core.pdpa_m_qty_of", ["[n]" => $travel_pdpa_size])}</span>
            </div>
        {/if}
        <p class="travel-pdpa-till__sum">
            <span class="travel-pdpa-till__meal">{$travel_pdpa_option.meal|escape:html}</span>
            {if $travel_pdpa_size > 1}
                <span class="travel-pdpa-till__l" data-travel-till-label>{__("travel_core.pdpa_m_total_rooms", ["[rooms]" => $travel_pdpa_qty_text, "[nights]" => $travel_pdpa_nights])}</span>
            {else}
                <span class="travel-pdpa-till__l">{__("travel_core.pdpa_r_total", ["[nights]" => $travel_pdpa_nights, "[guests]" => $travel_pdpa_guests|escape:html])}</span>
            {/if}
            <span class="travel-pdpa-till__was" data-travel-till-was>{if $travel_pdpa_option.qty_was}<span class="travel-pdpa-sr">{__("travel_core.pdpa_r_was")}: </span><s class="travel-pdpa-strike">{$travel_pdpa_option.qty_was|escape:html}</s>{if $travel_pdpa_option.off}<span class="travel-pdpa-off"><span class="travel-pdpa-sr">{__("travel_core.pdpa_r_off")} </span>{$travel_pdpa_option.off|escape:html}</span>{/if}{/if}</span>
            <b class="travel-pdpa-till__total" data-travel-till-total>{$travel_pdpa_option.qty_price|escape:html}</b>
            {if $travel_pdpa_size > 1}
                <span class="travel-pdpa-unit" data-travel-per-room>{__("travel_core.pdpa_m_per_room", ["[price]" => $travel_pdpa_option.price|escape:html])}</span>
            {elseif $travel_pdpa_option.per_night}
                <span class="travel-pdpa-unit">{__("travel_core.pdpa_r_per_night", ["[price]" => $travel_pdpa_option.per_night|escape:html])}</span>
            {/if}
        </p>
        <p class="travel-pdpa-capnote" data-travel-cap{if !($travel_pdpa_size > 1 && $travel_pdpa_room.left > 0 && $travel_pdpa_room.left < $travel_pdpa_size)} hidden{/if}>{if $travel_pdpa_size > 1 && $travel_pdpa_room.left > 0 && $travel_pdpa_room.left < $travel_pdpa_size}{__("travel_core.pdpa_m_cap", ["[n]" => $travel_pdpa_room.left, "[size]" => $travel_pdpa_size])}{/if}</p>
        {if $travel_pdpa_tcancel && $travel_pdpa_tcancel.kind == "penalty"}
            <p class="travel-pdpa-tillwarn">{include file="addons/travel_core/components/design_a_icon.tpl" icon="alert" size=14}<span><b>{__("travel_core.pdpa_r_no_free_lead")}</b> {__("travel_core.pdpa_r_no_free_today")}</span></p>
        {elseif $travel_pdpa_tcancel && $travel_pdpa_tcancel.kind == "non_refundable"}
            <p class="travel-pdpa-tillwarn travel-pdpa-tillwarn--full">{include file="addons/travel_core/components/design_a_icon.tpl" icon="alert" size=14}<span><b>{__("travel_core.pdpa_r_nrf_lead")}</b> {__("travel_core.pdpa_r_nrf_warn")}</span></p>
        {/if}
        <div class="travel-pdpa-cta">
            <button type="button" class="travel-pdpa-btn travel-pdpa-till__btn" data-travel-choose aria-pressed="false"
                    aria-label="{$travel_pdpa_choose|escape:html}: {$travel_pdpa_room.title|escape:html}, {$travel_pdpa_option.meal|escape:html}, {$travel_pdpa_option.qty_price|escape:html}">{$travel_pdpa_choose}</button>
            {if $travel_pdpa_option.on_request}
                <p class="travel-pdpa-cta__note">{include file="addons/travel_core/components/design_a_icon.tpl" icon="clock" size=14}{__("travel_core.pdpa_r_reply_48h")}<button type="button" class="travel-pdpa-tip" data-travel-tip aria-expanded="false" aria-controls="{$travel_pdpa_tip_id}" aria-label="{__("travel_core.pdpa_r_how_request")}">{include file="addons/travel_core/components/design_a_icon.tpl" icon="info"}</button></p>
                <div class="travel-pdpa-tip__pop" id="{$travel_pdpa_tip_id}" role="dialog" aria-labelledby="{$travel_pdpa_tip_id}-h" data-travel-tip-pop hidden>
                    <p class="travel-pdpa-tip__h" id="{$travel_pdpa_tip_id}-h">{__("travel_core.pdpa_r_how_request")}</p>
                    <ol class="travel-pdpa-tip__list">
                        <li><b>{__("travel_core.pdpa_m_request_step1_lead")}</b> {__("travel_core.pdpa_m_request_step1")}</li>
                        <li><b>{__("travel_core.pdpa_m_request_step2_lead")}</b> {__("travel_core.pdpa_m_request_step2")}</li>
                        <li><b>{__("travel_core.pdpa_m_request_step3_lead")}</b> {__("travel_core.pdpa_m_request_step3")}</li>
                    </ol>
                    <button type="button" class="travel-pdpa-tip__x" data-travel-tip-close aria-label="{__("travel_core.pdpa_r_close")}">{include file="addons/travel_core/components/design_a_icon.tpl" icon="x"}</button>
                </div>
            {else}
                <p class="travel-pdpa-cta__note">{__("travel_core.pdpa_r_no_pay_yet")}</p>
            {/if}
        </div>
    </div>
{else}
<div class="travel-pdpa-till" data-travel-for="{$travel_pdpa_option.index}" data-travel-say="{$travel_pdpa_option.meal|escape:html}, {__("travel_core.pdpa_r_total_short")} {$travel_pdpa_option.price|escape:html}"{if !$travel_pdpa_option.chosen} hidden{/if}>
    <p class="travel-pdpa-till__sum">
        <span class="travel-pdpa-till__meal">{$travel_pdpa_option.meal|escape:html}</span>
        <span class="travel-pdpa-till__l">{__("travel_core.pdpa_r_total", ["[nights]" => $travel_pdpa_nights, "[guests]" => $travel_pdpa_guests|escape:html])}</span>
        <span class="travel-pdpa-till__was">{if $travel_pdpa_option.was}<span class="travel-pdpa-sr">{__("travel_core.pdpa_r_was")}: </span><s class="travel-pdpa-strike">{$travel_pdpa_option.was|escape:html}</s>{if $travel_pdpa_option.off}<span class="travel-pdpa-off"><span class="travel-pdpa-sr">{__("travel_core.pdpa_r_off")} </span>{$travel_pdpa_option.off|escape:html}</span>{/if}{/if}</span>
        <b class="travel-pdpa-till__total">{$travel_pdpa_option.price|escape:html}</b>
        {if $travel_pdpa_option.per_night}<span class="travel-pdpa-unit">{__("travel_core.pdpa_r_per_night", ["[price]" => $travel_pdpa_option.per_night|escape:html])}</span>{/if}
    </p>
    {if $travel_pdpa_tcancel && $travel_pdpa_tcancel.kind == "penalty"}
        <p class="travel-pdpa-tillwarn">{include file="addons/travel_core/components/design_a_icon.tpl" icon="alert" size=14}<span><b>{__("travel_core.pdpa_r_no_free_lead")}</b> {__("travel_core.pdpa_r_no_free_today")}</span></p>
    {elseif $travel_pdpa_tcancel && $travel_pdpa_tcancel.kind == "non_refundable"}
        <p class="travel-pdpa-tillwarn travel-pdpa-tillwarn--full">{include file="addons/travel_core/components/design_a_icon.tpl" icon="alert" size=14}<span><b>{__("travel_core.pdpa_r_nrf_lead")}</b> {__("travel_core.pdpa_r_nrf_warn")}</span></p>
    {/if}
    <div class="travel-pdpa-cta">
        <a href="{$travel_pdpa_option.book_url|escape:html}" class="travel-pdpa-btn travel-pdpa-till__btn"
           aria-label="{$travel_pdpa_action|escape:html}: {$travel_pdpa_room.title|escape:html}, {$travel_pdpa_option.meal|escape:html}, {$travel_pdpa_option.price|escape:html}">{$travel_pdpa_action}</a>
        {if $travel_pdpa_option.on_request}
            <p class="travel-pdpa-cta__note">{include file="addons/travel_core/components/design_a_icon.tpl" icon="clock" size=14}{__("travel_core.pdpa_r_reply_48h")}<button type="button" class="travel-pdpa-tip" data-travel-tip aria-expanded="false" aria-controls="{$travel_pdpa_tip_id}" aria-label="{__("travel_core.pdpa_r_how_request")}">{include file="addons/travel_core/components/design_a_icon.tpl" icon="info"}</button></p>
            <div class="travel-pdpa-tip__pop" id="{$travel_pdpa_tip_id}" role="dialog" aria-labelledby="{$travel_pdpa_tip_id}-h" data-travel-tip-pop hidden>
                <p class="travel-pdpa-tip__h" id="{$travel_pdpa_tip_id}-h">{__("travel_core.pdpa_r_how_request")}</p>
                <ol class="travel-pdpa-tip__list">
                    <li><b>{__("travel_core.pdpa_r_request_step1_lead")}</b> {__("travel_core.pdpa_r_request_step1")}</li>
                    <li><b>{__("travel_core.pdpa_r_request_step2_lead")}</b> {__("travel_core.pdpa_r_request_step2")}</li>
                    <li><b>{__("travel_core.pdpa_r_request_step3_lead")}</b> {__("travel_core.pdpa_r_request_step3")}</li>
                </ol>
                <button type="button" class="travel-pdpa-tip__x" data-travel-tip-close aria-label="{__("travel_core.pdpa_r_close")}">{include file="addons/travel_core/components/design_a_icon.tpl" icon="x"}</button>
            </div>
        {else}
            <p class="travel-pdpa-cta__note">{__("travel_core.pdpa_r_no_pay_yet")}</p>
        {/if}
    </div>
</div>
{/if}
