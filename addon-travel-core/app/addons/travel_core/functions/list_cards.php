<?php

declare(strict_types=1);

/**
 * Travel Core — the hotel list card (board 15) on CS-Cart's own list pages.
 *
 * A category page and the store's product search draw their products with
 * CS-Cart's list views; for every product a supplier owns, the Travel Core
 * list view (blocks/product_list_templates/travelcore_cards.tpl) draws the
 * card instead, from $travel_list_cards (product id => card, see
 * ViewModels\ListCardFactory) and, under it, "Load N more" from
 * $travel_list_more (ViewModels\ListLoadMore). Stored data only: no
 * supplier call.
 *
 * @package TravelCore
 */

use Tygh\Addons\TravelCore\ViewModels\ListCardFactory;
use Tygh\Addons\TravelCore\ViewModels\ListLoadMore;

defined('BOOTSTRAP') or die('Access denied');

/** The list pages whose products get the card. */
const TRAVEL_CORE_LIST_DISPATCHES = ['categories.view', 'products.search'];

/**
 * Assign $travel_list_cards for the products the current list page shows
 * (called from dispatch_before_display, after the controller set them).
 */
function fn_travel_core_assign_list_cards(string $dispatch): void
{
    if (!in_array($dispatch, TRAVEL_CORE_LIST_DISPATCHES, true)) {
        return;
    }
    $view = \Tygh\Tygh::$app['view'];
    if (!is_object($view) || !method_exists($view, 'getTemplateVars') || !method_exists($view, 'assign')) {
        return;
    }
    $products = $view->getTemplateVars('products');
    if (!is_array($products) || $products === []) {
        return;
    }
    $cards = (new ListCardFactory())->forProducts($products);
    $view->assign('travel_list_cards', $cards);
    $view->assign('travel_list_more', $cards !== [] ? ListLoadMore::from($view->getTemplateVars('search')) : null);
}
