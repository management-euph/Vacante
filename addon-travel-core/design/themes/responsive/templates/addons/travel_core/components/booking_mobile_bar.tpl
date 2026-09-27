{*
    Mobile bottom bar (≤900px, CSS): the total and the main button stay on
    screen while the guest scrolls past the summary cards to the form.

    The total is a second `.price-total`, so every script that re-prices
    (novoton booking-form.js, travel_core booking-form-validation.js) updates
    it with the sidebar's — it deliberately has NO id. The button does not
    submit on its own: booking-conditions.js clicks the form's real submit
    button, keeping native validation, the inline messages and novoton's
    disabled state.

    Parameters:
      mb_form_id  (string, required) id of the booking <form>.
      mb_label    (string, required) button label (same as the form's CTA).
*}
{$tbs = $travel_booking_sidebar}
{if $tbs && $mb_form_id}
<div class="travel-mobile-bar">
    <div class="travel-mobile-bar__total">
        <span>{__("travel_core.total_price")|default:"Total"}</span>
        <strong class="price-total">{$tbs.total nofilter}</strong>
    </div>
    <button type="button" class="travel-btn--primary" data-travel-submit-proxy="{$mb_form_id|escape:html}">{$mb_label}</button>
</div>
{/if}
