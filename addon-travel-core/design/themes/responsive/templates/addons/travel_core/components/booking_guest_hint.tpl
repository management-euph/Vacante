{*
    One line above the guest cards: the names go to the supplier's rooming
    list and must match each guest's travel document. The information icon
    is inline SVG (currentColor), not an emoji that renders differently on
    every platform.

    With gh_airline (a trip with a flight: package, circuit) the line is an
    amber warning: the names, gender and date of birth go to the airline,
    and a mistake can only be corrected for a fee.

    Under it, for families: one tick gives every guest Adult 1's last name,
    kept in step while ticked; unticking takes the copies back
    (booking-conditions.js, which shows it only where the form has two
    guests or more; no name, so never submitted).
*}
{if $gh_airline|default:false}
<p class="travel-guest-hint travel-guest-hint--airline"><svg class="travel-guest-hint__icon" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg> <span><strong>{__("travel_core.guest_names_hint")}</strong> {__("travel_core.guest_names_airline")}</span></p>
{else}
<p class="travel-guest-hint"><svg class="travel-guest-hint__icon" viewBox="0 0 20 20" width="18" height="18" aria-hidden="true" focusable="false"><circle cx="10" cy="10" r="9" fill="none" stroke="currentColor" stroke-width="1.8"/><circle cx="10" cy="6" r="1.2" fill="currentColor"/><rect x="9.1" y="8.5" width="1.8" height="6.5" rx=".9" fill="currentColor"/></svg> <span>{__("travel_core.guest_names_hint")}</span></p>
{/if}
<label class="travel-same-surname" data-travel-same-surname hidden><input type="checkbox"> {__("travel_core.same_surname")}</label>
