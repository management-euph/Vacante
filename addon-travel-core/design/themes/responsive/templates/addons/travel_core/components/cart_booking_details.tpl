{*
    Shared cart/checkout booking-details card — the ONE template that renders
    a travel booking's details on cart lines for BOTH providers (novoton +
    sphinx). Ported from novoton's hooks/checkout/product_info.post.tpl and
    made provider-neutral:

    - gated on the provider-neutral $product.extra.travel_booking flag
      (both providers' add_to_cart set it);
    - every field renders only when its extra exists, so a provider that
      lacks a detail (API doesn't supply it) simply omits the line;
    - the edit link derives its dispatch from extra.travel_provider;
    - labels come from travel_core.* keys (self-seeded);
    - the room-collapse toggle lives in js/addons/travel_core/
      cart-booking-details.js (no inline JS — InlineScriptRatchet);
    - styling lives in booking-pages.css (.travel-bcard-*) — the only
      inline style left is the JS-toggled display state (InlineStyleRatchet
      permits display:none).

    Usage (from travel_core's checkout hooks):
      {include file="addons/travel_core/components/cart_booking_details.tpl" product=$product key=$key}
*}
{if !empty($product.extra.travel_booking)}
{$total_adults = 0}
{$total_children = 0}
{$total_rooms = $product.extra.num_rooms|default:1}
{$nights_count = $product.extra.nights|default:7}

{if $product.extra.rooms_data}
    {foreach from=$product.extra.rooms_data item=room}
        {$total_adults = $total_adults+$room.adults|default:2}
        {$total_children = $total_children+$room.children|default:0}
    {/foreach}
{else}
    {$total_adults = $product.extra.adults|default:2}
    {$total_children = $product.extra.children|default:0}
{/if}

{if $product.extra.travel_provider == 'sphinx'}
    {$_edit_dispatch = 'sphinx_booking.edit_booking'}
{else}
    {$_edit_dispatch = 'novoton_booking.edit_booking'}
{/if}
{$_edit_booking_id = $product.extra.travel_booking_id|default:$product.extra.novoton_booking_id|default:0}

<div class="travel-booking-card travel-bcard">

    {* Separator line *}
    <div class="travel-bcard-accent"></div>

    {* Header: Your booking details *}
    <div class="travel-bcard-head">
        <div class="travel-bcard-title">{__("travel_core.your_booking_details")|default:"Your booking details"}</div>

        {* Check-in / Check-out dates *}
        <div class="travel-bcard-dates">
            <div>
                <div class="travel-bcard-date-label">{__("travel_core.check_in")|default:"Check-in"}</div>
                <div class="travel-bcard-date-value">
                    {$product.extra.check_in|default:''|date_format:$settings.Appearance.date_format|default:"%d.%m.%Y"}
                </div>
            </div>
            <div>
                <div class="travel-bcard-date-label">{__("travel_core.check_out")|default:"Check-out"}</div>
                <div class="travel-bcard-date-value">
                    {$product.extra.check_out|default:''|date_format:$settings.Appearance.date_format|default:"%d.%m.%Y"}
                </div>
            </div>
            <div>
                <div class="travel-bcard-date-label">{__("travel_core.total_stay")|default:"Total stay"}</div>
                <div class="travel-bcard-date-value travel-bcard-date-value--plain">
                    {$nights_count} {if $nights_count == 1}{__("travel_core.night")|default:"night"}{else}{__("travel_core.nights")|default:"nights"}{/if}
                </div>
            </div>
        </div>
    </div>

    {* Room + party summary *}
    <div class="travel-bcard-summary">
        {$total_rooms} {if $total_rooms == 1}{__("travel_core.room")|default:"room"}{else}{__("travel_core.rooms")|default:"rooms"}{/if} {__("travel_core.for")|default:"for"} {$total_adults} {if $total_adults == 1}{__("travel_core.adult")|default:"adult"}{else}{__("travel_core.adults")|default:"adults"}{/if}{if $total_children > 0}, {$total_children} {if $total_children == 1}{__("travel_core.child")|default:"child"}{else}{__("travel_core.children")|default:"children"}{/if}{/if}
    </div>

    {* Room cards *}
    {if $product.extra.num_rooms > 1 && $product.extra.rooms_data}
        {* Multi-room: first expanded, rest collapsed *}
        {foreach from=$product.extra.rooms_data item=room key=idx}
            {$room_number = $idx+1}
            {$is_first = ($idx == 0)}
            {$collapse_id = "room_collapse_`$key`_`$idx`"}

            <div class="travel-room-card travel-bcard-room">
                {* Room header — clickable for collapse (handler in cart-booking-details.js) *}
                <div class="travel-bcard-room-head" onclick="travelToggleRoomCard('{$collapse_id}')">
                    <div class="travel-bcard-room-name">
                        <span>{__("travel_core.room")|default:"Room"} {$room_number}: {$room.room_type_display|default:$room.room_name|default:$room.room_id}</span>
                    </div>
                    <span class="travel-bcard-room-price">{$room.price|default:0|number_format:0} {$smarty.const.CART_PRIMARY_CURRENCY}</span>
                    <span id="{$collapse_id}_icon" class="travel-bcard-chevron{if $is_first} travel-bcard-chevron--open{/if}"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg></span>
                </div>

                {* Room details — collapsible (display state is JS-toggled) *}
                <div id="{$collapse_id}" class="travel-bcard-room-body" {if !$is_first}style="display: none;"{/if}>
                    {include file="addons/travel_core/components/cart_booking_room_body.tpl"
                        cbd_room=$room
                        cbd_room_number=$room_number
                        cbd_extra=$product.extra}
                </div>
            </div>
        {/foreach}
    {else}
        {* Single room — always expanded *}
        <div class="travel-room-card travel-bcard-room-single">
            {if $product.extra.room_type_display || $product.extra.room_name || $product.extra.room_id}
            <div class="travel-bcard-room-title">
                {$product.extra.room_type_display|default:$product.extra.room_name|default:$product.extra.room_id}
            </div>
            {/if}
            {include file="addons/travel_core/components/cart_booking_room_body.tpl"
                cbd_room=null
                cbd_room_number=0
                cbd_extra=$product.extra}
        </div>
    {/if}

    {* Paid with a deposit (travel_core DepositCartLine): this line charges
       the deposit; the balance is paid later from the order's pay link. *}
    {if !empty($product.extra.travel_deposit.deposit)}
        <div class="travel-bcard-deposit">
            <span class="travel-bcard-deposit__item">{__("travel_core.split_total")} <strong>{include file="common/price.tpl" value=$product.extra.travel_deposit.full}</strong></span>
            <span class="travel-bcard-deposit__item">{__("travel_core.split_deposit")} <strong>{include file="common/price.tpl" value=$product.extra.travel_deposit.deposit}</strong></span>
            <span class="travel-bcard-deposit__item">{__("travel_core.split_balance")} <strong>{include file="common/price.tpl" value=$product.extra.travel_deposit.balance}</strong> {__("travel_core.due_by", ["[date]" => fn_travel_core_store_date($product.extra.travel_deposit.balance_due)])}</span>
            <span class="travel-bcard-deposit__note">{__("travel_core.deposit_cart_note")}</span>
        </div>
    {/if}

    {* Line-item price change: the pre-order verifier corrected this line —
       cross out the old price, show the new one (both providers write
       extra.price_before_correction on correction). *}
    {if !empty($product.extra.price_before_correction) && $product.extra.price_before_correction != $product.extra.total_price}
        <div class="travel-bcard-price-change">
            <span class="travel-bcard-price-change__old">{$product.extra.price_before_correction|fn_format_price} {$smarty.const.CART_PRIMARY_CURRENCY}</span>
            <span class="travel-bcard-price-change__arrow">&rarr;</span>
            <span class="travel-bcard-price-change__new">{$product.extra.total_price|fn_format_price} {$smarty.const.CART_PRIMARY_CURRENCY}</span>
            {if $product.extra.total_price > $product.extra.price_before_correction}
                <span class="travel-bcard-price-change__badge travel-bcard-price-change__badge--warning">{__("travel_core.price_updated_badge")|default:"Price Updated"}</span>
            {else}
                <span class="travel-bcard-price-change__badge travel-bcard-price-change__badge--success">{__("travel_core.price_dropped_badge")|default:"Price Dropped!"}</span>
            {/if}
        </div>
    {/if}

    {* Edit link *}
    {if $_edit_booking_id}
        <div class="travel-bcard-footer">
            <a href="{"`$_edit_dispatch`?booking_id=`$_edit_booking_id`&cart_id=`$key`"|fn_url}" class="travel-bcard-edit-link">
                {__("travel_core.edit_guest_details")|default:"Edit guest details"}
            </a>
        </div>
    {/if}
</div>

{script src="js/addons/travel_core/cart-booking-details.js"}
{/if}

{* The balance of an earlier deposit booking (travel_balance.pay) *}
{if !empty($product.extra.travel_balance_id)}
<div class="travel-booking-card travel-bcard travel-bcard--balance">
    <div class="travel-bcard-deposit">
        <span class="travel-bcard-deposit__item"><strong>{__("travel_core.balance_payment_for", ["[order_id]" => $product.extra.parent_order_id])}</strong></span>
        {if $product.extra.hotel_name}<span class="travel-bcard-deposit__item">{$product.extra.hotel_name|escape:html}{if $product.extra.check_in} · {fn_travel_core_store_date($product.extra.check_in)}{/if}</span>{/if}
    </div>
</div>
{/if}
