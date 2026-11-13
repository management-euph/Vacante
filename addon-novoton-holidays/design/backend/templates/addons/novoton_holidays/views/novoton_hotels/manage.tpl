{* Novoton → Hotels: the sync monitor (layout B2). One row per hotel of
   novoton_hotels: its sync state (read from the sync timestamps) and its
   CS-Cart product. Nothing of the hotel is edited here — the syncs own that
   data; an admin can queue hotels for a re-sync and enable / disable their
   products.

   Built from CS-Cart's own pieces, as the Products page is: the filter bar
   (components/search_filters, passed to mainbox: applies as it changes, an
   "Add filter" list), common/image.tpl (its no-image placeholder), the
   tools dropdown, cm-confirm, popupbox and common/pagination.tpl. *}

{$status_labels = ["active" => "label-success", "pending" => "label-warning", "error" => "label-important", "unmapped" => ""]}
{$counts = $novoton_hotel_counts}
{$nvt_check_statuses = []}
{foreach from=$novoton_hotel_statuses item=st}{$nvt_check_statuses[$st] = __("novoton_holidays.hotels_status_`$st`")}{/foreach}
{$back = $novoton_hotels_back}
{capture name="nvt_error_label"}{__("novoton_holidays.hotels_status_error")} ›{/capture}
{$nvt_error_label = $smarty.capture.nvt_error_label}

{capture name="mainbox"}

<p class="muted">{__("novoton_holidays.hotels_intro")}</p>
<p class="muted" title="{__("novoton_holidays.hotels_sync_tip", ["[list]" => $novoton_last_hotel_list|default:"—", "[info]" => $novoton_last_hotelinfo|default:"—"])|escape:html}">
    {__("novoton_holidays.hotels_last_info")}: <strong>{$novoton_last_hotelinfo|default:"—"}</strong>
    · {__("novoton_holidays.hotels_last_list")}: {$novoton_last_hotel_list|default:"—"}
</p>

{include file="common/pagination.tpl" save_current_page=true save_current_url=true}

{* Sync status: one click filters; the other filters stay. *}
<div class="nvt-hotels-statuses" style="display: flex; flex-wrap: wrap; align-items: center; gap: 6px; margin: 4px 0 12px;">
    <span class="muted">{__("novoton_holidays.hotels_col_status")}:</span>
    <a href="{"novoton_hotels.manage?`$novoton_hotels_query_status`"|fn_url}" class="btn btn-small{if !$search.sync_status} btn-primary{/if}">{__("all")} {$novoton_hotel_total}</a>
    {foreach from=$novoton_hotel_statuses item=st}
        <a href="{"novoton_hotels.manage?`$novoton_hotels_query_status`&sync_status=`$st`"|fn_url}"
           class="btn btn-small{if $search.sync_status == $st} btn-primary{/if}"
           title="{__("novoton_holidays.hotels_status_`$st`_hint")|escape:html}">
            {__("novoton_holidays.hotels_status_`$st`")} {$counts.$st}
        </a>
    {/foreach}
</div>

<form action="{""|fn_url}" method="post" name="nvt_hotels_form" id="nvt_hotels_form">
    <input type="hidden" name="security_hash" value="{$security_hash}" />
    {* The list to come back to: the filters, the sort and the page. *}
    <input type="hidden" name="redirect_query" value="{$back|escape:html}" />


    {if $novoton_hotels}
    {capture name="nvt_hotels_table"}
    <div class="table-responsive-wrapper longtap-selection">
    <table width="100%" class="table table-middle table--relative table-responsive table--show-checkbox nvt-hotels-table" data-ca-main-content>
        <thead class="thead--overflow-hidden" data-ca-bulkedit-default-object="true" data-target=".nvt-hotels-table" data-ca-bulkedit-component="defaultObject">
            <tr>
                <th width="3%" class="left mobile-hide table__check-items-column table__check-items-column--show-checkbox">
                    {* CS-Cart's check-all, with "select by sync status". *}
                    {include file="common/check_items.tpl"
                        show_checkbox=true
                        check_statuses=$nvt_check_statuses
                        meta="table__check-items table__check-items--show-checkbox"
                        class="check-items--show-checkbox"
                    }
                    <input type="checkbox"
                        class="bulkedit-toggler hide"
                        data-ca-bulkedit-disable="[data-ca-bulkedit-default-object=true]"
                        data-ca-bulkedit-enable="[data-ca-bulkedit-expanded-object=true]"
                    />
                </th>
                <th width="6%"></th>
                {foreach from=["name" => "novoton_holidays.hotels_col_hotel", "destination" => "novoton_holidays.hotels_col_destination", "stars" => "novoton_holidays.hotels_col_stars", "synced" => "novoton_holidays.hotels_col_synced", "status" => "novoton_holidays.hotels_col_status", "product" => "novoton_holidays.hotels_col_product"] key=sortKey item=colLabel}
                    <th>
                        <a class="cm-ajax" data-ca-target-id="pagination_contents" href="{"novoton_hotels.manage?`$novoton_hotels_query`&sort_by=`$sortKey`&sort_order=`$search.sort_order_rev`"|fn_url}">
                            {__($colLabel)}{if $search.sort_by == $sortKey} {if $search.sort_order == "asc"}&#9650;{else}&#9660;{/if}{/if}
                        </a>
                    </th>
                {/foreach}
                <th width="9%" class="right">{__("actions")}</th>
            </tr>
        </thead>
        <tbody>
            {foreach from=$novoton_hotels item=h}
                {$st = $h.status}
                <tr class="cm-longtap-target"
                    data-ca-longtap-action="setCheckBox"
                    data-ca-longtap-target="input.cm-item"
                    data-ca-id="{$h.id|escape:html}">
                    <td width="3%" class="left mobile-hide table__check-items-cell table__check-items-cell--show-checkbox">
                        <input type="checkbox" name="hotel_ids[]" value="{$h.id|escape:html}" class="cm-item cm-item-status-{$st}"
                               aria-label="{$h.name|escape:html}" />
                    </td>
                    <td class="products-list__image">
                        {* With no image (or no product), core draws its own no-image placeholder. *}
                        {if $h.product_id}{$img_href = "products.update?product_id=`$h.product_id`"|fn_url}{else}{$img_href = ""}{/if}
                        {include file="common/image.tpl"
                            image=$h.main_pair.icon|default:$h.main_pair.detailed
                            image_id=$h.main_pair.image_id
                            image_width=$settings.Thumbnails.product_admin_mini_icon_width
                            image_height=$settings.Thumbnails.product_admin_mini_icon_height
                            href=$img_href
                            image_css_class="products-list__image--img"
                            link_css_class="products-list__image--link"
                        }
                    </td>
                    <td data-th="{__("novoton_holidays.hotels_col_hotel")}">
                        {if $h.product_id}
                            <a href="{"products.update?product_id=`$h.product_id`"|fn_url}" class="row-status">{$h.name|escape:html}</a>
                        {else}
                            <span class="row-status">{$h.name|escape:html}</span>
                        {/if}
                        <div class="muted">
                            ID {$h.id|escape:html}
                            {if $h.property_type}
                                {$ptype = $h.property_type|replace:"-":"_"}
                                <span class="label" style="margin-left: 6px;">{__("novoton_holidays.hotels_type_`$ptype`")}</span>
                            {/if}
                        </div>
                    </td>
                    <td data-th="{__("novoton_holidays.hotels_col_destination")}">
                        {if $h.city}{$h.city|escape:html}{else}&mdash;{/if}
                        {if $h.area}<div class="muted">{$h.area|escape:html}</div>{/if}
                    </td>
                    <td data-th="{__("novoton_holidays.hotels_col_stars")}">
                        {* As Novoton writes it: "4*", "3* Sup", "Apart". *}
                        {if $h.type}{$h.type|escape:html}{else}&mdash;{/if}
                    </td>
                    <td data-th="{__("novoton_holidays.hotels_col_synced")}">
                        {if $h.synced_at}
                            {$h.synced_at}
                            <div class="muted">{if $h.synced_days == 0}{__("today")}{else}{__("novoton_holidays.hotels_days_ago", ["[n]" => $h.synced_days])}{/if}</div>
                        {else}
                            <span class="muted">{__("novoton_holidays.hotels_never_synced")}</span>
                        {/if}
                        {if $h.tried_at}<div class="text-error">{__("novoton_holidays.hotels_tried")} {$h.tried_at}</div>{/if}
                    </td>
                    <td data-th="{__("novoton_holidays.hotels_col_status")}">
                        {if $st == "error"}
                            {* The badge opens the error panel. *}
                            {capture name="nvt_error_panel"}
                                <dl class="dl-horizontal">
                                    <dt>{__("novoton_holidays.hotels_err_last_request")}</dt><dd class="text-error">{$h.tried_at} · {__("novoton_holidays.hotels_err_no_answer")}</dd>
                                    <dt>{__("novoton_holidays.hotels_err_last_success")}</dt><dd>{if $h.synced_at}{$h.synced_at}{else}{__("novoton_holidays.hotels_never_synced")}{/if}</dd>
                                    <dt>{__("novoton_holidays.hotels_err_next_try")}</dt><dd>{if $h.next_try}{$h.next_try}{else}&mdash;{/if}</dd>
                                    <dt>{__("novoton_holidays.hotels_queued")}</dt><dd>{if $h.queued}{__("yes")}{else}{__("no")}{/if}</dd>
                                </dl>
                                <p class="muted">{__("novoton_holidays.hotels_err_no_answer_note")}</p>
                                {if $h.answer}
                                    <strong>{__("novoton_holidays.hotels_err_last_answer")}</strong>
                                    <pre style="max-height: 280px; overflow: auto; font-size: 12px;">{$h.answer|escape:html}</pre>
                                {/if}
                                <div class="buttons-container">
                                    {if $h.product_id}<a class="btn" href="{"products.update?product_id=`$h.product_id`"|fn_url}">{__("novoton_holidays.hotels_open_product")}</a>{/if}
                                    <a class="btn btn-primary cm-post" href="{"novoton_hotels.resync?hotel_id=`$h.id_url`&`$back`"|fn_url}"><i class="icon-refresh"></i> {__("novoton_holidays.hotels_queue_retry")}</a>
                                </div>
                            {/capture}
                            {include file="common/popupbox.tpl"
                                id=$h.dom_id
                                text=$h.name
                                link_text=$nvt_error_label
                                act="link"
                                link_class="label label-important"
                                content=$smarty.capture.nvt_error_panel
                            }
                        {else}
                            <span class="label {$status_labels.$st}" title="{__("novoton_holidays.hotels_status_`$st`_hint")|escape:html}">{__("novoton_holidays.hotels_status_`$st`")}</span>
                        {/if}
                        {if $h.queued && $st != "pending"}<div class="muted">{__("novoton_holidays.hotels_queued")}</div>{/if}
                    </td>
                    <td data-th="{__("novoton_holidays.hotels_col_product")}">
                        {if $h.product_id}
                            <a href="{"products.update?product_id=`$h.product_id`"|fn_url}">#{$h.product_id}</a>
                            <div class="muted">
                                {if $h.product_status == "A"}{__("active")}{elseif $h.product_status == "D"}{__("disabled")}{elseif $h.product_status == "H"}{__("hidden")}{/if}
                            </div>
                        {elseif $h.product_deleted}
                            <span class="muted">{__("novoton_holidays.hotels_product_deleted")}</span>
                        {else}
                            <span class="muted">{__("novoton_holidays.hotels_product_without")}</span>
                        {/if}
                    </td>
                    <td class="right nowrap">
                        <a class="btn cm-post cm-tooltip" title="{__("novoton_holidays.hotels_resync")|escape:html}"
                           href="{"novoton_hotels.resync?hotel_id=`$h.id_url`&`$back`"|fn_url}"
                           aria-label="{__("novoton_holidays.hotels_resync")|escape:html}">
                            <i class="icon-refresh"></i>
                        </a>
                        {capture name="tools_list"}
                            <li>{btn type="list" text=__("novoton_holidays.hotels_resync") href="novoton_hotels.resync?hotel_id=`$h.id_url`&`$back`" method="POST"}</li>
                            {if $h.product_id}
                                <li>{btn type="list" text=__("novoton_holidays.hotels_open_product") href="products.update?product_id=`$h.product_id`"}</li>
                                {if $h.product_status == "A"}
                                    <li class="divider"></li>
                                    <li>{btn type="list" text="{__("novoton_holidays.hotels_disable_product")}…" class="cm-confirm text-error" data=["data-ca-confirm-text" => __("novoton_holidays.hotels_confirm_disable_one", ["[hotel]" => $h.name])] href="novoton_hotels.disable_products?hotel_id=`$h.id_url`&`$back`" method="POST"}</li>
                                {else}
                                    <li>{btn type="list" text=__("novoton_holidays.hotels_enable_product") href="novoton_hotels.enable_products?hotel_id=`$h.id_url`&`$back`" method="POST"}</li>
                                {/if}
                            {/if}
                        {/capture}
                        {dropdown content=$smarty.capture.tools_list}
                    </td>
                </tr>
            {/foreach}
        </tbody>
    </table>
    </div>
    {/capture}

    {* CS-Cart's bulk bar (schemas/context_menu/novoton_hotels.php): it shows
       when hotels are checked — Status, Actions — as on the Products page. *}
    {include file="common/context_menu_wrapper.tpl"
        form="nvt_hotels_form"
        object="novoton_hotels"
        items=$smarty.capture.nvt_hotels_table
        is_check_all_shown=true
    }
    {else}
        <p class="no-items">{__("no_data")}</p>
    {/if}
</form>



{include file="common/pagination.tpl"}

{/capture}

{capture name="nvt_city_picker"}
    {* CS-Cart's object picker (Select2): type, and it lists the matching
       cities and regions (novoton_hotels.places); picking one filters. *}
    <div class="object-picker">
        <select name="city"
                class="cm-object-picker object-picker__select"
                data-ca-object-picker-ajax-url="{"novoton_hotels.places"|fn_url}"
                data-ca-object-picker-ajax-delay="250"
                data-ca-object-picker-escape-html="false"
                data-ca-object-picker-allow-clear="true"
                data-ca-object-picker-placeholder="{__("novoton_holidays.hotels_city_type_to_search")|escape:"javascript"}"
                data-ca-object-picker-placeholder-value=""
                data-ca-object-picker-width="100%"
                data-ca-search-filters="field">
            <option value="">{__("novoton_holidays.hotels_city_type_to_search")}</option>
            {if $search.city}<option value="{$search.city|escape:html}" selected="selected">{$search.city|escape:html}</option>{/if}
        </select>
    </div>
{/capture}
{$novoton_search_filters.data.city.content = $smarty.capture.nvt_city_picker}

{capture name="buttons"}
    {include file="addons/travel_core/components/run_job.tpl" run_dispatch="novoton_holidays.run_job" job="hotel_info_batched" label=__("novoton_holidays.hotels_run_details_sync") class="btn"}
    <a href="{"novoton_holidays.manage"|fn_url}" class="btn">{__("novoton_holidays.actions.novoton_dashboard")}</a>
{/capture}

{include file="common/mainbox.tpl"
    title=__("novoton_holidays.hotels_page_title")
    content=$smarty.capture.mainbox
    buttons=$smarty.capture.buttons
    search_filters=$novoton_search_filters
    content_id="manage_novoton_hotels"
}
