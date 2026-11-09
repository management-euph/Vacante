{*
 * Sphinx Circuit Booking Form
 *
 * The shared booking page (travel_core booking-pages.css), as a hotel's and
 * a package's: the three-step bar; the guest form on the left; on the right
 * the shared summary (travel_booking_sidebar, built by the controller): the
 * circuit and its picture, the departure and return dates, the rooms and
 * meal plan, the flights, what the price includes and leaves out, the price,
 * and the cancellation and payment terms. A phone folds the summary into a
 * bar at the top and keeps one button in the bar at the bottom.
 *
 * @package SphinxHolidays
 * @since 1.1.0
 *}

{if $sphinx_circuit_booking}

{* Client strings for the shared booking JS (inline field messages, DOB
   checks): without them the messages fell back to English. *}
{include file="addons/travel_core/components/travel_i18n.tpl"}

<div class="travel-booking-page sphinx-booking-form-page sphinx-circuit-booking">

    {include file="addons/travel_core/components/booking_steps.tpl"}

    <div class="travel-booking-layout">
        {* The deposit choice, where offered, is the form's (booking_pay_choice.tpl):
           the summary leaves its own split box out. *}
        {include file="addons/travel_core/components/booking_sidebar.tpl" tbs_deposit_addon="sphinx_holidays" tbs_deposit_form="sphinx-circuit-booking-form"}

        <div class="travel-booking-col-main">

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
        <input type="hidden" name="rooms_data" value="{$sphinx_circuit_booking.occupancy_json|escape:html}">
        <input type="hidden" name="total_price" value="{$sphinx_circuit_booking.total_price}">
        <input type="hidden" name="base_price" value="{$sphinx_circuit_booking.base_price}">
        <input type="hidden" name="currency" value="{$sphinx_circuit_booking.currency}">
        <input type="hidden" name="rooms_json" value="{$sphinx_circuit_booking.rooms|json_encode|escape:html}">

        {* Guest details *}
        <div class="travel-form-section guest-names-section">
            <h3>{__("travel_core.who_is_staying")}</h3>
            {* With a flight, the names go to the airline: the amber warning. *}
            {$cir_airline = !empty($sphinx_circuit_booking.flight)}
            {include file="addons/travel_core/components/booking_guest_hint.tpl" gh_airline=$cir_airline}

            {* Guest name + DOB cards — shared travel_core component (same markup
               contract all sphinx forms post: guests[room{N}_{type}_{i}][...]).
               rooms_data is normalized by the controller (CartService::
               normalizeRoomsForDisplay), incl. the one-room fallback the old
               duplicated {else} branch existed for. Adult DOB stays REQUIRED
               (circuit manifests need it); the age guard arms the client-side
               DOB-vs-priced-age check to match the server gate. The
               booking wants every traveller's gender (m/f), children too. *}
            {include file="addons/travel_core/components/booking_guest_cards.tpl"
                     guest_rooms=$sphinx_circuit_booking.rooms_data
                     guest_num_rooms=$sphinx_circuit_booking.rooms_data|count
                     guest_label_prefix="travel_core"
                     guest_extra_class="sphinx-room-header"
                     show_adult_dob=true
                     adult_dob_required=true
                     child_dob_required=true
                     guard_expected_ages=true
                     guest_gender_options=$sphinx_gender_options
                     guest_child_gender_options=$sphinx_gender_options}
        </div>

        {* Optional excursions and services, as whole-row cards: the ticked
           ones turn light green with a "Selectat" badge; a mandatory one is
           ticked and fixed. *}
        {if $sphinx_circuit_booking.additional_services}
            {* A ticked service moves the total, here and in the summary, the bars
               and the button (booking-conditions.js): data-base is the total as
               the page opened, data-money how the store prints it. *}
            {$trip_money = $sphinx_trip_money|default:[]}
            <section class="travel-form-section travel-extras" aria-labelledby="sphinx-circuit-extras"{if $trip_money.base|default:0 > 0} data-travel-extras data-base="{$trip_money.base}" data-money-symbol="{$trip_money.format.symbol|default:""|escape:html}" data-money-after="{if $trip_money.format.after|default:false}1{else}0{/if}" data-money-decimals="{$trip_money.format.decimals|default:0}" data-money-decimal="{$trip_money.format.decimal|default:"."|escape:html}" data-money-thousands="{$trip_money.format.thousands|default:""|escape:html}" data-total-label="{__("travel_core.total_price")|escape:html}"{/if}>
                <h3 id="sphinx-circuit-extras">{__("sphinx_holidays.additional_services")|default:"Additional Services"}</h3>
                {foreach $sphinx_circuit_booking.additional_services as $service}
                    <label class="travel-extra{if $service.type == 'mandatory'} travel-extra--fixed{/if}">
                        {if $service.type == 'mandatory'}
                            <input type="checkbox" name="services[]" value="{$service.code}" checked disabled>
                        {else}
                            <input type="checkbox" name="services[]" value="{$service.code}"{if $service.display_amount|default:0 > 0} data-amount="{$service.display_amount}"{/if} {if $service.selected}checked{/if}>
                        {/if}
                        <span class="travel-extra__text">
                            <span class="travel-extra__name">{$service.title|escape:html}{if $service.type == 'mandatory'}<span class="travel-extra__tag">{__("sphinx_holidays.mandatory")|default:"Mandatory"}</span>{else}<span class="travel-extra__sel">{__("travel_core.option_selected")}</span>{/if}</span>
                            {if $service.description}<span class="travel-extra__note">{$service.description|escape:html}</span>{/if}
                        </span>
                        <span class="travel-extra__price">{if $service.display_price|default:""}{$service.display_price|escape:html}{elseif $service.price > 0}+{$service.price|number_format:2:",":"."} {$service.currency}{/if}</span>
                    </label>
                {/foreach}
                <p class="travel-sr-only" aria-live="polite" data-travel-extras-live></p>
            </section>
        {/if}

        {* Contact (email/phone) is NOT collected here: CS-Cart checkout already
           collects it, and the booking submission reads it from the order. *}

        {* "How would you like to pay?" where the store offers deposits
           (travel_core DepositPolicy) and the quote's payment terms allow one:
           the radios post only pay_mode; add-to-cart works the deposit out
           again from the terms of what is booked. *}
        {include file="addons/travel_core/components/booking_pay_choice.tpl" pc_addon="sphinx_holidays" pc_form="sphinx-circuit-booking-form"}

        {* The one main button, with the total; "Adaugă în coș" unless the store
           skips the cart. The way back leads to the circuit's page. *}
        {include file="addons/travel_core/components/booking_form_actions.tpl"
            fa_addon="sphinx_holidays"
            fa_label_key="sphinx_holidays.add_to_cart_btn"
            fa_back_url=$travel_booking_sidebar.change_url|default:""
            fa_back_label=__("travel_core.back_to_offer")
            fa_button_class="sphinx-offer-book-btn"}

        {* "What are my booking conditions?" (its link sits in the summary's
           cancellation card). *}
        {include file="addons/travel_core/components/booking_conditions_modal.tpl"}
    </form>

        </div>{* /travel-booking-col-main *}
    </div>{* /travel-booking-layout *}

    {include file="addons/travel_core/components/booking_mobile_bar.tpl" mb_form_id="sphinx-circuit-booking-form" mb_label=fn_travel_core_booking_cta_label("sphinx_holidays", "sphinx_holidays.add_to_cart_btn")}
</div>

{/if}
