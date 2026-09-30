<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Services\ProviderCartProduct;

/**
 * Add-to-cart used to trust the product_id the browser sent: any store
 * product, active or not, could carry a hotel booking. The product must now
 * be that hotel's own product in the provider's table, and buyable.
 */
final class ProviderCartProductTest extends TestCase
{
    /** @param array<int, string> $statuses */
    private static function resolve(int $requested, int $owned, array $statuses, bool $gateHidden = false): int
    {
        return ProviderCartProduct::resolve($requested, $owned, static fn (int $id): string => $statuses[$id] ?? '', $gateHidden);
    }

    public function testTheHotelsOwnActiveProductIsUsed(): void
    {
        self::assertSame(41, self::resolve(0, 41, [41 => 'A']));
        self::assertSame(41, self::resolve(41, 41, [41 => 'A']), 'the request may name the same product');
    }

    public function testAnotherProductInTheRequestIsRefused(): void
    {
        // Any other store product — another hotel's, another supplier's, a t-shirt.
        self::assertSame(0, self::resolve(99, 41, [41 => 'A', 99 => 'A']));
    }

    public function testAHotelWithoutAProductOfThisSupplierIsRefused(): void
    {
        self::assertSame(0, self::resolve(0, 0, []));
        self::assertSame(0, self::resolve(99, 0, [99 => 'A']), 'the request cannot supply one');
    }

    public function testOnlyActiveOrGateHiddenProductsCanBeBought(): void
    {
        self::assertSame(0, self::resolve(0, 41, [41 => 'D']), 'disabled');
        self::assertSame(0, self::resolve(0, 41, []), 'deleted');
        self::assertSame(0, self::resolve(0, 41, [41 => 'H']), 'hidden by an admin');
        self::assertSame(41, self::resolve(0, 41, [41 => 'H'], true), 'hidden by the availability gate');
    }
}
