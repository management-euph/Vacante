{*
    "Condiții de plată și anulare" of a Design A room (RoomResultsBuilder
    TermsView), opened in place: structured terms as the booking sidebar
    shows them (booking_terms_timeline.tpl), prose terms as sections of
    lines, or terms loaded when opened (a supplier without them in the
    search: pdp-design-a.js fetches the url, JSON {sections}). One set for
    all the room's options, or each option's, shown with the chosen one.
    The link's chevron turns when open. On a phone (pdp-design-a.js, CSS
    under 768 px) the panel is a bottom sheet over the page: its head names
    the room and closes it, its foot books as the card's button does.
    Params: set, chosen (the room's chosen option), label (whose terms)
*}
<details class="travel-pdpa-terms"
    {if $set.for >= 0}data-travel-for="{$set.for}"{if $set.for != $chosen} hidden{/if}{/if}
    {if $set.url}data-travel-terms-url="{$set.url|escape:html}"{/if}>
    <summary class="travel-pdpa-linkbtn travel-pdpa-terms__toggle"{if $label} aria-label="{__("travel_core.pdpa_r_terms")}, {$label|escape:html}"{/if}>{__("travel_core.pdpa_r_terms")}{include file="addons/travel_core/components/design_a_icon.tpl" icon="chev" size=14}</summary>
    <div class="travel-pdpa-terms__panel" data-travel-terms-panel>
    <div class="travel-pdpa-terms__sheethead">
        <div>
            <p class="travel-pdpa-terms__sheett">{__("travel_core.pdpa_r_terms")}</p>
            {if $label}<p class="travel-pdpa-terms__sheets">{$label|escape:html}</p>{/if}
        </div>
        <button type="button" class="travel-pdpa-terms__x" data-travel-terms-close aria-label="{__("travel_core.tl_terms_close")}">{include file="addons/travel_core/components/design_a_icon.tpl" icon="x"}</button>
    </div>
    <div class="travel-pdpa-terms__body"{if $set.url} data-travel-terms-body aria-live="polite" data-error="{__("travel_core.pdpa_r_terms_error")}"{/if}>
        {if $set.timeline}
            {include file="addons/travel_core/components/booking_terms_timeline.tpl" tt=$set.timeline}
        {/if}
        {foreach $set.terms as $travel_pdpa_section}
            {if $travel_pdpa_section.title}<p class="travel-pdpa-terms__title">{$travel_pdpa_section.title|escape:html}</p>{/if}
            <ul class="travel-pdpa-terms__list">
                {foreach $travel_pdpa_section.lines as $travel_pdpa_line}
                    <li>{$travel_pdpa_line|escape:html}</li>
                {/foreach}
            </ul>
        {/foreach}
        {if $set.url}
            <p class="travel-pdpa-terms__wait" data-travel-terms-wait>{__("travel_core.pdpa_r_terms_loading")}</p>
        {/if}
    </div>
    <div class="travel-pdpa-terms__sheetfoot"><button type="button" class="travel-pdpa-btn travel-pdpa-terms__cta" data-travel-terms-cta></button></div>
    </div>
</details>
