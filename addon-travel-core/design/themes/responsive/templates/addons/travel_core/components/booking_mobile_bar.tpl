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
      mb_label    (string, required) button label (same as the form's CTA);
                  a new booking's (not $is_edit_mode) gets the CTA's lock.
*}
{$tbs = $travel_booking_sidebar}
{if $tbs && $mb_form_id}
<div class="travel-mobile-bar">
    <div class="travel-mobile-bar__total">
        <span>{__("travel_core.total_price")|default:"Total"}</span>
        <strong class="price-total">{$tbs.total nofilter}</strong>
    </div>
    <button type="button" class="travel-btn--primary" data-travel-submit-proxy="{$mb_form_id|escape:html}">{if !$is_edit_mode|default:false}<svg class="travel-btn__lock" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"><rect x="5" y="11" width="14" height="10" rx="2" fill="none" stroke="currentColor" stroke-width="2.2"/><path d="M8 11V7.5a4 4 0 0 1 8 0V11" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/></svg> {/if}{$mb_label}</button>
</div>
{/if}
