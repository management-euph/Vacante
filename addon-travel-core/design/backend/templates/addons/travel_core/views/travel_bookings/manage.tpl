{*
 * Unified Travel Bookings Management Page
 * Shows bookings from ALL providers (Novoton, Sphinx, etc.)
 *}

{capture name="mainbox"}

<div class="travel-bookings-manage">
    {* Search/Filter Form *}
    <form action="{""|fn_url}" method="get" class="form-horizontal form-inline search-form">
        <input type="hidden" name="dispatch" value="travel_bookings.manage" />

        <div class="control-group">
            <label class="control-label" for="search_provider">{__("provider")}:</label>
            <div class="controls">
                <select name="provider" id="search_provider">
                    <option value="">{__("all")}</option>
                    {foreach from=$providers key=code item=provider}
                        <option value="{$code}" {if $search.provider == $code}selected{/if}>{$provider.label|default:$provider.name|escape:html}</option>
                    {/foreach}
                </select>
            </div>
        </div>

        <div class="control-group">
            <label class="control-label" for="search_status">{__("status")}:</label>
            <div class="controls">
                <select name="status" id="search_status">
                    <option value="">{__("all")}</option>
                    {foreach from=$statuses item=st}
                        <option value="{$st}" {if $search.status == $st}selected{/if}>{$st|capitalize}</option>
                    {/foreach}
                </select>
            </div>
        </div>

        <div class="control-group">
            <label class="control-label" for="search_order_id">{__("order_id")}:</label>
            <div class="controls">
                <input type="text" name="order_id" id="search_order_id" value="{$search.order_id}" size="10" />
            </div>
        </div>

        <div class="control-group">
            <label class="control-label" for="search_hotel_name">{__("travel_core.hotel_name")}:</label>
            <div class="controls">
                <input type="text" name="hotel_name" id="search_hotel_name" value="{$search.hotel_name|escape:html}" size="20" />
            </div>
        </div>

        <div class="control-group">
            <label class="control-label">{__("travel_core.dates")}:</label>
            <div class="controls">
                {include file="common/calendar.tpl" date_id="date_from" date_name="date_from" date_val=$search.date_from extra="placeholder='{__("travel_core.date_from")}'"}
                <span style="display: inline-block; padding: 0 5px; vertical-align: middle; color: #999;">&mdash;</span>
                {include file="common/calendar.tpl" date_id="date_to" date_name="date_to" date_val=$search.date_to extra="placeholder='{__("travel_core.date_to")}'"}

            </div>
        </div>

        <div class="control-group">
            <label class="control-label" for="search_sort_by">{__("sort_by")}:</label>
            <div class="controls">
                <select name="sort_by" id="search_sort_by">
                    <option value="created_at" {if $search.sort_by == 'created_at'}selected{/if}>{__("date")}</option>
                    <option value="order_id" {if $search.sort_by == 'order_id'}selected{/if}>{__("order_id")}</option>
                    <option value="check_in" {if $search.sort_by == 'check_in'}selected{/if}>{__("travel_core.check_in")}</option>
                    <option value="total_price" {if $search.sort_by == 'total_price'}selected{/if}>{__("price")}</option>
                </select>
                <select name="sort_order">
                    <option value="desc" {if $search.sort_order == 'desc'}selected{/if}>v {__("descending")}</option>
                    <option value="asc" {if $search.sort_order == 'asc'}selected{/if}>^ {__("ascending")}</option>
                </select>
            </div>
        </div>

        <button type="submit" class="btn btn-primary">{__("search")}</button>
    </form>

    {* Bulk Actions *}
    {if $providers}
    <div style="margin-bottom: 15px;">
        {foreach from=$providers key=code item=provider}
        <form action="{"travel_bookings.bulk_check_status"|fn_url}" method="post" class="form-inline" style="display: inline-block; margin-right: 10px;">
            <input type="hidden" name="security_hash" value="{$security_hash}">
            <input type="hidden" name="provider" value="{$code}" />
            <button type="submit" class="btn btn-default btn-sm">
                <i class="icon-refresh"></i> Check All {$provider.label|default:$provider.name|escape:html} Statuses
            </button>
        </form>
        {/foreach}
    </div>
    {/if}

    {* Bookings Table. CS-Cart's pagination is a PAIR of includes: this one
       opens the pagination container, the one after the table draws the
       controls and closes it. *}
    {include file="common/pagination.tpl" save_current_url=true}

    {if $bookings}
    <table class="table table-striped table-hover">
        <thead>
            <tr>
                <th width="80">
                    <a href="{"travel_bookings.manage?sort_by=order_id&sort_order=`$sort_order_toggle`"|fn_url}">
                        {__("order_id")}
                        {if $search.sort_by == 'order_id'}
                            {if $search.sort_order == 'asc'}^{else}v{/if}
                        {/if}
                    </a>
                </th>
                <th width="80">{__("provider")}</th>
                <th>{__("travel_core.hotel")}</th>
                <th>{__("travel_core.room")}</th>
                <th>
                    <a href="{"travel_bookings.manage?sort_by=check_in&sort_order=`$sort_order_toggle`"|fn_url}">
                        {__("travel_core.check_in")}
                        {if $search.sort_by == 'check_in'}
                            {if $search.sort_order == 'asc'}^{else}v{/if}
                        {/if}
                    </a>
                </th>
                <th>{__("travel_core.nights")}</th>
                <th>{__("travel_core.guests")}</th>
                <th>
                    <a href="{"travel_bookings.manage?sort_by=total_price&sort_order=`$sort_order_toggle`"|fn_url}">
                        {__("price")}
                        {if $search.sort_by == 'total_price'}
                            {if $search.sort_order == 'asc'}^{else}v{/if}
                        {/if}
                    </a>
                </th>
                <th width="100">Booking confirmation</th>
                <th width="80">{__("status")}</th>
                <th width="100">{__("tools")}</th>
            </tr>
        </thead>
        <tbody>
            {foreach from=$bookings item=booking}
            <tr>
                {* Order ID *}
                <td>
                    {if $booking.order_id > 0}
                    <a href="{"orders.details?order_id=`$booking.order_id`"|fn_url}">{$booking.order_id}</a>
                    {else}
                    <span class="muted">-</span>
                    {/if}
                </td>

                {* Provider Badge *}
                <td>
                    {if $booking.provider == 'novoton'}
                        <span class="label label-info">Novoton</span>
                    {elseif $booking.provider == 'sphinx'}
                        <span class="label label-success">Sphinx</span>
                    {elseif $booking.provider == 'eurosite'}
                        <span class="label label-warning">Eurosite</span>
                    {else}
                        <span class="label">{$booking.provider|escape:html}</span>
                    {/if}
                </td>

                {* Hotel — links to the storefront product page when the
                   provider resolved one (hotel_product_url, new window). *}
                <td>
                    {if $booking.hotel_product_url}
                        <a href="{$booking.hotel_product_url|escape:html}" target="_blank" rel="noopener">
                            <strong>{if $booking.hotel_name}{$booking.hotel_name|escape:html}{else}Hotel #{$booking.hotel_id|escape:html}{/if}</strong>
                        </a>
                    {elseif $booking.hotel_name}
                        <strong>{$booking.hotel_name|escape:html}</strong>
                    {else}
                        <strong>Hotel #{$booking.hotel_id|escape:html}</strong>
                    {/if}
                    {if $booking.destination}
                        <br><small class="muted">{$booking.destination|escape:html}</small>
                    {/if}
                </td>

                {* Room / Board *}
                <td>
                    {if $booking.room_name}
                        {$booking.room_name|escape:html}
                    {else}
                        <span class="muted">-</span>
                    {/if}
                    {if $booking.board_code}
                        <br><small class="muted">{$booking.board_code|escape:html}</small>
                    {/if}
                </td>

                {* Dates *}
                <td>
                    {if $booking.check_in_short}
                        {$booking.check_in_short}
                        {if $booking.check_out_short}<br><small class="muted">→ {$booking.check_out_short}</small>{/if}
                    {/if}
                </td>

                {* Nights *}
                <td>{$booking.nights|default:'-'}</td>

                {* Guests *}
                <td>
                    {$booking.adults|default:0} A{if $booking.children > 0} + {$booking.children} C{/if}
                </td>

                {* Price *}
                <td>
                    {if $booking.total_price > 0}
                        <strong>{$booking.total_price|number_format:2} {$booking.currency|default:'EUR'}</strong>
                    {else}
                        <span class="muted">-</span>
                    {/if}
                </td>

                {* Provider Reference — the provider's own API reference/confirmation
                   number. When the booking is still pending there is none yet, so
                   show "-". Do NOT fall back to provider_booking_id: that is an
                   internal per-provider auto-increment, so it rendered an identical,
                   meaningless "#1" for every provider's first booking. *}
                <td>
                    {if $booking.provider_display.provider_ref}
                        <small>{$booking.provider_display.provider_ref|escape:html}</small>
                    {else}
                        <span class="muted">-</span>
                    {/if}
                </td>

                {* Status *}
                <td>
                    {* Use provider-specific status label if available (already HTML-safe from provider) *}
                    {if $booking.provider_display.status_label}
                        {$booking.provider_display.status_label nofilter}
                    {elseif $booking.status == 'confirmed'}
                        <span class="label label-success">Confirmed</span>
                    {elseif $booking.status == 'pending'}
                        <span class="label label-warning">Pending</span>
                    {elseif $booking.status == 'cancelled'}
                        <span class="label label-danger">Cancelled</span>
                    {elseif $booking.status == 'failed'}
                        <span class="label label-danger">Failed</span>
                    {else}
                        <span class="label">{$booking.status|escape:html}</span>
                    {/if}
                </td>

                {* Tools *}
                <td>
                    <div class="btn-group">
                        <a href="{"travel_bookings.view?booking_id=`$booking.booking_id`"|fn_url}" class="btn btn-xs btn-default" title="View">
                            <i class="icon-eye-open"></i>
                        </a>
                        {* Provider-specific action buttons *}
                        {if $booking.provider_actions}
                            {foreach from=$booking.provider_actions item=action}
                                {if $action.method == 'POST'}
                                <form action="{$action.url|fn_url}" method="post" style="display: inline;">
                                    <input type="hidden" name="security_hash" value="{$security_hash}">
                                    {* provider_action routing contract: the unified controller's
                                       provider_action mode needs provider + provider_action to
                                       dispatch into BookingAdminProvider::handleAction(). Without
                                       these the POST was a silent no-op. *}
                                    <input type="hidden" name="provider" value="{$booking.provider|escape:html}">
                                    <input type="hidden" name="provider_action" value="{$action.extra_params.provider_action|default:$action.name|escape:html}">
                                    {if $action.extra_params}
                                        {foreach from=$action.extra_params key="_ap_key" item="_ap_val"}
                                            {if $_ap_key !== 'provider_action'}
                                            <input type="hidden" name="{$_ap_key|escape:html}" value="{$_ap_val|escape:html}">
                                            {/if}
                                        {/foreach}
                                    {/if}
                                    {if $action.booking_id}
                                    <input type="hidden" name="booking_id" value="{$action.booking_id}" />
                                    {/if}
                                    <button type="submit" class="btn btn-xs {$action.css_class|default:'btn-default'}" title="{$action.label|escape:html}">
                                        <i class="{$action.icon|default:'icon-cog'}"></i>
                                    </button>
                                </form>
                                {else}
                                <a href="{$action.url|fn_url}" class="btn btn-xs {$action.css_class|default:'btn-default'}" title="{$action.label|escape:html}">
                                    <i class="{$action.icon|default:'icon-cog'}"></i>
                                </a>
                                {/if}
                            {/foreach}
                        {/if}
                        {* Link to provider-specific view *}
                        {if $booking.provider_view_url}
                        <a href="{$booking.provider_view_url|fn_url}" class="btn btn-xs btn-default" title="View in {$booking.provider|capitalize}">
                            <i class="icon-share-alt"></i>
                        </a>
                        {/if}
                    </div>
                </td>
            </tr>
            {/foreach}
        </tbody>
    </table>

    {else}
    <p class="no-items">{__("no_data")}</p>
    {/if}

    {include file="common/pagination.tpl"}
</div>

{/capture}

{capture name="buttons"}{/capture}

{include file="common/mainbox.tpl"
    title=__('travel_core.manage_bookings')
    content=$smarty.capture.mainbox
    buttons=$smarty.capture.buttons
}
