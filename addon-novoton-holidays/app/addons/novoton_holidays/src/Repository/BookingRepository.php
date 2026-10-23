<?php

declare(strict_types=1);

/**
 * Novoton Holidays - Booking Repository
 *
 * Centralized database access for booking data.
 *
 * @package NovotonHolidays
 * @since 2.8.0
 */

namespace Tygh\Addons\NovotonHolidays\Repository;

use Tygh\Addons\NovotonHolidays\Constants;
use Tygh\Addons\NovotonHolidays\Helpers\JsonDecoder;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Repository\RowNarrowingTrait;
use Tygh\Addons\TravelCore\Services\GuestDataNormalizer;

class BookingRepository implements BookingRepositoryInterface
{
    use RowNarrowingTrait;

    /**
     * Request-scoped memo cache for hydrated bookings.
     * Prevents the same booking's rooms_data/guests_data from being
     * decoded 2-3 times within a single request cycle.
     *
     * @var array<int, array<string, mixed>>
     */
    private static array $hydratedCache = [];

    /** @var int Nesting depth of withTransaction() calls (0 = no active transaction). */
    private static int $txDepth = 0;

    private readonly BookingSyncRepositoryInterface $syncRepo;
    private readonly BookingQueryRepository $queryRepo;

    /**
     * @param BookingSyncRepositoryInterface|null $syncRepo Mirror writer for the
     *                                                      shared travel_bookings table; defaults to the concrete repository
     *                                                      so existing `new BookingRepository()` call sites keep working.
     * @param BookingQueryRepository|null $queryRepo Read-model query collaborator
     *                                               (list/reporting reads); defaults to the concrete repository.
     */
    public function __construct(?BookingSyncRepositoryInterface $syncRepo = null, ?BookingQueryRepository $queryRepo = null)
    {
        $this->syncRepo = $syncRepo ?? new BookingSyncRepository();
        $this->queryRepo = $queryRepo ?? new BookingQueryRepository();
    }

    /**
     * Find booking by ID (raw DB row, no JSON decoding).
     * @return array<string, mixed>|null
     */
    public function findById(int $booking_id): ?array
    {
        $booking = self::asRow(db_get_row('SELECT * FROM ?:novoton_bookings WHERE booking_id = ?i', $booking_id));
        return $booking === [] ? null : $booking;
    }

    /**
     * Find booking by ID with JSON fields decoded and memo-cached.
     *
     * Decodes rooms_data and guests_data once per request. Subsequent
     * calls for the same booking_id return the cached result.
     *
     * @param bool $force Bypass cache (e.g. after an update)
     * @return array<string, mixed>|null Hydrated booking or null
     */
    public function findByIdHydrated(int $booking_id, bool $force = false): ?array
    {
        if (!$force && isset(self::$hydratedCache[$booking_id])) {
            return self::$hydratedCache[$booking_id];
        }

        $booking = $this->findById($booking_id);
        if ($booking === null) {
            return null;
        }

        $booking = self::hydrateJsonFields($booking);

        // Prevent unbounded cache growth in long-running processes (cron)
        if (count(self::$hydratedCache) > Constants::HYDRATED_CACHE_MAX) {
            self::$hydratedCache = array_slice(self::$hydratedCache, -Constants::HYDRATED_CACHE_TRIM, null, true);
        }
        self::$hydratedCache[$booking_id] = $booking;

        return $booking;
    }

    /**
     * Decode JSON fields on a raw booking row in-place.
     *
     * Call this instead of scattered json_decode() calls to ensure
     * each field is decoded exactly once.
     *
     * @param array<string, mixed> $booking Raw DB row
     * @return array<string, mixed> Booking with rooms_data_parsed, guests_data_parsed
     */
    public static function hydrateJsonFields(array $booking): array
    {
        // rooms_data
        $roomsRaw = $booking['rooms_data'] ?? '';
        $booking['rooms_data_parsed'] = JsonDecoder::decode(is_string($roomsRaw) ? $roomsRaw : '', 'rooms_data');

        // guests_data — always normalize to canonical keyed format
        if (!empty($booking['guests_data'])) {
            $booking['guests_data_parsed'] = (new GuestDataNormalizer())->normalize(TypeCoerce::toStringMap($booking['guests_data']));
        } else {
            $booking['guests_data_parsed'] = [];
        }

        return $booking;
    }

    /**
     * Invalidate the memo cache for a specific booking (e.g. after update).
     */
    public static function invalidateCache(int $booking_id = 0): void
    {
        if ($booking_id > 0) {
            unset(self::$hydratedCache[$booking_id]);
        } else {
            self::$hydratedCache = [];
        }
    }

    /**
     * Find bookings by order ID
     * @return list<array<string, mixed>>
     */
    public function findByOrderId(int $order_id): array
    {
        return $this->queryRepo->findByOrderId($order_id);
    }

    /**
     * Find bookings by user ID
     * @return list<array<string, mixed>>
     */
    public function findByUserId(int $user_id, int $limit = 100): array
    {
        return $this->queryRepo->findByUserId($user_id, $limit);
    }

    /**
     * Find bookings by session ID
     * @return list<array<string, mixed>>
     */
    public function findBySessionId(string $session_id): array
    {
        return $this->queryRepo->findBySessionId($session_id);
    }

    /**
     * Find bookings by hotel ID
     * @return list<array<string, mixed>>
     */
    public function findByHotelId(string $hotel_id, int $limit = 100): array
    {
        return $this->queryRepo->findByHotelId($hotel_id, $limit);
    }

    /**
     * Find pending bookings
     * @return list<array<string, mixed>>
     */
    public function findPending(int $limit = 500): array
    {
        return $this->queryRepo->findPending($limit);
    }

    /**
     * Find bookings with Novoton reservation ID
     * @return list<array<string, mixed>>
     */
    public function findWithReservationId(int $limit = 1000): array
    {
        return $this->queryRepo->findWithReservationId($limit);
    }

    /**
     * Find existing booking (for duplicate prevention)
     * @return array<string, mixed>|null
     */
    public function findExisting(string $hotel_id, string $check_in, string $check_out, string $holder_name, int $hours = 1): ?array
    {
        $booking = self::asRow(db_get_row(
            'SELECT * FROM ?:novoton_bookings
             WHERE order_id = 0
               AND hotel_id = ?s
               AND check_in = ?s
               AND check_out = ?s
               AND holder_name = ?s
               AND created_at > DATE_SUB(NOW(), INTERVAL ?i HOUR)
             LIMIT 1',
            $hotel_id,
            $check_in,
            $check_out,
            $holder_name,
            $hours,
        ));

        return $booking === [] ? null : $booking;
    }

    /**
     * Create new booking
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        $data = self::filterNullValues($data);

        return $this->withTransaction(function () use ($data): int {
            $booking_id = TypeCoerce::toInt(db_query('INSERT INTO ?:novoton_bookings ?e', $data));

            if ($booking_id > 0) {
                $this->syncRepo->upsertFromBooking($booking_id, $data);
            }

            return $booking_id;
        });
    }

    /**
     * Update booking
     * @param array<string, mixed> $data
     */
    public function update(int $booking_id, array $data): bool
    {
        $data = self::filterNullValues($data);

        return $this->withTransaction(function () use ($booking_id, $data): bool {
            // db_query() returns affected rows for UPDATE. A return of 0 means
            // "query succeeded but no rows changed" (data identical) — NOT failure.
            // We only fail if the booking doesn't exist at all.
            db_query('UPDATE ?:novoton_bookings SET ?u WHERE booking_id = ?i', $data, $booking_id);

            $this->syncRepo->applyBookingUpdate($booking_id, $data);

            return true;
        });
    }

    /**
     * Update booking status
     */
    public function updateStatus(int $booking_id, string $status, string $novoton_status = ''): bool
    {
        $data = ['status' => $status];
        if (!empty($novoton_status)) {
            $data['novoton_status'] = $novoton_status;
        }
        return $this->update($booking_id, $data);
    }

    /**
     * Link booking to order
     */
    public function linkToOrder(int $booking_id, int $order_id): bool
    {
        return $this->update($booking_id, ['order_id' => $order_id]);
    }

    /**
     * Store API request/response
     * @param mixed $request
     * @param mixed $response
     */
    public function storeApiData(int $booking_id, $request, $response): bool
    {
        return $this->update($booking_id, [
            'api_request' => is_array($request) ? json_encode($request) : $request,
            'api_response' => is_array($response) ? json_encode($response) : $response,
        ]);
    }

    /**
     * Delete booking
     */
    public function delete(int $booking_id): bool
    {
        return $this->withTransaction(function () use ($booking_id): bool {
            $this->syncRepo->deleteByBookingId($booking_id);
            return (bool) db_query('DELETE FROM ?:novoton_bookings WHERE booking_id = ?i', $booking_id);
        });
    }

    /**
     * Run $callback inside a DB transaction. Nesting-aware: only the outermost
     * call issues START/COMMIT, so a nested call cannot implicitly COMMIT the
     * outer transaction (MySQL has no true nested transactions) and a failure at
     * any depth rolls the whole unit back. Guards against the class of bug where
     * a caller wraps create()/update()/delete() in its own transaction and
     * silently loses atomicity.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function withTransaction(callable $callback): mixed
    {
        if (self::$txDepth > 0) {
            // An outer withTransaction() already owns the active transaction — join it.
            self::$txDepth++;
            try {
                return $callback();
            } finally {
                self::$txDepth--;
            }
        }

        db_query('START TRANSACTION');
        self::$txDepth = 1;
        try {
            $result = $callback();
            db_query('COMMIT');
            return $result;
        } catch (\Throwable $e) {
            db_query('ROLLBACK');
            throw $e;
        } finally {
            self::$txDepth = 0;
        }
    }

    /**
     * Delete orphan bookings (not linked to orders, older than X hours)
     */
    public function deleteOrphans(int $hours = 24): int
    {
        // Clean up matching travel_bookings first
        $this->syncRepo->deleteOrphansOlderThan($hours);

        $affected = db_query(
            'DELETE FROM ?:novoton_bookings
             WHERE order_id = 0
               AND created_at < DATE_SUB(NOW(), INTERVAL ?i HOUR)',
            $hours,
        );
        return TypeCoerce::toInt($affected);
    }

    /**
     * Link unassigned bookings to a user by session ID.
     *
     * Used after login to claim guest bookings from the current browser session.
     *
     * @return int Number of bookings linked
     */
    public function linkToUserBySession(int $user_id, string $session_id): int
    {
        // No account, or no session: an empty session id would claim every
        // session-less guest booking (C1).
        if ($user_id <= 0 || trim($session_id) === '') {
            return 0;
        }

        $affected = TypeCoerce::toInt(db_query(
            'UPDATE ?:novoton_bookings SET user_id = ?i WHERE session_id = ?s AND user_id = 0 AND order_id = 0',
            $user_id,
            $session_id,
        ));

        if ($affected > 0) {
            // Mirror the new owner onto travel_bookings for these bookings
            $id_strings = self::asStringList(db_get_fields(
                'SELECT booking_id FROM ?:novoton_bookings WHERE session_id = ?s AND user_id = ?i',
                $session_id,
                $user_id,
            ));
            $this->syncRepo->assignUser($user_id, $id_strings);
        }

        return $affected;
    }

    /**
     * Link unassigned bookings to a user by email.
     *
     * Used after login/registration to claim bookings made with the same email.
     *
     * @return int Number of bookings linked
     */
    public function linkToUserByEmail(int $user_id, string $email): int
    {
        // Get affected booking IDs before the update
        $id_strings = self::asStringList(db_get_fields(
            'SELECT booking_id FROM ?:novoton_bookings WHERE guest_email = ?s AND user_id = 0',
            $email,
        ));

        $affected = TypeCoerce::toInt(db_query(
            'UPDATE ?:novoton_bookings SET user_id = ?i WHERE guest_email = ?s AND user_id = 0',
            $user_id,
            $email,
        ));

        if ($affected > 0) {
            $this->syncRepo->assignUser($user_id, $id_strings);
        }

        return $affected;
    }

    /**
     * Delete all bookings for a product (used when product is deleted).
     *
     * @return int Number of bookings deleted
     */
    public function deleteByProductId(int $product_id): int
    {
        // Clean up travel_bookings for these bookings
        $id_strings = self::asStringList(db_get_fields(
            'SELECT booking_id FROM ?:novoton_bookings WHERE product_id = ?i',
            $product_id,
        ));
        $this->syncRepo->deleteByBookingIds($id_strings);

        return TypeCoerce::toInt(db_query('DELETE FROM ?:novoton_bookings WHERE product_id = ?i', $product_id));
    }

    /**
     * Get raw guests_data JSON for a booking.
     */
    public function getGuestsData(int $booking_id): ?string
    {
        $data = TypeCoerce::toString(db_get_field('SELECT guests_data FROM ?:novoton_bookings WHERE booking_id = ?i', $booking_id));
        return $data === '' ? null : $data;
    }

    /**
     * Find the most recent unassigned pending booking matching hotel + dates.
     *
     * Used as a fallback to recover guests_data when cart data is stale.
     * @return array<string, mixed>|null
     */
    public function findUnassignedByHotelDates(string $hotel_id, string $check_in, string $check_out): ?array
    {
        $row = self::asRow(db_get_row(
            'SELECT guests_data, holder_name FROM ?:novoton_bookings
             WHERE hotel_id = ?s AND check_in = ?s AND check_out = ?s AND order_id = 0
             ORDER BY booking_id DESC LIMIT 1',
            $hotel_id,
            $check_in,
            $check_out,
        ));
        return $row === [] ? null : $row;
    }

    /**
     * Find bookings for multiple order IDs in a single batch query.
     *
     * @param list<int> $order_ids
     * @return list<array<string, mixed>> Booking summary rows
     */
    public function findByOrderIds(array $order_ids): array
    {
        return $this->queryRepo->findByOrderIds($order_ids);
    }

    /**
     * Get booking terms (payment + cancellation) for order display.
     * @return array<string, mixed>|null
     */
    public function getTerms(int $booking_id): ?array
    {
        $terms = self::asRow(db_get_row(
            'SELECT terms_of_payment_raw, terms_of_cancellation_raw,
                    terms_of_payment_formatted, terms_of_cancellation_formatted
             FROM ?:novoton_bookings WHERE booking_id = ?i',
            $booking_id,
        ));
        return $terms === [] ? null : $terms;
    }

    /**
     * Find existing booking ID by order + hotel + dates (for dedup).
     */
    public function findIdByOrderAndHotelDates(int $order_id, string $hotel_id, string $check_in, string $check_out, int $room_number = 0): ?int
    {
        // With a room group number: that group's row only. Every package
        // group of one booking shares the order, hotel and dates, so without
        // it group 2 found (and overwrote) group 1's row.
        $group = $room_number > 0 ? db_quote('AND room_number = ?i', $room_number) : '';
        $id = TypeCoerce::toInt(db_get_field(
            'SELECT booking_id FROM ?:novoton_bookings
             WHERE order_id = ?i AND hotel_id = ?s AND check_in = ?s AND check_out = ?s ?p
             LIMIT 1',
            $order_id,
            $hotel_id,
            $check_in,
            $check_out,
            $group,
        ));
        return $id > 0 ? $id : null;
    }

    /**
     * Find bookings by Novoton API status (e.g. ASK, RQ).
     *
     * @param string $novoton_status API-level status (e.g. 'ASK')
     * @param list<string> $statuses Internal statuses to match
     * @param int $limit Max rows
     * @return list<array<string, mixed>>
     */
    public function findByNovotonStatus(string $novoton_status, array $statuses, int $limit = 50): array
    {
        return $this->queryRepo->findByNovotonStatus($novoton_status, $statuses, $limit);
    }

    /**
     * Find RQ bookings that haven't had alternatives requested yet.
     * @return list<array<string, mixed>>
     */
    public function findRqWithoutAlternatives(int $limit = 50): array
    {
        return $this->queryRepo->findRqWithoutAlternatives($limit);
    }

    /**
     * Filter null values from data array to prevent PHP 8.1+
     * real_escape_string() deprecation when passed to ?e / ?u placeholders.
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function filterNullValues(array $data): array
    {
        return array_filter($data, static fn ($v): bool => $v !== null);
    }
}
