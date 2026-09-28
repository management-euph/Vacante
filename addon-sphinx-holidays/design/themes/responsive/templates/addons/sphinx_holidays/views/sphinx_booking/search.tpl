{*
 * Sphinx Hotel Search Results
 *
 * Displays offer cards returned from the Sphinx API. For new searches
 * (status=pending), the page loads with a skeleton loader and JS polls
 * sphinx_booking.search_poll for incremental results. Cached searches
 * render inline immediately.
 *
 * @package SphinxHolidays
 * @since 1.0.0
 *}

<div class="travel-search-results-page sphinx-search-results">

    {* ===== NO HOTEL HEADER / BADGE =====
       Results render inline on the product page, which already shows the
       hotel identity — the old header strip (shared hotel_header include +
       the "✓ Available" badge block) was removed with it. The search
       id/status the poll JS needs moved onto #sphinx-results-container,
       INSIDE the swap region, so an inline search delivers a fresh id to
       the product page. *}

    {* ===== BOOKING FORM — Pre-rendered in controller to prevent OOM ===== *}
    <div class="travel-search-form-wrapper">
        {$booking_engine_html nofilter}
    </div>

    {* Loading skeleton — shown while JS polls for results. Styled by
       travel_core's search-results.css; display:none is JS-toggled state. *}
    <div class="sphinx-loading-skeleton" style="display: none;">
        <div class="travel-loading-message sphinx-loading-message">
            <div class="travel-spinner sphinx-spinner"></div>
            <span>{__("sphinx_holidays.searching_please_wait")|default:"Searching for live offers…"}</span>
            {if $sphinx_from_price}
                <div class="travel-from-price sphinx-from-price">
                    {__("sphinx_holidays.from")|default:"from"}
                    <strong>{$sphinx_from_price.price|number_format:2:",":"."} {$sphinx_from_price.currency|default:'EUR'|escape:html}</strong>
                </div>
            {/if}
        </div>
        {foreach from=[1,2,3] item=i}
            <div class="travel-offer-card sphinx-offer-card travel-skeleton-card sphinx-skeleton-card" aria-hidden="true">
                <div class="sphinx-offer-hotel">
                    <div class="travel-skeleton-img sphinx-skeleton-img"></div>
                    <div class="sphinx-offer-hotel-info">
                        <div class="travel-skeleton-line sphinx-skeleton-line travel-skeleton-title sphinx-skeleton-title"></div>
                        <div class="travel-skeleton-line sphinx-skeleton-line travel-skeleton-short sphinx-skeleton-short"></div>
                    </div>
                </div>
                <div class="sphinx-offer-details">
                    <div class="travel-skeleton-line sphinx-skeleton-line"></div>
                    <div class="travel-skeleton-line sphinx-skeleton-line travel-skeleton-short sphinx-skeleton-short"></div>
                </div>
                <div class="sphinx-offer-price-action">
                    <div class="travel-skeleton-line sphinx-skeleton-line travel-skeleton-price sphinx-skeleton-price"></div>
                </div>
            </div>
        {/foreach}
    </div>

    {* Results container — carries the poll handshake (search id + status)
       INSIDE the swap region: the engine's inline search copies this node
       into the product page and fires travel:results-swapped, which re-arms
       the poll in search-results.js against the fresh id. *}
    <div class="sphinx-results-container" id="sphinx-results-container"
         data-search-id="{$sphinx_search_id|escape:html}"
         data-search-status="{$sphinx_search_status|default:'idle'}"
         data-client-params="{$sphinx_client_params_json|default:''|escape:html}"
         data-client-config="{$sphinx_client_config_json|default:''|escape:html}">
        {foreach from=$sphinx_search_results item=result name=results}
            <div class="travel-offer-card sphinx-offer-card" data-offer-id="{$result.offer_id|default:''}">

                {* Hotel info *}
                <div class="travel-offer-hotel sphinx-offer-hotel">
                    {if $result.hotel_image}
                        <img src="{$result.hotel_image}" alt="{$result.hotel_name|escape:html}" class="travel-offer-image sphinx-offer-image" width="88" height="64" loading="lazy">
                    {/if}
                    <div class="sphinx-offer-hotel-info">
                        <h3 class="travel-offer-hotel-name sphinx-offer-hotel-name">{$result.hotel_name|escape:html}</h3>
                        {if $result.star_rating}
                            <span class="travel-hotel-stars sphinx-stars" role="img" aria-label="{__("sphinx_holidays.stars_rating", ["[rating]" => $result.star_rating])|escape:html}">{"★"|str_repeat:$result.star_rating}</span>
                        {/if}
                        {if $result.destination}
                            <span class="travel-offer-location sphinx-offer-location">{$result.destination|escape:html}</span>
                        {/if}
                    </div>
                </div>

                {* Offer details *}
                <div class="travel-offer-details sphinx-offer-details">
                    {* A multi-room search returns ONE offer for the whole party:
                       list every room with its guests, not just the first name *}
                    {if $result.room_lines && $result.room_lines|count > 1}
                        <ol class="travel-offer-rooms sphinx-offer-rooms">
                            {foreach from=$result.room_lines item=sx_room name=sx_rooms}
                                <li class="travel-offer-rooms__item">
                                    <span class="travel-offer-rooms__label">{__("sphinx_holidays.sx_room", ["[num]" => $smarty.foreach.sx_rooms.iteration])}</span>
                                    <span class="travel-offer-rooms__name">{$sx_room.name|default:$result.room_name|escape:html}</span>
                                    <span class="travel-offer-rooms__guests">{if $sx_room.adults == 1}{__("sphinx_holidays.sx_adults_one")}{else}{__("sphinx_holidays.sx_adults_many", ["[count]" => $sx_room.adults])}{/if}{if $sx_room.children == 1} + {__("sphinx_holidays.sx_children_one")}{elseif $sx_room.children > 1} + {__("sphinx_holidays.sx_children_many", ["[count]" => $sx_room.children])}{/if}</span>
                                </li>
                            {/foreach}
                        </ol>
                    {else}
                        <div class="travel-offer-room sphinx-offer-room">
                            {$result.room_name|default:$result.room_type|escape:html}
                        </div>
                    {/if}
                    <div class="travel-offer-board sphinx-offer-board">
                        {$result.board_name|default:$result.board_type|escape:html}
                    </div>
                    <div class="travel-offer-dates sphinx-offer-dates">
                        {$sphinx_search_params.check_in|date_format:"%d.%m.%Y"} - {$sphinx_search_params.check_out|date_format:"%d.%m.%Y"}
                        ({$sphinx_search_params.nights} {__("travel_core.nights")|default:"nights"})
                    </div>
                    {if $result.confirmation == 'immediate'}
                        <span class="travel-offer-badge--instant">✓ {__("sphinx_holidays.instant_confirmation")|default:"Instant confirmation"}</span>
                    {/if}
                    {* Terms modal trigger — offer_id read from the card wrapper's data-offer-id *}
                    <a href="#" class="travel-terms-link sphinx-terms-link">{__("sphinx_holidays.cancellation_and_payment_terms")|default:"Condiții de Plată și Anulare"}</a>
                </div>

                {* Price and action *}
                <div class="travel-offer-price-action sphinx-offer-price-action">
                    <div class="travel-offer-price sphinx-offer-price">
                        <span class="travel-price-amount sphinx-price-amount">{$result.price|number_format:2:",":"."}</span>
                        <span class="travel-price-currency sphinx-price-currency">{$sphinx_search_params.currency|default:'EUR'}</span>
                        {if $sphinx_search_params.nights > 0}
                            <span class="travel-price-per-night sphinx-price-per-night">
                                {($result.price / $sphinx_search_params.nights)|number_format:2:",":"."} / {__("sphinx_holidays.per_night")|default:"night"}
                            </span>
                        {/if}
                        {if $result.room_lines && $result.room_lines|count > 1}
                            <span class="travel-price-rooms sphinx-price-rooms">{__("sphinx_holidays.sx_total_for_rooms", ["[count]" => $result.room_lines|count])}</span>
                        {/if}
                        <span class="travel-price-includes">{__("sphinx_holidays.includes_taxes")|default:"Includes taxes and commissions"}</span>
                    </div>
                    {* Offer room/board names ride the Book-now URL so the
                       booking form + add_to_cart can fall back to them when
                       the verify response omits the names (display-only). *}
                    {$_sx_room_q = $result.room_name|default:$result.room_type|default:''|escape:url}
                    {$_sx_board_q = $result.board_name|default:$result.board_type|default:''|escape:url}
                    <a href="{"sphinx_booking.booking_form?offer_id=`$result.offer_id`&hotel_id=`$result.hotel_id`&product_id=`$result.product_id`&check_in=`$sphinx_search_params.check_in`&check_out=`$sphinx_search_params.check_out`&adults=`$sphinx_search_params.adults`&children=`$sphinx_search_params.children`&children_ages=`$sphinx_search_params.children_ages`&rooms=`$sphinx_search_params.rooms`&room_name=`$_sx_room_q`&board_name=`$_sx_board_q`"|fn_url}"
                       class="travel-offer-book-btn sphinx-offer-book-btn">
                        {__("sphinx_holidays.book_now")|default:"Book now"}
                    </a>
                </div>

            </div>
        {/foreach}
    </div>

    {* No-results state — always in the DOM so the async poller (which runs when
       status='pending') can reveal it once polling completes with 0 offers.
       Visible immediately only when a server-side search already finished empty
       (completed/error); hidden while pending/idle or when offers exist. *}
    {assign var="_sx_show_empty" value=(!$sphinx_search_results && ($sphinx_search_status == 'completed' || $sphinx_search_status == 'error'))}
    <div class="travel-no-results sphinx-no-results" id="sphinx-no-results"{if !$_sx_show_empty} style="display: none;"{/if}
         data-alt-heading="{__("sphinx_holidays.try_nearby_dates")|default:"Try nearby dates:"|escape:html}"
         data-alt-from="{__("sphinx_holidays.alt_from")|default:"from"|escape:html}">
        <p>{__("sphinx_holidays.no_results")|default:"No availability for the selected dates. Please try different dates."}</p>
        <div id="sphinx-alt-dates" class="travel-alt-dates" style="display: none;"></div>

        {* "Contact me when available" — stored in the SHARED travel registry
           for INTERNAL follow-up only; no Sphinx API request is created. *}
        <div class="travel-request-box">
            <h4>{__("travel_core.request_alternatives_title")|default:"Can't find what you're looking for?"}</h4>
            <p>{__("travel_core.request_alternatives_desc")|default:"Leave your contact details and we'll get back to you with alternatives for your dates."}</p>
            <form method="post" action="{""|fn_url}" name="sphinx_alt_request_form">
                <input type="hidden" name="dispatch" value="sphinx_booking.request_alternatives">
                <input type="hidden" name="security_hash" value="{$security_hash}">
                <input type="hidden" name="hotel_id" value="{$sphinx_search_params.hotel_id|escape:html}">
                <input type="hidden" name="hotel_name" value="{$sphinx_hotel_name|escape:html}">
                <input type="hidden" name="product_id" value="{$sphinx_search_params.product_id|escape:html}">
                <input type="hidden" name="check_in" value="{$sphinx_search_params.check_in|escape:html}">
                <input type="hidden" name="check_out" value="{$sphinx_search_params.check_out|escape:html}">
                <input type="hidden" name="nights" value="{$sphinx_search_params.nights|escape:html}">
                <input type="hidden" name="adults" value="{$sphinx_search_params.adults|escape:html}">
                <input type="hidden" name="children" value="{$sphinx_search_params.children|escape:html}">
                <input type="hidden" name="children_ages" value="{$sphinx_search_params.children_ages|escape:html}">
                <input type="hidden" name="rooms" value="{$sphinx_search_params.rooms|escape:html}">
                {* Honeypot — humans never see it, bots fill everything *}
                <input type="text" name="website" value="" tabindex="-1" autocomplete="off" aria-hidden="true" style="position:absolute;left:-9999px;">
                <div class="travel-request-grid">
                    <input type="email" name="contact_email" required placeholder="{__("email")|default:"E-mail"}">
                    <input type="tel" name="contact_phone" placeholder="{__("phone")|default:"Phone"}">
                    <button type="submit" class="travel-offer-book-btn">{__("travel_core.send_request")|default:"Send"}</button>
                </div>
            </form>
        </div>
    </div>

    {* On-demand payment/cancellation terms modal (sphinx_booking.offer_terms).
       INSIDE .travel-search-results-page on purpose: the inline search copies
       everything after the form wrapper into the product page, so the modal
       must travel WITH the results. Its handlers are document-delegated in
       search-results.js — the swapped-in copy needs no rebinding. *}
    <div id="sphinx-terms-modal" class="travel-terms-modal" role="dialog" aria-modal="true" aria-labelledby="sphinx-terms-modal-title" style="display: none;">
        <div class="travel-terms-modal__box">
            <div class="travel-terms-modal__head">
                <h3 id="sphinx-terms-modal-title">{__("sphinx_holidays.cancellation_and_payment_terms")|default:"Condiții de Plată și Anulare"}</h3>
                <button type="button" class="travel-terms-modal__close" aria-label="{__("close")|default:"Close"}">&times;</button>
            </div>
            <div class="travel-terms-modal__body" id="sphinx-terms-modal-body"></div>
        </div>
    </div>

    {* The poll params + labels ride #sphinx-results-container's
       data-client-params / data-client-config attributes (built in
       search.php) — attributes travel WITH the swapped node, unlike the old
       window.__sphinx* globals whose inline script tag did not reliably
       re-execute after the engine's inline swap. *}

</div>

{* Poll + terms-modal behavior lives in a real JS file (vitest imports it
   directly — tests/js/sphinx-search-results.test.mjs); the config objects
   above are its only Smarty-fed input. *}
{script src="js/addons/sphinx_holidays/search-results.js"}


{* Loading/skeleton styles live in travel_core's search-results.css
   (travel-skeleton-*, travel-spinner, travel-loading-message). *}
