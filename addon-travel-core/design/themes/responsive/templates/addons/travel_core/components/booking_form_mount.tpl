{*
 * Travel Core: shared booking-form React mount + script loader.
 *
 * Included by BOTH position hooks (products/product_tabs.pre.tpl and
 * products/product_detail_bottom.post.tpl), which gate on the shared
 * $addons.travel_core.booking_form_position setting — keep this file the
 * single source of truth for the mount markup.
 *
 * Variables assigned by the dispatch_before_display PHP hook (cart_hooks.php):
 *   $travel_booking_product_id  - product ID (hotel products only, and only
 *                                 when travel_core's show_booking_form is on)
 *   $travel_booking_scripts     - array of React JS bundle URLs
 *}

<!-- [travel_core] booking_form_mount.tpl LOADED pid={$travel_booking_product_id|default:'EMPTY'} -->

{if $travel_booking_product_id}
<!-- [travel_core] BOOKING FORM RENDERING for product_id={$travel_booking_product_id} -->
{* The engine's inline search swaps results into the SAME shell the
   standalone results pages use: everything after .travel-search-form-wrapper
   inside .travel-search-results-page gets replaced by the fetched offers.
   .travel-pdp-results scopes any PDP-specific layout neutralization. *}
<div class="travel-search-results-page travel-pdp-results">
    <div class="travel-search-form-wrapper">
        <div id="travel-booking-root"
             data-travel-booking
             data-product-id="{$travel_booking_product_id}"
             style="margin-bottom: 20px; min-height: 60px;">
            <div class="travel-loading-state">
                <div class="nvt-skeleton-row">
                    <div class="nvt-skeleton-field nvt-skeleton-field--wide"></div>
                    <div class="nvt-skeleton-field"></div>
                    <div class="nvt-skeleton-field nvt-skeleton-field--btn"></div>
                </div>
            </div>
        </div>
    </div>
</div>
{if $travel_booking_scripts}
    {foreach $travel_booking_scripts as $script_url}
    <!-- [travel_core] Loading script: {$script_url} -->
    <script src="{$script_url}" defer></script>
    {/foreach}
{/if}
{else}
<!-- [travel_core] booking_form_mount: travel_booking_product_id NOT SET - no booking form -->
{/if}
