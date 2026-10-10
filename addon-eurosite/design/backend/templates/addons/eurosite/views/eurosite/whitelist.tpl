{** Eurosite -> Destination whitelist: what we sell from Eurosite.

   Travel Core's destination picker (components/destination_picker.tpl) with
   Eurosite's two levels, country -> city, and four modes: Not sold / All
   cities / Own cities / Only selected (Services\DestinationsPicker). A
   country's cities load when it opens (eurosite.whitelist_body).

   Eurosite's own, under the intro: the country catalog line (Sync countries,
   last synced) and the catalog warnings. The Sync countries form sits here,
   outside the picker's form. Save never changes a product. **}

{capture name="mainbox"}

{capture name="eurosite_dest_top"}
    {if !$eurosite_countries_synced}
        <div class="alert alert-warning">
            {__("eurosite.whitelist_needs_countries", ["[default]" => "The country catalog has not been synced yet — run the 'countries' sync from the dashboard first. City lists fall back to live API calls."])}
        </div>
    {elseif $eurosite_country_names_missing}
        {* The catalog holds codes but no names: the rows render as bare "TT" /
           "VC" and the search finds nothing by name. The controller already
           tried to refill it from the API on this request. *}
        <div class="alert alert-warning">
            {__("eurosite.whitelist_names_missing", ["[default]" => "The country catalog has codes but no names. Run the 'countries' sync to refill it."])}
            {if $eurosite_country_heal_error}
                <br><span class="muted">{$eurosite_country_heal_error|escape:html}</span>
            {/if}
        </div>
    {/if}
    <div class="travel-dest__catalog">
        <span>{__("eurosite.dest_catalog", ["[countries]" => $eurosite_wl_country_count, "[cities]" => $eurosite_wl_city_count, "[own]" => $eurosite_wl_own_city_count])}</span>
        {if $eurosite_countries_last_synced}
            <span class="muted">· {__("eurosite.last_synced", ["[default]" => "Last synced"])}: {$eurosite_countries_last_synced|escape:html}</span>
        {/if}
        <form action="{""|fn_url}" method="post" class="travel-dest__catalog-form">
            <input type="hidden" name="dispatch" value="eurosite.run_sync">
            <input type="hidden" name="sync_type" value="countries">
            <input type="hidden" name="return_to" value="whitelist">
            <input type="hidden" name="security_hash" value="{$security_hash}">
            <button type="submit" class="btn btn-small"><i class="icon-refresh"></i> {__("eurosite.sync_countries", ["[default]" => "Sync countries"])}</button>
        </form>
    </div>
{/capture}

{include file="addons/travel_core/components/destination_picker.tpl" dest=$eurosite_destinations dest_top=$smarty.capture.eurosite_dest_top}

{/capture}

{include file="common/mainbox.tpl"
    title=__("eurosite.whitelist_title", ["[default]" => "Eurosite — Destination whitelist"])
    content=$smarty.capture.mainbox
}
