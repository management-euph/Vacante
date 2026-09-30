{* NETOPIA Payments processor configuration.

   Four tabs (Connection, Checkout, Order statuses, Keys) over ONE form: every
   field stays in the DOM, a tab only hides it, so Save sends them all.
   js/addons/netopia_payments/processor_config.js drives the tabs, the mode
   switch, the key cards, "Check settings", the status table and the
   description preview. Without it the page still works: every section shows,
   mode is a real radio input, and nothing depends on script to submit. *}

{$np_pid = $payment_id|default:$smarty.request.payment_id|default:0}
{$np_mode = $processor_params.mode|default:"sandbox"}
{if $np_mode != "live"}{$np_mode = "sandbox"}{/if}
{$np_keys = $processor_params|fn_netopia_key_overview:$np_pid}
{$np_st = $processor_params|fn_netopia_status_table}
{$order_statuses = "O"|fn_get_simple_statuses}
{$np_inst_on = $processor_params.allow_installments == "Y"}
{$np_desc = $processor_params.order_description|default:"comanda #[order_id] din [site_url]"}
{$np_site = $config.current_host|default:$config.http_host|default:"example.ro"}
{$np_currency = $smarty.const.CART_PRIMARY_CURRENCY|default:"RON"}
{$np_blocked_sandbox = $np_keys.sandbox.public_key.state != "ok"}
{$np_blocked_live = $np_keys.live.public_key.state != "ok"}

{literal}
<style>
.netopia-cfg { --np-ok:#2d7d46; --np-ok-bg:#e4f3e8; --np-warn:#946000; --np-warn-bg:#fcf1dc; --np-bad:#b3372f; --np-bad-bg:#f9e4e2; --np-info:#1c6fb8; --np-info-bg:#e5eff9; --np-line:#dfe3e8; --np-soft:#f6f8fa; --np-muted:#6b7580; }
.netopia-tabs { display:none; gap:2px; border-bottom:1px solid var(--np-line); margin:0 0 16px; flex-wrap:wrap; }
.netopia-cfg--tabs .netopia-tabs { display:flex; }
.netopia-tab { background:none; border:0; border-bottom:2px solid transparent; padding:8px 14px; color:var(--np-muted); cursor:pointer; font-size:13px; }
.netopia-tab[aria-selected="true"] { color:#222; border-bottom-color:var(--np-info); font-weight:600; }
.netopia-tab:focus-visible, .netopia-seg label:focus-within, .netopia-cfg .btn:focus-visible { outline:2px solid var(--np-info); outline-offset:2px; }
.netopia-tab__dot { display:inline-block; width:7px; height:7px; border-radius:50%; background:var(--np-bad); margin-left:6px; vertical-align:1px; }
.netopia-cfg--tabs .netopia-panel { display:none; }
.netopia-cfg--tabs .netopia-panel.is-active { display:block; }
.netopia-panel__title { font-size:14px; font-weight:600; margin:18px 0 10px; padding-bottom:5px; border-bottom:1px solid var(--np-line); }
.netopia-cfg--tabs .netopia-panel__title { display:none; }
.netopia-banner { display:flex; gap:10px; align-items:flex-start; padding:9px 12px; border-radius:4px; margin:0 0 16px; border:1px solid transparent; }
.netopia-banner--sandbox { background:var(--np-warn-bg); border-color:#f0d9a8; }
.netopia-banner--live { background:var(--np-ok-bg); border-color:#bfe0c8; }
.netopia-banner b { display:block; }
.netopia-cfg[data-mode="sandbox"] .netopia-banner--live, .netopia-cfg[data-mode="live"] .netopia-banner--sandbox { display:none; }
.netopia-pill { display:inline-block; font-size:11px; font-weight:600; line-height:18px; padding:0 8px; border-radius:9px; white-space:nowrap; letter-spacing:.02em; }
.netopia-pill--ok { background:var(--np-ok-bg); color:var(--np-ok); }
.netopia-pill--warn { background:var(--np-warn-bg); color:var(--np-warn); }
.netopia-pill--bad { background:var(--np-bad-bg); color:var(--np-bad); }
.netopia-pill--info { background:var(--np-info-bg); color:var(--np-info); }
.netopia-pill--muted { background:var(--np-soft); color:var(--np-muted); }
.netopia-seg { display:inline-flex; border:1px solid #ccc; border-radius:4px; overflow:hidden; }
.netopia-seg label { margin:0; padding:5px 16px; cursor:pointer; background:#fff; }
.netopia-seg label + label { border-left:1px solid #ccc; }
.netopia-seg input { position:absolute; opacity:0; width:1px; height:1px; }
.netopia-seg label.is-sandbox.is-checked, .netopia-seg label.is-sandbox:has(input:checked) { background:var(--np-warn-bg); color:var(--np-warn); font-weight:600; }
.netopia-seg label.is-live.is-checked, .netopia-seg label.is-live:has(input:checked) { background:var(--np-ok-bg); color:var(--np-ok); font-weight:600; }
.netopia-confirm { margin-top:8px; padding:9px 12px; border-radius:4px; background:var(--np-warn-bg); border:1px solid #f0d9a8; max-width:520px; }
.netopia-confirm .btn { margin-top:6px; margin-right:6px; }
.netopia-secret { display:flex; gap:6px; align-items:center; }
.netopia-test { border:1px solid var(--np-line); border-radius:4px; padding:10px 12px; margin-top:6px; max-width:640px; }
.netopia-test__head { display:flex; justify-content:space-between; align-items:center; gap:10px; }
.netopia-test__list { list-style:none; margin:8px 0 0; padding:0; }
.netopia-test__list li { margin:4px 0; display:flex; gap:8px; align-items:baseline; }
.netopia-tokens { margin-top:6px; display:flex; flex-wrap:wrap; gap:6px; }
.netopia-token { font-family:Menlo,Consolas,monospace; font-size:12px; border:1px solid var(--np-line); background:var(--np-soft); border-radius:3px; padding:1px 6px; cursor:pointer; }
.netopia-preview { margin-top:6px; color:var(--np-muted); }
.netopia-preview code { color:#222; background:var(--np-soft); }
.netopia-st__bar { display:flex; justify-content:space-between; align-items:center; gap:10px; margin-bottom:10px; flex-wrap:wrap; }
.netopia-st table { width:100%; }
.netopia-st td, .netopia-st th { vertical-align:middle !important; }
.netopia-st td.netopia-st__code { font-family:Menlo,Consolas,monospace; color:var(--np-muted); width:40px; }
.netopia-st select { margin:0; max-width:230px; }
.netopia-st td.netopia-st__msg { color:var(--np-muted); font-size:12px; }
.netopia-st tr.is-changed td:first-child { box-shadow:inset 3px 0 var(--np-info); }
.netopia-st__stripe { display:inline-block; width:8px; height:8px; border-radius:2px; margin-right:6px; }
.netopia-st__stripe--success { background:var(--np-ok); } .netopia-st__stripe--pending { background:#c9a13b; }
.netopia-st__stripe--cancel { background:#98a2ad; } .netopia-st__stripe--fail { background:var(--np-bad); }
.netopia-st__stripe--refund { background:var(--np-info); }
.netopia-st details { margin-top:12px; }
.netopia-st summary { cursor:pointer; color:var(--np-info); }
.netopia-keys-intro { color:var(--np-muted); margin-bottom:10px; }
.netopia-keyset { display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:12px; margin-bottom:12px; }
.netopia-cfg[data-mode="sandbox"] .netopia-keyset--live, .netopia-cfg[data-mode="live"] .netopia-keyset--sandbox { display:none; }
.netopia-cfg.is-showing-all-keys .netopia-keyset { display:grid; }
.netopia-cfg[data-mode="sandbox"] .netopia-creds--live, .netopia-cfg[data-mode="live"] .netopia-creds--sandbox { display:none; }
.netopia-cfg.is-showing-all-creds .netopia-creds { display:block; }
.netopia-key { border:1px solid var(--np-line); border-radius:4px; padding:12px; display:flex; flex-direction:column; gap:8px; min-width:0; }
.netopia-key__top { display:flex; justify-content:space-between; gap:8px; align-items:center; }
.netopia-key__file { font-family:Menlo,Consolas,monospace; font-size:12px; background:var(--np-soft); padding:5px 8px; border-radius:3px; display:flex; justify-content:space-between; gap:8px; align-items:center; overflow-wrap:anywhere; }
.netopia-key__file label { margin:0; font-family:inherit; white-space:nowrap; }
.netopia-drop { position:relative; border:1.5px dashed #c5ccd3; border-radius:4px; padding:12px; text-align:center; color:var(--np-muted); cursor:pointer; }
.netopia-drop.is-over { border-color:var(--np-info); background:var(--np-info-bg); }
.netopia-drop input[type=file] { position:absolute; inset:0; width:100%; height:100%; opacity:0; cursor:pointer; }
.netopia-drop__chosen { display:block; color:#222; font-weight:600; margin-top:4px; overflow-wrap:anywhere; }
.netopia-drop__warn, .netopia-key__warn { display:block; color:var(--np-bad); font-weight:600; margin-top:4px; }
.netopia-key textarea { width:100%; box-sizing:border-box; font-family:Menlo,Consolas,monospace; font-size:11px; margin-top:6px; }
.netopia-key summary { cursor:pointer; color:var(--np-info); font-size:12px; }
.netopia-key__note { color:var(--np-muted); font-size:12px; margin:0; }
.netopia-inst { display:flex; flex-wrap:wrap; gap:14px; align-items:center; }
.netopia-inst label { margin:0; display:inline-flex; gap:6px; align-items:center; }
.netopia-inst input[readonly] { opacity:.5; }
</style>
{/literal}

{* Uploads need multipart; the payment form is not by default. Kept inline
   (the config script does the same) so key uploads work even if the script
   has not loaded in the dialog yet. *}
{literal}
<script>
(function () {
    var el = document.getElementById('netopia_cfg') || document.getElementById('netopia_sandbox_pos_signature');
    var form = el ? el.closest('form') : null;
    if (form) { form.setAttribute('enctype', 'multipart/form-data'); form.encoding = 'multipart/form-data'; }
})();
</script>
{/literal}
{script src="js/addons/netopia_payments/processor_config.js"}

<div class="netopia-cfg" id="netopia_cfg"
     data-mode="{$np_mode}"
     data-blocked-sandbox="{if $np_blocked_sandbox}1{else}0{/if}"
     data-blocked-live="{if $np_blocked_live}1{else}0{/if}"
     data-test-url="{"netopia_config.test"|fn_url}"
     data-payment-id="{$np_pid}"
     data-sample-order-id="1001"
     data-sample-total="250.00"
     data-sample-currency="{$np_currency|escape:"html"}"
     data-sample-email="client@example.ro"
     data-sample-site-url="{$np_site|escape:"html"}"
     data-txt-show="{__("netopia_show")|escape:"html"}"
     data-txt-hide="{__("netopia_hide")|escape:"html"}"
     data-txt-testing="{__("netopia_test_running")|escape:"html"}"
     data-txt-test-failed="{__("netopia_test_request_failed")|escape:"html"}"
     data-txt-remove-marked="{__("netopia_key_remove_marked")|escape:"html"}"
     data-txt-mode-sandbox="{__("netopia_sandbox")|escape:"html"}"
     data-txt-mode-live="{__("netopia_live")|escape:"html"}"
     data-txt-key-wrong-mode="{__("netopia_key_pick_wrong_mode")|escape:"html"}">

    <div class="netopia-tabs" role="tablist" aria-label="NETOPIA">
        <button type="button" class="netopia-tab" role="tab" data-np-tab="conn" aria-selected="true">{__("netopia_tab_connection")}</button>
        <button type="button" class="netopia-tab" role="tab" data-np-tab="chk" aria-selected="false">{__("netopia_tab_checkout")}</button>
        <button type="button" class="netopia-tab" role="tab" data-np-tab="st" aria-selected="false">{__("netopia_tab_statuses")}{if $np_st.changed > 0} <span class="netopia-pill netopia-pill--info">{$np_st.changed}</span>{/if}</button>
        <button type="button" class="netopia-tab" role="tab" data-np-tab="keys" aria-selected="false">{__("netopia_tab_keys")}<span class="netopia-tab__dot" data-np-keys-dot {if ($np_mode == "live" && !$np_blocked_live) || ($np_mode == "sandbox" && !$np_blocked_sandbox)}hidden{/if}></span></button>
    </div>

    {* ── Connection ─────────────────────────────────────────────── *}
    <div class="netopia-panel is-active" data-np-panel="conn" role="tabpanel">
        <h4 class="netopia-panel__title">{__("netopia_tab_connection")}</h4>

        <div class="netopia-banner netopia-banner--sandbox">
            <span class="netopia-pill netopia-pill--warn">SANDBOX</span>
            <div><b>{__("netopia_banner_sandbox_title")}</b><span class="muted">{__("netopia_banner_sandbox_text")}</span></div>
        </div>
        <div class="netopia-banner netopia-banner--live">
            <span class="netopia-pill netopia-pill--ok">LIVE</span>
            <div><b>{__("netopia_banner_live_title")}</b><span class="muted">{__("netopia_banner_live_text")}</span></div>
        </div>

        <div class="control-group">
            <label class="control-label">{__("netopia_mode")}:</label>
            <div class="controls">
                <div class="netopia-seg" role="radiogroup" aria-label="{__("netopia_mode")|escape:"html"}">
                    <label class="is-sandbox"><input type="radio" name="payment_data[processor_params][mode]" id="netopia_mode_sandbox" value="sandbox" {if $np_mode == "sandbox"}checked="checked"{/if} /> {__("netopia_sandbox")}</label>
                    <label class="is-live"><input type="radio" name="payment_data[processor_params][mode]" id="netopia_mode" value="live" {if $np_mode == "live"}checked="checked"{/if} /> {__("netopia_live")}</label>
                </div>
                <div class="netopia-confirm" data-np-live-confirm hidden>
                    <b>{__("netopia_live_confirm_title")}</b>
                    <div class="muted">{__("netopia_live_confirm_text")}</div>
                    <p class="muted" data-np-live-creds-missing hidden><span class="netopia-pill netopia-pill--bad">{__("netopia_key_missing")}</span> {__("netopia_live_confirm_no_api_key")}</p>
                    <p class="muted" data-np-live-keys-missing {if !$np_blocked_live}hidden{/if}><span class="netopia-pill netopia-pill--bad">{__("netopia_key_missing")}</span> {__("netopia_live_confirm_no_key")}</p>
                    <button type="button" class="btn btn-primary" data-np-live-yes>{__("netopia_live_confirm_yes")}</button>
                    <button type="button" class="btn" data-np-live-no>{__("netopia_live_confirm_no")}</button>
                </div>
            </div>
        </div>

        {* One API key + POS signature per mode (Config\Credentials). Only the
           selected mode's pair shows; the other is kept and sent on Save. *}
        {foreach from=["sandbox","live"] item="m"}
        {$cred = $processor_params|fn_netopia_credentials:$m}
        {* Only what the admin typed is shown in the field; a signature read from the key file name is the placeholder. *}
        {$pos_source = $processor_params|fn_netopia_pos_signature_source:$m}
        <div class="netopia-creds netopia-creds--{$m}" data-np-creds="{$m}">
            <div class="control-group">
                <label class="control-label" for="netopia_{$m}_pos_signature">{__("netopia_`$m`_pos_signature")}:</label>
                <div class="controls">
                    <div class="netopia-secret">
                        <input type="password" name="payment_data[processor_params][{$m}_pos_signature]" id="netopia_{$m}_pos_signature" value="{if $pos_source == "typed"}{$cred.pos_signature|escape:"html"}{/if}" size="40" autocomplete="off" placeholder="{if $pos_source == "key_file"}{$cred.pos_signature|escape:"html"}{else}XXXX-XXXX-XXXX-XXXX-XXXX{/if}" />
                        <button type="button" class="btn" data-np-reveal="netopia_{$m}_pos_signature" aria-controls="netopia_{$m}_pos_signature">{__("netopia_show")}</button>
                    </div>
                    <p class="muted description" data-np-pos-note="{$m}" {if $pos_source != "key_file"}hidden{/if}>{__("netopia_pos_signature_from_key_hint")}</p>
                    <p class="muted description">{__("netopia_`$m`_pos_signature_description")}</p>
                </div>
            </div>
            <div class="control-group">
                <label class="control-label" for="netopia_{$m}_api_key">{__("netopia_`$m`_api_key")}:</label>
                <div class="controls">
                    <div class="netopia-secret">
                        <input type="password" name="payment_data[processor_params][{$m}_api_key]" id="netopia_{$m}_api_key" value="{$cred.api_key|escape:"html"}" size="40" autocomplete="off" placeholder="ApiKey_XXXXXXXX" />
                        <button type="button" class="btn" data-np-reveal="netopia_{$m}_api_key" aria-controls="netopia_{$m}_api_key">{__("netopia_show")}</button>
                    </div>
                    <p class="muted description">{__("netopia_`$m`_api_key_description")}</p>
                </div>
            </div>
        </div>
        {/foreach}
        <p class="muted description" style="margin-left:0"><a href="#" data-np-show-all-creds>{__("netopia_creds_show_all")}</a></p>

        <div class="control-group">
            <div class="controls">
                <div class="netopia-test" data-np-test>
                    <div class="netopia-test__head">
                        <b>{__("netopia_test_title")}</b>
                        <button type="button" class="btn" data-np-test-run>{__("netopia_test_button")}</button>
                    </div>
                    <div class="muted" data-np-test-intro>{__("netopia_test_intro")}</div>
                    <div data-np-test-summary hidden></div>
                    <ul class="netopia-test__list" data-np-test-list hidden></ul>
                </div>
            </div>
        </div>
    </div>

    {* ── Checkout ───────────────────────────────────────────────── *}
    <div class="netopia-panel" data-np-panel="chk" role="tabpanel">
        <h4 class="netopia-panel__title">{__("netopia_tab_checkout")}</h4>

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

        <div class="control-group">
            <label class="control-label" for="netopia_allow_installments">{__("netopia_allow_installments")}:</label>
            <div class="controls">
                <div class="netopia-inst">
                    <input type="hidden" name="payment_data[processor_params][allow_installments]" value="N" />
                    <label><input type="checkbox" name="payment_data[processor_params][allow_installments]" id="netopia_allow_installments" value="Y" {if $np_inst_on}checked="checked"{/if} /> {__("netopia_allow_installments_label")}</label>
                    <label for="netopia_max_installments">{__("netopia_installments_up_to")}
                        <input type="number" name="payment_data[processor_params][max_installments]" id="netopia_max_installments" value="{$processor_params.max_installments|default:1}" min="1" max="36" class="input-mini" {if !$np_inst_on}readonly="readonly" aria-disabled="true"{/if} />
                        {__("netopia_installments_months")}</label>
                </div>
                <p class="muted description">{__("netopia_max_installments_description")}</p>
            </div>
        </div>

        <div class="control-group">
            <label class="control-label" for="netopia_order_description">{__("netopia_order_description")}:</label>
            <div class="controls">
                <input type="text" name="payment_data[processor_params][order_description]" id="netopia_order_description" value="{$np_desc|escape:"html"}" size="60" />
                <div class="netopia-tokens" aria-label="{__("netopia_order_description_insert")|escape:"html"}">
                    {foreach from=["[order_id]","[total]","[currency]","[email]","[site_url]"] item="tok"}
                        <button type="button" class="netopia-token" data-np-token="{$tok}">{$tok}</button>
                    {/foreach}
                </div>
                <p class="netopia-preview">{__("netopia_order_description_preview")}: <code data-np-desc-preview>{$np_desc|replace:"[order_id]":"1001"|replace:"[total]":"250.00"|replace:"[currency]":$np_currency|replace:"[email]":"client@example.ro"|replace:"[site_url]":$np_site|escape:"html"}</code></p>
                <p class="muted description">{__("netopia_order_description_help")}</p>
            </div>
        </div>
    </div>

    {* ── Order statuses ─────────────────────────────────────────── *}
    <div class="netopia-panel netopia-st" data-np-panel="st" role="tabpanel">
        <h4 class="netopia-panel__title">{__("netopia_tab_statuses")}</h4>
        <div class="netopia-st__bar">
            <p class="muted" style="margin:0">{__("netopia_status_mapping_description")} {__("netopia_status_changed_hint")}</p>
            <button type="button" class="btn" data-np-reset>{__("netopia_status_reset")}</button>
        </div>

        <table class="table table-middle">
            <thead><tr><th>{__("netopia_status_col_result")}</th><th>#</th><th>{__("netopia_status_col_order_status")}</th><th>{__("netopia_status_col_customer")}</th></tr></thead>
            <tbody>
            {foreach from=$np_st.common item="row"}
                <tr class="{if $row.changed}is-changed{/if}">
                    <td><span class="netopia-st__stripe netopia-st__stripe--{$row.group}"></span>{__($row.lang_key)}</td>
                    <td class="netopia-st__code">{$row.code}</td>
                    <td>
                        <select name="payment_data[processor_params][status_map_{$row.key}]" data-np-default="{$row.default}" aria-label="{__($row.lang_key)|escape:"html"}">
                            {foreach from=$order_statuses key="cs_code" item="cs_label"}
                                <option value="{$cs_code}" {if $row.value == $cs_code}selected="selected"{/if}>[{$cs_code}] {$cs_label}</option>
                            {/foreach}
                        </select>
                    </td>
                    <td class="netopia-st__msg">{__($row.customer_msg_key)}</td>
                </tr>
            {/foreach}
            </tbody>
        </table>

        <details>
            <summary>{__("netopia_status_rare_toggle", ["[count]" => $np_st.rare|@count])}</summary>
            <table class="table table-middle">
                <thead><tr><th>{__("netopia_status_col_result")}</th><th>#</th><th>{__("netopia_status_col_order_status")}</th><th>{__("netopia_status_col_group")}</th></tr></thead>
                <tbody>
                {foreach from=$np_st.rare item="row"}
                    <tr class="{if $row.changed}is-changed{/if}">
                        <td><span class="netopia-st__stripe netopia-st__stripe--{$row.group}"></span>{__($row.lang_key)}</td>
                        <td class="netopia-st__code">{$row.code}</td>
                        <td>
                            <select name="payment_data[processor_params][status_map_{$row.key}]" data-np-default="{$row.default}" aria-label="{__($row.lang_key)|escape:"html"}">
                                {foreach from=$order_statuses key="cs_code" item="cs_label"}
                                    <option value="{$cs_code}" {if $row.value == $cs_code}selected="selected"{/if}>[{$cs_code}] {$cs_label}</option>
                                {/foreach}
                            </select>
                        </td>
                        <td class="netopia-st__msg">{__("netopia_status_group_`$row.group`")}</td>
                    </tr>
                {/foreach}
                </tbody>
            </table>
        </details>
    </div>

    {* ── Keys ───────────────────────────────────────────────────── *}
    <div class="netopia-panel" data-np-panel="keys" role="tabpanel">
        <h4 class="netopia-panel__title">{__("netopia_tab_keys")}</h4>
        <p class="netopia-keys-intro">
            <span data-np-keys-intro="sandbox" {if $np_mode != "sandbox"}hidden{/if}>{__("netopia_keys_intro_sandbox")}</span>
            <span data-np-keys-intro="live" {if $np_mode != "live"}hidden{/if}>{__("netopia_keys_intro_live")}</span>
            <a href="#" data-np-show-all-keys>{__("netopia_keys_show_all")}</a>
        </p>

        {foreach from=["sandbox","live"] item="m"}
        <div class="netopia-keyset netopia-keyset--{$m}" data-np-keyset="{$m}">
            {foreach from=["public_key","private_key"] item="t"}
            {$k = $np_keys.$m.$t}
            <div class="netopia-key" data-np-key="{$k.slot}" data-np-required="{if $k.required}1{else}0{/if}">
                <div class="netopia-key__top">
                    <b>{__("netopia_`$m`_`$t`")}</b>
                    {if $k.state == "ok" && $k.expires_soon}
                        <span class="netopia-pill netopia-pill--warn">{__("netopia_key_expires_soon")}</span>
                    {elseif $k.state == "ok"}
                        <span class="netopia-pill netopia-pill--ok">{__("netopia_key_loaded")}</span>
                    {elseif $k.state == "expired"}
                        <span class="netopia-pill netopia-pill--bad">{__("netopia_key_expired")}</span>
                    {elseif $k.state == "invalid"}
                        <span class="netopia-pill netopia-pill--bad">{__("netopia_key_invalid")}</span>
                    {elseif $k.required}
                        <span class="netopia-pill netopia-pill--bad">{__("netopia_key_missing")}</span>
                    {else}
                        <span class="netopia-pill netopia-pill--muted">{__("netopia_key_not_set")}</span>
                    {/if}
                </div>

                {if $k.file}
                    <div class="netopia-key__file">
                        <span>{$k.file|escape:"html"}</span>
                        <label><input type="checkbox" name="delete_netopia_{$k.slot}" value="1" data-np-remove /> {__("netopia_delete_key_file")}</label>
                    </div>
                    {if $k.wrong_mode}
                        <span class="netopia-key__warn">{__("netopia_key_in_wrong_slot", ["[file_mode]" => __("netopia_`$k.wrong_mode`"), "[slot_mode]" => __("netopia_`$m`")])}</span>
                    {/if}
                {/if}

                <label class="netopia-drop" data-np-drop>
                    <input type="file" name="netopia_{$k.slot}_file" id="netopia_{$k.slot}_file" accept=".pem,.key,.cer,.crt,.pub,.txt" />
                    {if $k.file}{__("netopia_key_drop_replace")}{else}{__("netopia_key_drop")}{/if}
                    <span class="netopia-drop__chosen" data-np-chosen hidden></span>
                    <span class="netopia-drop__warn" data-np-wrong-mode hidden></span>
                </label>

                <p class="netopia-key__note">
                    {if $k.expires_at}{__("netopia_key_valid_until", ["[date]" => $k.expires_at|date_format:"%d.%m.%Y"])} · {/if}
                    {if $k.required}{__("netopia_`$m`_public_key_description")}{else}{__("netopia_private_key_optional")}{/if}
                </p>

                <details {if $k.source == "pasted"}open{/if}>
                    <summary>{__("netopia_or_paste_key")}</summary>
                    <textarea name="payment_data[processor_params][{$k.slot}]" id="netopia_{$k.slot}" rows="4" aria-label="{__("netopia_`$m`_`$t`")|escape:"html"}">{$processor_params[$k.slot]|escape:"html"}</textarea>
                </details>
            </div>
            {/foreach}
        </div>
        {/foreach}
        <p class="muted description">{__("netopia_keys_where")}</p>
    </div>
</div>
