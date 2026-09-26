{*
 * Eurosite Touring — guest booking form ("Complete Your Booking").
 *
 * Same page as sphinx and novoton: the shared travel_core 2-column layout
 * (booking-pages.css .travel-booking-page contract) — summary sidebar on the
 * left, shared guest cards on the right, the shared "What are my booking
 * conditions?" modal under the button. Only the data is eurosite's:
 * $travel_booking_sidebar comes from Services\BookingSidebarBuilder (offer
 * snapshot + hotel row + getItemFees).
 *
 * Eurosite pax specifics ride on the shared guest cards as opt-in params:
 * a [gender] select per adult (AddBookingRequest TGender B/F) and a hidden
 * C for children. Contact (email/phone) is collected by CS-Cart checkout, as
 * for the other providers.
 *
 * The offer itself rides ONLY as offer_key — add_to_cart re-reads the
 * server-side snapshot for every price/occupancy fact.
 *}

{include file="addons/travel_core/components/travel_i18n.tpl"}

<div class="travel-booking-page eurosite-booking-form">

    <div class="travel-booking-layout">
        {include file="addons/travel_core/components/booking_sidebar.tpl"}

        <div class="travel-booking-col-main">

    <form action="{"eurosite_booking.add_to_cart"|fn_url}" method="post" id="eurosite-booking-form">
        <input type="hidden" name="offer_key" value="{$eurosite_offer_key}" />

        <div class="travel-form-section guest-names-section">
            <h3>{__("eurosite.complete_booking")}</h3>

            {include file="addons/travel_core/components/booking_guest_room_body.tpl"
                gb_room=$eurosite_room
                gb_room_num=1
                gb_room_idx=0
                gb_label_prefix="travel_core"
                gb_show_adult_dob=true
                gb_child_dob_required=true
                gb_guard_expected_ages=true
                gb_seq_offset=0
                gb_gender_options=$eurosite_gender_options
                gb_child_gender="C"}
        </div>

        <div class="travel-form-actions">
            {if $eurosite_back_url}
                <a href="{$eurosite_back_url|fn_url}" class="travel-btn-back">&larr; {__("eurosite.back_to_results")}</a>
            {/if}
            <button type="submit" class="travel-btn--primary eurosite-submit-btn">
                {__("eurosite.add_to_cart")}
            </button>
        </div>

        {include file="addons/travel_core/components/booking_conditions_modal.tpl"}
    </form>

        </div>{* /travel-booking-col-main *}
    </div>{* /travel-booking-layout *}

</div>
