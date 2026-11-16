{*
    Under a circuit's rooms (board 16.3; docs/circuit-page-plan.md step 3):
    the trip's high points, its program day by day (each day opens and
    closes; its photos load from the supplier's servers), the hotels, the
    things to know, what the price includes and does not, and the optional
    excursions. ViewModels\CircuitDetailsViewFactory builds $travel_circuit
    from what the circuit's supplier says; the menu names only the sections
    there is something for, and jumps to them. Every supplier text is
    escaped.

    Params: $travel_circuit (CircuitDetailsViewFactory::view())
*}
{if $travel_circuit && $travel_circuit.menu}
<section class="travel-circuit-details" aria-label="{__("travel_core.circuit_menu_label")|escape:html}">
    <nav class="travel-circuit-menu" aria-label="{__("travel_core.circuit_menu_label")|escape:html}">
        <ul class="travel-circuit-menu__list">
            {foreach $travel_circuit.menu as $travel_circuit_section}
                <li><a class="travel-circuit-menu__link" href="#travel-circuit-{$travel_circuit_section}">{__("travel_core.circuit_menu_`$travel_circuit_section`")}</a></li>
            {/foreach}
        </ul>
    </nav>

    {if $travel_circuit.highlights.items}
    <section class="travel-circuit-highlights" aria-labelledby="travel-circuit-highlights-title">
        <h2 class="travel-circuit-highlights__title" id="travel-circuit-highlights-title">{if $travel_circuit.highlights.title}{$travel_circuit.highlights.title|escape:html}{else}{__("travel_core.circuit_highlights")}{/if}</h2>
        <ul class="travel-circuit-highlights__list">
            {foreach $travel_circuit.highlights.items as $travel_circuit_item}
                <li>{include file="addons/travel_core/components/design_a_icon.tpl" icon="check"}<span>{$travel_circuit_item|escape:html}</span></li>
            {/foreach}
        </ul>
    </section>
    {/if}

    {if $travel_circuit.days || $travel_circuit.stays || $travel_circuit.accommodation.hotel || $travel_circuit.accommodation.stars > 0 || $travel_circuit.notes_shown}
    <div class="travel-circuit-details__grid">
        {if $travel_circuit.days}
        <section class="travel-circuit-program" id="travel-circuit-program" aria-labelledby="travel-circuit-program-title">
            {* The menu names it: the board shows no heading here. *}
            <h2 class="travel-pdpa-sr" id="travel-circuit-program-title">{__("travel_core.circuit_menu_program")}</h2>
            {if $travel_circuit.days|count > 1}
                {* pdp-design-a.js opens or closes every day; without it each day still opens on its own. *}
                <button type="button" class="travel-circuit-program__toggle" data-travel-circuit-expand hidden
                        aria-pressed="false"
                        data-label-expand="{__("travel_core.circuit_expand_all")|escape:html}"
                        data-label-collapse="{__("travel_core.circuit_collapse_all")|escape:html}">{__("travel_core.circuit_expand_all")}</button>
            {/if}
            <ol class="travel-circuit-days">
                {foreach $travel_circuit.days as $travel_circuit_day name=travel_circuit_days}
                    <li class="travel-circuit-day">
                        <span class="travel-circuit-day__n" aria-hidden="true">{$travel_circuit_day.number}</span>
                        <details class="travel-circuit-day__body"{if $smarty.foreach.travel_circuit_days.first} open{/if}>
                            <summary class="travel-circuit-day__summary">
                                <h3 class="travel-circuit-day__title">
                                    {__("travel_core.circuit_day", ["[n]" => $travel_circuit_day.number])}{if $travel_circuit_day.title} · {$travel_circuit_day.title|escape:html}{/if}
                                    {if $travel_circuit_day.highlighted}<span class="travel-circuit-day__badge">{__("travel_core.circuit_day_highlight")}</span>{/if}
                                </h3>
                                <span class="travel-circuit-day__chev" aria-hidden="true">{include file="addons/travel_core/components/design_a_icon.tpl" icon="chev"}</span>
                            </summary>
                            {foreach $travel_circuit_day.paragraphs as $travel_circuit_text}
                                <p class="travel-circuit-day__text">{$travel_circuit_text|escape:html|nl2br nofilter}</p>
                            {/foreach}
                            {if $travel_circuit_day.photos}
                                <ul class="travel-circuit-day__photos">
                                    {foreach $travel_circuit_day.photos as $travel_circuit_photo name=travel_circuit_photos}
                                        <li><img src="{$travel_circuit_photo|escape:html}" alt="{__("travel_core.circuit_day_photo", ["[n]" => $travel_circuit_day.number, "[m]" => $smarty.foreach.travel_circuit_photos.iteration])|escape:html}" loading="lazy" decoding="async" width="160" height="108"></li>
                                    {/foreach}
                                </ul>
                            {/if}
                            {if $travel_circuit_day.stars > 0 || $travel_circuit_day.hotel || $travel_circuit_day.meals}
                                <p class="travel-circuit-day__facts">
                                    {if $travel_circuit_day.stars > 0}
                                        <span>{include file="addons/travel_core/components/design_a_icon.tpl" icon="bed"}{__("travel_core.circuit_hotel")} {$travel_circuit_day.stars}★</span>
                                    {elseif $travel_circuit_day.hotel}
                                        <span>{include file="addons/travel_core/components/design_a_icon.tpl" icon="bed"}{$travel_circuit_day.hotel|escape:html}</span>
                                    {/if}
                                    {if $travel_circuit_day.meals}
                                        <span>{include file="addons/travel_core/components/design_a_icon.tpl" icon="meal"}{$travel_circuit_day.meals|escape:html}</span>
                                    {/if}
                                </p>
                            {/if}
                        </details>
                    </li>
                {/foreach}
            </ol>
        </section>
        {/if}

        {if $travel_circuit.stays || $travel_circuit.accommodation.hotel || $travel_circuit.accommodation.stars > 0 || $travel_circuit.notes_shown}
        <div class="travel-circuit-details__side">
            {if $travel_circuit.stays || $travel_circuit.accommodation.hotel || $travel_circuit.accommodation.stars > 0}
            <section class="travel-circuit-card" id="travel-circuit-stays" aria-labelledby="travel-circuit-stays-title">
                <h2 class="travel-circuit-card__title" id="travel-circuit-stays-title">{__("travel_core.circuit_menu_stays")}</h2>
                {if $travel_circuit.accommodation.hotel || $travel_circuit.accommodation.stars > 0}
                    <p class="travel-circuit-hotel">
                        <span class="travel-circuit-hotel__name">{if $travel_circuit.accommodation.hotel}{$travel_circuit.accommodation.hotel|escape:html}{else}{__("travel_core.circuit_hotel_stars", ["[n]" => $travel_circuit.accommodation.stars])}{/if}</span>
                        {if $travel_circuit.accommodation.hotel && $travel_circuit.accommodation.stars > 0}
                            <span class="travel-circuit-hotel__stars" role="img" aria-label="{__("travel_core.stars_rating", ["[rating]" => $travel_circuit.accommodation.stars])|escape:html}">{"★"|str_repeat:$travel_circuit.accommodation.stars}</span>
                        {/if}
                        <span class="travel-circuit-hotel__similar">{__("travel_core.circuit_or_similar")}</span>
                    </p>
                    {foreach $travel_circuit.accommodation.sections as $travel_circuit_part}
                        {if $travel_circuit_part.title}<h3 class="travel-circuit-card__subtitle">{$travel_circuit_part.title|escape:html}</h3>{/if}
                        {foreach $travel_circuit_part.paragraphs as $travel_circuit_text}
                            <p class="travel-circuit-card__text">{$travel_circuit_text|escape:html|nl2br nofilter}</p>
                        {/foreach}
                    {/foreach}
                {/if}
                {if $travel_circuit.stays}
                <ul class="travel-circuit-stays">
                    {foreach $travel_circuit.stays as $travel_circuit_stay}
                        <li class="travel-circuit-night">
                            <span class="travel-circuit-night__when">
                                {if $travel_circuit_stay.from == $travel_circuit_stay.to}
                                    {__("travel_core.circuit_night", ["[n]" => $travel_circuit_stay.from])}
                                {else}
                                    {__("travel_core.circuit_nights_range", ["[from]" => $travel_circuit_stay.from, "[to]" => $travel_circuit_stay.to])}
                                {/if}
                            </span>
                            <span class="travel-circuit-night__hotel">
                                {if $travel_circuit_stay.hotel}{$travel_circuit_stay.hotel|escape:html}{else}{__("travel_core.circuit_hotel")}{/if}{if $travel_circuit_stay.stars > 0} {$travel_circuit_stay.stars}★{/if} {__("travel_core.circuit_or_similar")}
                            </span>
                        </li>
                    {/foreach}
                </ul>
                {/if}
            </section>
            {/if}

            {if $travel_circuit.notes_shown}
            <section class="travel-circuit-card" id="travel-circuit-notes" aria-labelledby="travel-circuit-notes-title">
                <h2 class="travel-circuit-card__title" id="travel-circuit-notes-title">{__("travel_core.circuit_menu_notes")}</h2>
                {if $travel_circuit.group_min > 0}
                    <p class="travel-circuit-card__text">{__("travel_core.circuit_group_min_`$travel_circuit.group_min_form`", ["[n]" => $travel_circuit.group_min])}</p>
                {/if}
                {if $travel_circuit.age_min > 0}
                    <p class="travel-circuit-card__text">{__("travel_core.circuit_age_min_`$travel_circuit.age_min_form`", ["[n]" => $travel_circuit.age_min])}</p>
                {/if}
                {if $travel_circuit.age_max > 0}
                    <p class="travel-circuit-card__text">{__("travel_core.circuit_age_max_`$travel_circuit.age_max_form`", ["[n]" => $travel_circuit.age_max])}</p>
                {/if}
                {foreach $travel_circuit.notes as $travel_circuit_note}
                    <details class="travel-circuit-note">
                        <summary class="travel-circuit-note__summary">
                            <h3 class="travel-circuit-card__subtitle">{if $travel_circuit_note.title}{$travel_circuit_note.title|escape:html}{else}{__("travel_core.circuit_menu_notes")}{/if}</h3>
                            <span class="travel-circuit-day__chev" aria-hidden="true">{include file="addons/travel_core/components/design_a_icon.tpl" icon="chev"}</span>
                        </summary>
                        {foreach $travel_circuit_note.paragraphs as $travel_circuit_text}
                            <p class="travel-circuit-card__text">{$travel_circuit_text|escape:html|nl2br nofilter}</p>
                        {/foreach}
                    </details>
                {/foreach}
                {foreach $travel_circuit.price_notes as $travel_circuit_note}
                    <h3 class="travel-circuit-card__subtitle">{if $travel_circuit_note.title}{$travel_circuit_note.title|escape:html}{else}{__("travel_core.circuit_price_notes")}{/if}</h3>
                    <ul class="travel-circuit-card__lines">
                        {foreach $travel_circuit_note.lines as $travel_circuit_text}
                            <li>{$travel_circuit_text|escape:html}</li>
                        {/foreach}
                    </ul>
                {/foreach}
                {if $travel_circuit.documents}
                    <h3 class="travel-circuit-card__subtitle">{__("travel_core.circuit_documents")}</h3>
                    <ul class="travel-circuit-docs">
                        {foreach $travel_circuit.documents as $travel_circuit_doc}
                            <li><a class="travel-circuit-docs__link" href="{$travel_circuit_doc.url|escape:html}" target="_blank" rel="noopener">{include file="addons/travel_core/components/design_a_icon.tpl" icon="file"}<span>{if $travel_circuit_doc.title}{$travel_circuit_doc.title|escape:html}{else}{__("travel_core.circuit_documents")}{/if}</span><span class="travel-pdpa-sr"> ({__("travel_core.circuit_new_tab")})</span></a></li>
                        {/foreach}
                    </ul>
                {/if}
            </section>
            {/if}
        </div>
        {/if}
    </div>
    {/if}

    {if $travel_circuit.included || $travel_circuit.not_included}
    <section class="travel-circuit-include" id="travel-circuit-include" aria-labelledby="travel-circuit-include-title">
        <h2 class="travel-pdpa-sr" id="travel-circuit-include-title">{__("travel_core.circuit_menu_include")}</h2>
        <div class="travel-circuit-include__grid">
            {if $travel_circuit.included}
            <div class="travel-circuit-include__col">
                <h3 class="travel-circuit-include__head">{__("travel_core.circuit_included")}</h3>
                <ul class="travel-circuit-include__list travel-circuit-include__list--yes">
                    {foreach $travel_circuit.included as $travel_circuit_item}
                        <li>{include file="addons/travel_core/components/design_a_icon.tpl" icon="check"}<span>{$travel_circuit_item|escape:html}</span></li>
                    {/foreach}
                </ul>
            </div>
            {/if}
            {if $travel_circuit.not_included}
            <div class="travel-circuit-include__col">
                <h3 class="travel-circuit-include__head">{__("travel_core.circuit_not_included")}</h3>
                <ul class="travel-circuit-include__list travel-circuit-include__list--no">
                    {foreach $travel_circuit.not_included as $travel_circuit_item}
                        <li>{include file="addons/travel_core/components/design_a_icon.tpl" icon="nocheck"}<span>{$travel_circuit_item|escape:html}</span></li>
                    {/foreach}
                </ul>
            </div>
            {/if}
        </div>
    </section>
    {/if}

    {if $travel_circuit.excursions || $travel_circuit.excursion_notes}
    <section class="travel-circuit-excursions" id="travel-circuit-excursions" aria-labelledby="travel-circuit-excursions-title">
        <h2 class="travel-pdpa-sr" id="travel-circuit-excursions-title">{__("travel_core.circuit_menu_excursions")}</h2>
        <p class="travel-circuit-excursions__intro">{__("travel_core.circuit_excursions_intro")}</p>
        {if $travel_circuit.excursions}
        <ul class="travel-circuit-excursions__list">
            {foreach $travel_circuit.excursions as $travel_circuit_excursion}
                <li class="travel-circuit-excursion">
                    <h3 class="travel-circuit-excursion__title">{$travel_circuit_excursion.title|escape:html}{if $travel_circuit_excursion.highlighted} <span class="travel-circuit-day__badge">{__("travel_core.circuit_day_highlight")}</span>{/if}</h3>
                    {foreach $travel_circuit_excursion.paragraphs as $travel_circuit_text}
                        <p class="travel-circuit-excursion__text">{$travel_circuit_text|escape:html|nl2br nofilter}</p>
                    {/foreach}
                    {if $travel_circuit_excursion.price}
                        <p class="travel-circuit-excursion__price">{__("travel_core.circuit_excursion_price", ["[price]" => $travel_circuit_excursion.price|escape:html])}</p>
                    {/if}
                </li>
            {/foreach}
        </ul>
        {/if}
        {foreach $travel_circuit.excursion_notes as $travel_circuit_note}
            <div class="travel-circuit-excursions__note">
                {if $travel_circuit_note.title}<h3 class="travel-circuit-card__subtitle">{$travel_circuit_note.title|escape:html}</h3>{/if}
                {foreach $travel_circuit_note.paragraphs as $travel_circuit_text}
                    <p class="travel-circuit-card__text">{$travel_circuit_text|escape:html|nl2br nofilter}</p>
                {/foreach}
            </div>
        {/foreach}
    </section>
    {/if}
</section>
{/if}
