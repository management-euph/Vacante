<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Addons\NovotonHolidays\Repository\BookingRepositoryInterface;
use Tygh\Addons\TravelCore\Services\GuestDataNormalizer;

/**
 * Resolves the rooms and guests for a booking from the (possibly stale) cart
 * data, synthesising a single-room entry when rooms_data is absent and walking
 * the guests fallback chain: cart data, then a DB re-fetch by booking id, then
 * an unassigned pending booking matched by hotel + dates.
 *
 * Extracted from BookingSubmissionService — the most tangled of its pipeline
 * steps. It depends only on the booking repository and the guest-data
 * normaliser, so the fallback chain is directly unit-testable. Behaviour is
 * preserved; the moved code gains array/string guards before normalize() and
 * re-narrows the decoded rooms into a list (the baseline masked these in the
 * original).
 */
class BookingRoomsGuestsResolver
{
    public function __construct(
        private readonly GuestDataNormalizer $guestDataNormalizer,
        private readonly BookingRepositoryInterface $bookingRepo,
    ) {
    }

    /**
     * Parse rooms_data and guests_data, with DB fallbacks.
     *
     * @param array<string, mixed> $bookingData
     * @return array{0: list<array<int|string, mixed>>, 1: array<string, mixed>} [rooms_data[], guests_data[]]
     */
    public function resolveRoomsAndGuests(array $bookingData, bool $debug): array
    {
        // --- rooms_data ---
        $roomsData = [];
        if (!empty($bookingData['rooms_data'])) {
            $decoded = is_string($bookingData['rooms_data'])
                ? json_decode($bookingData['rooms_data'], true)
                : $bookingData['rooms_data'];
            $roomsData = is_array($decoded) ? $decoded : [];
        }

        // Synthesise a single-room entry when rooms_data is absent
        if (empty($roomsData)) {
            $childrenAges = [];
            if (!empty($bookingData['children_ages'])) {
                $childrenAges = is_string($bookingData['children_ages'])
                    ? array_map('intval', array_filter(explode(',', $bookingData['children_ages']), fn ($v): bool => $v !== ''))
                    : (array) $bookingData['children_ages'];
            }

            $roomsData = [[
                'room_id' => PriceInfoFormatter::toScalar($bookingData['room_id'] ?? ''),
                'room_name' => PriceInfoFormatter::toScalar($bookingData['room_type'] ?? $bookingData['room_name'] ?? $bookingData['room_id'] ?? ''),
                'room_type_display' => PriceInfoFormatter::toScalar($bookingData['room_type'] ?? $bookingData['room_name'] ?? $bookingData['room_id'] ?? ''),
                'board_id' => PriceInfoFormatter::toScalar($bookingData['board_id'] ?? ''),
                'ext_board_id' => PriceInfoFormatter::toScalar($bookingData['ext_board_id'] ?? ''),
                'board_name' => PriceInfoFormatter::toScalar($bookingData['board_name'] ?? $bookingData['board_id'] ?? ''),
                'package_name' => PriceInfoFormatter::toScalar($bookingData['package_name'] ?? ''),
                'check_in' => PriceInfoFormatter::toScalar($bookingData['check_in'] ?? ''),
                'check_out' => PriceInfoFormatter::toScalar($bookingData['check_out'] ?? ''),
                'adults' => PriceInfoFormatter::toInt($bookingData['adults'] ?? 2),
                'children' => PriceInfoFormatter::toInt($bookingData['children'] ?? 0),
                'childrenAges' => $childrenAges,
                'price' => PriceInfoFormatter::toFloat($bookingData['final_price'] ?? 0),
            ]];
        }

        // Re-narrow into a list of room arrays.
        $roomsList = [];
        foreach ($roomsData as $room) {
            if (is_array($room)) {
                $roomsList[] = $room;
            }
        }

        // --- guests_data ---
        $guestsData = $this->resolveGuestsData($bookingData, $debug);

        return [$roomsList, $guestsData];
    }

    /**
     * Resolve guests_data with multiple fallback strategies.
     *
     * 1. From cart extra (already hydrated from DB if available)
     * 2. Re-fetch from DB by booking_id
     * 3. Match unassigned pending booking by hotel + dates
     *
     * @param array<string, mixed> $bookingData
     * @return array<string, mixed>
     */
    private function resolveGuestsData(array $bookingData, bool $debug): array
    {
        // Primary: normalize from cart/DB data (handles both keyed and legacy array formats)
        $rawCartGuests = $bookingData['guests_data'] ?? null;
        if (!empty($rawCartGuests) && (is_array($rawCartGuests) || is_string($rawCartGuests))) {
            $guestsData = $this->normalizeGuests($rawCartGuests);
            if (!empty($guestsData)) {
                return $guestsData;
            }
        }

        // Fallback 1: re-fetch from DB by booking_id
        $bookingId = PriceInfoFormatter::toInt($bookingData['novoton_booking_id'] ?? 0);
        if ($bookingId > 0) {
            $dbGuests = $this->bookingRepo->getGuestsData($bookingId);
            if (!empty($dbGuests)) {
                $guestsData = $this->normalizeGuests($dbGuests);
                if ($debug) {
                    fn_log_event('general', 'runtime', [
                        'message' => 'Novoton - Fetched guests_data from database (cart was empty)',
                        'booking_id' => $bookingId,
                        'guests_count' => count($guestsData),
                    ]);
                }
                if (!empty($guestsData)) {
                    return $guestsData;
                }
            }
        }

        // Fallback 2: match unassigned pending booking by hotel + dates
        $existing = $this->bookingRepo->findUnassignedByHotelDates(
            PriceInfoFormatter::toScalar($bookingData['hotel_id'] ?? ''),
            PriceInfoFormatter::toScalar($bookingData['check_in'] ?? ''),
            PriceInfoFormatter::toScalar($bookingData['check_out'] ?? ''),
        );
        if (is_array($existing)) {
            $rawExisting = $existing['guests_data'] ?? null;
            if (!empty($rawExisting) && (is_array($rawExisting) || is_string($rawExisting))) {
                $guestsData = $this->normalizeGuests($rawExisting);
                if ($debug) {
                    fn_log_event('general', 'runtime', [
                        'message' => 'Novoton - Fetched guests_data from pending booking record',
                        'holder_name' => $existing['holder_name'] ?? '',
                        'guests_count' => count($guestsData),
                    ]);
                }
                return $guestsData;
            }
        }

        return [];
    }

    /**
     * Normalize raw guest data. An array is routed through JSON so the value
     * handed to the normalizer matches its array<string, mixed>|string contract
     * without changing the structure it sees — guest data is JSON round-trip
     * safe (it is stored and transported as JSON throughout the booking flow).
     *
     * @param array<int|string, mixed>|string $raw
     * @return array<string, mixed>
     */
    private function normalizeGuests(array|string $raw): array
    {
        if (is_array($raw)) {
            $json = json_encode($raw);
            if ($json === false) {
                return [];
            }
            return $this->guestDataNormalizer->normalize($json);
        }

        return $this->guestDataNormalizer->normalize($raw);
    }
}
