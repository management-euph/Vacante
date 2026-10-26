{*
    Design A hotel page: the room-first results under the search bar
    (docs/hotel-page-design-a-plan.md, phase 3; Design A board frames 3, 10
    and 12). A supplier's search renders it in place of its own results
    when the hotel page asks (design=a); the inline search swaps it in under
    the bar.

    Params: results — Results\RoomResultsBuilder::build(), every amount and
    date already formatted. The rules live there: the meal strip only with
    two or more meal plans (R1), one meal plan said once in the fact line
    (R2), a chip only when it splits the list, shared facts not repeated
    (R3), the best four rates per room, the long list collapsed.

    pdp-design-a.js: meal strip and chips filter the rates, the sort orders
    the rooms, "see all" opens a room's other rates.
*}
{$travel_pdpa_results = $results}
{if $travel_pdpa_results && $travel_pdpa_results.rooms}
    {$travel_pdpa_rates_text = __("travel_core.pdpa_r_rates_`$travel_pdpa_results.rate_form`", ["[n]" => $travel_pdpa_results.rate_count])}
    {$travel_pdpa_rooms_text = __("travel_core.pdpa_r_room_types_`$travel_pdpa_results.room_form`", ["[n]" => $travel_pdpa_results.room_count])}
    {$travel_pdpa_adults = __("travel_core.pdpa_r_adults_`$travel_pdpa_results.adults_form`", ["[n]" => $travel_pdpa_results.adults])}
    {if $travel_pdpa_results.children > 0}
        {$travel_pdpa_kids = __("travel_core.pdpa_r_children_`$travel_pdpa_results.children_form`", ["[n]" => $travel_pdpa_results.children])}
        {$travel_pdpa_guests = "`$travel_pdpa_adults` + `$travel_pdpa_kids`"}
    {else}
        {$travel_pdpa_guests = $travel_pdpa_adults}
    {/if}
    {$travel_pdpa_nights = __("travel_core.pdpa_r_nights_`$travel_pdpa_results.nights_form`", ["[n]" => $travel_pdpa_results.nights])}

    <section class="travel-pdpa-results" id="travel-pdpa-rooms" aria-labelledby="travel-pdpa-rooms-title" data-travel-results data-cheapest="{$travel_pdpa_results.cheapest|escape:html}">
        <div class="travel-pdpa-rhead">
            <h2 class="travel-pdpa-rhead__title" id="travel-pdpa-rooms-title">{__("travel_core.pdpa_r_title")}</h2>
            <p class="travel-pdpa-rhead__count" role="status">{__("travel_core.pdpa_r_count", ["[rates]" => $travel_pdpa_rates_text, "[rooms]" => $travel_pdpa_rooms_text])}</p>
        </div>

        {* R1: the lowest price of each meal plan, also the meal filter *}
        {if $travel_pdpa_results.meal_plans|count > 1}
            <div class="travel-pdpa-meals" role="group" aria-label="{__("travel_core.pdpa_r_meals_label")}">
                <button type="button" class="travel-pdpa-meal" aria-pressed="true" data-travel-meal="">
                    {include file="addons/travel_core/components/design_a_icon.tpl" icon="meal"}
                    <span class="travel-pdpa-meal__n">{__("travel_core.pdpa_r_all_meals")}</span>
                    <span class="travel-pdpa-meal__p travel-pdpa-meal__p--n">{$travel_pdpa_rates_text}</span>
                </button>
                {foreach $travel_pdpa_results.meal_plans as $travel_pdpa_plan}
                    <button type="button" class="travel-pdpa-meal" aria-pressed="false" data-travel-meal="{$travel_pdpa_plan.key}">
                        {include file="addons/travel_core/components/design_a_icon.tpl" icon="meal"}
                        <span class="travel-pdpa-meal__n">{$travel_pdpa_plan.name|escape:html}</span>
                        <span class="travel-pdpa-meal__p">{__("travel_core.pdpa_from")} <b>{$travel_pdpa_plan.from|escape:html}</b></span>
                    </button>
                {/foreach}
            </div>
        {/if}

        {* R2 / R3: what every rate shares, said once *}
        {if $travel_pdpa_results.facts.meal || $travel_pdpa_results.facts.immediate || $travel_pdpa_results.facts.free_until}
            <p class="travel-pdpa-rfacts">
                {if $travel_pdpa_results.facts.meal}
                    <span class="travel-pdpa-rfacts__item">{include file="addons/travel_core/components/design_a_icon.tpl" icon="meal"}{__("travel_core.pdpa_r_fact_meal", ["[meal]" => $travel_pdpa_results.facts.meal|escape:html])}</span>
                {/if}
                {if $travel_pdpa_results.facts.immediate}
                    <span class="travel-pdpa-rfacts__item">{include file="addons/travel_core/components/design_a_icon.tpl" icon="check"}{__("travel_core.pdpa_r_fact_immediate")}</span>
                {/if}
                {if $travel_pdpa_results.facts.free_until}
                    <span class="travel-pdpa-rfacts__item">{include file="addons/travel_core/components/design_a_icon.tpl" icon="check"}{__("travel_core.pdpa_r_fact_free", ["[date]" => $travel_pdpa_results.facts.free_until|escape:html])}</span>
                {/if}
            </p>
        {/if}

        {if $travel_pdpa_results.chips || $travel_pdpa_results.room_count > 1}
            <div class="travel-pdpa-rtools">
                {if $travel_pdpa_results.chips}
                    <div class="travel-pdpa-rtools__chips" role="group" aria-label="{__("travel_core.pdpa_r_filters_label")}">
                        {foreach $travel_pdpa_results.chips as $travel_pdpa_chip}
                            <button type="button" class="travel-pdpa-chip" aria-pressed="false" data-travel-chip="{$travel_pdpa_chip.key}">{__("travel_core.pdpa_r_chip_`$travel_pdpa_chip.key`")} <span class="travel-pdpa-chip__n">({$travel_pdpa_chip.count})</span></button>
                        {/foreach}
                    </div>
                {/if}
                {if $travel_pdpa_results.room_count > 1}
                    <label class="travel-pdpa-sort">
                        {include file="addons/travel_core/components/design_a_icon.tpl" icon="sort"}
                        <span class="travel-pdpa-sort__label">{__("travel_core.pdpa_r_sort")}:</span>
                        <select class="travel-pdpa-sort__select" data-travel-sort>
                            <option value="low" selected>{__("travel_core.pdpa_r_sort_low")}</option>
                            <option value="high">{__("travel_core.pdpa_r_sort_high")}</option>
                        </select>
                    </label>
                {/if}
            </div>
        {/if}

        <p class="travel-pdpa-note travel-pdpa-rnote">{include file="addons/travel_core/components/design_a_icon.tpl" icon="info"}<span>{__("travel_core.pdpa_r_not_yet")}</span></p>

        <ul class="travel-pdpa-rlist" data-travel-room-list>
            {foreach $travel_pdpa_results.rooms as $travel_pdpa_room}
                {include file="addons/travel_core/components/design_a_room.tpl"}
            {/foreach}
        </ul>

        <div class="travel-pdpa-rempty" data-travel-no-match hidden>
            <p>{__("travel_core.pdpa_r_no_match")}</p>
            <button type="button" class="travel-pdpa-linkbtn" data-travel-clear-filters>{__("travel_core.pdpa_r_clear_filters")}</button>
        </div>

        <p class="travel-pdpa-rfoot">
            {if $travel_pdpa_results.meals_included}
                {__("travel_core.pdpa_r_footnote_meals", ["[nights]" => $travel_pdpa_nights, "[guests]" => $travel_pdpa_guests|escape:html])}
            {else}
                {__("travel_core.pdpa_r_footnote", ["[nights]" => $travel_pdpa_nights, "[guests]" => $travel_pdpa_guests|escape:html])}
            {/if}
        </p>
    </section>
{/if}
