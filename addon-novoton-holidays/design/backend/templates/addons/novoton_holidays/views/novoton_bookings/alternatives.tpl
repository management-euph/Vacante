{*
 * Novoton Booking Alternatives View
 * Shows alternative hotels when booking is cancelled/rejected
 *}

{* capture name="mainbox" - DISABLED *}

{if $booking}
<div class="novoton-alternatives-view">
    
    {* Original Booking Info *}
    <div style="background: #dc3545; color: #fff; padding: 15px 20px; border-radius: 4px 4px 0 0;">
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <div>
                <h4 class="no-margin">Original Booking (Cancelled/Rejected)</h4>
                <p style="margin: 5px 0 0; opacity: 0.9;">
                    {$booking.hotel_name} | {$booking.room_id|replace:'%2b':'+'} | {$booking.board_id}
                </p>
            </div>
            <div>
                <span class="label label-danger" style="font-size: 14px;">{$booking.novoton_status}</span>
            </div>
        </div>
    </div>
    
    <div style="border: 1px solid #ddd; border-top: none; padding: 15px; background: #f9f9f9; margin-bottom: 20px;">
        <div class="row-fluid">
            <div class="span3">
                <strong>Check-in:</strong><br>{$booking.check_in|date_format:"%d.%m.%Y"}
            </div>
            <div class="span3">
                <strong>Check-out:</strong><br>{$booking.check_out|date_format:"%d.%m.%Y"}
            </div>
            <div class="span3">
                <strong>Nights:</strong><br>{$booking.nights}
            </div>
            <div class="span3">
                <strong>Original Price:</strong><br>{$booking.total_price|number_format:2} {$smarty.const.CART_PRIMARY_CURRENCY}
            </div>
        </div>
    </div>
    
    {* Request Alternatives Button *}
    <div style="margin-bottom: 20px;">
        <form action="{"novoton_bookings.request_alternatives"|fn_url}" method="post" style="display: inline;">
            <input type="hidden" name="security_hash" value="{$security_hash}">
            <input type="hidden" name="booking_id" value="{$booking.booking_id}" />
            <input type="hidden" name="return_url" value="{$config.current_url}" />
            <button type="submit" class="btn btn-primary">
                <i class="icon-refresh"></i> Refresh Alternatives from API
            </button>
        </form>
        <a href="{"novoton_bookings.view?booking_id=`$booking.booking_id`"|fn_url}" class="btn">
            View Booking Details
        </a>
        <a href="{"orders.details?order_id=`$booking.order_id`"|fn_url}" class="btn">
            View Order
        </a>
    </div>
    
    {* Alternatives List *}
    {if $alternatives && count($alternatives) > 0}
    <div style="background: #003580; color: #fff; padding: 15px 20px; border-radius: 4px 4px 0 0;">
        <h4 class="no-margin"><i class="icon-list-alt"></i> Alternative Hotels ({$alternatives|count})</h4>
    </div>
    
    <table class="table table-striped" style="border: 1px solid #ddd; border-top: none;">
        <thead style="background: #f5f5f5;">
            <tr>
                <th>Hotel / Package</th>
                <th>Room Type</th>
                <th>Board</th>
                <th>Dates</th>
                <th>Availability</th>
                <th>Price</th>
                <th>Match</th>
            </tr>
        </thead>
        <tbody>
            {foreach from=$alternatives item=alt}
            <tr {if $alt.alt_from_req == 'Y' || $alt.alt_from_req == '1'}style="background: #d4edda;"{/if}>
                <td>
                    <strong>{$alt.hotel_name|default:$alt.hotel_id}</strong><br>
                    <small style="color: #666;">{$alt.package_name}</small>
                    {if $alt.hotel_city}<br><small style="color: #999;">{$alt.hotel_city}, {$alt.hotel_country}</small>{/if}
                </td>
                <td>
                    {$alt.room_id|replace:'%2b':'+'}
                </td>
                <td>
                    {$alt.board_id}
                    {if $alt.ext_board}<br><small>+{$alt.ext_board}</small>{/if}
                </td>
                <td>
                    {$alt.check_in|date_format:"%d.%m.%Y"}<br>
                    <small>-> {$alt.check_out|date_format:"%d.%m.%Y"}</small>
                </td>
                <td>
                    {if $alt.quota > 0}
                        <span class="label label-success">{$alt.quota} rooms</span>
                    {elseif $alt.quota == 0}
                        <span class="label label-warning">On request</span>
                    {else}
                        <span class="label label-danger">N/A</span>
                    {/if}
                </td>
                <td>
                    <strong style="font-size: 16px;">{$alt.total|number_format:2} {$smarty.const.CART_PRIMARY_CURRENCY}</strong>
                    {if $alt.total != $booking.total_price}
                        {math equation="((new-old)/old)*100" new=$alt.total old=$booking.total_price assign=diff}
                        <br>
                        {if $diff > 0}
                            <small style="color: #dc3545;">+{$diff|number_format:1}%</small>
                        {else}
                            <small style="color: #28a745;">{$diff|number_format:1}%</small>
                        {/if}
                    {/if}
                </td>
                <td>
                    {if $alt.alt_from_req == 'Y' || $alt.alt_from_req == '1'}
                        <span class="label label-success" title="Same as original request">[Good] Exact Match</span>
                    {else}
                        <span class="label label-default">Different</span>
                    {/if}
                </td>
            </tr>
            {/foreach}
        </tbody>
    </table>
    
    <div style="padding: 15px; background: #f9f9f9; border: 1px solid #ddd; border-top: none; border-radius: 0 0 4px 4px;">
        <p class="no-margin" style="color: #666;">
            <i class="icon-info-sign"></i> 
            <strong>Note:</strong> Green highlighted rows are exact matches to the original request.
            Contact the customer to confirm an alternative before rebooking.
        </p>
    </div>
    
    {else}
    <div class="alert alert-info">
        <i class="icon-info-sign"></i> 
        <strong>No alternatives available yet.</strong><br>
        Click "Refresh Alternatives from API" to request available alternatives from Novoton.
    </div>
    {/if}
    
    {* Back Button *}
    <div style="margin-top: 20px;">
        <a href="{"novoton_bookings.manage"|fn_url}" class="btn">&larr; Back to Bookings</a>
    </div>
</div>
{else}
<p class="no-items">Booking not found</p>
{/if}

{* /capture - DISABLED *}

{* include file="common/mainbox.tpl" title="Alternative Hotels" content=$smarty.capture.mainbox - DISABLED *}
