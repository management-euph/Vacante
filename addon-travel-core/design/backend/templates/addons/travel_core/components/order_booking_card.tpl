{*
    Booking card under a travel line on the ADMIN order page — one template
    for every provider (novoton, sphinx, eurosite). Every value arrives
    prepared by fn_travel_core_order_booking_card(..., "admin")
    (ViewModels\OrderBookingCardFactory): status, supplier reference, dates
    with weekdays, each room with its board and guests, the cancellation
    headline and schedule, the balance of a deposit order, and an alert with
    the supplier's answer when the booking failed or was never sent.

    Parameters:
      tobc   the prepared card

    Several bookings on one order: each card folds into a one-row summary
    (native <details>; one that needs action stays open). The schedule is a
    <details> too. The copy buttons are js/addons/travel_core/order-booking-card.js.
    Styling: admin_styles.css .travel-obc*. Core Smarty modifiers only — the
    admin page renders inside {capture name="mainbox"}.
*}
{$tobc_status = $tobc.status.code|default:""}
{if $tobc.kind == "circuit"}
    {$tobc_in_label = __("travel_core.obc_departure")}
    {$tobc_out_label = __("travel_core.obc_return")}
{else}
    {$tobc_in_label = __("travel_core.check_in")}
    {$tobc_out_label = __("travel_core.check_out")}
{/if}
{capture name="tobc_badges"}
    {if $tobc.provider.name}<span class="travel-obc__provider">{$tobc.provider.name|escape:html}</span>{/if}
    {if $tobc.kind == "circuit"}
        <span class="travel-obc__kind">{__("travel_core.obc_kind_circuit")}</span>
    {elseif $tobc.kind == "package"}
        <span class="travel-obc__kind">{if $tobc.transport == "flight"}{__("travel_core.obc_kind_flight_hotel")}{elseif $tobc.transport == "bus"}{__("travel_core.obc_kind_bus_hotel")}{else}{__("travel_core.obc_kind_package")}{/if}</span>
    {/if}
    {if $tobc_status}<span class="travel-obc__status travel-obc__status--{$tobc.status.tone}">{__("travel_core.obc_status_`$tobc_status`")}</span>{/if}
{/capture}

{if $tobc.collapsible}<details class="travel-obc travel-obc--fold"{if $tobc.open} open{/if}>{else}<div class="travel-obc">{/if}

    {if $tobc.collapsible}
        <summary class="travel-obc__bar">
            {$smarty.capture.tobc_badges nofilter}
            <strong class="travel-obc__bar-dates">{$tobc.check_in.date|escape:html} &rarr; {$tobc.check_out.date|escape:html}</strong>
            <span class="travel-obc__muted">{__("travel_core.n_nights", [$tobc.nights])} &middot; {__("travel_core.n_rooms", [$tobc.rooms])} &middot; {__("travel_core.n_adults", [$tobc.adults])}{if $tobc.children}, {__("travel_core.n_children", [$tobc.children])}{/if}</span>
            <span class="travel-obc__toggle"><span class="travel-obc__toggle-open">{__("travel_core.stay_details")}</span><span class="travel-obc__toggle-close">{__("travel_core.stay_hide")}</span></span>
        </summary>
    {/if}

    {* Reference row: who holds the booking, under which number. *}
    <div class="travel-obc__head">
        {if !$tobc.collapsible}{$smarty.capture.tobc_badges nofilter}{/if}
        {if $tobc.reference}
            <span class="travel-obc__ref">{__("travel_core.obc_ref")} <strong>{$tobc.reference|escape:html}</strong></span>
            <button type="button" class="btn btn-mini travel-obc__copy" data-ca-travel-copy="{$tobc.reference|escape:html}" data-ca-travel-copied="{__("travel_core.obc_copied")|escape:html}" aria-label="{__("travel_core.obc_copy_ref")|escape:html}">{__("travel_core.obc_copy")}</button>
        {elseif $tobc.provider.name}
            <span class="travel-obc__muted">{__("travel_core.obc_no_ref")}</span>
        {/if}
        {if $tobc.our_reference}<span class="travel-obc__muted">{__("travel_core.obc_our_ref")} {$tobc.our_reference|escape:html}</span>{/if}
        {if $tobc.booking_id > 0}
            <a class="travel-obc__open" href="{"travel_bookings.view?booking_id=`$tobc.booking_id`"|fn_url}">{__("travel_core.obc_booking_n", ["[id]" => $tobc.booking_id])} &rsaquo;</a>
        {/if}
    </div>

    {* Failed, or never sent: what the supplier answered, and the remedies. *}
    {if $tobc.alert}
        <div class="travel-obc__alert" role="alert">
            <svg class="travel-obc__icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3 2.5 20h19z"></path><line x1="12" y1="10" x2="12" y2="14"></line><line x1="12" y1="17" x2="12" y2="17.5"></line></svg>
            <div class="travel-obc__alert-body">
                {if $tobc.alert.kind == "not_sent"}
                    <strong>{__("travel_core.obc_not_sent_title", ["[provider]" => $tobc.provider.name])}</strong>
                    <span>{__("travel_core.obc_not_sent_text")}</span>
                {else}
                    <strong>{__("travel_core.obc_failed_title", ["[provider]" => $tobc.provider.name])}</strong>
                    <span>{__("travel_core.obc_failed_text")}</span>
                {/if}
                {if $tobc.alert.error}
                    <span class="travel-obc__alert-error">{__("travel_core.obc_supplier_answer")}: {$tobc.alert.error|escape:html}</span>
                {/if}
                <span class="travel-obc__alert-actions">
                    {foreach from=$tobc.alert.actions item="tobc_action"}
                        <a class="btn btn-primary{if $tobc_action.post} cm-post{/if}{if $tobc_action.confirm} cm-confirm{/if}" href="{$tobc_action.href|fn_url}">{$tobc_action.label|escape:html}</a>
                    {/foreach}
                    {if $tobc.booking_id > 0}
                        <a class="btn" href="{"travel_bookings.view?booking_id=`$tobc.booking_id`"|fn_url}">{__("travel_core.obc_open_booking")}</a>
                    {/if}
                </span>
            </div>
        </div>
    {/if}

    {* The stay: dates with weekdays, nights (a circuit's days too), where. *}
    <div class="travel-obc__grid">
        <div>
            <div class="travel-obc__label">{$tobc_in_label}</div>
            <div class="travel-obc__value">{$tobc.check_in.date|escape:html}</div>
            {if $tobc.check_in.weekday}<div class="travel-obc__muted">{$tobc.check_in.weekday|escape:html}</div>{/if}
        </div>
        <div>
            <div class="travel-obc__label">{$tobc_out_label}</div>
            <div class="travel-obc__value">{$tobc.check_out.date|escape:html}</div>
            {if $tobc.check_out.weekday}<div class="travel-obc__muted">{$tobc.check_out.weekday|escape:html}</div>{/if}
        </div>
        <div>
            <div class="travel-obc__label">{if $tobc.kind == "circuit"}{__("travel_core.obc_trip")}{else}{__("travel_core.obc_stay")}{/if}</div>
            <div class="travel-obc__value">{if $tobc.days}{__("travel_core.obc_n_days", [$tobc.days])}, {/if}{__("travel_core.n_nights", [$tobc.nights])}</div>
            {if $tobc.hotel.location}<div class="travel-obc__muted">{$tobc.hotel.location|escape:html}</div>{/if}
        </div>
    </div>

    {* A circuit or package: how they travel, what is included. *}
    {if $tobc.transport || $tobc.departure || $tobc.services || $tobc.package}
        <div class="travel-obc__trip">
            {if $tobc.package}<span><span class="travel-obc__muted">{__("travel_core.package")}:</span> {$tobc.package|escape:html}</span>{/if}
            {if $tobc.transport}<span><span class="travel-obc__muted">{__("travel_core.obc_transport")}:</span> {if $tobc.transport == "flight"}{__("travel_core.obc_transport_flight")}{elseif $tobc.transport == "bus"}{__("travel_core.obc_transport_bus")}{else}{$tobc.transport|escape:html}{/if}{if $tobc.departure} {__("travel_core.obc_from_city", ["[city]" => $tobc.departure])}{/if}</span>{elseif $tobc.departure}<span><span class="travel-obc__muted">{__("travel_core.obc_departure")}:</span> {$tobc.departure|escape:html}</span>{/if}
            {if $tobc.services}<span><span class="travel-obc__muted">{__("travel_core.obc_extras")}:</span> {", "|implode:$tobc.services|escape:html}</span>{/if}
        </div>
    {/if}

    {* Rooms: each with its board, occupancy and guests. *}
    <div class="travel-obc__rooms">
        <div class="travel-obc__label">{__("travel_core.n_rooms", [$tobc.rooms])} &middot; {__("travel_core.n_adults", [$tobc.adults])}{if $tobc.children}, {__("travel_core.n_children", [$tobc.children])}{/if}</div>
        {foreach from=$tobc.room_cards item="tobc_room"}
            <div class="travel-obc__room">
                <span class="travel-obc__room-no">{$tobc_room.number}</span>
                <div class="travel-obc__room-body">
                    <div>
                        <strong>{if $tobc_room.name}{$tobc_room.name|escape:html}{else}{__("travel_core.room")}{/if}</strong>
                        {if $tobc_room.code}<span class="travel-obc__muted">{$tobc_room.code|escape:html}</span>{/if}
                        {if $tobc_room.board} &middot; {$tobc_room.board|escape:html}{/if}
                        {if $tobc_room.adults} &middot; {__("travel_core.n_adults", [$tobc_room.adults])}{/if}{if $tobc_room.children}, {__("travel_core.n_children", [$tobc_room.children])}{if $tobc_room.children_ages} ({$tobc_room.children_ages|escape:html}){/if}{/if}
                    </div>
                    {if $tobc_room.guests}
                        <div class="travel-obc__guests">
                            {foreach from=$tobc_room.guests item="tobc_guest" name="tobc_guests"}
                                {if !$smarty.foreach.tobc_guests.first} &middot; {/if}{$tobc_guest.name|escape:html}{if $tobc_guest.is_child && $tobc_guest.age} ({$tobc_guest.age}){/if}{if $tobc_guest.is_holder} <span class="travel-obc__lead">{__("travel_core.lead_guest")}</span>{/if}
                            {/foreach}
                        </div>
                    {/if}
                </div>
            </div>
        {/foreach}
        {if $tobc.note}
            <div class="travel-obc__note"><span class="travel-obc__muted">{__("travel_core.obc_supplier_note")}:</span> {$tobc.note|escape:html}</div>
        {/if}
    </div>

    {* Cancellation headline, its full schedule on demand, and payment. *}
    {if $tobc.has_terms}
        <div class="travel-obc__terms">
            {if $tobc.cancel.state == "free"}
                <div class="travel-obc__cancel travel-obc__cancel--ok">
                    <svg class="travel-obc__icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"></circle><polyline points="8 12.5 11 15.5 16 9.5"></polyline></svg>
                    <strong>{__("travel_core.free_cancellation_until")} {$tobc.cancel.free_until|escape:html}</strong>
                </div>
            {elseif $tobc.cancel.state == "partial"}
                {$tobc_now = $tobc.cancel.now.amount_label|default:""}
                {if $tobc.cancel.now.percent_label && $tobc_now}{$tobc_now = "`$tobc.cancel.now.percent_label` (`$tobc_now`)"}{elseif $tobc.cancel.now.percent_label}{$tobc_now = $tobc.cancel.now.percent_label}{/if}
                <div class="travel-obc__cancel travel-obc__cancel--bad">
                    <svg class="travel-obc__icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"></circle><line x1="12" y1="7.5" x2="12" y2="12.5"></line><line x1="12" y1="16" x2="12" y2="16.5"></line></svg>
                    <strong>{__("travel_core.cancel_now_costs", ["[amount]" => $tobc_now])}</strong>
                </div>
            {elseif $tobc.cancel.state == "full"}
                <div class="travel-obc__cancel travel-obc__cancel--bad">
                    <svg class="travel-obc__icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"></circle><line x1="12" y1="7.5" x2="12" y2="12.5"></line><line x1="12" y1="16" x2="12" y2="16.5"></line></svg>
                    <strong>{__("travel_core.cancel_now_full")}</strong>
                </div>
            {/if}
            {if $tobc.terms.cancel_steps || $tobc.terms.cancel_lines}
                <details class="travel-obc__schedule">
                    <summary><span class="travel-obc__toggle-open">{__("travel_core.obc_show_schedule")}</span><span class="travel-obc__toggle-close">{__("travel_core.obc_hide_schedule")}</span></summary>
                    <div class="travel-obc__steps">
                        {foreach from=$tobc.terms.cancel_steps item="tobc_step"}
                            <span>{if $tobc_step.is_no_show}{__("travel_core.no_show")}{elseif $tobc_step.from_label && $tobc_step.to_label}{$tobc_step.from_label|escape:html} &ndash; {$tobc_step.to_label|escape:html}{elseif $tobc_step.to_label}{__("travel_core.due_by", ["[date]" => $tobc_step.to_label])}{elseif $tobc_step.from_label}{__("travel_core.obc_from_date", ["[date]" => $tobc_step.from_label])}{else}{__("travel_core.until_check_in")}{/if}{if $tobc_step.is_current} &middot; {__("travel_core.today")}{/if}</span>
                            <span class="travel-obc__step-value">{if $tobc_step.kind == "free"}{__("travel_core.timeline_free")}{else}{$tobc_step.percent_label|escape:html}{if $tobc_step.percent_label && $tobc_step.amount_label} &middot; {/if}{$tobc_step.amount_label|escape:html}{/if}</span>
                        {/foreach}
                        {foreach from=$tobc.terms.cancel_lines item="tobc_line"}
                            <span class="travel-obc__step-line">{$tobc_line|escape:html}</span>
                        {/foreach}
                    </div>
                </details>
            {/if}
            {if $tobc.terms.payment_steps || $tobc.terms.payment_lines}
                <div class="travel-obc__payment">
                    <span class="travel-obc__muted">{__("travel_core.obc_payment")}:</span>
                    {foreach from=$tobc.terms.payment_steps item="tobc_pay" name="tobc_pay"}
                        {if !$smarty.foreach.tobc_pay.first} &middot; {/if}{$tobc_pay.percent_label|escape:html}{if $tobc_pay.amount_label} ({$tobc_pay.amount_label|escape:html}){/if} {if $tobc_pay.due_label}{__("travel_core.due_by", ["[date]" => $tobc_pay.due_label])}{else}{__("travel_core.obc_on_booking")}{/if}
                    {/foreach}
                    {foreach from=$tobc.terms.payment_lines item="tobc_line" name="tobc_pl"}
                        {if !$smarty.foreach.tobc_pl.first} &middot; {/if}{$tobc_line|escape:html}
                    {/foreach}
                </div>
            {/if}
        </div>
    {/if}

    {* Paid with a deposit: the whole stay, what was paid, what is owed. *}
    {if $tobc.balance}
        <div class="travel-obc__money">
            <div class="travel-obc__grid">
                <div>
                    <div class="travel-obc__label">{__("travel_core.obc_full_stay")}</div>
                    <div class="travel-obc__value">{$tobc.deposit.full|escape:html}</div>
                </div>
                <div>
                    <div class="travel-obc__label">{__("travel_core.obc_deposit_paid")}</div>
                    <div class="travel-obc__value travel-obc__value--ok">{$tobc.deposit.deposit|escape:html}</div>
                </div>
                <div>
                    <div class="travel-obc__label">{__("travel_core.obc_balance_due", ["[date]" => $tobc.balance.due])}</div>
                    <div class="travel-obc__value">{$tobc.balance.amount|escape:html} <span class="travel-obc__state travel-obc__state--{$tobc.balance.state}">{__("travel_core.obc_balance_`$tobc.balance.state`")}</span></div>
                </div>
            </div>
            {if $tobc.balance.state == "paid" && $tobc.balance.balance_order_id}
                <div class="travel-obc__muted">{__("travel_core.balance_paid", ["[order_id]" => $tobc.balance.balance_order_id])}</div>
            {elseif $tobc.balance.pay_query}
                <div class="travel-obc__paylink">
                    <button type="button" class="btn btn-mini travel-obc__copy" data-ca-travel-copy="{$tobc.balance.pay_query|fn_url:"C":"http"|escape:html}" data-ca-travel-copied="{__("travel_core.obc_copied")|escape:html}">{__("travel_core.obc_copy_pay_link")}</button>
                    {if $tobc.balance.reminders}<span class="travel-obc__muted">{__("travel_core.obc_reminders", [$tobc.balance.reminders])}</span>{/if}
                </div>
            {/if}
        </div>
    {/if}

    {if $tobc.supplier_price || $tobc.booked_at || $tobc.updated_at}
        <div class="travel-obc__foot">
            {if $tobc.supplier_price}<span>{__("travel_core.obc_supplier_price")} <strong>{$tobc.supplier_price|escape:html}</strong></span>{/if}
            {if $tobc.booked_at}<span>{__("travel_core.obc_booked_at", ["[date]" => $tobc.booked_at])}</span>{/if}
            {if $tobc.updated_at}<span>{__("travel_core.obc_updated_at", ["[date]" => $tobc.updated_at])}</span>{/if}
        </div>
    {/if}

{if $tobc.collapsible}</details>{else}</div>{/if}
