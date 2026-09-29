{*
  FGO Invoicing — the FGO cell of one order on the orders list (orders:manage_data).

  $o.fgo_invoice comes from the get_orders_post hook
  (OrderInvoiceColumn::cell): status, invoice_series, invoice_number,
  pdf_link (https only, or ''), last_error (shortened). No key at all means
  the lookup did not run (it failed, or this is not the admin list): "—",
  never a wrong "Not invoiced".
*}
{if !"RESTRICTED_ADMIN"|defined && !$runtime.company_id}
<td width="10%" class="fgo-invoicing-cell" data-th="{__("fgo_invoicing.col_fgo")}" data-fgo-status="{if $o.fgo_invoice}{$o.fgo_invoice.status}{else}unknown{/if}">
    {if !$o.fgo_invoice}
        <span class="muted">&mdash;</span>
    {else}
        {$fgo_cell = $o.fgo_invoice}
        {$fgo_status = $fgo_cell.status}
        {$fgo_label = "`$fgo_cell.invoice_series` `$fgo_cell.invoice_number`"|trim}
        {$fgo_view_url = "fgo_invoicing.view?order_id=`$o.order_id`"|fn_url}
        {if $fgo_status == "issued"}
            {if $fgo_cell.pdf_link}
                <a href="{$fgo_cell.pdf_link}" target="_blank" rel="noopener noreferrer" class="fgo-invoicing-cell__invoice" title="{__("fgo_invoicing.open_pdf")}">{$fgo_label|default:__("fgo_invoicing.state_issued")}</a>
            {else}
                <span class="fgo-invoicing-cell__invoice">{$fgo_label|default:__("fgo_invoicing.state_issued")}</span>
            {/if}
            <a href="{$fgo_view_url}" class="muted fgo-invoicing-cell__details">{__("fgo_invoicing.col_details")}</a>
        {elseif $fgo_status == "failed"}
            <a href="{$fgo_view_url}" class="label label-important" title="{$fgo_cell.last_error|default:__("fgo_invoicing.state_failed")}">{__("fgo_invoicing.col_failed")}</a>
        {elseif $fgo_status == "pending"}
            <a href="{$fgo_view_url}" class="label label-warning">{__("fgo_invoicing.state_pending")}</a>
        {elseif $fgo_status == "canceled" || $fgo_status == "reversed" || $fgo_status == "deleted"}
            <a href="{$fgo_view_url}" class="label" title="{$fgo_label}">{__("fgo_invoicing.state_`$fgo_status`")}</a>
        {else}
            <span class="muted">{__("fgo_invoicing.col_not_invoiced")}</span>
        {/if}
    {/if}
</td>
{/if}
