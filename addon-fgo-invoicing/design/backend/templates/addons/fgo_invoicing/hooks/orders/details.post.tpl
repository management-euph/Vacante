{*
  FGO Invoicing — order details panel.
  Renders below the existing CS-Cart order detail blocks via the
  `orders.details` template hook.

  $order_info.fgo_invoice is the summary the get_order_info hook attaches
  (OrderInvoiceColumn::summary: status, series, number, https PDF link, last
  error, updated_at), and only where fn_fgo_invoicing_shows_invoice_data()
  allows it; the panel hides on the same terms, so a restricted admin or a
  selected storefront never sees "no invoice yet" for an invoiced order.
*}
{if !"RESTRICTED_ADMIN"|defined && !$runtime.company_id}
{if $order_info.fgo_invoice}
    <div class="object-group">
        <h4>{__("fgo_invoicing.invoice")}</h4>
        <table class="table">
            <tr>
                <th>{__("status")}</th>
                <td>
                    <span class="label label-{if $order_info.fgo_invoice.status == "issued"}success
                        {elseif $order_info.fgo_invoice.status == "failed"}important
                        {elseif $order_info.fgo_invoice.status == "pending"}warning
                        {else}default{/if}">
                        {$order_info.fgo_invoice.status}
                    </span>
                </td>
            </tr>
            <tr>
                <th>{__("fgo_invoicing.invoice_series")}</th>
                <td>{$order_info.fgo_invoice.invoice_series|default:"-"}</td>
            </tr>
            <tr>
                <th>{__("fgo_invoicing.invoice_number")}</th>
                <td>{$order_info.fgo_invoice.invoice_number|default:"-"}</td>
            </tr>
            <tr>
                <th>{__("fgo_invoicing.pdf_link")}</th>
                <td>
                    {if $order_info.fgo_invoice.pdf_link}
                        <a href="{$order_info.fgo_invoice.pdf_link}" target="_blank" rel="noopener">
                            {__("fgo_invoicing.open_pdf")}
                        </a>
                    {else}-{/if}
                </td>
            </tr>
            <tr>
                <th>&nbsp;</th>
                <td>
                    <a class="btn btn-default btn-mini"
                       href="{"fgo_invoicing.view?order_id=`$order_info.order_id`"|fn_url}">
                        {__("fgo_invoicing.btn_view_panel")}
                    </a>
                </td>
            </tr>
        </table>
    </div>
{else}
    <div class="object-group">
        <h4>{__("fgo_invoicing.invoice")}</h4>
        <p>{__("fgo_invoicing.no_invoice_yet")}</p>
        <form action="{""|fn_url}" method="post" class="form-inline">
            <input type="hidden" name="order_id" value="{$order_info.order_id}" />
            <button type="submit"
                    name="dispatch[fgo_invoicing.issue]"
                    class="btn btn-primary cm-confirm"
                    data-ca-confirm-text="{__("fgo_invoicing.confirm_issue")}">
                {__("fgo_invoicing.btn_issue")}
            </button>
        </form>
    </div>
{/if}
{/if}
