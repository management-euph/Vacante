{*
 * Eurosite Touring — the booking page of a package (a hotel with the flight
 * or the coach), chosen on the hotel's page.
 *
 * The shared Travel Core booking page, as the hotel form and Sphinx's
 * packages have it: the three-step bar; the guests on the left; on the right
 * the summary (Services\BookingSidebarBuilder with the trip's part,
 * PackageTripSummary): the hotel, the kind of trip, the dates, rooms and
 * board, the flights or the coach, what the price includes, the price, the
 * cancellation and payment terms. A phone folds the summary into a bar at
 * the top and keeps one button in the bar at the bottom.
 *
 * Every adult's date of birth is required: the flight's or the coach's
 * passenger list needs it. With a flight, the names go to the airline: the
 * amber warning.
 *
 * The package rides ONLY as package_key: add-to-cart re-reads every price,
 * id and guest count from the server-side snapshot (PackageSnapshotStore).
 *}

{include file="addons/travel_core/components/travel_i18n.tpl"}

<div class="travel-booking-page eurosite-booking-form eurosite-package-booking">

    {include file="addons/travel_core/components/booking_steps.tpl" bs_search_url=$eurosite_back_url}

    <div class="travel-booking-layout">
        {include file="addons/travel_core/components/booking_sidebar.tpl"}

        <div class="travel-booking-col-main">

    <form action="{"eurosite_booking.package_add_to_cart"|fn_url}" method="post" id="eurosite-package-booking-form">
        <input type="hidden" name="package_key" value="{$eurosite_package_key}" />
        <input type="hidden" name="return_product_id" value="{$eurosite_return_product_id}" />
        {* Display-only, for the shared child-age guard; add-to-cart re-reads the snapshot. *}
        <input type="hidden" name="check_in" value="{$eurosite_check_in|escape:html}" />

        <div class="travel-form-section guest-names-section">
            <h3>{__("travel_core.who_is_staying")}</h3>
            {include file="addons/travel_core/components/booking_guest_hint.tpl" gh_airline=$eurosite_package_flight}

            {foreach from=$eurosite_rooms item=es_room}
                <div class="room-guest-section" data-room-num="{$es_room.num}">
                {if $eurosite_rooms|count > 1}
                    <div class="travel-room-banner room-section-header room-card" data-room-num="{$es_room.num}">
                        <span>{__("eurosite.room_n", ["[num]" => $es_room.num])}</span>
                        <span class="travel-room-banner-meta">{if $es_room.adults == 1}{__("eurosite.adults_one")}{else}{__("eurosite.adults_many", ["[count]" => $es_room.adults])}{/if}{if $es_room.children == 1} + {__("eurosite.children_one")}{elseif $es_room.children > 1} + {__("eurosite.children_many", ["[count]" => $es_room.children])}{/if}</span>
                    </div>
                {/if}
                {include file="addons/travel_core/components/booking_guest_room_body.tpl"
                    gb_room=$es_room
                    gb_room_num=$es_room.num
                    gb_room_idx=$es_room.idx
                    gb_label_prefix="travel_core"
                    gb_show_adult_dob=true
                    gb_adult_dob_required=true
                    gb_child_dob_required=true
                    gb_guard_expected_ages=true
                    gb_seq_offset=$es_room.seq_offset
                    gb_gender_options=$eurosite_gender_options
                    gb_child_gender="C"}
                </div>
            {/foreach}
        </div>

        {* The one main button, with the total. The way back leads to the
           hotel's page on this departure. *}
        {include file="addons/travel_core/components/booking_form_actions.tpl"
            fa_addon="eurosite"
            fa_back_url=$eurosite_back_url|default:""
            fa_back_label=__("travel_core.back_to_offer")
            fa_button_class="eurosite-submit-btn"}

        {include file="addons/travel_core/components/booking_conditions_modal.tpl"}
    </form>

        </div>{* /travel-booking-col-main *}
    </div>{* /travel-booking-layout *}

    {include file="addons/travel_core/components/booking_mobile_bar.tpl" mb_form_id="eurosite-package-booking-form" mb_label=fn_travel_core_booking_cta_label("eurosite")}

</div>
