{*
 * Shared per-line order booking-detail block.
 *
 * Renders hotel / dates / room / board / guests / guest names for a travel
 * booking order item, from its `extra` array. Dates and guest names are the
 * fields fn_travel_core_get_order_info() normalizes onto every travel-booking
 * product (check_in_formatted / check_out_formatted, and guests_data[] with
 * display_name / type / age / room). Area-agnostic: the caller wraps it for the
 * storefront (a <div>), admin (a full-width <tr><td>) or mail (a table cell).
 * STATELESS by contract — cross-item state (e.g. novoton's once-per-package
 * terms de-dup in order emails) lives in the calling wrapper.
 *
 * Core parameters:
 *   booking_extra        (array,  required) the order item's extra.
 *   booking_view_id      (int,    default 0) unified travel_bookings surrogate id;
 *                                            when > 0 an admin "View Booking #N"
 *                                            link renders. Pass 0 on the storefront.
 *   booking_label_prefix (string, default 'travel_core') langvar namespace.
 *
 * Optional sections (ALL default-off so existing sphinx output is unchanged):
 *   booking_location_style ('' | 'paren' | 'inline') hotel_city/hotel_region/
 *                          hotel_country — 'paren' = " (City)" after the hotel,
 *                          'inline' = ", City, Region, Country".
 *   show_package         (bool) package_name line.
 *   show_rooms_breakdown (bool) when num_rooms > 1: per-room line with room,
 *                          board, occupancy and price (rooms_data[] as enriched
 *                          by the provider's get_order_info hook).
 *   show_terms           (bool) payment terms with amounts (fallback formatted)
 *                          + cancellation policy, nl2br'd.
 *   guest_grouping       ('flat' | 'split') 'split' renders "Adults: …" /
 *                          "Children: … (N)" lines instead of one list.
 *   show_holder_badge    (bool) mark the holder in the flat list.
 *   booking_ref_line     (string) pre-composed provider reference line,
 *                          e.g. "NT 12345 (Good)" — provider-specific wording
 *                          belongs to the wrapper.
 *   booking_fallback_url (string) link rendered when booking_view_id is 0,
 *                          e.g. the provider's own admin view.
 *   booking_prefer_short (bool) prefer check_in_short/check_out_short (dotted
 *                          DD.MM.YYYY) over the store-format check_in_formatted
 *                          — novoton admin pages render dotted dates.
 *
 * @package TravelCore
 *}
{$_be = $booking_extra}
{$_bp = $booking_label_prefix|default:'travel_core'}
{$_bvid = $booking_view_id|default:0}
{$_loc_style = $booking_location_style|default:''}
{$_grouping = $guest_grouping|default:'flat'}
{if $booking_prefer_short|default:false}
    {$_ci = $_be.check_in_short|default:$_be.check_in_formatted|default:$_be.check_in|default:''}
    {$_co = $_be.check_out_short|default:$_be.check_out_formatted|default:$_be.check_out|default:''}
{else}
    {$_ci = $_be.check_in_formatted|default:$_be.check_in_short|default:$_be.check_in|default:''}
    {$_co = $_be.check_out_formatted|default:$_be.check_out_short|default:$_be.check_out|default:''}
{/if}
{if $_be}
    {if $_be.hotel_name}
        <strong>{__("`$_bp`.hotel")}:</strong> {$_be.hotel_name|escape:'html'}{if $_loc_style === 'paren' && $_be.hotel_city} ({$_be.hotel_city|escape:'html'}){elseif $_loc_style === 'inline'}{if $_be.hotel_city}, {$_be.hotel_city|escape:'html'}{/if}{if $_be.hotel_region}, {$_be.hotel_region|escape:'html'}{/if}{if $_be.hotel_country}, {$_be.hotel_country|escape:'html'}{/if}{/if}<br>
    {/if}

    <strong>{__("`$_bp`.check_in")}:</strong> {$_ci} |
    <strong>{__("`$_bp`.check_out")}:</strong> {$_co} |
    <strong>{__("`$_bp`.nights")}:</strong> {$_be.nights|default:0}<br>

    {if $show_package|default:false && $_be.package_name}
        <strong>{__("`$_bp`.package")|default:"Package"}:</strong> {$_be.package_name|escape:'html'}<br>
    {/if}

    {if $show_rooms_breakdown|default:false && $_be.num_rooms > 1 && $_be.rooms_data}
        <strong>{__("`$_bp`.rooms")|default:"Rooms"} ({$_be.num_rooms}):</strong><br>
        {foreach from=$_be.rooms_data item=_obd_room name=obd_rooms}
            &nbsp;&nbsp;- <strong>{__("`$_bp`.room")} {$smarty.foreach.obd_rooms.iteration}:</strong>
            {$_obd_room.room_name_formatted|default:$_obd_room.room_name|default:$_obd_room.room_type_display|default:$_obd_room.room_id|default:'Room'|escape:'html'}
            | {$_obd_room.board_name_formatted|default:$_obd_room.board_name|default:$_obd_room.board_id|default:''|escape:'html'}
            | {$_obd_room.adults|default:0} {__("`$_bp`.adults")}{if $_obd_room.children}, {$_obd_room.children} {__("`$_bp`.children")}{if $_obd_room.children_ages_str} ({$_obd_room.children_ages_str}){/if}{/if}
            | {$_obd_room.price|default:0} {$smarty.const.CART_PRIMARY_CURRENCY}<br>
        {/foreach}
    {else}
        {$_room = $_be.room_type_display|default:$_be.room_name|default:''}
        {if $_room}<strong>{__("`$_bp`.room")}:</strong> {$_room|escape:'html'}<br>{/if}
        {if $_be.board_name}<strong>{__("`$_bp`.board")}:</strong> {$_be.board_name|escape:'html'}<br>{/if}
    {/if}

    <strong>{__("`$_bp`.guests")}:</strong>
    {$_be.adults|default:0} {__("`$_bp`.adults")}{if $_be.children}, {$_be.children} {__("`$_bp`.children")}{if $_be.children_ages} ({$_be.children_ages}){/if}{/if}<br>

    {* Guest names — guests_data is pre-formatted into an array by
       fn_travel_core_get_order_info (display_name / type / age / room). *}
    {if $_be.guests_data && is_array($_be.guests_data)}
        {if $_grouping === 'split'}
            {$_adult_names = []}
            {$_child_names = []}
            {foreach from=$_be.guests_data item=_g}
                {$_gname = $_g.display_name|default:$_g.name|default:''}
                {if $_g.type == 'child'}
                    {$_gage = $_g.age|default:0}
                    {$_child_names[] = "`$_gname` (`$_gage`)"}
                {else}
                    {$_adult_names[] = $_gname}
                {/if}
            {/foreach}
            {if $_adult_names}<strong>{__("`$_bp`.adults")|capitalize}:</strong> {", "|implode:$_adult_names|escape:'html'}<br>{/if}
            {if $_child_names}<strong>{__("`$_bp`.children")|capitalize}:</strong> {", "|implode:$_child_names|escape:'html'}<br>{/if}
        {else}
            <strong>{__("`$_bp`.guest_names")}:</strong><br>
            {foreach from=$_be.guests_data item=_g}
                &nbsp;&nbsp;{$_g.display_name|default:$_g.name|default:''|escape:'html'}{if $_g.type == 'child'} ({$_g.age|default:0} {__("`$_bp`.years_old")}){/if}{if $show_holder_badge|default:false && $_g.is_holder} ({__("`$_bp`.main_guest")}){/if}{if $_g.room} &mdash; {__("`$_bp`.room")} {$_g.room}{/if}<br>
            {/foreach}
        {/if}
    {elseif $_be.holder_name}
        <strong>{__("`$_bp`.main_guest")}:</strong> {$_be.holder_name|escape:'html'}<br>
    {/if}

    {if $show_terms|default:false}
        {* Two provider shapes, one block. Novoton formats its XML terms into
           newline-joined STRINGS at add-to-cart (terms_of_payment_with_amounts
           resolves percentages into real sums, so it wins when present);
           sphinx stores ARRAYS of already-formatted lines (payment_terms /
           cancellation_fees). Reading only novoton's keys is why sphinx order
           emails silently showed no terms at all. *}
        {$_obd_pay = $_be.terms_of_payment_with_amounts|default:$_be.terms_of_payment_formatted|default:''}
        {$_obd_cancel = $_be.terms_of_cancellation_formatted|default:''}
        {$_obd_pay_lines = $_be.payment_terms|default:[]}
        {$_obd_cancel_lines = $_be.cancellation_fees|default:[]}

        {if $_obd_pay || $_obd_pay_lines}
            <strong>{__("`$_bp`.payment_terms")|default:"Terms of Payment"}:</strong><br>
            {if $_obd_pay}
                &nbsp;&nbsp;{$_obd_pay|escape:'html'|nl2br nofilter}<br>
            {else}
                {foreach from=$_obd_pay_lines item="_obd_line"}
                    &nbsp;&nbsp;{$_obd_line|escape:'html'}<br>
                {/foreach}
            {/if}
        {/if}
        {if $_obd_cancel || $_obd_cancel_lines}
            <strong>{__("`$_bp`.cancellation_policy")|default:"Cancellation Policy"}:</strong><br>
            {if $_obd_cancel}
                &nbsp;&nbsp;{$_obd_cancel|escape:'html'|nl2br nofilter}<br>
            {else}
                {foreach from=$_obd_cancel_lines item="_obd_line"}
                    &nbsp;&nbsp;{$_obd_line|escape:'html'}<br>
                {/foreach}
            {/if}
        {/if}
    {/if}

    {if $booking_ref_line|default:''}
        {$booking_ref_line|escape:'html'}<br>
    {/if}

    {if $_bvid > 0}
        <div style="margin-top:6px;">
            <a href="{"travel_bookings.view?booking_id=`$_bvid`"|fn_url}">{__("`$_bp`.view_booking")} #{$_bvid}</a>
        </div>
    {elseif $booking_fallback_url|default:''}
        <div style="margin-top:6px;">
            <a href="{$booking_fallback_url|fn_url}">{__("`$_bp`.view_booking")}</a>
        </div>
    {/if}

    {* Paid with a deposit: the balance and its pay link *}
    {if $booking_extra.travel_deposit.deposit|default:0 > 0}
        {include file="addons/travel_core/components/order_deposit_details.tpl" od=$booking_extra.travel_deposit od_balances=$order_info.travel_balances|default:[]}
    {/if}
{/if}
