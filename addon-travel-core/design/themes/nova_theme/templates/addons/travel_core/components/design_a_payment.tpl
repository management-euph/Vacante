{*
    What a Design A rate costs up front (RoomResultsBuilder Payment):
    nothing before the hotel confirms (on request), a deposit and the rest
    by a date (DepositPlan), or the full price on booking.
    Params: payment
*}
{if $payment.on_request}{__("travel_core.pdpa_r_pay_on_request")}{elseif $payment.deposit_percent > 0}{__("travel_core.pdpa_r_pay_deposit", ["[percent]" => $payment.deposit_percent, "[date]" => $payment.balance_due|escape:html])}{else}{__("travel_core.pdpa_r_pay_full")}{/if}
