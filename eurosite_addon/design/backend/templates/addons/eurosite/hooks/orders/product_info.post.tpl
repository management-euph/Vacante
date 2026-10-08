{* Eurosite — admin order page, per-line booking block (orders:product_info:
   inside the product cell, under the SKU, where novoton and sphinx render
   theirs). The SHARED travel_core partial draws it; fn_eurosite_get_order_info
   adds what a eurosite line never stored (meal plan, children, every room,
   terms, the reference line and the View Booking id).

   Gated on eurosite_booking_id: eurosite lines carry no provider flag, and
   that key is all the orders placed so far have. The link uses ONLY
   travel_surrogate_id — extra.booking_id and extra.eurosite_booking_id are
   eurosite_bookings ids, another id-space.

   No rooms breakdown: eurosite rooms have no per-room meal or price, so the
   partial would print an empty column and "0" per room; the Room line names
   every room instead. Core Smarty modifiers only: a compile error here aborts
   the admin {capture name="mainbox"}. *}
{if !empty($oi.extra.eurosite_booking_id)}
    <div class="eurosite-order-booking-details travel-order-booking-details" style="margin:10px 0;font-size:13px;line-height:1.8;">
        {include file="addons/travel_core/components/order_booking_details.tpl"
                 booking_extra=$oi.extra
                 booking_view_id=$oi.extra.travel_surrogate_id|default:0
                 show_terms=true
                 booking_ref_line=$oi.extra.eurosite_ref_line|default:''}
    </div>
{/if}
