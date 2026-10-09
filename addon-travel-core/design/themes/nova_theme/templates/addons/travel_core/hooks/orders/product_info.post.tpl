{*
    Customer order page: the booking card under each travel line, for every
    provider — the checkout's card in its "order" form (status, the trip, the
    rooms and guests, the cancellation terms, the balance of a deposit order).
    fn_travel_core_order_booking_card() builds the customer card, which never
    carries the provider's name, the supplier reference or the supplier price.
    Non-travel lines get no card.
*}
{$tobc = fn_travel_core_order_booking_card($product, $order_info|default:[], "customer")}
{if $tobc}
    {include file="addons/travel_core/components/cart_booking_details.tpl" product=$product tcc_card=$tobc tcc_context="order"}
{/if}
