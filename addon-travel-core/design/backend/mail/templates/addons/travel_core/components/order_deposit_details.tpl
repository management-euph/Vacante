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
<div style="margin-top:8px;padding:8px 10px;border:1px dashed #c9d6ea;border-radius:6px;">
    <strong>{__("travel_core.paid_with_deposit")}</strong><br>
    {__("travel_core.split_deposit")} <strong>{include file="common/price.tpl" value=$od.deposit}</strong> &middot; {__("travel_core.split_balance")} <strong>{include file="common/price.tpl" value=$od.balance}</strong> {__("travel_core.due_by", ["[date]" => fn_travel_core_store_date($od.balance_due)])}<br>
    {if $od_row.status|default:"" == "paid"}
        {__("travel_core.balance_paid", ["[order_id]" => $od_row.balance_order_id])}
    {elseif $od_row.pay_query|default:""}
        <a href="{$od_row.pay_query|fn_url:"C":"http"}" style="display:inline-block;margin-top:6px;padding:8px 14px;background:#0b5ed7;color:#fff;border-radius:6px;text-decoration:none;font-weight:bold;">{__("travel_core.pay_balance_btn")}</a><br>
        <span style="font-size:12px;color:#64748b;">{__("travel_core.pay_balance_note")}</span>
    {/if}
</div>
