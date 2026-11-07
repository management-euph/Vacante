<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Schema;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What package and circuit add-to-cart store is what packages/book and
 * circuits/book send at order placement and on a retry
 * (PackageBookRequest, CircuitBookRequest): the pre-commission selling
 * price, its currency and the chosen services (pricing_json), the offer's
 * own rooms, and travellers with a gender, each child with a birth date.
 * A request without those is refused at add-to-cart, not by Sphinx after
 * payment.
 */
final class BookBasisTest extends TestCase
{
    private static function read(string $rel): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/' . $rel);
    }

    private static function template(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__, 6)
            . '/design/themes/responsive/templates/addons/sphinx_holidays/views/sphinx_booking/' . $name);
    }

    /** @return array<string, array{string, string}> flow => [controller, form template] */
    public static function flows(): array
    {
        return [
            'package' => ['package_add_to_cart.php', 'package_booking_form.tpl'],
            'circuit' => ['circuit_add_to_cart.php', 'circuit_booking_form.tpl'],
        ];
    }

    #[DataProvider('flows')]
    public function testAddToCartStoresWhatTheBookCallSends(string $controller, string $template): void
    {
        $src = self::read('controllers/frontend/sphinx_booking/' . $controller);

        self::assertStringContainsString("\$booking_record['pricing_json'] = json_encode(\$bookPricing + ['chosen_services' => ", $src);
        self::assertStringContainsString('$basePrice     = $total_price;', $src, 'base_price is the selling price the book call sends');
        self::assertStringNotContainsString("['supplier_price']", $src, 'never the supplier price');
        self::assertStringNotContainsString('$bookingData', $src, 'nothing is stored from the form request');
        self::assertStringContainsString("__('sphinx_holidays.traveller_details_required'", $src);

        $form = self::template($template);
        self::assertStringContainsString('guest_gender_options=$sphinx_gender_options', $form);
        self::assertStringContainsString('guest_child_gender_options=$sphinx_gender_options', $form);
    }

    /** Circuits have no verify: the quote the form got is kept with its pricing and rooms, and add-to-cart reads them. */
    public function testTheCircuitPriceAndRoomsAreTheStoredQuotes(): void
    {
        $form = self::read('controllers/frontend/sphinx_booking/circuit_booking_form.php');
        // The quote as Sphinx sent it: its pricing, rooms and terms (CircuitQuoteStore::rememberQuotes()).
        self::assertStringContainsString('$quoteStore->rememberQuotes([$quote], $circuit_id, $departure_date, time());', $form);
        self::assertStringContainsString("TypeCoerce::toStringMap(\$quote['payment_terms'] ?? null),", self::read('src/Services/CircuitQuoteStore.php'));

        $cart = self::read('controllers/frontend/sphinx_booking/circuit_add_to_cart.php');
        self::assertStringContainsString("\$storedQuote['pricing']", $cart);
        self::assertStringContainsString("\$rooms = \$storedQuote['rooms'];", $cart);
    }
}
