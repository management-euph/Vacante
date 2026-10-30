{*
 * Travel booking page (travel_bookings.view) — one page for every provider.
 *
 * Every value arrives prepared by ViewModels\BookingPageFactory ($page): the
 * head (status, provider, order, the actions that fit the state), a banner
 * when something needs attention, key facts, the stay with each room and its
 * guests and the cancellation and payment terms (the order page's booking
 * card for this booking's order line), then the supplier's side, the order,
 * the history and the raw provider fields, folded away.
 *
 * Core Smarty only inside the mainbox capture: a failing modifier or PHP
 * call there surfaces as the masked "Not matching {capture}{/capture}"
 * (SmartyTemplateGuardTest). Styling: admin_styles.css .travel-bv*.
 *}

{capture name="mainbox"}

{if $page}
<div class="travel-bv">

    {* Head: what state the booking is in, whose it is, and what to do next. *}
    <div class="travel-bv__head">
        <div class="travel-bv__badges">
            {if $page.status.code}<span class="travel-obc__status travel-obc__status--{$page.status.tone}">{__("travel_core.obc_status_`$page.status.code`")}</span>{/if}
            {if $page.provider.name}<span class="travel-obc__provider">{$page.provider.name|escape:html}</span>{/if}
            <span class="travel-bv__meta">
                {if $page.order_id}{__("travel_core.bv_order")} <a href="{"orders.details?order_id=`$page.order_id`"|fn_url}">#{$page.order_id}</a>{if $page.created} &middot; {/if}{/if}
                {if $page.created}{__("travel_core.bv_created", ["[date]" => $page.created])}{/if}
            </span>
        </div>
        <div class="travel-bv__actions">
            {foreach from=$page.actions item="bv_action"}
                <a class="btn{if $bv_action.primary} btn-primary{/if}{if $bv_action.post} cm-post{/if}{if $bv_action.confirm} cm-confirm{/if}" href="{$bv_action.href|fn_url}">{if $bv_action.kind == "check_status"}{__("travel_core.bv_check_status")}{elseif $bv_action.kind == "order"}{__("travel_core.bv_open_order", ["[id]" => $page.order_id])}{else}{$bv_action.label|escape:html}{/if}</a>
            {/foreach}
        </div>
    </div>

    {* What needs attention: not confirmed yet, failed, or never sent. *}
    {if $page.banner}
        <div class="travel-bv__banner travel-bv__banner--{if $page.banner.kind == "awaiting"}warn{else}bad{/if}">
            <svg class="travel-obc__icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3 2.5 20h19z"></path><line x1="12" y1="10" x2="12" y2="14"></line><line x1="12" y1="17" x2="12" y2="17.5"></line></svg>
            <div class="travel-bv__banner-body">
                {if $page.banner.kind == "awaiting"}
                    <strong>{__("travel_core.bv_awaiting_title", ["[provider]" => $page.provider.name])}</strong>
                    <span>
                        {if $page.banner.has_reference}{__("travel_core.bv_awaiting_ref")}{elseif $page.banner.sent_at}{__("travel_core.bv_awaiting_no_ref", ["[date]" => $page.banner.sent_at])}{/if}
                        {if $page.banner.days_left == 0}{__("travel_core.bv_check_in_today")}{elseif $page.banner.days_left > 0}{__("travel_core.bv_check_in_days", [$page.banner.days_left])}{/if}
                        {__("travel_core.bv_awaiting_hint", ["[provider]" => $page.provider.name])}
                    </span>
                {elseif $page.banner.kind == "not_sent"}
                    <strong>{__("travel_core.obc_not_sent_title", ["[provider]" => $page.provider.name])}</strong>
                    <span>{__("travel_core.obc_not_sent_text")}</span>
                {else}
                    <strong>{__("travel_core.obc_failed_title", ["[provider]" => $page.provider.name])}</strong>
                    <span>{__("travel_core.obc_failed_text")}</span>
                {/if}
                {if $page.banner.error}
                    <span class="travel-bv__banner-error">{__("travel_core.obc_supplier_answer")}: {$page.banner.error|escape:html}</span>
                {/if}
            </div>
        </div>
    {/if}

    {* Key facts at a glance. *}
    <div class="travel-bv__facts">
        <div class="travel-bv__fact">
            <div class="travel-obc__label">{if $page.facts.kind == "circuit"}{__("travel_core.obc_departure")}{else}{__("travel_core.check_in")}{/if}</div>
            <div class="travel-bv__fact-value">{$page.facts.check_in.date|escape:html}</div>
            {if $page.facts.check_in.weekday}<div class="travel-obc__muted">{$page.facts.check_in.weekday|escape:html}</div>{/if}
        </div>
        <div class="travel-bv__fact">
            <div class="travel-obc__label">{if $page.facts.kind == "circuit"}{__("travel_core.obc_return")}{else}{__("travel_core.check_out")}{/if}</div>
            <div class="travel-bv__fact-value">{$page.facts.check_out.date|escape:html}</div>
            {if $page.facts.check_out.weekday}<div class="travel-obc__muted">{$page.facts.check_out.weekday|escape:html}</div>{/if}
        </div>
        <div class="travel-bv__fact">
            <div class="travel-obc__label">{if $page.facts.kind == "circuit"}{__("travel_core.obc_trip")}{else}{__("travel_core.obc_stay")}{/if}</div>
            <div class="travel-bv__fact-value">{if $page.facts.days}{__("travel_core.obc_n_days", [$page.facts.days])}, {/if}{__("travel_core.n_nights", [$page.facts.nights])}</div>
            {if $page.facts.place}<div class="travel-obc__muted">{$page.facts.place|escape:html}</div>{/if}
        </div>
        <div class="travel-bv__fact">
            <div class="travel-obc__label">{__("travel_core.guests")}</div>
            <div class="travel-bv__fact-value">{__("travel_core.n_adults", [$page.facts.adults])}{if $page.facts.children}, {__("travel_core.n_children", [$page.facts.children])}{/if}</div>
            <div class="travel-obc__muted">{__("travel_core.n_rooms", [$page.facts.rooms])}</div>
        </div>
        <div class="travel-bv__fact">
            <div class="travel-obc__label">{__("travel_core.bv_booking_total")}</div>
            <div class="travel-bv__fact-value">{if $page.facts.total}{$page.facts.total|escape:html}{else}&mdash;{/if}</div>
            {if $page.facts.order_total}<div class="travel-obc__muted">{__("travel_core.bv_order_total")} {$page.facts.order_total|escape:html}</div>{/if}
        </div>
    </div>

    <div class="travel-bv__body">
        <div class="travel-bv__main">

            {* The stay: the hotel, the package, every room with its guests. *}
            <section class="travel-bv__card">
                <h4 class="travel-bv__card-title">{__("travel_core.obc_stay")}</h4>
                <div class="travel-bv__hotel">
                    <div>
                        {* As on the order page: the product's name, opening it for editing, and its code. *}
                        <strong>{if $page.stay.hotel_url}<a href="{$page.stay.hotel_url|escape:html}">{$page.stay.hotel|escape:html}</a>{else}{$page.stay.hotel|escape:html}{/if}</strong>
                        {if $page.stay.hotel_code}<div class="travel-obc__muted">{__("sku")}:{$page.stay.hotel_code|escape:html}</div>{/if}
                        {if $page.stay.location}<div class="travel-obc__muted">{$page.stay.location|escape:html}</div>{/if}
                    </div>
                    {if $page.stay.storefront_url}<a class="travel-bv__out" href="{$page.stay.storefront_url|escape:html}" target="_blank" rel="noopener">{__("travel_core.bv_view_storefront")} &#8599;</a>{/if}
                </div>
                {if $page.stay.package}
                    <div class="travel-bv__line"><span class="travel-obc__muted">{__("travel_core.package")}:</span> {$page.stay.package|escape:html}</div>
                {/if}
                {foreach from=$page.stay.rooms item="bv_room"}
                    <div class="travel-bv__room">
                        <div class="travel-bv__room-head">
                            <span class="travel-obc__room-no">{$bv_room.number}</span>
                            <div class="travel-bv__room-title">
                                <div>
                                    <strong>{if $bv_room.name}{$bv_room.name|escape:html}{else}{__("travel_core.room")}{/if}</strong>
                                    {if $bv_room.board} &middot; {$bv_room.board|escape:html}{/if}
                                    {if $bv_room.adults} &middot; {__("travel_core.n_adults", [$bv_room.adults])}{/if}{if $bv_room.children}, {__("travel_core.n_children", [$bv_room.children])}{if $bv_room.children_ages} ({$bv_room.children_ages|escape:html}){/if}{/if}
                                </div>
                                {if $bv_room.supplier_room}
                                    <div class="travel-obc__supplier-room"><span class="travel-obc__muted">{__("travel_core.obc_supplier_room", ["[provider]" => $page.provider.name])}</span> <code>{$bv_room.supplier_room|escape:html}</code></div>
                                {/if}
                            </div>
                        </div>
                        {if $bv_room.guests}
                            <div class="travel-bv__table-wrap">
                                <table class="travel-bv__guests">
                                    <thead>
                                        <tr>
                                            <th scope="col">{__("travel_core.bv_guest")}</th>
                                            <th scope="col">{__("travel_core.bv_type")}</th>
                                            <th scope="col">{__("travel_core.bv_age")}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {foreach from=$bv_room.guests item="bv_guest"}
                                            <tr>
                                                <td>{$bv_guest.name|escape:html}{if $bv_guest.is_holder} <span class="travel-obc__lead">{__("travel_core.lead_guest")}</span>{/if}</td>
                                                <td>{if $bv_guest.is_child}{__("travel_core.bv_child")}{else}{__("travel_core.bv_adult")}{/if}</td>
                                                <td>{if $bv_guest.age}{$bv_guest.age|escape:html}{else}&mdash;{/if}</td>
                                            </tr>
                                        {/foreach}
                                    </tbody>
                                </table>
                            </div>
                        {/if}
                    </div>
                {/foreach}
                {if $page.supplier.note}
                    <div class="travel-bv__line"><span class="travel-obc__muted">{__("travel_core.obc_supplier_note")}:</span> {$page.supplier.note|escape:html}</div>
                {/if}
            </section>

            {* Cancellation headline, the whole schedule and payment. *}
            {if $page.terms}
                <section class="travel-bv__card">
                    <h4 class="travel-bv__card-title">{__("travel_core.bv_terms")}</h4>
                    {if $page.terms.cancel.state == "free"}
                        <div class="travel-obc__cancel travel-obc__cancel--ok"><strong>{__("travel_core.free_cancellation_until")} {$page.terms.cancel.free_until|escape:html}</strong></div>
                    {elseif $page.terms.cancel.state == "partial"}
                        {$bv_now = $page.terms.cancel.now.amount_label|default:""}
                        {if $page.terms.cancel.now.percent_label && $bv_now}{$bv_now = "`$page.terms.cancel.now.percent_label` (`$bv_now`)"}{elseif $page.terms.cancel.now.percent_label}{$bv_now = $page.terms.cancel.now.percent_label}{/if}
                        <div class="travel-obc__cancel travel-obc__cancel--bad"><strong>{__("travel_core.cancel_now_costs", ["[amount]" => $bv_now])}</strong></div>
                    {elseif $page.terms.cancel.state == "full"}
                        <div class="travel-obc__cancel travel-obc__cancel--bad"><strong>{__("travel_core.cancel_now_full")}</strong></div>
                    {/if}
                    {if $page.terms.lists.cancel_steps || $page.terms.lists.cancel_lines}
                        <ol class="travel-bv__steps">
                            {foreach from=$page.terms.lists.cancel_steps item="bv_step"}
                                <li class="travel-bv__step{if $bv_step.is_current} travel-bv__step--current{/if}">
                                    <span>{if $bv_step.is_no_show}{__("travel_core.no_show")}{elseif $bv_step.from_label && $bv_step.to_label}{$bv_step.from_label|escape:html} &ndash; {$bv_step.to_label|escape:html}{elseif $bv_step.to_label}{__("travel_core.due_by", ["[date]" => $bv_step.to_label])}{elseif $bv_step.from_label}{__("travel_core.obc_from_date", ["[date]" => $bv_step.from_label])}{else}{__("travel_core.until_check_in")}{/if}{if $bv_step.is_current} <span class="travel-bv__today">{__("travel_core.today")}</span>{/if}</span>
                                    <strong>{if $bv_step.kind == "free"}{__("travel_core.timeline_free")}{else}{$bv_step.percent_label|escape:html}{if $bv_step.percent_label && $bv_step.amount_label} &middot; {/if}{$bv_step.amount_label|escape:html}{/if}</strong>
                                </li>
                            {/foreach}
                            {foreach from=$page.terms.lists.cancel_lines item="bv_line"}
                                <li class="travel-bv__step"><span>{$bv_line|escape:html}</span></li>
                            {/foreach}
                        </ol>
                    {/if}
                    {if $page.terms.lists.payment_steps || $page.terms.lists.payment_lines}
                        <div class="travel-bv__line">
                            <span class="travel-obc__muted">{__("travel_core.obc_payment")}:</span>
                            {foreach from=$page.terms.lists.payment_steps item="bv_pay" name="bv_pay"}
                                {if !$smarty.foreach.bv_pay.first} &middot; {/if}<strong>{$bv_pay.percent_label|escape:html}{if $bv_pay.amount_label} ({$bv_pay.amount_label|escape:html}){/if}</strong> {if $bv_pay.due_label}{__("travel_core.due_by", ["[date]" => $bv_pay.due_label])}{else}{__("travel_core.obc_on_booking")}{/if}
                            {/foreach}
                            {foreach from=$page.terms.lists.payment_lines item="bv_line" name="bv_pl"}
                                {if !$smarty.foreach.bv_pl.first} &middot; {/if}{$bv_line|escape:html}
                            {/foreach}
                        </div>
                    {/if}
                </section>
            {/if}
        </div>

        <div class="travel-bv__side">

            {* The supplier's side: status, reference, price, its own numbers. *}
            <section class="travel-bv__card">
                <h4 class="travel-bv__card-title">{__("travel_core.bv_supplier")}{if $page.provider.name} <span class="travel-obc__provider">{$page.provider.name|escape:html}</span>{/if}</h4>
                <dl class="travel-bv__dl">
                    <dt>{__("travel_core.bv_status")}</dt>
                    <dd>{if $page.status.code}<span class="travel-bv__tone--{$page.status.tone}">{__("travel_core.obc_status_`$page.status.code`")}</span>{else}&mdash;{/if}</dd>
                    <dt>{__("travel_core.bv_reference")}</dt>
                    <dd>{if $page.supplier.reference}<strong>{$page.supplier.reference|escape:html}</strong> <button type="button" class="btn btn-mini travel-obc__copy" data-ca-travel-copy="{$page.supplier.reference|escape:html}" data-ca-travel-copied="{__("travel_core.obc_copied")|escape:html}" aria-label="{__("travel_core.obc_copy_ref")|escape:html}">{__("travel_core.obc_copy")}</button>{else}<span class="travel-obc__muted">{__("travel_core.bv_not_received")}</span>{/if}</dd>
                    {if $page.supplier.price}
                        <dt>{__("travel_core.obc_supplier_price")}</dt>
                        <dd><strong>{$page.supplier.price|escape:html}</strong></dd>
                    {/if}
                    {foreach from=$page.supplier.fields item="bv_field"}
                        <dt>{$bv_field.label|escape:html}</dt>
                        <dd>{$bv_field.value|escape:html}</dd>
                    {/foreach}
                </dl>
                {if !$page.supplier.reference && $page.banner.kind == "awaiting"}
                    <p class="travel-bv__hint">{__("travel_core.bv_ids_hint", ["[provider]" => $page.provider.name])}</p>
                {/if}
            </section>

            {* The order that holds it. *}
            {if $page.order}
                <section class="travel-bv__card">
                    <h4 class="travel-bv__card-title">{__("travel_core.bv_order")}</h4>
                    <dl class="travel-bv__dl">
                        <dt>{__("travel_core.bv_order")}</dt>
                        <dd><a href="{"orders.details?order_id=`$page.order.id`"|fn_url}"><strong>#{$page.order.id}</strong></a></dd>
                        {if $page.order.total}
                            <dt>{__("travel_core.bv_order_total")}</dt>
                            <dd>{$page.order.total|escape:html}</dd>
                        {/if}
                        {if $page.order.placed}
                            <dt>{__("travel_core.bv_placed")}</dt>
                            <dd>{$page.order.placed|escape:html}</dd>
                        {/if}
                    </dl>
                </section>
            {/if}

            {* Its moments, newest first. *}
            {if $page.history}
                <section class="travel-bv__card">
                    <h4 class="travel-bv__card-title">{__("travel_core.bv_history")}</h4>
                    <ol class="travel-bv__history">
                        {foreach from=$page.history item="bv_event"}
                            <li>
                                <strong>{if $bv_event.kind == "order"}{__("travel_core.bv_history_order", ["[id]" => $bv_event.order_id])}{elseif $bv_event.kind == "linked"}{__("travel_core.bv_history_linked", ["[id]" => $bv_event.order_id])}{elseif $bv_event.kind == "created"}{__("travel_core.bv_history_created")}{else}{__("travel_core.bv_history_updated")}{/if}</strong>
                                <span class="travel-obc__muted">{$bv_event.at|escape:html}</span>
                            </li>
                        {/foreach}
                    </ol>
                </section>
            {/if}

            {* Every raw provider field, for troubleshooting. *}
            {if $page.technical}
                <details class="travel-bv__card travel-bv__technical">
                    <summary>{__("travel_core.bv_technical")}</summary>
                    <p class="travel-bv__hint">{__("travel_core.bv_technical_hint")}</p>
                    <dl class="travel-bv__dl">
                        {foreach from=$page.technical item="bv_row"}
                            <dt>{$bv_row.label|escape:html}</dt>
                            <dd>{if $bv_row.is_pre}<pre class="travel-bv__pre">{$bv_row.value|escape:html}</pre>{elseif $bv_row.value !== ""}{$bv_row.value|escape:html}{else}&mdash;{/if}</dd>
                        {/foreach}
                    </dl>
                </details>
            {/if}
        </div>
    </div>
</div>
{/if}

{/capture}

{$_bk_title = __("travel_core.obc_booking_n", ["[id]" => $page.booking_id])}
{if $page.stay.hotel}{$_bk_title = "`$_bk_title` · `$page.stay.hotel`"}{/if}

{include file="common/mainbox.tpl"
    title=$_bk_title
    content=$smarty.capture.mainbox
}
