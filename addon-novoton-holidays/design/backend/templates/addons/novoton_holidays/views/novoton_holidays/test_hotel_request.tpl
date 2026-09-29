{* Test hotel_request API *}

{* capture name="mainbox" - DISABLED *}

<div class="well">
    <h4><i class="icon-beaker"></i> Test hotel_request API</h4>
    <p class="muted">Generate and optionally send hotel_request to Novoton API. Use this to test API connectivity and XML format.</p>
</div>

<form method="get" class="form-horizontal">
    <input type="hidden" name="dispatch" value="novoton_holidays.test_hotel_request">
    
    <div class="control-group">
        <label class="control-label">Hotel ID:</label>
        <div class="controls">
            <input type="text" name="hotel_id" value="{$hotel_id}" placeholder="e.g., 657" class="input-medium" required>
            <span class="help-inline">Novoton Hotel ID (find in Sync page or product settings)</span>
        </div>
    </div>
    
    <div class="control-group">
        <label class="control-label">Package Name:</label>
        <div class="controls">
            <input type="text" name="package_name" value="{$package_name}" placeholder="e.g., IGLIKA PALACE ****" class="input-xlarge">
            <span class="help-inline">Optional - hotel name for reference</span>
        </div>
    </div>
    
    <div class="control-group">
        <label class="control-label">Check-in:</label>
        <div class="controls">
            <input type="date" name="check_in" value="{$check_in}" class="input-medium" required>
        </div>
    </div>
    
    <div class="control-group">
        <label class="control-label">Check-out:</label>
        <div class="controls">
            <input type="date" name="check_out" value="{$check_out}" class="input-medium" required>
        </div>
    </div>
    
    <div class="control-group">
        <label class="control-label">Adults:</label>
        <div class="controls">
            <input type="number" name="adults" value="{$adults}" min="1" max="9" class="input-mini">
        </div>
    </div>
    
    <div class="control-group">
        <label class="control-label">Room ID:</label>
        <div class="controls">
            <input type="text" name="room_id" value="{$room_id}" placeholder="e.g., DBL 2+0 ECONOMY" class="input-large">
            <span class="help-inline">Leave empty for any room</span>
        </div>
    </div>
    
    <div class="control-group">
        <label class="control-label">Board ID:</label>
        <div class="controls">
            <input type="text" name="board_id" value="{$board_id}" placeholder="e.g., HB, BB, AI" class="input-medium">
            <span class="help-inline">Leave empty for any board</span>
        </div>
    </div>
    
    <div class="control-group">
        <label class="control-label">Holder:</label>
        <div class="controls">
            <input type="text" name="holder" value="{$holder|default:'Test Request - Do Not Process'}" class="input-xlarge">
        </div>
    </div>
    
    <div class="control-group">
        <div class="controls">
            <button type="submit" class="btn btn-primary">
                <i class="icon-eye-open"></i> Generate XML Preview
            </button>
            <button type="submit" name="send" value="1" class="btn btn-danger" onclick="return confirm('This will SEND the request to Novoton API. Are you sure?');">
                <i class="icon-cloud-upload"></i> Send to API
            </button>
        </div>
    </div>
</form>

{if $xml_preview}
<hr>
<div class="well">
    <h4><i class="icon-code"></i> XML Preview (hotel_request)</h4>
    <pre style="max-height: 500px; overflow: auto; background: #1e1e1e; color: #d4d4d4; padding: 15px; border-radius: 4px; font-size: 12px; font-family: 'Courier New', monospace; white-space: pre-wrap;">{$xml_preview|escape:'html'}</pre>
</div>
{/if}

{if $id_num}
<div class="alert alert-success">
    <h4><i class="icon-ok"></i> Request Sent Successfully!</h4>
    <p><strong>IdNum received:</strong> <span style="font-size: 18px; font-weight: bold;">{$id_num}</span></p>
    <p>Use this IdNum to check for alternatives (typically available after 24-48 hours):</p>
    <a href="{"novoton_holidays.test_alternative_rs&id_num=`$id_num`"|fn_url}" class="btn btn-success">
        <i class="icon-search"></i> Check Alternatives for IdNum {$id_num}
    </a>
</div>
{/if}

{if $api_response}
<div class="well">
    <h4><i class="icon-download-alt"></i> API Response</h4>
    <pre style="max-height: 300px; overflow: auto; background: #f8f9fa; padding: 15px; border-radius: 4px; font-size: 11px; font-family: 'Courier New', monospace; white-space: pre-wrap;">{$api_response|escape:'html'}</pre>
</div>
{/if}

<hr>
<div class="well">
    <h4>Related Tools</h4>
    <a href="{fn_url('novoton_holidays.test_alternative_rs')}" class="btn">
        <i class="icon-search"></i> Test alternative_RS
    </a>
    <a href="{fn_url('novoton_alternatives.manage')}" class="btn">
        <i class="icon-list"></i> View Alternative Requests
    </a>
    <a href="{fn_url('novoton_diagnostic.test')}" class="btn">
        <i class="icon-wrench"></i> API Diagnostic
    </a>
    <a href="{fn_url('novoton_holidays.manage')}" class="btn">
        <i class="icon-home"></i> Dashboard
    </a>
</div>

{* /capture - DISABLED *}

{* include file="common/mainbox.tpl" title="Test hotel_request API" content=$smarty.capture.mainbox - DISABLED *}
