{*
  FGO Invoicing — header of the FGO column on the orders list (orders:manage_header).
  The cells are hooks/orders/manage_data.post.tpl; both hide for restricted
  admins, whom the FGO pages deny, and while a storefront is selected
  ($runtime.company_id): the get_orders_post hook attaches nothing then
  (fn_fgo_invoicing_shows_invoice_data()).
*}
{if !"RESTRICTED_ADMIN"|defined && !$runtime.company_id}
    <th width="10%" class="fgo-invoicing-col">{__("fgo_invoicing.col_fgo")}</th>
{/if}
