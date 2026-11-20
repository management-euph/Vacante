<?php

declare(strict_types=1);

/**
 * Novoton Holidays - Booking Submission Service
 *
 * Extracted from order_hooks.php — encapsulates the entire booking submission
 * pipeline that runs when CS-Cart fires the place_order hook.
 *
 * Orchestration:
 *   1. hydrateBookingFromDb()       — merge DB data into cart data
 *   2. resolveRoomsAndGuests()      — parse rooms_data + guests_data
 *   3. groupRoomsByPackage()        — one request per hotel + package + stay
 *   4. buildGroupGuestsAndRooms()   — construct per-group guest+room payload
 *   5. buildApiBookingRequest()     — construct API request payload
 *   6. persistBookingRecord()       — upsert booking row (transaction)
 *   7. submitAndRecordBooking()     — call API + update status
 *
 * @package NovotonHolidays
 * @since 3.4.0
 */

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Addons\NovotonHolidays\Api\Contracts\ReservationApiClientInterface;
use Tygh\Addons\NovotonHolidays\Constants;
use Tygh\Addons\NovotonHolidays\Exceptions\ApiException;
use Tygh\Addons\NovotonHolidays\Exceptions\NovotonException;
use Tygh\Addons\NovotonHolidays\Exceptions\XmlParsingException;
use Tygh\Addons\NovotonHolidays\Helpers\BookingRoomAssembler;
use Tygh\Addons\NovotonHolidays\Repository\BookingRepositoryInterface;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Services\GuestDataNormalizer;
use Tygh\Addons\TravelCore\TravelConstants;

class BookingSubmissionService implements BookingSubmissionServiceInterface
{
    private readonly BookingRepositoryInterface $bookingRepo;
    private readonly ReservationApiClientInterface $reservations;
    private readonly GuestDataNormalizer $guestDataNormalizer;
    private readonly BookingRoomAssembler $roomAssembler;
    private readonly ApiBookingRequestBuilder $apiRequestBuilder;
    private readonly BookingRoomsGuestsResolver $roomsGuestsResolver;
    private readonly BookingRecordBuilder $recordBuilder;

    /**
     * @param (\Closure(array<string, mixed>, string): string)|null $answered a reservation the hotel refused (RQ) or
     *                                                                        cancelled (ST) as it was sent: the store is told
     *                                                                        (RequestAnswerMail); returns who was told
     */
    public function __construct(
        BookingRepositoryInterface $bookingRepo,
        ReservationApiClientInterface $reservations,
        ?GuestDataNormalizer $guestDataNormalizer = null,
        private readonly ?\Closure $answered = null,
    ) {
        $this->bookingRepo = $bookingRepo;
        $this->reservations = $reservations;
        $this->guestDataNormalizer = $guestDataNormalizer ?? new GuestDataNormalizer();
        $this->roomAssembler = new BookingRoomAssembler();
        $this->apiRequestBuilder = new ApiBookingRequestBuilder();
        $this->roomsGuestsResolver = new BookingRoomsGuestsResolver($this->guestDataNormalizer, $this->bookingRepo);
        $this->recordBuilder = new BookingRecordBuilder($this->bookingRepo);
    }

    /**
     * Submit all Novoton bookings in the cart to the API.
     *
     * Called from the fn_novoton_holidays_place_order_post hook after CS-Cart
     * creates the order record.
     *
     * For multi-room bookings (Novoton's condition for several rooms in one
     * hotel_res_RQ: the same hotel, package and stay):
     *   - Sends ALL rooms in SINGLE API request IF same hotel, package, and dates
     *     (a package compared as RoomOfferRows compares it: "+BEACH" = " BEACH")
     *   - Sends SEPARATE API calls if rooms have different packages or dates
     * @param array<string, mixed> $cart
     */
    public function submitOrder(int $orderId, array $cart): void
    {
        if (empty($orderId) || empty($cart['products'])) {
            return;
        }

        $commission = ConfigProvider::getCommission();
        $disableApi = ConfigProvider::isApiDisabled();
        $debugLogging = ConfigProvider::isDebugLogging();
        $orderComment = trim(PriceInfoFormatter::toScalar($cart['notes'] ?? ''));

        $cartProducts = TypeCoerce::toRowList($cart['products']);
        foreach ($cartProducts as $product) {
            $extra = TypeCoerce::toStringMap($product['extra'] ?? null);
            if (empty($extra['novoton_booking'])) {
                continue;
            }

            $bookingData = $extra;
            $originalBookingId = PriceInfoFormatter::toInt($bookingData['novoton_booking_id'] ?? 0);

            // Once submitted, a booking is linked to its order. CS-Cart runs
            // place_order_post again for the same cart (order edits, the parent
            // after its vendor sub-orders), and two place-order requests can run
            // at once: a second reservation would be a duplicate at the hotel.
            // So the booking is claimed for this order first, atomically, and
            // only the request that claimed it sends it.
            if (!$this->bookingRepo->claimForOrder($originalBookingId, $orderId)) {
                if ($debugLogging) {
                    fn_log_event('general', 'runtime', [
                        'message' => 'Novoton - Booking already linked to an order (or gone), not sent',
                        'booking_id' => $originalBookingId,
                        'order_id' => $orderId,
                    ]);
                }
                continue;
            }

            // 1. Hydrate booking data from DB (single source of truth)
            $bookingData = $this->hydrateBookingFromDb($bookingData, $originalBookingId, $debugLogging);

            // Resolve final price
            $finalPrice = $this->resolveFinalPrice($bookingData, $product);
            $bookingData['final_price'] = $finalPrice;

            if ($debugLogging) {
                fn_log_event('general', 'runtime', [
                    'message' => 'Novoton - Final price determined',
                    'booking_id' => $originalBookingId,
                    'final_price' => $finalPrice,
                    'hotel_id' => $bookingData['hotel_id'] ?? 'NOT SET',
                ]);
            }

            // 2. Resolve rooms and guests
            [$roomsData, $guestsData] = $this->roomsGuestsResolver->resolveRoomsAndGuests(
                $bookingData,
                $debugLogging,
            );

            // 3. Group rooms by (hotel + package + stay): one request each
            $roomGroups = $this->roomAssembler->groupRoomsByPackage($roomsData, $bookingData);

            if ($debugLogging) {
                fn_log_event('general', 'runtime', [
                    'message' => 'Novoton - Room grouping result',
                    'order_id' => $orderId,
                    'total_rooms' => count($roomsData),
                    'groups_count' => count($roomGroups),
                    'can_combine' => count($roomGroups) === 1 ? 'YES - single API call' : 'NO - multiple API calls needed',
                ]);
            }

            // 4-6. Process each group. Each booking record is persisted by its
            // own atomic repository write; there is deliberately NO transaction
            // spanning the API calls — a remote reservation cannot be undone by a
            // DB ROLLBACK, and keeping a transaction open across the network round
            // trip would lock the booking rows for the entire API call.
            // A group that fails is recorded on its own row and the next group
            // is still sent: one group's error never marks another group's
            // (possibly confirmed) row failed.
            $groupNum = 0;
            $groupCount = count($roomGroups);

            foreach ($roomGroups as $group) {
                $groupNum++;
                $bookingId = 0;

                try {
                    // 4. Build per-group guest list + API payload
                    [$allGuests, $apiRooms, $totalApiPrice, $totalGroupPrice] =
                        $this->roomAssembler->buildGroupGuestsAndRooms($group, $guestsData, $bookingData, $commission);

                    $apiData = $this->apiRequestBuilder->build(
                        $group,
                        $allGuests,
                        $apiRooms,
                        $bookingData,
                        $orderId,
                        $groupNum,
                        $groupCount,
                        $orderComment,
                    );

                    // 5. Persist booking record (upsert)
                    $bookingRecord = $this->recordBuilder->build(
                        $group,
                        $allGuests,
                        $bookingData,
                        $product,
                        $orderId,
                        $groupNum,
                        $groupCount,
                        $totalApiPrice,
                        $totalGroupPrice,
                        $apiData,
                        $disableApi,
                    );

                    $bookingId = $this->recordBuilder->persist(
                        $bookingRecord,
                        $originalBookingId,
                        $groupNum,
                        $orderId,
                    );

                    if ($debugLogging) {
                        fn_log_event('general', 'runtime', [
                            'message' => 'Novoton Booking - API Request Prepared',
                            'order_id' => $orderId,
                            'booking_id' => $bookingId,
                            'group' => $groupNum . ' of ' . $groupCount,
                            'rooms_in_group' => count($group['rooms']),
                            'api_disabled' => $disableApi ? 'YES' : 'NO',
                        ]);
                    }

                    // 6. Submit to API and record response
                    $this->submitAndRecordBooking(
                        $apiData,
                        $bookingId,
                        $orderId,
                        $groupNum,
                        $totalApiPrice,
                        $disableApi,
                        $debugLogging,
                    );
                } catch (\Throwable $e) {
                    // Before its row existed, group 1's row is the cart booking.
                    $this->recordGroupFailure($e, $bookingId > 0 ? $bookingId : ($groupNum === 1 ? $originalBookingId : 0), $originalBookingId, $orderId, $groupNum, $groupCount);
                }
            }
        }
    }

    // =========================================================================
    // PRIVATE PIPELINE METHODS
    // =========================================================================

    /**
     * A room group that failed before or while it was sent: its own row is
     * marked failed with the reason. A group whose row was never written
     * leaves a note on the cart booking's row (its status untouched: that
     * group may be booked) and the error in the log.
     */
    private function recordGroupFailure(\Throwable $e, int $rowId, int $originalBookingId, int $orderId, int $groupNum, int $groupCount): void
    {
        $reason = match (true) {
            $e instanceof ApiException => 'API Error (' . $e->getApiFunction() . ', HTTP ' . $e->getHttpCode() . '): ' . $e->getMessage(),
            $e instanceof NovotonException => 'Booking error (' . json_encode($e->getContext()) . '): ' . $e->getMessage(),
            default => 'Unexpected error: ' . $e->getMessage(),
        };

        if ($rowId > 0) {
            $this->bookingRepo->update($rowId, [
                'status' => TravelConstants::STATUS_FAILED,
                'order_id' => $orderId,
                'notes' => $reason,
            ]);
        } elseif ($originalBookingId > 0) {
            $this->bookingRepo->update($originalBookingId, [
                'notes' => "Room group {$groupNum} of {$groupCount} was not sent: {$reason}",
            ]);
        }

        fn_log_event('general', 'runtime', [
            'message' => 'Novoton Booking submission failed',
            'order_id' => $orderId,
            'booking_id' => $rowId,
            'group' => $groupNum . ' of ' . $groupCount,
            'error' => $reason,
        ]);
    }

    /**
     * Hydrate booking data from the canonical DB record.
     *
     * The database is the Single Source of Truth. Cart session data may be
     * stale or incomplete (e.g. after browser refresh), so we always merge
     * the DB row back into $bookingData before processing.
     * @param array<string, mixed> $bookingData
     * @return array<string, mixed>
     */
    private function hydrateBookingFromDb(array $bookingData, int $bookingId, bool $debug): array
    {
        if ($bookingId <= 0) {
            return $bookingData;
        }

        $dbBooking = $this->bookingRepo->findByIdHydrated($bookingId);

        if (empty($dbBooking)) {
            return $bookingData;
        }

        // DB takes priority for critical financial and identity fields
        $priorityFields = [
            'total_price', 'base_price', 'hotel_id', 'hotel_name', 'package_name',
            'room_id', 'room_type', 'board_id', 'check_in', 'check_out', 'nights',
            'adults', 'children', 'children_ages', 'num_rooms',
            'holder_name', 'guest_name',
            'terms_of_payment_raw', 'terms_of_cancellation_raw',
            'terms_of_payment_formatted', 'terms_of_cancellation_formatted',
        ];

        foreach ($priorityFields as $field) {
            if (!empty($dbBooking[$field])) {
                $bookingData[$field] = $dbBooking[$field];
            }
        }

        // Numeric casts
        $bookingData['total_price'] = PriceInfoFormatter::toFloat($bookingData['total_price']);
        $bookingData['base_price'] = PriceInfoFormatter::toFloat($bookingData['base_price'] ?? 0);

        // Structured JSON fields — prefer already-parsed arrays from hydrated cache
        if (!empty($dbBooking['rooms_data'])) {
            $bookingData['rooms_data'] = $dbBooking['rooms_data'];
        }
        if (!empty($dbBooking['guests_data'])) {
            $bookingData['guests_data'] = $dbBooking['guests_data'];
        }

        if ($debug) {
            fn_log_event('general', 'runtime', [
                'message' => 'Novoton - Fetched booking data from database',
                'booking_id' => $bookingId,
                'total_price' => $dbBooking['total_price'],
                'hotel_name' => $dbBooking['hotel_name'],
            ]);
        }

        return $bookingData;
    }

    /**
     * Determine the authoritative price for a booking.
     *
     * Priority: DB total_price > cart price > cart base_price.
     * @param array<string, mixed> $bookingData
     * @param array<string, mixed> $product
     */
    private function resolveFinalPrice(array $bookingData, array $product): float
    {
        $price = PriceInfoFormatter::toFloat($bookingData['total_price'] ?? 0);
        if ($price > 0) {
            return $price;
        }

        $price = PriceInfoFormatter::toFloat($product['price'] ?? 0);
        if ($price > 0) {
            return $price;
        }

        return PriceInfoFormatter::toFloat($product['base_price'] ?? 0);
    }

    /**
     * Submit booking to the Novoton reservation API and update the DB row.
     *
     * Uses Constants::NOVOTON_STATUS_TO_INTERNAL for status mapping.
     * @param array<string, mixed> $apiData
     */
    private function submitAndRecordBooking(
        array $apiData,
        int $bookingId,
        int $orderId,
        int $groupNum,
        float $totalApiPrice,
        bool $disableApi,
        bool $debug,
    ): void {
        if ($disableApi) {
            $this->bookingRepo->update($bookingId, ['notes' => 'API submission disabled - booking saved locally only.']);
            return;
        }

        $orderNum = TypeCoerce::toString($apiData['order_num'] ?? '');

        try {
            $response = $this->reservations->createReservation($apiData);

            $novotonId = (string) ($response->IdNum ?? '');
            $novotonStatus = Constants::normalizeApiStatus((string) ($response->Status ?? ''));
            $novotonPrice = (string) ($response->Price ?? '');

            $update = [
                'novoton_invoice_id' => $novotonId,
                'novoton_status' => $novotonStatus,
                'api_price' => !empty($novotonPrice) ? (float) $novotonPrice : $totalApiPrice,
                'api_response' => json_encode([
                    'IdNum' => $novotonId,
                    'Price' => $novotonPrice,
                    'Currency' => (string) ($response->Currency ?? 'EUR'),
                    'Quota' => (string) ($response->Quota ?? ''),
                    'Status' => $novotonStatus,
                ]),
            ];

            // Map API status to internal status via centralized constant.
            // An answer without a reservation number booked nothing that can
            // be looked up: failed, with the reference to check it by, never
            // a silent "pending".
            $statusMap = Constants::NOVOTON_STATUS_TO_INTERNAL;
            if ($novotonId === '' || $novotonId === '0') {
                $update['status'] = TravelConstants::STATUS_FAILED;
                $update['notes'] = 'Novoton answered without a reservation number (Status: '
                    . ($novotonStatus !== '' ? $novotonStatus : 'none')
                    . '). Check OrderNum ' . $orderNum . ' with Novoton before booking again.';
            } elseif (isset($statusMap[$novotonStatus])) {
                $update['status'] = $statusMap[$novotonStatus];
            }

            $this->bookingRepo->update($bookingId, $update);

            // Refused or cancelled as soon as it was sent: the store is told what to refund
            if ($this->answered !== null
                && in_array($novotonStatus, [Constants::NOVOTON_STATUS_ALTERNATIVES_PENDING, Constants::NOVOTON_STATUS_CANCELLED], true)) {
                try {
                    ($this->answered)($update + ['order_id' => $orderId] + ($this->bookingRepo->findById($bookingId) ?? []), $novotonStatus);
                } catch (\Throwable $e) {
                    fn_log_event('general', 'runtime', [
                        'message' => 'Novoton - Refusal email not sent',
                        'booking_id' => $bookingId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if ($debug) {
                fn_log_event('general', 'runtime', [
                    'message' => 'Novoton Booking - API Response',
                    'order_id' => $orderId,
                    'booking_id' => $bookingId,
                    'novoton_id' => $novotonId,
                    'status' => $novotonStatus,
                ]);
            }
        } catch (ApiException $e) {
            // A reservation that may have reached Novoton was not sent again
            // (NovotonHttpClient): whether it exists is checked there first.
            $this->bookingRepo->update($bookingId, [
                'status' => TravelConstants::STATUS_FAILED,
                'notes' => 'API Error (' . $e->getApiFunction() . ', HTTP ' . $e->getHttpCode() . '): ' . $e->getMessage()
                    . ($e->mayHaveReachedNovoton() ? '. The reservation may exist: check OrderNum ' . $orderNum . ' with Novoton before booking again.' : ''),
            ]);

            fn_log_event('general', 'runtime', [
                'message' => 'Novoton Booking API Error',
                'order_id' => $orderId,
                'group' => $groupNum,
                'error' => $e->getMessage(),
            ]);
        } catch (XmlParsingException $e) {
            // The request reached Novoton; its answer could not be read, so
            // the reservation may exist there.
            $this->bookingRepo->update($bookingId, [
                'status' => TravelConstants::STATUS_FAILED,
                'notes' => "Novoton's answer could not be read (" . $e->getMessage() . '). The reservation may exist: check OrderNum '
                    . $orderNum . ' with Novoton before booking again.',
            ]);

            fn_log_event('general', 'runtime', [
                'message' => 'Novoton Booking - unreadable reservation answer',
                'order_id' => $orderId,
                'group' => $groupNum,
                'order_num' => $orderNum,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
