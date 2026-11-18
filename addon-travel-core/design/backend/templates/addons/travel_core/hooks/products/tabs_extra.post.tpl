{*
    The product page's read-only "Circuit" tab (docs/circuit-page-plan.md
    step 5): what the circuit's supplier sync holds for this product,
    ViewModels\CircuitAdminView. Nothing here is editable: the next sync
    would overwrite it. Shown only for a product a supplier claims as a
    circuit (controllers/backend/products.post.php).
*}
{if $travel_circuit_admin}
{$tca = $travel_circuit_admin}
<div id="content_travel_circuit" class="cm-hide-save-button {if $selected_section !== "travel_circuit"}hidden{/if}">
    <div class="alert alert-info">
        <strong>{__("travel_core.circuit_admin_readonly")}</strong>
        {__("travel_core.circuit_admin_readonly_text", ["[provider]" => $tca.provider|escape:html])}
    </div>

    {* Every fact keeps its row: one the supplier did not send says so. *}
    {capture name="tca_none"}<span class="muted">{__("travel_core.circuit_admin_not_sent")}</span>{/capture}
    {$tca_none = $smarty.capture.tca_none}
    {include file="common/subheader.tpl" title=__("travel_core.circuit_admin_tab") target="#travel_circuit_facts"}
    <div id="travel_circuit_facts" class="in collapse row-fluid">
        <div class="span6">
        <table class="table table-middle table--relative">
            <tbody>
                <tr><td width="40%">{__("travel_core.circuit_admin_id")}</td><td>{$tca.provider|escape:html} · {$tca.circuit_id|escape:html}</td></tr>
                <tr><td>{__("travel_core.circuit_admin_status")}</td><td><span class="label label-success">{__("travel_core.circuit_admin_active")}</span> {__("travel_core.circuit_admin_departures_ahead", ["[n]" => $tca.departures_total])}</td></tr>
                <tr><td>{__("travel_core.circuit_admin_transport")}</td><td>{if $tca.transport}{__("travel_core.circuit_admin_transport_`$tca.transport`")}{else}{$tca_none nofilter}{/if}</td></tr>
                <tr><td>{__("travel_core.circuit_admin_duration")}</td><td>{if $tca.days || $tca.nights}{__("travel_core.circuit_admin_days_nights", ["[days]" => $tca.days, "[nights]" => $tca.nights])}{else}{$tca_none nofilter}{/if}</td></tr>
                <tr><td>{__("travel_core.circuit_admin_destinations")}</td><td>{if $tca.route}{$tca.route|escape:html}{else}{$tca_none nofilter}{/if}</td></tr>
                <tr><td>{__("travel_core.circuit_admin_departure_cities")}</td><td>{if $tca.departure_cities}{$tca.departure_cities|escape:html}{else}{$tca_none nofilter}{/if}</td></tr>
            </tbody>
        </table>
        </div>
        <div class="span6">
        <table class="table table-middle table--relative">
            <tbody>
                <tr><td width="40%">{__("travel_core.circuit_admin_tags")}</td><td>{if $tca.tags}{", "|implode:$tca.tags|escape:html}{else}{$tca_none nofilter}{/if}</td></tr>
                <tr><td>{__("travel_core.circuit_difficulty")}</td><td>{if $tca.difficulty}{__("travel_core.circuit_admin_difficulty_value", ["[level]" => $tca.difficulty])} · {__("travel_core.circuit_difficulty_`$tca.difficulty`")}{else}{$tca_none nofilter}{/if}</td></tr>
                <tr><td>{__("travel_core.circuit_admin_group")}</td><td>{if $tca.group_min || $tca.group_max}{__("travel_core.circuit_admin_travellers", ["[min]" => $tca.group_min|default:"—", "[max]" => $tca.group_max|default:"—"])}{else}{$tca_none nofilter}{/if}</td></tr>
                <tr><td>{__("travel_core.circuit_admin_age")}</td><td>{if $tca.age_min || $tca.age_max}{$tca.age_min|default:"—"} – {$tca.age_max|default:"—"}{elseif $tca.has_details}<span class="muted">{__("travel_core.circuit_admin_age_not_required")}</span>{else}{$tca_none nofilter}{/if}</td></tr>
                <tr><td>{__("travel_core.circuit_admin_accommodation")}</td><td>{if $tca.hotel || $tca.hotel_stars}{$tca.hotel|escape:html}{if $tca.hotel_stars} · {$tca.hotel_stars}★{/if}{else}{$tca_none nofilter}{/if}</td></tr>
                <tr><td>{__("travel_core.circuit_admin_media")}</td><td>{if $tca.photo_count || $tca.documents}{__("travel_core.circuit_admin_media_value", ["[photos]" => $tca.photo_count, "[documents]" => $tca.documents|count])}{else}{$tca_none nofilter}{/if}</td></tr>
            </tbody>
        </table>
        </div>
    </div>

    {include file="common/subheader.tpl" title=__("travel_core.circuit_admin_itinerary", ["[n]" => $tca.days_list|count]) target="#travel_circuit_days"}
    <div id="travel_circuit_days" class="in collapse">
        {if $tca.days_list}
        <div class="table-responsive-wrapper">
        <table class="table table-middle table--relative table-responsive">
            <thead><tr>
                <th width="6%">{__("travel_core.circuit_admin_day")}</th>
                <th>{__("travel_core.circuit_admin_day_title")}</th>
                <th>{__("travel_core.circuit_hotel")}</th>
                <th>{__("travel_core.circuit_admin_meals")}</th>
                <th width="8%">{__("travel_core.circuit_admin_photos")}</th>
                <th width="30%">{__("travel_core.circuit_admin_text")}</th>
            </tr></thead>
            <tbody>
                {foreach $tca.days_list as $tca_day}
                    <tr>
                        <td>{$tca_day.number}</td>
                        <td>{$tca_day.title|default:"—"|escape:html}{if $tca_day.highlighted} <span class="label label-warning">{__("travel_core.circuit_day_highlight")}</span>{/if}</td>
                        <td>{$tca_day.hotel|default:"—"|escape:html}{if $tca_day.stars} · {$tca_day.stars}★{/if}</td>
                        <td>{$tca_day.meals|default:"—"|escape:html}</td>
                        <td>{if $tca_day.photos}{$tca_day.photos}{else}<span class="muted">{__("travel_core.circuit_admin_none")}</span>{/if}</td>
                        <td>{if $tca_day.text}<details><summary><a>{__("travel_core.circuit_admin_show")}</a></summary><div style="white-space: pre-line;">{$tca_day.text|escape:html}</div></details>{else}—{/if}</td>
                    </tr>
                {/foreach}
            </tbody>
        </table>
        </div>
        {else}
            <p class="no-items">{__("travel_core.circuit_admin_no_program")}</p>
        {/if}
    </div>

    {include file="common/subheader.tpl" title=__("travel_core.circuit_menu_include") target="#travel_circuit_include"}
    <div id="travel_circuit_include" class="in collapse row-fluid">
        <div class="span6">
            <h5>{__("travel_core.circuit_included")}</h5>
            {if $tca.included}<ul>{foreach $tca.included as $tca_item}<li>{$tca_item|escape:html}</li>{/foreach}</ul>{else}<p>{$tca_none nofilter}</p>{/if}
        </div>
        <div class="span6">
            <h5>{__("travel_core.circuit_not_included")}</h5>
            {if $tca.not_included}<ul>{foreach $tca.not_included as $tca_item}<li>{$tca_item|escape:html}</li>{/foreach}</ul>{else}<p>{$tca_none nofilter}</p>{/if}
        </div>
    </div>

    {if $tca.excursions}
    {include file="common/subheader.tpl" title=__("travel_core.circuit_menu_excursions") target="#travel_circuit_excursions"}
    <div id="travel_circuit_excursions" class="in collapse">
        <ul>{foreach $tca.excursions as $tca_item}<li>{$tca_item.title|escape:html}{if $tca_item.price} · {$tca_item.price|escape:html}{/if}</li>{/foreach}</ul>
    </div>
    {/if}

    {if $tca.notes}
    {include file="common/subheader.tpl" title=__("travel_core.circuit_admin_notes") target="#travel_circuit_notes"}
    <div id="travel_circuit_notes" class="in collapse">
        <table class="table table-middle table--relative">
            <thead><tr><th>{__("travel_core.circuit_admin_note_title")}</th><th>{__("travel_core.circuit_admin_shown_in")}</th></tr></thead>
            <tbody>
                {foreach $tca.notes as $tca_note}
                    <tr><td>{$tca_note.title|default:"—"|escape:html}</td><td>{__("travel_core.circuit_admin_section_`$tca_note.shown_in`")}</td></tr>
                {/foreach}
            </tbody>
        </table>
    </div>
    {/if}

    {include file="common/subheader.tpl" title=__("travel_core.circuit_admin_departures", ["[n]" => $tca.departures_total]) target="#travel_circuit_departures"}
    <div id="travel_circuit_departures" class="in collapse">
        {if $tca.departures}
            <table class="table table-middle table--relative">
                <thead><tr>
                    <th>{__("travel_core.departure_date")}</th>
                    <th>{__("travel_core.departure_from")}</th>
                    <th>{__("travel_core.circuit_admin_supplier_price")}</th>
                    <th>{__("travel_core.circuit_admin_availability")}</th>
                </tr></thead>
                <tbody>
                    {foreach $tca.departures as $tca_dep}
                        <tr>
                            <td>{$tca_dep.date|escape:html}</td>
                            <td>{$tca_dep.city|default:"—"|escape:html}</td>
                            <td>{$tca_dep.price|escape:html}</td>
                            <td>{$tca_dep.availability|default:"—"|escape:html}{if $tca_dep.seats !== null} · {$tca_dep.seats}{/if}</td>
                        </tr>
                    {/foreach}
                </tbody>
            </table>
            {if $tca.departures_total > $tca.departures|count}<p class="muted">{__("travel_core.circuit_admin_more_departures", ["[n]" => $tca.departures_total - $tca.departures|count])}</p>{/if}
        {else}
            <p class="no-items">{__("travel_core.circuit_admin_no_departures")}</p>
        {/if}
    </div>

    {if $tca.documents}
    {include file="common/subheader.tpl" title=__("travel_core.circuit_documents") target="#travel_circuit_documents"}
    <div id="travel_circuit_documents" class="in collapse">
        <ul>{foreach $tca.documents as $tca_doc}<li><a href="{$tca_doc.url|escape:html}" target="_blank" rel="noopener">{$tca_doc.title|default:$tca_doc.url|escape:html}</a></li>{/foreach}</ul>
    </div>
    {/if}
<!--content_travel_circuit--></div>
{/if}
