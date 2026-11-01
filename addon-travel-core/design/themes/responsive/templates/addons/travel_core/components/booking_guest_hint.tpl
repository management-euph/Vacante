{*
    One line above the guest cards: the names go to the supplier's rooming
    list and must match each guest's travel document. The information icon
    is inline SVG (currentColor), not an emoji that renders differently on
    every platform.

    Under it, for families: one tick gives every guest Adult 1's last name,
    kept in step while ticked (booking-conditions.js, which shows it only
    where the form has two guests or more; no name, so never submitted).
*}
<p class="travel-guest-hint"><svg class="travel-guest-hint__icon" viewBox="0 0 20 20" width="18" height="18" aria-hidden="true" focusable="false"><circle cx="10" cy="10" r="9" fill="none" stroke="currentColor" stroke-width="1.8"/><circle cx="10" cy="6" r="1.2" fill="currentColor"/><rect x="9.1" y="8.5" width="1.8" height="6.5" rx=".9" fill="currentColor"/></svg> <span>{__("travel_core.guest_names_hint")}</span></p>
<label class="travel-same-surname" data-travel-same-surname hidden><input type="checkbox"> {__("travel_core.same_surname")}</label>
