{*
    Under a circuit's rooms (board 16.3): its program day by day, the hotels
    on the route, the things to know, and what the price includes and does
    not. ViewModels\CircuitDetailsViewFactory builds $travel_circuit from
    what the circuit's supplier says; the menu names only the sections there
    is something for, and jumps to them.

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

    {if $travel_circuit.days || $travel_circuit.stays || $travel_circuit.notes_shown}
    <div class="travel-circuit-details__grid">
        {if $travel_circuit.days}
        <section class="travel-circuit-program" id="travel-circuit-program" aria-labelledby="travel-circuit-program-title">
            {* The menu names it: the board shows no heading here. *}
            <h2 class="travel-pdpa-sr" id="travel-circuit-program-title">{__("travel_core.circuit_menu_program")}</h2>
            <ol class="travel-circuit-days">
                {foreach $travel_circuit.days as $travel_circuit_day}
                    <li class="travel-circuit-day">
                        <span class="travel-circuit-day__n" aria-hidden="true">{$travel_circuit_day.number}</span>
                        <h3 class="travel-circuit-day__title">
                            {__("travel_core.circuit_day", ["[n]" => $travel_circuit_day.number])}{if $travel_circuit_day.title} · {$travel_circuit_day.title|escape:html}{/if}
                        </h3>
                        {foreach $travel_circuit_day.paragraphs as $travel_circuit_text}
                            <p class="travel-circuit-day__text">{$travel_circuit_text|escape:html|nl2br nofilter}</p>
                        {/foreach}
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
                    </li>
                {/foreach}
            </ol>
        </section>
        {/if}

        {if $travel_circuit.stays || $travel_circuit.notes_shown}
        <div class="travel-circuit-details__side">
            {if $travel_circuit.stays}
            <section class="travel-circuit-card" id="travel-circuit-stays" aria-labelledby="travel-circuit-stays-title">
                <h2 class="travel-circuit-card__title" id="travel-circuit-stays-title">{__("travel_core.circuit_hotels")}</h2>
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
                    {if $travel_circuit_note.title}<h3 class="travel-circuit-card__subtitle">{$travel_circuit_note.title|escape:html}</h3>{/if}
                    {foreach $travel_circuit_note.paragraphs as $travel_circuit_text}
                        <p class="travel-circuit-card__text">{$travel_circuit_text|escape:html|nl2br nofilter}</p>
                    {/foreach}
                {/foreach}
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
</section>
{/if}
