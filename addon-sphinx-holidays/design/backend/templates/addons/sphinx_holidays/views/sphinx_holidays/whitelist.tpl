{** Sphinx -> Destination whitelist: what we sell from Sphinx.

   Travel Core's destination picker (components/destination_picker.tpl) with
   Sphinx's three levels, country -> region -> city (Services\DestinationsPicker):
   per country Not sold / All destinations / Only selected; a ticked region
   is the whole region, cities Sphinx adds to it later included. A country's
   regions load when it opens (sphinx_holidays.whitelist_body); a continent
   filter and a pager keep ~200 countries in hand.

   Sphinx's own, under the intro: the catalog counts. Saving also adds the
   sold regions to Feature Mappings (the summary says so). Save never changes
   a product. **}

{capture name="mainbox"}

{if $total_destinations == 0}
    <div class="alert alert-warning">{__("sphinx_holidays.no_destinations_synced")}</div>
{else}
    {capture name="sphinx_dest_top"}
        <div class="travel-dest__catalog">
            <span>{__("sphinx_holidays.dest_catalog", [
                "[continents]" => $counts_by_type.continent|default:0,
                "[countries]" => $counts_by_type.country|default:0,
                "[regions]" => $counts_by_type.region|default:0,
                "[cities]" => $counts_by_type.city|default:0 + $counts_by_type.destination|default:0
            ])}</span>
            {if $sphinx_dest_last_synced}<span class="muted">· {__("sphinx_holidays.dest_last_synced", ["[at]" => $sphinx_dest_last_synced|escape:html])}</span>{/if}
        </div>
    {/capture}
    {include file="addons/travel_core/components/destination_picker.tpl" dest=$sphinx_destinations dest_top=$smarty.capture.sphinx_dest_top}
{/if}

{/capture}

{include file="common/mainbox.tpl"
    title=__("sphinx_holidays.destination_whitelist")
    content=$smarty.capture.mainbox
}
