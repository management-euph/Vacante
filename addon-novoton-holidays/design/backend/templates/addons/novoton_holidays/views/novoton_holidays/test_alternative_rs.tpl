{* Test alternative_RS API *}

{* capture name="mainbox" - DISABLED *}

<div class="well">
    <h4><i class="icon-search"></i> Test alternative_RS API</h4>
    <p class="muted">Check for available alternatives for a previously submitted hotel_request. Enter the IdNum received from hotel_request response.</p>
</div>

<form method="get" class="form-inline" style="margin-bottom: 20px;">
    <input type="hidden" name="dispatch" value="novoton_holidays.test_alternative_rs">
    
    <label>IdNum:</label>
    <input type="text" name="id_num" value="{$id_num}" placeholder="e.g., 94439" class="input-medium" required>
    
    <button type="submit" class="btn btn-primary">
        <i class="icon-search"></i> Check Alternatives
    </button>
</form>

{if $recent_requests}
<div class="well">
    <h5>Recent Alternative Requests (for reference)</h5>
    <table class="table table-condensed table-striped" style="font-size: 12px;">
        <thead>
            <tr>
                <th>IdNum</th>
                <th>Hotel</th>
                <th>Check-in</th>
                <th>Status</th>
                <th>Created</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            {foreach from=$recent_requests item=req}
            <tr>
                <td><strong>{$req.novoton_request_id}</strong></td>
                <td>{$req.hotel_name|truncate:30}</td>
                <td>{$req.check_in}</td>
                <td>
                    {if $req.status == 'pending'}<span class="label label-warning">Pending</span>
                    {elseif $req.status == 'alternatives_found'}<span class="label label-success">Found</span>
                    {else}<span class="label">{$req.status}</span>{/if}
                </td>
                <td>{$req.created_at|date_format:"%d %b %H:%M"}</td>
                <td>
                    <a href="{"novoton_holidays.test_alternative_rs&id_num=`$req.novoton_request_id`"|fn_url}" class="btn btn-small">Check</a>
                </td>
            </tr>
            {/foreach}
        </tbody>
    </table>
</div>
{/if}

{if $id_num && $alternatives}
<div class="alert alert-success">
    <h4><i class="icon-ok"></i> {$alternatives|count} Alternatives Found!</h4>
</div>

<table class="table table-striped">
    <thead>
        <tr>
            <th>ResNum</th>
            <th>Hotel</th>
            <th>Room</th>
            <th>Board</th>
            <th>Dates</th>
            <th>Quota</th>
            <th>Price</th>
            <th>Match</th>
        </tr>
    </thead>
    <tbody>
        {foreach from=$alternatives item=alt}
        <tr {if $alt.alt_from_req == 'Yes'}style="background: #d4edda;"{/if}>
            <td>{$alt.res_num}</td>
            <td>
                <strong>{$alt.package_name}</strong><br>
                <small class="muted">ID: {$alt.hotel_id}</small>
            </td>
            <td>{$alt.room_id}</td>
            <td>{$alt.board_id}{if $alt.ext_board_id}<br><small>+{$alt.ext_board_id}</small>{/if}</td>
            <td>{$alt.check_in}<br><small>-> {$alt.check_out}</small></td>
            <td>
                {if $alt.quota > 0}
                    <span class="label label-success">{$alt.quota}</span>
                {else}
                    <span class="label label-warning">RQ</span>
                {/if}
            </td>
            <td><strong>{$alt.total} {$smarty.const.CART_PRIMARY_CURRENCY}</strong></td>
            <td>
                {if $alt.alt_from_req == 'Yes'}
                    <span class="label label-success">[Good] Exact</span>
                {else}
                    <span class="label">Different</span>
                {/if}
            </td>
        </tr>
        {/foreach}
    </tbody>
</table>
{elseif $id_num && !$alternatives}
<div class="alert alert-warning">
    <h4><i class="icon-time"></i> No Alternatives Available Yet</h4>
    <p>Alternatives are typically available 24-48 hours after the hotel_request is submitted. Please check again later.</p>
</div>
{/if}

{if $api_response}
<div class="well">
    <h4><i class="icon-download-alt"></i> Raw API Response (alternative_list)</h4>
    <pre style="max-height: 400px; overflow: auto; background: #1e1e1e; color: #d4d4d4; padding: 15px; border-radius: 4px; font-size: 11px; font-family: 'Courier New', monospace; white-space: pre-wrap;">{$api_response|escape:'html'}</pre>
</div>
{/if}

<hr>
<div class="well">
    <h4>Related Tools</h4>
    <a href="{fn_url('novoton_holidays.test_hotel_request')}" class="btn">
        <i class="icon-cloud-upload"></i> Test hotel_request
    </a>
    <a href="{fn_url('novoton_alternatives.manage')}" class="btn">
        <i class="icon-list"></i> View Alternative Requests
    </a>
</div>

{* /capture - DISABLED *}

{* include file="common/mainbox.tpl" title="Test alternative_RS API" content=$smarty.capture.mainbox - DISABLED *}
