<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Services;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Repository\BalanceRepository;
use Tygh\Addons\TravelCore\Repository\HotelAvailabilityRepository;
use Tygh\Addons\TravelCore\TravelConstants;

/**
 * A closed hotel's page redirects (301) to another page
 * (docs/novoton-availability-gaps-plan.md §7.4, §9): by default to its resort
 * page (the product's main category), or to a product the admin picks — the
 * same hotel at another supplier, or a similar hotel. Never a 404 or 410,
 * never a deleted product: the product stays Hidden, and clearing the
 * "closed" decision brings the page back under its old address.
 *
 * Closing a hotel schedules the redirect for the closing day plus 6 months
 * (AvailabilityOverride); the admin can move that day, today included.
 * From that day a hotel's page redirects only while
 *  - its supplier is in Automatic mode (Report only changes nothing for
 *    customers),
 *  - its product is Hidden (an admin who shows it again wants it seen),
 *  - nobody has a booking still to come there or a balance to pay for it
 *    (they may need the page), and
 *  - there is a page to send them to.
 *
 * Which pages redirect is worked out by the daily run and after each admin
 * change, and kept in storage_data: the storefront reads one value per
 * product page, and never depends on the availability tables.
 */
final class ClosedHotelRedirects
{
    /** storage_data key: the hotels whose pages redirect, and those that wait (JSON). */
    public const string STORAGE_KEY = 'travel_core_closed_redirects';

    /** Why a closed hotel's page does not redirect yet. */
    public const string WAIT_NOT_HIDDEN = 'not_hidden';

    public const string WAIT_BOOKINGS = 'bookings';

    public const string WAIT_BALANCE = 'balance';

    public const string WAIT_NO_TARGET = 'no_target';

    /** redirect_to: another product ('product:123'); empty = the resort page. */
    public const string TARGET_PRODUCT = 'product:';

    /** The active map's targets. */
    private const string TARGET_CATEGORY = 'category:';

    /** @var \Closure(string): string */
    private readonly \Closure $read;

    /** @var \Closure(string, string): void */
    private readonly \Closure $write;

    /** @var \Closure(): list<string> */
    private readonly \Closure $automatic;

    /** @var \Closure(string): string */
    private readonly \Closure $url;

    /**
     * @param (\Closure(string): string)|null $read storage_data read ('' when missing)
     * @param (\Closure(string, string): void)|null $write storage_data write
     * @param (\Closure(): list<string>)|null $automatic the suppliers in Automatic mode the shared gate acts for
     * @param (\Closure(string): string)|null $url a dispatch => the storefront URL (fn_url)
     */
    public function __construct(?\Closure $read = null, ?\Closure $write = null, ?\Closure $automatic = null, ?\Closure $url = null)
    {
        $this->read = $read ?? static fn (string $key): string => function_exists('fn_get_storage_data') ? TypeCoerce::toString(fn_get_storage_data($key)) : '';
        $this->write = $write ?? static function (string $key, string $value): void {
            if (function_exists('fn_set_storage_data')) {
                fn_set_storage_data($key, $value);
            }
        };
        $this->automatic = $automatic ?? static fn (): array => AvailabilityReview::automatic(TravelProviderRegistry::getAvailabilitySources());
        $this->url = $url ?? static fn (string $dispatch): string => TypeCoerce::toString(fn_url($dispatch));
    }

    /**
     * The storefront: where this product's page redirects now, or null.
     * Never throws: a page shows rather than fail.
     */
    public function urlFor(int $productId): ?string
    {
        if ($productId <= 0) {
            return null;
        }
        try {
            $target = $this->status()['active'][$productId] ?? '';
            if (str_starts_with($target, self::TARGET_PRODUCT)) {
                return ($this->url)('products.view?product_id=' . TypeCoerce::toInt(substr($target, strlen(self::TARGET_PRODUCT))));
            }
            if (str_starts_with($target, self::TARGET_CATEGORY)) {
                return ($this->url)('categories.view?category_id=' . TypeCoerce::toInt(substr($target, strlen(self::TARGET_CATEGORY))));
            }
        } catch (\Throwable) {
        }

        return null;
    }

    /**
     * The last refresh: product id => target ('product:12', 'category:7') for
     * the pages that redirect, product id => why for those that wait.
     *
     * @return array{active: array<int, string>, waiting: array<int, string>}
     */
    public function status(): array
    {
        $stored = json_decode(($this->read)(self::STORAGE_KEY), true);
        $out = ['active' => [], 'waiting' => []];
        foreach (['active', 'waiting'] as $part) {
            foreach (is_array($stored) && is_array($stored[$part] ?? null) ? $stored[$part] : [] as $productId => $value) {
                if (TypeCoerce::toInt($productId) > 0 && is_string($value)) {
                    $out[$part][TypeCoerce::toInt($productId)] = $value;
                }
            }
        }

        return $out;
    }

    /**
     * Works out which closed hotels' pages redirect from today, for the
     * suppliers in Automatic mode, and stores it. Never throws: on a failure
     * the last list stays, and the failure is logged (the daily review and
     * an admin's decision go on).
     *
     * @return array{active: array<int, string>, waiting: array<int, string>}
     */
    public function refresh(\DateTimeImmutable $now): array
    {
        try {
            $result = $this->compute($now);
        } catch (\Throwable $e) {
            fn_log_event('general', 'runtime', ['message' => '[TravelAvailability] closed hotel redirects: ' . $e->getMessage()]);

            return $this->status();
        }
        ($this->write)(self::STORAGE_KEY, (string) json_encode($result + ['at' => $now->format('Y-m-d H:i:s')]));

        return $result;
    }

    /**
     * @return array{active: array<int, string>, waiting: array<int, string>}
     */
    private function compute(\DateTimeImmutable $now): array
    {
        $result = ['active' => [], 'waiting' => []];
        $providers = ($this->automatic)();
        if ($providers !== []) {
            HotelAvailabilityRepository::ensureTables();
            BalanceRepository::ensureTable();
            $today = $now->format('Y-m-d');
            $rows = TypeCoerce::toRowList(db_get_array(
                "SELECT a.provider, a.hotel_id, a.product_id, a.redirect_to, COALESCE(p.status, '') AS status
                 FROM ?:travel_hotel_availability a
                 LEFT JOIN ?:products p ON p.product_id = a.product_id
                 WHERE a.provider IN (?a) AND a.product_id > 0 AND a.override = ?s
                   AND a.redirect_from IS NOT NULL AND a.redirect_from <= ?s",
                $providers,
                AvailabilityGate::CLOSED,
                $today,
            ));
            foreach ($rows as $row) {
                $productId = TypeCoerce::toInt($row['product_id'] ?? 0);
                $wait = $this->waitsFor($row, $productId, $today);
                if ($wait !== '') {
                    $result['waiting'][$productId] = $wait;
                    continue;
                }
                $target = $this->target(TypeCoerce::toString($row['redirect_to'] ?? ''), $productId);
                if ($target === '') {
                    $result['waiting'][$productId] = self::WAIT_NO_TARGET;
                } else {
                    $result['active'][$productId] = $target;
                }
            }
        }

        return $result;
    }

    /**
     * The admin's redirect for a closed hotel: to the product with this code
     * ('' = the hotel's resort page), from this day (Y-m-d; today or earlier
     * redirects at once). Applied at once.
     *
     * @param string $provider the supplier, such as 'novoton'
     * @param string $hotelId the supplier's hotel id
     * @param string $targetCode another product's code, or '' for the resort page
     * @param string $from the redirect's first day (Y-m-d)
     * @param int $userId the admin, for the state log
     * @param \DateTimeImmutable $now when the admin saved it
     *
     * @return 'saved'|'unknown'|'not_closed'|'bad_target'|'bad_date'
     */
    public function configure(string $provider, string $hotelId, string $targetCode, string $from, int $userId, \DateTimeImmutable $now): string
    {
        HotelAvailabilityRepository::ensureTables();
        $record = TypeCoerce::toStringMap(db_get_row(
            'SELECT product_id, state, override FROM ?:travel_hotel_availability WHERE provider = ?s AND hotel_id = ?s',
            $provider,
            $hotelId,
        ));
        if ($record === []) {
            return 'unknown';
        }
        if (TypeCoerce::toString($record['override'] ?? '') !== AvailabilityGate::CLOSED) {
            return 'not_closed';
        }
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $from);
        if ($day === false || $day->format('Y-m-d') !== $from) {
            return 'bad_date';
        }
        $productId = TypeCoerce::toInt($record['product_id'] ?? 0);
        $redirectTo = '';
        $targetCode = trim($targetCode);
        if ($targetCode !== '') {
            $targetId = TypeCoerce::toInt(db_get_field('SELECT product_id FROM ?:products WHERE product_code = ?s', $targetCode));
            // Never to itself, nor to another closed hotel: a redirect must not loop.
            $closed = $targetId > 0 && TypeCoerce::toInt(db_get_field(
                'SELECT COUNT(*) FROM ?:travel_hotel_availability WHERE product_id = ?i AND override = ?s',
                $targetId,
                AvailabilityGate::CLOSED,
            )) > 0;
            if ($targetId <= 0 || $targetId === $productId || $closed) {
                return 'bad_target';
            }
            $redirectTo = self::TARGET_PRODUCT . $targetId;
        }

        db_query(
            'UPDATE ?:travel_hotel_availability SET redirect_to = ?s, redirect_from = ?s WHERE provider = ?s AND hotel_id = ?s',
            $redirectTo,
            $from,
            $provider,
            $hotelId,
        );
        $state = TypeCoerce::toString($record['state'] ?? '');
        db_query(
            'INSERT INTO ?:travel_hotel_state_log (provider, hotel_id, product_id, old_state, new_state, reason, user_id, created_at)
             VALUES (?s, ?s, ?i, ?s, ?s, ?s, ?i, ?s)',
            $provider,
            $hotelId,
            $productId,
            $state,
            $state,
            'redirect_set',
            $userId,
            $now->format('Y-m-d H:i:s'),
        );
        $this->refresh($now);

        return 'saved';
    }

    /**
     * The Hotel availability page's line for each closed hotel on it: the
     * redirect's state (redirects now, waits and why, starts on a day, or
     * waits for Automatic mode), from when, the product code the admin chose
     * ('' = the resort page), and where it goes: that code, or '' for the
     * resort page (also when the chosen product is no longer active).
     *
     * @param list<array<string, mixed>> $rows the page's records
     * @param array<string, bool> $automatic the suppliers in Automatic mode the shared gate acts for
     *
     * @return array<int, array{status: 'active'|'waiting'|'scheduled'|'report', why: string, from: string, code: string, target: string}>
     */
    public function describe(array $rows, array $automatic): array
    {
        $closed = array_values(array_filter(
            $rows,
            static fn (array $row): bool => TypeCoerce::toString($row['override'] ?? '') === AvailabilityGate::CLOSED && TypeCoerce::toInt($row['product_id'] ?? 0) > 0,
        ));
        if ($closed === []) {
            return [];
        }
        $targets = [];
        foreach ($closed as $row) {
            $redirectTo = TypeCoerce::toString($row['redirect_to'] ?? '');
            if (str_starts_with($redirectTo, self::TARGET_PRODUCT)) {
                $targets[] = TypeCoerce::toInt(substr($redirectTo, strlen(self::TARGET_PRODUCT)));
            }
        }
        // Product ids come back as int keys.
        $codes = [];
        if ($targets !== []) {
            $found = db_get_hash_single_array(
                'SELECT product_id, product_code FROM ?:products WHERE product_id IN (?n)',
                ['product_id', 'product_code'],
                array_values(array_unique($targets)),
            );
            foreach (is_array($found) ? $found : [] as $id => $code) {
                $codes[TypeCoerce::toInt($id)] = TypeCoerce::toString($code);
            }
        }
        $status = $this->status();

        $out = [];
        foreach ($closed as $row) {
            $productId = TypeCoerce::toInt($row['product_id'] ?? 0);
            $redirectTo = TypeCoerce::toString($row['redirect_to'] ?? '');
            $code = str_starts_with($redirectTo, self::TARGET_PRODUCT)
                ? $codes[TypeCoerce::toInt(substr($redirectTo, strlen(self::TARGET_PRODUCT)))] ?? ''
                : '';
            $out[$productId] = [
                'status' => match (true) {
                    !($automatic[TypeCoerce::toString($row['provider'] ?? '')] ?? false) => 'report',
                    isset($status['active'][$productId]) => 'active',
                    isset($status['waiting'][$productId]) => 'waiting',
                    default => 'scheduled',
                },
                'why' => $status['waiting'][$productId] ?? '',
                'from' => substr(TypeCoerce::toString($row['redirect_from'] ?? ''), 0, 10),
                'code' => $code,
                'target' => str_starts_with($status['active'][$productId] ?? '', self::TARGET_CATEGORY) ? '' : $code,
            ];
        }

        return $out;
    }

    /**
     * Why this closed hotel's page waits, '' when it may redirect.
     *
     * @param array<string, mixed> $row
     */
    private function waitsFor(array $row, int $productId, string $today): string
    {
        if (TypeCoerce::toString($row['status'] ?? '') !== 'H') {
            return self::WAIT_NOT_HIDDEN;
        }
        // A stay not over yet (on request or confirmed): its guests may need the page.
        if (TypeCoerce::toInt(db_get_field(
            'SELECT COUNT(*) FROM ?:travel_bookings WHERE provider = ?s AND hotel_id = ?s AND check_out >= ?s AND status NOT IN (?a)',
            TypeCoerce::toString($row['provider'] ?? ''),
            TypeCoerce::toString($row['hotel_id'] ?? ''),
            $today,
            [TravelConstants::STATUS_CANCELLED, TravelConstants::STATUS_FAILED],
        )) > 0) {
            return self::WAIT_BOOKINGS;
        }
        if (TypeCoerce::toInt(db_get_field(
            'SELECT COUNT(*) FROM ?:travel_balances WHERE product_id = ?i AND status = ?s',
            $productId,
            BalanceRepository::STATUS_OPEN,
        )) > 0) {
            return self::WAIT_BALANCE;
        }

        return '';
    }

    /**
     * Where the page goes: the admin's product while it is Active, else the
     * hotel's resort page (its main category, while Active); '' for none.
     */
    private function target(string $redirectTo, int $productId): string
    {
        if (str_starts_with($redirectTo, self::TARGET_PRODUCT)) {
            $targetId = TypeCoerce::toInt(substr($redirectTo, strlen(self::TARGET_PRODUCT)));
            if ($targetId > 0 && TypeCoerce::toString(db_get_field('SELECT status FROM ?:products WHERE product_id = ?i', $targetId)) === 'A') {
                return self::TARGET_PRODUCT . $targetId;
            }
        }
        $categoryId = TypeCoerce::toInt(db_get_field(
            "SELECT pc.category_id FROM ?:products_categories pc
             INNER JOIN ?:categories c ON c.category_id = pc.category_id AND c.status = 'A'
             WHERE pc.product_id = ?i AND pc.link_type = 'M'",
            $productId,
        ));

        return $categoryId > 0 ? self::TARGET_CATEGORY . $categoryId : '';
    }
}
