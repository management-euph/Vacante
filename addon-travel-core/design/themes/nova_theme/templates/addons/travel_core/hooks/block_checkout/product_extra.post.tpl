{* Booking card in the checkout summary — the shared component in its
   "sidebar" form: it opens with the hotel, and the core product line above
   it is hidden (gated inside on $product.extra.travel_booking). *}
{include file="addons/travel_core/components/cart_booking_details.tpl" product=$product key=$key tcc_context="sidebar"}
