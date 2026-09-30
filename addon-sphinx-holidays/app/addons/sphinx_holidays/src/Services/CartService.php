<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Services;

use Tygh\Addons\SphinxHolidays\Contracts\CartServiceInterface;
use Tygh\Addons\SphinxHolidays\Repository\HotelSkipRepository;
use Tygh\Addons\TravelCore\Helpers\SessionAccessor;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Services\CartSkipPolicy;
use Tygh\Addons\TravelCore\Services\CommissionCalculator;
use Tygh\Addons\TravelCore\Services\CurrencyService;
use Tygh\Addons\TravelCore\Services\DepositCartLine;
use Tygh\Addons\TravelCore\Services\DepositPlan;
use Tygh\Addons\TravelCore\Services\GuestDataService;
use Tygh\Addons\TravelCore\Services\ProviderCartProduct;
use Tygh\Addons\TravelCore\TravelConstants;

/**
 * Unified cart service for Sphinx Holidays bookings.
 *
 * Encapsulates the shared logic across all 4 add-to-cart controllers
 * (hotel, circuit, experience, package): rate limiting, duplicate detection,
 * commission calculation, product resolution, guest validation, booking
 * upsert, and CS-Cart cart assembly.
 *
 * Controllers only provide type-specific logic (offer verification,
 * service customization, type-specific booking record fields).
 */
final class CartService implements CartServiceInterface
{
    private readonly SessionAccessor $session;

    public function __construct(?SessionAccessor $session = null)
    {
        $this->session = $session ?? new SessionAccessor();
    }

    /**
     * Normalize API room rows into the canonical rooms_data shape the shared
     * booking_guest_cards.tpl partial consumes: room_name, adults, children,
     * childrenAges[] (camelCase). Package/circuit quotes deliver rooms with
     * `name` + snake_case `children_ages`; when the quote carries no rooms at
     * all, ONE room is synthesized from the booking-level occupancy so the
     * form always renders guest cards (the old templates needed a duplicated
     * fallback branch for this case).
     *
     * @param array<int, array<string, mixed>> $rooms Raw API room rows
     * @param string $childrenAgesStr Booking-level ages, comma-separated ("7,12")
     * @return list<array<string, mixed>>
     */
    public static function normalizeRoomsForDisplay(array $rooms, int $fallbackAdults, string $childrenAgesStr): array
    {
        $normalized = [];
        foreach ($rooms as $room) {
            $ages = array_map(
                static fn ($a): int => TypeCoerce::toInt($a),
                TypeCoerce::toList($room['childrenAges'] ?? $room['children_ages'] ?? []),
            );
            $normalized[] = [
                'room_id' => TypeCoerce::toString($room['room_id'] ?? $room['code'] ?? ''),
                'room_name' => TypeCoerce::toString($room['room_name'] ?? $room['name'] ?? ''),
                'board_name' => TypeCoerce::toString($room['board_name'] ?? ''),
                'adults' => max(1, TypeCoerce::toInt($room['adults'] ?? $fallbackAdults)),
                'children' => count($ages),
                'childrenAges' => $ages,
            ];
        }

        if ($normalized === []) {
            $ages = array_values(array_map(
                'intval',
                array_filter(
                    array_map('trim', explode(',', $childrenAgesStr)),
                    static fn (string $a): bool => $a !== '',
                ),
            ));
            $normalized[] = [
                'room_id' => '',
                'room_name' => '',
                'board_name' => '',
                'adults' => max(1, $fallbackAdults),
                'children' => count($ages),
                'childrenAges' => $ages,
            ];
        }

        return $normalized;
    }
    /**
     * @return array<int, mixed>|null
     */
    #[\Override]
    public function checkRateLimit(string $errorRedirect = 'index.index'): ?array
    {
        $security = Container::getSecurityService();
        $auth = $this->session->auth();
        $rateLimitId = !empty($auth['user_id']) ? TypeCoerce::toString($auth['user_id']) : (string) session_id();

        if (!$security->checkBookingRateLimit($rateLimitId)) {
            fn_set_notification(
                'E',
                __('error'),
                __(
                    'sphinx_holidays.rate_limit_exceeded',
                    ['[default]' => 'Too many booking requests. Please try again later.'],
                ),
            );
            return [CONTROLLER_STATUS_REDIRECT, $errorRedirect];
        }

        return null;
    }

    /**
     * Check for an existing pending booking with the same offer_id.
     * Returns a redirect array if a duplicate is found, null otherwise.
     * @return array<int, mixed>|null
     */
    #[\Override]
    public function checkDuplicate(string $offerId, ?string $redirectUrl = null): ?array
    {
        $repo = Container::getBookingRepository();
        $pending = $repo->findPendingDuplicateByOffer($offerId, TravelConstants::STATUS_PENDING);

        if ($pending !== null) {
            fn_set_notification(
                'W',
                __('warning'),
                __(
                    'sphinx_holidays.duplicate_booking',
                    ['[default]' => 'A booking for this offer is already pending.'],
                ),
            );
            return [CONTROLLER_STATUS_REDIRECT, $redirectUrl ?? self::afterCartUrl()];
        }

        return null;
    }

    /**
     * Apply configured commission (if any) to a price.
     */
    #[\Override]
    public function applyCommission(float $price): float
    {
        $commission = ConfigProvider::getCommission();
        if ($commission <= 0 || $price <= 0) {
            return $price;
        }

        $calculator = new CommissionCalculator($commission, ConfigProvider::shouldRoundPrices() ? 'Y' : 'N');
        return $calculator->apply($price);
    }

    /**
     * Sanitize raw guest data and run server-side validation.
     * Returns the parsed result or false on validation failure (notification already set).
     *
     * @return array<string, mixed>|false
     * @param array<string, mixed> $rawGuests
     */
    #[\Override]
    public function parseGuests(array $rawGuests, string $dateRef): array|false
    {
        $security = Container::getSecurityService();
        $sanitized = $security->sanitizeGuestData($rawGuests);

        return GuestDataService::parseAndValidateGuests($sanitized, $dateRef, 'sphinx');
    }

    /**
     * Remember the provider's raw (pre-commission) price for an offer just
     * priced at add-to-cart, for the checkout "Silent Sync"
     * (PreOrderPriceVerifier): the same session entry hotel add-to-cart
     * writes, so circuit and package lines skip a second provider call while
     * it is fresh.
     */
    public function rememberVerifiedPrice(string $offerId, float $rawPrice): void
    {
        if ($offerId === '' || $rawPrice <= 0) {
            return;
        }
        $cache = TypeCoerce::toStringMap($this->session->get('sphinx_price_cache'));
        $cache[md5($offerId)] = ['api_price_raw' => $rawPrice, 'timestamp' => time()];
        $this->session->set('sphinx_price_cache', $cache);
    }

    /**
     * The CS-Cart product a hotel booking goes on: the hotel's own product
     * in ?:sphinx_hotels (so it is that hotel's, and a Sphinx product), and
     * buyable — active, or hidden by the availability gate. A product_id in
     * the request ($providedId) is accepted only when it is that same
     * product; 0 means refuse (ProviderCartProduct).
     */
    #[\Override]
    public function resolveProductId(string $entityId, int $providedId = 0): int
    {
        if ($entityId === '') {
            return 0;
        }

        $row = TypeCoerce::toStringMap(db_get_row(
            'SELECT product_id, product_skip_reason FROM ?:sphinx_hotels WHERE hotel_id = ?s',
            $entityId,
        ));

        return ProviderCartProduct::resolve(
            max(0, $providedId),
            TypeCoerce::toInt($row['product_id'] ?? 0),
            ProviderCartProduct::productStatus(...),
            TypeCoerce::toString($row['product_skip_reason'] ?? '') === HotelSkipRepository::SKIP_REASON_NO_AVAILABILITY,
        );
    }

    /**
     * The CS-Cart product for a circuit: its own product in ?:sphinx_circuits
     * (written by the add_circuit_products cron), for an active circuit, and
     * active — circuits have no availability gate. A product_id in the
     * request is accepted only when it is that same product.
     */
    public function resolveCircuitProductId(int $circuitId, int $providedId = 0): int
    {
        if ($circuitId <= 0) {
            return 0;
        }

        return ProviderCartProduct::resolve(
            max(0, $providedId),
            TypeCoerce::toInt(db_get_field(
                "SELECT product_id FROM ?:sphinx_circuits WHERE circuit_id = ?i AND sync_status = 'active'",
                $circuitId,
            )),
            ProviderCartProduct::productStatus(...),
        );
    }

    /**
     * Create or update a booking record using the findRecentUnassigned pattern.
     * Returns the booking_id.
     * @param array<string, mixed> $record
     */
    #[\Override]
    public function upsertBooking(
        array $record,
        string $entityId,
        string $checkIn,
        string $checkOut,
        string $holderName,
    ): int {
        $repo = Container::getBookingRepository();
        $existingId = $repo->findRecentUnassigned($entityId, $checkIn, $checkOut, $holderName);

        if ($existingId !== null) {
            $repo->update($existingId, $record);
            return $existingId;
        }

        $record['created_at'] = date('Y-m-d H:i:s');
        return $repo->create($record);
    }

    /**
     * Assemble the product entry in the CS-Cart cart and persist it.
     * Returns the controller redirect tuple.
     * @param array<string, mixed> $productExtra
     * @return array<int, mixed>
     */
    #[\Override]
    public function addToCartAndRedirect(
        int $productId,
        float $totalPrice,
        string $apiCurrency,
        array $productExtra,
        string $successMessage,
        ?string $redirectUrl = null,
        ?DepositPlan $deposit = null,
    ): array {
        $primaryCurrency = defined('CART_PRIMARY_CURRENCY') ? CART_PRIMARY_CURRENCY : 'EUR';
        $currencyService = new CurrencyService($apiCurrency);
        $cartPrice = $currencyService->convertFromApiCurrency($totalPrice, TypeCoerce::toString($primaryCurrency));

        $cartId = TypeCoerce::toString(fn_generate_cart_id($productId, $productExtra));

        // Write the assembled row into the session cart via the allowlisted
        // functions/ boundary — keeps Tygh::\$app out of this service class.
        $row = [
            'product_id' => $productId,
            'amount' => 1,
            'price' => $cartPrice,
            'base_price' => $cartPrice,
            'original_price' => $cartPrice,
            'extra' => $productExtra,
            'stored_price' => 'Y',
        ];
        fn_sphinx_holidays_write_cart_row($cartId, $deposit !== null ? DepositCartLine::apply($row, $deposit) : $row);

        fn_set_notification('N', __('notice'), $successMessage);

        return [CONTROLLER_STATUS_REDIRECT, $redirectUrl ?? self::afterCartUrl()];
    }

    /**
     * Build the base booking record fields shared across all 4 booking types.
     * Callers add/override type-specific fields before calling upsertBooking().
     *
     * @return array<string, mixed>
     * @param array<string, mixed> $parsedGuests
     * @param array<string, mixed> $contact
     * @param array<string, mixed> $apiResponse
     */
    #[\Override]
    public function buildBaseBookingRecord(
        int $productId,
        string $entityId,
        string $offerId,
        string $hotelName,
        array $parsedGuests,
        array $contact,
        float $basePrice,
        float $totalPrice,
        string $currency,
        array $apiResponse,
    ): array {
        $auth = $this->session->auth();

        return [
            'order_id' => 0,
            'user_id' => !empty($auth['user_id']) ? TypeCoerce::toInt($auth['user_id']) : 0,
            'session_id' => session_id(),
            'product_id' => $productId,
            'hotel_id' => $entityId,
            'hotel_name' => $hotelName,
            'offer_id' => $offerId,
            'guest_name' => $parsedGuests['guest_list'] ?? '',
            'holder_name' => $parsedGuests['holder_name'] ?? '',
            'guest_email' => $contact['email'] ?? '',
            'guest_phone' => $contact['phone'] ?? '',
            'guests_data' => json_encode($parsedGuests['guests_data'] ?? [], JSON_UNESCAPED_UNICODE),
            'base_price' => $basePrice,
            'total_price' => $totalPrice,
            'currency' => $currency,
            'status' => TravelConstants::STATUS_PENDING,
            'api_response' => json_encode($apiResponse, JSON_UNESCAPED_UNICODE),
        ];
    }

    /**
     * checkout.cart, or checkout.checkout when Settings -> Travel Core ->
     * "Skip the cart page for" ticks Sphinx (hotels, circuits and packages).
     */
    private static function afterCartUrl(): string
    {
        return CartSkipPolicy::current()->afterAddToCart('sphinx_holidays');
    }
}
