{*
    One hotel card of the list (board 15): the same card for every supplier,
    drawn by the Travel Core list view (blocks/product_list_templates/travelcore_cards.tpl).
    Params:
      product  the CS-Cart product row (product_id, product, main_pair)
      card     its ListCardFactory card: kind, stars, place, from_price, cta
    No "Add to cart": a hotel is booked on its page, once dates, room and
    turiști are chosen; the button opens that page.
*}
{$travel_lc_url = "products.view?product_id=`$product.product_id`"|fn_url}
<li class="travel-lc travel-lc--{$card.kind}">
    <a class="travel-lc__photo" href="{$travel_lc_url}" tabindex="-1" aria-hidden="true">
        {include file="common/image.tpl"
            obj_id="travel_lc_`$product.product_id`"
            images=$product.main_pair
            image_width=$settings.Thumbnails.product_lists_thumbnail_width
            image_height=$settings.Thumbnails.product_lists_thumbnail_height
            lazy_load=true}
    </a>
    <div class="travel-lc__body">
        <h3 class="travel-lc__t">
            <a class="travel-lc__name" href="{$travel_lc_url}">{$product.product nofilter}</a>
            {if $card.stars > 0}
                <span class="travel-lc__stars" role="img" aria-label="{__("travel_core.stars_rating", ["[rating]" => $card.stars])|escape:html}">{"★"|str_repeat:$card.stars}</span>
            {/if}
        </h3>
        {if $card.place}
            <p class="travel-lc__loc">{include file="addons/travel_core/components/design_a_icon.tpl" icon="pin" size=15}<span>{$card.place}</span></p>
        {/if}
    </div>
    <div class="travel-lc__price">
        {if $card.from_price}
            <p class="travel-lc__p">
                <span class="travel-lc__l">{__("travel_core.list_from")}</span>
                <span class="travel-lc__v">{$card.from_price}</span>
                <span class="travel-lc__u">{__("travel_core.list_per_night")}</span>
                <span class="travel-lc__per">{__("travel_core.list_for_two")}</span>
            </p>
        {else}
            <p class="travel-lc__p travel-lc__p--none">{__("travel_core.list_price_with_dates")}</p>
        {/if}
        <a class="travel-lc__cta{if !$card.from_price} travel-lc__cta--sec{/if}" href="{$travel_lc_url}">{__($card.cta)}</a>
    </div>
</li>
