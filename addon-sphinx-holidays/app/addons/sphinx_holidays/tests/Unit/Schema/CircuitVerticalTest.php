<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Pins the completed circuit/package vertical and the experiences unroute.
 *
 * History: circuits synced and rendered but could never be SOLD — nothing
 * ever wrote sphinx_circuits.product_id and add-to-cart resolved products
 * via a hotels-only lookup, so every circuit add-to-cart dead-ended on
 * "product not found". Experiences are provider-unsupported and their
 * half-shipped pages must stay unrouted. Package/circuit search pages were
 * orphans with no storefront entry point.
 */
final class CircuitVerticalTest extends TestCase
{
    private static function addonRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function read(string $relative): string
    {
        $path = self::addonRoot() . '/' . $relative;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    private static function designRoot(): string
    {
        return dirname(__DIR__, 6) . '/design/themes/responsive/templates/addons/sphinx_holidays';
    }

    public function testCircuitProductCreationPathIsWired(): void
    {
        // Registration is auto-discovery: the command self-registers via
        // getModes() (CronModeRegistrationTest pins the discovered set).
        $command = self::read('src/Cron/Commands/AddCircuitProductsCommand.php');
        self::assertStringContainsString("['add_circuit_products']", $command);
        self::assertStringContainsString('getCircuitsCategoryId', $command);
        self::assertStringContainsString('findUnlinked', $command);

        $factory = self::read('src/Helpers/CircuitProductFactory.php');
        // SPXC codes cannot collide with the hotels' <country-code><id> scheme.
        self::assertStringContainsString("PRODUCT_CODE_PREFIX = 'SPXC'", $factory);
        self::assertStringContainsString('linkToProduct', $factory);
        self::assertStringContainsString('fn_update_product', $factory);

        // Admin dashboard lists the cron URL.
        $backend = self::read('controllers/backend/sphinx_holidays.php');
        self::assertStringContainsString('cron_mode=add_circuit_products', $backend);
    }

    public function testCircuitAddToCartResolvesViaTheCircuitLink(): void
    {
        $cartService = self::read('src/Services/CartService.php');
        self::assertStringContainsString('public function resolveCircuitProductId(', $cartService);
        self::assertStringContainsString('FROM ?:sphinx_circuits WHERE circuit_id = ?i', $cartService);

        $controller = self::read('controllers/frontend/sphinx_booking/circuit_add_to_cart.php');
        self::assertStringContainsString('resolveCircuitProductId($circuit_id', $controller);
        // The hotels-only fallback must be gone from the circuit flow.
        self::assertStringNotContainsString('resolveProductId((string) $circuit_id', $controller);
    }

    public function testExperiencePagesStayUnrouted(): void
    {
        $dispatcher = self::read('controllers/frontend/sphinx_booking.php');

        self::assertStringNotContainsString("'experience_search'", $dispatcher);
        self::assertStringNotContainsString("'experience_booking_form'", $dispatcher);
        self::assertStringNotContainsString("'experience_add_to_cart'", $dispatcher);

        $modeDir = self::addonRoot() . '/controllers/frontend/sphinx_booking';
        self::assertFileDoesNotExist($modeDir . '/experience_search.php');
        self::assertFileDoesNotExist($modeDir . '/experience_booking_form.php');
        self::assertFileDoesNotExist($modeDir . '/experience_add_to_cart.php');

        self::assertFileDoesNotExist(self::designRoot() . '/views/sphinx_booking/experience_search.tpl');
        self::assertFileDoesNotExist(self::designRoot() . '/views/sphinx_booking/experience_booking_form.tpl');
    }

    public function testStorefrontEntryPointsExist(): void
    {
        $blocks = self::read('schemas/block_manager/blocks.post.php');
        self::assertStringContainsString("\$schema['sphinx_package_search']", $blocks);

        // The package form posts the two params package_search requires and
        // feeds its selects from the synced route cache.
        $pkgTpl = (string) file_get_contents(self::designRoot() . '/blocks/package_search.tpl');
        self::assertStringContainsString('value="sphinx_booking.package_search"', $pkgTpl);
        self::assertStringContainsString('name="destination_id" required', $pkgTpl);
        self::assertStringContainsString('name="departure_date" required', $pkgTpl);
        self::assertStringContainsString('type=package_routes', $pkgTpl);

        // cache_deals serves only the package form's route options now (local
        // data, no provider API call): the Best Deals block it also fed is gone.
        $cacheDeals = self::read('controllers/frontend/sphinx_booking/cache_deals.php');
        self::assertStringContainsString("'package_routes'", $cacheDeals);
        self::assertStringContainsString('getRouteOptions', $cacheDeals);
        self::assertStringNotContainsString('CacheEndpointService', $cacheDeals);
        self::assertStringNotContainsString('findSellable', $cacheDeals);
    }

    public function testRouteOptionsComeFromTheSyncedCache(): void
    {
        $repo = self::read('src/Repository/PackageRouteRepository.php');

        self::assertStringContainsString('public function getRouteOptions(): array', $repo);
        self::assertStringContainsString('DISTINCT transport_type FROM ?:sphinx_package_routes', $repo);
        self::assertStringContainsString('departure_id AS id, departure_name AS name', $repo);
        self::assertStringContainsString('arrival_id AS id, arrival_name AS name', $repo);
    }
}
