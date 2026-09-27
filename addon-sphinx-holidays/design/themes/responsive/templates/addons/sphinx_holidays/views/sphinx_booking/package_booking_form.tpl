{*
 * Sphinx Package Booking Form
 *
 * Shows verified package details (hotel, transport, services),
 * optional services selection, and guest entry form.
 * Styled by travel_core's booking-pages.css (.travel-booking-page contract).
 *
 * @package SphinxHolidays
 * @since 1.2.0
 *}



{if $sphinx_package_booking}

<div class="travel-booking-page sphinx-booking-form-page sphinx-package-booking">

    {* Package summary *}
    <div class="travel-booking-summary sphinx-booking-summary">
        <div class="travel-booking-row">
            <div class="travel-booking-main">
                <h2>{$sphinx_package_booking.hotel_name|escape:html}</h2>
                {if $sphinx_package_booking.destination_name}
                    <div class="travel-booking-sub">{$sphinx_package_booking.destination_name|escape:html}</div>
                {/if}
                <div class="travel-booking-meta">
                    <span><strong>{__("travel_core.check_in")|default:"Check-in"}:</strong> {$sphinx_package_booking.check_in|date_format:"%d.%m.%Y"}</span>
                    <span><strong>{__("travel_core.check_out")|default:"Check-out"}:</strong> {$sphinx_package_booking.check_out|date_format:"%d.%m.%Y"}</span>
                    {if $sphinx_package_booking.meal_type}
                        <span><strong>{__("travel_core.meal")|default:"Meal"}:</strong> {$sphinx_package_booking.meal_type|escape:html}</span>
                    {/if}
                </div>

                {* Room list *}
                {if $sphinx_package_booking.rooms}
                    <div class="travel-booking-roomlist">
                        {foreach $sphinx_package_booking.rooms as $room}
                            <span>{$room.name|escape:html}</span>{if !$room@last}, {/if}
                        {/foreach}
                    </div>
                {/if}

                {* Labels *}
                {if $sphinx_package_booking.labels}
                    <div class="travel-booking-roomlist">
                        {foreach $sphinx_package_booking.labels as $label}
                            <span class="travel-label-chip">{$label.name|escape:html}</span>
                        {/foreach}
                    </div>
                {/if}
            </div>
            <div class="travel-booking-price-col">
                <div class="travel-price-total">
                    {$sphinx_package_booking.total_price|number_format:2:",":"."} {$sphinx_package_booking.currency}
                </div>
                <div class="travel-price-label">{__("sphinx_holidays.total_price")|default:"Total price"}</div>
                {if $sphinx_package_booking.confirmation == 'immediate'}
                    <div class="travel-instant-note">{__("sphinx_holidays.instant_confirmation")|default:"Instant confirmation"}</div>
                {/if}
            </div>
        </div>
    </div>

    {* Flight info *}
    {if $sphinx_package_booking.flight && ($sphinx_package_booking.flight.outbound || $sphinx_package_booking.flight.inbound)}
        <div class="travel-info-card sphinx-flight-info">
            <h3 class="travel-section-title"><i class="icon-plane"></i> {__("sphinx_holidays.flight_info")|default:"Flight Information"}</h3>
            <div class="travel-info-grid">
                {if $sphinx_package_booking.flight.outbound}
                    <div class="travel-info-col">
                        <strong>{__("sphinx_holidays.outbound")|default:"Outbound"}</strong>
                        {foreach $sphinx_package_booking.flight.outbound as $segment}
                            <div>{$segment.departure.name|escape:html} &rarr; {$segment.arrival.name|escape:html}</div>
                            <div class="travel-info-detail">
                                {$segment.airline.name|escape:html} {$segment.flight_number}
                                {if $segment.departure.datetime} &mdash; {$segment.departure.datetime|date_format:"%d.%m %H:%M"}{/if}
                            </div>
                        {/foreach}
                    </div>
                {/if}
                {if $sphinx_package_booking.flight.inbound}
                    <div class="travel-info-col">
                        <strong>{__("sphinx_holidays.return")|default:"Return"}</strong>
                        {foreach $sphinx_package_booking.flight.inbound as $segment}
                            <div>{$segment.departure.name|escape:html} &rarr; {$segment.arrival.name|escape:html}</div>
                            <div class="travel-info-detail">
                                {$segment.airline.name|escape:html} {$segment.flight_number}
                                {if $segment.departure.datetime} &mdash; {$segment.departure.datetime|date_format:"%d.%m %H:%M"}{/if}
                            </div>
                        {/foreach}
                    </div>
                {/if}
            </div>
        </div>
    {/if}

    {* Bus info *}
    {if $sphinx_package_booking.bus}
        <div class="travel-info-card">
            <h3 class="travel-section-title"><i class="icon-truck"></i> {__("sphinx_holidays.bus_transport")|default:"Bus Transport"}</h3>
            {foreach $sphinx_package_booking.bus as $bus_segment}
                <div>{$bus_segment.departure.name|escape:html} &rarr; {$bus_segment.arrival.name|escape:html}</div>
            {/foreach}
        </div>
    {/if}

    {* Transfers *}
    {if $sphinx_package_booking.transfers}
        <div class="travel-info-card">
            <h3 class="travel-section-title">{__("sphinx_holidays.transfers")|default:"Transfers"}</h3>
            {foreach $sphinx_package_booking.transfers as $transfer}
                <div class="travel-booking-sub">
                    <strong>{$transfer.title|escape:html}</strong>
                    <span class="travel-info-detail">({$transfer.from|escape:html} &rarr; {$transfer.to|escape:html})</span>
                </div>
            {/foreach}
        </div>
    {/if}

    {* Included / not included services *}
    {if $sphinx_package_booking.included_services || $sphinx_package_booking.not_included_services}
        <div class="travel-included-row">
            {if $sphinx_package_booking.included_services}
                <div class="travel-included-panel">
                    <h4>{__("sphinx_holidays.included")|default:"Included"}</h4>
                    <ul>
                        {foreach $sphinx_package_booking.included_services as $svc}
                            <li>{$svc|escape:html}</li>
                        {/foreach}
                    </ul>
                </div>
            {/if}
            {if $sphinx_package_booking.not_included_services}
                <div class="travel-included-panel travel-included-panel--negative">
                    <h4>{__("sphinx_holidays.not_included")|default:"Not Included"}</h4>
                    <ul>
                        {foreach $sphinx_package_booking.not_included_services as $svc}
                            <li>{$svc|escape:html}</li>
                        {/foreach}
                    </ul>
                </div>
            {/if}
        </div>
    {/if}

    {* Guest entry form *}
    <form action="{"sphinx_booking.package_add_to_cart"|fn_url}" method="post" id="sphinx-package-booking-form">
        <input type="hidden" name="offer_id" value="{$sphinx_package_booking.offer_id}">
        <input type="hidden" name="hotel_id" value="{$sphinx_package_booking.hotel_id}">
        <input type="hidden" name="hotel_name" value="{$sphinx_package_booking.hotel_name|escape:html}">
        <input type="hidden" name="check_in" value="{$sphinx_package_booking.check_in}">
        <input type="hidden" name="check_out" value="{$sphinx_package_booking.check_out}">
        <input type="hidden" name="transport_type" value="{$sphinx_package_booking.transport_type}">
        <input type="hidden" name="adults" value="{$sphinx_package_booking.adults}">
        <input type="hidden" name="children" value="{$sphinx_package_booking.children}">
        <input type="hidden" name="children_ages" value="{$sphinx_package_booking.children_ages}">
        <input type="hidden" name="num_rooms" value="{$sphinx_package_booking.num_rooms}">
        <input type="hidden" name="total_price" value="{$sphinx_package_booking.total_price}">
        <input type="hidden" name="base_price" value="{$sphinx_package_booking.base_price}">
        <input type="hidden" name="currency" value="{$sphinx_package_booking.currency}">
        {* no hidden "nights" input: the old |strtotime|cat|array_diff chain was a
           compile/TypeError bomb; package_add_to_cart computes nights from
           check_in/check_out when the posted value is absent *}
        {if $sphinx_package_booking.rooms}
            <input type="hidden" name="rooms_json" value="{$sphinx_package_booking.rooms|json_encode|escape:html}">
            <input type="hidden" name="room_name" value="{$sphinx_package_booking.rooms.0.name|escape:html}">
        {/if}
        <input type="hidden" name="board_name" value="{$sphinx_package_booking.meal_type|escape:html}">

        {* Optional services *}
        {if $sphinx_package_booking.additional_services}
            <div class="travel-services sphinx-services-section">
                <h3 class="travel-section-title"><i class="icon-cog"></i> {__("sphinx_holidays.additional_services")|default:"Additional Services"}</h3>
                {foreach $sphinx_package_booking.additional_services as $service}
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

        {* Guest details per room *}
        <div class="guest-names-section">
            <h3 class="travel-section-title"><i class="icon-user"></i> {__("travel_core.who_is_staying")}</h3>

            {* Guest name + DOB cards — shared travel_core component (same markup
               contract all sphinx forms post: guests[room{N}_{type}_{i}][...]).
               rooms_data is normalized by the controller (CartService::
               normalizeRoomsForDisplay), incl. the one-room fallback the old
               duplicated {else} branch existed for. Adult DOB stays REQUIRED
               (package manifests need it); the age guard arms the client-side
               DOB-vs-priced-age check to match the server gate. *}
            {include file="addons/travel_core/components/booking_guest_cards.tpl"
                     guest_rooms=$sphinx_package_booking.rooms_data
                     guest_num_rooms=$sphinx_package_booking.rooms_data|count
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
