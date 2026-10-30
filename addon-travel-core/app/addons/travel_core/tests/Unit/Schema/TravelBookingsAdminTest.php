<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Pins the unified Travel Bookings admin pages (travel_bookings.manage/view):
 *
 * 1. Labels come from travel_core.* language keys. The templates once called
 *    bare __("hotel")/__("hotel_name") — neither exists in CS-Cart core, so
 *    the admin rendered the missing-variable placeholders "_hotel" and
 *    "_hotel_name". The keys are declared in addon.xml, which the lang
 *    self-seed merges (fn_travel_core_language_variables), reaching
 *    already-installed stores.
 *
 * 2. The hotel name links to its STOREFRONT product page in a new window.
 *    product_id is resolved through the provider registry
 *    (HotelProductProviderInterface::productIdForHotelId) — no provider SQL
 *    in the shared controller.
 */
final class TravelBookingsAdminTest extends TestCase
{
    private static function backendTpl(string $name): string
    {
        $path = dirname(__DIR__, 6)
            . '/design/backend/templates/addons/travel_core/views/travel_bookings/' . $name;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function testLabelsUseTravelCoreKeysNotMissingCoreVars(): void
    {
        foreach (['manage.tpl', 'view.tpl'] as $name) {
            $tpl = self::backendTpl($name);

            self::assertStringNotContainsString(
                '{__("hotel_name")}',
                $tpl,
                "{$name}: bare hotel_name var does not exist in CS-Cart core — renders \"_hotel_name\"",
            );
            self::assertStringNotContainsString(
                '{__("hotel")}',
                $tpl,
                "{$name}: bare hotel var does not exist in CS-Cart core — renders \"_hotel\"",
            );
        }

        self::assertStringContainsString('{__("travel_core.hotel_name")}', self::backendTpl('manage.tpl'));
        self::assertStringContainsString('{__("travel_core.hotel")}', self::backendTpl('manage.tpl'));
        // The booking page names the hotel in its Stay card, under the
        // card's own seeded heading.
        self::assertStringContainsString('{__("travel_core.obc_stay")}', self::backendTpl('view.tpl'));
    }

    public function testViewLabelsUseSeededTravelCoreKeysNotBareOnes(): void
    {
        $view = self::backendTpl('view.tpl');

        // Bare "created_at" is NOT a CS-Cart core var (it's a DB column name),
        // and "back" is a core var the kit may not ship — both rendered raw
        // ("_created_at" / "_back"). Use the addon's own seeded keys.
        self::assertStringNotContainsString('{__("created_at")}', $view,
            'bare created_at is not a lang var — renders "_created_at"; use travel_core.created');
        self::assertStringNotContainsString('{__("back")}', $view,
            'bare back depends on the kit shipping the core var — renders "_back"; use travel_core.back');
        self::assertStringContainsString('{__("travel_core.bv_created", ["[date]" => $page.created])}', $view);
        // No Back buttons of its own: the arrow beside the page title goes
        // back, and the Travel menu is above it.
        self::assertStringNotContainsString('{__("travel_core.back")}', $view);
    }

    public function testHotelNameLangKeyIsDeclaredForSeeding(): void
    {
        $xml = (string) file_get_contents(dirname(__DIR__, 3) . '/addon.xml');

        foreach (['en', 'ro'] as $lang) {
            self::assertStringContainsString(
                'lang="' . $lang . '" id="travel_core.hotel_name"',
                $xml,
                'travel_core.hotel_name must be declared so the lang self-seed reaches installed stores',
            );
        }
    }

    public function testHotelNameLinksToTheStorefrontProductPageInANewWindow(): void
    {
        foreach (['manage.tpl' => '$booking.hotel_product_url', 'view.tpl' => '$page.stay.hotel_url'] as $name => $url) {
            self::assertStringContainsString(
                '<a href="{' . $url . '|escape:html}" target="_blank" rel="noopener">',
                self::backendTpl($name),
                "{$name}: the hotel name must link to the storefront product page in a new window",
            );
        }
    }

    public function testControllerResolvesTheProductThroughTheProviderRegistry(): void
    {
        $controller = (string) file_get_contents(
            dirname(__DIR__, 3) . '/controllers/backend/travel_bookings.php',
        );

        self::assertStringContainsString('getHotelProductProvider', $controller);
        self::assertStringContainsString('productIdForHotelId', $controller);
        // Area 'C' — the admin grid links OUT to the customer storefront.
        self::assertStringContainsString("fn_url('products.view?product_id=' . \$productId, 'C')", $controller);
    }

    public function testInterfaceDeclaresTheReverseLookup(): void
    {
        $interface = (string) file_get_contents(
            dirname(__DIR__, 3) . '/src/Contracts/HotelProductProviderInterface.php',
        );

        self::assertStringContainsString(
            'public function productIdForHotelId(string $hotelId): ?int;',
            $interface,
            'both provider addons implement this — removing it breaks their class load',
        );
    }
}
