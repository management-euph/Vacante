<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Helpers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Helpers\PlaceOrderPostArgs;

/**
 * REGRESSION: the travel add-ons' place_order_post handlers were declared in
 * the 'place_order' hook's argument order ($order_id, $action, $order_status,
 * $cart, $auth), but CS-Cart 4.20 fires place_order_post as
 *
 *     ($cart, $auth, $action, $issuer_id, $parent_order_id, $order_id,
 *      $order_status, $short_order_data, $notification_rules)
 *
 * so the cart arrived as the order id, read 0, and no supplier booking was
 * submitted or linked. The resolver must read BOTH orders.
 */
#[CoversClass(PlaceOrderPostArgs::class)]
final class PlaceOrderPostArgsTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function cart(): array
    {
        return [
            'products' => [
                3044526915 => ['product_id' => 12, 'price' => 795.0, 'extra' => ['novoton_booking' => true]],
            ],
            'user_data' => ['email' => 'guest@example.com'],
            'order_id' => 999, // a cart's own key: never the placed order's id
        ];
    }

    public function testCsCart420OrderWithAnIntOrderId(): void
    {
        $args = PlaceOrderPostArgs::resolve([
            self::cart(),
            ['user_id' => 7],
            'save',
            null,
            0,
            1234,
            'O',
            ['status' => 'O'],
            [],
        ]);

        self::assertSame(1234, $args['order_id']);
        self::assertSame([1234], $args['order_ids']);
        self::assertSame(self::cart(), $args['cart']);
        self::assertSame(['user_id' => 7], $args['auth']);
        self::assertSame('save', $args['action']);
        self::assertSame('O', $args['order_status']);
    }

    public function testCsCart420OrderWithANumericStringOrderId(): void
    {
        $args = PlaceOrderPostArgs::resolve([self::cart(), [], '', null, 0, '1234', 'P', [], []]);

        self::assertSame(1234, $args['order_id']);
        self::assertSame('P', $args['order_status']);
    }

    public function testCsCart420OrderWithAMultiVendorOrderIdList(): void
    {
        $args = PlaceOrderPostArgs::resolve([self::cart(), [], '', null, 0, [0, 50, 51, 50], 'O', [], []]);

        self::assertSame(50, $args['order_id'], 'the first POSITIVE id: the parent order');
        self::assertSame([50, 51], $args['order_ids']);
    }

    /**
     * A build that stops passing trailing arguments must still find the id:
     * it is the sixth, not the last.
     */
    public function testCsCart420OrderWithoutTheTrailingArguments(): void
    {
        $args = PlaceOrderPostArgs::resolve([self::cart(), ['user_id' => 7], '', null, 0, 1234]);

        self::assertSame(1234, $args['order_id']);
        self::assertSame('', $args['order_status']);
    }

    /**
     * No cart to recognise (an empty one): $auth, an array, in the second slot
     * still marks the 4.20 order — the old order has $action, a string, there.
     */
    public function testCsCart420OrderWithAnEmptyCart(): void
    {
        $args = PlaceOrderPostArgs::resolve([[], ['user_id' => 7], '', null, 0, 1234, 'O', [], []]);

        self::assertSame(1234, $args['order_id']);
        self::assertSame([], $args['cart']);
        self::assertSame(['user_id' => 7], $args['auth']);
    }

    public function testOldOrderWithAnIntOrderId(): void
    {
        $args = PlaceOrderPostArgs::resolve([1234, 'save', 'O', self::cart(), ['user_id' => 7], null, null, null, null]);

        self::assertSame(1234, $args['order_id']);
        self::assertSame([1234], $args['order_ids']);
        self::assertSame(self::cart(), $args['cart']);
        self::assertSame(['user_id' => 7], $args['auth']);
        self::assertSame('save', $args['action']);
        self::assertSame('O', $args['order_status']);
    }

    public function testOldOrderWithAMultiVendorOrderIdList(): void
    {
        $args = PlaceOrderPostArgs::resolve([[50, 51, 52], '', 'O', self::cart(), []]);

        self::assertSame(50, $args['order_id'], 'the parent (first) order id');
        self::assertSame([50, 51, 52], $args['order_ids']);
        self::assertSame(self::cart(), $args['cart']);
    }

    /**
     * Payment callbacks and order-status re-triggers fire the hook without a
     * cart: the order id must still come through (the handlers then link the
     * bookings from the stored order), with cart null rather than [].
     */
    public function testOldOrderWithoutACart(): void
    {
        $args = PlaceOrderPostArgs::resolve(['1234', '', 'P', null, null, null, null, null, null]);

        self::assertSame(1234, $args['order_id']);
        self::assertNull($args['cart']);
        self::assertSame([], $args['auth']);
        self::assertSame('P', $args['order_status']);
    }

    /**
     * @return array<string, array{list<mixed>}>
     */
    public static function garbage(): array
    {
        return [
            'no arguments' => [[]],
            'nine nulls' => [[null, null, null, null, null, null, null, null, null]],
            'zero order id' => [[0, '', '', null, null]],
            'negative order id' => [[-5, '', '', null, null]],
            'non-numeric id' => [['abc', '', '', null, null]],
            'empty id list' => [[[], '', '', null, null]],
            'list of non-ids' => [[[['x'], 'abc', 0], '', '', null, null]],
            'object first' => [[new \stdClass(), '', '', null, null]],
            '4.20 order, no id' => [[['products' => []], [], '', null, 0]],
            '4.20 order, cart id' => [[['products' => []], [], '', null, 0, ['products' => []]]],
            '4.20 order, bad id' => [[['products' => []], [], '', null, 0, 'abc', 'O']],
        ];
    }

    /**
     * @param list<mixed> $args
     */
    #[DataProvider('garbage')]
    public function testGarbageResolvesToNoOrderAndNeverThrows(array $args): void
    {
        $resolved = PlaceOrderPostArgs::resolve($args);

        self::assertSame(0, $resolved['order_id']);
        self::assertSame([], $resolved['order_ids']);
    }

    public function testNoArgumentsMeansNoCart(): void
    {
        $args = PlaceOrderPostArgs::resolve([]);

        self::assertSame(0, $args['order_id']);
        self::assertNull($args['cart']);
        self::assertSame([], $args['auth']);
        self::assertSame('', $args['action']);
        self::assertSame('', $args['order_status']);
    }

    /**
     * Non-array cart/auth slots never leak through as a wrong type.
     */
    public function testWrongTypedSlotsAreNarrowed(): void
    {
        $args = PlaceOrderPostArgs::resolve([1234, ['not', 'a', 'string'], 7, 'not a cart', 'not auth']);

        self::assertSame(1234, $args['order_id']);
        self::assertNull($args['cart']);
        self::assertSame([], $args['auth']);
        self::assertSame('', $args['action']);
        self::assertSame('7', $args['order_status']);
    }
}
