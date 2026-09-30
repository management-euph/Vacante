{*
 * Eurosite Touring — a hotel's live offers (its product page's search).
 *
 * Structure contract (travel_core shared search UI):
 *   .travel-search-results-page > .travel-search-form-wrapper >
 *   {$booking_engine_html nofilter}, then one .travel-offer-card per offer.
 * The engine's inline-results mode swaps the whole page root via AJAX, so
 * the #info-modal shell lives INSIDE it and search-results.js re-arms via
 * delegated handlers.
 *
 * One hotel only: the product page's booking engine fetches this page with
 * the hotel's code and product (hotel_id, product_id) and shows its results
 * inline; there is no destination search.
 *}

{include file="addons/travel_core/components/travel_i18n.tpl"}

<div class="travel-search-results-page eurosite-search-results-page">

    <div class="travel-search-form-wrapper eurosite-search-form-wrapper">
        {$booking_engine_html nofilter}
    </div>

    {if $eurosite_search_error}
        <div class="eurosite-notice eurosite-notice--error">{$eurosite_search_error|escape:html}</div>
    {elseif $eurosite_searched && !$eurosite_results}
        <div class="eurosite-notice">
            {__("eurosite.no_offers_found", ["[default]" => "No offers found for the selected destination and dates. Try different dates."])}
        </div>
    {/if}

    {foreach from=$eurosite_results item=hotel}
        <div class="eurosite-hotel-group">
            <div class="eurosite-hotel-header">
                {if $hotel.image}
                    <img class="eurosite-hotel-image" src="{$hotel.image}" alt="{$hotel.name|escape:html}" loading="lazy" />
                {/if}
                <div class="eurosite-hotel-title">
                    <h2>{$hotel.name|escape:html}
                        {if $hotel.category}<span class="eurosite-stars">{section name=s loop=$hotel.category}★{/section}</span>{/if}
                    </h2>
                    <div class="eurosite-hotel-city">{$hotel.location|default:$hotel.city_name|escape:html}</div>
                    {if $hotel.description}
                        <div class="eurosite-hotel-desc">{$hotel.description|strip_tags|truncate:220|escape:html}</div>
                    {/if}
                </div>
            </div>

            {foreach from=$hotel.offers item=offer}
                {assign var="modal_id" value="`$hotel.product_code`-`$offer.row_id`"}
                <div class="travel-offer-card eurosite-offer-card">
                    <div class="travel-offer-details">
                        {* One offer for the whole party: a room per requested room *}
                        {if $offer.room_lines|count > 1}
                            <ol class="travel-offer-rooms eurosite-offer-rooms">
                                {foreach from=$offer.room_lines item=es_room name=es_rooms}
                                    <li class="travel-offer-rooms__item">
                                        <span class="travel-offer-rooms__label">{__("eurosite.room_n", ["[num]" => $smarty.foreach.es_rooms.iteration])}</span>
                                        <span class="travel-offer-rooms__name">{$es_room.room_name|escape:html}</span>
                                        <span class="travel-offer-rooms__guests">{if $es_room.adults == 1}{__("eurosite.adults_one")}{else}{__("eurosite.adults_many", ["[count]" => $es_room.adults])}{/if}{if $es_room.children == 1} + {__("eurosite.children_one")}{elseif $es_room.children > 1} + {__("eurosite.children_many", ["[count]" => $es_room.children])}{/if}</span>
                                    </li>
                                {/foreach}
                            </ol>
                        {else}
                            {foreach from=$offer.rooms item=room}
                                <div class="travel-offer-room">{$room.name|escape:html}{if $room.quantity && $room.quantity != '1'} &times; {$room.quantity}{/if}</div>
                            {/foreach}
                        {/if}
                        <div class="eurosite-offer-dates">{$offer.check_in} &rarr; {$offer.check_out}</div>
                        {if $offer.grila}<div class="eurosite-offer-grila">{$offer.grila|escape:html}</div>{/if}
                    </div>
                    <div class="travel-offer-details">
                        {foreach from=$offer.meals item=meal}
                            <div class="travel-offer-board">{$meal.name|escape:html}</div>
                        {/foreach}
                        {if !$offer.bookable}
                            <div class="eurosite-offer-availability eurosite-availability--stop">{__("eurosite.stop_sale", ["[default]" => "Stop sale — not bookable"])}</div>
                        {elseif $offer.availability}
                            <div class="eurosite-offer-availability {if $offer.availability == 'OnRequest'}eurosite-availability--request{/if}">{$offer.availability|escape:html}</div>
                        {/if}
                        {if $offer.bookable}
                        <div class="eurosite-offer-info-row">
                            <a href="#" class="eurosite-info-link" data-offer-key="{$offer.key}" data-modal-id="{$modal_id}">
                                {__("eurosite.cancellation_and_payment_terms", ["[default]" => "Condiții de Anulare și Plată"])}
                            </a>
                        </div>
                        <div id="modal-content-{$modal_id}" style="display: none;" data-offer-key="{$offer.key}"></div>
                        {/if}
                    </div>
                    <div class="travel-offer-price-action">
                        <div class="travel-offer-price">
                            <span class="travel-price-amount">{$offer.price} {$offer.currency}</span>
                            {if $offer.room_lines|count > 1}
                                <span class="travel-price-rooms">{__("eurosite.total_for_rooms", ["[count]" => $offer.room_lines|count])}</span>
                            {/if}
                        </div>
                        {if $offer.bookable}
                        <a class="ty-btn ty-btn__primary travel-offer-book-btn"
                           href="{"eurosite_booking.booking_form?offer_key=`$offer.key`&return_product_id=`$eurosite_return_product_id`"|fn_url}">
                            {__("eurosite.book_now", ["[default]" => "Rezervă"])}
                        </a>
                        {/if}
                    </div>
                </div>
            {/foreach}
        </div>
    {/foreach}

    {* Modal shell — inside the results root so the inline AJAX swap carries it *}
    <div id="info-modal" class="eurosite-info-modal" style="display: none;">
        <div class="eurosite-info-modal__dialog">
            <button type="button" class="eurosite-info-modal__close" aria-label="close">&times;</button>
            <h3>{__("eurosite.cancellation_and_payment_terms", ["[default]" => "Condiții de Anulare și Plată"])}</h3>
            <div id="info-modal-content"></div>
        </div>
    </div>

</div>

{script src="js/addons/eurosite/search-results.js"}
