<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Services\OrderCardFacts;

/**
 * What the order booking card learns from a novoton booking row and line:
 * the supplier reference, why a booking failed, the offer's remarks as plain
 * text, the package, and what Novoton charges.
 */
#[CoversClass(OrderCardFacts::class)]
final class OrderCardFactsTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function row(array $overrides = []): array
    {
        return $overrides + [
            'booking_id' => 17,
            'status' => 'ask',
            'novoton_confirm_id' => null,
            'novoton_invoice_id' => '418833',
            'novoton_res_num' => null,
            'base_price' => '1210.00',
            'api_price' => '1280.00',
            'currency' => 'EUR',
            'notes' => '',
        ];
    }

    public function testTheReferenceIsTheFirstNovotonIdentifierHeld(): void
    {
        $invoice = OrderCardFacts::fromRow([], 17, self::row());
        $confirm = OrderCardFacts::fromRow([], 17, self::row(['novoton_confirm_id' => 'C-9']));
        $none = OrderCardFacts::fromRow([], 17, self::row(['novoton_invoice_id' => '']));

        self::assertSame('NT 418833', $invoice['reference']);
        self::assertSame('NT C-9', $confirm['reference']);
        self::assertSame('', $none['reference']);
        self::assertSame('ask', $invoice['status']);
        self::assertSame('17', $invoice['provider_booking_id']);
    }

    public function testTheSupplierPriceIsNovotonsOwn(): void
    {
        self::assertSame(['amount' => 1280.0, 'currency' => 'EUR'], OrderCardFacts::fromRow([], 17, self::row())['supplier_price']);
        self::assertSame(['amount' => 1210.0, 'currency' => 'EUR'], OrderCardFacts::fromRow([], 17, self::row(['api_price' => null]))['supplier_price']);
    }

    public function testAFailureReadsAsTheStoredError(): void
    {
        $failed = OrderCardFacts::fromRow([], 17, self::row(['status' => 'failed', 'notes' => "API Error (book, HTTP 500):\n  Room <b>sold out</b>"]));
        $pending = OrderCardFacts::fromRow([], 17, self::row(['status' => 'pending', 'notes' => 'API submission disabled - booking saved locally only.']));

        self::assertSame('API Error (book, HTTP 500): Room sold out', $failed['error']);
        self::assertArrayNotHasKey('error', $pending);
    }

    public function testTheOffersRemarksArePlainText(): void
    {
        // Novoton escapes its markup and loses the ampersands.
        $facts = OrderCardFacts::fromRow([
            'important' => 'lt;pgt;Late check-in after 22:00 amp; on requestlt;/pgt;',
            'remark' => 'Pool closed in November',
            'package_name' => 'ADMIRAL ***** +BEACH',
        ], 17, null);

        self::assertSame('Late check-in after 22:00 & on request · Pool closed in November', $facts['note']);
        self::assertSame('ADMIRAL ***** +BEACH', $facts['package']);
        self::assertArrayNotHasKey('reference', $facts);
    }

    public function testOnlyNovotonLinesAreClaimed(): void
    {
        self::assertSame([], (new OrderCardFacts())->facts(['sphinx_booking' => true]));
    }
}
