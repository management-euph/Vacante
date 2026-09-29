<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Repository;

use Tygh\Addons\TravelCore\Helpers\RegistryCoerce;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * travel_balances — what is still owed on a booking paid with a deposit.
 *
 * One row per deposit order item. Amounts are in the store's primary
 * currency (the cart-line scale the deposit was charged in). The balance is
 * paid with its own CS-Cart order (balance_order_id), placed from the pay
 * link through the normal checkout, so any payment method works.
 *
 * status: open → paid (the balance order reached P/C) | cancelled (the
 * deposit order was cancelled or declined).
 */
class BalanceRepository
{
    public const STATUS_OPEN = 'open';
    public const STATUS_PAID = 'paid';
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Same statement as addon.xml's install/upgrade items; also run lazily
     * before the first write, so a store that upgraded without an admin
     * visit can still record the balance at checkout.
     */
    public const CREATE_SQL = 'CREATE TABLE IF NOT EXISTS `?:travel_balances` (
        `balance_id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `order_id`            INT UNSIGNED  NOT NULL COMMENT \'The deposit order\',
        `item_id`             VARCHAR(64)   NOT NULL COMMENT \'Order item (cart id)\',
        `product_id`          INT UNSIGNED  NOT NULL DEFAULT 0,
        `user_id`             INT UNSIGNED  NOT NULL DEFAULT 0,
        `provider`            VARCHAR(50)   NOT NULL DEFAULT \'\',
        `hotel_name`          VARCHAR(255)  DEFAULT NULL,
        `check_in`            DATE          DEFAULT NULL,
        `check_out`           DATE          DEFAULT NULL,
        `full_amount`         DECIMAL(12,2) NOT NULL DEFAULT 0,
        `deposit_amount`      DECIMAL(12,2) NOT NULL DEFAULT 0,
        `amount`              DECIMAL(12,2) NOT NULL COMMENT \'Balance, primary currency\',
        `due_date`            DATE          NOT NULL,
        `status`              VARCHAR(12)   NOT NULL DEFAULT \'open\' COMMENT \'open, paid, cancelled\',
        `balance_order_id`    INT UNSIGNED  DEFAULT NULL COMMENT \'Order that pays the balance\',
        `reminders_sent`      VARCHAR(20)   NOT NULL DEFAULT \'\' COMMENT \'Days-before marks already emailed, e.g. 7,2\',
        `overdue_notified_at` DATETIME      DEFAULT NULL,
        `paid_at`             DATETIME      DEFAULT NULL,
        `created_at`          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `uq_order_item` (`order_id`, `item_id`),
        KEY `idx_status_due` (`status`, `due_date`),
        KEY `idx_balance_order` (`balance_order_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';

    private static bool $ensured = false;

    public static function ensureTable(): void
    {
        if (!self::$ensured) {
            db_query(self::CREATE_SQL);
            self::$ensured = true;
        }
    }

    /** @param array<string, mixed> $row */
    public function insertIfNew(array $row): void
    {
        self::ensureTable();
        db_query('INSERT IGNORE INTO ?:travel_balances ?e', $row);
    }

    /** @return array<string, mixed>|null */
    public function find(int $balanceId): ?array
    {
        $row = db_get_row('SELECT * FROM ?:travel_balances WHERE balance_id = ?i', $balanceId);

        return is_array($row) && $row !== [] ? TypeCoerce::toStringMap($row) : null;
    }

    /** @return list<array<string, mixed>> */
    public function forOrder(int $orderId): array
    {
        if (!$this->tableExists()) {
            return [];
        }

        return array_map(
            [TypeCoerce::class, 'toStringMap'],
            TypeCoerce::toRowList(db_get_array('SELECT * FROM ?:travel_balances WHERE order_id = ?i ORDER BY balance_id', $orderId)),
        );
    }

    /** @return list<array<string, mixed>> */
    public function paidBy(int $balanceOrderId): array
    {
        if (!$this->tableExists()) {
            return [];
        }

        return array_map(
            [TypeCoerce::class, 'toStringMap'],
            TypeCoerce::toRowList(db_get_array('SELECT * FROM ?:travel_balances WHERE balance_order_id = ?i', $balanceOrderId)),
        );
    }

    /**
     * Open balances whose deposit order is paid (P/C), due on or before $until.
     *
     * @return list<array<string, mixed>>
     */
    public function openDueBy(string $until): array
    {
        if (!$this->tableExists()) {
            return [];
        }

        return array_map(
            [TypeCoerce::class, 'toStringMap'],
            TypeCoerce::toRowList(db_get_array(
                'SELECT b.*, o.email, o.firstname, o.lastname, o.lang_code, o.status AS order_status'
                . ' FROM ?:travel_balances b INNER JOIN ?:orders o ON o.order_id = b.order_id'
                . ' WHERE b.status = ?s AND b.due_date <= ?s AND o.status IN (?a)'
                . ' ORDER BY b.due_date',
                self::STATUS_OPEN,
                $until,
                ['P', 'C'],
            )),
        );
    }

    /** @param array<string, mixed> $fields */
    public function update(int $balanceId, array $fields): void
    {
        db_query('UPDATE ?:travel_balances SET ?u WHERE balance_id = ?i', $fields, $balanceId);
    }

    public function cancelOpenForOrder(int $orderId): void
    {
        if ($this->tableExists()) {
            db_query(
                'UPDATE ?:travel_balances SET status = ?s WHERE order_id = ?i AND status = ?s',
                self::STATUS_CANCELLED,
                $orderId,
                self::STATUS_OPEN,
            );
        }
    }

    private function tableExists(): bool
    {
        if (self::$ensured) {
            return true;
        }
        $exists = TypeCoerce::toInt(db_get_field(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?s',
            RegistryCoerce::string('config.table_prefix') . 'travel_balances',
        )) > 0;
        if ($exists) {
            self::$ensured = true;
        }

        return $exists;
    }
}
