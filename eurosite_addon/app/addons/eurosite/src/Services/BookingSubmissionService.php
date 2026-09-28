<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Services;

use Tygh\Addons\Eurosite\Repository\EurositeBookingRepository;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\TravelConstants;

/**
 * Submits placed CS-Cart orders to the Eurosite API (place_order_post):
 * finds the order's eurosite bookings, links them, sends AddBookingRequest
 * (pax names + TGender + DOB + ChildAge from guests_json), stores the API
 * references and the post-booking cancellation-fee snapshot. A failed
 * submission marks the booking failed (retry surface: unified admin grid).
 */
class BookingSubmissionService
{
    public function __construct(
        private readonly EurositeBookingRepository $repo = new EurositeBookingRepository(),
    ) {
    }

    /**
     * @param array<string, mixed> $orderInfo fn_get_order_info() result
     * @return array{submitted: int, failed: int}
     */
    public function submitOrder(int $orderId, array $orderInfo): array
    {
        $submitted = 0;
        $failed = 0;
        $userId = TypeCoerce::toInt($orderInfo['user_id'] ?? 0);

        foreach ($this->bookingIdsFromOrder($orderInfo) as $bookingId) {
            $booking = $this->repo->findById($bookingId);
            if ($booking === null) {
                continue;
            }
            if (TypeCoerce::toInt($booking['order_id'] ?? 0) === 0) {
                $this->repo->linkToOrder($bookingId, $orderId, $userId);
            }
            // The booking form no longer collects contact — checkout does
            // (as for sphinx/novoton), so the booking row takes it from the order.
            $contact = self::contactBackfill($booking, $orderInfo);
            if ($contact !== []) {
                $this->repo->update($bookingId, $contact);
            }
            if (TypeCoerce::toString($booking['api_ref'] ?? '') !== '') {
                continue; // already submitted (idempotent re-run)
            }

            if ($this->submitBooking($bookingId, $booking)) {
                $submitted++;
            } else {
                $failed++;
            }
        }

        return ['submitted' => $submitted, 'failed' => $failed];
    }

    /**
     * The contact columns a booking row still lacks, filled from the order
     * (e-mail / phone the guest gave at checkout). Never overwrites a value
     * the booking already has; [] when there is nothing to fill.
     *
     * @param array<string, mixed> $booking
     * @param array<string, mixed> $orderInfo
     * @return array<string, string>
     */
    public static function contactBackfill(array $booking, array $orderInfo): array
    {
        $fill = [];
        $email = trim(TypeCoerce::toString($orderInfo['email'] ?? ''));
        $phone = trim(TypeCoerce::toString($orderInfo['phone'] ?? $orderInfo['b_phone'] ?? ''));
        if (trim(TypeCoerce::toString($booking['guest_email'] ?? '')) === '' && $email !== '') {
            $fill['guest_email'] = $email;
        }
        if (trim(TypeCoerce::toString($booking['guest_phone'] ?? '')) === '' && $phone !== '') {
            $fill['guest_phone'] = $phone;
        }

        return $fill;
    }

    /**
     * @param array<string, mixed> $orderInfo
     * @return list<int>
     */
    public function bookingIdsFromOrder(array $orderInfo): array
    {
        $ids = [];
        $products = is_array($orderInfo['products'] ?? null) ? $orderInfo['products'] : [];
        foreach ($products as $product) {
            if (!is_array($product)) {
                continue;
            }
            $extra = is_array($product['extra'] ?? null) ? $product['extra'] : [];
            $id = TypeCoerce::toInt($extra['eurosite_booking_id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param array<string, mixed> $booking
     */
    private function submitBooking(int $bookingId, array $booking): bool
    {
        $guests = json_decode(TypeCoerce::toString($booking['guests_json'] ?? '[]'), true);
        $guests = is_array($guests) ? $guests : [];
        $pax = [];
        foreach ($guests as $guest) {
            if (!is_array($guest)) {
                continue;
            }
            $type = TypeCoerce::toString($guest['type'] ?? 'adult');
            $entry = [
                'type' => $type,
                'name' => TypeCoerce::toString($guest['name'] ?? ''),
                'gender' => TypeCoerce::toString($guest['gender'] ?? ''),
                'dob' => TypeCoerce::toString($guest['dob'] ?? ''),
            ];
            if ($type === 'child') {
                $entry['child_age'] = TypeCoerce::toString($guest['age'] ?? '');
            }
            // Which room the traveller sleeps in (1-based) — RoomOccupancy
            // groups them into each room's <PaxNames>.
            $entry['room'] = max(1, TypeCoerce::toInt($guest['room'] ?? 1));
            $pax[] = $entry;
        }

        $hotelRow = Container::hotels()->findByProductCode(TypeCoerce::toString($booking['product_code'] ?? ''));
        $tourop = $hotelRow !== null ? TypeCoerce::toString($hotelRow['tourop_code'] ?? '') : '';

        try {
            $result = Container::getApi()->addBooking([
                'currency' => TypeCoerce::toString($booking['currency'] ?? 'EUR'),
                'booking_name' => TypeCoerce::toString($booking['client_ref'] ?? ''),
                'client_id' => TypeCoerce::toString($booking['client_ref'] ?? ''),
                'country_code' => TypeCoerce::toString($booking['country_code'] ?? ''),
                'city_code' => TypeCoerce::toString($booking['city_code'] ?? ''),
                'product_code' => TypeCoerce::toString($booking['product_code'] ?? ''),
                'variant_id' => TypeCoerce::toString($booking['variant_id'] ?? ''),
                'check_in' => TypeCoerce::toString($booking['check_in'] ?? ''),
                'check_out' => TypeCoerce::toString($booking['check_out'] ?? ''),
                'tourop_code' => $tourop,
                // Every room with its code and travellers (older single-room
                // rows: one room with every traveller).
                'rooms' => RoomOccupancy::bookingRooms($booking, $pax),
            ]);
        } catch (\Throwable $e) {
            $this->repo->update($bookingId, [
                'status' => TravelConstants::STATUS_FAILED,
                'api_response' => 'EXCEPTION: ' . $e->getMessage(),
            ]);
            fn_log_event('general', 'runtime', [
                'message' => "Eurosite addBooking failed for booking {$bookingId}: " . $e->getMessage(),
            ]);

            return false;
        }

        $ok = !empty($result['ok']);
        $this->repo->update($bookingId, [
            'api_ref' => TypeCoerce::toString($result['api_ref']),
            'status' => $ok ? TravelConstants::STATUS_CONFIRMED : TravelConstants::STATUS_FAILED,
            'api_response' => TypeCoerce::toString($result['raw']),
        ]);

        if ($ok) {
            try {
                $fees = Container::getApi()->getBookingFees(
                    TypeCoerce::toString($result['api_ref']),
                    'api',
                );
                $this->repo->update($bookingId, [
                    'cancellation_fees_json' => (string) json_encode($fees, JSON_UNESCAPED_UNICODE),
                ]);
            } catch (\Throwable) {
                // fees snapshot is best-effort; the admin can refresh later
            }
        } else {
            fn_log_event('general', 'runtime', [
                'message' => "Eurosite addBooking rejected booking {$bookingId}: " . TypeCoerce::toString($result['error']),
            ]);
        }

        return $ok;
    }
}
