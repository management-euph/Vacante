<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * The package and circuit results pages, and the package booking form:
 *  - both results pages price through ResultPricing, so the struck
 *    "before" price carries the store's commission too, and no supplier
 *    figure ("original" price) goes to the template;
 *  - the package results list only the packages the store can sell, and
 *    the booking form turns one it cannot sell away before the guests are
 *    filled in (add-to-cart refused it only then).
 */
final class PackageResultsWiringTest extends TestCase
{
    private static function read(string $rel): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/' . $rel);
    }

    public function testBothResultsPagesShowTheStoresPricesOnly(): void
    {
        foreach (['package_search', 'circuit_search'] as $mode) {
            $controller = self::read("controllers/frontend/sphinx_booking/{$mode}.php");
            self::assertStringContainsString("\$result['pricing'] = ResultPricing::forShopper(TypeCoerce::toStringMap(\$result['pricing'] ?? null), \$withCommission);", $controller, $mode);
            self::assertStringContainsString('$withCommission = ', $controller, $mode);
            self::assertStringNotContainsString("['original_selling_price']", $controller, $mode);
            self::assertStringNotContainsString("['original_price']", $controller, $mode);
        }
    }

    public function testThePackageResultsListOnlyWhatTheStoreSells(): void
    {
        $controller = self::read('controllers/frontend/sphinx_booking/package_search.php');
        $filter = '$allResults = $cartService->sellablePackages($allResults);';

        self::assertStringContainsString($filter, $controller);
        // After the results are in (from the cache or Sphinx), before they are priced and shown.
        self::assertGreaterThan(strpos($controller, '// Cache raw results'), strpos($controller, $filter));
        self::assertLessThan(strpos($controller, 'ResultPricing::forShopper('), strpos($controller, $filter));
        self::assertLessThan(strpos($controller, "\$view->assign('sphinx_package_results', \$allResults);"), strpos($controller, $filter));
    }

    public function testTheBookingFormTurnsAwayAPackageTheStoreCannotSell(): void
    {
        $form = self::read('controllers/frontend/sphinx_booking/package_booking_form.php');
        $check = 'if ($cartService->resolveProductId(CartService::packageHotelId($verifyResult)) <= 0) {';

        self::assertStringContainsString($check, $form);
        self::assertLessThan(strpos($form, "\$view->assign('sphinx_package_booking', ["), strpos($form, $check), 'before the form is shown');
        self::assertStringContainsString("return [CONTROLLER_STATUS_REDIRECT, 'sphinx_booking.package_search'];", substr($form, (int) strpos($form, $check), 400));
    }
}
