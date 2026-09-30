<?php

declare(strict_types=1);

/**
 * Novoton Booking Service
 *
 * Handles booking creation, cart operations, and order processing.
 * Extracted from novoton_booking.php for better maintainability.
 *
 * @package NovotonHolidays
 * @since 2.7.0
 */

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Addons\NovotonHolidays\Api\Contracts\PricingApiClientInterface;
use Tygh\Addons\NovotonHolidays\Repository\BookingRepositoryInterface;
use Tygh\Addons\NovotonHolidays\Repository\HotelRepositoryInterface;
use Tygh\Addons\TravelCore\Helpers\SessionAccessor;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Services\GuestDataNormalizer;
use Tygh\Addons\TravelCore\TravelConstants;

class BookingService implements BookingServiceInterface
{
    private \Tygh\Addons\TravelCore\Contracts\GuestDataServiceInterface $guestService;

    private BookingRepositoryInterface $bookingRepo;

    private HotelRepositoryInterface $hotelRepo;

    private GuestDataNormalizer $guestDataNormalizer;

    private CartAssemblyService $cartAssembly;

    private RoomsDataParser $roomsParser;

    private PriceVerificationService $priceVerifier;

    private readonly SessionAccessor $session;

    private bool $debug = false;

    /**
     * Constructor — all dependencies must be injected explicitly.
     *
     * The `$pricing` sub-client is only forwarded to the internally-constructed
     * PriceVerificationService. BookingService itself does not call the Novoton
     * API directly; narrowing the type from NovotonApi to PricingApiClientInterface
     * eliminates a dependency on the 46-method facade.
     *
     * Use Container::bookingService() to get a properly wired instance.
     */
    public function __construct(
        \Tygh\Addons\TravelCore\Contracts\GuestDataServiceInterface $guestService,
        BookingRepositoryInterface $bookingRepo,
        PricingApiClientInterface $pricing,
        ?HotelRepositoryInterface $hotelRepo = null,
        ?GuestDataNormalizer $guestDataNormalizer = null,
        ?CartAssemblyService $cartAssembly = null,
        ?RoomsDataParser $roomsParser = null,
        ?SessionAccessor $session = null,
    ) {
        $this->guestService = $guestService;
        $this->bookingRepo = $bookingRepo;
        $this->hotelRepo = $hotelRepo ?? new \Tygh\Addons\NovotonHolidays\Repository\HotelRepository();
        $this->guestDataNormalizer = $guestDataNormalizer ?? new GuestDataNormalizer();
        $this->cartAssembly = $cartAssembly ?? new CartAssemblyService();
        $this->roomsParser = $roomsParser ?? new RoomsDataParser();
        $this->priceVerifier = new PriceVerificationService($pricing);
        $this->session = $session ?? new SessionAccessor();
        $this->debug = ConfigProvider::isDebugLogging();
    }

    /**
     * Create a new booking record
     *
     * @param array<string, mixed> $bookingData Booking data from form
     * @param int $product_id Associated product ID
     * @return int Booking ID
     */
    public function createBooking(array $bookingData, int $product_id): int
    {
        // Get current user/session
        $auth = $this->session->auth();
        $user_id = !empty($auth['user_id']) ? TypeCoerce::toInt($auth['user_id']) : 0;
        $session_id = session_id();

        // Parse and validate data
        $rooms_data = $this->roomsParser->parseRoomsData($bookingData);
        $guests_data = $this->guestService->parseGuestsData($bookingData);

        // RoomsDataParser's aggregation helpers declare an array<string, mixed>
        // contract but reindex their input via TypeCoerce::toRowList, so a
        // string-keyed view of the parsed list yields identical results.
        $rooms_map = $this->roomsAsKeyedMap($rooms_data);

        // Extract room info for database columns
        $room_info = $this->roomsParser->extractRoomInfo($rooms_map, $bookingData);

        // Calculate totals
        $totals = $this->roomsParser->calculateTotals($rooms_map);

        // Get hotel info (typed DTO)
        $hotelId = \Tygh\Addons\TravelCore\Helpers\TypeCoerce::toString($bookingData['hotel_id'] ?? '');
        $hotel = $this->hotelRepo->findByIdAsDto($hotelId);

        // Build booking record
        $booking_record = [
            'order_id' => 0, // Updated when order is placed
            'user_id' => $user_id,
            'session_id' => $session_id,
            'product_id' => $product_id,
            'hotel_id' => $hotelId,
            'hotel_name' => $hotel !== null ? $hotel->name ?? '' : '',
            'package_name' => $bookingData['package_name'] ?? '',
            'room_id' => $room_info['room_id'],
            'room_type' => $room_info['room_type'],
            'board_id' => $bookingData['board_id'] ?? 'BB',
            'board_name' => $bookingData['board_name'] ?? '',
            'check_in' => $bookingData['check_in'],
            'check_out' => $bookingData['check_out'],
            'nights' => $this->calculateNights(
                TypeCoerce::toString($bookingData['check_in']),
                TypeCoerce::toString($bookingData['check_out']),
            ),
            'adults' => $totals['adults'],
            'children' => $totals['children'],
            'children_ages' => implode(',', $totals['ages']),
            'num_rooms' => count($rooms_data),
            'rooms_data' => json_encode($rooms_data),
            'guest_name' => $this->guestService->buildGuestList($guests_data),
            'holder_name' => $this->guestService->getHolderName($guests_data, $bookingData),
            'guest_email' => '',
            'guest_phone' => $bookingData['phone'] ?? '',
            'guests_data' => $this->guestDataNormalizer->toJson($guests_data),
            'base_price' => TypeCoerce::toFloat($bookingData['base_price'] ?? 0),
            'api_price' => TypeCoerce::toFloat($bookingData['api_price'] ?? 0),
            'total_price' => TypeCoerce::toFloat($bookingData['total_price'] ?? 0),
            'currency' => ConfigProvider::getApiCurrency(),
            'status' => TravelConstants::STATUS_PENDING,
        ];

        // Check for duplicate booking (delegate to repository)
        $existing = $this->bookingRepo->findExisting(
            $booking_record['hotel_id'],
            TypeCoerce::toString($booking_record['check_in']),
            TypeCoerce::toString($booking_record['check_out']),
            $booking_record['holder_name'],
            1, // hours
        );
        $existing_id = !empty($existing) ? TypeCoerce::toInt($existing['booking_id']) : null;

        if (!empty($existing_id)) {
            // Update existing (routes through repository → syncs to travel_bookings)
            $this->updateBooking($existing_id, $booking_record);
            return $existing_id;
        }

        // Create new (routes through repository → syncs to travel_bookings)
        $booking_id = $this->bookingRepo->create($booking_record);

        $this->log('Booking created', [
            'booking_id' => $booking_id,
            'hotel_id' => $bookingData['hotel_id'],
            'rooms' => count($rooms_data),
        ]);

        return $booking_id;
    }

    /**
     * Update existing booking
     *
     * @param int $booking_id Booking ID
     * @param array<string, mixed> $data Data to update
     * @return bool Success
     */
    public function updateBooking(int $booking_id, array $data): bool
    {
        // Remove fields that shouldn't be updated
        unset($data['booking_id'], $data['created_at']);

        // Route through repository → syncs to travel_bookings
        $result = $this->bookingRepo->update($booking_id, $data);

        $this->log('Booking updated', ['booking_id' => $booking_id]);

        return $result;
    }

    /**
     * Get booking by ID
     *
     * @param int $booking_id Booking ID
     * @return array<string, mixed>|null Booking data
     */
    public function getBooking(int $booking_id): ?array
    {
        return $this->bookingRepo->findByIdHydrated($booking_id);
    }

    /**
     * Get bookings for order
     *
     * @param int $order_id Order ID
     * @return list<array<string, mixed>> Bookings
     */
    public function getBookingsForOrder(int $order_id): array
    {
        return $this->bookingRepo->findByOrderId($order_id);
    }

    /**
     * Link booking to order
     *
     * @param int $booking_id Booking ID
     * @param int $order_id Order ID
     * @return bool Success
     */
    public function linkToOrder(int $booking_id, int $order_id): bool
    {
        // Get order info for user/email
        $order_info = TypeCoerce::toStringMap(fn_get_order_info($order_id));

        $update = [
            'order_id' => $order_id,
            'user_id' => TypeCoerce::toInt($order_info['user_id'] ?? 0),
            'guest_email' => $order_info['email'] ?? '',
        ];

        return $this->updateBooking($booking_id, $update);
    }

    /**
     * Add booking to cart
     *
     * @param int $booking_id Booking ID
     * @param int $product_id Product ID
     * @param array<string, mixed> $bookingData Additional booking data
     * @return bool Success
     */
    public function addToCart(int $booking_id, int $product_id, array $bookingData): bool
    {
        $booking = $this->getBooking($booking_id);
        if (empty($booking)) {
            return false;
        }

        // Build cart product
        $product = [
            'product_id' => $product_id,
            'amount' => 1,
            'extra' => $this->cartAssembly->buildCartExtra($booking, $bookingData),
        ];

        // Add to cart — reference-based CS-Cart procedural flow lives in
        // functions/helpers.php (allowlisted boundary). Keeps Tygh::\$app
        // out of this service class.
        fn_novoton_holidays_add_to_session_cart($product);

        $this->log('Added to cart', [
            'booking_id' => $booking_id,
            'product_id' => $product_id,
        ]);

        return true;
    }

    /**
     * Parse rooms data from booking form
     *
     * @param array<string, mixed> $bookingData Form data
     * @return array<string, mixed> Parsed rooms data
     */
    public function parseRoomsData(array $bookingData): array
    {
        return $this->roomsAsKeyedMap($this->roomsParser->parseRoomsData($bookingData));
    }

    /**
     * Present a parsed rooms list as an array<string, mixed>.
     *
     * RoomsDataParser::parseRoomsData() returns a list<array<string, mixed>>,
     * but both this service's interface contract and the parser's aggregation
     * helpers (extractRoomInfo/calculateTotals) are typed array<string, mixed>.
     * Those helpers reindex their input through TypeCoerce::toRowList(), so
     * substituting string keys here is observationally identical to passing
     * the original list.
     *
     * @param list<array<string, mixed>> $rooms
     * @return array<string, mixed>
     */
    private function roomsAsKeyedMap(array $rooms): array
    {
        $map = [];
        foreach ($rooms as $index => $room) {
            $map['room_' . $index] = $room;
        }

        return $map;
    }

    /**
     * Calculate nights between dates
     *
     * @param string $check_in Check-in date
     * @param string $check_out Check-out date
     * @return int Number of nights
     */
    public function calculateNights(string $check_in, string $check_out): int
    {
        return CartAssemblyService::calculateNights($check_in, $check_out);
    }

    /**
     * Verify price via room_price API and extract terms.
     *
     * Calls the Novoton room_price API with the given parameters,
     * validates that a price is returned, applies commission, and
     * extracts terms of payment/cancellation from the response.
     *
     * @param array<string, mixed> $params {hotel_id, room_id, board_id, check_in, check_out, adults, children_ages: int[]}
     * @return array{success: bool, total_price: float, base_price: float, terms_of_payment: string, terms_of_cancellation: string, remark: string, important: string, error: string}
     */
    public function verifyPrice(array $params): array
    {
        return $this->priceVerifier->verifyPrice($params);
    }

    /**
     * Assemble the full cart product array for a Novoton booking.
     *
     * Combines booking record data, hotel info, and terms into the
     * structure expected by CS-Cart's cart system.
     *
     * @param int $productId CS-Cart product ID
     * @param int $bookingId Novoton booking ID
     * @param array<string, mixed> $bookingData Raw form data
     * @param array<string, mixed> $hotelInfo Hotel data from repository
     * @param array<string, mixed> $guestsData Parsed guest data
     * @param array<string, mixed> $priceResult Result from verifyPrice()
     * @param array<string, mixed> $roomsData Parsed rooms data
     * @return array<string, mixed> Cart product entry with 'extra' containing all booking metadata
     */
    public function assembleCartProduct(
        int $productId,
        int $bookingId,
        array $bookingData,
        array $hotelInfo,
        array $guestsData,
        array $priceResult,
        array $roomsData,
    ): array {
        return $this->cartAssembly->assembleCartProduct(
            $productId,
            $bookingId,
            $bookingData,
            $hotelInfo,
            $guestsData,
            $priceResult,
            $roomsData,
        );
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
    public function enrichRoomsData(array $roomsData, array $guestsData): array
    {
        return $this->cartAssembly->enrichRoomsData($roomsData, $guestsData);
    }

    /**
     * The hotel's own active Novoton product (HotelCartProduct).
     *
     * @param string $hotelId Novoton hotel ID
     * @param int $fallbackProductId the form's product_id: accepted only when it is that same product
     * @return int Product ID or 0 if the booking must be refused
     */
    public function resolveProductId(string $hotelId, int $fallbackProductId = 0): int
    {
        return HotelCartProduct::resolve($hotelId, $fallbackProductId);
    }

    /**
     * Log debug message
     *
     * @param string $message Message
     * @param array<string, mixed> $context Context
     */
    private function log(string $message, array $context = []): void
    {
        if ($this->debug) {
            fn_log_event('general', 'runtime', array_merge(
                ['message' => 'NovotonBooking: ' . $message],
                $context,
            ));
        }
    }
}
