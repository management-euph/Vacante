{** Travel Core - Hotel availability
    Every supplier hotel's state as the weekly review left it
    (docs/novoton-availability-gaps-plan.md §6, §10), its product's status
    and what the availability gate did or would do, with filters and a CSV
    of what they match. A decision on a hotel (keep visible, keep hidden,
    closed) is saved from its row and applied at once in Automatic mode; a
    run the gate's brake held is confirmed from the supplier's tile. **}

{capture name="sidebar"}
<div class="sidebar-row">
    <h6>{__("search")}</h6>

    <form action="{""|fn_url}" method="get" name="travel_availability_filter_form">
        <input type="hidden" name="dispatch" value="travel_hotel_availability.manage" />

        <div class="sidebar-field">
            <label for="travel_avail_provider">{__("travel_core.provider")}:</label>
            <select name="provider" id="travel_avail_provider">
                <option value="">--</option>
                {foreach from=$availability_labels key=provider item=label}
                    <option value="{$provider|escape:html}" {if $search.provider == $provider}selected{/if}>{$label|escape:html}</option>
                {/foreach}
            </select>
        </div>

        <div class="sidebar-field">
            <label for="travel_avail_state">{__("travel_core.availability_state")}:</label>
            <select name="state" id="travel_avail_state">
                <option value="">--</option>
                {foreach from=$availability_states item=state}
                    <option value="{$state}" {if $search.state == $state}selected{/if}>{__("travel_core.availability_state_`$state`")}</option>
                {/foreach}
            </select>
        </div>

        <div class="sidebar-field">
            <label for="travel_avail_months">{__("travel_core.availability_no_offer_for")}:</label>
            <select name="no_offer_months" id="travel_avail_months">
                <option value="">--</option>
                {foreach from=$availability_offer_months item=months}
                    <option value="{$months}" {if $search.no_offer_months == $months}selected{/if}>{__("travel_core.availability_months", ["[n]" => $months])}</option>
                {/foreach}
            </select>
        </div>

        <div class="sidebar-field">
            <label for="travel_avail_gate">{__("travel_core.availability_gate_filter")}:</label>
            <select name="gate" id="travel_avail_gate">
                <option value="">--</option>
                {foreach from=$availability_gate_filters item=gate}
                    <option value="{$gate}" {if $search.gate == $gate}selected{/if}>{__("travel_core.availability_gate_`$gate`")}</option>
                {/foreach}
            </select>
        </div>

        <div class="sidebar-field">
            <label for="travel_avail_q">{__("search")}:</label>
            <input type="text" name="q" id="travel_avail_q" value="{$search.q|escape:html}" placeholder="{__("travel_core.availability_search_placeholder")|escape:html}" />
        </div>

        <div class="sidebar-field">
            <label for="travel_avail_sort">{__("travel_core.availability_sort")}:</label>
            <select name="sort_by" id="travel_avail_sort">
                <option value="hotel" {if $search.sort_by == "hotel"}selected{/if}>{__("travel_core.availability_sort_hotel")}</option>
                <option value="offer" {if $search.sort_by == "offer"}selected{/if}>{__("travel_core.availability_sort_offer")}</option>
                <option value="changed" {if $search.sort_by == "changed"}selected{/if}>{__("travel_core.availability_sort_changed")}</option>
            </select>
        </div>

        <div class="sidebar-field">
            <input type="submit" class="btn" value="{__("search")}" />
        </div>
    </form>
</div>
{/capture}

{capture name="mainbox"}

<div class="travel-cron travel-avail">

    <p class="travel-cron-subtitle">{__("travel_core.availability_page_intro")}</p>

    {* ── Each supplier at a glance; a state links to its hotels ── *}
    {if $availability_labels}
    <div class="travel-cron-tiles">
        {foreach from=$availability_labels key=provider item=label}
        {assign var="mode" value=$availability_modes.$provider|default:"report"}
        <div class="travel-cron-tile">
            <div class="travel-cron-tile__label">{$label|escape:html} · {__("travel_core.availability_mode_`$mode`")}</div>
            {if $availability_counts.$provider}
                <ul class="travel-avail-counts">
                {foreach from=$availability_states item=state}
                    {assign var="hotels" value=$availability_counts.$provider.$state|default:0}
                    {if $hotels > 0}
                    <li>
                        <a href="{"travel_hotel_availability.manage?provider=`$provider`&state=`$state`"|fn_url}">
                            <span class="travel-cron-pill travel-cron-pill--{$availability_pills.$state}">{__("travel_core.availability_state_`$state`")}</span>
                            <strong>{$hotels}</strong>
                        </a>
                    </li>
                    {/if}
                {/foreach}
                </ul>
            {else}
                <div class="travel-cron-tile__note">{__("travel_core.availability_no_hotels_supplier")}</div>
            {/if}
            {* What the availability gate did (Automatic) or would do (Report only) *}
            {assign var="gc" value=$availability_gate_counts.$provider|default:[]}
            {if $gc}
                <div class="travel-cron-tile__note travel-avail-gate">
                    {if $availability_automatic.$provider}
                        <a href="{"travel_hotel_availability.manage?provider=`$provider`&gate=hidden"|fn_url}">{__("travel_core.availability_tile_hidden", ["[n]" => $gc.hidden])}</a>
                    {else}
                        <a href="{"travel_hotel_availability.manage?provider=`$provider`&gate=would_hide"|fn_url}">{__("travel_core.availability_tile_would_hide", ["[n]" => $gc.would_hide])}</a>
                    {/if}
                    {if $gc.released > 0} · <a href="{"travel_hotel_availability.manage?provider=`$provider`&gate=released"|fn_url}">{__("travel_core.availability_tile_released", ["[n]" => $gc.released])}</a>{/if}
                    {if $gc.override > 0} · <a href="{"travel_hotel_availability.manage?provider=`$provider`&gate=override"|fn_url}">{__("travel_core.availability_tile_override", ["[n]" => $gc.override])}</a>{/if}
                </div>
                {* The brake held the last review's hiding: the admin checks the list, then confirms *}
                {assign var="braked" value=$availability_summary.providers.$provider.gate.braked|default:0}
                {if $availability_automatic.$provider && $braked > 0 && $gc.would_hide > 0}
                    <div class="travel-cron-hint travel-cron-hint--warn travel-avail-brake">
                        <p>{__("travel_core.availability_braked", ["[n]" => $gc.would_hide, "[min]" => $availability_brake.min, "[percent]" => $availability_brake.percent])}
                            <a href="{"travel_hotel_availability.manage?provider=`$provider`&gate=would_hide"|fn_url}">{__("travel_core.availability_braked_check")}</a></p>
                        <form method="post" action="{"travel_hotel_availability.apply_gate"|fn_url}">
                            <input type="hidden" name="security_hash" value="{$security_hash}" />
                            <input type="hidden" name="provider" value="{$provider|escape:html}" />
                            <button type="submit" class="btn btn-primary cm-confirm">{__("travel_core.availability_hide_now", ["[n]" => $gc.would_hide])}</button>
                        </form>
                    </div>
                {/if}
            {/if}
        </div>
        {/foreach}
    </div>
    {/if}

    {* ── The weekly review: when, what it changed, run it now ── *}
    <section class="travel-cron-card" aria-labelledby="travel-avail-review-title">
        <div class="travel-cron-card__head">
            <div>
                <h3 id="travel-avail-review-title">{__("travel_core.availability_review_title")}</h3>
                <p class="muted">
                    {if $availability_summary}
                        {__("travel_core.availability_last_review", ["[date]" => $availability_summary.at|date_format:"%d.%m.%Y %H:%M"])}{if $availability_next_review}, {__("travel_core.availability_next_review", ["[date]" => $availability_next_review|date_format:"%d.%m.%Y"])}{/if}.
                    {else}
                        {__("travel_core.availability_no_review_yet")}
                    {/if}
                </p>
                {if $availability_last_failed}
                    <p class="travel-cron-hint travel-cron-hint--bad">{__("travel_core.availability_review_failed", ["[error]" => $availability_last_failed|escape:html])}</p>
                {/if}
            </div>
            <div class="travel-avail-actions">
                <a class="btn" href="{$availability_export_url|fn_url}">{__("travel_core.availability_export_csv")}</a>
                <form method="post" action="{"travel_hotel_availability.run"|fn_url}">
                    <input type="hidden" name="security_hash" value="{$security_hash}" />
                    <button type="submit" class="btn btn-primary">{__("travel_core.availability_review_now")}</button>
                </form>
            </div>
        </div>

        {if $availability_summary}
            {foreach from=$availability_summary.providers key=provider item=ps}
                {if $ps.changes}
                {assign var="listed" value=$ps.changes|count}
                <h4 class="travel-avail-changes__title">{$availability_labels.$provider|default:$provider|escape:html}: {__("travel_core.availability_mail_changes")}</h4>
                <ul class="travel-avail-changes">
                    {foreach from=$ps.changes item=ch}
                    <li>
                        {$ch.hotel_name|default:"#`$ch.hotel_id`"|escape:html}:
                        {__("travel_core.availability_state_`$ch.from`")} &rarr; {__("travel_core.availability_state_`$ch.to`")}
                        <span class="muted">({__("travel_core.availability_reason_`$ch.reason`")})</span>
                    </li>
                    {/foreach}
                </ul>
                {if $ps.changed > $listed}
                    <p class="muted">{__("travel_core.availability_mail_more", ["[n]" => $ps.changed-$listed])}</p>
                {/if}
                {/if}
            {/foreach}
        {/if}
    </section>

    <details class="travel-avail-legend">
        <summary>{__("travel_core.availability_legend")}</summary>
        <dl>
            {foreach from=$availability_states item=state}
                <dt><span class="travel-cron-pill travel-cron-pill--{$availability_pills.$state}">{__("travel_core.availability_state_`$state`")}</span></dt>
                <dd>{__("travel_core.availability_state_`$state`_hint", ["[months]" => $availability_dormant_months])}</dd>
            {/foreach}
        </dl>
    </details>

{include file="common/pagination.tpl" save_current_url=true}

{if $availability_rows}
<table class="table table-middle table-striped travel-avail-table">
    <thead>
        <tr>
            <th>{__("travel_core.hotel")}</th>
            <th width="100">{__("travel_core.provider")}</th>
            <th width="180">{__("travel_core.availability_state")}</th>
            <th width="230">{__("travel_core.availability_product")}</th>
            <th>{__("travel_core.availability_reason")}</th>
            <th width="120">{__("travel_core.availability_last_offer")}</th>
            <th width="170">{__("travel_core.availability_prices")}</th>
            <th width="110">{__("travel_core.availability_last_booking")}</th>
            <th width="120">{__("travel_core.availability_hotel_list")}</th>
        </tr>
    </thead>
    <tbody>
        {foreach from=$availability_rows item=row}
        {assign var="provider" value=$row.provider}
        {assign var="state" value=$row.state}
        <tr>
            <td>
                {if $row.product_id}
                    <a href="{"products.update?product_id=`$row.product_id`"|fn_url}">{$row.hotel_name|default:"#`$row.hotel_id`"|escape:html}</a>
                {else}
                    {$row.hotel_name|default:"#`$row.hotel_id`"|escape:html}
                {/if}
                <div class="muted travel-avail-sub">
                    {$row.hotel_id|escape:html}{if $row.destination_key} · {$row.destination_key|escape:html}{/if}{if !$row.product_id} · {__("travel_core.availability_no_product")}{/if}
                </div>
            </td>
            <td>{$availability_labels.$provider|default:$provider|escape:html}</td>
            <td>
                <span class="travel-cron-pill travel-cron-pill--{$availability_pills.$state|default:"none"}">{__("travel_core.availability_state_`$state`")}</span>
                {if $row.state_since}<div class="muted travel-avail-sub">{__("travel_core.availability_since", ["[date]" => $row.state_since|date_format:"%d.%m.%Y"])}</div>{/if}
                {if $row.season_published == "Y"}<div class="travel-avail-sub">{__("travel_core.availability_next_season")}</div>{/if}
            </td>
            <td>
                {if $row.product_id}
                    {if $row.product_status == "A"}{__("active")}{elseif $row.product_status == "H"}{__("hidden")}{elseif $row.product_status == "D"}{__("disabled")}{else}&mdash;{/if}
                    {if $row.gate_hidden == "Y"}<div class="travel-avail-sub">{__("travel_core.availability_gate_hidden")}</div>{/if}
                    {if $row.gate_released == "Y"}<div class="travel-avail-sub muted">{__("travel_core.availability_gate_released")}</div>{/if}
                    {if $row.would_hide}<div class="travel-cron-hint travel-cron-hint--warn">{if $availability_automatic.$provider}{__("travel_core.availability_gate_next_review")}{else}{__("travel_core.availability_gate_would_hide")}{/if}</div>{/if}
                    {* The admin's decision on this hotel: wins over the rules *}
                    <form method="post" action="{""|fn_url}" class="travel-avail-override">
                        <input type="hidden" name="dispatch" value="travel_hotel_availability.override" />
                        <input type="hidden" name="security_hash" value="{$security_hash}" />
                        <input type="hidden" name="provider" value="{$row.provider|escape:html}" />
                        <input type="hidden" name="hotel_id" value="{$row.hotel_id|escape:html}" />
                        <input type="hidden" name="hotel_name" value="{$row.hotel_name|escape:html}" />
                        <input type="hidden" name="return_query" value="{$availability_return_query|escape:html}" />
                        <select name="override" aria-label="{__("travel_core.availability_override_label", ["[hotel]" => $row.hotel_name|default:"#`$row.hotel_id`"])|escape:html}">
                            <option value="">{__("travel_core.availability_override_none")}</option>
                            {foreach from=$availability_overrides item=ov}
                                <option value="{$ov}" {if $row.override == $ov}selected{/if}>{__("travel_core.availability_override_`$ov`")}</option>
                            {/foreach}
                        </select>
                        <input type="text" name="override_note" value="{$row.override_note|escape:html}" maxlength="255" placeholder="{__("travel_core.availability_override_note")|escape:html}" aria-label="{__("travel_core.availability_override_note")|escape:html}" />
                        <button type="submit" class="btn btn-small">{__("save")}</button>
                    </form>
                {else}
                    <span class="muted">{__("travel_core.availability_no_product")}</span>
                {/if}
            </td>
            <td>{if $row.state_reason}{__("travel_core.availability_reason_`$row.state_reason`")}{else}&mdash;{/if}</td>
            <td>
                {if $row.offer_seen_at}
                    {$row.offer_seen_at|date_format:"%d.%m.%Y"}
                    {if $row.offer_stay_date}<div class="muted travel-avail-sub">{__("travel_core.availability_for_stay", ["[date]" => $row.offer_stay_date|date_format:"%d.%m.%Y"])}</div>{/if}
                {else}
                    <span class="muted">{__("travel_core.availability_never")}</span>
                {/if}
            </td>
            <td>
                {if $row.contract_from}
                    {$row.contract_from|date_format:"%d.%m.%Y"} &rarr; {$row.contract_to|date_format:"%d.%m.%Y"}
                {else}
                    <span class="muted">{__("travel_core.availability_none_published")}</span>
                {/if}
                {if $row.contract_last_date}<div class="muted travel-avail-sub">{__("travel_core.availability_last_priced", ["[date]" => $row.contract_last_date|date_format:"%d.%m.%Y"])}</div>{/if}
            </td>
            <td>{if $row.last_booking_check_in}{$row.last_booking_check_in|date_format:"%d.%m.%Y"}{else}&mdash;{/if}</td>
            <td>
                {if $row.listed_last_seen_at}{$row.listed_last_seen_at|date_format:"%d.%m.%Y"}{else}&mdash;{/if}
                {if $row.listed_missing_runs > 0}<div class="travel-cron-hint travel-cron-hint--warn">{__("travel_core.availability_missed_lists", ["[n]" => $row.listed_missing_runs])}</div>{/if}
            </td>
        </tr>
        {/foreach}
    </tbody>
</table>
{else}
    <p class="no-items">{__("travel_core.availability_no_records")}</p>
{/if}

{include file="common/pagination.tpl"}

</div>

{/capture}

{include file="common/mainbox.tpl"
    title=__("travel_core.hotel_availability")
    content=$smarty.capture.mainbox
    sidebar=$smarty.capture.sidebar
}
