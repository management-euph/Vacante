{* Customer order page: full order total, deposit paid, the balance still open (deposit bookings only). *}
{$dt_open = fn_travel_core_order_open_balance($order_info)}
{include file="addons/travel_core/components/deposit_totals.tpl" dt_products=$order_info.products dt_charged=$order_info.total dt_order=$dt_open}
