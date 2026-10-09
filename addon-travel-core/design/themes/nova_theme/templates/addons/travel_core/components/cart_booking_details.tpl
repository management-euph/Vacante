{*
    Booking card on cart and checkout lines — ONE template for every provider
    (novoton, sphinx, eurosite). Every value arrives prepared by
    fn_travel_core_cart_booking_card() (ViewModels\CartBookingCardFactory):
    names split from supplier codes, dates in the store format, the guest
    list, the cancellation headline and timeline from the provider's own
    terms, the deposit split. So there is no provider branch, no arithmetic
    and no decoding in this markup, and a detail a provider does not supply
    arrives empty and its block is skipped.

    Parameters:
      product      the cart line
      key          its cart id (edit link)
      tcc_context  "sidebar" — checkout summary: the title, then the hotel (photo,
                   stars, destination), and booking-pages.css hides the core
                   product name / "1 x price" line above the card, which would
                   repeat both;
                   "summary" — inside the stay-summary block at the top of the
                   checkout (blocks/stay_summary.tpl), whose bar already shows
                   the hotel: no title, no header, no frame;
                   "cart" (default) — cart page and mini cart, whose rows
                   already show the product: the title only;
                   "order" — the customer's order page (hooks/orders/
                   product_info.post.tpl): the title with the booking's status,
                   the trip details of a circuit or package, and the balance of
                   a deposit order with its pay button. Its card comes from
                   fn_travel_core_order_booking_card(..., "customer"), which
                   carries nothing about the provider: no name, supplier
                   reference or price.
      tcc_card     optional: the prepared card, when the caller already has it.

    The collapsibles (guests, rooms, full terms) are native <details>: no JS.
    Styling: booking-pages.css .travel-ccard*.

    Usage (travel_core's checkout hooks):
      {include file="addons/travel_core/components/cart_booking_details.tpl" product=$product key=$key tcc_context="sidebar"}
*}
{if $tcc_card|default:false}
    {$tcc = $tcc_card}
{else}
    {$tcc = fn_travel_core_cart_booking_card($product|default:[], $key|default:'')}
{/if}
{$tcc_context = $tcc_context|default:"cart"}
{$tcc_sidebar = $tcc_context == "sidebar"}
{if $tcc}
<div class="travel-ccard travel-ccard--{$tcc_context}">

    {* The card's title. The stay-summary block's bar already names the stay. *}
    {if $tcc_context == "order"}
        <div class="travel-ccard-title travel-ccard-title--order">
            <span>{__("travel_core.your_booking_details")}</span>
            {if $tcc.kind == "circuit"}
                <span class="travel-ccard-kind">{__("travel_core.obc_kind_circuit")}</span>
            {elseif $tcc.kind == "package"}
                <span class="travel-ccard-kind">{if $tcc.transport == "flight"}{__("travel_core.obc_kind_flight_hotel")}{elseif $tcc.transport == "bus"}{__("travel_core.obc_kind_bus_hotel")}{else}{__("travel_core.obc_kind_package")}{/if}</span>
            {/if}
            {if $tcc.status.code}
                <span class="travel-ccard-status travel-ccard-status--{$tcc.status.tone}">{__("travel_core.obc_status_`$tcc.status.code`")}</span>
            {/if}
        </div>
    {elseif $tcc_context != "summary"}
        <div class="travel-ccard-title">{__("travel_core.your_booking_details")}</div>
    {/if}

    {* The pre-order verifier corrected this line's price. *}
    {if $tcc.price_change}
        <div class="travel-ccard-pricechange{if $tcc.price_change.up} travel-ccard-pricechange--up{/if}" role="status">
            <svg class="travel-ccard-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"></circle><path d="M12 8v5M12 16.5v.01"></path></svg>
            <span class="travel-ccard-pricechange__text">
                <strong>{if $tcc.price_change.up}{__("travel_core.price_updated_badge")}{else}{__("travel_core.price_dropped_badge")}{/if}</strong>
                <span><s class="travel-ccard-pricechange__old">{$tcc.price_change.old|escape:html}</s> &rarr; <strong>{$tcc.price_change.new|escape:html}</strong></span>
            </span>
        </div>
    {/if}

    {if $tcc_sidebar}
        <div class="travel-ccard-hotel">
            <div class="travel-ccard-hotel__photo">
                {if $tcc.hotel.image_pair}
                    {include file="common/image.tpl" images=$tcc.hotel.image_pair image_width=152 image_height=152 no_ids=true}
                {else}
                    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"></rect><circle cx="9" cy="10" r="1.6"></circle><path d="M21 16l-5-5-8 8"></path></svg>
                {/if}
            </div>
            <div class="travel-ccard-hotel__text">
                {if $tcc.hotel.stars > 0}
                    <span class="travel-ccard-stars" role="img" aria-label="{__("travel_core.stars_rating", ["[rating]" => $tcc.hotel.stars])|escape:html}">{"★"|str_repeat:$tcc.hotel.stars}</span>
                {/if}
                <span class="travel-ccard-hotel__name">
                    {if $tcc.hotel.product_id}
                        <a href="{"products.view?product_id=`$tcc.hotel.product_id`"|fn_url}">{$tcc.hotel.name|escape:html}</a>
                    {else}
                        {$tcc.hotel.name|escape:html}
                    {/if}
                </span>
                {if $tcc.hotel.location}
                    <span class="travel-ccard-hotel__location">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21s-7-6.2-7-11a7 7 0 0 1 14 0c0 4.8-7 11-7 11z"></path><circle cx="12" cy="10" r="2.5"></circle></svg>
                        {$tcc.hotel.location|escape:html}
                    </span>
                {/if}
            </div>
        </div>
    {/if}

    {* Check-in → nights → check-out *}
    <div class="travel-ccard-dates">
        <div class="travel-ccard-date">
            <span class="travel-ccard-date__label">{if $tcc.kind|default:"" == "circuit"}{__("travel_core.obc_departure")}{else}{__("travel_core.check_in")}{/if}</span>
            <span class="travel-ccard-date__value">{$tcc.check_in.date|escape:html}</span>
            {if $tcc.show_weekday && $tcc.check_in.weekday}<span class="travel-ccard-date__weekday">{$tcc.check_in.weekday|escape:html}</span>{/if}
        </div>
        <div class="travel-ccard-dates__stay">
            {if $tcc.nights > 0}<span class="travel-ccard-nights">{if $tcc.days|default:0}{__("travel_core.obc_n_days", [$tcc.days])}, {/if}{__("travel_core.n_nights", [$tcc.nights])}</span>{/if}
            <svg width="40" height="10" viewBox="0 0 40 10" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 5h37M33 1l4 4-4 4"></path></svg>
            {if $tcc.per_night}<span class="travel-ccard-pernight">{__("travel_core.price_per_night", ["[price]" => $tcc.per_night])}</span>{/if}
        </div>
        <div class="travel-ccard-date travel-ccard-date--end">
            <span class="travel-ccard-date__label">{if $tcc.kind|default:"" == "circuit"}{__("travel_core.obc_return")}{else}{__("travel_core.check_out")}{/if}</span>
            <span class="travel-ccard-date__value">{$tcc.check_out.date|escape:html}</span>
            {if $tcc.show_weekday && $tcc.check_out.weekday}<span class="travel-ccard-date__weekday">{$tcc.check_out.weekday|escape:html}</span>{/if}
        </div>
    </div>

    {* Room, meal plan, party *}
    <ul class="travel-ccard-facts">
        {if $tcc.room_list || $tcc.room.name}
            <li class="travel-ccard-fact">
                <span class="travel-ccard-fact__icon" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 18v-7a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v7"></path><path d="M3 14h18"></path><path d="M7 9V7a1 1 0 0 1 1-1h3a1 1 0 0 1 1 1v2"></path><path d="M3 18v2M21 18v2"></path></svg></span>
                <span class="travel-ccard-fact__text">
                    {if $tcc.room_list}
                        <strong>{__("travel_core.n_rooms", [$tcc.rooms])}</strong>
                        <span>{foreach from=$tcc.room_lines item="tcc_line" name="tcc_lines"}{$tcc_line.qty}&times; {$tcc_line.name|escape:html}{if !$smarty.foreach.tcc_lines.last}, {/if}{/foreach}</span>
                    {else}
                        <strong>{$tcc.room.name|escape:html}</strong>
                        {if $tcc.room.code}<span class="travel-ccard-code">{$tcc.room.code|escape:html}</span>{/if}
                    {/if}
                </span>
            </li>
        {/if}
        {if $tcc.board}
            <li class="travel-ccard-fact">
                <span class="travel-ccard-fact__icon" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M7 3v8M5 3v5a2 2 0 0 0 4 0V3M7 11v10"></path><path d="M17 21V3c-2 1.5-3 4-3 7h3"></path></svg></span>
                <span class="travel-ccard-fact__text">
                    <strong>{$tcc.board|escape:html}</strong>
                    <span>{__("travel_core.meal_plan")}</span>
                </span>
            </li>
        {/if}
        {if $tcc.adults > 0}
            <li class="travel-ccard-fact">
                <span class="travel-ccard-fact__icon" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3"></circle><path d="M3 20c0-3.3 2.7-6 6-6s6 2.7 6 6"></path><path d="M16 5.5a3 3 0 0 1 0 5M21 20c0-2.6-1.6-4.8-4-5.6"></path></svg></span>
                <span class="travel-ccard-fact__text">
                    <strong>{__("travel_core.n_adults", [$tcc.adults])}{if $tcc.children > 0}, {__("travel_core.n_children", [$tcc.children])}{/if}</strong>
                    {if $tcc.children_ages}
                        <span>{__("travel_core.childrens_ages")}: {$tcc.children_ages|escape:html}</span>
                    {elseif !$tcc.room_list}
                        <span>{__("travel_core.n_rooms", [$tcc.rooms])}</span>
                    {/if}
                </span>
            </li>
        {/if}
        {if $tcc.package|default:""}
            <li class="travel-ccard-fact">
                <span class="travel-ccard-fact__icon" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8z"></path><circle cx="7.5" cy="7.5" r="1.5"></circle></svg></span>
                <span class="travel-ccard-fact__text">
                    <strong>{$tcc.package|escape:html}</strong>
                    <span>{__("travel_core.package")}</span>
                </span>
            </li>
        {/if}
        {if $tcc.transport|default:"" || $tcc.departure|default:""}
            <li class="travel-ccard-fact">
                <span class="travel-ccard-fact__icon" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="6" cy="18" r="2"></circle><circle cx="18" cy="6" r="2"></circle><path d="M8 18h7a3 3 0 0 0 0-6H9a3 3 0 0 1 0-6h7"></path></svg></span>
                <span class="travel-ccard-fact__text">
                    <strong>{if $tcc.transport == "flight"}{__("travel_core.obc_transport_flight")}{elseif $tcc.transport == "bus"}{__("travel_core.obc_transport_bus")}{elseif $tcc.transport}{$tcc.transport|escape:html}{else}{$tcc.departure|escape:html}{/if}</strong>
                    <span>{if $tcc.transport && $tcc.departure}{__("travel_core.obc_from_city", ["[city]" => $tcc.departure])}{else}{__("travel_core.obc_transport")}{/if}</span>
                </span>
            </li>
        {/if}
        {if $tcc.services|default:[]}
            <li class="travel-ccard-fact">
                <span class="travel-ccard-fact__icon" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 12v8H4v-8"></path><path d="M2 7h20v5H2z"></path><path d="M12 20V7"></path><path d="M12 7c-1.5-3-5-3-5-1s3 1 5 1c2 0 5 1 5-1s-3.5-2-5 1z"></path></svg></span>
                <span class="travel-ccard-fact__text">
                    <strong>{", "|implode:$tcc.services|escape:html}</strong>
                    <span>{__("travel_core.obc_extras")}</span>
                </span>
            </li>
        {/if}
    </ul>

    {if $tcc.room_list}
        {* Multi-room: one collapsible row per room, the first one open. *}
        <div class="travel-ccard-rooms">
            {foreach from=$tcc.room_list item="tcc_room" name="tcc_rooms"}
                <details class="travel-ccard-disclosure"{if $smarty.foreach.tcc_rooms.first} open{/if}>
                    <summary class="travel-ccard-disclosure__summary">
                        <span class="travel-ccard-disclosure__text">
                            <strong>{__("travel_core.room")} {$tcc_room.number}{if $tcc_room.name} &middot; {$tcc_room.name|escape:html}{/if}</strong>
                            <span>{__("travel_core.n_adults", [$tcc_room.adults])}{if $tcc_room.children > 0}, {__("travel_core.n_children", [$tcc_room.children])}{if $tcc_room.children_ages} ({$tcc_room.children_ages|escape:html}){/if}{/if}{if $tcc_room.board} &middot; {$tcc_room.board|escape:html}{/if}</span>
                        </span>
                        {if $tcc_room.price}<span class="travel-ccard-disclosure__price">{$tcc_room.price|escape:html}</span>{/if}
                        <svg class="travel-ccard-chevron" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"></polyline></svg>
                    </summary>
                    <div class="travel-ccard-disclosure__body">
                        {if $tcc_room.code}<span class="travel-ccard-code">{$tcc_room.code|escape:html}</span>{/if}
                        {include file="addons/travel_core/components/cart_booking_room_body.tpl" cbr_guests=$tcc_room.guests}
                    </div>
                </details>
            {/foreach}
            {if $tcc.edit_url}
                <div class="travel-ccard-rooms__footer">
                    <a class="travel-ccard-edit" href="{$tcc.edit_url|fn_url}">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 20h4l10.5-10.5a2.1 2.1 0 0 0-3-3L5 17v3z"></path><path d="M13.5 6.5l3 3"></path></svg>
                        {__("travel_core.edit_guests")}
                    </a>
                </div>
            {/if}
        </div>
    {elseif $tcc.guests || $tcc.lead_guest || $tcc.edit_url}
        {* One room: the guests folded into one row, the edit link beside it. *}
        <div class="travel-ccard-guests">
            {if $tcc.guests}
                <details class="travel-ccard-disclosure">
                    <summary class="travel-ccard-disclosure__summary">
                        <span class="travel-ccard-disclosure__text">
                            <strong>{__("travel_core.guests")} ({$tcc.guest_count})</strong>
                            <span>{$tcc.lead_guest|escape:html}{if $tcc.guest_count > 1} &middot; {__("travel_core.n_more", ["[n]" => $tcc.guest_count - 1])}{/if}</span>
                        </span>
                        <svg class="travel-ccard-chevron" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"></polyline></svg>
                    </summary>
                    <div class="travel-ccard-disclosure__body">
                        {include file="addons/travel_core/components/cart_booking_room_body.tpl" cbr_guests=$tcc.guests}
                    </div>
                </details>
            {elseif $tcc.lead_guest}
                <div class="travel-ccard-disclosure__summary travel-ccard-disclosure__summary--static">
                    <span class="travel-ccard-disclosure__text">
                        <strong>{__("travel_core.guests")}</strong>
                        <span>{$tcc.lead_guest|escape:html}</span>
                    </span>
                </div>
            {/if}
            {if $tcc.edit_url}
                <a class="travel-ccard-edit travel-ccard-guests__edit" href="{$tcc.edit_url|fn_url}">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 20h4l10.5-10.5a2.1 2.1 0 0 0-3-3L5 17v3z"></path><path d="M13.5 6.5l3 3"></path></svg>
                    {__("travel_core.edit_guests")}
                </a>
            {/if}
        </div>
    {/if}

    {* Deposit bookings (DepositCartLine): this line charges the deposit, the
       balance is paid later from the order's pay link. *}
    {if $tcc.deposit && $tcc.balance|default:[]}
        {* A placed deposit order: what was paid, what is still owed and by
           when, and — while it is open — the button to pay it. *}
        <div class="travel-ccard-split">
            <span class="travel-ccard-split__title">{__("travel_core.paid_with_deposit")}</span>
            <div class="travel-ccard-split__row travel-ccard-split__row--now">
                <span>{__("travel_core.obc_deposit_paid")}</span>
                <strong>{$tcc.deposit.deposit|escape:html}</strong>
            </div>
            <div class="travel-ccard-split__row">
                <span>{__("travel_core.split_balance")}{if $tcc.balance.due} {__("travel_core.due_by", ["[date]" => $tcc.balance.due])}{/if}</span>
                <span>{$tcc.balance.amount|escape:html} <span class="travel-ccard-status travel-ccard-status--{if $tcc.balance.state == "paid"}ok{elseif $tcc.balance.state == "overdue"}bad{elseif $tcc.balance.state == "cancelled"}muted{else}warn{/if}">{__("travel_core.obc_balance_`$tcc.balance.state`")}</span></span>
            </div>
            <div class="travel-ccard-split__row travel-ccard-split__row--total">
                <span>{__("travel_core.split_total")}</span>
                <span>{$tcc.deposit.full|escape:html}</span>
            </div>
            {if $tcc.balance.pay_query}
                <a class="ty-btn ty-btn__primary travel-ccard-split__pay" href="{$tcc.balance.pay_query|fn_url}">{__("travel_core.pay_balance_btn")}</a>
            {/if}
        </div>
    {elseif $tcc.deposit}
        <div class="travel-ccard-split">
            <span class="travel-ccard-split__title">{__("travel_core.how_you_pay")}</span>
            <meter class="travel-ccard-split__bar" min="0" max="100" value="{$tcc.deposit.percent}" aria-hidden="true"></meter>
            <div class="travel-ccard-split__row travel-ccard-split__row--now">
                <span><span class="travel-ccard-split__key" aria-hidden="true"></span>{__("travel_core.split_today")} &middot; {__("travel_core.split_deposit")}</span>
                <strong>{$tcc.deposit.deposit|escape:html}</strong>
            </div>
            <div class="travel-ccard-split__row">
                <span><span class="travel-ccard-split__key travel-ccard-split__key--later" aria-hidden="true"></span>{__("travel_core.split_balance")}{if $tcc.deposit.balance_due} {__("travel_core.due_by", ["[date]" => $tcc.deposit.balance_due])}{/if}</span>
                <span>{$tcc.deposit.balance|escape:html}</span>
            </div>
            <div class="travel-ccard-split__row travel-ccard-split__row--total">
                <span>{__("travel_core.split_total")}</span>
                <span>{$tcc.deposit.full|escape:html}</span>
            </div>
            <span class="travel-ccard-split__note">{__("travel_core.deposit_cart_note")}</span>
        </div>
    {/if}

    {* Cancellation headline + the full cancellation & payment timeline. *}
    {if $tcc.cancel.state || $tcc.has_terms}
        <div class="travel-ccard-policy{if $tcc.cancel.state} travel-ccard-policy--{$tcc.cancel.state}{/if}">
            {if $tcc.cancel.state == "free"}
                <div class="travel-ccard-policy__row">
                    <svg class="travel-ccard-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"></circle><path d="M8 12.5l2.5 2.5L16 9.5"></path></svg>
                    <span class="travel-ccard-policy__text">
                        <strong>{__("travel_core.free_cancellation_until")} {$tcc.cancel.free_until|escape:html}</strong>
                        {if $tcc.cancel.then}
                            {capture assign="tcc_then"}{if $tcc.cancel.then.nights > 0}{__("travel_core.n_nights", [$tcc.cancel.then.nights])}{else}{$tcc.cancel.then.percent_label|escape:html}{/if}{if $tcc.cancel.then.amount_label} ({$tcc.cancel.then.amount_label|escape:html}){/if}{/capture}
                            <span>{__("travel_core.cancel_then_pay", ["[amount]" => $tcc_then])}</span>
                        {/if}
                    </span>
                </div>
            {elseif $tcc.cancel.state}
                <div class="travel-ccard-policy__row">
                    <svg class="travel-ccard-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"></circle><path d="M12 7v5l3 2"></path></svg>
                    <span class="travel-ccard-policy__text">
                        {if $tcc.cancel.state == "full"}
                            <strong>{__("travel_core.cancel_now_full")}</strong>
                        {else}
                            {capture assign="tcc_now"}{if $tcc.cancel.now.nights > 0}{__("travel_core.n_nights", [$tcc.cancel.now.nights])}{else}{$tcc.cancel.now.percent_label|escape:html}{/if}{if $tcc.cancel.now.amount_label} ({$tcc.cancel.now.amount_label|escape:html}){/if}{/capture}
                            <strong>{__("travel_core.cancel_now_costs", ["[amount]" => $tcc_now])}</strong>
                        {/if}
                    </span>
                </div>
            {/if}
            {if $tcc.has_terms}
                <details class="travel-ccard-terms">
                    <summary class="travel-ccard-terms__summary">{__("travel_core.cancel_payment_title")}</summary>
                    <div class="travel-ccard-terms__body">
                        {include file="addons/travel_core/components/booking_terms_timeline.tpl" tt=$tcc.terms}
                    </div>
                </details>
            {/if}
        </div>
    {/if}
</div>
{/if}

{* The balance of an earlier deposit booking (travel_balance.pay) *}
{if !empty($product.extra.travel_balance_id)}
<div class="travel-ccard travel-ccard--balance">
    <div class="travel-ccard-split">
        <span class="travel-ccard-split__title">{__("travel_core.balance_payment_for", ["[order_id]" => $product.extra.parent_order_id])}</span>
        {if $product.extra.hotel_name}<span class="travel-ccard-split__note">{$product.extra.hotel_name|escape:html}{if $product.extra.check_in} &middot; {fn_travel_core_store_date($product.extra.check_in)}{/if}</span>{/if}
    </div>
</div>
{/if}
