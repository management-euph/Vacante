{** block-description:tmpl_travel_stay_summary **}
{*
    Stay summary for the top of the checkout on phones — a layout block
    (block type travel_stay_summary, schemas/block_manager/blocks.post.php).
    Placed in Design -> Layouts -> Checkout above the checkout blocks, it
    shows one collapsed bar per booking in the cart (photo, hotel, dates,
    stay price) that opens into the same booking card the order summary
    shows (components/cart_booking_details.tpl, "summary" form).

    On wider screens the order summary sits beside the form, so the block
    hides itself there; on phones the order summary drops below the form and
    its copy of the card is hidden instead (booking-pages.css).

    The checkout controller assigns cart_products (with images); cart.products
    is the fallback. Non-travel lines return no card and are skipped.

    Line 1 names the template in the block's Template dropdown: CS-Cart reads
    block-description as a language key (without it, it falls back to the
    file name and shows "_stay_summary").
*}
{$tss_products = $cart_products|default:$cart.products|default:[]}
{foreach from=$tss_products key="tss_key" item="tss_product"}
    {$tss = fn_travel_core_cart_booking_card($tss_product, $tss_key)}
    {if $tss}
        <details class="travel-stay-summary">
            <summary class="travel-stay-summary__bar">
                <span class="travel-stay-summary__photo">
                    {if $tss.hotel.image_pair}
                        {include file="common/image.tpl" images=$tss.hotel.image_pair image_width=96 image_height=96 no_ids=true}
                    {else}
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"></rect><circle cx="9" cy="10" r="1.6"></circle><path d="M21 16l-5-5-8 8"></path></svg>
                    {/if}
                </span>
                <span class="travel-stay-summary__text">
                    <strong class="travel-stay-summary__name">{$tss.hotel.name|escape:html}</strong>
                    <span>{$tss.check_in.date|escape:html} &ndash; {$tss.check_out.date|escape:html}{if $tss.nights > 0} &middot; {__("travel_core.n_nights", [$tss.nights])}{/if}{if $tss.adults > 0} &middot; {__("travel_core.n_adults", [$tss.adults])}{/if}</span>
                </span>
                <span class="travel-stay-summary__side">
                    {if $tss.price}<strong>{$tss.price|escape:html}</strong>{/if}
                    <span class="travel-stay-summary__toggle">
                        <span class="travel-stay-summary__open">{__("travel_core.stay_details")}</span>
                        <span class="travel-stay-summary__close">{__("travel_core.stay_hide")}</span>
                        <svg class="travel-ccard-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"></polyline></svg>
                    </span>
                </span>
            </summary>
            {include file="addons/travel_core/components/cart_booking_details.tpl" product=$tss_product key=$tss_key tcc_card=$tss tcc_context="summary"}
        </details>
    {/if}
{/foreach}
