{*
    "What are my booking conditions?" — the modal the link in the sidebar's
    cancellation & payment card opens (the link itself lives in
    booking_terms_timeline.tpl), shared by every provider
    (2 surfaces x 2 themes, synced by `composer mirror`).

    Data contract: $travel_booking_sidebar (the same view variable the summary
    sidebar reads) — the timeline steps (cancel_steps / payment_steps) or,
    for prose terms, `payment_lines` and `cancel_lines`; all already
    formatted and localized by the provider controller.

    MULTI-ROOM: the body is a list of SECTIONS, one per room. Sphinx books a
    single room and renders its section server-side. Novoton re-prices each
    room on load and its booking-form.js appends/updates one section per room
    into #travel-conditions-rooms — which is why the section markup lives in a
    template the JS mirrors rather than being built ad hoc.

    Behaviour (open/close, ESC, backdrop) is in
    js/addons/travel_core/booking-conditions.js — no inline script.
*}
{$tbc = $travel_booking_sidebar}

<div class="travel-conditions-modal travel-is-hidden" id="travel-conditions-modal" role="dialog" aria-modal="true" aria-labelledby="travel-conditions-title" aria-hidden="true">
    <div class="travel-conditions-modal__backdrop" data-travel-conditions-close></div>
    <div class="travel-conditions-modal__dialog">
        <div class="travel-conditions-modal__head">
            <h3 class="travel-conditions-modal__title" id="travel-conditions-title">{__("travel_core.booking_conditions_link")}</h3>
            <button type="button" class="travel-conditions-modal__close" data-travel-conditions-close aria-label="{__("travel_core.close")|escape:html}">&times;</button>
        </div>
        <div class="travel-conditions-modal__body" id="travel-conditions-rooms">
            {* Server-rendered section. Providers that only learn their terms
               from a later price call leave both lists empty and let the JS
               fill this container instead. *}
            {$tbc_has = $tbc.cancel_steps || $tbc.payment_steps || $tbc.payment_lines || $tbc.cancel_lines}
            {if $tbc_has}
                <section class="travel-conditions-room" data-room="1">
                    {if $tbc.room_label}
                        <h4 class="travel-conditions-room__title">{$tbc.room_label|escape:html}</h4>
                    {/if}
                    <div class="travel-conditions-group">
                        <strong>{__("travel_core.cancellation_policy")}</strong>
                        {include file="addons/travel_core/components/booking_terms_timeline.tpl" tt=$tbc}
                    </div>
                </section>
            {/if}
        </div>
        <p class="travel-conditions-modal__empty{if $tbc_has} travel-is-hidden{/if}" id="travel-conditions-empty">{__("travel_core.booking_conditions_loading")}</p>
    </div>
</div>
