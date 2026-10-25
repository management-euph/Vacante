{* Location line under the hotel name (both providers), assigned by
   _travel_core_prepare_hotel_seo_data(): "street, city, country" when the
   hotel has a street address (sphinx), else "city, region, country"
   (novoton), plus a "show on map" link whose URL is built SERVER-SIDE
   (HotelMapUrl): a full-precision coordinate pin when the provider has
   coordinates, else a Google place search by hotel name + address.
   Hook products:main_info_title WRAPS the product h1 in the theme's
   blocks/product_templates/*.tpl (default_template, bigpicture_template) —
   this .post renders immediately AFTER it, i.e. directly under the hotel
   name. Inline styles by design: no travel_core stylesheet is guaranteed to
   load on the product page. Empty for non-hotel products (vars unset), and
   on the Design A page (travelcore_design_a.tpl), which prints its own line
   and says so through the travel_pdp_design capture. *}
{if $smarty.capture.travel_pdp_design != "a" && ($travel_hotel_location_line || $travel_hotel_map_url)}
    <span class="travel-pdp-location" style="display:block; font-size:14px; line-height:1.4; font-weight:normal; color:#666; margin-top:4px;">
        {$travel_hotel_location_line|escape:html}{if $travel_hotel_map_url}{if $travel_hotel_location_line} - {/if}<a href="{$travel_hotel_map_url|escape:html}" target="_blank" rel="noopener" class="travel-hotel-map-link" style="font-size:13px; white-space:nowrap;">{__("travel_core.location_show_map")|default:"Location - show map"}</a>{/if}
    </span>
{/if}
