<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Repository\BalanceRepository;
use Tygh\Addons\TravelCore\Services\BalanceReminder;
use Tygh\Addons\TravelCore\Services\BalanceService;
use Tygh\Addons\TravelCore\Services\MoneyFormatter;

/** In-memory travel_balances. */
final class FakeBalanceRepository extends BalanceRepository
{
    /** @var array<int, array<string, mixed>> */
    public array $rows = [];
    /** @var list<array<string, mixed>> */
    public array $due = [];

    public function insertIfNew(array $row): void
    {
        foreach ($this->rows as $r) {
            if ($r['order_id'] === $row['order_id'] && $r['item_id'] === $row['item_id']) {
                return;
            }
        }
        $id = count($this->rows) + 1;
        $this->rows[$id] = ['balance_id' => $id] + $row;
    }

    public function find(int $balanceId): ?array
    {
        return $this->rows[$balanceId] ?? null;
    }

    public function forOrder(int $orderId): array
    {
        return array_values(array_filter($this->rows, static fn (array $r): bool => $r['order_id'] === $orderId));
    }

    public function openDueBy(string $until): array
    {
        return $this->due;
    }

    public function update(int $balanceId, array $fields): void
    {
        $this->rows[$balanceId] = $fields + ($this->rows[$balanceId] ?? ['balance_id' => $balanceId]);
    }

    public function cancelOpenForOrder(int $orderId): void
    {
        foreach ($this->rows as $id => $r) {
            if ($r['order_id'] === $orderId && $r['status'] === self::STATUS_OPEN) {
                $this->rows[$id]['status'] = self::STATUS_CANCELLED;
            }
        }
    }
}

/**
 * The balance of a deposit booking: recorded when the deposit order is
 * placed, paid by its own order, cancelled with the deposit order, and
 * chased by the daily reminder.
 */
final class BalanceTest extends TestCase
{
    private const DEPOSIT = ['ratio' => 0.3, 'balance_due' => '2026-10-14', 'full' => 299.0, 'deposit' => 89.7, 'balance' => 209.3];

    /** @return array<string, mixed> */
    private static function depositOrder(): array
    {
        return [
            'order_id' => 1042,
            'user_id' => 5,
            'products' => [
                'abc' => [
                    'product_id' => 77,
                    'product' => 'ADMIRAL',
                    'extra' => ['novoton_booking' => true, 'hotel_name' => 'ADMIRAL', 'check_in' => '2026-10-05', 'check_out' => '2026-10-11', 'travel_deposit' => self::DEPOSIT],
                ],
                'plain' => ['product_id' => 3, 'extra' => []],
            ],
        ];
    }

    public function testADepositOrderRecordsItsBalanceOnce(): void
    {
        $repo = new FakeBalanceRepository();
        $service = new BalanceService($repo, 'secret');

        $service->onOrderPlaced(1042, self::depositOrder());
        $service->onOrderPlaced(1042, self::depositOrder());

        self::assertCount(1, $repo->rows, 'one row per deposit item, idempotent');
        $row = $repo->rows[1];
        self::assertSame(209.3, $row['amount']);
        self::assertSame(89.7, $row['deposit_amount']);
        self::assertSame('2026-10-14', $row['due_date']);
        self::assertSame('novoton', $row['provider']);
        self::assertSame(77, $row['product_id']);
        self::assertSame('open', $row['status']);
    }

    public function testTheBalanceOrderLinksAndPaysIt(): void
    {
        $repo = new FakeBalanceRepository();
        $service = new BalanceService($repo, 'secret');
        $service->onOrderPlaced(1042, self::depositOrder());

        $balanceOrder = ['order_id' => 1100, 'products' => ['x' => ['product_id' => 77, 'extra' => ['travel_balance_id' => 1, 'parent_order_id' => 1042]]]];
        $service->onOrderPlaced(1100, $balanceOrder);
        self::assertSame(1100, $repo->rows[1]['balance_order_id']);
        self::assertSame('open', $repo->rows[1]['status'], 'placed is not paid (bank transfer waits for the admin)');

        $service->onStatusChanged('O', $balanceOrder);
        self::assertSame('open', $repo->rows[1]['status']);
        $service->onStatusChanged('P', $balanceOrder);
        self::assertSame('paid', $repo->rows[1]['status']);
    }

    public function testCancellingTheDepositOrderCancelsTheBalance(): void
    {
        $repo = new FakeBalanceRepository();
        $service = new BalanceService($repo, 'secret');
        $service->onOrderPlaced(1042, self::depositOrder());

        $service->onStatusChanged('I', self::depositOrder());

        self::assertSame('cancelled', $repo->rows[1]['status']);
    }

    public function testThePayLinkKeyIsBoundToTheBalanceAndOrder(): void
    {
        $service = new BalanceService(new FakeBalanceRepository(), 'secret');
        $key = $service->key(17, 1042);

        self::assertTrue($service->keyMatches(17, 1042, $key));
        self::assertFalse($service->keyMatches(18, 1042, $key), 'another balance');
        self::assertFalse($service->keyMatches(17, 1043, $key), 'another order');
        self::assertFalse($service->keyMatches(17, 1042, ''));
        self::assertSame('travel_balance.pay?balance_id=17&key=' . $key, $service->payQuery(17, 1042));
    }

    public function testOnlyOpenBalancesCarryAPayLink(): void
    {
        $repo = new FakeBalanceRepository();
        $service = new BalanceService($repo, 'secret');
        $service->onOrderPlaced(1042, self::depositOrder());
        self::assertStringStartsWith('travel_balance.pay?balance_id=1&key=', $service->forOrder(1042)[0]['pay_query']);

        $repo->update(1, ['status' => 'paid']);
        self::assertSame('', $service->forOrder(1042)[0]['pay_query']);
    }

    /** @return array<string, mixed> */
    private static function dueRow(string $due, string $sent = '', ?string $overdue = null): array
    {
        return [
            'balance_id' => 1, 'order_id' => 1042, 'amount' => 209.3, 'due_date' => $due, 'hotel_name' => 'ADMIRAL',
            'email' => 'maria@example.com', 'firstname' => 'Maria', 'lastname' => 'Pop', 'lang_code' => 'en',
            'reminders_sent' => $sent, 'overdue_notified_at' => $overdue, 'check_in' => '2026-10-05',
        ];
    }

    /** @return array{0: BalanceReminder, 1: FakeBalanceRepository, 2: \ArrayObject<int, array{0: array<string, string>, 1: string}>} */
    private static function reminder(array $due): array
    {
        $repo = new FakeBalanceRepository();
        $repo->due = $due;
        $repo->rows[1] = $due[0] ?? ['balance_id' => 1];
        $sent = new \ArrayObject();
        $reminder = new BalanceReminder(
            $repo,
            new BalanceService($repo, 'secret'),
            static function (array $mail, string $area) use ($sent): bool {
                $sent->append([$mail, $area]);

                return true;
            },
            static fn (string $q): string => 'https://shop.test/' . $q,
            new MoneyFormatter(['symbol' => '€', 'after' => 'Y', 'decimals' => 2, 'decimals_separator' => ',', 'thousands_separator' => '.']),
        );

        return [$reminder, $repo, $sent];
    }

    public function testSevenDaysBeforeTheGuestGetsTheLinkOnce(): void
    {
        [$reminder, $repo, $sent] = self::reminder([self::dueRow('2026-10-14')]);

        self::assertSame(['reminded' => 1, 'overdue' => 0], $reminder->run('2026-10-07'));
        self::assertCount(1, $sent);
        self::assertSame('maria@example.com', $sent[0][0]['to']);
        self::assertSame('C', $sent[0][1]);
        self::assertSame('7', $repo->rows[1]['reminders_sent']);
    }

    public function testASentReminderIsNotRepeatedUntilTwoDaysBefore(): void
    {
        [$reminder] = self::reminder([self::dueRow('2026-10-14', '7')]);
        self::assertSame(['reminded' => 0, 'overdue' => 0], $reminder->run('2026-10-10'));

        [$reminder, $repo] = self::reminder([self::dueRow('2026-10-14', '7')]);
        self::assertSame(['reminded' => 1, 'overdue' => 0], $reminder->run('2026-10-12'));
        self::assertSame('2,7', $repo->rows[1]['reminders_sent']);
    }

    public function testFirstRunCloseToTheDateSendsOneEmailForBothMarks(): void
    {
        [$reminder, $repo, $sent] = self::reminder([self::dueRow('2026-10-14')]);

        $reminder->run('2026-10-13');

        self::assertCount(1, $sent);
        self::assertSame('2,7', $repo->rows[1]['reminders_sent']);
    }

    public function testPastTheDueDateTheAdminIsAlertedOnceAndNothingIsCancelled(): void
    {
        if (!function_exists('db_get_field')) {
            self::markTestSkipped('needs the CS-Cart db_get_field stub');
        }
        [$reminder, $repo, $sent] = self::reminder([self::dueRow('2026-10-14')]);
        $reminder->run('2026-10-16');
        self::assertSame('open', $repo->rows[1]['status'] ?? 'open');
        self::assertLessThanOrEqual(1, count($sent));

        [$reminder, , $sent] = self::reminder([self::dueRow('2026-10-14', '', '2026-10-15 08:00:00')]);
        $reminder->run('2026-10-16');
        self::assertCount(0, $sent, 'already alerted');
    }
}
