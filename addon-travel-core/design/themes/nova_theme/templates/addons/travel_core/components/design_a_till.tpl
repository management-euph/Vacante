{*
    The till of a Design A room for one of its options (board 13b): the
    meal, the stay and guests, the struck price with its badge (an empty
    line of the same height without one, so the totals and the buttons of
    all cards line up), the total, per night, the button and one short line
    under it: "Nu plătești încă", or on request "Răspuns în max. 48 h" with
    an ⓘ that opens how a request works (on click or tap, not on hover).
    The room shows its chosen option's till; pdp-design-a.js switches them
    with the radios. Included by design_a_room.tpl with $travel_pdpa_room
    and $travel_pdpa_option set.
*}
{if $travel_pdpa_option.on_request}
    {$travel_pdpa_action = __("travel_core.pdpa_r_request")}
{else}
    {$travel_pdpa_action = __("travel_core.pdpa_r_book")}
{/if}
{$travel_pdpa_tip_id = "travel-pdpa-`$travel_pdpa_room.key`-tip`$travel_pdpa_option.index`"}
<div class="travel-pdpa-till" data-travel-for="{$travel_pdpa_option.index}" data-travel-say="{$travel_pdpa_option.meal|escape:html}, {__("travel_core.pdpa_r_total_short")} {$travel_pdpa_option.price|escape:html}"{if !$travel_pdpa_option.chosen} hidden{/if}>
    <p class="travel-pdpa-till__sum">
        <span class="travel-pdpa-till__meal">{$travel_pdpa_option.meal|escape:html}</span>
        <span class="travel-pdpa-till__l">{__("travel_core.pdpa_r_total", ["[nights]" => $travel_pdpa_nights, "[guests]" => $travel_pdpa_guests|escape:html])}</span>
        <span class="travel-pdpa-till__was">{if $travel_pdpa_option.was}<span class="travel-pdpa-sr">{__("travel_core.pdpa_r_was")}: </span><s class="travel-pdpa-strike">{$travel_pdpa_option.was|escape:html}</s>{if $travel_pdpa_option.off}<span class="travel-pdpa-off"><span class="travel-pdpa-sr">{__("travel_core.pdpa_r_off")} </span>{$travel_pdpa_option.off|escape:html}</span>{/if}{/if}</span>
        <b class="travel-pdpa-till__total">{$travel_pdpa_option.price|escape:html}</b>
        {if $travel_pdpa_option.per_night}<span class="travel-pdpa-unit">{__("travel_core.pdpa_r_per_night", ["[price]" => $travel_pdpa_option.per_night|escape:html])}</span>{/if}
    </p>
    <div class="travel-pdpa-cta">
        <a href="{$travel_pdpa_option.book_url|escape:html}" class="travel-pdpa-btn travel-pdpa-till__btn"
           aria-label="{$travel_pdpa_action|escape:html}: {$travel_pdpa_room.title|escape:html}, {$travel_pdpa_option.meal|escape:html}, {$travel_pdpa_option.price|escape:html}">{$travel_pdpa_action}</a>
        {if $travel_pdpa_option.on_request}
            <p class="travel-pdpa-cta__note">{include file="addons/travel_core/components/design_a_icon.tpl" icon="clock" size=14}{__("travel_core.pdpa_r_reply_48h")}<button type="button" class="travel-pdpa-tip" data-travel-tip aria-expanded="false" aria-controls="{$travel_pdpa_tip_id}" aria-label="{__("travel_core.pdpa_r_how_request")}">{include file="addons/travel_core/components/design_a_icon.tpl" icon="info"}</button></p>
            <div class="travel-pdpa-tip__pop" id="{$travel_pdpa_tip_id}" role="dialog" aria-labelledby="{$travel_pdpa_tip_id}-h" data-travel-tip-pop hidden>
                <p class="travel-pdpa-tip__h" id="{$travel_pdpa_tip_id}-h">{__("travel_core.pdpa_r_how_request")}</p>
                <ol class="travel-pdpa-tip__list">
                    <li><b>{__("travel_core.pdpa_r_request_step1_lead")}</b> {__("travel_core.pdpa_r_request_step1")}</li>
                    <li><b>{__("travel_core.pdpa_r_request_step2_lead")}</b> {__("travel_core.pdpa_r_request_step2")}</li>
                    <li><b>{__("travel_core.pdpa_r_request_step3_lead")}</b> {__("travel_core.pdpa_r_request_step3")}</li>
                </ol>
                <button type="button" class="travel-pdpa-tip__x" data-travel-tip-close aria-label="{__("travel_core.pdpa_r_close")}">{include file="addons/travel_core/components/design_a_icon.tpl" icon="x"}</button>
            </div>
        {else}
            <p class="travel-pdpa-cta__note">{__("travel_core.pdpa_r_no_pay_yet")}</p>
        {/if}
    </div>
</div>
