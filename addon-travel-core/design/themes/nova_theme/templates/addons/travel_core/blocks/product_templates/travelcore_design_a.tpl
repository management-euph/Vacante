{script src="js/tygh/exceptions.js"}
{script src="js/addons/travel_core/pdp-design-a.js"}

{*
    Travel Core · Design A — hotel page with the search bar under the photos.

    Selectable in Settings → Appearance → "Product detailed page view" (store
    default) or in a product's own "Product details view", labelled
    "Travel Core · Design A" (lang var: travelcore_design_a). The current
    hotel page (travelcore_template.tpl) is untouched, so the owner switches
    between the two in the admin. Plan: docs/hotel-page-design-a-plan.md.

    Table of contents
    ---
    #10. Title row: hotel name and stars inside the products:main_info_title
         hook (as on every product template), the location line with
         "Vezi pe hartă", then wishlist/compare buttons, the indicative
         "from" price when known and "Alege datele"
    #20. Photo mosaic: one large photo and up to four smaller ones
    #25. Stock gallery, kept out of sight: the theme's image previewer is
         bound to its links, so a mosaic tile opens the previewer through
         them (pdp-design-a.js); without it a tile opens the photo itself
    #30. Search bar and results: the booking form mount comes from the
         travel_core products:product_tabs pre-hook (or product_detail_bottom
         when booking_form_position is after_description) — either way it
         renders directly under the photos, and the inline results land
         under the bar, above the tabs
    #40. Compact search summary, shown by pdp-design-a.js once the bar has
         scrolled away after a search
    #50. Product tabs ("Descriere" first, as ordered in Design → Product tabs)

    No short description, price column, options or quantity: a hotel's price
    is date-driven and comes from the search (see travelcore_template.tpl).
*}

{* Product data (the stock defaults, as in travelcore_template.tpl — they
   shape the captures common/product_data.tpl builds) *}
{$show_product_common_info = $show_product_common_info|default:true}
{$show_old_price_label = $show_old_price_label|default:false}
{$show_amount_label = $show_amount_label|default:false}
{$show_in_stock_label = $show_in_stock_label|default:false}
{$hide_qty_label = $hide_qty_label|default:true}
{$add_to_cart_type = $add_to_cart_type|default:"text"}
{$add_to_cart_class = $add_to_cart_class|default:"ty-products-buttons-block"}
{$show_product_company_data = $show_product_company_data|default:true}
{$show_product_company_data_in_advanced_options = $show_product_company_data_in_advanced_options|default:false}
{$show_product_features_short_list = $show_product_features_short_list|default:true}
{$product_details_thumbnail_width = $product_details_thumbnail_width|default:$settings.Thumbnails.product_details_thumbnail_width}
{$product_details_thumbnail_height = $product_details_thumbnail_height|default:$settings.Thumbnails.product_details_thumbnail_height}
{$thumbnails_size = $thumbnails_size|default:68}
{* /Product data *}

{* This page prints its own location line (#10); the shared hook
   (main_info_title.post.tpl) skips the current page's one when it sees this
   capture. A capture, not a variable: hook templates read it without going
   up the Smarty scope chain. *}
{capture name="travel_pdp_design"}a{/capture}

<div class="ty-product-block ty-product-detail travel-pdpa" data-travel-design="a">

    <div class="ty-product-block__wrapper travel-pdpa__wrapper">

        {hook name="products:view_main_info"}

            {if $product}
                {$obj_id = $product.product_id}

                {* Get product data (fills the captures used below) *}
                {include file="common/product_data.tpl"
                    product=$product
                }

                {* #10. Title row *}
                <div class="travel-pdpa-head">
                    <div class="travel-pdpa-head__main">
                        {hook name="products:main_info_title"}
                            {if !$hide_title}
                                <h1 class="ty-product-block-title travel-pdpa-head__title" {live_edit name="product:product:{$product.product_id}"}>
                                    <bdi>{$product.product nofilter}</bdi>
                                    {if $travel_pdp_stars > 0}
                                        <span class="travel-pdpa-stars" role="img" aria-label="{__("travel_core.stars_rating", ["[rating]" => $travel_pdp_stars])|escape:html}">{"★"|str_repeat:$travel_pdp_stars}</span>
                                    {/if}
                                </h1>
                            {/if}

                            {hook name="products:brand"}
                                {hook name="products:brand_default"}
                                    {if $show_product_features_short_list}
                                        <div class="brand">
                                            {include file="views/products/components/product_features_short_list.tpl"
                                                features=$product.header_features
                                                feature_image=$feature_image
                                                product=$product
                                                image_size=$image_size
                                                no_container=$no_container
                                                settings=$settings
                                                smarty=$smarty
                                            }
                                        </div>
                                    {/if}
                                {/hook}
                            {/hook}
                        {/hook}

                        {* Location line + map link, from the same
                           server-built values as the current page's line
                           (HotelLocationLine / HotelMapUrl), which the hook
                           above leaves out on this page. *}
                        {if $travel_hotel_location_line || $travel_hotel_map_url}
                            <p class="travel-pdpa-location">
                                {if $travel_hotel_location_line}
                                    <span class="travel-pdpa-location__line">{$travel_hotel_location_line|escape:html}</span>
                                {/if}
                                {if $travel_hotel_map_url}
                                    <a href="{$travel_hotel_map_url|escape:html}" target="_blank" rel="noopener" class="travel-pdpa-location__map travel-hotel-map-link">{__("travel_core.pdpa_show_map")}</a>
                                {/if}
                            </p>
                        {/if}
                    </div>

                    <div class="travel-pdpa-head__side">
                        {* Wishlist / compare / addon buttons. The stock
                           add-to-cart submit stays hidden (CSS): a hotel is
                           booked from the search results, never as a bare
                           cart line. *}
                        <div class="travel-pdpa-head__actions">
                            {$form_open = "form_open_`$obj_id`"}
                            {$smarty.capture.$form_open nofilter}
                                {$add_to_cart = "add_to_cart_`$obj_id`"}
                                {$smarty.capture.$add_to_cart nofilter}
                            {hook name="products:product_form_close_tag"}
                                {$form_close = "form_close_`$obj_id`"}
                                {$smarty.capture.$form_close nofilter}
                            {/hook}
                        </div>

                        {if $travel_pdp_from_price}
                            <p class="travel-pdpa-from">
                                <span class="travel-pdpa-from__label">{__("travel_core.pdpa_from")}</span>
                                <span class="travel-pdpa-from__price">{$travel_pdp_from_price|escape:html} {__("travel_core.pdpa_per_night")}</span>
                                <span class="travel-pdpa-from__note">{__("travel_core.pdpa_from_note")}</span>
                            </p>
                        {/if}

                        <a href="#travel-booking-root" class="ty-btn travel-pdpa-head__dates" data-travel-open-dates>{__("travel_core.pdpa_choose_dates")}</a>
                    </div>
                </div>
                {* /Title row *}

                {* #20. Photo mosaic *}
                {$travel_pdpa_photos = []}
                {if $product.main_pair}
                    {$travel_pdpa_photos[] = $product.main_pair}
                {/if}
                {if $product.image_pairs}
                    {foreach $product.image_pairs as $travel_pdpa_pair}
                        {$travel_pdpa_photos[] = $travel_pdpa_pair}
                    {/foreach}
                {/if}
                {$travel_pdpa_photo_count = $travel_pdpa_photos|count}

                {if $travel_pdpa_photo_count > 0}
                    {if $travel_pdpa_photo_count >= 5}
                        {$travel_pdpa_tiles = 5}
                    {else}
                        {$travel_pdpa_tiles = $travel_pdpa_photo_count}
                    {/if}
                    <div class="travel-pdpa-gallery travel-pdpa-gallery--{$travel_pdpa_tiles}" data-travel-gallery data-photo-count="{$travel_pdpa_photo_count}">
                        {foreach $travel_pdpa_photos as $travel_pdpa_photo name=travel_pdpa_photos}
                            {if $smarty.foreach.travel_pdpa_photos.iteration <= $travel_pdpa_tiles}
                                {if $smarty.foreach.travel_pdpa_photos.first}
                                    {$travel_pdpa_width = 1200}
                                {else}
                                    {$travel_pdpa_width = 600}
                                {/if}
                                <a href="{$travel_pdpa_photo.detailed.image_path|default:$travel_pdpa_photo.icon.image_path}"
                                   class="travel-pdpa-gallery__tile{if $smarty.foreach.travel_pdpa_photos.first} travel-pdpa-gallery__tile--main{/if}"
                                   target="_blank"
                                   rel="noopener"
                                   data-travel-gallery-index="{$smarty.foreach.travel_pdpa_photos.index}"
                                   aria-label="{__("travel_core.pdpa_open_photo", ["[n]" => $smarty.foreach.travel_pdpa_photos.iteration, "[total]" => $travel_pdpa_photo_count])|escape:html}">
                                    {include file="common/image.tpl"
                                        images=$travel_pdpa_photo
                                        image_width=$travel_pdpa_width
                                        no_ids=true
                                    }
                                </a>
                            {/if}
                        {/foreach}
                        {if $travel_pdpa_photo_count > 1}
                            <button type="button" class="travel-pdpa-gallery__all" data-travel-gallery-index="0">
                                <span class="travel-pdpa-gallery__all-icon" aria-hidden="true"></span>{__("travel_core.pdpa_all_photos", ["[n]" => $travel_pdpa_photo_count])}
                            </button>
                            <span class="travel-pdpa-gallery__count" aria-hidden="true">1 / {$travel_pdpa_photo_count}</span>
                        {/if}
                    </div>
                {/if}
                {* /Photo mosaic *}

                {* #25. Stock gallery, kept out of sight (see the header) *}
                <div class="travel-pdpa-stock-gallery" aria-hidden="true">
                    {hook name="products:image_wrap"}
                        {if !$no_images}
                            <div class="ty-product-block__img cm-reload-{$product.product_id}" data-ca-previewer="true" id="product_images_{$product.product_id}_update">
                                {$product_labels = "product_labels_`$obj_prefix``$obj_id`"}
                                {$smarty.capture.$product_labels nofilter}
                                {include file="views/products/components/product_images.tpl"
                                    product=$product
                                    image_pair_var=$image_pair_var
                                    preview_id=$preview_id
                                    image_id=$image_id
                                    image_width=$product_details_thumbnail_width
                                    image_height=$product_details_thumbnail_height
                                    image_pair=$image_pair
                                    settings=$settings
                                    thumbnails_size=$thumbnails_size
                                    show_detailed_link=true
                                }
                            <!--product_images_{$product.product_id}_update--></div>
                        {/if}
                    {/hook}
                </div>
                {* /Stock gallery *}
            {/if}

        {/hook}
    </div>

    {if $smarty.capture.hide_form_changed == "Y"}
        {$hide_form = $smarty.capture.orig_val_hide_form}
    {/if}

    {* #30. Search bar + results (booking_form_position = after_description) *}
    {hook name="products:product_detail_bottom"}
    {/hook}

    {if $show_product_tabs}
        {include file="views/tabs/components/product_popup_tabs.tpl"
            tabs=$tabs
            smarty=$smarty
        }
        {$smarty.capture.popupsbox_content nofilter}
    {/if}

    {* #40. Compact search summary *}
    {if $product}
        <div class="travel-pdpa-compact" data-travel-pdpa-compact hidden
             role="region"
             aria-label="{__("travel_core.pdpa_summary_label")|escape:html}"
             data-label-adult="{__("travel_core.adult")|escape:html}"
             data-label-adults="{__("travel_core.adults")|escape:html}"
             data-label-child="{__("travel_core.child")|escape:html}"
             data-label-children="{__("travel_core.children")|escape:html}"
             data-label-room="{__("travel_core.room")|escape:html}"
             data-label-rooms="{__("travel_core.rooms")|escape:html}">
            <div class="travel-pdpa-compact__inner">
                <p class="travel-pdpa-compact__hotel">
                    <bdi>{$product.product nofilter}</bdi>
                    {if $travel_pdp_stars > 0}
                        <span class="travel-pdpa-stars" aria-hidden="true">{"★"|str_repeat:$travel_pdp_stars}</span>
                    {/if}
                </p>
                <p class="travel-pdpa-compact__summary" data-travel-pdpa-summary></p>
                <button type="button" class="travel-pdpa-compact__link travel-pdpa-compact__link--change" data-travel-open-dates>{__("travel_core.pdpa_change")}</button>
                <button type="button" class="travel-pdpa-compact__link travel-pdpa-compact__link--rooms" data-travel-goto-results>{__("travel_core.pdpa_rooms_link")}</button>
            </div>
        </div>
    {/if}
    {* /Compact search summary *}

    {* #50. Product tabs (the booking form mount renders in its pre-hook) *}
    {if $show_product_tabs}
        {hook name="products:product_tabs"}
            {include file="views/tabs/components/product_tabs.tpl"
                tabs=$tabs
                smarty=$smarty
            }

            {if $blocks.$tabs_block_id.properties.wrapper}
                {include file=$blocks.$tabs_block_id.properties.wrapper
                    content=$smarty.capture.tabsbox_content
                    title=$blocks.$tabs_block_id.description
                }
            {else}
                {$smarty.capture.tabsbox_content nofilter}
            {/if}
        {/hook}
    {/if}
    {* /Product tabs *}

</div>

{capture name="mainbox_title"}{$details_page = true}{/capture}
