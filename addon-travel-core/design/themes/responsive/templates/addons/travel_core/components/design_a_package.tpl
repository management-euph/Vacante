{*
    The package and its discount of a Design A option, or of all of a
    room's options (one unit: a discount belongs to its package). The
    package as the supplier names it; the discount's line says until when
    it can be booked and, for one option, how much it saves — its percent
    is on the badge by the price. Shared by several options: "inclus în
    preț", as each saves a different amount.
    Params: package, deal (RoomResultsBuilder Deal or null), shared
*}
{if $package}
    <span class="travel-pdpa-pkg">{include file="addons/travel_core/components/design_a_icon.tpl" icon="tag" size=14}<span>{__("travel_core.pdpa_r_package", ["[name]" => $package|escape:html])}</span></span>
{/if}
{if $deal}
    <span class="travel-pdpa-eb">{include file="addons/travel_core/components/design_a_icon.tpl" icon="pct" size=14}<span>
        {if $shared}
            {if $deal.until}{__("travel_core.pdpa_r_deal_until_included", ["[deal]" => $deal.name|escape:html, "[date]" => $deal.until|escape:html])}{else}{__("travel_core.pdpa_r_deal_included", ["[deal]" => $deal.name|escape:html])}{/if}
        {elseif $deal.until && $deal.saving}
            {__("travel_core.pdpa_r_deal_until_saving", ["[deal]" => $deal.name|escape:html, "[date]" => $deal.until|escape:html, "[amount]" => $deal.saving|escape:html])}
        {elseif $deal.until}
            {__("travel_core.pdpa_r_deal_until", ["[deal]" => $deal.name|escape:html, "[date]" => $deal.until|escape:html])}
        {elseif $deal.saving}
            {__("travel_core.pdpa_r_deal_saving", ["[deal]" => $deal.name|escape:html, "[amount]" => $deal.saving|escape:html])}
        {else}
            {$deal.name|escape:html}
        {/if}
    </span></span>
{/if}
