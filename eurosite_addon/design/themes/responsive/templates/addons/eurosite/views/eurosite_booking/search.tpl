{*
 * Eurosite Touring — search results (Cazari individuale).
 *
 * Structure contract (travel_core shared search UI):
 *   .travel-search-results-page > .travel-search-form-wrapper >
 *   {$booking_engine_html nofilter}, then one .travel-offer-card per offer.
 * The engine's inline-results mode swaps the whole page root via AJAX, so
 * the #info-modal shell lives INSIDE it and search-results.js re-arms via
 * delegated handlers.
 *
 * Destination picking: eurosite search is country/city-driven (no CS-Cart
 * hotel products). The selects write {country, city} into the engine
 * mount's data-extra-params (search-form.js) — the additive travel_core
 * engine contract.
 *}

{include file="addons/travel_core/components/travel_i18n.tpl"}

<div class="travel-search-results-page eurosite-search-results-page">

    <div class="travel-search-form-wrapper eurosite-search-form-wrapper">
        <div class="eurosite-destination-picker">
            <div class="eurosite-picker-field">
                <label for="eurosite-country">{__("eurosite.country", ["[default]" => "Country"])}</label>
                <select id="eurosite-country">
                    <option value="">{__("eurosite.pick_country", ["[default]" => "— country —"])}</option>
                    {foreach from=$eurosite_destinations item=dest}
                        <option value="{$dest.code}" {if $dest.code == $eurosite_params.country}selected{/if}>{$dest.name|escape:html}</option>
                    {/foreach}
                </select>
            </div>
            <div class="eurosite-picker-field">
                <label for="eurosite-city">{__("eurosite.city", ["[default]" => "City / resort"])}</label>
                <select id="eurosite-city">
                    <option value="">{__("eurosite.pick_city", ["[default]" => "— city —"])}</option>
                    {foreach from=$eurosite_destinations item=dest}
                        {foreach from=$dest.cities item=city}
                            <option value="{$city.code}" data-country="{$dest.code}"
                                    {if $city.code == $eurosite_params.city}selected{/if}
                                    {if $dest.code != $eurosite_params.country}hidden{/if}>{$city.name|escape:html}</option>
                        {/foreach}
                    {/foreach}
                </select>
            </div>
        </div>
        {if !$eurosite_destinations}
            <div class="eurosite-notice eurosite-notice--warning">
                {__("eurosite.no_destinations_configured", ["[default]" => "No destinations are enabled yet. Please check back soon."])}
            </div>
        {/if}
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
                        {foreach from=$offer.rooms item=room}
                            <div class="travel-offer-room">{$room.name|escape:html}{if $room.quantity && $room.quantity != '1'} &times; {$room.quantity}{/if}</div>
                        {/foreach}
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
                        </div>
                        {if $offer.bookable}
                        <a class="ty-btn ty-btn__primary travel-offer-book-btn"
                           href="{"eurosite_booking.booking_form?offer_key=`$offer.key`"|fn_url}">
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

{script src="js/addons/eurosite/search-form.js"}
{script src="js/addons/eurosite/search-results.js"}
