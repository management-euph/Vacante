{* Eurosite → Hotels. Built from CS-Cart's own admin pieces only (the
   sidebar search panel, table, label, btn, alert, muted), like Sphinx's
   hotel list: no stylesheet of its own. *}

{assign var="sum" value=$eurosite_hotel_summary}

{* ── Sidebar: summary and filters ── *}
{capture name="sidebar"}
<div class="sidebar-row">
    <h6>{__("eurosite.hotels_summary", ["[default]" => "Summary"])}</h6>
    <ul class="unstyled">
        <li>{__("eurosite.hotels_tile_listed", ["[default]" => "In the whitelist"])}: <strong>{$sum.listed}</strong>
            <span class="muted">{__("eurosite.hotels_in_n_destinations", ["[n]" => $sum.destinations, "[default]" => "hotels in [n] destinations"])}</span></li>
        <li>{__("eurosite.hotels_tile_checked", ["[default]" => "Checked for availability"])}: <strong>{$sum.checked}</strong>
            <span class="muted">{if $sum.last_availability}{__("eurosite.hotels_last_check", ["[when]" => $sum.last_availability, "[default]" => "last check [when]"])}{else}{__("eurosite.hotels_never_checked", ["[default]" => "not checked yet"])}{/if}</span></li>
        <li>{__("eurosite.avail_im", ["[default]" => "Immediate"])}: <strong>{$sum.immediate}</strong>
            <span class="muted">{__("eurosite.hotels_can_become_products", ["[default]" => "can become products"])}</span></li>
        <li>{__("eurosite.images_with", ["[default]" => "With images"])}: <strong>{$sum.with_images}</strong>
            <span class="muted">{__("eurosite.hotels_n_not_fetched", ["[n]" => $sum.not_fetched, "[default]" => "[n] not fetched yet"])}</span></li>
        <li>{__("eurosite.products", ["[default]" => "Products"])}: <strong>{$sum.products}</strong>
            <span class="muted">{if $sum.gate_hidden}{__("eurosite.hotels_n_hidden_by_check", ["[n]" => $sum.gate_hidden, "[default]" => "[n] hidden: no Immediate offer"])}{elseif $sum.products}{__("eurosite.hotels_all_visible", ["[default]" => "all visible"])}{else}{__("eurosite.hotels_none_created", ["[default]" => "none created yet"])}{/if}</span></li>
    </ul>
</div>

<div class="sidebar-row">
    <h6>{__("search", ["[default]" => "Search"])}</h6>
    <form action="{""|fn_url}" method="get" name="es_hotels_filter">
        <input type="hidden" name="dispatch" value="eurosite.hotels" />
        {foreach from=["sort_by", "sort_order", "items_per_page"] item=keep}
            {if $search.$keep}<input type="hidden" name="{$keep}" value="{$search.$keep|escape:html}" />{/if}
        {/foreach}

        <div class="sidebar-field">
            <label for="es_q">{__("eurosite.col_hotel", ["[default]" => "Hotel"])}:</label>
            <input type="text" id="es_q" name="q" value="{$search.q|escape:html}" placeholder="{__("eurosite.hotels_search_placeholder", ["[default]" => "Hotel name or code"])|escape:html}" />
        </div>

        <div class="sidebar-field">
            <label for="es_city">{__("eurosite.destination", ["[default]" => "Destination"])}:</label>
            <select name="city" id="es_city">
                <option value="">{__("eurosite.all_destinations", ["[default]" => "All destinations"])}</option>
                {foreach from=$sum.destination_list item=dest}
                    <option value="{$dest.code|escape:html}" {if $search.city == $dest.code}selected{/if}>{$dest.label|escape:html}</option>
                {/foreach}
            </select>
        </div>

        {foreach from=["availability" => "eurosite.col_availability", "images" => "eurosite.col_images", "product" => "eurosite.col_product"] key=group item=groupLabel}
            <div class="sidebar-field">
                <label for="es_{$group}">{__($groupLabel)}:</label>
                <select name="{$group}" id="es_{$group}">
                    {foreach from=$eurosite_hotel_chips.$group item=chip}
                        <option value="{$chip.value|escape:html}" {if $chip.active}selected{/if}>{__($chip.label_key)}{if $chip.count !== null} ({$chip.count}){/if}</option>
                    {/foreach}
                </select>
            </div>
        {/foreach}

        <div class="sidebar-field">
            <input type="submit" class="btn" value="{__("search", ["[default]" => "Search"])}" />
        </div>
    </form>
</div>
{/capture}

{* ── Main content ── *}
{capture name="mainbox"}

<div id="es-hotels"
     data-txt-confirm-create="{__("eurosite.hotels_confirm_create", ["[default]" => "Create [n] products? Hotels that cannot become products are skipped, with the reason."])|escape:html}"
     data-txt-confirm-check="{__("eurosite.hotels_confirm_check", ["[default]" => "Check availability for the destinations of the selected hotels now? This can take a minute per destination."])|escape:html}"
     data-txt-confirm-check-all="{__("eurosite.hotels_confirm_check_all", ["[default]" => "No hotel is selected: check every listed destination now? This can take several minutes."])|escape:html}">

    <p class="muted">
        {__("eurosite.hotels_intro", ["[default]" => "Hotels from your whitelisted destinations, with what the live price search says about them. Only hotels with an Immediate offer can become products."])}
    </p>

    <div class="alert alert-info">
        {__("eurosite.hotels_whitelist_note", ["[default]" => "Only hotels from whitelisted destinations are listed, checked and made into products."])}
        {if $sum.hidden_hotels}
            {__("eurosite.hotels_hidden_note", ["[n]" => $sum.hidden_hotels, "[d]" => $sum.hidden_destinations, "[default]" => "[n] hotels from [d] destinations outside it are hidden and kept; whitelist a destination and they return on the next hotels sync."])}
        {/if}
        <a href="{"eurosite.whitelist"|fn_url}">{__("eurosite.edit_whitelist", ["[default]" => "Edit whitelist"])}</a>
        {if $sum.last_hotels_sync}
            · {__("eurosite.hotels_last_sync", ["[when]" => $sum.last_hotels_sync, "[default]" => "last hotels sync [when]"])}
        {/if}
    </div>
    {if !$eurosite_root_category_set}
        <div class="alert alert-warning">
            {__("eurosite.hotels_no_root_category", ["[default]" => "Choose \"CS-Cart category ID for Eurosite hotels\" in the add-on settings before creating products: products go under it, then country, then destination."])}
            <a href="{"addons.update&addon=eurosite"|fn_url}">{__("eurosite.addon_settings", ["[default]" => "Addon settings"])}</a>
        </div>
    {/if}

    <form action="{""|fn_url}" method="post" id="es-hotels-form" name="es_hotels_form">
        <input type="hidden" name="security_hash" value="{$security_hash}" />
        <input type="hidden" name="return_query" value="{$eurosite_hotels_query|escape:html}" />

        <div class="clearfix">
            <div class="pull-right">
                <button type="submit" class="btn" name="dispatch[eurosite.check_availability]" id="es-check-btn">
                    {__("eurosite.hotels_check_now", ["[default]" => "Check availability now"])}
                </button>
                <button type="submit" class="btn btn-primary" name="dispatch[eurosite.create_products]" id="es-create-btn" disabled>
                    {__("eurosite.hotels_create_products", ["[default]" => "Create products"])} (<span id="es-create-n">0</span>)
                </button>
            </div>
            <p>
                <strong id="es-sel-count">0</strong> {__("eurosite.hotels_selected", ["[default]" => "selected"])}
                · <strong id="es-sel-ok">0</strong> {__("eurosite.hotels_can_become_products", ["[default]" => "can become products"])}
                <span id="es-sel-skip-wrap" hidden>· <strong id="es-sel-skip">0</strong> {__("eurosite.hotels_will_be_skipped", ["[default]" => "will be skipped"])}</span>
                &nbsp;
                <a href="#" id="es-select-eligible">{__("eurosite.hotels_select_eligible", ["[default]" => "Select all that can become products"])}</a>
                · <a href="#" id="es-select-clear">{__("eurosite.hotels_clear", ["[default]" => "Clear"])}</a>
            </p>
        </div>

        {include file="common/pagination.tpl" save_current_url=true}

        {if $eurosite_hotels}
        <table class="table table-middle">
            <thead>
                <tr>
                    <th width="1%"><input type="checkbox" id="es-check-page" aria-label="{__("eurosite.hotels_select_page", ["[default]" => "Select this page"])|escape:html}" /></th>
                    <th width="70">
                        {* The thumbnail's column sorts by images: ascending puts the
                           hotels without any first, so they are easy to work through. *}
                        <a href="{"`$eurosite_hotels_url`&sort_by=images&sort_order=`$eurosite_images_sort_order`"|fn_url}" title="{__("eurosite.hotels_sort_by_images_hint", ["[default]" => "Ascending puts hotels without images first."])|escape:html}">
                            {__("eurosite.col_images", ["[default]" => "Images"])}{if $search.sort_by == "images"} {if $search.sort_order == "asc"}&#9650;{else}&#9660;{/if}{/if}
                        </a>
                    </th>
                    {foreach from=["name" => "eurosite.col_hotel", "destination" => "eurosite.destination", "stars" => "eurosite.col_stars", "availability" => "eurosite.col_availability", "price" => "eurosite.col_price"] key=sortKey item=colLabel}
                        <th{if $sortKey == "price"} class="right"{/if}>
                            <a href="{"`$eurosite_hotels_url`&sort_by=`$sortKey`&sort_order=`$search.sort_order_rev`"|fn_url}">
                                {__($colLabel)}{if $search.sort_by == $sortKey} {if $search.sort_order == "asc"}&#9650;{else}&#9660;{/if}{/if}
                            </a>
                        </th>
                    {/foreach}
                    <th>{__("eurosite.col_product", ["[default]" => "Product"])}</th>
                </tr>
            </thead>
            <tbody>
                {foreach from=$eurosite_hotels item=h}
                    <tr>
                        <td>
                            <input type="checkbox" name="hotel_keys[]" value="{$h.key|escape:html}" class="es-row-check"
                                   data-eligible="{if $h.eligible}1{else}0{/if}"
                                   aria-label="{$h.name|escape:html}" />
                        </td>
                        <td>
                            {if $h.thumb}
                                <img src="{$h.thumb|escape:html}" alt="" width="60" loading="lazy" referrerpolicy="no-referrer" class="img-polaroid" />
                                {if $h.image_state == "pictures"}<div class="muted">{__("eurosite.images_n_pictures", ["[n]" => $h.image_count, "[default]" => "[n] pictures"])}</div>{/if}
                            {elseif $h.image_state == "not_fetched"}
                                <span class="muted" title="{__("eurosite.hotels_details_hint", ["[default]" => "The hotel details (pictures, description) are fetched by the product_info job, or when a product is created."])|escape:html}">{__("eurosite.images_not_fetched", ["[default]" => "Not fetched yet"])}</span>
                            {else}
                                <span class="text-warning">{__("eurosite.images_none", ["[default]" => "No pictures"])}</span>
                            {/if}
                        </td>
                        <td>
                            <strong>{$h.name|escape:html}</strong>
                            <div class="muted">{$h.tourop|escape:html} · {$h.code|escape:html}</div>
                        </td>
                        <td>
                            {if $h.city_name}{$h.city_name|escape:html}{else}{$h.city_code|escape:html}{/if}
                            <div class="muted">{if $h.country_name}{$h.country_name|escape:html}{else}{$h.country_code|escape:html}{/if} · {$h.city_code|escape:html}</div>
                        </td>
                        <td class="nowrap">{if $h.stars}{$h.stars}&#9733;{else}&mdash;{/if}</td>
                        <td>
                            {if $h.availability_key == "im"}<span class="label label-success">{__("eurosite.avail_im")}</span>
                            {elseif $h.availability_key == "or"}<span class="label label-warning">{__("eurosite.avail_or")}</span>
                            {elseif $h.availability_key == "st"}<span class="label label-important">{__("eurosite.avail_st")}</span>
                            {elseif $h.availability_key == "none"}<span class="label">{__("eurosite.avail_none")}</span>
                            {else}<span class="muted">{__("eurosite.avail_unchecked")}</span>{/if}
                            {if $h.check_in}
                                <div class="muted">{if $h.is_season}{__("eurosite.hotels_season", ["[default]" => "season"])} {/if}{$h.check_in}</div>
                            {/if}
                        </td>
                        <td class="right nowrap">
                            {if $h.price}{$h.price}<div class="muted">{__("eurosite.hotels_gross", ["[default]" => "gross"])} {$h.gross}</div>{else}&mdash;{/if}
                        </td>
                        <td>
                            {if $h.product_id}
                                <a href="{"products.update&product_id=`$h.product_id`"|fn_url}">{$h.product_code|escape:html}</a>
                                <div class="muted">
                                    {if $h.product_status == "A"}{__("eurosite.product_active", ["[default]" => "Active"])}
                                    {elseif $h.product_status == "H" && $h.gate_hidden}{__("eurosite.product_hidden_by_check", ["[default]" => "Hidden: no Immediate offer"])}
                                    {elseif $h.product_status == "H"}{__("eurosite.product_hidden", ["[default]" => "Hidden"])}
                                    {elseif $h.product_status == "D"}{__("eurosite.product_disabled", ["[default]" => "Disabled"])}
                                    {else}{__("eurosite.product_missing", ["[default]" => "Product deleted"])}{/if}
                                </div>
                            {elseif $h.eligible}
                                <span class="text-success">{__("eurosite.product_ready", ["[default]" => "Ready"])}</span>
                                {if $h.details_pending}<div class="muted">{__("eurosite.product_details_first", ["[default]" => "details fetched first"])}</div>{/if}
                            {else}
                                <span class="muted">{__("eurosite.product_not_a_product", ["[default]" => "Not a product"])}</span>
                                {if $h.skip_reason}<div class="muted">{__("eurosite.skip_`$h.skip_reason`")}</div>{/if}
                            {/if}
                        </td>
                    </tr>
                {/foreach}
            </tbody>
        </table>
        {else}
            <p class="no-items">
                {if $sum.listed}
                    {__("eurosite.hotels_no_match", ["[default]" => "No hotel matches these filters."])}
                {else}
                    {__("eurosite.hotels_empty", ["[default]" => "No hotels listed yet. Whitelist destinations, then run the hotels sync from the dashboard."])}
                {/if}
            </p>
        {/if}

        {include file="common/pagination.tpl" save_current_url=true}
    </form>
</div>

{* Inside the capture: admin AJAX navigation only runs scripts placed here. *}
{script src="js/addons/eurosite/hotels.js"}

{/capture}

{include file="common/mainbox.tpl"
    title=__("eurosite.hotels_title", ["[default]" => "Eurosite — Hotels"])
    content=$smarty.capture.mainbox
    sidebar=$smarty.capture.sidebar
}
