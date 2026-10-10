{*
    Order summary when a line is paid with a deposit: the FULL order total
    first, then what is charged now and the balance (DepositCartLine::totals).

    The store's own total line keeps the amount CS-Cart hands the payment
    method — the deposit, the only amount charged now — so deposit-totals.js
    relabels it "Deposit — paid now" and adds the amount to the place-order
    button. Without the script the block below still says it all.

    On a placed order (dt_order given) the deposit was paid, and the balance
    row shows only what is still open — not a balance already paid, nor one
    of a cancelled booking (each booking card says which); the pay button is
    on the booking's card, so no checkout note and no relabelling script.

    Parameters:
      dt_products  $cart.products / $order_info.products
      dt_charged   $cart.total / $order_info.total (what is charged now)
      dt_order     the customer's order page: fn_travel_core_order_open_balance($order_info)
*}
{$dt = fn_travel_core_deposit_totals($dt_products|default:[], $dt_charged|default:0)}
{$dt_placed = $dt_order|default:[]}
{if $dt}
    {capture assign="dt_now_text"}{include file="common/price.tpl" value=$dt.now}{/capture}
    <div class="travel-deposit-totals"{if !$dt_placed} data-travel-deposit-totals="1" data-now-label="{__("travel_core.deposit_paid_now")|escape:html}" data-pay-label="{__("travel_core.deposit_pay_now_btn", ["[amount]" => $dt_now_text|strip_tags|trim])|escape:html}"{/if}>
        <div class="travel-deposit-totals__row travel-deposit-totals__row--total">
            <span>{__("travel_core.deposit_order_total")}</span>
            <span>{include file="common/price.tpl" value=$dt.total}</span>
        </div>
        <div class="travel-deposit-totals__row travel-deposit-totals__row--now">
            <span>{if $dt_placed}{__("travel_core.obc_deposit_paid")}{else}{__("travel_core.deposit_paid_now")}{/if}</span>
            <span>{$dt_now_text nofilter}</span>
        </div>
        {if !$dt_placed}
            <div class="travel-deposit-totals__row">
                <span>{__("travel_core.deposit_balance_by", ["[date]" => fn_travel_core_store_date($dt.balance_due)])}</span>
                <span>{include file="common/price.tpl" value=$dt.balance}</span>
            </div>
            <div class="travel-deposit-totals__note">{__("travel_core.deposit_cart_note")}</div>
        {elseif $dt_placed.balance > 0}
            <div class="travel-deposit-totals__row">
                <span>{__("travel_core.deposit_balance_by", ["[date]" => fn_travel_core_store_date($dt_placed.balance_due)])}</span>
                <span>{include file="common/price.tpl" value=$dt_placed.balance}</span>
            </div>
        {/if}
    </div>
    {if !$dt_placed}{script src="js/addons/travel_core/deposit-totals.js"}{/if}
{/if}
