<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * REGRESSION: every "lt;"/"gt;"/"amp;" was decoded, so "1 adult;" became
     * "1 adu<" and cut the note at the next tag; "&amp;" became "&&".
     *
     * @return iterable<string, array{string, string}>
     */
    public static function remarks(): iterable
    {
        yield 'escaped markup, lost ampersands' => ['Line onelt;br /gt;Line twolt;/pgt;lt;pgt;Para two', 'Line one Line two Para two'];
        yield 'inline markup inside a word' => ['Late lt;bgt;checklt;/bgt;-in', 'Late check-in'];
        yield 'a word ending in "lt;"' => ['Extra bed for 1 adult; children up to 12 free', 'Extra bed for 1 adult; children up to 12 free'];
        yield 'and a tag after it' => ['Tax for the adult;s lt;bgt;onlylt;/bgt;', 'Tax for the adult;s only'];
        yield 'a word ending in "amp;"' => ['Near the beach camp; quiet', 'Near the beach camp; quiet'];
        yield 'properly escaped entities' => ['Hotel offers B&amp;B, balcony &gt; 20m2', 'Hotel offers B&B, balcony > 20m2'];
        yield 'properly escaped markup' => ['&lt;p&gt;One &amp; two&lt;/p&gt;&lt;p&gt;Three&lt;/p&gt;', 'One & two Three'];
        yield 'a plain "<"' => ['Children < 12 free', 'Children < 12 free'];
    }

    #[DataProvider('remarks')]
    public function testARemarkKeepsItsWordsAndLosesOnlyItsMarkup(string $remark, string $expected): void
    {
        self::assertSame($expected, OrderCardFacts::plainText($remark));
    }

    public function testOnlyNovotonLinesAreClaimed(): void
    {
        self::assertSame([], (new OrderCardFacts())->facts(['sphinx_booking' => true]));
    }
}
