{*
    Guest list of the cart / checkout booking card (cart_booking_details.tpl):
    one room's guests, or every guest of a one-room booking. The guests
    arrive prepared by CartBookingCardFactory::guests() — display name, lead
    guest, child with age — so nothing is decoded or derived here.

    Parameters:
      cbr_guests  list of {name, is_holder, is_child, age, room}
*}
{if $cbr_guests}
    <ol class="travel-ccard-guestlist">
        {foreach from=$cbr_guests item="cbr_guest" name="cbr_guests"}
            <li class="travel-ccard-guest">
                <span class="travel-ccard-guest__num" aria-hidden="true">{$smarty.foreach.cbr_guests.iteration}</span>
                <span class="travel-ccard-guest__name">{if $cbr_guest.name}{$cbr_guest.name|escape:html}{else}{__("travel_core.guest_n", ["[n]" => $smarty.foreach.cbr_guests.iteration])}{/if}</span>
                {if $cbr_guest.is_holder}<span class="travel-ccard-badge">{__("travel_core.lead_guest")}</span>{/if}
                <span class="travel-ccard-guest__type">{if $cbr_guest.is_child}{__("travel_core.child")}{if $cbr_guest.age > 0}, {$cbr_guest.age} {__("travel_core.years_old")}{/if}{else}{__("travel_core.adult")}{/if}</span>
            </li>
        {/foreach}
    </ol>
{/if}
