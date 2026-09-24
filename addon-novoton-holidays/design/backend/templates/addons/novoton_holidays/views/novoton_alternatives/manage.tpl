{** Novoton Alternatives - Manage Requests **}

{capture name="mainbox"}

<div class="items-container">
    
    {* Toolbar *}
    <div class="clearfix" style="margin-bottom: 15px;">
        <div class="pull-left">
            <form method="get" class="form-inline">
                <input type="hidden" name="dispatch" value="novoton_alternatives.manage">
                <select name="status" class="input-medium" onchange="this.form.submit()">
                    <option value="">{__("all_statuses")}</option>
                    <option value="pending" {if $status_filter == 'pending'}selected{/if}>Pending ({$status_counts.pending|default:0})</option>
                    <option value="alternatives_found" {if $status_filter == 'alternatives_found'}selected{/if}>Alternatives Found ({$status_counts.alternatives_found|default:0})</option>
                    <option value="notified" {if $status_filter == 'notified'}selected{/if}>Notified ({$status_counts.notified|default:0})</option>
                    <option value="expired" {if $status_filter == 'expired'}selected{/if}>Expired ({$status_counts.expired|default:0})</option>
                </select>
                <input type="text" name="email" value="{$search_email|escape:'html'}" placeholder="Search by email..." class="input-medium">
                <button type="submit" class="btn">{__("search")}</button>
            </form>
        </div>
        <div class="pull-right">
            <form method="post" action="{fn_url('')}" class="form-inline">
                <input type="hidden" name="security_hash" value="{$security_hash}">
                <input type="hidden" name="dispatch" value="novoton_alternatives.check_all_pending">
                <button type="submit" class="btn btn-primary">
                    <i class="icon-refresh"></i> Check All Pending
                </button>
            </form>
        </div>
    </div>
    
    {* Opens the pagination container; the include after the table draws the controls. *}
    {include file="common/pagination.tpl" save_current_url=true}

    {if $requests}
    <table class="table table-middle">
        <thead>
            <tr>
                <th width="5%">ID</th>
                <th width="20%">Hotel</th>
                <th width="15%">Dates</th>
                <th width="15%">Contact</th>
                <th width="10%">Status</th>
                <th width="10%">Alternatives</th>
                <th width="10%">Created</th>
                <th width="15%">Actions</th>
            </tr>
        </thead>
        <tbody>
            {foreach from=$requests item=request}
            <tr>
                <td>#{$request.request_id}</td>
                <td>
                    <strong>{$request.hotel_name|default:$request.hotel_id|escape:'html'}</strong>
                    <br><small class="muted">{$request.adults} adults{if $request.children > 0}, {$request.children} children{/if}, {$request.num_rooms} room(s)</small>
                </td>
                <td>
                    {$request.check_in|date_format:"%d %b %Y"}<br>
                    <small class="muted">{$request.nights} nights</small>
                </td>
                <td>
                    <a href="mailto:{$request.contact_email|escape:'url'}">{$request.contact_email|escape:'html'}</a>
                    {if $request.contact_phone}<br><small>{$request.contact_phone|escape:'html'}</small>{/if}
                </td>
                <td>
                    {if $request.status == 'pending'}
                        <span class="label label-warning">Pending</span>
                    {elseif $request.status == 'alternatives_found'}
                        <span class="label label-success">Found</span>
                    {elseif $request.status == 'notified'}
                        <span class="label label-info">Notified</span>
                    {elseif $request.status == 'expired'}
                        <span class="label">Expired</span>
                    {else}
                        <span class="label">{$request.status}</span>
                    {/if}
                </td>
                <td>
                    {if $request.alternatives}
                        <strong class="text-success">{$request.alternatives|count}</strong> found
                    {else}
                        <span class="muted">-</span>
                    {/if}
                </td>
                <td>
                    <small>{$request.created_at|date_format:"%d %b %Y"}<br>{$request.created_at|date_format:"%H:%M"}</small>
                </td>
                <td>
                    <div class="btn-group">
                        <a href="{"novoton_alternatives.view&request_id=`$request.request_id`"|fn_url}" class="btn btn-small" title="View Details">
                            <i class="icon-eye-open"></i>
                        </a>
                        
                        {if $request.novoton_request_id && $request.status == 'pending'}
                        <form method="post" action="{fn_url('')}" style="display: inline;">
                            <input type="hidden" name="security_hash" value="{$security_hash}">
                            <input type="hidden" name="dispatch" value="novoton_alternatives.check_alternatives">
                            <input type="hidden" name="request_id" value="{$request.request_id}">
                            <button type="submit" class="btn btn-small" title="Check Alternatives">
                                <i class="icon-search"></i>
                            </button>
                        </form>
                        {/if}
                        
                        {if $request.status == 'alternatives_found'}
                        <form method="post" action="{fn_url('')}" style="display: inline;">
                            <input type="hidden" name="security_hash" value="{$security_hash}">
                            <input type="hidden" name="dispatch" value="novoton_alternatives.notify_customer">
                            <input type="hidden" name="request_id" value="{$request.request_id}">
                            <button type="submit" class="btn btn-small btn-primary" title="Notify Customer">
                                <i class="icon-envelope"></i>
                            </button>
                        </form>
                        {/if}
                        
                        <form method="post" action="{fn_url('')}" style="display: inline;" onsubmit="return confirm('Delete this request?');">
                            <input type="hidden" name="security_hash" value="{$security_hash}">
                            <input type="hidden" name="dispatch" value="novoton_alternatives.delete">
                            <input type="hidden" name="request_id" value="{$request.request_id}">
                            <button type="submit" class="btn btn-small btn-danger" title="Delete">
                                <i class="icon-trash"></i>
                            </button>
                        </form>
                    </div>
                </td>
            </tr>
            {/foreach}
        </tbody>
    </table>

    {else}
    <p class="no-items">{__("no_data")}</p>
    {/if}

    {include file="common/pagination.tpl" save_current_page=true}
    
</div>

{/capture}

{capture name="sidebar"}
<div class="sidebar-row">
    <h6>{__("novoton_holidays.alternatives_info")}</h6>
    <p class="muted" style="font-size: 12px;">
        <strong>hotel_request:</strong> Request alternatives when no prices available<br>
        <strong>alternative_RS:</strong> Check for available alternatives
    </p>
    
    <h6 style="margin-top: 15px;">{__("statistics")}</h6>
    <ul class="unstyled" style="font-size: 12px;">
        <li><span class="label label-warning">Pending</span> {$status_counts.pending|default:0}</li>
        <li><span class="label label-success">Found</span> {$status_counts.alternatives_found|default:0}</li>
        <li><span class="label label-info">Notified</span> {$status_counts.notified|default:0}</li>
    </ul>
</div>
{/capture}

{capture name="buttons"}{/capture}

{include file="common/mainbox.tpl" 
    title="{__('novoton_holidays.alternative_requests')}" 
    content=$smarty.capture.mainbox
    buttons=$smarty.capture.buttons 
    sidebar=$smarty.capture.sidebar
}
