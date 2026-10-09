{*
    Admin order page: the booking card under each travel line, for every
    provider (orders:product_info — inside the product cell, under the SKU).
    It replaces the per-provider text blocks novoton, sphinx and eurosite used
    to print here. fn_travel_core_order_booking_card() prepares everything;
    non-travel lines get no card.
*}
{$tobc = fn_travel_core_order_booking_card($oi, $order_info|default:[], "admin")}
{if $tobc}
    {include file="addons/travel_core/components/order_booking_card.tpl" tobc=$tobc}
{/if}
