{*
    Deposit / balance under the booking total, when the supplier's payment
    terms split the price (TermsTimelineFactory::split()).

    With ps_form (the booking form's id, only where a deposit is offered —
    DepositPolicy) it is the guest's CHOICE: "Pay in full" (default) or "Pay
    a deposit". The radios post pay_mode with that form (form= attribute, as
    the sidebar sits outside it on some pages); add_to_cart works the amount
    out again from the live terms, so only the choice is sent. Checkout then
    charges the deposit, and the balance is paid later from a link (with
    the store's own CS-Cart payment methods).

    Without ps_form it is information only: "Deposit … · Balance … by …".

    Parameters:
      ps       array{full, deposit, balance, balance_due} (already formatted); empty → nothing.
      ps_form  (string) id of the form the choice posts with; '' → information only.
*}
{if $ps && $ps.deposit}
    {if $ps_form|default:""}
        <fieldset class="travel-paymode">
            <legend class="travel-sr-only">{__("travel_core.pay_mode_legend")}</legend>
            <label class="travel-paymode__opt">
                <input type="radio" name="pay_mode" value="full" form="{$ps_form|escape:html}" checked="checked" />
                <span class="travel-paymode__text">
                    <span class="travel-paymode__name">{__("travel_core.pay_in_full")}</span>
                    <span class="travel-paymode__hint">{__("travel_core.pay_in_full_hint")}</span>
                </span>
                <span class="travel-paymode__amt">{$ps.full nofilter}</span>
            </label>
            <label class="travel-paymode__opt">
                <input type="radio" name="pay_mode" value="deposit" form="{$ps_form|escape:html}" />
                <span class="travel-paymode__text">
                    <span class="travel-paymode__name">{__("travel_core.pay_deposit")}</span>
                    <span class="travel-paymode__hint">{capture assign="ps_balance"}<strong>{$ps.balance nofilter}</strong>{/capture}{__("travel_core.pay_deposit_hint", ["[balance]" => $ps_balance, "[date]" => $ps.balance_due])}</span>
                </span>
                <span class="travel-paymode__amt">{$ps.deposit nofilter}</span>
            </label>
        </fieldset>
    {else}
        <div class="travel-price-split">
            <span class="travel-price-split__item">{__("travel_core.split_deposit")} <strong>{$ps.deposit nofilter}</strong></span>
            <span class="travel-price-split__item">{__("travel_core.split_balance")} <strong>{$ps.balance nofilter}</strong> {__("travel_core.due_by", ["[date]" => $ps.balance_due])}</span>
        </div>
    {/if}
{/if}
