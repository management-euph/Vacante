<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Hooks;

use Tygh\Addons\SphinxHolidays\Repository\CircuitRepository;
use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Product-lifecycle hook bodies. The func.php shell
 * (fn_sphinx_holidays_delete_product_post) delegates here.
 */
final class ProductHooks
{
    /**
     * A deleted product leaves its hotel or circuit unlinked, so the next
     * "Create Products" makes it a new one. Without this the row kept the
     * deleted id: counted as linked, never given a product again.
     *
     * @param mixed $productId from the hook (untyped)
     * @param mixed $deleted whether CS-Cart deleted it
     */
    public static function unlinkDeleted(mixed $productId, mixed $deleted): void
    {
        $id = TypeCoerce::toInt($productId);
        if (!TypeCoerce::toBool($deleted) || $id <= 0) {
            return;
        }

        try {
            Container::getHotelRepository()->unlinkProduct($id);
            (new CircuitRepository())->unlinkProduct($id);
        } catch (\Throwable) {
            // During the add-on's uninstall its tables may already be gone.
        }
    }
}
