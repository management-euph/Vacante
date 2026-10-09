<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Functions;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Who gets the ADMIN booking card on an order page (supplier price before
 * our commission, supplier reference and error, the Retry / Booking links):
 * an administrator travel_bookings itself lets in. REGRESSION: staff in an
 * admin usergroup (RESTRICTED_ADMIN), whom travel_bookings and the provider
 * pages deny, got it too. They — and the storefront — get the customer's
 * card. AREA is a process-wide constant, so each case runs in its own process.
 */
#[CoversNothing]
final class OrderCardAudienceTest extends TestCase
{
    private static function load(string $area, bool $restricted = false, bool $multiVendor = false): bool
    {
        if (!defined('BOOTSTRAP')) {
            define('BOOTSTRAP', true);
        }
        define('AREA', $area);
        if ($restricted) {
            define('RESTRICTED_ADMIN', true);
        }
        if ($multiVendor) {
            eval('function fn_allowed_for(string $edition): bool { return $edition === "MULTIVENDOR"; }');
        }
        require_once dirname(__DIR__, 3) . '/functions/order_card.php';

        return \fn_travel_core_order_card_full_admin();
    }

    #[RunInSeparateProcess]
    public function testAnAdministratorGetsTheAdminCard(): void
    {
        self::assertTrue(self::load('A'));
    }

    #[RunInSeparateProcess]
    public function testStaffInAnAdminUsergroupGetTheCustomersCard(): void
    {
        self::assertFalse(self::load('A', restricted: true));
    }

    #[RunInSeparateProcess]
    public function testAMultiVendorStoreGetsTheCustomersCard(): void
    {
        self::assertFalse(self::load('A', multiVendor: true));
    }

    #[RunInSeparateProcess]
    public function testTheStorefrontNeverGetsTheAdminCard(): void
    {
        self::assertFalse(self::load('C'));
    }
}
