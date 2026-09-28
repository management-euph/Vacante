{**
 * NETOPIA Payments — "Send payment link" items in the gear icon dropdown.
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
    {* The dialog target div MUST be in the DOM at the moment cm-dialog-opener
       fires; if it isn't, CS-Cart's fallback path AJAX-loads the link's href
       and renders an unrelated 404 page inside the dialog wrapper. We render
       the modal as a sibling of the trigger anchor inside this same <li>
       (a known-working hook). The browser tolerates a <div class="hidden">
       inside an <li>; the menu layout is unaffected because the div is
       display:none. *}
    <a class="cm-combination cm-dialog-opener" data-ca-target-id="netopia_refund_dialog" href="javascript:void(0);">
        {__("netopia_refund_action")}
    </a>

    <div class="hidden" id="netopia_refund_dialog" title="{__("netopia_refund_action")}">
        {* Bake the dispatch into the form's action URL — the standard CS-Cart
           admin pattern. The previous `{""|fn_url}` + dispatch-as-button-name
           combination silently rerouted to admin index when the dispatcher
           failed to extract the array key. *}
        <form action="{"netopia_refund.process"|fn_url}" method="post" name="netopia_refund_form">
            <input type="hidden" name="order_id" value="{$order_info.order_id}" />
            <input type="hidden" name="security_hash" value="{$runtime.security_hash|default:""}" />

            <div class="control-group">
                <p>
                    <strong>{__("netopia_refund_paid_amount")}:</strong>
                    {$netopia_refund_paid_display|escape:"html"}
                </p>
                {if $netopia_refund_already_refunded > 0}
                <p>
                    <strong>{__("netopia_refund_already_refunded_label")}:</strong>
                    {$netopia_refund_already_refunded_display|escape:"html"}
                </p>
                {/if}
                <p>
                    <strong>{__("netopia_refund_remaining")}:</strong>
                    {$netopia_refund_remaining_display|escape:"html"}
                </p>
            </div>

            {if $netopia_refund_history}
            <fieldset>
                <legend>{__("netopia_refund_history_title")}</legend>
                <table class="table table-condensed">
                    <thead>
                        <tr>
                            <th>{__("netopia_refund_history_date")}</th>
                            <th>{__("netopia_refund_history_amount")}</th>
                            <th>{__("netopia_refund_history_kind")}</th>
                            <th>{__("netopia_refund_history_origin")}</th>
                            <th>{__("netopia_refund_history_ntp_id")}</th>
                            <th>{__("netopia_refund_history_payment_id")}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {foreach from=$netopia_refund_history item="row"}
                        <tr>
                            <td>{$row.date|escape:"html"}</td>
                            <td>{$row.amount|escape:"html"}</td>
                            <td>{if $row.kind == "full"}{__("netopia_refund_full")}{else}{__("netopia_refund_partial")}{/if}</td>
                            <td>{$row.origin|escape:"html"}</td>
                            <td><code>{$row.ntp_id|escape:"html"}</code></td>
                            <td><code>{$netopia_refund_history_payment_id|escape:"html"}</code></td>
                        </tr>
                        {/foreach}
                    </tbody>
                </table>
            </fieldset>
            {/if}

            {if $netopia_refund_can_submit}
            <div class="control-group">
                <label class="control-label" for="netopia_refund_amount">
                    {__("netopia_refund_amount")} ({$netopia_refund_currency|escape:"html"}):
                </label>
                <div class="controls">
                    <input type="text"
                           name="amount"
                           id="netopia_refund_amount"
                           value="{$netopia_refund_remaining|string_format:"%.2f"}"
                           size="12"
                           autocomplete="off" />
                    <span class="muted">{$netopia_refund_currency|escape:"html"}</span>
                    <p class="muted description">{__("netopia_refund_amount_help_currency", ['[currency]' => $netopia_refund_currency])}</p>
                </div>
            </div>

            <div class="buttons-container">
                {include file="buttons/save_cancel.tpl"
                    but_name="dispatch[netopia_refund.process]"
                    but_text=__("netopia_refund_submit")
                    cancel_action="close"}
            </div>
            {else}
            {* Fully-refunded orders: keep the modal accessible (admin can
               still review paid/refunded amounts and the history table
               above) but no further refunds are possible. The dialog's
               built-in X close icon is the way out — no submit button
               renders, so a stray click can't fire a doomed-to-fail
               operation/credit POST. *}
            <div class="control-group">
                <p class="muted"><em>{__("netopia_refund_fully_refunded_notice")}</em></p>
            </div>
            {/if}
        </form>
    </div>
    {literal}
    <script>
    // Disable the refund submit button on first click so an impatient admin
    // cannot fire two refund POSTs for the same intent. The deterministic
    // refundRequestId on the server side is the durable safety net; this is
    // the cheap UX-level guard.
    (function () {
        var form = document.querySelector('form[name="netopia_refund_form"]');
        if (!form) return;
        form.addEventListener('submit', function () {
            var btn = form.querySelector('[name="dispatch[netopia_refund.process]"]');
            if (btn) {
                btn.disabled = true;
                btn.classList.add('disabled');
            }
        }, { once: true });
    })();
    </script>
    {/literal}
</li>
{/if}
