{*
    Cancellation & payment timeline — the body of the sidebar's cancellation
    card and of each room section in the "What are my booking conditions?"
    modal, for every provider. Novoton's price re-check renders this same
    file server-side and swaps it in, so the markup lives only here.

    Parameters:
      tt            (array, required) the sidebar view array, or any array
                    with the same keys: cancel_steps, payment_steps (from
                    TermsTimelineFactory), and the legacy fallbacks
                    cancel_lines, cancel_free_until, cancel_full_amount,
                    payment_lines / payment_lines_html for prose terms
                    (supplier text, store-written payment text).
      tt_show_link  (bool, default false) the booking-conditions link.
      tt_ids        (bool, default false) the sidebar copy's element ids
                    (#travel-cancel-lines / -free / -free-date); off in the
                    modal so ids stay unique.

    Colours: the step in force today is amber with a neutral "Today" tag —
    policy terms, not an error. A free step is green.

    One list: each period says when, what cancelling costs and how much
    (the amount on the right; "≈" only for N average nights, when the
    provider gave no night's own price). A provider that knows more says so
    on the steps (TermsTimelineFactory $meta, Novoton): the hour a deadline
    day ends ("Până pe 4 nov. 2026, ora 23:59", "Până mâine …", "Până azi
    la ora 23:59", and the note under the list) and the last period, from
    the arrival ("Din 9 nov. 2026 (ziua sosirii) și la neprezentare").
    No payment method is named here (the owner's rule).
*}
{$_tt_link = $tt_show_link|default:false}
{$_tt_ids = $tt_ids|default:false}

{if $tt.cancel_steps}
    <ol class="travel-timeline"{if $_tt_ids} id="travel-cancel-lines"{/if}>
        {foreach from=$tt.cancel_steps item="tt_step"}
            <li class="travel-timeline__step travel-timeline__step--{$tt_step.kind}{if $tt_step.is_current} travel-timeline__step--current{/if}">
                {$_tt_time = $tt_step.to_time|default:""}
                {$_tt_rel = $tt_step.to_rel|default:""}
                <span class="travel-timeline__when">
                    {if $tt_step.is_no_show}{__("travel_core.no_show")}{elseif $tt_step.is_last|default:false}{if $tt_step.is_current || !$tt_step.from_label}{__("travel_core.tl_last_today")}{elseif $tt_step.from_arrival|default:false}{__("travel_core.tl_last_arrival", ["[date]" => $tt_step.from_label])}{else}{__("travel_core.tl_last_from", ["[date]" => $tt_step.from_label])}{/if}{elseif $tt_step.from_label && $tt_step.to_label}{$tt_step.from_label|escape:html} &ndash; {$tt_step.to_label|escape:html}{elseif $tt_step.to_label && $_tt_time && $_tt_rel == "today"}{__("travel_core.tl_until_today", ["[time]" => $_tt_time])}{elseif $tt_step.to_label && $_tt_time && $_tt_rel == "tomorrow"}{__("travel_core.tl_until_tomorrow", ["[date]" => $tt_step.to_label, "[time]" => $_tt_time])}{elseif $tt_step.to_label && $_tt_time}{__("travel_core.tl_until_time", ["[date]" => $tt_step.to_label, "[time]" => $_tt_time])}{elseif $tt_step.to_label}{__("travel_core.until_date", ["[date]" => $tt_step.to_label])}{elseif $tt_step.from_label}{__("travel_core.from_date", ["[date]" => $tt_step.from_label])}{else}{__("travel_core.until_check_in")}{/if}
                    {if $tt_step.is_current}<span class="travel-timeline__tag">{__("travel_core.today")}</span>{/if}
                </span>
                <span class="travel-timeline__what">{if $tt_step.kind == "free"}{__("travel_core.timeline_free")}{elseif $tt_step.nights > 0 && $tt_step.approx|default:false}{__("travel_core.tl_cost_nights_avg", [$tt_step.nights])}{elseif $tt_step.nights > 0}{__("travel_core.tl_cost_first_nights", [$tt_step.nights])}{elseif $tt_step.kind == "full"}{__("travel_core.tl_cost_all")}{elseif $tt_step.percent_label}{__("travel_core.tl_cost_percent", ["[percent]" => $tt_step.percent_label])}{else}{__("travel_core.tl_cost")}{/if}</span>
                {if $tt_step.amount_label}<b class="travel-timeline__amt">{if $tt_step.approx|default:false}&asymp;&nbsp;{/if}{$tt_step.amount_label nofilter}</b>{/if}
            </li>
        {/foreach}
    </ol>
    {if $tt.cancel_cutoff|default:""}<p class="travel-timeline__note">{__("travel_core.tl_cutoff_note", ["[time]" => $tt.cancel_cutoff])}</p>{/if}
{elseif $tt.cancel_lines || $tt.cancel_free_until || $tt.cancel_full_amount}
    {* Prose terms (supplier text, legacy shapes): lines as the API sent them. *}
    {if $tt.cancel_free_until}
        <div class="travel-bsidebar-freecancel"{if $_tt_ids} id="travel-cancel-free"{/if}>
            &#10003; {__("travel_core.free_cancellation_until")} <strong{if $_tt_ids} id="travel-cancel-free-date"{/if}>{$tt.cancel_free_until|escape:html}</strong>
        </div>
    {/if}
    {if $tt.cancel_full_amount}
        <div class="travel-bsidebar-cancelrow">
            <mark class="travel-bsidebar-cancelhl">{__("travel_core.cancel_you_will_pay")}</mark>
            <strong>{$tt.cancel_full_amount nofilter}</strong>
        </div>
    {/if}
    <ul class="travel-bsidebar-cancel"{if $_tt_ids} id="travel-cancel-lines"{/if}>
        {foreach from=$tt.cancel_lines item="tt_cancel"}
            <li>{$tt_cancel|escape:html}</li>
        {/foreach}
    </ul>
{/if}

{if $tt.payment_steps || $tt.payment_lines}
    <div class="travel-bsidebar-payment">
        <span class="travel-bsidebar-payment__title">{__("travel_core.payment_terms")}</span>
        <ul>
            {if $tt.payment_steps}
                {foreach from=$tt.payment_steps item="tt_pay"}
                    <li><strong>{$tt_pay.percent_label|escape:html}</strong>{if $tt_pay.amount_label} &middot; {$tt_pay.amount_label nofilter}{/if} {if $tt_pay.is_now}{__("travel_core.due_now")}{elseif $tt_pay.at_booking|default:false}{__("travel_core.obc_on_booking")}{else}{__("travel_core.due_by", ["[date]" => $tt_pay.due_label])}{/if}</li>
                {/foreach}
            {elseif $tt.payment_lines_html}
                {foreach from=$tt.payment_lines_html item="tt_pay_line"}
                    <li>{$tt_pay_line nofilter}</li>
                {/foreach}
            {else}
                {foreach from=$tt.payment_lines item="tt_pay_line"}
                    <li>{$tt_pay_line|escape:html}</li>
                {/foreach}
            {/if}
        </ul>
    </div>
{/if}

{if $_tt_link}
    <a href="#" class="travel-conditions-link" data-travel-conditions-open>{__("travel_core.booking_conditions_link")}</a>
{/if}
