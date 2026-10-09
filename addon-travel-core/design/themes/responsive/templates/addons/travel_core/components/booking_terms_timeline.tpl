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
*}
{$_tt_link = $tt_show_link|default:false}
{$_tt_ids = $tt_ids|default:false}

{if $tt.cancel_steps}
    <ol class="travel-timeline"{if $_tt_ids} id="travel-cancel-lines"{/if}>
        {foreach from=$tt.cancel_steps item="tt_step"}
            <li class="travel-timeline__step travel-timeline__step--{$tt_step.kind}{if $tt_step.is_current} travel-timeline__step--current{/if}">
                <span class="travel-timeline__when">
                    {if $tt_step.is_no_show}{__("travel_core.no_show")}{elseif $tt_step.from_label && $tt_step.to_label}{$tt_step.from_label|escape:html} &ndash; {$tt_step.to_label|escape:html}{elseif $tt_step.to_label}{__("travel_core.until_date", ["[date]" => $tt_step.to_label])}{elseif $tt_step.from_label}{__("travel_core.from_date", ["[date]" => $tt_step.from_label])}{else}{__("travel_core.until_check_in")}{/if}
                    {if $tt_step.is_current}<span class="travel-timeline__tag">{__("travel_core.today")}</span>{/if}
                </span>
                <span class="travel-timeline__what">
                    {if $tt_step.kind == "free"}{__("travel_core.timeline_free")}{else}{__("travel_core.you_pay")} <strong>{if $tt_step.nights > 0}{__("travel_core.n_nights", [$tt_step.nights])}{else}{$tt_step.percent_label|escape:html}{/if}</strong>{if $tt_step.amount_label} &middot; {$tt_step.amount_label nofilter}{/if}{/if}
                </span>
            </li>
        {/foreach}
    </ol>
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
