{* Booking card in the checkout summary — the shared component in its
   "sidebar" form: it opens with the hotel, and the core product line above
   it is hidden (gated inside on $product.extra.travel_booking). *}
{* docker/fullstore/diagnose-checkout.sh opens the checkout with travel_debug=1: what this hook was handed. *}
{if $smarty.request.travel_debug|default:""}<!-- travel_core block_checkout:product_extra key={$key|default:"-"|escape:html} product_id={$product.product_id|default:"-"|escape:html} travel_booking={if $product.extra.travel_booking|default:false}yes{else}no{/if} -->{/if}
{include file="addons/travel_core/components/cart_booking_details.tpl" product=$product key=$key tcc_context="sidebar"}
