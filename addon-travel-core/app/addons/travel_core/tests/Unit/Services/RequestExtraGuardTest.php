<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Services\RequestExtraGuard;

/**
 * Audit T1 / H1: a storefront checkout.add may not hand the travel add-ons
 * the cart-line keys they act on (travel_balance_id, novoton_booking,
 * offer_id, total_price …). The guard strips them in the customer area,
 * leaves admin order editing and a trusted server-side add alone, and on a
 * cart update keeps what the cart already holds for the line.
 */
#[CoversClass(RequestExtraGuard::class)]
final class RequestExtraGuardTest extends TestCase
{
    /** @return array<array-key, mixed> checkout.add?product_data[77][…] */
    private static function forgedAdd(): array
    {
        return [
            77 => [
                'product_id' => 77,
                'amount' => 1,
                'extra' => [
                    'travel_balance_id' => 57,
                    'travel_balance' => true,
                    'parent_order_id' => 1042,
                    'novoton_booking' => true,
                    'sphinx_booking' => true,
                    'eurosite_booking_id' => 9,
                    'travel_booking_id' => 12,
                    'travel_deposit' => ['ratio' => 0.01],
                    'total_price' => 1,
                    'rooms_data' => '[{"adults":9}]',
                    'offer_id' => 'X1',
                    'product_options' => [5 => 11],
                    'custom_note' => 'kept',
                ],
            ],
        ];
    }

    public function testTheCustomerAreaLosesEveryProtectedKey(): void
    {
        $data = self::forgedAdd();

        $removed = RequestExtraGuard::strip($data, [], false, 'C');

        self::assertSame(['product_options' => [5 => 11], 'custom_note' => 'kept'], $data[77]['extra']);
        self::assertSame(77, $data[77]['product_id']);
        self::assertSame(1, $data[77]['amount']);
        self::assertContains('77.travel_balance_id', $removed);
        self::assertCount(11, $removed);
    }

    public function testTheAdminAreaIsLeftAlone(): void
    {
        $data = self::forgedAdd();

        self::assertSame([], RequestExtraGuard::strip($data, [], false, 'A'));
        self::assertSame(self::forgedAdd(), $data);
    }

    public function testATrustedServerSideAddIsLeftAlone(): void
    {
        $data = self::forgedAdd();

        $removed = RequestExtraGuard::trusted(static fn (): array => RequestExtraGuard::strip($data, [], false, 'C'));

        self::assertSame([], $removed);
        self::assertSame(self::forgedAdd(), $data);
        self::assertFalse(RequestExtraGuard::isTrusted(), 'the mark ends with the call');
    }

    public function testTheTrustedMarkEndsEvenWhenTheAddThrows(): void
    {
        try {
            RequestExtraGuard::trusted(static function (): never {
                throw new \RuntimeException('cart full');
            });
        } catch (\RuntimeException) {
        }

        self::assertFalse(RequestExtraGuard::isTrusted());
    }

    public function testACartUpdateKeepsTheValuesTheCartAlreadyHolds(): void
    {
        $cart = ['products' => [
            'b1' => ['product_id' => 77, 'extra' => ['travel_balance_id' => 57, 'parent_order_id' => 1042, 'travel_balance' => true]],
        ]];
        $data = [
            'b1' => ['product_id' => 77, 'amount' => 1, 'extra' => ['travel_balance_id' => 58, 'parent_order_id' => 1042, 'offer_id' => 'X1']],
            'new' => ['product_id' => 3, 'amount' => 1, 'extra' => ['travel_balance_id' => 57]],
        ];

        $removed = RequestExtraGuard::strip($data, $cart, true, 'C');

        self::assertSame(['travel_balance_id' => 57, 'parent_order_id' => 1042], $data['b1']['extra'], 'the request cannot re-point a line');
        self::assertSame([], $data['new']['extra'], 'a line the cart does not hold gets nothing');
        self::assertSame(['b1.offer_id', 'new.travel_balance_id'], $removed);
    }

    public function testANewAddCannotBorrowAnExistingLinesValues(): void
    {
        $cart = ['products' => [77 => ['product_id' => 77, 'extra' => ['travel_balance_id' => 57]]]];
        $data = self::forgedAdd();

        RequestExtraGuard::strip($data, $cart, false, 'C');

        self::assertArrayNotHasKey('travel_balance_id', $data[77]['extra']);
    }

    public function testLinesWithoutExtraAreUntouched(): void
    {
        $data = [77 => ['product_id' => 77, 'amount' => 2], 'junk' => 'x', 5 => ['extra' => 'not-an-array']];

        self::assertSame([], RequestExtraGuard::strip($data, [], false, 'C'));
        self::assertSame([77 => ['product_id' => 77, 'amount' => 2], 'junk' => 'x', 5 => ['extra' => 'not-an-array']], $data);
    }

    public function testProtectedKeys(): void
    {
        foreach (['travel_x', 'novoton_x', 'sphinx_x', 'eurosite_x', 'total_price', 'rooms_data', 'offer_id', 'parent_order_id'] as $key) {
            self::assertTrue(RequestExtraGuard::isProtectedKey($key), $key);
        }
        foreach (['product_options', 'parent', 'travelx', 'hotel_name', 'check_in'] as $key) {
            self::assertFalse(RequestExtraGuard::isProtectedKey($key), $key);
        }
    }
}
