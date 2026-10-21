{script src="js/lib/select2/dist/js/select2.full.min.js"}
{style src="js/lib/select2/dist/css/select2.min.css"}
{script src="js/tygh/backend/bulkedit.js"}

{* ── Sidebar: Filter Panel ── *}
{capture name="sidebar"}
<div class="sidebar-row">
    <h6>{__("search")}</h6>

    <form action="{""|fn_url}" method="get" name="sphinx_hotels_filter_form" id="sphinx_hotels_filter_form">
        <input type="hidden" name="dispatch" value="sphinx_holidays.hotels" />

        <div class="sidebar-field">
            <label>{__("sphinx_holidays.country_code")}:</label>
            <select name="country_code" id="sphinx_country_filter">
                <option value="">--</option>
                {foreach from=$distinct_countries item=cc}
                    <option value="{$cc}" {if $search.country_code == $cc}selected{/if}>{$cc}</option>
                {/foreach}
            </select>
        </div>

        <div class="sidebar-field">
            <label>{__("sphinx_holidays.city")}:</label>
            <input type="text" name="city" value="{$search.city|escape:html}" placeholder="{__("sphinx_holidays.city")}" />
        </div>

        <div class="sidebar-field">
            <label>{__("sphinx_holidays.classification")}:</label>
            <select name="classification">
                <option value="">{__("sphinx_holidays.all_classifications")}</option>
                {foreach from=$distinct_classifications item=cl}
                    {if $cl == 0}
                        <option value="0" {if $search.classification == '0' && $search.classification != ''}selected{/if}>{__("sphinx_holidays.unclassified")}</option>
                    {else}
                        <option value="{$cl}" {if $search.classification == $cl && $search.classification != ''}selected{/if}>{$cl}&#9733;</option>
                    {/if}
                {/foreach}
            </select>
        </div>

        <div class="sidebar-field">
            <label>{__("sphinx_holidays.property_type")}:</label>
            <select name="property_type">
                <option value="">{__("sphinx_holidays.all_property_types")}</option>
                {foreach from=$distinct_property_types item=pt}
                    <option value="{$pt|escape:html}" {if $search.property_type == $pt}selected{/if}>{$pt|escape:html}</option>
                {/foreach}
            </select>
        </div>

        <div class="sidebar-field">
            <label>{__("sphinx_holidays.sync_status")}:</label>
            <select name="sync_status">
                <option value="">{__("sphinx_holidays.all_statuses")}</option>
                <option value="active" {if $search.sync_status == "active"}selected{/if}>{__("sphinx_holidays.active")}</option>
                <option value="inactive" {if $search.sync_status == "inactive"}selected{/if}>{__("sphinx_holidays.inactive")}</option>
                <option value="error" {if $search.sync_status == "error"}selected{/if}>{__("error")}</option>
            </select>
        </div>

        <div class="sidebar-field">
            <label>{__("sphinx_holidays.link_status")}:</label>
            <select name="link_status">
                <option value="">{__("sphinx_holidays.all_link_statuses")}</option>
                <option value="linked" {if $search.link_status == "linked"}selected{/if}>{__("sphinx_holidays.linked")}</option>
                <option value="orphan" {if $search.link_status == "orphan"}selected{/if}>{__("sphinx_holidays.orphan")}</option>
            </select>
        </div>

        <div class="sidebar-field">
            <label>{__("sphinx_holidays.availability_filter")}:</label>
            <select name="availability">
                <option value="">{__("sphinx_holidays.availability_immediate")}</option>
                <option value="waiting" {if $search.availability == "waiting"}selected{/if}>{__("sphinx_holidays.availability_waiting")}</option>
                <option value="all" {if $search.availability == "all"}selected{/if}>{__("sphinx_holidays.availability_all")}</option>
            </select>
        </div>

        <div class="sidebar-field">
            <label>{__("name")}:</label>
            <input type="hidden" name="q" id="hotel_search_q" value="{$search.q|escape:html}" />
            <select id="hotel_name_select2" style="width: 100%;">
                {if $search.q}
                    <option value="{$search.q|escape:html}" selected>{$search.q|escape:html}</option>
                {/if}
            </select>
        </div>

        <div class="sidebar-field">
            <input type="submit" class="btn" value="{__("search")}" />
        </div>
    </form>
</div>
{/capture}


{* ── Main Content ── *}
{capture name="mainbox"}

{include file="common/pagination.tpl" save_current_url=true}

{* $sort_url_base is built in the controller with proper URL encoding *}

<form action="{"sphinx_holidays.bulk_update_hotels"|fn_url}" method="post" name="sphinx_bulk_form" id="sphinx_bulk_form">
<input type="hidden" name="security_hash" value="{$security_hash}" />
<input type="hidden" name="bulk_status" value="active" id="sphinx_bulk_action" />

{if $hotels}
{* longtap-selection + data-ca-bulkedit-component activate the native
   selection driver (row click/tap toggles the row checkbox, keeps the
   selected counter current and fires ce.tap.toggle); bulkedit.js then swaps
   the default header for the contextual action bar — the same mechanism
   products.manage uses. *}
<div class="longtap-selection" data-ca-bulkedit-component="true" data-ca-longtap="true">
<table class="table table-middle table-striped">
    <thead data-ca-bulkedit-default-object="true">
        <tr>
            {* Bulk-mode toggler: checking it swaps this header for the
               contextual action bar below. *}
            <th width="30">
                <input type="checkbox" class="bulkedit-toggler"
                       data-ca-bulkedit-enable="[data-ca-bulkedit-expanded-object=true]"
                       data-ca-bulkedit-disable="[data-ca-bulkedit-default-object=true]" />
            </th>

            {* ID — sortable *}
            <th width="100">
                <a href="{"`$sort_url_base`&sort_by=hotel_id&sort_order=`$search.sort_order_toggle`"|fn_url}">
                    ID
                    {if $search.sort_by == 'hotel_id'}
                        {if $search.sort_order == 'asc'}&#9650;{else}&#9660;{/if}
                    {/if}
                </a>
            </th>

            {* Name — sortable *}
            <th>
                <a href="{"`$sort_url_base`&sort_by=name&sort_order=`$search.sort_order_toggle`"|fn_url}">
                    {__("name")}
                    {if $search.sort_by == 'name'}
                        {if $search.sort_order == 'asc'}&#9650;{else}&#9660;{/if}
                    {/if}
                </a>
            </th>

            {* Classification — sortable *}
            <th width="100">
                <a href="{"`$sort_url_base`&sort_by=classification&sort_order=`$search.sort_order_toggle`"|fn_url}">
                    {__("sphinx_holidays.classification")}
                    {if $search.sort_by == 'classification'}
                        {if $search.sort_order == 'asc'}&#9650;{else}&#9660;{/if}
                    {/if}
                </a>
            </th>

            {* Country — sortable *}
            <th width="80">
                <a href="{"`$sort_url_base`&sort_by=country_code&sort_order=`$search.sort_order_toggle`"|fn_url}">
                    {__("sphinx_holidays.country_code")}
                    {if $search.sort_by == 'country_code'}
                        {if $search.sort_order == 'asc'}&#9650;{else}&#9660;{/if}
                    {/if}
                </a>
            </th>

            {* City — the RESOLVED city (what the product's City feature uses),
               not the raw API address city; sortable *}
            <th width="140">
                <a href="{"`$sort_url_base`&sort_by=destination_name&sort_order=`$search.sort_order_toggle`"|fn_url}">
                    {__("sphinx_holidays.city")}
                    {if $search.sort_by == 'destination_name'}
                        {if $search.sort_order == 'asc'}&#9650;{else}&#9660;{/if}
                    {/if}
                </a>
            </th>

            {* Region — the RESOLVED region (product's Region feature), sortable *}
            <th width="140">
                <a href="{"`$sort_url_base`&sort_by=region_name&sort_order=`$search.sort_order_toggle`"|fn_url}">
                    {__("sphinx_holidays.region")}
                    {if $search.sort_by == 'region_name'}
                        {if $search.sort_order == 'asc'}&#9650;{else}&#9660;{/if}
                    {/if}
                </a>
            </th>

            {* Country — from the hotel API address, sortable *}
            <th width="120">
                <a href="{"`$sort_url_base`&sort_by=address_country&sort_order=`$search.sort_order_toggle`"|fn_url}">
                    {__("sphinx_holidays.country")}
                    {if $search.sort_by == 'address_country'}
                        {if $search.sort_order == 'asc'}&#9650;{else}&#9660;{/if}
                    {/if}
                </a>
            </th>

            {* Type — sortable *}
            <th width="90">
                <a href="{"`$sort_url_base`&sort_by=property_type&sort_order=`$search.sort_order_toggle`"|fn_url}">
                    {__("sphinx_holidays.property_type")}
                    {if $search.sort_by == 'property_type'}
                        {if $search.sort_order == 'asc'}&#9650;{else}&#9660;{/if}
                    {/if}
                </a>
            </th>

            {* Product link *}
            <th width="80">{__("sphinx_holidays.product")}</th>

            {* Status — sortable *}
            <th width="80">
                <a href="{"`$sort_url_base`&sort_by=sync_status&sort_order=`$search.sort_order_toggle`"|fn_url}">
                    {__("sphinx_holidays.sync_status")}
                    {if $search.sort_by == 'sync_status'}
                        {if $search.sort_order == 'asc'}&#9650;{else}&#9660;{/if}
                    {/if}
                </a>
            </th>

            {* Last Synced — sortable *}
            <th width="140">
                <a href="{"`$sort_url_base`&sort_by=last_synced_at&sort_order=`$search.sort_order_toggle`"|fn_url}">
                    {__("sphinx_holidays.last_synced")}
                    {if $search.sort_by == 'last_synced_at'}
                        {if $search.sort_order == 'asc'}&#9650;{else}&#9660;{/if}
                    {/if}
                </a>
            </th>
        </tr>
    </thead>
    {* Contextual bulk bar — shown while rows are selected (or via the
       toggler). check_all uses the core cm-check-items microformat; the
       counter is updated by the selection driver; every action is a plain
       dispatch[] submit of the surrounding form, so the checked
       hotel_ids[] (+ bulk_status for the status actions) POST exactly as
       the old "With selected" buttons did — the three backend handlers are
       unchanged. *}
    {* products.manage-style bar: three dropdowns (N Selected / Status /
       Actions), plain-text items — no icons. "All" proxies a real click on
       the native check_all checkbox so counter/highlight update through the
       same core handlers as a user click; "None" is the core
       bulkedit-deselect handler. *}
    <thead class="hidden" data-ca-bulkedit-expanded-object="true">
        <tr>
            <th width="30">
                <input type="checkbox" name="check_all" id="sphinx_check_all" class="cm-check-items" title="{__("check_uncheck_all")}" />
            </th>
            <th colspan="11">
                <div class="bulk-edit" style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                    <div class="btn-group dropdown">
                        <a class="btn dropdown-toggle" data-toggle="dropdown" href="#">
                            <strong><span data-ca-longtap-selected-counter="true">0</span> {__("sphinx_holidays.selected_count_label")}</strong> <span class="caret"></span>
                        </a>
                        <ul class="dropdown-menu">
                            <li>
                                <a href="#" onclick="var c = document.getElementById('sphinx_check_all'); if (c) { if (c.checked) { c.click(); } c.click(); } return false;">{__("sphinx_holidays.bulk_select_all")}</a>
                            </li>
                            <li>
                                <a href="#" class="bulkedit-deselect">{__("sphinx_holidays.bulk_select_none")}</a>
                            </li>
                        </ul>
                    </div>
                    <div class="btn-group dropdown">
                        <a class="btn dropdown-toggle" data-toggle="dropdown" href="#">{__("status")} <span class="caret"></span></a>
                        <ul class="dropdown-menu">
                            <li>
                                <button type="submit" class="btn btn-link" name="dispatch[sphinx_holidays.bulk_update_hotels]"
                                        onclick="document.getElementById('sphinx_bulk_action').value='active';">
                                    {__("sphinx_holidays.bulk_change_to_active")}
                                </button>
                            </li>
                            <li>
                                <button type="submit" class="btn btn-link" name="dispatch[sphinx_holidays.bulk_update_hotels]"
                                        onclick="document.getElementById('sphinx_bulk_action').value='inactive';">
                                    {__("sphinx_holidays.bulk_change_to_disabled")}
                                </button>
                            </li>
                        </ul>
                    </div>
                    <div class="btn-group dropdown">
                        <a class="btn dropdown-toggle" data-toggle="dropdown" href="#">{__("sphinx_holidays.bulk_actions")} <span class="caret"></span></a>
                        <ul class="dropdown-menu">
                            <li>
                                <button type="submit" class="btn btn-link" name="dispatch[sphinx_holidays.bulk_sync_images]">
                                    {__("sphinx_holidays.bulk_sync_images")}
                                </button>
                            </li>
                            <li>
                                <button type="submit" class="btn btn-link cm-confirm" name="dispatch[sphinx_holidays.bulk_delete_hotels]"
                                        data-ca-confirm-text="{__("sphinx_holidays.bulk_delete_confirm")|escape:html}">
                                    {__("sphinx_holidays.bulk_delete")}
                                </button>
                            </li>
                        </ul>
                    </div>
                    <a href="#" class="bulkedit-disabler btn" style="margin-left:auto;"
                       data-ca-bulkedit-enable="[data-ca-bulkedit-default-object=true]"
                       data-ca-bulkedit-disable="[data-ca-bulkedit-expanded-object=true]"
                       title="{__("close")}">&times;</a>
                </div>
            </th>
        </tr>
    </thead>
    <tbody>
        {foreach from=$hotels item=hotel}
        <tr class="cm-longtap-target"
            data-ca-longtap-action="setCheckBox"
            data-ca-longtap-target="input.cm-item"
            data-ca-id="{$hotel.hotel_id|escape:html}">
            {* Checkbox *}
            <td>
                <input type="checkbox" name="hotel_ids[]" value="{$hotel.hotel_id|escape:html}" class="cm-item" />
            </td>

            {* Hotel ID *}
            <td>
                <code style="font-size: 11px;">{$hotel.hotel_id|escape:html|truncate:18}</code>
            </td>

            {* Name + thumbnail *}
            <td>
                <div style="display:flex; align-items:center; gap:8px;">
                    {if $hotel.image_url}
                        <img src="{$hotel.image_url|escape:html}" alt="" style="width:40px; height:40px; object-fit:cover; border-radius:3px; flex-shrink:0;" loading="lazy" />
                    {else}
                        <div class="no-image" style="width:40px; height:40px; flex-shrink:0; margin:0;">
                            <span class="cs-icon glyph-image" title="{__("no_image")}"></span>
                        </div>
                    {/if}
                    <span>{$hotel.name|escape:html}</span>
                </div>
            </td>

            {* Classification *}
            <td>
                {if $hotel.classification > 0}
                    {$hotel.classification}<span style="color:#f39c12;">&#9733;</span>
                {else}
                    <span class="muted">-</span>
                {/if}
            </td>

            {* Country *}
            <td>{$hotel.country_code|escape:html}</td>

            {* City — the resolved value the product's City feature uses,
               with a badge naming the rung of the ladder that answered.
               address_city is the raw API string and only shows as a
               fallback (it is exactly what the ladder demotes when it
               merely repeats the region). *}
            <td>
                {if $hotel.destination_name}
                    {$hotel.destination_name|escape:html}
                {elseif $hotel.address_city}
                    {$hotel.address_city|escape:html}
                {else}-{/if}
                {if $hotel.location_source == 'destination' || $hotel.location_source == 'ancestor'}
                    <span class="sphinx-badge sphinx-badge-success">{__("sphinx_holidays.location_source_tree")}</span>
                {elseif $hotel.location_source == 'address'}
                    <span class="sphinx-badge sphinx-badge-warning">{__("sphinx_holidays.location_source_address")}</span>
                {elseif $hotel.location_source == 'geocode'}
                    <span class="sphinx-badge sphinx-badge-info">{__("sphinx_holidays.location_source_geocode")}</span>
                {/if}
            </td>

            {* Region — the resolved value the product's Region feature uses *}
            <td>{$hotel.region_name|default:"-"|escape:html}</td>

            {* Country — hotel API address.country *}
            <td>{$hotel.address_country|default:"-"|escape:html}</td>

            {* Property Type *}
            <td>{$hotel.property_type|escape:html}</td>

            {* Product Link *}
            <td>
                {if $hotel.product_id > 0}
                    <a href="{"products.update?product_id=`$hotel.product_id`"|fn_url}" title="{__("sphinx_holidays.product")} #{$hotel.product_id}">
                        <i class="icon-link"></i> #{$hotel.product_id}
                    </a>
                {else}
                    <span class="muted">-</span>
                {/if}
            </td>

            {* Status *}
            <td>
                {if $hotel.sync_status == 'active'}
                    <span class="label label-success" title="{__("sphinx_holidays.active_hint")}">{__("sphinx_holidays.active")}</span>
                {elseif $hotel.sync_status == 'inactive'}
                    <span class="label label-warning" title="{__("sphinx_holidays.inactive_hint")}">{__("sphinx_holidays.inactive")}</span>
                {else}
                    <span class="label label-important" title="{__("sphinx_holidays.error_hint")}">{__("error")}</span>
                {/if}
            </td>

            {* Last Synced *}
            <td>{$hotel.last_synced_at|default:"-"}</td>
        </tr>
        {/foreach}
    </tbody>
</table>
</div>

{else}
    <p class="no-items">{__("sphinx_holidays.no_hotels")}</p>
{/if}

{include file="common/pagination.tpl"}

</form>

{* ── JavaScript: hotel-name autocomplete (Select2) ── *}
<script>
(function() {ldelim}
    // ─── Hotel name autocomplete (Select2 + AJAX) ───
    if (typeof $ !== 'undefined' && typeof $.fn.select2 !== 'undefined') {ldelim}
        (function() {ldelim}
            var $sel = $('#hotel_name_select2');
            var $hidden = $('#hotel_search_q');

            $sel.select2({ldelim}
                ajax: {ldelim}
                    url: '{"sphinx_holidays.search_hotels"|fn_url:"A"}',
                    dataType: 'json',
                    delay: 300,
                    data: function(params) {ldelim}
                        return {ldelim} q: params.term {rdelim};
                    {rdelim},
                    processResults: function(data) {ldelim}
                        return {ldelim}
                            results: (data.results || []).map(function(r) {ldelim}
                                var stars = r.classification > 0 ? ' ' + r.classification + '\u2605' : '';
                                var loc = r.destination_name
                                    ? r.destination_name + ', ' + r.country_code
                                    : r.country_code;
                                return {ldelim}
                                    id: r.name,
                                    text: r.name + stars + '  \u2014 ' + loc
                                {rdelim};
                            {rdelim})
                        {rdelim};
                    {rdelim},
                    cache: true
                {rdelim},
                minimumInputLength: 2,
                placeholder: '{__("sphinx_holidays.search_hotels")|escape:"javascript"}',
                allowClear: true,
                width: '100%'
            {rdelim});

            // On selection: copy name to hidden input and auto-submit form
            $sel.on('select2:select', function(e) {ldelim}
                $hidden.val(e.params.data.id);
                document.getElementById('sphinx_hotels_filter_form').submit();
            {rdelim});

            // On clear: reset hidden input and auto-submit form
            $sel.on('select2:unselecting', function() {ldelim}
                $hidden.val('');
                setTimeout(function() {ldelim}
                    document.getElementById('sphinx_hotels_filter_form').submit();
                {rdelim}, 50);
            {rdelim});
        {rdelim})();
    {rdelim}

{rdelim})();
</script>

{/capture}

{include file="common/mainbox.tpl"
    title="{__('sphinx_holidays.hotels')}"
    content=$smarty.capture.mainbox
    sidebar=$smarty.capture.sidebar
}
