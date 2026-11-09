{*
    "How would you like to pay?" in the form, just before its button, where
    the provider offers a deposit (DepositPolicy; never when editing a cart
    line): "Pay in full" or "Pay a deposit", as whole-row cards. A choice
    belongs with the guest's other answers, and a phone can then fold the
    summary away without hiding it.

    This is the page's only #travel-price-split: the summary's price card
    leaves its own out when the choice shows here (booking_sidebar.tpl).
    Novoton's re-price refills it with the same choice (data-deposit-form),
    keeping the guest's pick. Until the terms split the price there is
    nothing in it, and the section is not shown (booking-pages.css).

    Parameters:
      pc_addon  (string) the provider add-on, for the deposit policy.
      pc_form   (string) the booking form's id, which the radios post with.
*}
{$pc_tbs = $travel_booking_sidebar|default:[]}
{if $pc_addon|default:"" && $pc_form|default:"" && $pc_tbs && !$is_edit_mode|default:false && fn_travel_core_deposit_offered($pc_addon)}
    <div class="travel-form-section travel-paychoice" id="travel-price-split" data-deposit-form="{$pc_form|escape:html}">{include file="addons/travel_core/components/booking_payment_split.tpl" ps=$pc_tbs.payment_split|default:[] ps_form=$pc_form}</div>
{/if}
