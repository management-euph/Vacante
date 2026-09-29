{* NETOPIA Payments processor configuration template *}

{literal}
<style>
.netopia-fieldset-legend { border-bottom: 1px solid #ddd; padding-bottom: 5px; margin-bottom: 15px; }
.netopia-description-mb { margin-bottom: 15px; }
.netopia-status-group-title { margin-top: 15px; margin-bottom: 10px; }
.netopia-status-group-title:first-of-type { margin-top: 0; }
.netopia-status-success { color: #468847; }
.netopia-status-pending { color: #c09853; }
.netopia-status-cancel  { color: #b94a48; }
.netopia-status-refund  { color: #3a87ad; }
.netopia-status-fail    { color: #b94a48; }
.netopia-upload-row { margin-bottom: 8px; }
.netopia-upload-btn { cursor: pointer; }
.netopia-file-input { margin-top: 4px; }
.netopia-current-file { margin-bottom: 8px; }
.netopia-delete-label { display: inline; cursor: pointer; }
.netopia-paste-label { margin-bottom: 4px; }
</style>
<script>
(function() {
    var form = document.querySelector('form[name="payments_form"], form.cm-ajax-content-input');
    if (!form) {
        form = document.getElementById('netopia_mode');
        if (form) form = form.closest('form');
    }
    if (form) {
        form.setAttribute('enctype', 'multipart/form-data');
        form.encoding = 'multipart/form-data';
    }
})();
</script>
{/literal}

{* Mode *}
<div class="control-group">
    <label class="control-label" for="netopia_mode">{__("netopia_mode")}:</label>
    <div class="controls">
        <select name="payment_data[processor_params][mode]" id="netopia_mode">
            <option value="sandbox" {if $processor_params.mode == "sandbox"}selected="selected"{/if}>{__("netopia_sandbox")}</option>
            <option value="live" {if $processor_params.mode == "live"}selected="selected"{/if}>{__("netopia_live")}</option>
        </select>
    </div>
</div>

{* POS Signature *}
<div class="control-group">
    <label class="control-label" for="netopia_pos_signature">{__("netopia_pos_signature")}:</label>
    <div class="controls">
        <input type="password" name="payment_data[processor_params][pos_signature]" id="netopia_pos_signature" value="{$processor_params.pos_signature|escape:"html"}" size="60" autocomplete="off" placeholder="XXXX-XXXX-XXXX-XXXX-XXXX" />
        <p class="muted description">{__("netopia_pos_signature_description")}</p>
    </div>
</div>

{* API Key *}
<div class="control-group">
    <label class="control-label" for="netopia_api_key">{__("netopia_api_key")}:</label>
    <div class="controls">
        <input type="password" name="payment_data[processor_params][api_key]" id="netopia_api_key" value="{$processor_params.api_key|escape:"html"}" size="60" autocomplete="off" placeholder="ApiKey_XXXXXXXX" />
        <p class="muted description">{__("netopia_api_key_description")}</p>
    </div>
</div>

{* Currency *}
<div class="control-group">
    <label class="control-label" for="netopia_currency">{__("netopia_currency")}:</label>
    <div class="controls">
        <select name="payment_data[processor_params][currency]" id="netopia_currency">
            <option value="order_currency" {if $processor_params.currency == "order_currency"}selected="selected"{/if}>{__("netopia_use_primary_currency")}</option>
            {foreach from=$currencies item="currency"}
                <option value="{$currency.currency_code|escape:"html"}" {if $processor_params.currency == $currency.currency_code}selected="selected"{/if}>{$currency.currency_code|escape:"html"} - {$currency.description|escape:"html"}</option>
            {/foreach}
        </select>
        <p class="muted description">{__("netopia_currency_description")}</p>
    </div>
</div>

{* Installments *}
<div class="control-group">
    <label class="control-label" for="netopia_allow_installments">{__("netopia_allow_installments")}:</label>
    <div class="controls">
        <select name="payment_data[processor_params][allow_installments]" id="netopia_allow_installments">
            <option value="N" {if $processor_params.allow_installments != "Y"}selected="selected"{/if}>{__("no")}</option>
            <option value="Y" {if $processor_params.allow_installments == "Y"}selected="selected"{/if}>{__("yes")}</option>
        </select>
    </div>
</div>

<div class="control-group">
    <label class="control-label" for="netopia_max_installments">{__("netopia_max_installments")}:</label>
    <div class="controls">
        <input type="number" name="payment_data[processor_params][max_installments]" id="netopia_max_installments" value="{$processor_params.max_installments|default:1}" min="1" max="36" size="5" />
        <p class="muted description">{__("netopia_max_installments_description")}</p>
    </div>
</div>

{* Order Description *}
<div class="control-group">
    <label class="control-label" for="netopia_order_description">{__("netopia_order_description")}:</label>
    <div class="controls">
        <input type="text" name="payment_data[processor_params][order_description]" id="netopia_order_description" value="{$processor_params.order_description|default:"comanda #[order_id] din [site_url]"|escape:"html"}" size="60" />
        <p class="muted description">{__("netopia_order_description_help")}</p>
    </div>
</div>

{* Order Status Mapping *}
{$order_statuses = "O"|fn_get_simple_statuses}
{$ntp_statuses = ""|fn_netopia_get_status_definitions}

<fieldset>
    <legend class="netopia-fieldset-legend">{__("netopia_status_mapping_section")}</legend>
    <p class="muted description netopia-description-mb">{__("netopia_status_mapping_description")}</p>

    {* Success *}
    <div class="netopia-status-group-title"><strong class="netopia-status-success">{__("netopia_status_group_success")}</strong></div>
    {foreach from=$ntp_statuses key="ntp_code" item="ntp_info"}
        {if $ntp_info.group == "success"}
            <div class="control-group">
                <label class="control-label">{__("netopia_ntp_status_`$ntp_code`")} <span class="muted">(#{$ntp_code})</span>:</label>
                <div class="controls">
                    {assign var="map_key" value="status_map_`$ntp_code`"}
                    {assign var="map_val" value=$processor_params.$map_key|default:$ntp_info.default}
                    <select name="payment_data[processor_params][status_map_{$ntp_code}]">
                        {foreach from=$order_statuses key="cs_code" item="cs_label"}
                            <option value="{$cs_code}" {if $map_val == $cs_code}selected="selected"{/if}>[{$cs_code}] {$cs_label}</option>
                        {/foreach}
                    </select>
                </div>
            </div>
        {/if}
    {/foreach}

    {* Pending *}
    <div class="netopia-status-group-title"><strong class="netopia-status-pending">{__("netopia_status_group_pending")}</strong></div>
    {foreach from=$ntp_statuses key="ntp_code" item="ntp_info"}
        {if $ntp_info.group == "pending"}
            <div class="control-group">
                <label class="control-label">{__("netopia_ntp_status_`$ntp_code`")} <span class="muted">(#{$ntp_code})</span>:</label>
                <div class="controls">
                    {assign var="map_key" value="status_map_`$ntp_code`"}
                    {assign var="map_val" value=$processor_params.$map_key|default:$ntp_info.default}
                    <select name="payment_data[processor_params][status_map_{$ntp_code}]">
                        {foreach from=$order_statuses key="cs_code" item="cs_label"}
                            <option value="{$cs_code}" {if $map_val == $cs_code}selected="selected"{/if}>[{$cs_code}] {$cs_label}</option>
                        {/foreach}
                    </select>
                </div>
            </div>
        {/if}
    {/foreach}

    {* Cancel *}
    <div class="netopia-status-group-title"><strong class="netopia-status-cancel">{__("netopia_status_group_cancel")}</strong></div>
    {foreach from=$ntp_statuses key="ntp_code" item="ntp_info"}
        {if $ntp_info.group == "cancel"}
            <div class="control-group">
                <label class="control-label">{__("netopia_ntp_status_`$ntp_code`")} <span class="muted">(#{$ntp_code})</span>:</label>
                <div class="controls">
                    {assign var="map_key" value="status_map_`$ntp_code`"}
                    {assign var="map_val" value=$processor_params.$map_key|default:$ntp_info.default}
                    <select name="payment_data[processor_params][status_map_{$ntp_code}]">
                        {foreach from=$order_statuses key="cs_code" item="cs_label"}
                            <option value="{$cs_code}" {if $map_val == $cs_code}selected="selected"{/if}>[{$cs_code}] {$cs_label}</option>
                        {/foreach}
                    </select>
                </div>
            </div>
        {/if}
    {/foreach}

    {* Refund — split into full vs partial. Full refund = cumulative refunded
       reaches the order total; partial = anything below. Different defaults
       (B vs P) so the order list can distinguish them at a glance. *}
    <div class="netopia-status-group-title"><strong class="netopia-status-refund">{__("netopia_status_group_refund")}</strong></div>

    <div class="control-group">
        <label class="control-label">{__("netopia_ntp_status_8_full")} <span class="muted">(#8)</span>:</label>
        <div class="controls">
            {assign var="map_val_full" value=$processor_params.status_map_8_full|default:"B"}
            <select name="payment_data[processor_params][status_map_8_full]">
                {foreach from=$order_statuses key="cs_code" item="cs_label"}
                    <option value="{$cs_code}" {if $map_val_full == $cs_code}selected="selected"{/if}>[{$cs_code}] {$cs_label}</option>
                {/foreach}
            </select>
        </div>
    </div>

    <div class="control-group">
        <label class="control-label">{__("netopia_ntp_status_8_partial")} <span class="muted">(#8)</span>:</label>
        <div class="controls">
            {assign var="map_val_partial" value=$processor_params.status_map_8_partial|default:"P"}
            <select name="payment_data[processor_params][status_map_8_partial]">
                {foreach from=$order_statuses key="cs_code" item="cs_label"}
                    <option value="{$cs_code}" {if $map_val_partial == $cs_code}selected="selected"{/if}>[{$cs_code}] {$cs_label}</option>
                {/foreach}
            </select>
        </div>
    </div>

    {* Fail *}
    <div class="netopia-status-group-title"><strong class="netopia-status-fail">{__("netopia_status_group_fail")}</strong></div>
    {foreach from=$ntp_statuses key="ntp_code" item="ntp_info"}
        {if $ntp_info.group == "fail"}
            <div class="control-group">
                <label class="control-label">{__("netopia_ntp_status_`$ntp_code`")} <span class="muted">(#{$ntp_code})</span>:</label>
                <div class="controls">
                    {assign var="map_key" value="status_map_`$ntp_code`"}
                    {assign var="map_val" value=$processor_params.$map_key|default:$ntp_info.default}
                    <select name="payment_data[processor_params][status_map_{$ntp_code}]">
                        {foreach from=$order_statuses key="cs_code" item="cs_label"}
                            <option value="{$cs_code}" {if $map_val == $cs_code}selected="selected"{/if}>[{$cs_code}] {$cs_label}</option>
                        {/foreach}
                    </select>
                </div>
            </div>
        {/if}
    {/foreach}
</fieldset>

{* Sandbox Certificates *}
<fieldset>
    <legend class="netopia-fieldset-legend">{__("netopia_sandbox_keys_section")}</legend>

    <div class="control-group">
        <label class="control-label">{__("netopia_sandbox_public_key")}:</label>
        <div class="controls">
            <div class="netopia-upload-row">
                <label for="netopia_sandbox_public_key_file" class="btn netopia-upload-btn"><i class="icon-upload"></i> {__("netopia_upload_key_file")}</label>
                <input type="file" name="netopia_sandbox_public_key_file" id="netopia_sandbox_public_key_file" accept=".pem,.key,.cer,.crt,.pub,.txt" class="netopia-file-input" />
            </div>
            {if $processor_params.sandbox_public_key_file}
                <div class="well well-small netopia-current-file">
                    <i class="icon-file"></i> {__("netopia_current_file")}: <strong>{$processor_params.sandbox_public_key_file|escape:"html"}</strong>
                    <label class="netopia-delete-label"><input type="checkbox" name="delete_netopia_sandbox_public_key" value="1" /> {__("netopia_delete_key_file")}</label>
                </div>
            {/if}
            <p class="muted netopia-paste-label">{__("netopia_or_paste_key")}:</p>
            <textarea name="payment_data[processor_params][sandbox_public_key]" id="netopia_sandbox_public_key" cols="65" rows="4">{$processor_params.sandbox_public_key|escape:"html"}</textarea>
            <p class="muted description">{__("netopia_sandbox_public_key_description")}</p>
        </div>
    </div>

    <div class="control-group">
        <label class="control-label">{__("netopia_sandbox_private_key")}:</label>
        <div class="controls">
            <div class="netopia-upload-row">
                <label for="netopia_sandbox_private_key_file" class="btn netopia-upload-btn"><i class="icon-upload"></i> {__("netopia_upload_key_file")}</label>
                <input type="file" name="netopia_sandbox_private_key_file" id="netopia_sandbox_private_key_file" accept=".pem,.key,.cer,.crt,.pub,.txt" class="netopia-file-input" />
            </div>
            {if $processor_params.sandbox_private_key_file}
                <div class="well well-small netopia-current-file">
                    <i class="icon-file"></i> {__("netopia_current_file")}: <strong>{$processor_params.sandbox_private_key_file|escape:"html"}</strong>
                    <label class="netopia-delete-label"><input type="checkbox" name="delete_netopia_sandbox_private_key" value="1" /> {__("netopia_delete_key_file")}</label>
                </div>
            {/if}
            <p class="muted netopia-paste-label">{__("netopia_or_paste_key")}:</p>
            <textarea name="payment_data[processor_params][sandbox_private_key]" id="netopia_sandbox_private_key" cols="65" rows="4">{$processor_params.sandbox_private_key|escape:"html"}</textarea>
            <p class="muted description">{__("netopia_sandbox_private_key_description")}</p>
        </div>
    </div>
</fieldset>

{* Live Certificates *}
<fieldset>
    <legend class="netopia-fieldset-legend">{__("netopia_live_keys_section")}</legend>

    <div class="control-group">
        <label class="control-label">{__("netopia_live_public_key")}:</label>
        <div class="controls">
            <div class="netopia-upload-row">
                <label for="netopia_live_public_key_file" class="btn netopia-upload-btn"><i class="icon-upload"></i> {__("netopia_upload_key_file")}</label>
                <input type="file" name="netopia_live_public_key_file" id="netopia_live_public_key_file" accept=".pem,.key,.cer,.crt,.pub,.txt" class="netopia-file-input" />
            </div>
            {if $processor_params.live_public_key_file}
                <div class="well well-small netopia-current-file">
                    <i class="icon-file"></i> {__("netopia_current_file")}: <strong>{$processor_params.live_public_key_file|escape:"html"}</strong>
                    <label class="netopia-delete-label"><input type="checkbox" name="delete_netopia_live_public_key" value="1" /> {__("netopia_delete_key_file")}</label>
                </div>
            {/if}
            <p class="muted netopia-paste-label">{__("netopia_or_paste_key")}:</p>
            <textarea name="payment_data[processor_params][live_public_key]" id="netopia_live_public_key" cols="65" rows="4">{$processor_params.live_public_key|escape:"html"}</textarea>
            <p class="muted description">{__("netopia_live_public_key_description")}</p>
        </div>
    </div>

    <div class="control-group">
        <label class="control-label">{__("netopia_live_private_key")}:</label>
        <div class="controls">
            <div class="netopia-upload-row">
                <label for="netopia_live_private_key_file" class="btn netopia-upload-btn"><i class="icon-upload"></i> {__("netopia_upload_key_file")}</label>
                <input type="file" name="netopia_live_private_key_file" id="netopia_live_private_key_file" accept=".pem,.key,.cer,.crt,.pub,.txt" class="netopia-file-input" />
            </div>
            {if $processor_params.live_private_key_file}
                <div class="well well-small netopia-current-file">
                    <i class="icon-file"></i> {__("netopia_current_file")}: <strong>{$processor_params.live_private_key_file|escape:"html"}</strong>
                    <label class="netopia-delete-label"><input type="checkbox" name="delete_netopia_live_private_key" value="1" /> {__("netopia_delete_key_file")}</label>
                </div>
            {/if}
            <p class="muted netopia-paste-label">{__("netopia_or_paste_key")}:</p>
            <textarea name="payment_data[processor_params][live_private_key]" id="netopia_live_private_key" cols="65" rows="4">{$processor_params.live_private_key|escape:"html"}</textarea>
            <p class="muted description">{__("netopia_live_private_key_description")}</p>
        </div>
    </div>
</fieldset>
