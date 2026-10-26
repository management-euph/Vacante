{*
    "Condiții de plată și anulare" of a Design A room or rate: the terms in
    full, opened in place (RateOffer terms: sections of plain lines).
    Params: terms, label (the accessible name: whose terms)
*}
<details class="travel-pdpa-terms">
    <summary class="travel-pdpa-linkbtn travel-pdpa-terms__toggle"{if $label} aria-label="{__("travel_core.pdpa_r_terms")}, {$label|escape:html}"{/if}>{__("travel_core.pdpa_r_terms")}</summary>
    <div class="travel-pdpa-terms__body">
        {foreach $terms as $travel_pdpa_section}
            {if $travel_pdpa_section.title}<p class="travel-pdpa-terms__title">{$travel_pdpa_section.title|escape:html}</p>{/if}
            <ul class="travel-pdpa-terms__list">
                {foreach $travel_pdpa_section.lines as $travel_pdpa_line}
                    <li>{$travel_pdpa_line|escape:html}</li>
                {/foreach}
            </ul>
        {/foreach}
    </div>
</details>
