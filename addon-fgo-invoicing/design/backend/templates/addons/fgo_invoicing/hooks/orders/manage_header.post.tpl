{*
  FGO Invoicing — header of the FGO column on the orders list (orders:manage_header).
  The cells are hooks/orders/manage_data.post.tpl; both hide for restricted
  admins, whom the FGO pages deny (the get_orders_post hook skips them too).
*}
{if !"RESTRICTED_ADMIN"|defined}
    <th width="10%" class="fgo-invoicing-col">{__("fgo_invoicing.col_fgo")}</th>
{/if}
