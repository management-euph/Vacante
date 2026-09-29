{**
 * NETOPIA Payments — the order's NETOPIA panel (hook orders:details).
 *
 * One place for the card payment: paid / refunded / still refundable on a
 * bar, the refund history, the refund form, and the payment-link actions
 * while the order is unpaid. Variables come from controllers/backend/orders.post.php.
 *
 * The refund asks before it sends: the submit button sits inside a
 * <details> that works without script; js/addons/netopia_payments/order_panel.js
 * keeps its label in step with the amount typed and stops double submits.
 *}
{if $netopia_payment_link_available}
{literal}
<style>
.netopia-order { --np-ok:#2d7d46; --np-warn:#c9a13b; --np-line:#dfe3e8; --np-soft:#f6f8fa; --np-muted:#6b7580; }
.netopia-order__amounts { display:grid; grid-template-columns:repeat(3, minmax(0, 1fr)); gap:8px; margin-bottom:8px; font-variant-numeric:tabular-nums; }
.netopia-order__amounts div { background:var(--np-soft); border-radius:4px; padding:8px 10px; }
.netopia-order__amounts small { display:block; color:var(--np-muted); }
.netopia-order__amounts b { font-size:15px; }
.netopia-order__bar { height:8px; border-radius:4px; background:var(--np-ok); overflow:hidden; margin-bottom:12px; }
.netopia-order__bar i { display:block; height:100%; background:var(--np-warn); }
.netopia-order__form { display:flex; flex-wrap:wrap; gap:8px; align-items:flex-end; }
.netopia-order__form input[type=text] { margin:0; width:110px; }
.netopia-order details > summary { display:inline-block; cursor:pointer; list-style:none; }
.netopia-order details > summary::-webkit-details-marker { display:none; }
.netopia-order__confirm { margin-top:8px; padding:9px 12px; border-radius:4px; background:#fcf1dc; border:1px solid #f0d9a8; max-width:520px; }
.netopia-order__confirm .btn { margin-top:6px; margin-right:6px; }
.netopia-order__links { display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin-top:12px; padding-top:12px; border-top:1px solid var(--np-line); }
.netopia-order__links form { margin:0; }
.netopia-order table { margin-top:8px; }
</style>
{/literal}
{script src="js/addons/netopia_payments/order_panel.js"}

<div class="object-group netopia-order" id="netopia_panel">
    <h4>{__("netopia_order_panel_title")}</h4>

    {if $netopia_refund_available}
        <div class="netopia-order__amounts">
            <div><small>{__("netopia_refund_paid_amount")}</small><b>{$netopia_refund_paid_display|escape:"html"}</b></div>
            <div><small>{__("netopia_refund_already_refunded_label")}</small><b>{$netopia_refund_already_refunded_display|escape:"html"}</b></div>
            <div><small>{__("netopia_refund_remaining")}</small><b>{$netopia_refund_remaining_display|escape:"html"}</b></div>
        </div>
        <div class="netopia-order__bar" role="img" aria-label="{__("netopia_refund_bar_label", ["[pct]" => $netopia_refund_refunded_pct])|escape:"html"}"><i style="width:{$netopia_refund_refunded_pct}%"></i></div>

        {if $netopia_refund_history}
            <strong>{__("netopia_refund_history_title")}</strong>
            <table class="table table-condensed">
                <thead>
                    <tr>
                        <th>{__("netopia_refund_history_date")}</th>
                        <th>{__("netopia_refund_history_amount")}</th>
                        <th>{__("netopia_refund_history_kind")}</th>
                        <th>{__("netopia_refund_history_origin")}</th>
                        <th>{__("netopia_refund_history_ntp_id")}</th>
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
                    </tr>
                    {/foreach}
                </tbody>
            </table>
            {if $netopia_refund_history_payment_id}
                <p class="muted">{__("netopia_refund_history_payment_id")}: <code>{$netopia_refund_history_payment_id|escape:"html"}</code></p>
            {/if}
        {/if}

        {if $netopia_refund_can_submit}
            <form action="{"netopia_refund.process"|fn_url}" method="post" name="netopia_refund_form" class="netopia-order__refund"
                  data-np-remaining="{$netopia_refund_remaining|string_format:"%.2f"}"
                  data-np-currency="{$netopia_refund_currency|escape:"html"}"
                  data-txt-button="{__("netopia_refund_button", ["[amount]" => '{amount}'])|escape:"html"}"
                  data-txt-confirm="{__("netopia_refund_confirm_title", ["[amount]" => '{amount}'])|escape:"html"}"
                  data-txt-full="{__("netopia_refund_confirm_full")|escape:"html"}"
                  data-txt-partial="{__("netopia_refund_confirm_partial")|escape:"html"}"
                  data-txt-too-much="{__("netopia_refund_amount_exceeds_remaining_short", ["[amount]" => $netopia_refund_remaining_display])|escape:"html"}">
                <input type="hidden" name="order_id" value="{$order_info.order_id}" />
                <input type="hidden" name="security_hash" value="{$runtime.security_hash|default:""}" />
                <div class="netopia-order__form">
                    <div>
                        <label for="netopia_refund_amount">{__("netopia_refund_amount")} ({$netopia_refund_currency|escape:"html"})</label>
                        <input type="text" name="amount" id="netopia_refund_amount" value="{$netopia_refund_remaining|string_format:"%.2f"}" autocomplete="off" inputmode="decimal" />
                        <button type="button" class="btn" data-np-refund-all>{__("netopia_refund_all")}</button>
                    </div>
                </div>
                <p class="muted description">{__("netopia_refund_amount_help_currency", ["[currency]" => $netopia_refund_currency])}</p>
                <p class="text-error" data-np-refund-error hidden></p>
                <details data-np-refund-review>
                    <summary class="btn btn-primary" data-np-refund-open>{__("netopia_refund_button", ["[amount]" => $netopia_refund_remaining_display])}</summary>
                    <div class="netopia-order__confirm">
                        <b data-np-refund-confirm-title>{__("netopia_refund_confirm_title", ["[amount]" => $netopia_refund_remaining_display])}</b>
                        <div class="muted" data-np-refund-confirm-text>{__("netopia_refund_confirm_full")}</div>
                        <button type="submit" class="btn btn-primary" name="dispatch[netopia_refund.process]">{__("netopia_refund_submit")}</button>
                        <button type="button" class="btn" data-np-refund-cancel>{__("netopia_refund_keep")}</button>
                    </div>
                </details>
            </form>
        {else}
            <p class="muted"><em>{__("netopia_refund_fully_refunded_notice")}</em></p>
        {/if}
    {else}
        <p class="muted">{__("netopia_order_refund_unavailable")}</p>
    {/if}

    {if $netopia_payment_link_allowed}
        <div class="netopia-order__links">
            <span class="muted">{__("netopia_order_payment_link_hint")}</span>
            <form action="{"netopia_payment_link.send"|fn_url}" method="post">
                <input type="hidden" name="order_id" value="{$order_info.order_id}" />
                <input type="hidden" name="security_hash" value="{$runtime.security_hash|default:""}" />
                <button type="submit" class="btn btn-primary" name="dispatch[netopia_payment_link.send]">{__("netopia_payment_link_send_email")}</button>
            </form>
            <form action="{"netopia_payment_link.generate"|fn_url}" method="post">
                <input type="hidden" name="order_id" value="{$order_info.order_id}" />
                <input type="hidden" name="security_hash" value="{$runtime.security_hash|default:""}" />
                <button type="submit" class="btn" name="dispatch[netopia_payment_link.generate]">{__("netopia_payment_link_generate_only")}</button>
            </form>
        </div>
        {if $order_info.payment_info.netopia_payment_link}
            <p class="muted">{__("netopia_payment_link")}: <a href="{$order_info.payment_info.netopia_payment_link|escape:"html"}" target="_blank" rel="noopener">{$order_info.payment_info.netopia_payment_link|truncate:60:"…":true|escape:"html"}</a>
            {if $order_info.payment_info.netopia_payment_link_at} · {$order_info.payment_info.netopia_payment_link_at|escape:"html"}{/if}</p>
        {/if}
    {/if}
</div>
{/if}
