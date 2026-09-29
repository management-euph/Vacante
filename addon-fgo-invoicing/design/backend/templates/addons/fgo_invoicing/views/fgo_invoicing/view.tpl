{*
  FGO Invoicing — single invoice view.
  Shows the persisted row and the raw FGO response payload.
*}
{capture name="mainbox"}

<div class="row-fluid">
    <div class="span6">
        <div class="object-group">
            <h4>{__("fgo_invoicing.summary")}</h4>
            <table class="table">
                <tr><th>{__("order_id")}</th><td>
                    <a href="{"orders.details?order_id=`$fgo_invoice.order_id`"|fn_url}">
                        #{$fgo_invoice.order_id}
                    </a>
                </td></tr>
                <tr><th>{__("status")}</th><td>
                    <span class="label label-{if $fgo_invoice.status == "issued"}success
                        {elseif $fgo_invoice.status == "failed"}important
                        {elseif $fgo_invoice.status == "pending"}warning
                        {else}default{/if}">{$fgo_invoice.status}</span>
                </td></tr>
                <tr><th>{__("fgo_invoicing.invoice_series")}</th><td>{$fgo_invoice.invoice_series|default:"-"}</td></tr>
                <tr><th>{__("fgo_invoicing.invoice_number")}</th><td>{$fgo_invoice.invoice_number|default:"-"}</td></tr>
                <tr><th>{__("fgo_invoicing.pdf_link")}</th><td>
                    {if $fgo_invoice.pdf_link}
                        <a href="{$fgo_invoice.pdf_link}" target="_blank" rel="noopener">{__("fgo_invoicing.open_pdf")}</a>
                    {else}-{/if}
                </td></tr>
                <tr><th>{__("fgo_invoicing.payment_link")}</th><td>
                    {if $fgo_invoice.payment_link}
                        <a href="{$fgo_invoice.payment_link}" target="_blank" rel="noopener">{__("fgo_invoicing.open_payment")}</a>
                    {else}-{/if}
                </td></tr>
                <tr><th>{__("fgo_invoicing.awb")}</th><td>{$fgo_invoice.awb|default:"-"}</td></tr>
                <tr><th>{__("fgo_invoicing.retry_count")}</th><td>{$fgo_invoice.retry_count|default:0}</td></tr>
                <tr><th>{__("fgo_invoicing.message")}</th><td>{$fgo_invoice.message|default:"-"|escape:html}</td></tr>
                <tr><th>{__("fgo_invoicing.last_error")}</th><td>
                    {if $fgo_invoice.last_error}
                        <pre>{$fgo_invoice.last_error|escape:html}</pre>
                    {else}-{/if}
                </td></tr>
                <tr><th>{__("created")}</th><td>{$fgo_invoice.created_at}</td></tr>
                <tr><th>{__("updated")}</th><td>{$fgo_invoice.updated_at}</td></tr>
            </table>
        </div>
    </div>
    <div class="span6">
        <div class="object-group">
            <h4>{__("fgo_invoicing.actions")}</h4>
            <form action="{""|fn_url}" method="post" class="form-horizontal">
                <input type="hidden" name="order_id" value="{$fgo_invoice.order_id}" />
                <input type="hidden" name="result_ids" value="" />

                <div class="control-group">
                    <button type="submit"
                            name="dispatch[fgo_invoicing.issue]"
                            class="btn btn-primary cm-confirm"
                            data-ca-confirm-text="{__("fgo_invoicing.confirm_issue")}">
                        {__("fgo_invoicing.btn_issue")}
                    </button>

                    {if $fgo_invoice.status == "issued"}
                        <button type="submit"
                                name="dispatch[fgo_invoicing.cancel]"
                                class="btn cm-confirm"
                                data-ca-confirm-text="{__("fgo_invoicing.confirm_cancel")}">
                            {__("fgo_invoicing.btn_cancel")}
                        </button>
                        <button type="submit"
                                name="dispatch[fgo_invoicing.storno]"
                                class="btn cm-confirm"
                                data-ca-confirm-text="{__("fgo_invoicing.confirm_storno")}">
                            {__("fgo_invoicing.btn_storno")}
                        </button>
                        <button type="submit"
                                name="dispatch[fgo_invoicing.delete]"
                                class="btn btn-danger cm-confirm"
                                data-ca-confirm-text="{__("fgo_invoicing.confirm_delete")}">
                            {__("fgo_invoicing.btn_delete")}
                        </button>
                    {/if}
                </div>

                {if $fgo_invoice.status == "issued"}
                <div class="control-group">
                    <label class="control-label">{__("fgo_invoicing.awb")}</label>
                    <div class="controls">
                        <input type="text" name="awb" value="{$fgo_invoice.awb|escape:html}" />
                        <button type="submit" name="dispatch[fgo_invoicing.attach_awb]" class="btn">
                            {__("fgo_invoicing.btn_attach_awb")}
                        </button>
                    </div>
                </div>
                {/if}
            </form>
        </div>
    </div>
</div>

<div class="row-fluid">
    <div class="span6">
        <h4>{__("fgo_invoicing.request_payload")}</h4>
        <pre style="max-height:400px;overflow:auto;">{$fgo_request_pretty|escape:html}</pre>
    </div>
    <div class="span6">
        <h4>{__("fgo_invoicing.response_payload")}</h4>
        <pre style="max-height:400px;overflow:auto;">{$fgo_response_pretty|escape:html}</pre>
    </div>
</div>

{* Issue attempts from ?:fgo_diagnostic_logs. Output is auto-escaped by
   CS-Cart (escape_html), so no |escape here. *}
<div class="row-fluid">
    <div class="span12">
        <h4>{__("fgo_invoicing.diagnostics_title")}</h4>
        <p class="muted">{__("fgo_invoicing.diagnostics_intro")}</p>
        {if $fgo_attempts}
            <table class="table table-middle">
                <thead>
                    <tr>
                        <th>{__("fgo_invoicing.diag_date")}</th>
                        <th>{__("fgo_invoicing.diag_response_code")}</th>
                        <th>{__("fgo_invoicing.diag_cnp_length")}</th>
                        <th>{__("fgo_invoicing.diag_cnp_checksum")}</th>
                        <th>{__("fgo_invoicing.diag_error_message")}</th>
                    </tr>
                </thead>
                <tbody>
                {foreach from=$fgo_attempts item="attempt"}
                    <tr>
                        <td class="nowrap">{$attempt.created_at}</td>
                        <td>
                            <span class="label {if $attempt.fgo_response_code == "success"}label-success{elseif $attempt.fgo_response_code == "blocked"}label-warning{else}label-important{/if}">{$attempt.fgo_response_code}</span>
                        </td>
                        <td>{if $attempt.cnp_length === null || $attempt.cnp_length === ""}-{else}{$attempt.cnp_length}{/if}</td>
                        <td>
                            {if $attempt.is_cnp_checksum_valid === null || $attempt.is_cnp_checksum_valid === ""}
                                <span class="muted">{__("fgo_invoicing.diag_no_cnp")}</span>
                            {elseif $attempt.is_cnp_checksum_valid == 1}
                                {__("fgo_invoicing.diag_checksum_ok")}
                            {else}
                                <strong class="text-error">{__("fgo_invoicing.diag_checksum_bad")}</strong>
                            {/if}
                        </td>
                        <td>{$attempt.fgo_error_message|default:"-"}</td>
                    </tr>
                    {if $attempt.request_pretty}
                    <tr>
                        <td colspan="5">
                            <details>
                                <summary>{__("fgo_invoicing.diag_payload")}</summary>
                                <pre style="max-height:300px;overflow:auto;">{$attempt.request_pretty}</pre>
                            </details>
                        </td>
                    </tr>
                    {/if}
                {/foreach}
                </tbody>
            </table>
        {else}
            <p>{__("fgo_invoicing.diag_none")}</p>
        {/if}
    </div>
</div>

{/capture}
{$fgo_title = __("fgo_invoicing.invoice_for_order")|cat:" #"|cat:$fgo_invoice.order_id}
{include file="common/mainbox.tpl" title=$fgo_title content=$smarty.capture.mainbox}
