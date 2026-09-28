{*
    Deposit booking on an order line: what was paid, what is still owed, and
    — while it is open — the "Pay balance" link (travel_core BalanceService).
    The link opens the normal checkout with the balance, so any payment method
    works: Netopia card, bank transfer (its CS-Cart instructions), …

    Parameters:
      od           the order item's extra.travel_deposit {deposit, balance, balance_due}
      od_balances  $order_info.travel_balances (fn_travel_core_get_order_info)
*}
{$od_row = []}
{foreach from=$od_balances|default:[] item="od_b"}
    {if !$od_row && $od_b.due_date == $od.balance_due}{$od_row = $od_b}{/if}
{/foreach}
<div class="travel-order-deposit" style="margin-top:8px;padding:8px 10px;border:1px dashed #c9d6ea;border-radius:6px;">
    <strong>{__("travel_core.paid_with_deposit")}</strong><br>
    {__("travel_core.split_deposit")} <strong>{include file="common/price.tpl" value=$od.deposit}</strong> &middot; {__("travel_core.split_balance")} <strong>{include file="common/price.tpl" value=$od.balance}</strong> {__("travel_core.due_by", ["[date]" => fn_travel_core_store_date($od.balance_due)])}<br>
    {if $od_row.status|default:"" == "paid"}
        <span style="color:#15803d;">{__("travel_core.balance_paid", ["[order_id]" => $od_row.balance_order_id])}</span>
    {elseif $od_row.status|default:"" == "cancelled"}
        {__("travel_core.balance_cancelled")}
    {elseif $od_row.pay_query|default:""}
        <span style="color:#b45309;">{__("travel_core.balance_open")}</span>{if $od_row.overdue_notified_at} &middot; <strong style="color:#b91c1c;">{__("travel_core.balance_overdue")}</strong>{/if}<br>
        {__("travel_core.balance_pay_link")}: <input type="text" readonly="readonly" value="{$od_row.pay_query|fn_url:"C":"http"}" style="width:100%;max-width:520px;" />
    {/if}
</div>
