{** Novoton -> Destinations: what we sell from Novoton.

   Travel Core's destination picker (components/destination_picker.tpl) with
   Novoton's two levels, country -> resort. Each country is Not sold / All
   resorts / Only selected; Services\DestinationsPicker builds the data. Save
   never changes a product; "Disable products outside the whitelist" is its
   own confirmed step. The top row holds only the page links: Save lives in
   the summary and in the bar that appears once something is unsaved. **}

{capture name="mainbox"}
    {include file="addons/travel_core/components/destination_picker.tpl" dest=$novoton_destinations}
{/capture}

{include file="common/mainbox.tpl"
    title=__("novoton_holidays.dest_page_title")
    content=$smarty.capture.mainbox
}
