{script src="js/tygh/exceptions.js"}
{script src="js/addons/travel_core/pdp-design-a.js"}
{script src="js/addons/travel_core/pdp-design-a-rooms.js"}

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
         "Vezi pe hartă" (a circuit: its route, then its length, transport
         and group, components/circuit_facts.tpl), then wishlist/compare
         buttons, the indicative "from" price when known and "Alege datele"
    #20. Photo mosaic: one large photo and up to four smaller ones, each
         filling its tile; one too small or too tall for its tile shows
         whole over a blurred copy of itself (pdp-design-a.js)
    #25. Stock gallery, kept out of sight: the theme's image previewer is
         bound to its links, so a mosaic tile opens the previewer through
         them (pdp-design-a.js); without it a tile opens the photo itself
    #30. Search bar and results: the booking form mount comes from the
         travel_core products:product_tabs pre-hook (or product_detail_bottom
         when booking_form_position is after_description) — either way it
         renders directly under the photos, and the inline results land
         under the bar, above the tabs
    #40. Sticky header, two lines, shown by pdp-design-a.js: line 1 (name,
         stars, map pin, "Alege datele" / "Alege cameră", favourites) once
         the hero has scrolled off, line 2 (the product's tabs) once the
         page's tab strip has been passed
    #45. A circuit's program, hotels, things to know and inclusions (board
         16.3), under its rooms: components/circuit_details.tpl, first in
         the tabs hook so it follows the bar its pre-hook renders
    #50. Product tabs ("Descriere" first, as ordered in Design → Product tabs),
         their panels shown one under another by pdp-design-a.js

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
{* No feature list under the hotel name on this page ("TV, Air
   Conditioning, … 3 Stars, Resort"): facilities live in the tabs, and the
   stars sit next to the name. *}
{$show_product_features_short_list = false}
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

    {* Not the stock ty-product-block__wrapper: themes lay out that wrapper's
       children side by side (image left, info right), which put the title
       row next to the photos. This page stacks them (design-a.css). *}
    <div class="travel-pdpa__wrapper">

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
                           above leaves out on this page; between them the
                           hotel's code, the product code ("COD: NVT476"),
                           in the location's own colour and font. *}
                        {if $travel_hotel_location_line || $product.product_code || $travel_hotel_map_url || $travel_circuit_facts.route_line}
                            <p class="travel-pdpa-location">
                                {if $travel_hotel_location_line}
                                    <span class="travel-pdpa-location__line">{$travel_hotel_location_line|escape:html}</span>
                                {elseif $travel_circuit_facts.route_line}
                                    {* A circuit's route, where a hotel has its place (board 16.3) *}
                                    <span class="travel-pdpa-location__line travel-pdpa-location__route">{include file="addons/travel_core/components/design_a_icon.tpl" icon="route"}<span class="travel-pdpa-sr">{__("travel_core.circuit_route")}: </span>{$travel_circuit_facts.route_line|escape:html}</span>
                                {/if}
                                {if $product.product_code}
                                    <span class="travel-pdpa-location__code">{__("travel_core.pdpa_code", ["[code]" => $product.product_code|escape:html])}</span>
                                {/if}
                                {if $travel_hotel_map_url}
                                    <a href="{$travel_hotel_map_url|escape:html}" target="_blank" rel="noopener" class="travel-pdpa-location__map travel-hotel-map-link">{__("travel_core.pdpa_show_map")}</a>
                                {/if}
                            </p>
                        {/if}

                        {* A circuit's length, transport and group (board 16.3) *}
                        {if $travel_circuit_facts}
                            {include file="addons/travel_core/components/circuit_facts.tpl" travel_circuit_facts=$travel_circuit_facts}
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

                        <a href="#travel-booking-root" class="ty-btn travel-pdpa-head__dates" data-travel-open-dates>{if $travel_circuit_facts}{__("travel_core.choose_departure_date")}{else}{__("travel_core.pdpa_choose_dates")}{/if}</a>
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
                                {* CS-Cart never enlarges a photo into a thumbnail: one narrower
                                   than the width asked for comes back centred on a canvas of
                                   that width, in the thumbnail background colour (the white
                                   bands beside a 1024px photo in the 1200px main tile). So ask
                                   at most the photo's own width; the tile does the rest. *}
                                {$travel_pdpa_photo_x = $travel_pdpa_photo.detailed.image_x|default:$travel_pdpa_photo.icon.image_x|default:0}
                                {if $travel_pdpa_photo_x > 0 && $travel_pdpa_photo_x < $travel_pdpa_width}
                                    {$travel_pdpa_width = $travel_pdpa_photo_x}
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

    {* #40. Sticky header, two lines (pdp-design-a.js shows it; it stays
       hidden without the script).
       Line 1, once the hero has scrolled off (the title row, the photos and
       the booking box: the bar and its departure chips, not the offers): the
       name (cut with "…" when it does not fit; hover or focus shows it whole
       with the address; a click goes back to the top), the stars or a
       circuit's length, the map pin, then "Alege datele" ("Alege data
       plecării" for a circuit or a package by coach or plane) before a search
       or "Alege cameră" after it, each shown only while what it leads to is
       off screen, and the favourites heart (the title row's wishlist button,
       clicked for the visitor).
       Line 2, once the page's own tab strip has gone under the header (line 2
       then covers it): the product's CS-Cart tabs, built from that strip,
       following the section in view. *}
    {if $product}
        <div class="travel-pdpa-sticky" data-travel-pdpa-sticky hidden
             role="region"
             aria-label="{__("travel_core.pdpa_sticky_label")|escape:html}">
            <div class="travel-pdpa-sticky__line">
                <div class="travel-pdpa-sticky__inner">
                    <div class="travel-pdpa-sticky__id">
                        <span class="travel-pdpa-sticky__namebox">
                            {* Screen readers get the name once (the button) and, as its
                               description, only what the tooltip adds: the address, or a
                               circuit's route and length, transport and departure city. *}
                            <button type="button" class="travel-pdpa-sticky__name" data-travel-pdpa-top{if $travel_hotel_location_line || $travel_circuit_facts} aria-describedby="travel-pdpa-sticky-tip-{$product.product_id}"{/if}><bdi>{$product.product nofilter}</bdi><span class="travel-pdpa-sr">, {__("travel_core.pdpa_back_to_top")}</span></button>
                            <span class="travel-pdpa-sticky__tip" role="tooltip">
                                <span class="travel-pdpa-sticky__tip-name" aria-hidden="true"><bdi>{$product.product nofilter}</bdi>{if $travel_pdp_stars > 0} · {$travel_pdp_stars} ★{/if}</span>
                                <span id="travel-pdpa-sticky-tip-{$product.product_id}">
                                    {if $travel_hotel_location_line}
                                        <span class="travel-pdpa-sticky__tip-line">{$travel_hotel_location_line|escape:html}</span>
                                    {elseif $travel_circuit_facts}
                                        {if $travel_circuit_facts.route_line}
                                            <span class="travel-pdpa-sticky__tip-line">{__("travel_core.circuit_route")}: {$travel_circuit_facts.route_line|escape:html}</span>
                                        {/if}
                                        {if $travel_circuit_facts.days > 0 || $travel_circuit_facts.nights > 0 || $travel_circuit_facts.transport || $travel_circuit_facts.from_city}
                                            <span class="travel-pdpa-sticky__tip-line">{if $travel_circuit_facts.days > 0}{__("travel_core.circuit_days_`$travel_circuit_facts.days_form`", ["[n]" => $travel_circuit_facts.days])}{/if}{if $travel_circuit_facts.days > 0 && $travel_circuit_facts.nights > 0} / {/if}{if $travel_circuit_facts.nights > 0}{__("travel_core.circuit_nights_`$travel_circuit_facts.nights_form`", ["[n]" => $travel_circuit_facts.nights])}{/if}{if ($travel_circuit_facts.days > 0 || $travel_circuit_facts.nights > 0) && ($travel_circuit_facts.transport || $travel_circuit_facts.from_city)} · {/if}{if $travel_circuit_facts.transport == "flight"}{if $travel_circuit_facts.from_city}{__("travel_core.circuit_flight_from", ["[city]" => $travel_circuit_facts.from_city|escape:html])}{else}{__("travel_core.circuit_flight")}{/if}{elseif $travel_circuit_facts.transport == "bus"}{if $travel_circuit_facts.from_city}{__("travel_core.circuit_bus_from", ["[city]" => $travel_circuit_facts.from_city|escape:html])}{else}{__("travel_core.circuit_bus")}{/if}{elseif $travel_circuit_facts.from_city}{__("travel_core.circuit_departs_from", ["[city]" => $travel_circuit_facts.from_city|escape:html])}{/if}</span>
                                        {/if}
                                    {/if}
                                </span>
                            </span>
                        </span>
                        {if $travel_pdp_stars > 0}
                            <span class="travel-pdpa-sticky__stars" role="img" aria-label="{__("travel_core.stars_rating", ["[rating]" => $travel_pdp_stars])|escape:html}">{$travel_pdp_stars}<span aria-hidden="true"> ★</span></span>
                        {elseif $travel_circuit_facts.days > 0}
                            <span class="travel-pdpa-sticky__days">{__("travel_core.circuit_days_`$travel_circuit_facts.days_form`", ["[n]" => $travel_circuit_facts.days])}</span>
                        {/if}
                        {if $travel_hotel_map_url}
                            <a href="{$travel_hotel_map_url|escape:html}" target="_blank" rel="noopener" class="travel-pdpa-sticky__map" title="{__("travel_core.pdpa_show_map")|escape:html}" aria-label="{__("travel_core.pdpa_show_map_new_tab")|escape:html}"></a>
                        {/if}
                    </div>
                    <div class="travel-pdpa-sticky__side">
                        {* Before a search: "Alege datele", or the title row's "Alege data
                           plecării" for a circuit (and, set by the script, for a
                           package by coach or plane); after it "Alege cameră". *}
                        <button type="button" class="travel-pdpa-sticky__cta" data-travel-pdpa-cta
                                data-label-dates="{if $travel_circuit_facts}{__("travel_core.choose_departure_date")|escape:html}{else}{__("travel_core.pdpa_check_availability")|escape:html}{/if}"
                                data-label-departure="{__("travel_core.choose_departure_date")|escape:html}"
                                data-label-room="{__("travel_core.pdpa_choose_room")|escape:html}">{if $travel_circuit_facts}{__("travel_core.choose_departure_date")}{else}{__("travel_core.pdpa_check_availability")}{/if}</button>
                        <button type="button" class="travel-pdpa-sticky__fav" data-travel-pdpa-fav aria-pressed="false"
                                aria-label="{__("travel_core.pdpa_add_favourite")|escape:html}"
                                title="{__("travel_core.pdpa_add_favourite")|escape:html}"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/></svg></button>
                    </div>
                </div>
            </div>
            <nav class="travel-pdpa-sticky__tabs" data-travel-pdpa-sticky-tabs hidden
                 aria-label="{__("travel_core.pdpa_sections_label")|escape:html}">
                <div class="travel-pdpa-sticky__tablist" data-travel-pdpa-sticky-tablist></div>
            </nav>
        </div>
    {/if}
    {* /Sticky header *}

    {* #50. Product tabs (the booking form mount renders in its pre-hook) *}
    {if $show_product_tabs}
        {hook name="products:product_tabs"}
            {* #45. A circuit's program, under its rooms (the bar comes from the pre-hook) *}
            {if $travel_circuit}
                {include file="addons/travel_core/components/circuit_details.tpl" travel_circuit=$travel_circuit}
            {/if}

            {include file="views/tabs/components/product_tabs.tpl"
                tabs=$tabs
                smarty=$smarty
            }

            {* The tabs' strip and panels: pdp-design-a.js shows the panels one
               under another, each under its tab's title, so line 2 of the
               sticky header can follow them; without the script they stay
               CS-Cart's tabs. *}
            <div class="travel-pdpa-tabs" data-travel-pdpa-tabs>
                {if $blocks.$tabs_block_id.properties.wrapper}
                    {include file=$blocks.$tabs_block_id.properties.wrapper
                        content=$smarty.capture.tabsbox_content
                        title=$blocks.$tabs_block_id.description
                    }
                {else}
                    {$smarty.capture.tabsbox_content nofilter}
                {/if}
            </div>
        {/hook}
    {/if}
    {* /Product tabs *}

</div>

{capture name="mainbox_title"}{$details_page = true}{/capture}
