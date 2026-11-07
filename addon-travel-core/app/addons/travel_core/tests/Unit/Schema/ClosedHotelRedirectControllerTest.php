<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Pins the closed hotel's redirect on the storefront
 * (docs/novoton-availability-gaps-plan.md §7.4, §9): a products pre-controller
 * that sends a permanent redirect (301) for the pages the daily run marked,
 * reading only what ClosedHotelRedirects stored, and the daily run that
 * keeps that list current.
 */
final class ClosedHotelRedirectControllerTest extends TestCase
{
    private static function file(string $relative): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/' . $relative);
    }

    public function testTheProductPageRedirectsPermanentlyWhenMarked(): void
    {
        $controller = self::file('controllers/frontend/products.pre.php');

        self::assertStringContainsString("if (\$mode === 'view') {", $controller);
        self::assertStringContainsString("(new ClosedHotelRedirects())->urlFor(RequestCoerce::int(\$_REQUEST, 'product_id'))", $controller);
        self::assertStringContainsString('fn_redirect($travelClosedRedirect, false, true);', $controller, 'permanent, never to another site');
        self::assertStringNotContainsString('404', $controller);
        self::assertStringNotContainsString('db_', $controller, 'the storefront reads only the stored list');
    }

    public function testTheDailyRunAndTheDecisionsKeepTheListCurrent(): void
    {
        self::assertStringContainsString('(new ClosedHotelRedirects())->refresh($now);', self::file('src/Services/AvailabilityReview.php'));
        self::assertStringContainsString('$this->redirects->refresh($now);', self::file('src/Services/AvailabilityOverride.php'));
        self::assertStringContainsString('$this->refresh($now);', self::file('src/Services/ClosedHotelRedirects.php'));
    }
}
