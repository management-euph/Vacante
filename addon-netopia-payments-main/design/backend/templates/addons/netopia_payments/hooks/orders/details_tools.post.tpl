{**
 * NETOPIA Payments — "Send payment link" and "Refund" items in the gear icon dropdown.
 *
 * Hook: orders:details_tools — appends <li> items to the order actions dropdown.
 * Only shown for orders using the NETOPIA Payments processor.
 * Detection: checks payment_info for netopia_ntp_id (set during payment).
 *}

{if $order_info.payment_info.netopia_payment_link || $netopia_payment_link_available}
{if $order_info.status == "F" || $order_info.status == "O" || $order_info.status == "I" || $order_info.status == "N" || $order_info.status == "D"}
<li class="divider"></li>
<li>
    {btn type="list"
        text=__("netopia_payment_link_send_email")
        href="netopia_payment_link.send?order_id=`$order_info.order_id`"
        method="POST"
    }
</li>
<li>
    {btn type="list"
        text=__("netopia_payment_link_generate_only")
        href="netopia_payment_link.generate?order_id=`$order_info.order_id`"
        method="POST"
    }
</li>
{/if}
{/if}

{if $netopia_refund_available}
<li class="divider"></li>
<li>
    {* The refund form lives in the NETOPIA panel on the page
       (hooks/orders/details.post.tpl); this item takes you there. *}
    <a href="#netopia_panel">{__("netopia_refund_action")}</a>
</li>
{/if}
