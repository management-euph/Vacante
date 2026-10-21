<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Addons\NovotonHolidays\Constants;
use Tygh\Addons\TravelCore\Dto\Booking\BoardSelection;
use Tygh\Addons\TravelCore\Dto\Booking\BookingCartItem;
use Tygh\Addons\TravelCore\Dto\Booking\BookingCartItemBuilder;
use Tygh\Addons\TravelCore\Dto\Booking\BookingPricing;
use Tygh\Addons\TravelCore\Dto\Booking\BookingTerms;
use Tygh\Addons\TravelCore\Dto\Booking\ContactInfo;
use Tygh\Addons\TravelCore\Dto\Booking\GuestList;
use Tygh\Addons\TravelCore\Dto\Booking\HotelSummary;
use Tygh\Addons\TravelCore\Dto\Booking\RoomSelection;
use Tygh\Addons\TravelCore\Dto\Booking\StayDates;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\ValueObjects\BoardType;
use Tygh\Addons\TravelCore\ValueObjects\RoomType;

/**
 * Assembles CS-Cart cart product entries from Novoton booking data.
 *
 * Extracted from BookingService (SRP) — pure data transformation, no I/O.
 * Combines booking metadata, hotel info, guest data, and pricing into the
 * structure expected by CS-Cart's cart system.
 *
 * @package NovotonHolidays
 * @since   3.4.0
 */
class CartAssemblyService implements CartAssemblyServiceInterface
{
    /**
     * Assemble the full cart product array for a Novoton booking.
     *
     * Internally builds a {@see BookingCartItem} and emits the legacy
     * array shape via `toCartProductRow()` for unchanged consumers.
     *
     * @param int $productId CS-Cart product ID
     * @param int $bookingId Novoton booking ID
     * @param array<string, mixed> $bookingData Raw form data
     * @param array<string, mixed> $hotelInfo Hotel data from repository
     * @param array<string, mixed> $guestsData Parsed guest data (list shape)
     * @param array<string, mixed> $priceResult Result from verifyPrice()
     * @param array<string, mixed> $roomsData Parsed rooms data (list shape)
     * @return array<string, mixed> Cart product entry with 'extra' containing all booking metadata
     */
    #[\Override]
    public function assembleCartProduct(
        int $productId,
        int $bookingId,
        array $bookingData,
        array $hotelInfo,
        array $guestsData,
        array $priceResult,
        array $roomsData,
    ): array {
        /** @var list<array<string, mixed>> $guestsList */
        $guestsList = array_values(array_filter($guestsData, 'is_array'));
        /** @var list<array<string, mixed>> $roomsList */
        $roomsList = array_values(array_filter($roomsData, 'is_array'));

        return $this->buildCartItem(
            $productId,
            $bookingId,
            $bookingData,
            $hotelInfo,
            $guestsList,
            $priceResult,
            $roomsList,
        )->toCartProductRow();
    }

    /**
     * Typed-DTO view of {@see self::assembleCartProduct()}. Preferred by
     * new callers; existing array consumers keep working via the legacy
     * method above.
     *
     * @param array<string, mixed> $bookingData
     * @param array<string, mixed> $hotelInfo
     * @param list<array<string, mixed>> $guestsData
     * @param array<string, mixed> $priceResult
     * @param list<array<string, mixed>> $roomsData
     */
    public function buildCartItem(
        int $productId,
        int $bookingId,
        array $bookingData,
        array $hotelInfo,
        array $guestsData,
        array $priceResult,
        array $roomsData,
    ): BookingCartItem {
        $boardId = TypeCoerce::toString($bookingData['board_id'] ?? 'BB');
        $roomIdRaw = TypeCoerce::toString($bookingData['room_id'] ?? '');
        $checkIn = TypeCoerce::toString($bookingData['check_in'] ?? '');
        $checkOut = TypeCoerce::toString($bookingData['check_out'] ?? '');
        $adults = TypeCoerce::toInt($bookingData['adults'] ?? 2);
        $children = TypeCoerce::toInt($bookingData['children'] ?? 0);
        $fallbackAgesCsv = TypeCoerce::toString($bookingData['children_ages'] ?? '');
        $totalPrice = TypeCoerce::toFloat($priceResult['total_price'] ?? 0);

        $hotel = new HotelSummary(
            hotelId: TypeCoerce::toString($bookingData['hotel_id'] ?? ''),
            name: TypeCoerce::toString($hotelInfo['hotel_name'] ?? ''),
            city: TypeCoerce::toString($hotelInfo['city'] ?? ''),
            region: TypeCoerce::toString($hotelInfo['region'] ?? ''),
            country: TypeCoerce::toString($hotelInfo['country'] ?? Constants::DEFAULT_COUNTRY),
        );

        $room = new RoomSelection(
            roomId: $roomIdRaw,
            roomName: str_replace(['%2b', '%2B'], '+', $roomIdRaw),
            typeDisplay: RoomType::formatRoomLabel($roomIdRaw),
        );

        $board = new BoardSelection(
            boardId: $boardId,
            boardName: BoardType::toDisplayName($boardId),
        );

        $stay = StayDates::fromDates($checkIn, $checkOut);

        $guests = GuestList::fromGuestsData($guestsData, $adults, $children, $fallbackAgesCsv);

        $contact = ContactInfo::fromBookingData($bookingData);

        $terms = new BookingTerms(
            payment: TermsFormatter::formatPaymentTerms(TypeCoerce::toString($priceResult['terms_of_payment'] ?? '')),
            paymentRaw: TypeCoerce::toString($priceResult['terms_of_payment'] ?? ''),
            cancellation: TermsFormatter::formatCancellationTerms(
                TypeCoerce::toString($priceResult['terms_of_cancellation'] ?? ''),
                $checkIn,
            ),
            cancellationRaw: TypeCoerce::toString($priceResult['terms_of_cancellation'] ?? ''),
        );

        $pricing = new BookingPricing(
            totalPrice: $totalPrice,
            currency: ConfigProvider::getApiCurrency(),
            remark: TypeCoerce::toString($priceResult['remark'] ?? ''),
            important: TypeCoerce::toString($priceResult['important'] ?? ''),
        );

        return (new BookingCartItemBuilder())
            ->productId($productId)
            ->bookingId($bookingId)
            ->packageName(TypeCoerce::toString($bookingData['package_name'] ?? ''))
            ->numRooms(TypeCoerce::toInt($bookingData['num_rooms'] ?? 1))
            ->roomsData($roomsData)
            ->hotel($hotel)
            ->room($room)
            ->board($board)
            ->stay($stay)
            ->guests($guests)
            ->contact($contact)
            ->terms($terms)
            ->pricing($pricing)
            ->build();
    }

    /**
     * Enrich rooms_data with display fields needed by Smarty templates.
     *
     * Adds children_ages_str and room_type_display to each room entry,
     * and syncs children ages from guest form data back to rooms.
     *
     * @param array<string, mixed> $roomsData Rooms data array
     * @param array<string, mixed> $guestsData Parsed guest data
     * @return array<string, mixed> Enriched rooms data
     */
    #[\Override]
    public function enrichRoomsData(array $roomsData, array $guestsData): array
    {
        foreach ($roomsData as $roomIdx => &$room) {
            $roomNum = TypeCoerce::toInt($roomIdx) + 1;
            if (!is_array($room)) {
                continue;
            }

            // Collect children ages from guests for this room
            $childAgesForRoom = [];
            foreach ($guestsData as $guest) {
                $guestRow = TypeCoerce::toStringMap($guest);
                if (isset($guestRow['room']) && TypeCoerce::toInt($guestRow['room']) === $roomNum && ($guestRow['type'] ?? '') === 'child') {
                    $childAgesForRoom[] = TypeCoerce::toInt($guestRow['age'] ?? 0);
                }
            }

            if (!empty($childAgesForRoom)) {
                $room['childrenAges'] = $childAgesForRoom;
            }

            // Build display string for children ages
            if (!empty($room['childrenAges']) && is_array($room['childrenAges'])) {
                $validAges = array_filter($room['childrenAges'], fn ($age): bool => $age !== null && $age !== '');
                $validAgesStrList = array_map(static fn ($age): string => TypeCoerce::toString($age), $validAges);
                $room['children_ages_str'] = !empty($validAgesStrList)
                    ? implode(', ', $validAgesStrList) . ' ' . TypeCoerce::toString(__('novoton_holidays.years_old'))
                    : '';
            } else {
                $room['children_ages_str'] = '';
            }

            // Ensure room_type_display is set
            if (empty($room['room_type_display']) && !empty($room['room_id'])) {
                $roomIdStr = TypeCoerce::toString($room['room_id']);
                $room['room_type_display'] = RoomType::formatRoomLabel($roomIdStr);
                $room['room_name'] = RoomType::formatRoomLabel($roomIdStr);
            }

            // Normalize room_id and room_name: restore + lost by URL decoding
            if (!empty($room['room_id'])) {
                $room['room_id'] = RoomType::normalizeRoomCode(TypeCoerce::toString($room['room_id']));
            }
            if (!empty($room['room_name'])) {
                $room['room_name'] = RoomType::normalizeRoomCode(TypeCoerce::toString($room['room_name']));
            }
        }
        unset($room);

        return $roomsData;
    }

    /**
     * Build cart extra metadata from a booking record.
     *
     * (Its add-to-cart caller is gone: add_to_cart.php builds the line itself.)
     *
     * @param array<string, mixed> $booking Booking record from DB
     * @param array<string, mixed> $bookingData Additional data from form
     * @return array<string, mixed> Cart extra array
     */
    #[\Override]
    public function buildCartExtra(array $booking, array $bookingData): array
    {
        return [
            'travel_booking' => true,
            'novoton_booking' => true,
            'novoton_booking_id' => $booking['booking_id'],
            'hotel_id' => $booking['hotel_id'],
            'hotel_name' => $booking['hotel_name'],
            'hotel_city' => $bookingData['hotel_city'] ?? '',
            'hotel_country' => $bookingData['hotel_country'] ?? Constants::DEFAULT_COUNTRY,
            'package_name' => $booking['package_name'],
            'room_id' => $booking['room_id'],
            'room_name' => str_replace(['%2b', '%2B'], '+', TypeCoerce::toString($booking['room_id'] ?? '')),
            'room_type_display' => $booking['room_type'],
            'board_id' => $booking['board_id'],
            'board_name' => BoardType::toDisplayName(TypeCoerce::toString($booking['board_id'] ?? '')),
            'check_in' => $booking['check_in'],
            'check_out' => $booking['check_out'],
            'nights' => $booking['nights'],
            'adults' => $booking['adults'],
            'children' => $booking['children'],
            'children_ages' => $booking['children_ages'],
            'num_rooms' => $booking['num_rooms'],
            'rooms_data' => $booking['rooms_data'],
            'holder_name' => $booking['holder_name'],
            'guest_name' => $booking['guest_name'],
            'guests_data' => $booking['guests_data'],
            'total_price' => $booking['total_price'],
            'terms_of_payment' => $bookingData['terms_of_payment'] ?? '',
            'terms_of_cancellation' => $bookingData['terms_of_cancellation'] ?? '',
            'terms_of_payment_raw' => $bookingData['terms_of_payment_raw'] ?? '',
            'terms_of_cancellation_raw' => $bookingData['terms_of_cancellation_raw'] ?? '',
        ];
    }

    /**
     * Calculate nights between two dates.
     */
    public static function calculateNights(string $checkIn, string $checkOut): int
    {
        $in = strtotime($checkIn);
        $out = strtotime($checkOut);

        if ($in === false || $in === 0 || $out === false || $out === 0 || $out <= $in) {
            return 0;
        }

        return (int) (($out - $in) / 86400);
    }
}
