{*
    Design A hotel page: the room-first results under the search bar, by the
    owner's board 13b (docs/hotel-page-design-a-plan.md). A supplier's search
    renders it in place of its own results when the hotel page asks
    (design=a); the inline search swaps it in under the bar.

    Params: results — Results\RoomResultsBuilder::build(), every amount and
    date already formatted. The rules live there: one option per meal plan
    and terms, what a room's options share said once, the meal filter only
    with two or more meal plans, a chip only when it splits the options, the
    first four room types before "see all".

    pdp-design-a.js: the meal filter and the chips show the rooms that have
    it and choose it in them, a room's radios switch its till, the sort
    orders the rooms, "see all" shows the others.
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
            <p class="travel-pdpa-rhead__count" role="status">
                {if $travel_pdpa_results.single_meal}
                    {__("travel_core.pdpa_r_count_meal_`$travel_pdpa_results.room_form`", ["[rooms]" => $travel_pdpa_rooms_text, "[meal]" => $travel_pdpa_results.single_meal|escape:html])}
                {else}
                    {__("travel_core.pdpa_r_count", ["[rates]" => $travel_pdpa_rates_text, "[rooms]" => $travel_pdpa_rooms_text])}
                {/if}
            </p>
        </div>

        {* Two or more meal plans: each with how many room types have it and its lowest price, also the filter *}
        {if $travel_pdpa_results.meal_plans}
            <div class="travel-pdpa-meals" role="group" aria-label="{__("travel_core.pdpa_r_meals_label")}">
                <button type="button" class="travel-pdpa-meal" aria-pressed="true" data-travel-meal=""
                        aria-label="{__("travel_core.pdpa_r_meal_aria", ["[meal]" => __("travel_core.pdpa_r_all_meals"), "[rooms]" => $travel_pdpa_rooms_text, "[price]" => $travel_pdpa_results.cheapest|escape:html])}">
                    {include file="addons/travel_core/components/design_a_icon.tpl" icon="meal"}
                    <span class="travel-pdpa-meal__n">{__("travel_core.pdpa_r_all_meals")} <span class="travel-pdpa-meal__c">({$travel_pdpa_results.room_count})</span></span>
                    <span class="travel-pdpa-meal__p">{__("travel_core.pdpa_from")} <b>{$travel_pdpa_results.cheapest|escape:html}</b></span>
                </button>
                {foreach $travel_pdpa_results.meal_plans as $travel_pdpa_plan}
                    {$travel_pdpa_plan_rooms = __("travel_core.pdpa_r_room_types_`$travel_pdpa_plan.form`", ["[n]" => $travel_pdpa_plan.count])}
                    <button type="button" class="travel-pdpa-meal" aria-pressed="false" data-travel-meal="{$travel_pdpa_plan.key}"
                            aria-label="{__("travel_core.pdpa_r_meal_aria", ["[meal]" => $travel_pdpa_plan.name|escape:html, "[rooms]" => $travel_pdpa_plan_rooms, "[price]" => $travel_pdpa_plan.from|escape:html])}">
                        {include file="addons/travel_core/components/design_a_icon.tpl" icon="meal"}
                        <span class="travel-pdpa-meal__n">{$travel_pdpa_plan.name|escape:html} <span class="travel-pdpa-meal__c">({$travel_pdpa_plan.count})</span></span>
                        <span class="travel-pdpa-meal__p">{__("travel_core.pdpa_from")} <b>{$travel_pdpa_plan.from|escape:html}</b></span>
                    </button>
                {/foreach}
            </div>
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

        <ul class="travel-pdpa-rlist" id="travel-pdpa-room-list" data-travel-room-list>
            {foreach $travel_pdpa_results.rooms as $travel_pdpa_room}
                {include file="addons/travel_core/components/design_a_room.tpl"}
            {/foreach}
        </ul>

        {if $travel_pdpa_results.more}
            <div class="travel-pdpa-rall" data-travel-rall>
                <button type="button" class="travel-pdpa-btn travel-pdpa-btn--sec travel-pdpa-rall__btn" data-travel-see-all aria-expanded="false" aria-controls="travel-pdpa-room-list">
                    {__("travel_core.pdpa_r_see_all_rooms", ["[rooms]" => $travel_pdpa_rooms_text])}{include file="addons/travel_core/components/design_a_icon.tpl" icon="chev"}
                </button>
                <p class="travel-pdpa-rall__hint" data-travel-hint data-template="{__("travel_core.pdpa_r_more_rooms")|escape:html}">{__("travel_core.pdpa_r_more_rooms", ["[n]" => $travel_pdpa_results.more.count, "[names]" => $travel_pdpa_results.more.names|escape:html, "[price]" => $travel_pdpa_results.more.from|escape:html])}</p>
            </div>
        {/if}

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
            {if $travel_pdpa_results.struck_deal}
                {__("travel_core.pdpa_r_footnote_struck_deal", ["[deal]" => $travel_pdpa_results.struck_deal|escape:html])}
            {elseif $travel_pdpa_results.struck}
                {__("travel_core.pdpa_r_footnote_struck")}
            {/if}
        </p>
    </section>
{/if}
