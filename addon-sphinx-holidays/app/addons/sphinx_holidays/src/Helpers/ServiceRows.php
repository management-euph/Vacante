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

    /**
     * The same rows with the shopper's money: each price as the page's total
     * is shown (the store's primary currency, then the shopper's), as a number
     * the booking page adds to the total when the service is ticked
     * (display_amount), and as text ("+196 €", display_price; '' without a
     * price). The forms printed "+196,00 EUR" beside a total of "1.240 €".
     *
     * @param list<array{code: string, title: string, description: string, type: string, selected: bool, price: float, currency: string}> $rows
     * @param \Closure(float): float $toDisplay a price in the API currency, in the shopper's
     * @param \Closure(float): string $format a shopper's amount, as the store prints it
     * @return list<array{code: string, title: string, description: string, type: string, selected: bool, price: float, currency: string, display_amount: float, display_price: string}>
     */
    public static function withDisplay(array $rows, \Closure $toDisplay, \Closure $format): array
    {
        $out = [];
        foreach ($rows as $row) {
            $amount = $row['price'] > 0 ? round($toDisplay($row['price']), 2) : 0.0;
            $row['display_amount'] = $amount;
            $row['display_price'] = $amount > 0 ? '+' . $format($amount) : '';
            $out[] = $row;
        }

        return $out;
    }
}
