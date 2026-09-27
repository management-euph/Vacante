{*
 * Novoton Booking Form - Guest Entry
 * v2.7.0-A72 - Added DOB future date validation (client + server-side)
 *}

{style src="css/addons/novoton_holidays/styles.css"}
{* Booking-form styles now come from travel_core booking-pages.css (.travel-booking-page classes); the embedded <style> block was removed. *}

{* Helper function to format room type with full name *}
{function name="format_room_type" room_id=""}
    {* Fix URL encoding: decode %2b to +, then restore any + lost to space *}
    {$room_clean = $room_id|replace:'%2b':'+'|replace:'%2B':'+'}
    {$room_clean = preg_replace('/(\d)\s+(\d)/', '$1+$2', $room_clean)}
    {if strpos($room_clean, 'DBL') !== false}
        {__("novoton_holidays.room_type_double")} ({$room_clean})
    {elseif strpos($room_clean, 'SGL') !== false}
        {__("novoton_holidays.room_type_single")} ({$room_clean})
    {elseif strpos($room_clean, 'APP') !== false}
        {__("novoton_holidays.room_type_apartment")} ({$room_clean})
    {elseif strpos($room_clean, 'STU') !== false}
        {__("novoton_holidays.room_type_studio")} ({$room_clean})
    {elseif strpos($room_clean, 'MAISONNETE') !== false || strpos($room_clean, 'MAIS') !== false}
        {__("novoton_holidays.room_type_maisonette")} ({$room_clean})
    {elseif strpos($room_clean, 'FAMILY') !== false || strpos($room_clean, 'FAM') !== false}
        {__("novoton_holidays.room_type_family")} ({$room_clean})
    {elseif strpos($room_clean, 'SUITE') !== false}
        {__("novoton_holidays.room_type_suite")} ({$room_clean})
    {elseif strpos($room_clean, 'TRP') !== false || strpos($room_clean, 'TRIPLE') !== false}
        {__("novoton_holidays.room_type_triple")} ({$room_clean})
    {else}
        {$room_clean}
    {/if}
{/function}

<div class="travel-booking-page novoton-reservation-form">
    {include file="addons/travel_core/components/booking_steps.tpl" bs_edit_mode=$is_edit_mode|default:false}
    <form action="{if $is_edit_mode}{fn_url("novoton_booking.update_booking")}{else}{fn_url("novoton_booking.add_to_cart")}{/if}" method="post" id="novoton-booking-form">
        <input type="hidden" name="security_hash" value="{$security_hash}" />
        {if $is_edit_mode}
            <input type="hidden" name="booking_id" value="{$booking_id}" />
            <input type="hidden" name="cart_id" value="{$cart_id}" />
        {/if}
        <input type="hidden" name="hotel_id" value="{$booking_data.hotel_id}" />
        <input type="hidden" name="room_id" value="{$booking_data.room_id}" />
        <input type="hidden" name="board_id" value="{$booking_data.board_id}" />
        <input type="hidden" name="check_in" value="{$booking_data.check_in}" />
        <input type="hidden" name="check_out" value="{$booking_data.check_out}" />
        <input type="hidden" name="nights" value="{$booking_data.nights}" />
        <input type="hidden" name="adults" value="{$booking_data.adults}" />
        <input type="hidden" name="children" value="{$booking_data.children}" />
        <input type="hidden" name="children_ages" value="{$booking_data.children_ages}" />
        <input type="hidden" name="total_price" value="{$booking_data.total_price}" />
        <input type="hidden" name="product_id" value="{$product_id}" />
        <input type="hidden" name="package_name" value="{$package_name|default:$booking_data.package_name|default:''}" />
        <input type="hidden" name="num_rooms" value="{$booking_data.num_rooms|default:1}" />
        {if $booking_data.rooms_data && is_array($booking_data.rooms_data)}
            <input type="hidden" name="rooms_data" value="{$booking_data.rooms_data|json_encode|escape:'html'}" />
        {elseif $booking_data.rooms_data && is_string($booking_data.rooms_data)}
            <input type="hidden" name="rooms_data" value="{$booking_data.rooms_data|escape:'html'}" />
        {else}
            <input type="hidden" name="rooms_data" value="" />
        {/if}
        {* Terms are now fetched directly from API at checkout - no need for hidden fields *}
        
        {* 2-column layout (travel_core booking-pages.css): the shared summary
           sidebar on the left, the guest forms on the right. Everything the
           sidebar shows — hotel identity, dates, occupancy, rooms, board,
           price, cancellation — is assigned by the controller as
           $travel_booking_sidebar, so this page owns no summary markup of its
           own any more. The two dead blocks it used to carry — a hotel image
           whose variable was never assigned, and a terms section whose
           variables were never assigned either — went with it. *}
        <div class="travel-booking-layout">
            {include file="addons/travel_core/components/booking_sidebar.tpl"}

            <div class="travel-booking-col-main">

            {* Guest Names Section - Multi-Room Support with Split Fields *}
            <div class="travel-form-section guest-names-section">
                <h3>{__("novoton_holidays.enter_booking_details")}</h3>
                {include file="addons/travel_core/components/booking_guest_hint.tpl"}
                
                {* Booking-wide sequential guest numbering ("3. Adult") — the
                   shared room body offsets its labels by the guests rendered
                   in earlier rooms. *}
                {$guest_seq = 0}

                {* Loop through each room *}
                {foreach from=$booking_data.rooms_data item=room key=room_idx}
                    {$room_num = $room_idx + 1}

                    {* Room wrapper with data attribute for JS targeting *}
                    <div class="room-guest-section" data-room-num="{$room_num}">
                    
                    {* Room header if multiple rooms *}
                    {if count($booking_data.rooms_data) > 1}
                    <div class="travel-room-banner room-section-header room-card" data-room-num="{$room_num}">
                        <span> {__("novoton_holidays.room_number")} {$room_num}</span>
                        <span class="travel-room-banner-meta">
                            {$room.adults} {if $room.adults == 1}{__("novoton_holidays.adult")}{else}{__("novoton_holidays.adults")}{/if}{if $room.children > 0}, {$room.children} {if $room.children == 1}{__("novoton_holidays.child")}{else}{__("novoton_holidays.children")}{/if}{/if}
                            <span class="room-price">{fn_novoton_holidays_format_price($room.price|default:0, $novoton_display_coefficient|default:1, $novoton_display_symbol|default:$novoton_display_currency|default:$smarty.const.CART_PRIMARY_CURRENCY) nofilter}</span>
                        </span>
                    </div>
                    {/if}
                    
                    {* Shared guest cards (travel_core room body) — novoton
                       passes its provider machinery: fixed adult age for the
                       pricing API, the DOB age-recheck that re-prices,
                       booking-wide sequential labels and the bed-type
                       sublabel. Name/DOB prefill comes from the guest_prefill
                       map built by edit_booking.php. *}
                    {include file="addons/travel_core/components/booking_guest_room_body.tpl"
                        gb_room=$room
                        gb_room_num=$room_num
                        gb_room_idx=$room_idx
                        gb_label_prefix="travel_core"
                        gb_prefill=$guest_prefill|default:[]
                        gb_show_adult_dob=false
                        gb_child_dob_required=true
                        gb_adult_age_value="30"
                        gb_child_dob_onblur="validateAndCheckAge"
                        gb_seq_offset=$guest_seq}
                    {$_room_guests = $room.adults|default:1}
                    {$_room_guests = $_room_guests + $room.children|default:0}
                    {$guest_seq = $guest_seq + $_room_guests}
                    </div>{* Close room-guest-section *}
                {/foreach}
            </div>
            
            {* Form Actions *}
            <div class="travel-form-actions travel-form-actions--cta">
                {* Build rooms_data JSON for URL *}
                {if $booking_data.rooms_data && is_array($booking_data.rooms_data)}
                    {$rooms_data_url = $booking_data.rooms_data|json_encode|escape:'url'}
                {elseif $booking_data.rooms_data && is_string($booking_data.rooms_data)}
                    {$rooms_data_url = $booking_data.rooms_data|escape:'url'}
                {else}
                    {$rooms_data_url = ''}
                {/if}
                <a href="{fn_url("novoton_booking.search?hotel_id=`$booking_data.hotel_id`&product_id=`$product_id`&check_in=`$booking_data.check_in`&check_out=`$booking_data.check_out`&nights=`$booking_data.nights`&adults=`$booking_data.adults`&children=`$booking_data.children`&children_ages=`$booking_data.children_ages`&rooms=`$booking_data.num_rooms|default:1`&rooms_data=`$rooms_data_url`")}" class="travel-btn-back">
                    &larr; {__("novoton_holidays.back_to_results")}
                </a>
                <button type="submit" class="travel-btn--primary" id="booking-submit-btn">
                    {if $is_edit_mode}{__("novoton_holidays.update_booking")}{else}{__("travel_core.continue_to_checkout")} &rarr;{/if}
                </button>
            </div>
            {if !$is_edit_mode}<p class="travel-cta-note">{__("travel_core.cta_note")}</p>{/if}

            {* "What are my booking conditions?" modal (shared; its link sits in
               the sidebar's cancellation & payment card). Its body is filled
               per room by booking-form.js from the same price re-verification
               that fills the cancellation card. *}
            {include file="addons/travel_core/components/booking_conditions_modal.tpl"}

            </div>{* /travel-booking-col-main *}
        </div>{* /travel-booking-layout *}
    </form>

    {if $is_edit_mode}{$nvt_cta_label = __("novoton_holidays.update_booking")}{else}{$nvt_cta_label = __("travel_core.continue_to_checkout")}{/if}
    {include file="addons/travel_core/components/booking_mobile_bar.tpl" mb_form_id="novoton-booking-form" mb_label=$nvt_cta_label}
</div>

{* A73: Include DOB validation script with price recalculation *}
{* A74e: Include external booking form validation JS *}
{script src="js/addons/travel_core/booking-form-validation.js"}

{* Client i18n for the booking form — shared travel_core partial (replaces
   the incremental window.NovotonTranslations.* block); currency/coeff passed
   for the price-recalculation display. Emits window.TravelTranslations +
   the NovotonTranslations alias the logic below reads. *}
{include file="addons/travel_core/components/travel_i18n.tpl"
         travel_i18n_currency=$novoton_display_symbol
         travel_i18n_coeff=$novoton_display_coefficient}

<script>
// Smarty-fed config for the booking-form module (js/addons/novoton_holidays/
// booking-form.js) — data + translated labels only; behavior lives in the
// module, which vitest imports directly.
// Room limits from hotel API
var roomLimits = {$booking_data.current_room_limits|default:[]|json_encode nofilter};

// Booking data for price recalculation (used by the module + React engine)
window.bookingData = {ldelim}
    checkIn: '{$booking_data.check_in|default:$smarty.now|date_format:"%Y-%m-%d"}',
    hotelId: '{$booking_data.hotel_id|default:0}',
    productId: '{$product_id|default:0}',
    currentPrice: {$booking_data.total_price|default:0},
    roomId: '{$booking_data.room_id|default:""}',
    boardId: '{$booking_data.board_id|default:""}',
    packageName: '{$booking_data.package_name|default:""|escape:"javascript"}',
    nights: {$booking_data.nights|default:7},
    adults: {$booking_data.adults|default:2},
    numRooms: {$booking_data.num_rooms|default:1},
    maxAdults: roomLimits.max_adults || 4,
    maxChildren: roomLimits.max_children || 2,
    minPax: roomLimits.min_pax || 1,
    totalCapacity: (roomLimits.rb || 2) + (roomLimits.eb || 0),
    roomsData: {$booking_data.rooms_data_json|default:"[]" nofilter},
    calendarPrices: {$calendar_prices_json|default:'{}' nofilter},
    calendarPricesCurrency: '{$calendar_prices_currency|default:$smarty.const.CART_PRIMARY_CURRENCY|escape:"javascript"}',
    showCalendarPrices: {if $show_calendar_prices == 'Y'}true{else}false{/if},
    ajaxRecalculateUrl: '{"novoton_booking.ajax_recalculate_price"|fn_url}'
{rdelim};

// Translated labels for the module's t() lookup (raw "_key" values are
// treated as missing there, so the baked-in fallbacks still guard unseeded
// stores).
window.NovotonBookingI18n = {ldelim}
    fillAllFields: '{__("novoton_holidays.fill_all_fields")|escape:"javascript"}',
    chooseOption: '{__("travel_core.choose_option")|escape:"javascript"}',
    dobValidationError: '{__("novoton_holidays.dob_validation_error")|default:"Verificati datele de nastere introduse."|escape:"javascript"}',
    priceVerifiedAtCheckout: '{__("novoton_holidays.price_verified_at_checkout")|default:"Prețul va fi verificat la finalizare"|escape:"javascript"}',
    priceMustBeVerified: '{__("novoton_holidays.price_must_be_verified")|default:"Prețul trebuie verificat înainte de a continua"|escape:"javascript"}',
    unavailableForChildAge: '{__("novoton_holidays.unavailable_for_child_age")|default:"Indisponibil"|escape:"javascript"}',
    unavailableForChildAgeSub: '{__("novoton_holidays.unavailable_for_child_age_sub")|default:"pentru vârsta copilului"|escape:"javascript"}',
    available: '{__("novoton_holidays.available")|default:"Disponibil"|escape:"javascript"}',
    priceUpdatedChildAge: '{__("novoton_holidays.price_updated_child_age")|default:"Prețul a fost actualizat în funcție de vârsta copilului"|escape:"javascript"}',
    roomChangedTitle: '{__("novoton_holidays.room_changed_title")|default:"Camera s-a modificat"|escape:"javascript"}',
    roomChangedDueToAge: '{__("novoton_holidays.room_changed_due_to_age")|default:"Camera selectata nu este disponibila pentru varsta copilului introdusa."|escape:"javascript"}',
    originalRoom: '{__("novoton_holidays.original_room")|default:"Camera selectata"|escape:"javascript"}',
    newRoom: '{__("novoton_holidays.new_room")|default:"Camera noua"|escape:"javascript"}',
    priceChange: '{__("novoton_holidays.price_change")|default:"Modificare pret"|escape:"javascript"}',
    goBackToSearch: '{__("novoton_holidays.go_back_to_search")|default:"Inapoi la cautare"|escape:"javascript"}',
    continueWithNewRoom: '{__("novoton_holidays.continue_with_new_room")|default:"Continua cu noua camera"|escape:"javascript"}',
    roomTypeDouble: '{__("novoton_holidays.room_type_double")|default:"Double Room"|escape:"javascript"}',
    roomNumber: '{__("novoton_holidays.room_number")|default:"Camera"|escape:"javascript"}',
    roomUpdated: '{__("novoton_holidays.room_updated")|default:"Camera a fost actualizata:"|escape:"javascript"}',
    cancelYouWillPay: '{__("travel_core.cancel_you_will_pay")|escape:"javascript"}',
    paymentTerms: '{__("travel_core.payment_terms")|escape:"javascript"}',
    cancellationPolicy: '{__("travel_core.cancellation_policy")|escape:"javascript"}'
{rdelim};
</script>
{script src="js/addons/novoton_holidays/booking-form.js"}
