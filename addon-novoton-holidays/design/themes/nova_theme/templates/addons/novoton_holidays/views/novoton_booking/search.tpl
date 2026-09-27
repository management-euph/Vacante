{*
 * Novoton Booking Search Results - v2.8.0
 *
 * v2.8.0: Migrated onto travel_core's shared search-results design system.
 * - ONE .travel-offer-card per offer (replaces the v2.7.x dual render: a
 *   mobile card + a desktop table row per offer, glued together by an
 *   embedded <style> block and a fixDisplayStyles() JS resize hack forcing
 *   visibility with !important — all deleted). Mobile stacking is handled
 *   by the shared stylesheet's media query.
 * - Styling: shared classes from travel_core search-results.css + the
 *   novoton-specific classes in novoton-results.css (loaded via the
 *   styles.post.tpl hook). No {style}/<link>/<style> loads in this file.
 * - JS contract preserved: #multi-room-selection + data attrs,
 *   #room-N-price, #total-combined-price, #book-multi-room-btn,
 *   #multi-room-booking-form (multiroom-booking.js), #info-modal +
 *   #modal-content-N (openInfoModal), #request-alternatives-form.
 *}

<div class="travel-search-results-page novoton-search-results-page">

    {* Debug Output *}
    {if $novoton_debug}
    <div class="novoton-debug-panel">
        <h4>DEBUG MODE</h4>
        {foreach from=$novoton_debug item=line}
{$line|default:''}
{/foreach}
    </div>
    {/if}

    {* ===== NO HOTEL HEADER =====
       Results render inline on the product page, which already shows the
       hotel identity (name, stars, location, map) — the old header strip
       (shared hotel_header include, season note, availability badge) was
       removed with it. The standalone page (direct URL / no-JS fallback)
       intentionally renders headerless too. *}

    {* ===== BOOKING FORM — Pre-rendered in controller to prevent OOM ===== *}
    {* Rendered to string in search.php BEFORE heavy results are assigned.
       Smarty has NO scope="local" — {include} always inherits parent scope.
       Pre-rendering avoids scope chain traversal over large result arrays. *}
    <div class="travel-search-form-wrapper novoton-search-form-wrapper">
        {$booking_engine_html nofilter}
    </div>

    {if $novoton_results && $novoton_results|count > 0}

        {* ===== EARLY BOOKING BANNER ===== *}
        {if $active_early_booking || ($early_booking_range && $early_booking_range.max > 0)}
        <div class="novoton-early-banner">
            {if $early_booking_range && $early_booking_range.max > 0}
                {__("novoton_holidays.early_booking")}
                {if $early_booking_range.min == $early_booking_range.max}
                    -{$early_booking_range.max|string_format:"%.0f"}%
                {else}
                    -{$early_booking_range.min|string_format:"%.0f"}% to -{$early_booking_range.max|string_format:"%.0f"}%
                {/if}
            {elseif $active_early_booking}
                -{$active_early_booking.reduction|floatval}% {__("novoton_holidays.early_booking")}
            {/if}
        </div>
        {/if}

        {* Store dates for URLs *}
        {$check_in_date = $novoton_params.check_in}
        {$check_out_date = $novoton_params.check_out}

        {* ===== MULTI-ROOM SELECTION MODE ===== *}
        {if $novoton_params.num_rooms > 1 && $is_multi_room_search}

            {* First check if ALL rooms have available options *}
            {$all_rooms_have_options = true}
            {$rooms_without_options = []}
            {foreach from=$novoton_params.rooms_data item=room key=room_idx}
                {$room_num = $room_idx + 1}
                {assign var="room_key" value=$room_num}
                {if isset($all_room_results.$room_key) && $all_room_results.$room_key|count > 0}
                    {* Room has options - OK *}
                {else}
                    {$all_rooms_have_options = false}
                    {$rooms_without_options[] = ["num" => $room_num, "adults" => $room.adults, "children" => $room.children|default:0]}
                {/if}
            {/foreach}

            {* If any room has no options, show error instead of selection grid *}
            {if !$all_rooms_have_options}
                <div class="novoton-config-error">
                    <div class="novoton-config-error-inner">
                        <h3>
                            {__("novoton_holidays.configuration_not_available")|default:"This room configuration is not available"}
                        </h3>
                        <p>
                            {__("novoton_holidays.no_rooms_for_configuration")|default:"We couldn't find rooms that match all your requirements:"}
                        </p>

                        <div class="novoton-config-problems">
                            {foreach from=$rooms_without_options item=problem_room}
                                <div class="novoton-config-problem">
                                    <strong>Room #{$problem_room.num}:</strong>
                                    <span>
                                        {$problem_room.adults} {if $problem_room.adults == 1}{__("novoton_holidays.adult")|default:"adult"}{else}{__("novoton_holidays.adults")}{/if}{if $problem_room.children > 0} + {$problem_room.children} {if $problem_room.children == 1}{__("novoton_holidays.child")|default:"child"}{else}{__("novoton_holidays.children")}{/if}{/if}
                                    </span>
                                    <div class="novoton-config-problem-note">
                                        {__("novoton_holidays.no_room_types_support")|default:"No room types available for this occupancy"}
                                    </div>
                                </div>
                            {/foreach}
                        </div>

                        {* Show hotel's maximum room capacity (calculated in PHP) *}
                        {if $max_room_capacity}
                        <div class="novoton-capacity-box">
                            <strong>
                                 {__("novoton_holidays.hotel_max_capacity")|default:"Maximum room capacity at this hotel"}:
                            </strong>
                            <div class="novoton-capacity-value">
                                {$max_room_capacity.adults} {if $max_room_capacity.adults == 1}{__("novoton_holidays.adult")|default:"adult"}{else}{__("novoton_holidays.adults")}{/if} + {$max_room_capacity.children} {if $max_room_capacity.children == 1}{__("novoton_holidays.child")|default:"child"}{else}{__("novoton_holidays.children")}{/if}
                            </div>
                            <div class="novoton-capacity-note">
                                ({$max_room_capacity.total} {__("novoton_holidays.persons_per_room")|default:"persons per room"})
                            </div>
                        </div>
                        {/if}

                        <div class="novoton-suggestions-box">
                            <strong>
                                 {__("novoton_holidays.suggestions")|default:"Suggestions"}:
                            </strong>
                            <ul>
                                <li>{__("novoton_holidays.suggestion_split_rooms")|default:"Split your group into more rooms with fewer guests each"}</li>
                                <li>{__("novoton_holidays.suggestion_different_dates")|default:"Try different dates when more room types may be available"}</li>
                                <li>{__("novoton_holidays.suggestion_contact_us")|default:"Contact us for assistance with special arrangements"}</li>
                            </ul>
                        </div>

                        <div class="novoton-config-error-actions">
                            <button onclick="window.history.back()" class="novoton-btn-back">
                                <- {__("novoton_holidays.go_back")|default:"Go Back"}
                            </button>
                            <a href="{fn_url("novoton_booking.search?hotel_id=`$novoton_params.hotel_id`&product_id=`$novoton_params.product_id`")}" class="travel-offer-book-btn">
                                {__("novoton_holidays.change_search")|default:"Change Search"} ->
                            </a>
                        </div>
                    </div>
                </div>

            {else}
            {* All rooms have options - show the room-by-room selection.
               Up to 3 rooms: every room is open. 4+ rooms (the guest picker
               allows 12): one room open at a time — choosing a room closes it
               and opens the next one still to choose (data-stepper). Booking
               stays blocked until EVERY room has a choice. *}
            {$mr_currency = $novoton_display_symbol|default:$novoton_display_currency|default:$smarty.const.CART_PRIMARY_CURRENCY}
            {$mr_stepper = $novoton_params.num_rooms > 3}
            <div class="multi-room-selection novoton-mr" id="multi-room-selection"
                 data-num-rooms="{$novoton_params.num_rooms}"
                 data-rooms-data='{$novoton_params.rooms_data_json|default:"[]"|escape:"html"}'
                 data-currency="{$novoton_display_symbol|default:$smarty.const.CART_PRIMARY_CURRENCY|escape:"html"}"
                 data-coefficient="{$novoton_display_coefficient|default:1}"
                 data-round-prices="{if $novoton_round_prices}true{else}false{/if}"
                 data-stepper="{if $mr_stepper}true{else}false{/if}"
                 data-label-progress="{__("novoton_holidays.mr_progress")|escape:"html"}"
                 data-label-left="{__("novoton_holidays.mr_rooms_left")|escape:"html"}"
                 data-label-book="{__("novoton_holidays.mr_book_all")|escape:"html"}"
                 data-label-same="{__("novoton_holidays.mr_same_guests")|escape:"html"}"
                 data-label-savings="{__("novoton_holidays.mr_savings")|escape:"html"}"
                 data-label-not-chosen="{__("novoton_holidays.mr_not_chosen")|escape:"html"}">

                <div class="novoton-mr__layout">
                <div class="novoton-mr__rooms">
                    <div class="novoton-mr-header">
                        <h3>{__("novoton_holidays.select_room_types")}</h3>
                        <p>{__("novoton_holidays.select_room_type_for_each")} {__("novoton_holidays.mr_prices_note")}</p>
                    </div>

                {foreach from=$novoton_params.rooms_data item=room key=room_idx}
                    {$room_num = $room_idx + 1}
                    {assign var="room_key" value=$room_num}
                    {if isset($all_room_results.$room_key)}
                        {$room_specific_results = $all_room_results.$room_key}
                    {else}
                        {$room_specific_results = []}
                    {/if}
                    {$room_open = !$mr_stepper || $room_num == 1}
                    {capture name="mr_party"}{$room.adults} {if $room.adults == 1}{__("novoton_holidays.adult")}{else}{__("novoton_holidays.adults")}{/if}{if $room.children > 0} + {$room.children} {if $room.children == 1}{__("novoton_holidays.child")}{else}{__("novoton_holidays.children")}{/if}{/if}{/capture}

                    <section class="room-type-selection novoton-mr-room{if $room_open} is-open{/if}"
                             data-room="{$room_num}"
                             data-occupancy="{$room.adults}a{$room.children|default:0}c{if $room.childrenAges}{foreach from=$room.childrenAges item=mr_age}-{$mr_age}{/foreach}{/if}">
                        <h4 class="novoton-mr-room__heading">
                            <button type="button" class="novoton-mr-room__toggle"
                                    aria-expanded="{if $room_open}true{else}false{/if}"
                                    aria-controls="mr-room-{$room_num}-options">
                                <span class="novoton-mr-room__num" aria-hidden="true">{$room_num}</span>
                                <span class="novoton-mr-room__title">
                                    <span class="novoton-mr-room__label">{__("novoton_holidays.mr_room", ["[num]" => $room_num])}</span>
                                    <span class="novoton-mr-room-occupancy">{$smarty.capture.mr_party}</span>
                                </span>
                                <span class="novoton-mr-room__status">
                                    <span class="novoton-mr-room__choice" id="room-{$room_num}-choice"></span>
                                    <span id="room-{$room_num}-price" class="novoton-mr-room-price">{__("novoton_holidays.mr_not_chosen")}</span>
                                </span>
                                <svg class="novoton-mr-room__chevron" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                            </button>
                        </h4>

                        <div class="novoton-mr-options" id="mr-room-{$room_num}-options" role="radiogroup"
                             aria-label="{__("novoton_holidays.mr_room", ["[num]" => $room_num])}"{if !$room_open} hidden{/if}>
                            {if $room_specific_results && $room_specific_results|count > 0}
                                {foreach from=$room_specific_results item=result}
                                    {if $result.room_type_display}
                                        {$room_display = $result.room_type_display}
                                    {else}
                                        {$room_display = $result.room_name|default:$result.room_id}
                                    {/if}
                                    {* Novoton sends "* No name *" for some room types *}
                                    {if $room_display|trim == '* No name *' || $room_display|trim == ''}
                                        {$room_display = {__("novoton_holidays.mr_room_unnamed")}}
                                    {/if}

                                    {* Normalize the provider code (trim/upper/strip spaces) so
                                       variants like "HB +" still map to the translated name *}
                                    {$__bcode = $result.board_id|default:''|trim|upper|replace:' ':''}
                                    {if $__bcode == 'AI' || $__bcode == 'ALLINCL' || $__bcode == 'ALLINCLUSIVE'}
                                        {$board_display = {__('novoton_holidays.all_inclusive')|default:'All Inclusive'}}
                                    {elseif $__bcode == 'UAI' || $__bcode|strpos:'ULTRA' !== false}
                                        {$board_display = {__('novoton_holidays.ultra_all_inclusive')|default:'Ultra All Inclusive'}}
                                    {elseif $__bcode == 'FB' || $__bcode == 'FB+'}
                                        {$board_display = {__('novoton_holidays.full_board')|default:'Full Board'}}
                                    {elseif $__bcode == 'HB' || $__bcode == 'HB+'}
                                        {$board_display = {__('novoton_holidays.half_board')|default:'Half Board'}}
                                    {elseif $__bcode == 'BB' || $__bcode == 'B&B'}
                                        {$board_display = {__('novoton_holidays.bed_breakfast')|default:'Bed & Breakfast'}}
                                    {elseif $__bcode == 'RO' || $__bcode == 'ROOMONLY'}
                                        {$board_display = {__('novoton_holidays.room_only')|default:'Room Only'}}
                                    {elseif $__bcode == 'SC'}
                                        {$board_display = {__('novoton_holidays.self_catering')|default:'Self Catering'}}
                                    {else}
                                        {$board_display = $result.board_name|default:$result.board_id}
                                    {/if}

                                    {* The struck-through "was" price shown on the option (sent as
                                       data-was-price so the summary can add up the savings) *}
                                    {$mr_was_price = ''}
                                    {if $result.extras_price}
                                        {$mr_was_price = $result.total_price}
                                    {elseif $result.early_booking_discount > 0}
                                        {math equation="price / (1 - discount / 100)" price=$result.total_price discount=$result.early_booking_discount assign="mr_was_price"}
                                    {/if}
                                    {$mr_is_on_request = $result.is_on_request || $result.rooms_available === 0 || $result.rooms_available === '0'}

                                    <label class="room-option">
                                        <input type="radio"
                                               name="room_{$room_num}_selection"
                                               value="{$result.room_id}|{$result.board_id}|{$result.extras_price|default:$result.total_price}"
                                               data-room-num="{$room_num}"
                                               data-room-id="{$result.room_id}"
                                               data-board-id="{$result.board_id}"
                                               data-price="{$result.extras_price|default:$result.total_price}"
                                               data-standard-price="{$result.total_price}"
                                               data-was-price="{$mr_was_price}"
                                               data-quota="{if $mr_is_on_request}{else}{$result.rooms_available}{/if}"
                                               data-early-booking="{$result.early_booking_discount|default:0}"
                                               data-extras="{$result.extras_label|default:''|escape:'html'}"
                                               data-room-display="{$room_display}"
                                               data-board-name="{$board_display}"
                                               data-package-name="{$result.package_name|escape:'htmlall'}" />

                                        <span class="novoton-mr-option-body">
                                            <span class="novoton-mr-option-main">
                                                <span class="novoton-mr-option-name">{$room_display}</span>
                                                <span class="novoton-mr-option-board">
                                                    {$board_display}
                                                    {if $result.package_name}
                                                        <span class="novoton-mr-package">{$result.package_name}</span>
                                                    {/if}
                                                </span>
                                                {* Room availability - Quota display *}
                                                {if $mr_is_on_request}
                                                    <span class="novoton-mr-quota--onrequest">
                                                        <strong>{__("novoton_holidays.reservation_on_request")}</strong> <span>- {__("novoton_holidays.confirmation_48h")|default:"confirmation within max 48 hours"}</span>
                                                    </span>
                                                    {if $result.nearby_availability && $result.nearby_availability|count > 0}
                                                        <span class="novoton-nearby">
                                                            <strong>{__("novoton_holidays.nearby_dates_available")|default:"Available on nearby dates"}:</strong>
                                                            {foreach from=$result.nearby_availability item=nearby name=nearby_loop}
                                                                <a href="{fn_url("novoton_booking.search?hotel_id=`$novoton_params.hotel_id`&check_in=`$nearby.check_in`&check_out=`$nearby.check_out`&adults=`$novoton_params.adults`&children=`$novoton_params.children_count`&rooms=`$novoton_params.num_rooms`")}">
                                                                    {$nearby.check_in|date_format:"%b %d"} - {$nearby.check_out|date_format:"%b %d"} ({$nearby.quota} {__("novoton_holidays.rooms_short")|default:"rooms"})
                                                                </a>{if !$smarty.foreach.nearby_loop.last}, {/if}
                                                            {/foreach}
                                                        </span>
                                                    {/if}
                                                {elseif $result.rooms_available !== null && $result.rooms_available !== ''}
                                                    {if $result.rooms_available > 5}
                                                        <span class="novoton-mr-quota--ok">{$result.rooms_available} {__("novoton_holidays.available_rooms")}</span>
                                                    {elseif $result.rooms_available >= 1}
                                                        <span class="novoton-mr-quota--low">{__("novoton_holidays.we_have_left", ["[count]" => $result.rooms_available])}</span>
                                                    {/if}
                                                {/if}
                                                {if $result.terms_of_payment || $result.terms_of_cancellation || $result.remark || $result.more_info || $result.important}
                                                    <a href="#" onclick="openInfoModal('mr-{$room_num}-{$result@index}'); return false;" class="novoton-info-link">{__("novoton_holidays.cancellation_and_payment_terms")}</a>
                                                {/if}
                                            </span>

                                            <span class="novoton-mr-option-price">
                                                {if $mr_was_price}
                                                    <span class="novoton-price-strike">{fn_novoton_holidays_format_price($mr_was_price, $novoton_display_coefficient|default:1, $mr_currency) nofilter}</span>
                                                {/if}
                                                <span class="novoton-mr-price-amount">{fn_novoton_holidays_format_price($result.extras_price|default:$result.total_price|default:0, $novoton_display_coefficient|default:1, $mr_currency) nofilter}</span>
                                                <span class="novoton-mr-price-note">{__("novoton_holidays.mr_whole_stay")}</span>
                                            </span>
                                        </span>
                                    </label>
                                    {if $result.terms_of_payment || $result.terms_of_cancellation || $result.remark || $result.more_info || $result.important}
                                        <div id="modal-content-mr-{$room_num}-{$result@index}" style="display: none;">
                                            {if $result.terms_of_payment}
                                                {$mr_payment_terms = fn_novoton_holidays_format_payment_terms_with_amounts($result.terms_of_payment, $result.total_price, $novoton_display_currency|default:$smarty.const.CART_PRIMARY_CURRENCY, $novoton_display_coefficient|default:1, $novoton_display_symbol|default:'')}
                                                {if $mr_payment_terms}
                                                    <div class="novoton-modal-section"><strong>{__("novoton_holidays.terms_of_payment")|default:"Termeni de plată"}:</strong><br>{$mr_payment_terms|escape:'html'|nl2br nofilter}</div>
                                                {/if}
                                            {/if}
                                            {if $result.terms_of_cancellation}
                                                {$mr_cancel_terms = fn_novoton_holidays_format_cancellation_terms($result.terms_of_cancellation, $check_in_date)}
                                                {if $mr_cancel_terms}
                                                    <div class="novoton-modal-section"><strong>{__("novoton_holidays.cancellation_terms")|default:"Condiții de anulare"}:</strong><br>{$mr_cancel_terms|escape:'html'|nl2br nofilter}</div>
                                                {/if}
                                            {/if}
                                            {if $result.remark}<div class="novoton-modal-section"><strong>{__("novoton_holidays.note")|default:"Note"}:</strong><br>{$result.remark|escape:'html'|replace:'lt;pgt;':'<p>'|replace:'lt;/pgt;':'</p>'|replace:'lt;br /gt;':'<br>'|replace:'lt;br/gt;':'<br>'|replace:'amp;':'&'|regex_replace:'/(\s*[\r\n]){2,}/':"\n"|trim|nl2br nofilter}</div>{/if}
                                            {if $result.more_info}<div class="novoton-modal-section"><strong>{__("novoton_holidays.additional_information")|default:"Additional Information"}:</strong><br>{$result.more_info|escape:'html'|replace:'lt;pgt;':'<p>'|replace:'lt;/pgt;':'</p>'|replace:'lt;br /gt;':'<br>'|replace:'lt;br/gt;':'<br>'|replace:'amp;':'&'|nl2br nofilter}</div>{/if}
                                            {if $result.important}<div class="novoton-modal-section novoton-modal-section--important"><strong>{__("novoton_holidays.important")|default:"Important"}:</strong><br>{$result.important|escape:'html'|replace:'lt;pgt;':'<p>'|replace:'lt;/pgt;':'</p>'|replace:'lt;br /gt;':'<br>'|replace:'lt;br/gt;':'<br>'|replace:'amp;':'&'|nl2br nofilter}</div>{/if}
                                        </div>
                                    {/if}
                                {/foreach}

                                {* Filled by multiroom-booking.js after a choice: apply it to the
                                   other rooms with the same guests, or go on to the next room *}
                                <div class="novoton-mr-next" hidden>
                                    <button type="button" class="novoton-mr-next__same" data-same-from="{$room_num}" hidden></button>
                                    <button type="button" class="novoton-mr-next__go" data-next-from="{$room_num}">{__("novoton_holidays.mr_next_room")} &rarr;</button>
                                </div>
                            {else}
                                <div class="novoton-mr-empty">
                                    <strong>{__("novoton_holidays.no_rooms_available")|default:"No rooms available for this configuration"}</strong>
                                </div>
                            {/if}
                        </div>
                    </section>
                {/foreach}
                </div>

                {* Selection summary: sticky beside the rooms on desktop, a bar
                   fixed to the bottom of the screen on phones *}
                <aside class="novoton-mr__summary novoton-mr-total-bar" aria-label="{__("novoton_holidays.mr_your_selection")}">
                    <div class="novoton-mr__summary-title">{__("novoton_holidays.mr_your_selection")}</div>
                    <div class="novoton-mr__progress">
                        <span id="mr-progress-text" class="novoton-mr__progress-text" aria-live="polite">{__("novoton_holidays.mr_progress", ["[chosen]" => 0, "[total]" => $novoton_params.num_rooms])}</span>
                        <span class="novoton-mr__bar" aria-hidden="true"><span id="mr-progress-bar" class="novoton-mr__bar-fill"></span></span>
                    </div>
                    <ol class="novoton-mr__list">
                        {foreach from=$novoton_params.rooms_data item=room key=room_idx}
                            {$room_num = $room_idx + 1}
                            <li>
                                <button type="button" class="novoton-mr__list-item is-pending" data-goto-room="{$room_num}">
                                    <span class="novoton-mr__list-room">{__("novoton_holidays.mr_room", ["[num]" => $room_num])}</span>
                                    <span class="novoton-mr__list-price" id="mr-sum-{$room_num}">{__("novoton_holidays.mr_not_chosen")}</span>
                                </button>
                            </li>
                        {/foreach}
                    </ol>
                    <div class="novoton-mr-total-row">
                        <span class="novoton-mr-total-label">{__("novoton_holidays.total_for_all_rooms")}</span>
                        <span class="novoton-mr-total" id="total-combined-price">-- {$mr_currency}</span>
                    </div>
                    <div class="novoton-mr__savings" id="mr-savings" hidden></div>
                    <button type="button"
                            id="book-multi-room-btn"
                            class="travel-offer-book-btn novoton-mr__book"
                            disabled>{__("novoton_holidays.mr_rooms_left", ["[count]" => $novoton_params.num_rooms])}</button>
                    <p class="novoton-mr__hint">{__("novoton_holidays.mr_guest_details_next")}</p>
                </aside>
                </div>

                <form id="multi-room-booking-form" method="get" action="{$config.current_location|fn_url}" style="display: none;">
                    <input type="hidden" name="dispatch" value="novoton_booking.booking_form" />
                    <input type="hidden" name="hotel_id" value="{$novoton_params.hotel_id}" />
                    <input type="hidden" name="check_in" value="{$check_in_date}" />
                    <input type="hidden" name="check_out" value="{$check_out_date}" />
                    <input type="hidden" name="nights" value="{$novoton_params.nights}" />
                    <input type="hidden" name="num_rooms" value="{$novoton_params.num_rooms}" />
                    <input type="hidden" name="multi_room" value="1" />
                    <input type="hidden" name="rooms_data" id="hidden_rooms_data" value="" />
                    <input type="hidden" name="total_price" id="hidden_total_price" value="" />
                    {* Terms are now fetched directly from API at checkout *}
                </form>
            </div>

            {* Multi-room JS is loaded externally via multiroom-booking.js *}

            {/if}{* End of all_rooms_have_options else block *}

        {else}

        {* ===== SINGLE ROOM MODE — one shared offer card per room+board offer ===== *}
        <div class="novoton-results-list">

            {foreach from=$novoton_results item=result name=results}
                {if $result.room_type_display}
                    {$room_display = $result.room_type_display}
                {else}
                    {$room_display = $result.room_name|default:$result.room_id}
                {/if}

                {* Normalize the provider code (trim/upper/strip spaces) so
                   variants like "HB +" still map to the translated name *}
                {$__bcode = $result.board_id|default:''|trim|upper|replace:' ':''}
                {if $__bcode == 'AI' || $__bcode == 'ALLINCL' || $__bcode == 'ALLINCLUSIVE'}
                    {$board_display = {__('novoton_holidays.all_inclusive')|default:'All Inclusive'}}
                {elseif $__bcode == 'UAI' || $__bcode|strpos:'ULTRA' !== false}
                    {$board_display = {__('novoton_holidays.ultra_all_inclusive')|default:'Ultra All Inclusive'}}
                {elseif $__bcode == 'FB' || $__bcode == 'FB+'}
                    {$board_display = {__('novoton_holidays.full_board')|default:'Full Board'}}
                {elseif $__bcode == 'HB' || $__bcode == 'HB+'}
                    {$board_display = {__('novoton_holidays.half_board')|default:'Half Board'}}
                {elseif $__bcode == 'BB' || $__bcode == 'B&B'}
                    {$board_display = {__('novoton_holidays.bed_breakfast')|default:'Bed & Breakfast'}}
                {elseif $__bcode == 'RO' || $__bcode == 'ROOMONLY'}
                    {$board_display = {__('novoton_holidays.room_only')|default:'Room Only'}}
                {elseif $__bcode == 'SC'}
                    {$board_display = {__('novoton_holidays.self_catering')|default:'Self Catering'}}
                {else}
                    {$board_display = $result.board_name|default:$result.board_id}
                {/if}

                {$result_package_name = $result.package_name|default:$hotel_package_name}
                {$row_id = $smarty.foreach.results.index}
                {$single_room_data = [["adults" => $novoton_params.adults, "children" => $novoton_params.children_count, "childrenAges" => $novoton_params.children_ages_array|default:[]]]}

                <div class="travel-offer-card novoton-offer-card{if $result.is_on_request} travel-offer-card--on-request on-request{/if}">

                    {* Zone 1: Room *}
                    <div class="travel-offer-details novoton-offer-room-zone">
                        <div class="travel-offer-room travel-offer-hotel-name">{$room_display}</div>
                        {if $result_package_name}
                            <div class="novoton-offer-package">{$result_package_name}</div>
                        {/if}

                        {* Room facilities *}
                        {if $novoton_room_facilities && $novoton_room_facilities|count > 0}
                            <div class="novoton-facilities">
                                {foreach from=$novoton_room_facilities item=rfac}
                                    {if $rfac.facility_name}
                                        <span class="novoton-facility"><i class="icon-ok"></i>{$rfac.facility_name}</span>
                                    {/if}
                                {/foreach}
                            </div>
                        {/if}

                        {* MoreInfo from API - display additional room details *}
                        {if $result.more_info}
                            <div class="novoton-goodinfo">
                                ✓ {$result.more_info|replace:'lt;':'<'|replace:'gt;':'>'|replace:'amp;':'&'|strip_tags}
                            </div>
                        {/if}

                        {* Important from API - display important notices *}
                        {if $result.important}
                            <div class="novoton-important-note">
                                ⚠️ {$result.important|replace:'lt;':'<'|replace:'gt;':'>'|replace:'amp;':'&'|strip_tags}
                            </div>
                        {/if}

                        {* Room availability - Quota display *}
                        {if $result.is_on_request || $result.rooms_available === 0 || $result.rooms_available === '0'}
                            <div class="novoton-quota--onrequest">
                                <strong>{__("novoton_holidays.reservation_on_request")}</strong> <span>- {__("novoton_holidays.confirmation_48h")|default:"confirmation within max 48 hours"}</span>
                            </div>
                            {if $result.nearby_availability && $result.nearby_availability|count > 0}
                                <div class="novoton-nearby">
                                    <strong>{__("novoton_holidays.nearby_dates_available")|default:"Available on nearby dates"}:</strong>
                                    {foreach from=$result.nearby_availability item=nearby name=nearby_loop}
                                        <a href="{fn_url("novoton_booking.search?hotel_id=`$novoton_params.hotel_id`&check_in=`$nearby.check_in`&check_out=`$nearby.check_out`&adults=`$novoton_params.adults`&children=`$novoton_params.children_count`&rooms=`$novoton_params.num_rooms`")}">
                                            {$nearby.check_in|date_format:"%b %d"} - {$nearby.check_out|date_format:"%b %d"} ({$nearby.quota} {__("novoton_holidays.rooms_short")|default:"rooms"})
                                        </a>{if !$smarty.foreach.nearby_loop.last}, {/if}
                                    {/foreach}
                                </div>
                            {/if}
                        {elseif $result.rooms_available !== null && $result.rooms_available !== ''}
                            {if $result.rooms_available > 5}
                                <div class="novoton-quota--ok">{$result.rooms_available} {__("novoton_holidays.available_rooms")}</div>
                            {elseif $result.rooms_available >= 1}
                                <div class="novoton-quota--low">{__("novoton_holidays.we_have_left", ["[count]" => $result.rooms_available])}</div>
                            {/if}
                        {/if}
                    </div>

                    {* Zone 2: Choices *}
                    <div class="travel-offer-details novoton-offer-choices-zone">
                        {* Translated meal plan first, raw provider code in parens when it
                           differs — e.g. "Demipensiune (HB +)" *}
                        <div class="travel-offer-board novoton-board">{$board_display}{if $result.board_id && $result.board_id|trim != $board_display} ({$result.board_id|trim}){/if}</div>

                        {* Free Cancellation Date *}
                        {if $result.free_cancellation_date}
                            <div class="novoton-perk">
                                ✓ {__("novoton_holidays.free_cancellation_until")|default:"Anulare gratuită până la"} <strong>{$result.free_cancellation_date|date_format:$settings.Appearance.date_format|default:"%d.%m.%Y"}</strong>
                            </div>
                        {/if}

                        {$has_payment_terms = $result.terms_of_payment || $terms_of_payment}
                        {$has_cancel_terms = $result.terms_of_cancellation || $terms_of_cancellation}

                        {if $has_payment_terms && $has_cancel_terms}
                            <div class="novoton-perk">✓ {__("novoton_holidays.payment_and_cancellation_terms_apply")}</div>
                        {elseif $has_payment_terms}
                            <div class="novoton-perk">✓ {__("novoton_holidays.payment_terms_apply")}</div>
                        {elseif $has_cancel_terms}
                            <div class="novoton-perk">✓ {__("novoton_holidays.cancellation_terms_apply")}</div>
                        {/if}

                        {* Extras promotion badge *}
                        {$_extras_text = $result.extras_label|default:$result.extras|default:''|trim}
                        {if $_extras_text && $_extras_text|strstr:'='}
                            {$_extras_parts = '='|explode:$_extras_text}
                            {$_book_n = $_extras_parts[0]|trim}
                            {$_pay_n = $_extras_parts[1]|trim}
                            {if $_book_n && $_pay_n}
                                <div class="novoton-extras-chip">
                                    {__("novoton_holidays.book_x_pay_y", ["[book]" => $_book_n, "[pay]" => $_pay_n])|default:"Book `$_book_n` nights, pay for `$_pay_n`"}
                                </div>
                            {/if}
                        {/if}

                        {if $result.remark || $result.more_info || $result.important || $result.terms_of_payment || $result.terms_of_cancellation}
                            <div class="novoton-offer-info-row">
                                <a href="#" onclick="openInfoModal({$row_id}); return false;" class="novoton-info-link">{__("novoton_holidays.cancellation_and_payment_terms")}</a>
                            </div>
                            <div id="modal-content-{$row_id}" style="display: none;">
                                {* Payment Terms - displayed first *}
                                {if $result.terms_of_payment}
                                    {$payment_terms_row = fn_novoton_holidays_format_payment_terms_with_amounts($result.terms_of_payment, $result.total_price, $novoton_display_currency|default:$smarty.const.CART_PRIMARY_CURRENCY, $novoton_display_coefficient|default:1, $novoton_display_symbol|default:'')}
                                    {if $payment_terms_row}
                                        <div class="novoton-modal-section"><strong>{__("novoton_holidays.terms_of_payment")|default:"Termeni de plată"}:</strong><br>{$payment_terms_row|escape:'html'|nl2br nofilter}</div>
                                    {/if}
                                {/if}
                                {* Cancellation Terms - displayed second *}
                                {if $result.terms_of_cancellation}
                                    {$cancel_terms_row = fn_novoton_holidays_format_cancellation_terms($result.terms_of_cancellation, $check_in_date)}
                                    {if $cancel_terms_row}
                                        <div class="novoton-modal-section"><strong>{__("novoton_holidays.cancellation_terms")|default:"Condiții de anulare"}:</strong><br>{$cancel_terms_row|escape:'html'|nl2br nofilter}</div>
                                    {/if}
                                {/if}
                                {* Remark/Note field - uses translation key, collapses blank lines *}
                                {if $result.remark}<div class="novoton-modal-section"><strong>{__("novoton_holidays.note")|default:"Note"}:</strong><br>{$result.remark|escape:'html'|replace:'lt;pgt;':'<p>'|replace:'lt;/pgt;':'</p>'|replace:'lt;br /gt;':'<br>'|replace:'lt;br/gt;':'<br>'|replace:'amp;':'&'|regex_replace:'/(\s*[\r\n]){2,}/':"\n"|trim|nl2br nofilter}</div>{/if}
                                {* Additional information field *}
                                {if $result.more_info}<div class="novoton-modal-section"><strong>{__("novoton_holidays.additional_information")|default:"Additional Information"}:</strong><br>{$result.more_info|escape:'html'|replace:'lt;pgt;':'<p>'|replace:'lt;/pgt;':'</p>'|replace:'lt;br /gt;':'<br>'|replace:'lt;br/gt;':'<br>'|replace:'amp;':'&'|nl2br nofilter}</div>{/if}
                                {* Important notice - highlighted *}
                                {if $result.important}<div class="novoton-modal-section novoton-modal-section--important"><strong>{__("novoton_holidays.important")|default:"Important"}:</strong><br>{$result.important|escape:'html'|replace:'lt;pgt;':'<p>'|replace:'lt;/pgt;':'</p>'|replace:'lt;br /gt;':'<br>'|replace:'lt;br/gt;':'<br>'|replace:'amp;':'&'|nl2br nofilter}</div>{/if}
                            </div>
                        {/if}
                    </div>

                    {* Zone 3: Price + action *}
                    <div class="travel-offer-price-action novoton-offer-price-zone">
                        <div class="travel-offer-price">
                            {if $result.extras_price}
                                {* Extras promotion: standard price strikethrough + promotional price *}
                                <div class="novoton-price-strike">{fn_novoton_holidays_format_price($result.total_price, $novoton_display_coefficient|default:1, $novoton_display_symbol|default:$novoton_display_currency|default:$smarty.const.CART_PRIMARY_CURRENCY) nofilter}</div>
                                <span class="travel-price-amount">{fn_novoton_holidays_format_price($result.extras_price, $novoton_display_coefficient|default:1, $novoton_display_symbol|default:$novoton_display_currency|default:$smarty.const.CART_PRIMARY_CURRENCY) nofilter}</span>
                                {math equation="standard - promo" standard=$result.total_price promo=$result.extras_price assign="_savings"}
                                {if $_savings > 0}
                                    <div class="novoton-extras-chip">
                                        -{fn_novoton_holidays_format_price($_savings, $novoton_display_coefficient|default:1, $novoton_display_symbol|default:$novoton_display_currency|default:$smarty.const.CART_PRIMARY_CURRENCY) nofilter} {__("novoton_holidays.off")|default:"off"}
                                    </div>
                                {/if}
                            {else}
                                {* Standard display (no extras promotion) *}
                                {if $result.early_booking_discount > 0}
                                    {math equation="price / (1 - discount / 100)" price=$result.total_price discount=$result.early_booking_discount assign="original_price"}
                                    <div class="novoton-price-strike">{fn_novoton_holidays_format_price($original_price, $novoton_display_coefficient|default:1, $novoton_display_symbol|default:$novoton_display_currency|default:$smarty.const.CART_PRIMARY_CURRENCY) nofilter}</div>
                                {/if}
                                <span class="travel-price-amount">{fn_novoton_holidays_format_price($result.total_price|default:0, $novoton_display_coefficient|default:1, $novoton_display_symbol|default:$novoton_display_currency|default:$smarty.const.CART_PRIMARY_CURRENCY) nofilter}</span>
                            {/if}
                            {* Stay-length qualifier — carried the nights count in the old
                               table header; the price covers the WHOLE stay, not a night *}
                            <span class="travel-price-per-night">{__("novoton_holidays.price_for_stay", ["[nights]" => $novoton_params.nights|default:7])}</span>
                            <span class="travel-price-includes">{__("novoton_holidays.includes_taxes")}</span>
                            {* Early-booking chip renders whenever the discount exists —
                               the old mobile card did this; the old desktop row hid it
                               when an extras promo was also present *}
                            {if $result.early_booking_discount > 0}
                                <div class="novoton-early-chip">
                                    -{$result.early_booking_discount|string_format:"%.0f"}% {__("novoton_holidays.early_booking")}
                                </div>
                            {/if}
                        </div>
                        <a href="{fn_url("novoton_booking.booking_form?hotel_id=`$novoton_params.hotel_id`&room_id=`$result.room_id|escape:'url'`&board_id=`$result.board_id|escape:'url'`&check_in=`$check_in_date`&check_out=`$check_out_date`&nights=`$novoton_params.nights`&adults=`$novoton_params.adults`&children=`$novoton_params.children_count`&children_ages=`$novoton_params.children_ages|default:''`&price=`$result.extras_price|default:$result.total_price`&package_name=`$result_package_name|escape:'url'`&room_name=`$room_display|escape:'url'`&board_name=`$board_display|escape:'url'`&rooms_data=`$single_room_data|json_encode|escape:'url'`&extras=`$result.extras_label|default:''|escape:'url'`&extras_price=`$result.extras_price|default:''`&standard_price=`$result.total_price`&early_booking=`$result.early_booking_discount|default:0`&is_on_request=`$result.is_on_request|default:0`&rooms_available=`$result.rooms_available|default:0`")}"
                           class="travel-offer-book-btn">
                            {__("novoton_holidays.book")}
                        </a>
                    </div>
                </div>
            {/foreach}
        </div>

        {* Below-list "Condiții de plată" / "Condiții de anulare" panels were
           removed: every offer row links its own payment/cancellation terms,
           so the page-level duplicates only added noise under the results. *}

        {/if}

    {elseif $no_availability_message && $alternative_results && $alternative_results|count > 0}
        {* Show alternative dates results *}
        <div class="novoton-alt-banner">
            <h3>
                {if $flex_days > 0}
                    {__("novoton_holidays.flexible_dates_found")|default:"We found availability on nearby dates!"}
                {else}
                    {__("novoton_holidays.alternative_dates_found")|default:"No availability on selected dates, but found on:"}
                {/if}
            </h3>
            <p>
                {$alternative_check_in|date_format:"%a, %b %d"} - {$alternative_check_out|date_format:"%a, %b %d, %Y"}
            </p>
            <a href="{fn_url("novoton_booking.search?hotel_id=`$novoton_params.hotel_id`&check_in=`$alternative_check_in`&nights=`$novoton_params.nights`&adults=`$novoton_params.adults`&children=`$novoton_params.children_count`&rooms=`$novoton_params.num_rooms`")}"
               class="novoton-alt-cta">
                {__("novoton_holidays.view_availability")|default:"View availability for these dates"} ->
            </a>
        </div>

        <div class="novoton-empty-box">
            <h3>{__("novoton_holidays.no_availability_selected_dates")|default:"No availability for your selected dates"}</h3>
            <p>
                {$novoton_params.check_in|date_format:"%a, %b %d"} - {$novoton_params.check_out|date_format:"%a, %b %d, %Y"}
            </p>
            {if $hotel_season_from && $hotel_season_to}
            <p class="novoton-season-note">
                {__("novoton_holidays.accommodation_period")|default:"This hotel offers accommodation from"} {$hotel_season_from|date_format:"%d %b"} {__("novoton_holidays.to")|default:"to"} {$hotel_season_to|date_format:"%d %b %Y"}
            </p>
            {/if}
        </div>
    {elseif $no_availability_message}
        <div class="novoton-empty-box">
            <h3>{__("novoton_holidays.no_availability")}</h3>
            <p>{__("novoton_holidays.try_different_dates")}</p>
            {if $hotel_season_from && $hotel_season_to}
            <p class="novoton-season-note">
                {__("novoton_holidays.accommodation_period")|default:"This hotel offers accommodation from"} {$hotel_season_from|date_format:"%d %b"} {__("novoton_holidays.to")|default:"to"} {$hotel_season_to|date_format:"%d %b %Y"}
            </p>
            {/if}

            {* Request Alternatives Form *}
            <div class="novoton-request-box">
                <h4>
                    {__("novoton_holidays.request_alternatives_title")|default:"Can't find what you're looking for?"}
                </h4>
                <p>
                    {__("novoton_holidays.request_alternatives_desc")|default:"Leave your contact details and we'll notify you when alternatives become available for your dates."}
                </p>

                <form id="request-alternatives-form" method="post" action="{fn_url('novoton_booking.request_alternatives')}">
                    <input type="hidden" name="security_hash" value="{$security_hash}">
                    <input type="hidden" name="hotel_id" value="{$novoton_params.hotel_id}">
                    <input type="hidden" name="hotel_name" value="{$hotel_name|escape:'html'}">
                    <input type="hidden" name="check_in" value="{$novoton_params.check_in}">
                    <input type="hidden" name="check_out" value="{$novoton_params.check_out}">
                    <input type="hidden" name="nights" value="{$novoton_params.nights}">
                    <input type="hidden" name="adults" value="{$novoton_params.adults}">
                    <input type="hidden" name="children" value="{$novoton_params.children_count}">
                    <input type="hidden" name="num_rooms" value="{$novoton_params.num_rooms}">

                    <div class="novoton-form-grid">
                        <div class="novoton-form-field">
                            <label>{__("novoton_holidays.email")}<span class="novoton-form-required">*</span></label>
                            <input type="email" name="contact_email" required placeholder="your@email.com">
                        </div>
                        <div class="novoton-form-field">
                            <label>{__("novoton_holidays.phone")}</label>
                            <input type="tel" name="contact_phone" placeholder="+40...">
                        </div>
                    </div>

                    <div class="novoton-form-grid">
                        <div class="novoton-form-field">
                            <label>{__("novoton_holidays.notes")|default:"Notes"}</label>
                            <textarea name="notes" rows="2" placeholder="{__('novoton_holidays.alternatives_notes_placeholder')|default:'Any specific requirements or preferences...'}"></textarea>
                        </div>
                    </div>

                    <button type="submit" class="travel-offer-book-btn">
                        {__("novoton_holidays.request_alternatives_btn")|default:"Notify me when available"} ->
                    </button>

                    <p class="novoton-request-privacy">
                        {__("novoton_holidays.request_alternatives_privacy")|default:"We'll only use your contact info to notify you about availability."}
                    </p>
                </form>
            </div>
        </div>
    {/if}

    {* Modal for More Info — INSIDE .travel-search-results-page on purpose:
       the engine's inline search copies everything after the form wrapper
       into the product page, so the modal must travel WITH the results
       (outside the wrapper it would never exist on the PDP). Handlers are
       document-delegated in search-results.js, so the swapped-in copy
       needs no rebinding. *}
    <div id="info-modal" class="novoton-info-modal">
        <div class="novoton-info-modal-box">
            <div class="novoton-info-modal-head">
                <h3>{__("novoton_holidays.room_details")}</h3>
                <button onclick="closeInfoModal()" class="novoton-info-modal-close" aria-label="{__("close")|default:"Close"}">&times;</button>
            </div>
            <div id="info-modal-content" class="novoton-info-modal-body"></div>
        </div>
    </div>

</div>

{* Modal behavior lives in a real JS file (vitest imports it directly —
   tests/js/novoton-search-results.test.mjs). *}
{script src="js/addons/novoton_holidays/search-results.js"}

{* Client i18n for the booking engine — shared travel_core partial
   (replaces the hand-maintained window.NovotonTranslations block). *}
{include file="addons/travel_core/components/travel_i18n.tpl"}
{* React scripts loaded by shared booking_engine.tpl include above *}
{$cache_ver = $smarty.const.TRAVEL_CACHE_VER|default:$smarty.const.NOVOTON_CACHE_VER|default:'1'}
<script src="{$config.current_location}/js/addons/travel_core/dob-validation.js?v={$cache_ver}" defer></script>
