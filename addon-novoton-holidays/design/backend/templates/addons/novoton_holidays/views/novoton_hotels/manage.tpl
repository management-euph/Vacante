{* Novoton → Hotels: the sync monitor. One row per hotel of novoton_hotels:
   its sync state (read from the sync timestamps) and its CS-Cart product.
   Nothing of the hotel is edited here — the syncs own that data; an admin
   can queue hotels for a re-sync and enable / disable their products.
   Laid out like Eurosite's and Sphinx's Hotels pages: search in the
   sidebar, plain admin table, top and bottom pagination. *}

{$counts = $novoton_hotel_counts}
{$status_labels = ["active" => "label-success", "pending" => "label-warning", "error" => "label-important", "unmapped" => ""]}

{capture name="sidebar"}
<div class="sidebar-row">
    <h6>{__("novoton_holidays.hotels_summary")}</h6>
    <ul class="unstyled">
        <li>{__("novoton_holidays.hotels_total")}: <strong>{$novoton_hotel_total}</strong></li>
        {foreach from=$novoton_hotel_statuses item=st}
            <li><a href="{"novoton_hotels.manage?sync_status=`$st`"|fn_url}">{__("novoton_holidays.hotels_status_`$st`")}</a>: <strong>{$counts.$st}</strong></li>
        {/foreach}
    </ul>
    <p class="muted">
        {__("novoton_holidays.hotels_last_list")}: {if $novoton_last_hotel_list}{$novoton_last_hotel_list}{else}&mdash;{/if}<br />
        {__("novoton_holidays.hotels_last_info")}: {if $novoton_last_hotelinfo}{$novoton_last_hotelinfo}{else}&mdash;{/if}
    </p>
</div>

<div class="sidebar-row">
    <h6>{__("search")}</h6>
    <form action="{""|fn_url}" method="get" name="nvt_hotels_filter">
        <input type="hidden" name="dispatch" value="novoton_hotels.manage" />
        {foreach from=["sort_by", "sort_order", "items_per_page"] item=keep}
            {if $search.$keep}<input type="hidden" name="{$keep}" value="{$search.$keep|escape:html}" />{/if}
        {/foreach}

        <div class="sidebar-field">
            <label for="nvt_q">{__("novoton_holidays.hotels_col_hotel")}:</label>
            <input type="text" id="nvt_q" name="q" value="{$search.q|escape:html}" placeholder="{__("novoton_holidays.hotels_search_placeholder")|escape:html}" />
        </div>

        <div class="sidebar-field">
            <label for="nvt_country">{__("country")}:</label>
            <select name="country" id="nvt_country">
                <option value="">{__("all")}</option>
                {foreach from=$novoton_hotel_countries item=country}
                    <option value="{$country|escape:html}" {if $search.country == $country}selected{/if}>{$country|escape:html}</option>
                {/foreach}
            </select>
        </div>

        <div class="sidebar-field">
            <label for="nvt_city">{__("novoton_holidays.hotels_city_or_region")}:</label>
            <input type="text" id="nvt_city" name="city" value="{$search.city|escape:html}" />
        </div>

        <div class="sidebar-field">
            <label for="nvt_stars">{__("novoton_holidays.hotels_col_stars")}:</label>
            <select name="stars" id="nvt_stars">
                <option value="">{__("all")}</option>
                {for $n = 5 to 1 step -1}
                    <option value="{$n}" {if $search.stars == $n}selected{/if}>{$n}*</option>
                {/for}
            </select>
        </div>

        <div class="sidebar-field">
            <label for="nvt_status">{__("novoton_holidays.hotels_col_status")}:</label>
            <select name="sync_status" id="nvt_status">
                <option value="">{__("all")}</option>
                {foreach from=$novoton_hotel_statuses item=st}
                    <option value="{$st}" {if $search.sync_status == $st}selected{/if}>{__("novoton_holidays.hotels_status_`$st`")} ({$counts.$st})</option>
                {/foreach}
            </select>
        </div>

        <div class="sidebar-field">
            <label for="nvt_age">{__("novoton_holidays.hotels_not_synced_in")}:</label>
            <select name="not_synced_days" id="nvt_age">
                <option value="">{__("novoton_holidays.hotels_any_time")}</option>
                {foreach from=$novoton_hotel_ages item=days}
                    <option value="{$days}" {if $search.not_synced_days == $days}selected{/if}>{__("novoton_holidays.hotels_n_days", ["[n]" => $days])}</option>
                {/foreach}
            </select>
        </div>

        <div class="sidebar-field">
            <input type="submit" class="btn" value="{__("search")}" />
            <a href="{"novoton_hotels.manage"|fn_url}" class="btn btn-link">{__("reset")}</a>
        </div>
    </form>
</div>
{/capture}

{capture name="mainbox"}

<p class="muted">{__("novoton_holidays.hotels_intro")}</p>

<form action="{""|fn_url}" method="post" name="nvt_hotels_form" id="nvt_hotels_form">
    <input type="hidden" name="security_hash" value="{$security_hash}" />
    {* The list to come back to: the filters, the sort and the page. *}
    {foreach from=["q", "country", "city", "stars", "sync_status", "not_synced_days", "items_per_page", "sort_by", "sort_order", "page"] item=keep}
        {if $search.$keep}<input type="hidden" name="{$keep}" value="{$search.$keep|escape:html}" />{/if}
    {/foreach}

    {if $novoton_hotels}
    <div class="well well-small">
        <strong>{__("novoton_holidays.hotels_with_selected")}:</strong>
        <button type="submit" class="btn btn-primary" name="dispatch[novoton_hotels.resync]"
                onclick="return confirm('{__("novoton_holidays.hotels_confirm_resync")|escape:javascript}');">
            <i class="icon-refresh"></i> {__("novoton_holidays.hotels_resync")}
        </button>
        <button type="submit" class="btn" name="dispatch[novoton_hotels.enable_products]"
                onclick="return confirm('{__("novoton_holidays.hotels_confirm_enable")|escape:javascript}');">
            {__("novoton_holidays.hotels_enable_products")}
        </button>
        <button type="submit" class="btn" name="dispatch[novoton_hotels.disable_products]"
                onclick="return confirm('{__("novoton_holidays.hotels_confirm_disable")|escape:javascript}');">
            {__("novoton_holidays.hotels_disable_products")}
        </button>
        <span class="muted">{__("novoton_holidays.hotels_resync_hint")}</span>
    </div>
    {/if}

    {include file="common/pagination.tpl" save_current_url=true}

    {if $novoton_hotels}
    <div class="table-responsive-wrapper">
    <table class="table table-middle table--relative table-responsive">
        <thead>
            <tr>
                <th width="1%">
                    <input type="checkbox" aria-label="{__("novoton_holidays.hotels_select_page")|escape:html}"
                           onclick="var boxes = document.querySelectorAll('.nvt-row-check'); for (var i = 0; i < boxes.length; i++) {ldelim} boxes[i].checked = this.checked; {rdelim}" />
                </th>
                <th width="70"></th>
                {foreach from=["name" => "novoton_holidays.hotels_col_hotel", "destination" => "novoton_holidays.hotels_col_destination", "stars" => "novoton_holidays.hotels_col_stars", "synced" => "novoton_holidays.hotels_col_synced", "status" => "novoton_holidays.hotels_col_status"] key=sortKey item=colLabel}
                    <th>
                        <a href="{"novoton_hotels.manage?`$novoton_hotels_query`&sort_by=`$sortKey`&sort_order=`$search.sort_order_rev`"|fn_url}">
                            {__($colLabel)}{if $search.sort_by == $sortKey} {if $search.sort_order == "asc"}&#9650;{else}&#9660;{/if}{/if}
                        </a>
                    </th>
                {/foreach}
                <th width="110" class="right">{__("actions")}</th>
            </tr>
        </thead>
        <tbody>
            {foreach from=$novoton_hotels item=h}
                {$st = $h.status}
                <tr>
                    <td data-th="">
                        <input type="checkbox" name="hotel_ids[]" value="{$h.id|escape:html}" class="nvt-row-check" aria-label="{$h.name|escape:html}" />
                    </td>
                    <td class="products-list__image" data-th="">
                        {if $h.product_id}
                            {include file="common/image.tpl"
                                image=$h.main_pair.icon|default:$h.main_pair.detailed
                                image_id=$h.main_pair.image_id
                                image_width=$settings.Thumbnails.product_admin_mini_icon_width
                                image_height=$settings.Thumbnails.product_admin_mini_icon_height
                                href="products.update?product_id=`$h.product_id`"|fn_url
                                image_css_class="products-list__image--img"
                                link_css_class="products-list__image--link"
                            }
                        {else}
                            <span class="muted">&mdash;</span>
                        {/if}
                    </td>
                    <td data-th="{__("novoton_holidays.hotels_col_hotel")}">
                        <strong>{$h.name|escape:html}</strong>
                        <div class="muted">ID {$h.id|escape:html}</div>
                    </td>
                    <td data-th="{__("novoton_holidays.hotels_col_destination")}">
                        {if $h.city}{$h.city|escape:html}{else}&mdash;{/if}
                        {if $h.area}<div class="muted">{$h.area|escape:html}</div>{/if}
                    </td>
                    <td data-th="{__("novoton_holidays.hotels_col_stars")}">
                        {if $h.stars}<span title="{$h.stars}*" aria-label="{$h.stars}*">{$h.stars_text}</span>{else}&mdash;{/if}
                        {if $h.type}<div class="muted">{$h.type|escape:html}</div>{/if}
                    </td>
                    <td data-th="{__("novoton_holidays.hotels_col_synced")}">
                        {if $h.synced_at}
                            {$h.synced_at}
                            <div class="muted">
                                {if $h.synced_days == 0}{__("today")}{else}{__("novoton_holidays.hotels_days_ago", ["[n]" => $h.synced_days])}{/if}
                            </div>
                        {else}
                            <span class="muted">{__("novoton_holidays.hotels_never_synced")}</span>
                        {/if}
                        {if $h.tried_at}<div class="text-error">{__("novoton_holidays.hotels_tried")} {$h.tried_at}</div>{/if}
                    </td>
                    <td data-th="{__("novoton_holidays.hotels_col_status")}">
                        <span class="label {$status_labels.$st}" title="{__("novoton_holidays.hotels_status_`$st`_hint")|escape:html}">{__("novoton_holidays.hotels_status_`$st`")}</span>
                        {if $h.queued && $st != "pending"}<div class="muted">{__("novoton_holidays.hotels_queued")}</div>{/if}
                        {if $h.product_deleted}
                            <div class="muted">{__("novoton_holidays.hotels_product_deleted")}</div>
                        {elseif $h.product_id && $h.product_status == "D"}
                            <div class="muted">{__("novoton_holidays.hotels_product_disabled")}</div>
                        {elseif $h.product_id && $h.product_status == "H"}
                            <div class="muted">{__("novoton_holidays.hotels_product_hidden")}</div>
                        {/if}
                    </td>
                    <td class="right nowrap" data-th="{__("actions")}">
                        <a class="btn cm-post" title="{__("novoton_holidays.hotels_resync")|escape:html}"
                           href="{"novoton_hotels.resync?hotel_id=`$h.id_url`&`$novoton_hotels_back`"|fn_url}">
                            <i class="icon-refresh"></i>
                        </a>
                        {if $h.product_id}
                            <a class="btn" title="{__("novoton_holidays.hotels_open_product")|escape:html} #{$h.product_id}"
                               href="{"products.update?product_id=`$h.product_id`"|fn_url}">
                                <i class="icon-edit"></i>
                            </a>
                        {/if}
                    </td>
                </tr>
            {/foreach}
        </tbody>
    </table>
    </div>
    {else}
        <p class="no-items">{__("no_data")}</p>
    {/if}

    {include file="common/pagination.tpl" save_current_url=true}
</form>

{/capture}

{capture name="buttons"}
    <a href="{"novoton_holidays.manage"|fn_url}" class="btn">{__("novoton_holidays.actions.novoton_dashboard")}</a>
{/capture}

{include file="common/mainbox.tpl"
    title=__("novoton_holidays.hotels_page_title")
    content=$smarty.capture.mainbox
    buttons=$smarty.capture.buttons
    sidebar=$smarty.capture.sidebar
}
