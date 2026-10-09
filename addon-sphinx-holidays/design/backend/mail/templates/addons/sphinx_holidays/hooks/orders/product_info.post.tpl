{* Sphinx Holidays — Order Email Product Info Hook
 * Hook: orders:product_info (mail area) — adds the booking-stay block to each
 * sphinx product line in order-confirmation emails, through travel_core's
 * mail order_booking_details partial (the order pages draw the booking card).
 * booking_view_id=0: no admin links in customer emails. *}
{if !empty($oi.extra.sphinx_booking)}
<table cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-top: 5px; font-size: 12px; color: #666;">
    <tr>
        <td>
            {* show_terms: the customer's confirmation email is where they look
               up the cancellation policy later, so it carries the same payment
               and cancellation terms the booking form showed them (stored on
               the cart line at add-to-cart from the verified offer). *}
            {include file="addons/travel_core/components/order_booking_details.tpl"
                     booking_extra=$oi.extra
                     booking_view_id=0
                     booking_label_prefix="travel_core"
                     show_terms=true
                 show_rooms_breakdown=true}
        </td>
    </tr>
</table>
{/if}
