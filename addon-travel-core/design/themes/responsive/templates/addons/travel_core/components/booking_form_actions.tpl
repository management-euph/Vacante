{*
    The booking form's actions row, one for every provider (novoton, sphinx,
    eurosite).

    A new booking: the way back to the results, when the provider has one,
    and the main button, which leads to payment, with a lock beside its
    label and the note that nothing is paid yet under it.

    Editing a cart line's guests: "Back to booking details" and "Cancel"
    return, unsaved, to where the booking is shown: the cart, or the
    checkout when the add-on skips the cart (CartSkipPolicy, the very place
    a saved edit lands). The main button saves the changes.

    Parameters:
      fa_addon         (string, required) the provider add-on: novoton_holidays,
                       sphinx_holidays or eurosite.
      fa_edit_mode     (bool) editing a cart line.
      fa_back_url      (string, dispatch) a new booking's way back to its
                       results; none, no link.
      fa_back_label    (string) its label.
      fa_button_class  (string) the provider's own classes on the submit button.
      fa_button_id     (string) its id, where the provider's script needs one.
*}
{if $fa_edit_mode|default:false}
    {$fa_return = fn_travel_core_after_add_to_cart_url($fa_addon)}
    <div class="travel-form-actions travel-form-actions--cta travel-form-actions--edit">
        <a href="{$fa_return|fn_url}" class="travel-btn-back">&larr; {__("travel_core.edit_back_to_booking")}</a>
        <span class="travel-form-actions__buttons">
            <a href="{$fa_return|fn_url}" class="travel-btn--secondary">{__("travel_core.edit_cancel")}</a>
            <button type="submit" class="travel-btn--primary{if $fa_button_class|default:""} {$fa_button_class|escape:html}{/if}"{if $fa_button_id|default:""} id="{$fa_button_id|escape:html}"{/if}>{__("travel_core.save_changes")}</button>
        </span>
    </div>
{else}
    <div class="travel-form-actions travel-form-actions--cta">
        {if $fa_back_url|default:""}
            <a href="{$fa_back_url|fn_url}" class="travel-btn-back">&larr; {$fa_back_label}</a>
        {else}
            <span></span>
        {/if}
        <button type="submit" class="travel-btn--primary{if $fa_button_class|default:""} {$fa_button_class|escape:html}{/if}"{if $fa_button_id|default:""} id="{$fa_button_id|escape:html}"{/if}>
            <svg class="travel-btn__lock" viewBox="0 0 24 24" width="17" height="17" aria-hidden="true" focusable="false"><rect x="5" y="11" width="14" height="10" rx="2" fill="none" stroke="currentColor" stroke-width="2.2"/><path d="M8 11V7.5a4 4 0 0 1 8 0V11" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/></svg>
            {fn_travel_core_booking_cta_label($fa_addon)} &rarr;
        </button>
    </div>
    <p class="travel-cta-note">{__("travel_core.cta_note")}</p>
{/if}
