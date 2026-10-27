<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Repository;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Cross-provider "no availability" requests — travel_alternative_requests.
 *
 * Guests who asked to be contacted when a hotel had no offers for their
 * dates. Novoton mirrors its provider-level requests here
 * (provider_request_id links back to novoton_alternative_requests); sphinx
 * writes here ONLY — internal follow-up, no provider API call.
 *
 * The same table keeps the hotel page's "notify me" requests
 * (request_type 'notify', NotifyRequestRepository;
 * docs/novoton-availability-gaps-plan.md §8): one email when booking opens,
 * with the guest's consent.
 *
 * @since 1.5.0
 */
class AlternativeRequestRepository
{
    /** Same statement as addon.xml's install/upgrade items (AlternativeRequestsTableTest pins it). */
    public const string CREATE_SQL = 'CREATE TABLE IF NOT EXISTS `?:travel_alternative_requests` (
        `request_id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `provider`            VARCHAR(50)  NOT NULL COMMENT \'novoton, sphinx\',
        `provider_request_id` INT UNSIGNED DEFAULT NULL COMMENT \'Row id in the provider request table, when mirrored\',
        `user_id`             INT UNSIGNED DEFAULT NULL COMMENT \'FK to ?:users\',
        `hotel_id`            VARCHAR(100) NOT NULL,
        `hotel_name`          VARCHAR(255) DEFAULT NULL,
        `product_id`          INT UNSIGNED DEFAULT NULL COMMENT \'FK to ?:products\',
        `check_in`            DATE         DEFAULT NULL,
        `check_out`           DATE         DEFAULT NULL,
        `nights`              INT UNSIGNED DEFAULT NULL,
        `num_rooms`           TINYINT UNSIGNED NOT NULL DEFAULT 1,
        `adults`              TINYINT UNSIGNED NOT NULL DEFAULT 2,
        `children`            TINYINT UNSIGNED NOT NULL DEFAULT 0,
        `children_ages`       VARCHAR(100) DEFAULT NULL,
        `contact_email`       VARCHAR(255) DEFAULT NULL,
        `contact_phone`       VARCHAR(50)  DEFAULT NULL,
        `notes`               TEXT,
        `request_type`        VARCHAR(20)  NOT NULL DEFAULT \'alternatives\' COMMENT \'alternatives, or notify: one email when booking opens\',
        `watch_for`           VARCHAR(20)  NOT NULL DEFAULT \'\' COMMENT \'notify: prices, booking or return\',
        `lang_code`           CHAR(2)      NOT NULL DEFAULT \'\' COMMENT \'notify: the language of the email\',
        `consent_at`          DATETIME     DEFAULT NULL COMMENT \'notify: when the guest ticked the consent box\',
        `consent_version`     VARCHAR(20)  NOT NULL DEFAULT \'\' COMMENT \'notify: which consent text they agreed to\',
        `status`              ENUM(\'pending\',\'pending_manual\',\'alternatives_found\',\'notified\',\'booked\',\'expired\',\'cancelled\') NOT NULL DEFAULT \'pending\',
        `created_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY `idx_provider` (`provider`),
        KEY `idx_status` (`status`),
        KEY `idx_created` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';

    /**
     * Columns added since the table was first created: what ensureTable()
     * adds to a store's older table, each defined as in CREATE_SQL.
     *
     * @var array<string, string>
     */
    public const array ADDED_COLUMNS = [
        'request_type' => 'VARCHAR(20) NOT NULL DEFAULT \'alternatives\' COMMENT \'alternatives, or notify: one email when booking opens\' AFTER `notes`',
        'watch_for' => 'VARCHAR(20) NOT NULL DEFAULT \'\' COMMENT \'notify: prices, booking or return\' AFTER `request_type`',
        'lang_code' => 'CHAR(2) NOT NULL DEFAULT \'\' COMMENT \'notify: the language of the email\' AFTER `watch_for`',
        'consent_at' => 'DATETIME DEFAULT NULL COMMENT \'notify: when the guest ticked the consent box\' AFTER `lang_code`',
        'consent_version' => 'VARCHAR(20) NOT NULL DEFAULT \'\' COMMENT \'notify: which consent text they agreed to\' AFTER `consent_at`',
    ];

    private static bool $ensured = false;

    /**
     * Creates the table, and the columns added since, when missing; once per
     * request. Stores updated by pulling code get them from the runtime heal
     * (fn_travel_core_ensure_schema), and every query that reads a later
     * column calls this first, so a cron that runs before any admin page
     * still works.
     */
    public static function ensureTable(): void
    {
        if (self::$ensured) {
            return;
        }
        db_query(self::CREATE_SQL);
        foreach (self::ADDED_COLUMNS as $column => $definition) {
            if (TypeCoerce::toString(db_get_field('SHOW COLUMNS FROM ?:travel_alternative_requests LIKE ?s', $column)) === '') {
                db_query('ALTER TABLE ?:travel_alternative_requests ADD COLUMN `' . $column . '` ' . $definition);
            }
        }
        self::$ensured = true;
    }

    /** Lets a test run the heal again (it runs once per request). */
    public static function resetEnsured(): void
    {
        self::$ensured = false;
    }

    /**
     * Statuses an admin may set manually from the shared grid. Only
     * internal-only providers (sphinx) are managed here — novoton rows
     * follow the provider workflow, whose transitions propagate via
     * updateStatusByProviderRef().
     *
     * @var list<string>
     */
    public const array MANUAL_STATUSES = ['notified', 'booked', 'cancelled'];

    /**
     * Store a request; returns the new request_id.
     *
     * @param array<string, mixed> $row
     */
    public function create(array $row): int
    {
        return TypeCoerce::toInt(db_query('INSERT INTO ?:travel_alternative_requests ?e', $row));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $requestId): ?array
    {
        $row = TypeCoerce::toStringMap(db_get_row(
            'SELECT * FROM ?:travel_alternative_requests WHERE request_id = ?i',
            $requestId,
        ));

        return $row === [] ? null : $row;
    }

    /** Set one request's status (updated_at maintained by ON UPDATE). */
    public function updateStatus(int $requestId, string $status): bool
    {
        return (bool) db_query(
            'UPDATE ?:travel_alternative_requests SET status = ?s WHERE request_id = ?i',
            $status,
            $requestId,
        );
    }

    /**
     * Propagate a provider-side transition to the mirror row, keyed by
     * (provider, provider_request_id) — the link written at create time.
     */
    public function updateStatusByProviderRef(string $provider, int $providerRequestId, string $status): bool
    {
        return (bool) db_query(
            'UPDATE ?:travel_alternative_requests SET status = ?s WHERE provider = ?s AND provider_request_id = ?i',
            $status,
            $provider,
            $providerRequestId,
        );
    }

    /** Remove the mirror row of a deleted provider request. */
    public function deleteByProviderRef(string $provider, int $providerRequestId): bool
    {
        return (bool) db_query(
            'DELETE FROM ?:travel_alternative_requests WHERE provider = ?s AND provider_request_id = ?i',
            $provider,
            $providerRequestId,
        );
    }

    /**
     * Expire stale open requests — same 30-day policy as novoton's provider
     * table. Optionally restricted to one provider (the shared cron passes
     * 'sphinx'; novoton propagates its own expiry here itself). Notify-me
     * requests are not touched: they wait up to 12 months.
     *
     * @return int Rows expired
     */
    public function expireOlderThan(int $days = 30, string $provider = ''): int
    {
        self::ensureTable();
        $providerCondition = $provider !== '' ? db_quote(' AND provider = ?s', $provider) : '';

        // Notify-me requests wait up to 12 months (NotifyRequestRepository).
        return TypeCoerce::toInt(db_query(
            "UPDATE ?:travel_alternative_requests
             SET status = 'expired'
             WHERE status IN ('pending', 'pending_manual') AND request_type <> 'notify'
             AND created_at < DATE_SUB(NOW(), INTERVAL ?i DAY) ?p",
            $days,
            $providerCondition,
        ));
    }

    /**
     * PII hygiene: hard-delete terminal rows past the retention window.
     * Only expired/cancelled rows go — notified/booked stay as history
     * (novoton keeps its own provider copy regardless).
     *
     * @return int Rows deleted
     */
    public function purgeOlderThan(int $days = 180): int
    {
        return TypeCoerce::toInt(db_query(
            "DELETE FROM ?:travel_alternative_requests
             WHERE status IN ('expired', 'cancelled')
             AND created_at < DATE_SUB(NOW(), INTERVAL ?i DAY)",
            $days,
        ));
    }

    /** Total number of stored requests. */
    public function countAll(): int
    {
        return TypeCoerce::toInt(db_get_field('SELECT COUNT(*) FROM ?:travel_alternative_requests'));
    }

    /**
     * Paginated, filterable listing for the admin grid.
     * Returns [$rows, $params] where $params carries total_items / page /
     * items_per_page — the CS-Cart pagination.tpl contract.
     *
     * @param array<string, mixed> $params
     * @return array{0: list<array<string, mixed>>, 1: array<string, mixed>}
     */
    public function getListing(array $params = []): array
    {
        self::ensureTable();
        $default_params = [
            'page' => 1,
            'items_per_page' => 50,
            'provider' => '',
            'status' => '',
            'request_type' => '',
            'q' => '',
        ];

        $params = array_merge($default_params, array_intersect_key($params, $default_params));
        $params['page'] = max(1, TypeCoerce::toInt($params['page']));
        $params['items_per_page'] = max(1, TypeCoerce::toInt($params['items_per_page']));
        $params['provider'] = trim(TypeCoerce::toString($params['provider']));
        $params['status'] = trim(TypeCoerce::toString($params['status']));
        $params['request_type'] = trim(TypeCoerce::toString($params['request_type']));
        $params['q'] = trim(TypeCoerce::toString($params['q']));

        $condition = '';
        if ($params['provider'] !== '') {
            $condition .= db_quote(' AND r.provider = ?s', $params['provider']);
        }
        if ($params['status'] !== '') {
            $condition .= db_quote(' AND r.status = ?s', $params['status']);
        }
        if ($params['request_type'] !== '') {
            $condition .= db_quote(' AND r.request_type = ?s', $params['request_type']);
        }
        if ($params['q'] !== '') {
            $condition .= db_quote(
                ' AND (r.hotel_name LIKE ?l OR r.contact_email LIKE ?l)',
                '%' . $params['q'] . '%',
                '%' . $params['q'] . '%',
            );
        }

        $params['total_items'] = TypeCoerce::toInt(db_get_field(
            'SELECT COUNT(*) FROM ?:travel_alternative_requests r WHERE 1 ?p',
            $condition,
        ));

        $offset = ($params['page'] - 1) * $params['items_per_page'];

        $rows = TypeCoerce::toRowList(db_get_array(
            'SELECT r.* FROM ?:travel_alternative_requests r'
            . ' WHERE 1 ?p'
            . ' ORDER BY r.created_at DESC, r.request_id DESC'
            . ' LIMIT ?i, ?i',
            $condition,
            $offset,
            $params['items_per_page'],
        ));

        return [$rows, $params];
    }
}
