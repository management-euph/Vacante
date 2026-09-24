{*
 * Novoton Bookings Management Page
 *}

{capture name="mainbox"}

<div class="novoton-bookings-manage">
    {* Search/Filter Form *}
    <form action="{""|fn_url}" method="get" class="form-horizontal form-inline search-form">
        <input type="hidden" name="dispatch" value="novoton_bookings.manage" />

        <div class="control-group">
            <label class="control-label" for="search_order_id">{__("order_id")}:</label>
            <div class="controls">
                <input type="text" name="order_id" id="search_order_id" value="{$search.order_id}" size="10" />
            </div>
        </div>

        <div class="control-group">
            <label class="control-label" for="search_novoton_status">{__("novoton_holidays.novoton_status")}:</label>
            <div class="controls">
                <select name="novoton_status" id="search_novoton_status">
                    <option value="">{__("all")}</option>
                    <option value="Good" {if $search.novoton_status == 'Good'}selected{/if}>Good - Confirmed</option>
                    <option value="ASK" {if $search.novoton_status == 'ASK'}selected{/if}>ASK - Pending</option>
                    <option value="ST" {if $search.novoton_status == 'ST'}selected{/if}>ST - Cancelled</option>
                    <option value="WT" {if $search.novoton_status == 'WT'}selected{/if}>WT - Waiting</option>
                    <option value="RQ" {if $search.novoton_status == 'RQ'}selected{/if}>RQ - Alternatives</option>
                </select>
            </div>
        </div>

        <div class="control-group">
            <label class="control-label">{__("novoton_holidays.check_in")}:</label>
            <div class="controls">
                {include file="common/calendar.tpl" date_id="check_in_from" date_name="check_in_from" date_val=$search.check_in_from extra="placeholder='From'"}
                {include file="common/calendar.tpl" date_id="check_in_to" date_name="check_in_to" date_val=$search.check_in_to extra="placeholder='To'"}
            </div>
        </div>

        <div class="control-group">
            <label class="control-label" for="search_sort_by">{__("sort_by")}:</label>
            <div class="controls">
                <select name="sort_by" id="search_sort_by">
                    <option value="order_id" {if $search.sort_by == 'order_id'}selected{/if}>{__("order_id")}</option>
                    <option value="check_in" {if $search.sort_by == 'check_in'}selected{/if}>{__("novoton_holidays.check_in")}</option>
                    <option value="created_at" {if $search.sort_by == 'created_at'}selected{/if}>{__("date")}</option>
                </select>
                <select name="sort_order">
                    <option value="desc" {if $search.sort_order == 'desc'}selected{/if}>v {__("descending")}</option>
                    <option value="asc" {if $search.sort_order == 'asc'}selected{/if}>^ {__("ascending")}</option>
                </select>
            </div>
        </div>

        <button type="submit" class="btn btn-primary">{__("search")}</button>
    </form>

    {* Check All ASK Status Button - POST form *}
    <form action="{"novoton_bookings.check_all_status"|fn_url}" method="post" class="form-inline" style="display: inline-block; margin-bottom: 20px;">
        <input type="hidden" name="security_hash" value="{$security_hash}">
        <button type="submit" class="btn btn-default">
            <i class="icon-refresh"></i> {__("novoton_holidays.check_all_status")}
        </button>
    </form>

    {* Cleanup Orphans Button *}
    <form action="{"novoton_bookings.cleanup_orphans"|fn_url}" method="post" class="form-inline" style="display: inline-block; margin-left: 10px; margin-bottom: 20px;">
        <input type="hidden" name="security_hash" value="{$security_hash}">
        <button type="submit" class="btn btn-warning" onclick="return confirm('{__("novoton_holidays.cleanup_confirm")|escape:"javascript"}');">
            <i class="icon-trash"></i> {__("novoton_holidays.cleanup_orphan_bookings")}
        </button>
    </form>

    {* Show orphans toggle *}
    <form action="{"novoton_bookings.manage"|fn_url}" method="get" class="form-inline" style="display: inline-block; margin-left: 20px;">
        <label class="hand">
            <input type="checkbox" class="checkbox" name="show_orphans" value="1" {if $search.show_orphans}checked{/if} onchange="this.form.submit()">
            {__("novoton_holidays.show_incomplete_bookings")}
        </label>
    </form>

    {* Bookings Table *}
    {include file="common/pagination.tpl" save_current_url=true}

    {if $bookings}
    <table class="table table-striped table-hover">
        <thead>
            <tr>
                <th width="80">
                    <a href="{"novoton_bookings.manage?sort_by=order_id&sort_order=`$sort_order_toggle`"|fn_url}">
                        {__("order_id")}
                        {if $search.sort_by == 'order_id'}
                            {if $search.sort_order == 'asc'}^{else}v{/if}
                        {/if}
                    </a>
                </th>
                <th>{__("hotel")} / {__("novoton_holidays.room_type")}</th>
                <th>
                    <a href="{"novoton_bookings.manage?sort_by=check_in&sort_order=`$sort_order_toggle`"|fn_url}">
                        {__("novoton_holidays.check_in")}
                        {if $search.sort_by == 'check_in'}
                            {if $search.sort_order == 'asc'}^{else}v{/if}
                        {/if}
                    </a>
                </th>
                <th>{__("novoton_holidays.nights")}</th>
                <th>{__("novoton_holidays.guests")}</th>
                <th>{__("price")}</th>
                <th width="80">{__("status")}</th>
                <th width="80">{__("tools")}</th>
            </tr>
        </thead>
        <tbody>
            {foreach from=$bookings item=booking}
            <tr>
                <td>
                    {if $booking.order_id > 0}
                    <a href="{"orders.details?order_id=`$booking.order_id`"|fn_url}">{$booking.order_id}</a>
                    {else}
                    <span class="muted">-</span>
                    {/if}
                </td>
                <td>
                    {if $booking.hotel_name && $booking.hotel_name != 'N/A'}
                        <strong>{$booking.hotel_name}</strong>
                    {else}
                        <strong>Hotel #{$booking.hotel_id}</strong>
                    {/if}
                    {if $booking.hotel_city}
                        <br><small class="muted">{$booking.hotel_city}{if $booking.hotel_region}, {$booking.hotel_region}{/if}</small>
                    {/if}
                    {if $booking.total_rooms > 1}
                        <br><small class="multi-room-badge">Room {$booking.room_number} of {$booking.total_rooms}</small>
                    {/if}
                    <br><small style="color: #666;">
                    {if $booking.room_types_list}
                        {$room_types = $booking.room_types_list|replace:'%2b':'+'|replace:'%2B':'+'}
                        {$room_types_array = ", "|explode:$room_types}
                        {foreach from=$room_types_array item=room_type name=rtloop}
                            {$room_type|trim}{if !$smarty.foreach.rtloop.last}, {/if}
                        {/foreach}
                    {elseif $booking.room_id}
                        {$booking.room_id|replace:'%2b':'+'|replace:'%2B':'+'}
                    {/if}
                    {if $booking.board_display|default:$booking.board_id} &middot; {$booking.board_display|default:$booking.board_id}{/if}
                    </small>
                </td>
                <td>
                    {$booking.check_in|date_format:"%d.%m.%Y"}<br>
                    <small>-> {$booking.check_out|date_format:"%d.%m.%Y"}</small>
                </td>
                <td>{$booking.nights}</td>
                <td>
                    {$booking.adults} A
                    {if $booking.children > 0}+ {$booking.children} C{/if}
                    {if $booking.guests_by_room}
                        {foreach from=$booking.guests_by_room item=room_guests key=room_num}
                            <br><small class="muted"><strong>R{$room_num}:</strong> {", "|implode:$room_guests|truncate:40}</small>
                        {/foreach}
                    {elseif $booking.guest_name}
                        <br><small class="muted" title="{$booking.guest_name}">{$booking.guest_name|truncate:30}</small>
                    {/if}
                </td>
                <td>
                    {if $booking.total_price > 0}
                        <strong title="{if $booking.base_price > 0}API: {$booking.base_price|number_format:2} {$smarty.const.CART_PRIMARY_CURRENCY}{/if}">{$booking.total_price|number_format:2} {$booking.currency|default:$smarty.const.CART_PRIMARY_CURRENCY}</strong>
                        {if $booking.novoton_invoice_id}<br><small class="muted">NT {$booking.novoton_invoice_id}</small>{/if}
                    {else}
                        <span class="muted" title="Price not recorded">N/A</span>
                    {/if}
                </td>
                <td>
                    {if $booking.novoton_status == 'Good'}
                        <span class="label label-success">Good</span>
                    {elseif $booking.novoton_status == 'ASK'}
                        <span class="label label-warning">ASK</span>
                    {elseif $booking.novoton_status == 'ST'}
                        <span class="label label-danger">ST</span>
                    {elseif $booking.novoton_status == 'WT'}
                        <span class="label label-info">WT</span>
                    {elseif $booking.novoton_status == 'RQ'}
                        <span class="label label-primary">RQ</span>
                    {else}
                        <span class="label">{$booking.status}</span>
                    {/if}
                </td>
                <td>
                    <div class="btn-group">
                        <a href="{"novoton_bookings.view?booking_id=`$booking.booking_id`"|fn_url}" class="btn btn-xs btn-default" title="View">
                            <i class="icon-eye-open"></i>
                        </a>
                        {if $booking.novoton_status == 'ASK'}
                        <form action="{"novoton_bookings.resinfo"|fn_url}" method="post" style="display: inline;">
                            <input type="hidden" name="security_hash" value="{$security_hash}">
                            <input type="hidden" name="booking_id" value="{$booking.booking_id}" />
                            <button type="submit" class="btn btn-xs btn-default" title="Check Status">
                                <i class="icon-refresh"></i>
                            </button>
                        </form>
                        {/if}
                        {if $booking.novoton_status == 'ST' || $booking.novoton_status == 'RQ'}
                        <a href="{"novoton_bookings.alternatives?booking_id=`$booking.booking_id`"|fn_url}" class="btn btn-xs btn-primary" title="Alternatives">
                            <i class="icon-list"></i>
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
    title="{__('novoton_holidays.manage_bookings')}"
    content=$smarty.capture.mainbox
    buttons=$smarty.capture.buttons
}
