{*
 * Sphinx Booking Form
 *
 * Displays the guest entry form for a verified Sphinx hotel offer.
 * Uses shared travel_core form components for DOB masking, validation,
 * and multi-room support. Styled by travel_core's booking-pages.css
 * (.travel-booking-page class contract).
 *
 * @package SphinxHolidays
 * @since 1.0.0
 *}



{if $sphinx_booking_data}

{* Client strings for the shared booking JS (inline field messages, DOB
   checks) — without it they fell back to hard-coded Romanian. *}
{include file="addons/travel_core/components/travel_i18n.tpl"}

<div class="travel-booking-page sphinx-booking-form">

    {include file="addons/travel_core/components/booking_steps.tpl" bs_edit_mode=$is_edit_mode|default:false}

    {* 2-column layout (travel_core booking-pages.css): the shared summary
       sidebar on the left, the guest form on the right. The sidebar renders
       hotel identity, dates, occupancy, room/board, price and the cancellation
       policy from $travel_booking_sidebar, so the old separate summary header,
       price box and terms box are gone from this page. *}
    <div class="travel-booking-layout">
        {include file="addons/travel_core/components/booking_sidebar.tpl"}

        <div class="travel-booking-col-main">

    {* Guest entry form *}
    <form action="{if $is_edit_mode}{"sphinx_booking.update_booking"|fn_url}{else}{"sphinx_booking.add_to_cart"|fn_url}{/if}" method="post" id="sphinx-booking-form">
        {if $is_edit_mode}
            <input type="hidden" name="booking_id" value="{$edit_booking_id}">
            <input type="hidden" name="cart_id" value="{$edit_cart_id|escape:html}">
        {/if}
        <input type="hidden" name="security_hash" value="{$security_hash}" />
        <input type="hidden" name="offer_id" value="{$sphinx_booking_data.offer_id}">
        <input type="hidden" name="hotel_id" value="{$sphinx_booking_data.hotel_id}">
        <input type="hidden" name="product_id" value="{$sphinx_booking_data.product_id}">
        <input type="hidden" name="check_in" value="{$sphinx_booking_data.check_in}">
        <input type="hidden" name="check_out" value="{$sphinx_booking_data.check_out}">
        <input type="hidden" name="nights" value="{$sphinx_booking_data.nights}">
        <input type="hidden" name="adults" value="{$sphinx_booking_data.adults}">
        <input type="hidden" name="children" value="{$sphinx_booking_data.children}">
        <input type="hidden" name="children_ages" value="{$sphinx_booking_data.children_ages}">
        {* Display-only fallbacks for add_to_cart: the verify response stays the
           primary source for room/board names, but when it omits them (shape
           drift) the names the customer SAW on this form are the next-best
           truth. Price/ids are never taken from these. *}
        <input type="hidden" name="room_name" value="{$sphinx_booking_data.room_name|escape:html}">
        <input type="hidden" name="board_name" value="{$sphinx_booking_data.board_name|escape:html}">
        <input type="hidden" name="total_price" value="{$sphinx_booking_data.total_price}">
        <input type="hidden" name="num_rooms" value="{$sphinx_booking_data.num_rooms|default:1}">
        <input type="hidden" name="rooms_data" value="{$sphinx_booking_data.rooms_data|json_encode|escape:html}">

        <div class="guest-names-section">
            <h3 class="travel-section-title"><i class="icon-user"></i> {__("travel_core.guest_details")|default:"Guest Details"}</h3>
            {include file="addons/travel_core/components/booking_guest_hint.tpl"}

            {* Guest name + DOB cards — shared travel_core component (same markup
               contract both providers post: guests[room{N}_{type}_{i}][...]). *}
            {include file="addons/travel_core/components/booking_guest_cards.tpl"
                     guest_rooms=$sphinx_booking_data.rooms_data
                     guest_num_rooms=$sphinx_booking_data.num_rooms
                     guest_label_prefix="travel_core"
                     show_adult_dob=true
                     child_dob_required=true
                     guard_expected_ages=true
                     guest_prefill=$guest_prefill|default:[]}
        </div>

        {* Contact (email/phone) is NOT collected here: CS-Cart checkout already
           collects it, and the booking submission reads it from the order. *}

        {* Submit *}
        {* Right-aligned with the guest cards, taller, more air around it —
           the same CTA row the other providers' booking pages use. *}
        <div class="travel-form-actions travel-form-actions--cta sphinx-booking-submit">
            <span></span>
            <button type="submit" class="travel-btn--primary sphinx-offer-book-btn">
                {if $is_edit_mode}{__("travel_core.save_changes")|default:"Save changes"}{else}{__("travel_core.continue_to_checkout")} &rarr;{/if}
            </button>
        </div>
        {if !$is_edit_mode}<p class="travel-cta-note">{__("travel_core.cta_note")}</p>{/if}

        {* "What are my booking conditions?" modal (shared; its link sits in
           the sidebar's cancellation & payment card). Sphinx knows its terms
           from the verified offer, so the modal body is rendered server-side. *}
        {include file="addons/travel_core/components/booking_conditions_modal.tpl"}

    </form>

        </div>{* /travel-booking-col-main *}
    </div>{* /travel-booking-layout *}

    {if $is_edit_mode}{$sphinx_cta_label = __("travel_core.save_changes")}{else}{$sphinx_cta_label = __("travel_core.continue_to_checkout")}{/if}
    {include file="addons/travel_core/components/booking_mobile_bar.tpl" mb_form_id="sphinx-booking-form" mb_label=$sphinx_cta_label}

</div>

{else}
    <div class="sphinx-no-booking-data">
        <p>{__("sphinx_holidays.booking_data_missing")|default:"Booking data not available. Please search again."}</p>
        <a href="{"index.index"|fn_url}" class="ty-btn ty-btn__secondary">{__("travel_core.search")|default:"Search"}</a>
    </div>
{/if}
