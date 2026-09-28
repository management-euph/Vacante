{*
    Deposit booking on an order line: what was paid, what is still owed, and
    — while it is open — the "Pay balance" link (travel_core BalanceService).
    The link opens the normal checkout with the balance, so any payment method
    set up in CS-Cart works (card, bank transfer, …); none is added here.

    Parameters:
      od           the order item's extra.travel_deposit {deposit, balance, balance_due}
      od_balances  $order_info.travel_balances (fn_travel_core_get_order_info)
*}
{$od_row = []}
{foreach from=$od_balances|default:[] item="od_b"}
    {if !$od_row && $od_b.due_date == $od.balance_due}{$od_row = $od_b}{/if}
{/foreach}
<div class="travel-order-deposit">
    <div class="travel-order-deposit__title">{__("travel_core.paid_with_deposit")}</div>
    <div>{__("travel_core.split_deposit")} <strong>{include file="common/price.tpl" value=$od.deposit}</strong> &middot; {__("travel_core.split_balance")} <strong>{include file="common/price.tpl" value=$od.balance}</strong> {__("travel_core.due_by", ["[date]" => fn_travel_core_store_date($od.balance_due)])}</div>
    {if $od_row.status|default:"" == "paid"}
        <div class="travel-order-deposit__state travel-order-deposit__state--paid">{__("travel_core.balance_paid", ["[order_id]" => $od_row.balance_order_id])}</div>
    {elseif $od_row.status|default:"" == "cancelled"}
        <div class="travel-order-deposit__state">{__("travel_core.balance_cancelled")}</div>
    {elseif $od_row.pay_query|default:""}
        <a class="ty-btn ty-btn__primary travel-order-deposit__pay" href="{$od_row.pay_query|fn_url}">{__("travel_core.pay_balance_btn")}</a>
        <div class="travel-order-deposit__note">{__("travel_core.pay_balance_note")}</div>
    {/if}
</div>
