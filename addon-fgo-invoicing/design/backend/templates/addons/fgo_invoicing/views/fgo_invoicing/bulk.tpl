{*
  FGO Invoicing — bulk pre-check, then progress and results, on one page
  (controllers/backend/fgo_invoicing.php, mode=bulk; $fgo_bulk).

  Everything the page script (js/addons/fgo_invoicing/bulk.js) needs travels
  in data-ca-fgo-* attributes of #fgo_bulk: no inline script block, which
  CS-Cart rewrites. The script runs the ticked orders one at a time through
  fgo_invoicing.bulk_run, which pre-checks each order again server-side.
*}
{capture name="mainbox"}
{$b = $fgo_bulk}
{$today = $smarty.const.TIME|date_format:$settings.Appearance.date_format}

<div class="fgo-bulk" id="fgo_bulk"
     data-ca-fgo-bulk="true"
     data-ca-fgo-action="{$b.action}"
     data-ca-fgo-run-url="{"fgo_invoicing.bulk_run"|fn_url}"
     data-ca-fgo-zip-url="{"fgo_invoicing.m_download_pdfs"|fn_url}"
     data-ca-fgo-retry-url="{"fgo_invoicing.bulk?action=`$b.retry_action`"|fn_url}"
     data-ca-fgo-interval="{$b.interval_ms}"
     data-ca-fgo-zip="{if $b.zip_enabled}Y{else}N{/if}"
     data-ca-fgo-i18n="{$b.i18n_json}"
>

    <div class="alert {if $b.sandbox}alert-warning{else}alert-error{/if} fgo-bulk__env">
        <strong>{if $b.sandbox}{__("fgo_invoicing.env_sandbox")}{else}{__("fgo_invoicing.env_production")}{/if}</strong>
        &middot; <code>{$b.api_host}</code> &middot;
        {if $b.sandbox}{__("fgo_invoicing.banner_sandbox")}{else}{__("fgo_invoicing.banner_production")}{/if}
    </div>

    {if $b.truncated}
        <div class="alert alert-warning">{__("fgo_invoicing.bulk_truncated", ["[selected]" => $b.selected_total, "[max]" => $b.max_batch])}</div>
    {/if}
    {if $b.missing}
        <div class="alert alert-warning">{__("fgo_invoicing.bulk_missing", ["[count]" => $b.missing])}</div>
    {/if}

    <p class="fgo-bulk__intro">{__("fgo_invoicing.bulk_intro", ["[count]" => $b.summary.total])}</p>

    <div class="fgo-bulk__chips" data-fgo-precheck-chips>
        <span class="fgo-bulk__chip fgo-bulk__chip--ready" data-fgo-chip="to_process">{__("fgo_invoicing.chip_to_process", ["[count]" => $b.summary.to_process])}</span>
        <span class="fgo-bulk__chip fgo-bulk__chip--skip">{__("fgo_invoicing.chip_skipped", ["[count]" => $b.summary.skipped])}</span>
        {if $b.summary.with_warnings}
            <span class="fgo-bulk__chip fgo-bulk__chip--warn">{__("fgo_invoicing.chip_warnings", ["[count]" => $b.summary.with_warnings])}</span>
        {/if}
        {if $b.summary.blocked}
            <span class="fgo-bulk__chip fgo-bulk__chip--block">{__("fgo_invoicing.chip_blocked", ["[count]" => $b.summary.blocked])}</span>
        {/if}
    </div>

    {if $b.issues}
        <dl class="fgo-bulk__settings">
            <div>
                <dt>{__("fgo_invoicing.snap_document_type")}</dt>
                <dd>{$b.settings.document_type}</dd>
            </div>
            <div>
                <dt>{__("fgo_invoicing.snap_series")}</dt>
                <dd>{if $b.settings.series}{$b.settings.series}{else}{__("fgo_invoicing.snap_series_default")}{/if}</dd>
            </div>
            <div>
                <dt>{__("fgo_invoicing.snap_issue_date")}</dt>
                <dd>{__("fgo_invoicing.snap_today", ["[date]" => $today])}</dd>
            </div>
            <div>
                <dt>{__("fgo_invoicing.snap_currency")}</dt>
                <dd>{__("fgo_invoicing.snap_currency_primary", ["[currency]" => $b.settings.currency])}</dd>
            </div>
        </dl>
    {/if}

    <div class="table-responsive-wrapper">
        <table width="100%" class="table table-middle table--relative table-responsive fgo-bulk__table">
            <thead>
                <tr>
                    <th width="1%" class="center">
                        <input type="checkbox" data-fgo-select-all
                               title="{__("fgo_invoicing.select_all")}" aria-label="{__("fgo_invoicing.select_all")}"
                               {if !$b.summary.actionable}disabled="disabled"{/if} />
                    </th>
                    <th width="12%">{__("fgo_invoicing.th_order")}</th>
                    <th width="22%">{__("fgo_invoicing.th_customer")}</th>
                    <th width="6%">{__("fgo_invoicing.th_type")}</th>
                    <th width="10%" class="right">{__("fgo_invoicing.th_total")}</th>
                    <th>{__("fgo_invoicing.th_precheck")}</th>
                    <th width="24%" class="hidden" data-fgo-result-head>{__("fgo_invoicing.th_result")}</th>
                </tr>
            </thead>
            <tbody>
            {foreach $b.rows as $row}
                <tr class="fgo-bulk__row fgo-bulk__row--{$row.verdict}"
                    data-fgo-row
                    data-fgo-order-id="{$row.order_id}"
                    data-fgo-verdict="{$row.verdict}"
                    data-fgo-actionable="{if $row.actionable}Y{else}N{/if}"
                    data-fgo-has-invoice="{if $row.invoice_status == "issued" && $row.pdf_link}Y{else}N{/if}"
                >
                    <td class="center">
                        <input type="checkbox" value="{$row.order_id}" data-fgo-select
                               aria-label="{__("fgo_invoicing.th_order")} #{$row.order_id}"
                               {if $row.selected}checked="checked"{/if}
                               {if !$row.actionable}disabled="disabled"{/if} />
                    </td>
                    <td data-th="{__("fgo_invoicing.th_order")}">
                        <a href="{"orders.details?order_id=`$row.order_id`"|fn_url}">#{$row.order_id}</a>
                        {if $row.order_status}
                            <div class="muted">{$b.statuses[$row.order_status]|default:$row.order_status}</div>
                        {/if}
                    </td>
                    <td data-th="{__("fgo_invoicing.th_customer")}">
                        {$row.customer_name|default:"—"}
                        {if $row.email}<div class="muted">{$row.email}</div>{/if}
                    </td>
                    <td data-th="{__("fgo_invoicing.th_type")}">
                        {if $row.client_type == "PJ"}
                            <span title="{__("fgo_invoicing.type_pj")}">PJ</span>
                        {elseif $row.client_type == "PF"}
                            <span title="{__("fgo_invoicing.type_pf")}">PF</span>
                        {else}
                            <span class="muted">&mdash;</span>
                        {/if}
                    </td>
                    <td class="right" data-th="{__("fgo_invoicing.th_total")}">
                        {include file="common/price.tpl" value=$row.total}
                    </td>
                    <td data-th="{__("fgo_invoicing.th_precheck")}" data-fgo-precheck>
                        <span class="fgo-bulk__verdict fgo-bulk__verdict--{$row.verdict}">
                            <span class="fgo-bulk__icon" aria-hidden="true">{if $row.verdict == "ready"}&#10003;{elseif $row.verdict == "retry"}&#8635;{elseif $row.verdict == "warn"}!{elseif $row.verdict == "block"}&#10005;{else}&ndash;{/if}</span>
                            {$row.verdict_label}
                        </span>
                        {foreach $row.lines as $line}
                            <div class="fgo-bulk__reason fgo-bulk__reason--{$line.level}">{$line.text}</div>
                        {/foreach}
                    </td>
                    <td class="hidden" data-th="{__("fgo_invoicing.th_result")}" data-fgo-result></td>
                </tr>
            {/foreach}
            </tbody>
        </table>
    </div>

    <div data-fgo-options>
        {if $b.issues}
            <label class="checkbox fgo-bulk__option" for="fgo_bulk_send_email">
                <input type="checkbox" id="fgo_bulk_send_email" value="Y" data-fgo-email {if $b.email_default}checked="checked"{/if} />
                {__("fgo_invoicing.bulk_email_option")}
            </label>
        {/if}
        <p class="muted fgo-bulk__note">{__("fgo_invoicing.bulk_footer_note")}</p>
    </div>

    <div class="fgo-bulk__actions" data-fgo-precheck-actions>
        <a class="btn" href="{"orders.manage"|fn_url}">{__("cancel")}</a>
        <button type="button" class="btn btn-primary" data-fgo-start {if !$b.summary.to_process}disabled="disabled"{/if}>{$b.start_label}</button>
    </div>

    <div class="fgo-bulk__progress hidden" data-fgo-progress>
        <div class="fgo-bulk__bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" data-fgo-progress-bar>
            <span class="fgo-bulk__bar-fill" style="width: 0%;" data-fgo-progress-fill></span>
        </div>
        <p class="fgo-bulk__progress-label" aria-live="polite" data-fgo-progress-label></p>
        <div class="fgo-bulk__chips" data-fgo-run-chips></div>
        <p class="muted fgo-bulk__note">{__("fgo_invoicing.bulk_keep_open")}</p>
        <div class="fgo-bulk__actions">
            <button type="button" class="btn" data-fgo-stop>{__("fgo_invoicing.btn_stop")}</button>
        </div>
    </div>

    <div class="fgo-bulk__actions hidden" data-fgo-finished>
        <button type="button" class="btn hidden" data-fgo-zip>{__("fgo_invoicing.menu_download_pdfs")}</button>
        <button type="button" class="btn hidden" data-fgo-retry></button>
        <a class="btn" href="{"fgo_invoicing.manage"|fn_url}">{__("fgo_invoicing.menu_open_log")}</a>
        <a class="btn btn-primary" href="{"orders.manage"|fn_url}">{__("fgo_invoicing.btn_back_to_orders")}</a>
    </div>
</div>

{* Inside the capture on purpose: the admin reaches this page by AJAX
   navigation too, and that response carries only the mainbox. *}
{script src="js/addons/fgo_invoicing/bulk.js"}
{/capture}
{include file="common/mainbox.tpl" title=$fgo_bulk.title content=$smarty.capture.mainbox}
