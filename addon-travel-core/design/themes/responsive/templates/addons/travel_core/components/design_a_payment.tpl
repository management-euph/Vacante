{*
    How a Design A option, or all of a room's options, is paid
    (RoomResultsBuilder Payment): on request, by card on booking (refunded
    if the hotel does not confirm) or by bank transfer once it confirms (the
    owner's rule: checkout charges every room, those on request too); the store's own words for a
    supplier without payment terms in the search, a deposit and the rest by
    a date (DepositPlan), or the full price on booking.
    Params: payment
*}
{if $payment.on_request}
    <span class="travel-pdpa-payl">{include file="addons/travel_core/components/design_a_icon.tpl" icon="card" size=14}<span>{__("travel_core.pdpa_r_pay_on_request")}</span></span>
{else}
    <span class="travel-pdpa-payl">{include file="addons/travel_core/components/design_a_icon.tpl" icon="card" size=14}<span>{if $payment.text}{$payment.text|escape:html}{elseif $payment.deposit_percent > 0}{__("travel_core.pdpa_r_pay_deposit", ["[percent]" => $payment.deposit_percent, "[date]" => $payment.balance_due|escape:html])}{else}{__("travel_core.pdpa_r_pay_full")}{/if}</span></span>
{/if}
