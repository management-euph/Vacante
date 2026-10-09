<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Services;

use Tygh\Addons\SphinxHolidays\Contracts\BookingRetryServiceInterface;
use Tygh\Addons\SphinxHolidays\Repository\SphinxBookingRepository;
use Tygh\Addons\SphinxHolidays\SphinxApi;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\TravelConstants;

/**
 * Retries a failed Sphinx hotel booking.
 *
 * Re-verifies the offer with the API, then re-attempts the booking.
 * Status flow: failed → pending → confirmed/failed.
 *
 * @package SphinxHolidays
 * @since   1.2.0
 */
class BookingRetryService implements BookingRetryServiceInterface
{
    public function __construct(
        private readonly SphinxApi $api,
        private readonly SphinxBookingRepository $repo,
    ) {
    }

    /**
     * Retry a failed booking.
     *
     * @param int $bookingId The sphinx_bookings.booking_id
     * @return array{success: bool, message: string, booking_ref: string|null}
     */
    #[\Override]
    public function retry(int $bookingId): array
    {
        $booking = $this->repo->findById($bookingId);
        if ($booking === null) {
            return ['success' => false, 'message' => 'Booking not found', 'booking_ref' => null];
        }

        if (($booking['status'] ?? '') !== TravelConstants::STATUS_FAILED) {
            return ['success' => false, 'message' => 'Only failed bookings can be retried', 'booking_ref' => null];
        }

        $offerId = TypeCoerce::toString($booking['offer_id'] ?? '');
        if (empty($offerId)) {
            return ['success' => false, 'message' => 'No offer ID stored — cannot retry', 'booking_ref' => null];
        }

        $bookingType = TypeCoerce::toString($booking['room_type'] ?? 'hotel');
        // Normalize: room_type stores 'circuit', 'experience', or 'package' for non-hotel bookings
        if (!in_array($bookingType, ['circuit', 'experience', 'package'], true)) {
            $bookingType = 'hotel';
        }

        // Step 1: Re-verify the offer (hotels and packages have verify endpoints).
        // SphinxApi returns the offer PAYLOAD (envelope unwrapped by
        // ResponseDecoder); availability uses the same tolerant gate as the
        // storefront verify paths. The previous ad-hoc normalization computed
        // available = !(must_verify ?? true) from a raw ['data'] read — so a
        // payload WITHOUT must_verify (the live shape) made every retry report
        // "Offer no longer available", and an envelope drift broke it entirely.
        if ($bookingType === 'hotel' || $bookingType === 'package') {
            try {
                $verifyResult = $bookingType === 'package'
                    ? $this->api->verifyPackageOffer($offerId)
                    : $this->api->verifyHotelOffer($offerId);
            } catch (\Throwable $e) {
                return ['success' => false, 'message' => 'Verification failed: ' . $e->getMessage(), 'booking_ref' => null];
            }

            $available = \Tygh\Addons\SphinxHolidays\Helpers\OfferAvailability::isVerifiedAvailable(
                $verifyResult,
                ConfigProvider::shouldRequireImmediateAvailability(),
            )
                // An explicit must_verify=true means the price is not final —
                // do not auto-retry a booking on an unconfirmed price.
                && !TypeCoerce::toBool(($verifyResult ?? [])['must_verify'] ?? false);

            if (!$available) {
                return ['success' => false, 'message' => 'Offer no longer available', 'booking_ref' => null];
            }
        }

        // Mark as pending before retry
        $this->repo->update($bookingId, ['status' => TravelConstants::STATUS_PENDING]);

        // Step 2: Re-attempt booking using type-aware dispatch
        try {
            $guestsData = [];
            if (!empty($booking['guests_data'])) {
                $rawGuests = is_string($booking['guests_data'])
                    ? json_decode($booking['guests_data'], true)
                    : $booking['guests_data'];
                $guestsData = TypeCoerce::toStringMap($rawGuests);
            }

            $bookResult = $this->submitBooking($bookingType, $offerId, $booking, $guestsData);

            // Real book responses carry booking_confirmation_number (the
            // voucher code) — booking_reference never existed in the API and
            // is kept only as a tolerance fallback.
            $bookingRef = TypeCoerce::toString(
                $bookResult['booking_confirmation_number'] ?? $bookResult['booking_reference'] ?? '',
            );
            if ($bookingRef !== '') {
                $this->repo->updateApiResponse(
                    $bookingId,
                    $bookingRef,
                    (string) json_encode($bookResult),
                );
            }

            $this->repo->update($bookingId, [
                'status' => TravelConstants::STATUS_CONFIRMED,
            ]);

            fn_log_event('general', 'runtime', [
                'message' => "Sphinx booking #{$bookingId} retry succeeded",
                'booking_ref' => $bookingRef,
            ]);

            return [
                'success' => true,
                'message' => 'Booking retry successful',
                'booking_ref' => $bookingRef !== '' ? $bookingRef : null,
            ];
        } catch (\Throwable $e) {
            $this->repo->update($bookingId, [
                'status' => TravelConstants::STATUS_FAILED,
                'api_response' => (string) json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE),
            ]);

            fn_log_event('general', 'runtime', [
                'message' => "Sphinx booking #{$bookingId} retry failed: " . $e->getMessage(),
            ]);

            return ['success' => false, 'message' => 'Retry failed: ' . $e->getMessage(), 'booking_ref' => null];
        }
    }

    /**
     * Dispatch booking to the correct API method based on type.
     *
     * Uses the SAME payload the order-placement hook submits
     * (BookingPayloadFactory) — the previous version built an "occupancy"
     * via fn_sphinx_holidays_build_*_occupancy helpers that were never
     * defined anywhere, so every retry fataled into the caller's catch
     * block and re-marked the booking failed.
     *
     * @param array<string, mixed> $booking
     * @param array<string, mixed> $guestsData
     * @return array<string, mixed>
     */
    private function submitBooking(string $type, string $offerId, array $booking, array $guestsData): array
    {
        // Contact: the booking row is backfilled from the order at placement;
        // older failed bookings may predate that — fall back to the order.
        $contact = [
            'email' => TypeCoerce::toString($booking['guest_email'] ?? ''),
            'phone' => TypeCoerce::toString($booking['guest_phone'] ?? ''),
        ];
        $orderId = TypeCoerce::toInt($booking['order_id'] ?? 0);
        if ($contact['email'] === '' && $contact['phone'] === '' && $orderId > 0 && function_exists('fn_get_order_info')) {
            $orderInfo = fn_get_order_info($orderId);
            $contact = BookingPayloadFactory::contactFromUserData(
                TypeCoerce::toStringMap(is_array($orderInfo) ? $orderInfo : []),
            );
        }

        $payload = BookingPayloadFactory::build($offerId, $guestsData, $contact, $orderId);

        $result = match ($type) {
            'circuit' => $this->api->bookCircuit($payload),
            'package' => $this->api->bookPackage($payload),
            'experience' => $this->api->bookExperience($payload),
            default => $this->api->bookHotel($payload),
        };

        return $result ?: [];
    }
}
