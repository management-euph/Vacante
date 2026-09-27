{*
    Booking progress bar — the first row of every provider's booking page,
    above BOTH columns (sidebar + form), so it reads as the step the whole
    page belongs to: Search (done) → Guest details (current) → Checkout →
    Confirmation. Not shown when editing a cart line (that is not a step of
    a new booking).

    Parameters:
      bs_edit_mode  (bool, default false) hide the bar in edit mode.
*}
{if !$bs_edit_mode|default:false}
<ol class="travel-steps" aria-label="{__("travel_core.booking_progress")|escape:html}">
    <li class="travel-steps__item travel-steps__item--done">
        <span class="travel-steps__badge" aria-hidden="true">&#10003;</span>
        <span class="travel-steps__label">{__("travel_core.step_search")}</span>
    </li>
    <li class="travel-steps__item travel-steps__item--active" aria-current="step">
        <span class="travel-steps__badge" aria-hidden="true">2</span>
        <span class="travel-steps__label">{__("travel_core.step_guest_details")}</span>
    </li>
    <li class="travel-steps__item travel-steps__item--upcoming">
        <span class="travel-steps__badge" aria-hidden="true">3</span>
        <span class="travel-steps__label">{__("travel_core.step_checkout")}</span>
    </li>
    <li class="travel-steps__item travel-steps__item--upcoming">
        <span class="travel-steps__badge" aria-hidden="true">4</span>
        <span class="travel-steps__label">{__("travel_core.step_confirmation")}</span>
    </li>
</ol>
{/if}
