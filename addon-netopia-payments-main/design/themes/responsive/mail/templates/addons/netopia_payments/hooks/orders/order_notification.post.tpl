{**
 * NETOPIA Payments — Payment retry link in order notification emails.
 *
 * Hook: orders:order_notification — appended to CS-Cart's standard
 * order status change email for failed orders with a NETOPIA payment link.
 *}

{if $order_info.payment_info.netopia_payment_link && $order_info.status == "F"}
<table cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-top: 20px;">
    <tr>
        <td style="padding: 15px; background-color: #f8f8f8; border: 1px solid #e5e5e5; border-radius: 4px;">
            <p style="margin: 0 0 10px 0; font-size: 14px;">{__("netopia_retry_payment_message")}</p>
            <a href="{$order_info.payment_info.netopia_payment_link|escape:"html"}"
               style="display: inline-block; padding: 10px 25px; background-color: #4CAF50; color: #ffffff; text-decoration: none; border-radius: 4px; font-size: 14px; font-weight: bold;">
                {__("netopia_pay_now_button")}
            </a>
        </td>
    </tr>
</table>
{/if}
