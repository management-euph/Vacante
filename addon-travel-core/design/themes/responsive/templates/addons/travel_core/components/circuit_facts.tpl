{*
    Under a circuit's title (board 16.3): how long it lasts, how it travels
    and from where (the city the bar starts on), and the group a departure
    takes. Its route sits in the location line above (travelcore_design_a.tpl).
    Every fact the supplier does not give is left out.

    Params: $travel_circuit_facts (ViewModels\CircuitDetailsViewFactory::facts())
*}
{if $travel_circuit_facts.days > 0 || $travel_circuit_facts.nights > 0 || $travel_circuit_facts.transport || $travel_circuit_facts.from_city || $travel_circuit_facts.group_min > 0 || $travel_circuit_facts.group_max > 0}
<ul class="travel-pdpa-facts" aria-label="{__("travel_core.circuit_menu_label")|escape:html}">
    {if $travel_circuit_facts.days > 0 || $travel_circuit_facts.nights > 0}
        <li class="travel-pdpa-fact">
            {include file="addons/travel_core/components/design_a_icon.tpl" icon="cal" size=14}
            <span>{if $travel_circuit_facts.days > 0}{__("travel_core.circuit_days_`$travel_circuit_facts.days_form`", ["[n]" => $travel_circuit_facts.days])}{/if}{if $travel_circuit_facts.days > 0 && $travel_circuit_facts.nights > 0} / {/if}{if $travel_circuit_facts.nights > 0}{__("travel_core.circuit_nights_`$travel_circuit_facts.nights_form`", ["[n]" => $travel_circuit_facts.nights])}{/if}</span>
        </li>
    {/if}
    {if $travel_circuit_facts.transport == "flight"}
        <li class="travel-pdpa-fact">
            {include file="addons/travel_core/components/design_a_icon.tpl" icon="plane" size=14}
            <span>{if $travel_circuit_facts.from_city}{__("travel_core.circuit_flight_from", ["[city]" => $travel_circuit_facts.from_city|escape:html])}{else}{__("travel_core.circuit_flight")}{/if}</span>
        </li>
    {elseif $travel_circuit_facts.transport == "bus"}
        <li class="travel-pdpa-fact">
            {include file="addons/travel_core/components/design_a_icon.tpl" icon="bus" size=14}
            <span>{if $travel_circuit_facts.from_city}{__("travel_core.circuit_bus_from", ["[city]" => $travel_circuit_facts.from_city|escape:html])}{else}{__("travel_core.circuit_bus")}{/if}</span>
        </li>
    {elseif $travel_circuit_facts.from_city}
        <li class="travel-pdpa-fact">
            {include file="addons/travel_core/components/design_a_icon.tpl" icon="pin" size=14}
            <span>{__("travel_core.circuit_departs_from", ["[city]" => $travel_circuit_facts.from_city|escape:html])}</span>
        </li>
    {/if}
    {if $travel_circuit_facts.group_min > 0 || $travel_circuit_facts.group_max > 0}
        <li class="travel-pdpa-fact">
            {include file="addons/travel_core/components/design_a_icon.tpl" icon="users" size=14}
            <span>{if $travel_circuit_facts.group_min > 0 && $travel_circuit_facts.group_max > 0}{__("travel_core.circuit_group_range_`$travel_circuit_facts.group_form`", ["[min]" => $travel_circuit_facts.group_min, "[max]" => $travel_circuit_facts.group_max])}{elseif $travel_circuit_facts.group_min > 0}{__("travel_core.circuit_group_from_`$travel_circuit_facts.group_form`", ["[n]" => $travel_circuit_facts.group_min])}{else}{__("travel_core.circuit_group_up_to_`$travel_circuit_facts.group_form`", ["[n]" => $travel_circuit_facts.group_max])}{/if}</span>
        </li>
    {/if}
</ul>
{/if}
