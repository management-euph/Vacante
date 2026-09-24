{capture name="mainbox"}

{assign var="sum" value=$eurosite_hotel_summary}

<div class="es-hotels" id="es-hotels"
     data-txt-confirm-create="{__("eurosite.hotels_confirm_create", ["[default]" => "Create [n] products? Hotels that cannot become products are skipped, with the reason."])|escape:html}"
     data-txt-confirm-check="{__("eurosite.hotels_confirm_check", ["[default]" => "Check availability for the destinations of the selected hotels now? This can take a minute per destination."])|escape:html}"
     data-txt-confirm-check-all="{__("eurosite.hotels_confirm_check_all", ["[default]" => "No hotel is selected: check every listed destination now? This can take several minutes."])|escape:html}">

    <p class="es-hotels__intro">
        {__("eurosite.hotels_intro", ["[default]" => "Hotels from your whitelisted destinations, with what the live price search says about them. Only hotels with an Immediate offer can become products."])}
    </p>

    {* ── At a glance ── *}
    <div class="es-tiles">
        <div class="es-tile">
            <div class="es-tile__label">{__("eurosite.hotels_tile_listed", ["[default]" => "In the whitelist"])}</div>
            <div class="es-tile__value">{$sum.listed}</div>
            <div class="es-tile__sub">{__("eurosite.hotels_in_n_destinations", ["[n]" => $sum.destinations, "[default]" => "hotels in [n] destinations"])}</div>
        </div>
        <div class="es-tile">
            <div class="es-tile__label">{__("eurosite.hotels_tile_checked", ["[default]" => "Checked for availability"])}</div>
            <div class="es-tile__value">{$sum.checked}</div>
            <div class="es-tile__sub">
                {if $sum.last_availability}{__("eurosite.hotels_last_check", ["[when]" => $sum.last_availability, "[default]" => "last check [when]"])}{else}{__("eurosite.hotels_never_checked", ["[default]" => "not checked yet"])}{/if}
            </div>
        </div>
        <div class="es-tile es-tile--good">
            <div class="es-tile__label">{__("eurosite.avail_im", ["[default]" => "Immediate"])}</div>
            <div class="es-tile__value">{$sum.immediate}</div>
            <div class="es-tile__sub">{__("eurosite.hotels_can_become_products", ["[default]" => "can become products"])}</div>
        </div>
        <div class="es-tile">
            <div class="es-tile__label">{__("eurosite.images_with", ["[default]" => "With images"])}</div>
            <div class="es-tile__value">{$sum.with_images}</div>
            <div class="es-tile__sub">{__("eurosite.hotels_n_not_fetched", ["[n]" => $sum.not_fetched, "[default]" => "[n] not fetched yet"])}</div>
        </div>
        <div class="es-tile">
            <div class="es-tile__label">{__("eurosite.products", ["[default]" => "Products"])}</div>
            <div class="es-tile__value">{$sum.products}</div>
            <div class="es-tile__sub">
                {if $sum.gate_hidden}{__("eurosite.hotels_n_hidden_by_check", ["[n]" => $sum.gate_hidden, "[default]" => "[n] hidden: no Immediate offer"])}{elseif $sum.products}{__("eurosite.hotels_all_visible", ["[default]" => "all visible"])}{else}{__("eurosite.hotels_none_created", ["[default]" => "none created yet"])}{/if}
            </div>
        </div>
    </div>

    {* ── Why these hotels and not others ── *}
    <div class="es-note">
        {__("eurosite.hotels_whitelist_note", ["[default]" => "Only hotels from whitelisted destinations are listed, checked and made into products."])}
        {if $sum.hidden_hotels}
            {__("eurosite.hotels_hidden_note", ["[n]" => $sum.hidden_hotels, "[d]" => $sum.hidden_destinations, "[default]" => "[n] hotels from [d] destinations outside it are hidden and kept; whitelist a destination and they return on the next hotels sync."])}
        {/if}
        <a href="{"eurosite.whitelist"|fn_url}">{__("eurosite.edit_whitelist", ["[default]" => "Edit whitelist"])}</a>
        {if $sum.last_hotels_sync}
            <span class="muted">· {__("eurosite.hotels_last_sync", ["[when]" => $sum.last_hotels_sync, "[default]" => "last hotels sync [when]"])}</span>
        {/if}
    </div>
    {if !$eurosite_root_category_set}
        <div class="alert alert-warning">
            {__("eurosite.hotels_no_root_category", ["[default]" => "Choose \"CS-Cart category ID for Eurosite hotels\" in the add-on settings before creating products: products go under it, then country, then destination."])}
            <a href="{"addons.update&addon=eurosite"|fn_url}">{__("eurosite.addon_settings", ["[default]" => "Addon settings"])}</a>
        </div>
    {/if}

    {* ── Filters ── *}
    <form action="{""|fn_url}" method="get" class="es-filters" name="es_hotels_filter">
        <input type="hidden" name="dispatch" value="eurosite.hotels" />
        {foreach from=["availability", "images", "product", "sort_by", "sort_order", "items_per_page"] item=keep}
            {if $search.$keep}<input type="hidden" name="{$keep}" value="{$search.$keep|escape:html}" />{/if}
        {/foreach}
        <label class="es-filters__field">
            <span>{__("eurosite.destination", ["[default]" => "Destination"])}</span>
            <select name="city">
                <option value="">{__("eurosite.all_destinations", ["[default]" => "All destinations"])}</option>
                {foreach from=$sum.destination_list item=dest}
                    <option value="{$dest.code|escape:html}" {if $search.city == $dest.code}selected{/if}>{$dest.label|escape:html}</option>
                {/foreach}
            </select>
        </label>
        <label class="es-filters__field">
            <span>{__("search", ["[default]" => "Search"])}</span>
            <input type="text" name="q" value="{$search.q|escape:html}" placeholder="{__("eurosite.hotels_search_placeholder", ["[default]" => "Hotel name or code"])|escape:html}" />
        </label>
        <button type="submit" class="btn">{__("search", ["[default]" => "Search"])}</button>
    </form>

    <div class="es-chips">
        {foreach from=["availability" => "eurosite.col_availability", "images" => "eurosite.col_images", "product" => "eurosite.col_product"] key=group item=groupLabel}
            <div class="es-chips__group">
                <span class="es-chips__label">{__($groupLabel)}</span>
                {foreach from=$eurosite_hotel_chips.$group item=chip}
                    <a class="es-chip{if $chip.active} is-active{/if}" href="{$chip.url|fn_url}" {if $chip.active}aria-current="true"{/if}>
                        {__($chip.label_key)}{if $chip.count !== null} <span class="es-chip__n">{$chip.count}</span>{/if}
                    </a>
                {/foreach}
            </div>
        {/foreach}
    </div>

    {* ── The list ── *}
    <form action="{""|fn_url}" method="post" id="es-hotels-form" name="es_hotels_form">
        <input type="hidden" name="security_hash" value="{$security_hash}" />
        <input type="hidden" name="return_query" value="{$eurosite_hotels_query|escape:html}" />

        <div class="es-selection" id="es-selection">
            <span class="es-selection__text">
                <strong id="es-sel-count">0</strong> {__("eurosite.hotels_selected", ["[default]" => "selected"])}
                · <strong id="es-sel-ok">0</strong> {__("eurosite.hotels_can_become_products", ["[default]" => "can become products"])}
                <span id="es-sel-skip-wrap" hidden>· <strong id="es-sel-skip">0</strong> {__("eurosite.hotels_will_be_skipped", ["[default]" => "will be skipped"])}</span>
            </span>
            <button type="button" class="btn btn-link" id="es-select-eligible">{__("eurosite.hotels_select_eligible", ["[default]" => "Select all that can become products"])}</button>
            <button type="button" class="btn btn-link" id="es-select-clear">{__("eurosite.hotels_clear", ["[default]" => "Clear"])}</button>
            <span class="es-selection__actions">
                <button type="submit" class="btn" name="dispatch[eurosite.check_availability]" id="es-check-btn">
                    {__("eurosite.hotels_check_now", ["[default]" => "Check availability now"])}
                </button>
                <button type="submit" class="btn btn-primary" name="dispatch[eurosite.create_products]" id="es-create-btn" disabled>
                    {__("eurosite.hotels_create_products", ["[default]" => "Create products"])} (<span id="es-create-n">0</span>)
                </button>
            </span>
        </div>

        {include file="common/pagination.tpl" save_current_url=true}

        {if $eurosite_hotels}
        <table class="table table-middle es-hotels__table">
            <thead>
                <tr>
                    <th class="es-col-check"><input type="checkbox" id="es-check-page" aria-label="{__("eurosite.hotels_select_page", ["[default]" => "Select this page"])|escape:html}" /></th>
                    {foreach from=["name" => "eurosite.col_hotel", "destination" => "eurosite.destination", "stars" => "eurosite.col_stars", "availability" => "eurosite.col_availability", "images" => "eurosite.col_images", "price" => "eurosite.col_price"] key=sortKey item=colLabel}
                        <th class="es-col-{$sortKey}">
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
                    <tr class="es-row{if !$h.eligible} is-ineligible{/if}">
                        <td class="es-col-check">
                            <input type="checkbox" name="hotel_keys[]" value="{$h.key|escape:html}" class="es-row-check"
                                   data-eligible="{if $h.eligible}1{else}0{/if}"
                                   aria-label="{$h.name|escape:html}" />
                        </td>
                        <td class="es-col-name">
                            <div class="es-hotel">
                                {if $h.thumb}
                                    <span class="es-thumb">
                                        <img src="{$h.thumb|escape:html}" alt="" loading="lazy" referrerpolicy="no-referrer" />
                                        {if $h.image_state == "pictures"}<span class="es-thumb__n">{$h.image_count}</span>{/if}
                                    </span>
                                {else}
                                    <span class="es-thumb es-thumb--empty" title="{if $h.image_state == "not_fetched"}{__("eurosite.images_not_fetched", ["[default]" => "Not fetched yet"])|escape:html}{else}{__("eurosite.images_none", ["[default]" => "No pictures"])|escape:html}{/if}">
                                        {if $h.image_state == "not_fetched"}?{else}{__("eurosite.no_image_short", ["[default]" => "no image"])}{/if}
                                    </span>
                                {/if}
                                <span>
                                    <span class="es-hotel__name">{$h.name|escape:html}</span>
                                    <span class="es-hotel__code">{$h.tourop|escape:html} · {$h.code|escape:html}</span>
                                </span>
                            </div>
                        </td>
                        <td>
                            {if $h.city_name}{$h.city_name|escape:html}{else}{$h.city_code|escape:html}{/if}
                            <div class="es-sub">{if $h.country_name}{$h.country_name|escape:html}{else}{$h.country_code|escape:html}{/if} · {$h.city_code|escape:html}</div>
                        </td>
                        <td class="es-col-stars">{if $h.stars}{$h.stars}&#9733;{else}&mdash;{/if}</td>
                        <td>
                            <span class="es-pill es-pill--{$h.availability_key}">{__("eurosite.avail_`$h.availability_key`")}</span>
                            {if $h.check_in}
                                <div class="es-sub">{if $h.is_season}{__("eurosite.hotels_season", ["[default]" => "season"])} {/if}{$h.check_in}</div>
                            {/if}
                        </td>
                        <td class="es-col-images">
                            {if $h.image_state == "pictures"}{__("eurosite.images_n_pictures", ["[n]" => $h.image_count, "[default]" => "[n] pictures"])}
                            {elseif $h.image_state == "cover"}{__("eurosite.images_cover", ["[default]" => "Cover image"])}
                            {elseif $h.image_state == "none"}<span class="text-warning">{__("eurosite.images_none", ["[default]" => "No pictures"])}</span>
                            {else}<span class="muted">{__("eurosite.images_not_fetched", ["[default]" => "Not fetched yet"])}</span>{/if}
                        </td>
                        <td class="es-col-price">
                            {if $h.price}{$h.price}<div class="es-sub">{__("eurosite.hotels_gross", ["[default]" => "gross"])} {$h.gross}</div>{else}&mdash;{/if}
                        </td>
                        <td class="es-col-product">
                            {if $h.product_id}
                                <a href="{"products.update&product_id=`$h.product_id`"|fn_url}">{$h.product_code|escape:html}</a>
                                <div class="es-sub">
                                    {if $h.product_status == "A"}{__("eurosite.product_active", ["[default]" => "Active"])}
                                    {elseif $h.product_status == "H" && $h.gate_hidden}{__("eurosite.product_hidden_by_check", ["[default]" => "Hidden: no Immediate offer"])}
                                    {elseif $h.product_status == "H"}{__("eurosite.product_hidden", ["[default]" => "Hidden"])}
                                    {elseif $h.product_status == "D"}{__("eurosite.product_disabled", ["[default]" => "Disabled"])}
                                    {else}{__("eurosite.product_missing", ["[default]" => "Product deleted"])}{/if}
                                </div>
                            {elseif $h.eligible}
                                <span class="es-ready">{__("eurosite.product_ready", ["[default]" => "Ready"])}</span>
                                {if $h.details_pending}<div class="es-sub">{__("eurosite.product_details_first", ["[default]" => "details fetched first"])}</div>{/if}
                            {else}
                                <span class="muted">{__("eurosite.product_not_a_product", ["[default]" => "Not a product"])}</span>
                                {if $h.skip_reason}<div class="es-sub">{__("eurosite.skip_`$h.skip_reason`")}</div>{/if}
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

{capture name="buttons"}{/capture}

{include file="common/mainbox.tpl" title=__("eurosite.hotels_title", ["[default]" => "Eurosite — Hotels"]) content=$smarty.capture.mainbox buttons=$smarty.capture.buttons}
