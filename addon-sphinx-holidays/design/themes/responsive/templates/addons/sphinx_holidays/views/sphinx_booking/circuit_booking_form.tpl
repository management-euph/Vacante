{*
 * Sphinx Circuit Booking Form
 *
 * Shows circuit quote details, optional services selection,
 * and guest entry form.
 *
 * @package SphinxHolidays
 * @since 1.1.0
 *}



{if $sphinx_circuit_booking}

<div class="travel-booking-page sphinx-booking-form-page sphinx-circuit-booking">

    {* Circuit summary *}
    <div class="travel-booking-summary sphinx-booking-summary">
        <div class="travel-booking-row">
            {if $sphinx_circuit_booking.image}
                <img src="{$sphinx_circuit_booking.image}" alt="{$sphinx_circuit_booking.title|escape:html}" class="travel-booking-thumb">
            {/if}
            <div class="travel-booking-main">
                <h2>{$sphinx_circuit_booking.title|escape:html}</h2>
                <div class="travel-booking-meta">
                    <span><strong>{__("sphinx_holidays.departure")|default:"Departure"}:</strong> {$sphinx_circuit_booking.departure_date|date_format:"%d.%m.%Y"}</span>
                    <span><strong>{__("sphinx_holidays.duration")|default:"Duration"}:</strong> {$sphinx_circuit_booking.duration_days} {__("travel_core.days")|default:"days"} / {$sphinx_circuit_booking.duration_nights} {__("travel_core.nights")|default:"nights"}</span>
                    {if $sphinx_circuit_booking.transport_type}
                        <span><strong>{__("sphinx_holidays.transport")|default:"Transport"}:</strong> {$sphinx_circuit_booking.transport_type|capitalize|escape:html}</span>
                    {/if}
                    {if $sphinx_circuit_booking.meal_type}
                        <span><strong>{__("sphinx_holidays.meal")|default:"Meal"}:</strong> {$sphinx_circuit_booking.meal_type|escape:html}</span>
                    {/if}
                </div>
                {if $sphinx_circuit_booking.departure_name}
                    <div class="travel-booking-roomlist">
                        {__("sphinx_holidays.departure_from")|default:"Departure from"}: {$sphinx_circuit_booking.departure_name|escape:html}
                    </div>
                {/if}
            </div>
            <div class="travel-booking-price-col">
                <div class="travel-price-total">
                    {$sphinx_circuit_booking.total_price|number_format:2:",":"."} {$sphinx_circuit_booking.currency}
                </div>
                <div class="travel-price-label">{__("sphinx_holidays.total_price")|default:"Total price"}</div>
            </div>
        </div>
    </div>

    {* Flight info (if available) *}
    {if $sphinx_circuit_booking.flight}
        <div class="travel-info-card sphinx-flight-info">
            <h3 class="travel-section-title"><i class="icon-plane"></i> {__("sphinx_holidays.flight_info")|default:"Flight Information"}</h3>
            <div class="travel-info-grid">
                {if $sphinx_circuit_booking.flight.outbound}
                    <div class="travel-info-col">
                        <strong>{__("sphinx_holidays.outbound")|default:"Outbound"}</strong>
                        <div>{$sphinx_circuit_booking.flight.outbound.departure.name|escape:html} &rarr; {$sphinx_circuit_booking.flight.outbound.arrival.name|escape:html}</div>
                        <div class="travel-info-detail">{$sphinx_circuit_booking.flight.outbound.airline.name|escape:html} {$sphinx_circuit_booking.flight.outbound.flight_number}</div>
                    </div>
                {/if}
                {if $sphinx_circuit_booking.flight.inbound}
                    <div class="travel-info-col">
                        <strong>{__("sphinx_holidays.inbound")|default:"Return"}</strong>
                        <div>{$sphinx_circuit_booking.flight.inbound.departure.name|escape:html} &rarr; {$sphinx_circuit_booking.flight.inbound.arrival.name|escape:html}</div>
                        <div class="travel-info-detail">{$sphinx_circuit_booking.flight.inbound.airline.name|escape:html} {$sphinx_circuit_booking.flight.inbound.flight_number}</div>
                    </div>
                {/if}
            </div>
        </div>
    {/if}

    {* Guest entry form *}
    <form action="{"sphinx_booking.circuit_add_to_cart"|fn_url}" method="post" id="sphinx-circuit-booking-form">
        <input type="hidden" name="offer_id" value="{$sphinx_circuit_booking.offer_id}">
        <input type="hidden" name="circuit_id" value="{$sphinx_circuit_booking.circuit_id}">
        <input type="hidden" name="departure_date" value="{$sphinx_circuit_booking.departure_date}">
        <input type="hidden" name="departure_id" value="{$sphinx_circuit_booking.departure_id}">
        <input type="hidden" name="departure_name" value="{$sphinx_circuit_booking.departure_name|escape:html}">
        <input type="hidden" name="title" value="{$sphinx_circuit_booking.title|escape:html}">
        <input type="hidden" name="transport_type" value="{$sphinx_circuit_booking.transport_type}">
        <input type="hidden" name="duration_days" value="{$sphinx_circuit_booking.duration_days}">
        <input type="hidden" name="duration_nights" value="{$sphinx_circuit_booking.duration_nights}">
        <input type="hidden" name="adults" value="{$sphinx_circuit_booking.adults}">
        <input type="hidden" name="children" value="{$sphinx_circuit_booking.children}">
        <input type="hidden" name="children_ages" value="{$sphinx_circuit_booking.children_ages}">
        <input type="hidden" name="total_price" value="{$sphinx_circuit_booking.total_price}">
        <input type="hidden" name="base_price" value="{$sphinx_circuit_booking.base_price}">
        <input type="hidden" name="currency" value="{$sphinx_circuit_booking.currency}">
        <input type="hidden" name="rooms_json" value="{$sphinx_circuit_booking.rooms|json_encode|escape:html}">

        {* Optional services *}
        {if $sphinx_circuit_booking.additional_services}
            <div class="travel-services sphinx-services-section">
                <h3 class="travel-section-title"><i class="icon-cog"></i> {__("sphinx_holidays.additional_services")|default:"Additional Services"}</h3>
                {foreach $sphinx_circuit_booking.additional_services as $service}
                    <div class="travel-service-row">
                        {if $service.type == 'mandatory'}
                            <input type="checkbox" name="services[]" value="{$service.code}" checked disabled>
                            <span class="travel-service-mandatory">({__("sphinx_holidays.mandatory")|default:"Mandatory"})</span>
                        {else}
                            <input type="checkbox" name="services[]" value="{$service.code}" {if $service.selected}checked{/if}>
                        {/if}
                        <div class="travel-service-body">
                            <strong>{$service.title|escape:html}</strong>
                            {if $service.description}
                                <div class="travel-service-desc">{$service.description|escape:html}</div>
                            {/if}
                        </div>
                        <div class="travel-service-price">
                            +{$service.pricing.selling_price|number_format:2:",":"."} {$service.pricing.currency}
                        </div>
                    </div>
                {/foreach}
            </div>
        {/if}

        {* Guest details — reuse the same pattern as hotel booking *}
        <div class="guest-names-section">
            <h3 class="travel-section-title"><i class="icon-user"></i> {__("travel_core.who_is_staying")}</h3>

            {* Guest name + DOB cards — shared travel_core component (same markup
               contract all sphinx forms post: guests[room{N}_{type}_{i}][...]).
               rooms_data is normalized by the controller (CartService::
               normalizeRoomsForDisplay), incl. the one-room fallback the old
               duplicated {else} branch existed for. Adult DOB stays REQUIRED
               (circuit manifests need it); the age guard arms the client-side
               DOB-vs-priced-age check to match the server gate. *}
            {include file="addons/travel_core/components/booking_guest_cards.tpl"
                     guest_rooms=$sphinx_circuit_booking.rooms_data
                     guest_num_rooms=$sphinx_circuit_booking.rooms_data|count
                     guest_label_prefix="travel_core"
                     guest_extra_class="sphinx-room-header"
                     show_adult_dob=true
                     adult_dob_required=true
                     child_dob_required=true
                     guard_expected_ages=true}
        </div>

        {* Contact (email/phone) is NOT collected here: CS-Cart checkout already
           collects it, and the booking submission reads it from the order. *}

        {* Submit *}
        <div class="travel-booking-submit sphinx-booking-submit">
            <button type="submit" class="travel-offer-book-btn sphinx-offer-book-btn">
                {__("sphinx_holidays.add_to_cart_btn")|default:"Add to Cart"}
            </button>
        </div>
    </form>

</div>

{/if}


