<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Pins that a sphinx customer's order-confirmation email carries the payment
 * and cancellation terms.
 *
 * Field gap: novoton emails showed them, sphinx emails did not — and the miss
 * was invisible because it took TWO independent causes:
 *   1. the sphinx mail hook never passed `show_terms`, which defaults to false;
 *   2. even with the flag on, the shared component only read novoton's key
 *      names/shape (newline strings), while sphinx stores ARRAYS of formatted
 *      lines — so the block would have rendered empty anyway.
 * Fixing either one alone leaves the customer with no policy in the one place
 * they go looking for it after booking.
 */
final class OrderEmailTermsTest extends TestCase
{
    private static function repoRoot(): string
    {
        return dirname(__DIR__, 7);
    }

    private static function read(string $relative): string
    {
        $path = self::repoRoot() . '/' . $relative;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function testSphinxOrderEmailAsksForTheTermsBlock(): void
    {
        $hook = self::read(
            'addon-sphinx-holidays/design/backend/mail/templates/addons/sphinx_holidays/hooks/orders/product_info.post.tpl',
        );

        self::assertStringContainsString('components/order_booking_details.tpl', $hook);
        self::assertStringContainsString('show_terms=true', $hook);
    }

    /**
     * The shared component must understand BOTH providers' storage shapes:
     * novoton's newline-joined strings and sphinx's line arrays.
     */
    public function testSharedComponentRendersBothProviderShapes(): void
    {
        // The order pages draw travel_core's booking card; the mail tree
        // keeps the only copy of this component.
        foreach ([
            'addon-travel-core/design/backend/mail/templates/addons/travel_core/components/order_booking_details.tpl',
        ] as $copy) {
            $tpl = self::read($copy);

            // novoton: with-amounts wins over plain formatted.
            self::assertStringContainsString('terms_of_payment_with_amounts', $tpl, $copy);
            self::assertStringContainsString('terms_of_cancellation_formatted', $tpl, $copy);
            // sphinx: arrays of already-formatted lines.
            self::assertStringContainsString('$_be.payment_terms', $tpl, $copy);
            self::assertStringContainsString('$_be.cancellation_fees', $tpl, $copy);
            // Headings render when EITHER shape has content.
            self::assertStringContainsString('{if $_obd_pay || $_obd_pay_lines}', $tpl, $copy);
            self::assertStringContainsString('{if $_obd_cancel || $_obd_cancel_lines}', $tpl, $copy);
        }
    }

    /**
     * The email can only show what add_to_cart persisted onto the cart line —
     * these keys ARE the contract the component reads.
     */
    public function testAddToCartPersistsTheTermsTheEmailReads(): void
    {
        $addToCart = self::read(
            'addon-sphinx-holidays/app/addons/sphinx_holidays/controllers/frontend/sphinx_booking/add_to_cart.php',
        );

        self::assertStringContainsString("'payment_terms' => \\Tygh\\Addons\\SphinxHolidays\\Services\\TermsFormatter::lines", $addToCart);
        self::assertStringContainsString("'cancellation_fees' => \\Tygh\\Addons\\SphinxHolidays\\Services\\TermsFormatter::lines", $addToCart);
    }
}
