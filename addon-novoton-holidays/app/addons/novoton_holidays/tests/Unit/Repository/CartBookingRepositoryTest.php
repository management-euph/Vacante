<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Repository;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Repository\CartBookingRepository;
use Tygh\Addons\NovotonHolidays\Tests\Support\DbStub;

/**
 * Audit H3 / C1: the lookup behind CartBookingGuard returns only bookings the
 * cart may still be charged for - pending with no order yet, or linked to
 * the cart's own processed/failed order and not failed/cancelled - owned by
 * this account (user_id > 0), this session (non-empty) or the session's
 * verified list; never by user_id = 0 alone.
 */
#[CoversClass(CartBookingRepository::class)]
final class CartBookingRepositoryTest extends TestCase
{
    private const string SELECT = 'SELECT booking_id, product_id, total_price, currency, order_id, status, hotel_id, check_in, check_out FROM ?:novoton_bookings'
        . ' WHERE booking_id IN (?n) AND ';

    /** @var list<array{0: string, 1: list<mixed>}> */
    private array $queries = [];

    protected function setUp(): void
    {
        DbStub::reset();
        $this->queries = [];
        DbStub::$getArray = function (string $query, mixed ...$params): array {
            $this->queries[] = [$query, array_values($params)];

            return [
                ['booking_id' => '5', 'product_id' => '77', 'total_price' => '1500.00', 'currency' => 'EUR'],
                ['booking_id' => '0'],
            ];
        };
    }

    protected function tearDown(): void
    {
        DbStub::reset();
    }

    public function testScopesToPendingOrderlessBookingsOfUserOrSession(): void
    {
        $rows = (new CartBookingRepository())->findCartStage([5, 5, 0, 6], 42, 'sess-1');

        $this->assertSame([5], array_keys($rows));
        $this->assertSame('77', $rows[5]['product_id']);
        $this->assertSame([[
            self::SELECT . '(order_id = 0 AND status = ?s) AND (user_id = ?i OR session_id = ?s)',
            [[5, 6], 'pending', 42, 'sess-1'],
        ]], $this->queries);
    }

    public function testUserOnly(): void
    {
        (new CartBookingRepository())->findCartStage([5], 42, '');

        $this->assertSame([[
            self::SELECT . '(order_id = 0 AND status = ?s) AND (user_id = ?i)',
            [[5], 'pending', 42],
        ]], $this->queries);
    }

    public function testGuestMatchesBySessionNeverByUserIdZero(): void
    {
        (new CartBookingRepository())->findCartStage([5], 0, 'sess-1');

        [$sql, $params] = $this->queries[0];
        $this->assertStringEndsWith('AND (session_id = ?s)', $sql);
        $this->assertStringNotContainsString('user_id', $sql);
        $this->assertSame([[5], 'pending', 'sess-1'], $params);
    }

    public function testOwnProcessedOrFailedOrderCountsAsStageUnlessTheSubmissionDied(): void
    {
        (new CartBookingRepository())->findCartStage([5], 42, '', [812, 0, 812, 813]);

        $this->assertSame([[
            self::SELECT . '((order_id = 0 AND status = ?s) OR (order_id IN (?n) AND status NOT IN (?a))) AND (user_id = ?i)',
            [[5], 'pending', [812, 813], ['failed', 'cancelled'], 42],
        ]], $this->queries);
    }

    public function testVerifiedListIsAnOwnershipMatchThatSurvivesTheLogin(): void
    {
        // After login: a real account, a regenerated session id, and the
        // guest booking the cart verified before - user_id 0, old session.
        (new CartBookingRepository())->findCartStage([5], 42, 'sess-after-login', [], [5, 0]);

        $this->assertSame([[
            self::SELECT . '(order_id = 0 AND status = ?s) AND (user_id = ?i OR session_id = ?s OR booking_id IN (?n))',
            [[5], 'pending', 42, 'sess-after-login', [5]],
        ]], $this->queries);
    }

    /**
     * Payment retry of a booking whose rooms used two packages: submission
     * rewrote booking 5's row with group 1 (700) and added group 2's row
     * (900) to order 812. The line charges both: line_total is 1600.
     */
    public function testAPlacedBookingCarriesTheTotalOfItsPackageGroups(): void
    {
        $stay = ['hotel_id' => '476', 'check_in' => '2026-10-05', 'check_out' => '2026-10-11'];
        DbStub::$getArray = function (string $query, mixed ...$params) use ($stay): array {
            $this->queries[] = [$query, array_values($params)];
            if (str_contains($query, 'SUM(total_price)')) {
                return [
                    ['order_id' => '812', 'line_total' => '1600.00'] + $stay,
                    ['order_id' => '812', 'hotel_id' => '476', 'check_in' => '2026-11-01', 'check_out' => '2026-11-05', 'line_total' => '450.00'],
                ];
            }

            return [
                ['booking_id' => '5', 'product_id' => '77', 'total_price' => '700.00', 'order_id' => '812', 'status' => 'confirmed'] + $stay,
                ['booking_id' => '6', 'product_id' => '77', 'total_price' => '300.00', 'order_id' => '0', 'status' => 'pending'] + $stay,
            ];
        };

        $rows = (new CartBookingRepository())->findCartStage([5, 6], 42, '', [812]);

        $this->assertSame(1600.0, $rows[5]['line_total']);
        $this->assertArrayNotHasKey('line_total', $rows[6], 'a cart-stage booking is its own row');
        $this->assertCount(2, $this->queries);
        $this->assertSame(
            ['SELECT order_id, hotel_id, check_in, check_out, SUM(total_price) AS line_total FROM ?:novoton_bookings'
                . ' WHERE order_id IN (?n) AND status NOT IN (?a) GROUP BY order_id, hotel_id, check_in, check_out', [[812], ['failed', 'cancelled']]],
            $this->queries[1],
            'a failed or cancelled row has no live reservation: it is not charged',
        );
    }

    public function testCartStageBookingsNeedNoTotalsQuery(): void
    {
        (new CartBookingRepository())->findCartStage([5], 42, '');

        $this->assertCount(1, $this->queries);
    }

    public function testNoOwnershipContextQueriesNothing(): void
    {
        $this->assertSame([], (new CartBookingRepository())->findCartStage([5], 0, '  '));
        $this->assertSame([], (new CartBookingRepository())->findCartStage([5], 0, '', [812], [0]));
        $this->assertSame([], (new CartBookingRepository())->findCartStage([0, -3], 42, 'sess-1'));
        $this->assertSame([], $this->queries);
    }
}
