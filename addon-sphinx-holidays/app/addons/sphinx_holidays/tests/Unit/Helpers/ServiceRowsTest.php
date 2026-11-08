<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Helpers;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Helpers\ServiceRows;

/**
 * The package booking form listed each additional service with
 * pricing.selling_price as Sphinx sent it: a service without a price raised
 * "number_format(): Passing null to parameter #1" on every row, and a priced
 * one showed the supplier's figure, without the store's commission.
 */
final class ServiceRowsTest extends TestCase
{
    public function testEachServiceWithTheStoresPriceOrNone(): void
    {
        $rows = ServiceRows::forShopper([
            ['code' => '123', 'title' => 'Asigurare storno', 'description' => 'd', 'type' => 'optional', 'selected' => false,
                'pricing' => ['selling_price' => 72, 'supplier_price' => 60, 'currency' => 'EUR']],
            ['code' => '456', 'title' => 'Taxa de aeroport', 'type' => 'mandatory', 'selected' => true, 'pricing' => ['selling_price' => null, 'currency' => 'EUR']],
            ['code' => '789', 'title' => 'Fără preț'],
        ], static fn (float $price): float => $price * 1.1);

        self::assertSame(
            ['code' => '123', 'title' => 'Asigurare storno', 'description' => 'd', 'type' => 'optional', 'selected' => false, 'price' => 79.2, 'currency' => 'EUR'],
            $rows[0],
            'the store\'s price, never the supplier\'s',
        );
        self::assertSame([0.0, true, 'mandatory'], [$rows[1]['price'], $rows[1]['selected'], $rows[1]['type']], 'no price: 0, the form shows none');
        self::assertSame([0.0, ''], [$rows[2]['price'], $rows[2]['currency']]);
        self::assertSame([], ServiceRows::forShopper(null, static fn (float $p): float => $p));
    }

    public function testTheFormsPrintOnlyAPrice(): void
    {
        foreach (['package_booking_form', 'circuit_booking_form'] as $form) {
            $template = (string) file_get_contents(dirname(__DIR__, 6) . "/design/themes/responsive/templates/addons/sphinx_holidays/views/sphinx_booking/{$form}.tpl");
            self::assertStringContainsString('{if $service.price > 0}+{$service.price|number_format:2:",":"."} {$service.currency}{/if}', $template, $form);
            self::assertStringNotContainsString('service.pricing', $template, $form);
            $controller = (string) file_get_contents(dirname(__DIR__, 3) . "/controllers/frontend/sphinx_booking/{$form}.php");
            self::assertStringContainsString('ServiceRows::forShopper(', $controller, $form);
        }
    }
}
