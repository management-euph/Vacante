<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Dto\Booking;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Typed view of a Novoton cart item.
 *
 * Replaces the ~30-key `array<string, mixed>` `extra` sub-array written by
 * Novoton's add_to_cart (controllers/frontend/novoton_booking/add_to_cart.php) and
 * consumed downstream by cart/order hooks, the React mount, admin emails,
 * and the reservation-API payload builder.
 *
 * The DTO is built via {@see BookingCartItemBuilder} (assembly is
 * inherently mutational today); callers hold/pass only this final
 * readonly value. Round-trip: `fromCartExtra(toCartExtra($item)) == $item`.
 *
 * Session persistence: the cart stays array-shaped in `$_SESSION`; the
 * DTO is reconstructed via {@see self::fromCartExtra()} on every request.
 */
final readonly class BookingCartItem
{
    /**
     * @param list<array<string, mixed>> $roomsData per-room occupancy + ages snapshot
     *                                              used by SearchResultFormatter /
     *                                              React mount; kept as raw array
     *                                              to avoid a deep cascade of
     *                                              additional DTOs for PR 4.
     */
    public function __construct(
        public int $productId,
        public int $bookingId,
        public string $packageName,
        public int $numRooms,
        public array $roomsData,
        public HotelSummary $hotel,
        public RoomSelection $room,
        public BoardSelection $board,
        public StayDates $stay,
        public GuestList $guests,
        public ContactInfo $contact,
        public BookingTerms $terms,
        public BookingPricing $pricing,
    ) {
    }

    /**
     * Reconstruct from a cart-product `extra` array (the shape written by
     * Novoton's add_to_cart / read by cart_hooks.php and friends).
     *
     * @param array<string, mixed> $extra the `extra` sub-array
     * @param array<string, mixed>|null $outer optional outer cart-product row
     *                                         (for `product_id`)
     */
    public static function fromCartExtra(array $extra, ?array $outer = null): self
    {
        $roomsData = [];
        if (isset($extra['rooms_data']) && is_array($extra['rooms_data'])) {
            foreach ($extra['rooms_data'] as $room) {
                if (is_array($room)) {
                    /** @var array<string, mixed> $room */
                    $roomsData[] = TypeCoerce::toStringMap($room);
                }
            }
        }

        return new self(
            productId: TypeCoerce::toInt($outer['product_id'] ?? $extra['product_id'] ?? 0),
            bookingId: TypeCoerce::toInt($extra['novoton_booking_id'] ?? 0),
            packageName: TypeCoerce::toString($extra['package_name'] ?? ''),
            numRooms: TypeCoerce::toInt($extra['num_rooms'] ?? 1),
            roomsData: $roomsData,
            hotel: HotelSummary::fromCartExtra($extra),
            room: RoomSelection::fromCartExtra($extra),
            board: BoardSelection::fromCartExtra($extra),
            stay: StayDates::fromCartExtra($extra),
            guests: GuestList::fromCartExtra($extra),
            contact: ContactInfo::fromCartExtra($extra),
            terms: BookingTerms::fromCartExtra($extra),
            pricing: BookingPricing::fromCartExtra($extra),
        );
    }

    /**
     * Re-emit in the `extra` shape CS-Cart's cart system expects.
     *
     * @return array{
     *   travel_booking: bool,
     *   novoton_booking: bool,
     *   novoton_booking_id: int,
     *   hotel_id: string,
     *   hotel_name: string,
     *   hotel_city: string,
     *   hotel_region: string,
     *   hotel_country: string,
     *   package_name: string,
     *   room_id: string,
     *   room_name: string,
     *   room_type_display: string,
     *   board_id: string,
     *   board_name: string,
     *   check_in: string,
     *   check_out: string,
     *   nights: int,
     *   adults: int,
     *   children: int,
     *   children_ages: string,
     *   num_rooms: int,
     *   rooms_data: list<array<string, mixed>>,
     *   guest_names: string,
     *   holder_name: string,
     *   guests_data: string,
     *   contact_email: string,
     *   contact_phone: string,
     *   terms_of_payment: string,
     *   terms_of_cancellation: string,
     *   terms_of_payment_raw: string,
     *   terms_of_cancellation_raw: string,
     *   remark: string,
     *   important: string,
     *   total_price: float,
     *   currency: string
     * }
     */
    public function toCartExtra(): array
    {
        return [
            'travel_booking' => true,
            'novoton_booking' => true,
            'novoton_booking_id' => $this->bookingId,
            'hotel_id' => $this->hotel->hotelId,
            'hotel_name' => $this->hotel->name,
            'hotel_city' => $this->hotel->city,
            'hotel_region' => $this->hotel->region,
            'hotel_country' => $this->hotel->country,
            'package_name' => $this->packageName,
            'room_id' => $this->room->roomId,
            'room_name' => $this->room->roomName,
            'room_type_display' => $this->room->typeDisplay,
            'board_id' => $this->board->boardId,
            'board_name' => $this->board->boardName,
            'check_in' => $this->stay->checkIn,
            'check_out' => $this->stay->checkOut,
            'nights' => $this->stay->nights,
            'adults' => $this->guests->adults,
            'children' => $this->guests->children,
            'children_ages' => $this->guests->childrenAgesCsv(),
            'num_rooms' => $this->numRooms,
            'rooms_data' => $this->roomsData,
            'guest_names' => $this->guests->guestNamesCsv,
            'holder_name' => $this->guests->holderName,
            'guests_data' => $this->guests->guestsDataJson,
            'contact_email' => $this->contact->email,
            'contact_phone' => $this->contact->phone,
            'terms_of_payment' => $this->terms->payment,
            'terms_of_cancellation' => $this->terms->cancellation,
            'terms_of_payment_raw' => $this->terms->paymentRaw,
            'terms_of_cancellation_raw' => $this->terms->cancellationRaw,
            'remark' => $this->pricing->remark,
            'important' => $this->pricing->important,
            'total_price' => $this->pricing->totalPrice,
            'currency' => $this->pricing->currency,
        ];
    }

    /**
     * Emit the full outer cart-product row (CS-Cart's expected shape with
     * `product_id`, `amount`, `price`, `extra`, …). Mirrors what Novoton's
     * CartAssemblyService::assembleCartProduct() returned (since removed:
     * nothing built cart lines through it).
     *
     * @return array{
     *   product_id: int,
     *   amount: int,
     *   price: float,
     *   base_price: float,
     *   original_price: float,
     *   stored_price: string,
     *   extra: array<string, mixed>
     * }
     */
    public function toCartProductRow(): array
    {
        return [
            'product_id' => $this->productId,
            'amount' => 1,
            'price' => $this->pricing->totalPrice,
            'base_price' => $this->pricing->totalPrice,
            'original_price' => $this->pricing->totalPrice,
            'stored_price' => 'Y',
            'extra' => $this->toCartExtra(),
        ];
    }
}
