<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Helpers;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * An offer's additional services as the package and circuit booking forms
 * list them: each with the store's price (commission included), never the
 * supplier's own figure, and 0 when the supplier gives none (the form then
 * shows no price). The forms printed pricing.selling_price as it came: a
 * service without one raised "number_format(): Passing null" on each row.
 */
final class ServiceRows
{
    /**
     * @param mixed $services the offer's additional_services
     * @param \Closure(float): float $withCommission the store's selling price for a supplier price
     *
     * @return list<array{code: string, title: string, description: string, type: string, selected: bool, price: float, currency: string}>
     */
    public static function forShopper(mixed $services, \Closure $withCommission): array
    {
        $rows = [];
        foreach (TypeCoerce::toRowList($services) as $service) {
            $pricing = TypeCoerce::toStringMap($service['pricing'] ?? null);
            $supplierPrice = TypeCoerce::toFloat($pricing['selling_price'] ?? 0);
            $rows[] = [
                'code' => TypeCoerce::toString($service['code'] ?? ''),
                'title' => TypeCoerce::toString($service['title'] ?? ''),
                'description' => TypeCoerce::toString($service['description'] ?? ''),
                'type' => TypeCoerce::toString($service['type'] ?? ''),
                'selected' => ($service['selected'] ?? false) === true,
                'price' => $supplierPrice > 0 ? round($withCommission($supplierPrice), 2) : 0.0,
                'currency' => TypeCoerce::toString($pricing['currency'] ?? ''),
            ];
        }

        return $rows;
    }
}
