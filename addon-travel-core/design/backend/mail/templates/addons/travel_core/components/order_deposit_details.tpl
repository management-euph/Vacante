{*
    Deposit booking on an order line: what was paid, what is still owed, and
    — while it is open — the "Pay balance" link (travel_core BalanceService).
    The link opens the normal checkout with the balance, so any payment method
    set up in CS-Cart works (card, bank transfer, …); none is added here.

    Parameters:
      od           the order item's extra.travel_deposit {full, deposit, balance, balance_due}
      od_balances  $order_info.travel_balances (fn_travel_core_get_order_info)
*}
{$od_row = []}
{foreach from=$od_balances|default:[] item="od_b"}
    {if !$od_row && $od_b.due_date == $od.balance_due}{$od_row = $od_b}{/if}
{/foreach}
{* Once per email: the FULL order total, then the deposit charged and the
   balance (fn_travel_core_get_order_info → travel_deposit_totals). *}
{$od_totals = $order_info.travel_deposit_totals|default:[]}
{if $od_totals && !$smarty.capture.travel_deposit_mail_totals}
    {capture name="travel_deposit_mail_totals"}1{/capture}
    <table cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:12px 0;border:1.5px solid #0b5ed7;border-radius:8px;background:#f0f6ff;">
        <tr><td style="padding:10px 12px 4px;font-size:16px;font-weight:bold;">{__("travel_core.deposit_order_total")}</td><td align="right" style="padding:10px 12px 4px;font-size:16px;font-weight:bold;">{$od_totals.total_formatted}</td></tr>
        <tr><td style="padding:2px 12px;font-weight:bold;color:#0b3d91;">{__("travel_core.deposit_paid_now")}</td><td align="right" style="padding:2px 12px;font-weight:bold;color:#0b3d91;">{$od_totals.now_formatted}</td></tr>
        <tr><td style="padding:2px 12px 10px;">{__("travel_core.deposit_balance_by", ["[date]" => $od_totals.balance_due_formatted])}</td><td align="right" style="padding:2px 12px 10px;">{$od_totals.balance_formatted}</td></tr>
    </table>
{/if}
<div style="margin-top:8px;padding:8px 10px;border:1px dashed #c9d6ea;border-radius:6px;">
    <strong>{__("travel_core.paid_with_deposit")}</strong><br>
    {__("travel_core.split_total")} <strong>{include file="common/price.tpl" value=$od.full}</strong> &middot; {__("travel_core.split_deposit")} <strong>{include file="common/price.tpl" value=$od.deposit}</strong> &middot; {__("travel_core.split_balance")} <strong>{include file="common/price.tpl" value=$od.balance}</strong> {__("travel_core.due_by", ["[date]" => fn_travel_core_store_date($od.balance_due)])}<br>
    {if $od_row.status|default:"" == "paid"}
        {__("travel_core.balance_paid", ["[order_id]" => $od_row.balance_order_id])}
    {elseif $od_row.pay_query|default:""}
        <a href="{$od_row.pay_query|fn_url:"C":"http"}" style="display:inline-block;margin-top:6px;padding:8px 14px;background:#0b5ed7;color:#fff;border-radius:6px;text-decoration:none;font-weight:bold;">{__("travel_core.pay_balance_btn")}</a><br>
        <span style="font-size:12px;color:#64748b;">{__("travel_core.pay_balance_note")}</span>
    {/if}
</div>
