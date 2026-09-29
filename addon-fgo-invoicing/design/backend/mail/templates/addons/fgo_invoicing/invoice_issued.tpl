{include file="common/letter_header.tpl"}

{* $customer_name, not $company_name: CS-Cart's mailer sets $company_name to the STORE's name. *}
<p>{__("fgo_invoicing.email_greeting")}{if $customer_name}, {$customer_name}{/if},</p>

<p>{__("fgo_invoicing.email_body_intro")} <strong>#{$order_id}</strong>.</p>

<table style="border-collapse:collapse;margin:16px 0;">
    <tr>
        <td style="padding:4px 12px;"><strong>{__("fgo_invoicing.invoice_series")}:</strong></td>
        <td style="padding:4px 12px;">{$invoice_series}</td>
    </tr>
    <tr>
        <td style="padding:4px 12px;"><strong>{__("fgo_invoicing.invoice_number")}:</strong></td>
        <td style="padding:4px 12px;">{$invoice_number}</td>
    </tr>
</table>

<p>
    <a href="{$pdf_link}"
       style="display:inline-block;padding:10px 18px;background:#286090;color:#fff;text-decoration:none;border-radius:4px;">
        {__("fgo_invoicing.email_pdf_button")}
    </a>
</p>

{if $payment_link}
<p>
    <a href="{$payment_link}">{__("fgo_invoicing.email_payment_link")}</a>
</p>
{/if}

<p>{__("fgo_invoicing.email_signoff")}</p>

{include file="common/letter_footer.tpl"}
