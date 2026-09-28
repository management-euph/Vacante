{*
    Deposit / balance line under the booking total, when the supplier's
    payment terms split the price (TermsTimelineFactory::split()): e.g.
    "Deposit $108 · Balance $252 by 10/20/2026". Information only — checkout
    still takes the full total.

    Parameters:
      ps  array{deposit, balance, balance_due} (already formatted); empty → nothing.
*}
{if $ps && $ps.deposit}
    <div class="travel-price-split">
        <span class="travel-price-split__item">{__("travel_core.split_deposit")} <strong>{$ps.deposit nofilter}</strong></span>
        <span class="travel-price-split__item">{__("travel_core.split_balance")} <strong>{$ps.balance nofilter}</strong> {__("travel_core.due_by", ["[date]" => $ps.balance_due])}</span>
    </div>
{/if}
