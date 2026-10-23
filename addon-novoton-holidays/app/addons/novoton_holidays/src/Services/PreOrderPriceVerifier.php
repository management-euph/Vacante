<?php

declare(strict_types=1);

/**
 * Novoton Holidays - Pre-Order Price Verifier
 *
 * Real-time price verification at the final step of checkout.
 * Runs just before the order is placed (pre_place_order hook) to catch
 * price discrepancies between what the customer is paying and the
 * current API price.
 *
 * "Silent Sync" optimisation: the add_to_cart controller caches the
 * verified API price + timestamp in the user session. If the cached
 * entry is younger than the configurable TTL (default 180 s), we
 * trust it and skip the API round-trip, making checkout feel instant.
 *
 * The live price is the price of the offer the line was SOLD from: same
 * room, board and package. room_price answers with one row per package
 * (e.g. "+BEACH" at 795 and an early-booking "+BEACH [STAY …]" at 600);
 * reading the first <Price> compared a 600 line against 795 and pushed the
 * customer to another package's price.
 *
 * A multi-room line is the sum of its rooms (rooms_data), each priced with
 * its own room, board, package, adults and child ages, each with its own
 * Silent Sync entry — the reservation books every room, so every room is
 * checked, not only the line's top-level room (audit C2/H4).
 *
 * The rooms are priced as the reservation will send them: the booking
 * row's room fields and guests over the cart line's (the submission reads
 * the row first), each child at its guest's age when the guest has one
 * (BookingRoomAssembler) — an Edit Booking that moves a child's age is
 * re-priced here, not booked at the old age's price.
 *
 * Scenarios:
 *   1. Form price < API price → CORRECT cart price to API price, notify admin
 *   2. Form price > API price by > threshold% → ALLOW order, notify admin
 *   3. Prices match (within threshold) → ALLOW order silently
 *   4. The line's package (or room) is no longer offered → ALLOW order at the
 *      shown price, never re-price it to another package; notify admin
 *
 * @package NovotonHolidays
 * @since 3.5.0
 */

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Addons\NovotonHolidays\Api\Contracts\PricingApiClientInterface;
use Tygh\Addons\TravelCore\Contracts\PreOrderPriceVerifierInterface;
use Tygh\Addons\TravelCore\Enums\PriceComparisonOutcome;
use Tygh\Addons\TravelCore\Helpers\SessionAccessor;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Services\CheckoutPriceGuard;
use Tygh\Addons\TravelCore\Services\GuestDataNormalizer;

/**
 * @phpstan-import-type OfferRow from RoomOfferRows
 */
final class PreOrderPriceVerifier implements PreOrderPriceVerifierInterface
{
    /** Session bag of the Silent Sync entries (written by add_to_cart). */
    public const string PRICE_CACHE_SESSION_KEY = 'novoton_price_cache';

    /**
     * The booking row's fields the reservation is built from: non-empty,
     * they win over the cart line's (BookingSubmissionService::hydrateBookingFromDb).
     */
    private const array BOOKED_FIELDS = [
        'hotel_id', 'package_name', 'room_id', 'board_id', 'check_in', 'check_out',
        'adults', 'children', 'children_ages', 'rooms_data', 'guests_data',
    ];

    private readonly SessionAccessor $session;

    /** @var \Closure(): (PricingApiClientInterface|null) */
    private readonly \Closure $pricing;

    /** @var \Closure(float): string */
    private readonly \Closure $formatAmount;

    /** @var \Closure(string, string, string): void */
    private readonly \Closure $notify;

    /** @var \Closure(string): string */
    private readonly \Closure $hotelName;

    /** @var \Closure(int): (array<string, mixed>|null) */
    private readonly \Closure $bookingRow;

    /**
     * @param (\Closure(): (PricingApiClientInterface|null))|null $pricing the live room_price client;
     *                                                                     null = the add-on's API (null when it is not configured)
     * @param (\Closure(float): string)|null $formatAmount an API-currency amount written as the cart shows it
     * @param (\Closure(string, string, string): void)|null $notify storefront toast (type, title, message)
     * @param (\Closure(string): string)|null $hotelName catalogue hotel name for a hotel id ('' = unknown)
     * @param (\Closure(int): (array<string, mixed>|null))|null $bookingRow the novoton_bookings row of a booking id
     */
    public function __construct(
        ?SessionAccessor $session = null,
        ?\Closure $pricing = null,
        ?\Closure $formatAmount = null,
        ?\Closure $notify = null,
        ?\Closure $hotelName = null,
        ?\Closure $bookingRow = null,
    ) {
        $this->session = $session ?? new SessionAccessor();
        $this->pricing = $pricing ?? static function (): ?PricingApiClientInterface {
            $api = fn_novoton_holidays_get_api();

            return $api?->pricing();
        };
        $this->formatAmount = $formatAmount ?? static fn (float $amount): string => function_exists('fn_novoton_holidays_format_api_amount_for_shopper')
            ? fn_novoton_holidays_format_api_amount_for_shopper($amount)
            : number_format($amount, 2);
        $this->notify = $notify ?? static function (string $type, string $title, string $message): void {
            fn_set_notification($type, $title, $message);
        };
        $this->hotelName = $hotelName ?? static function (string $hotelId): string {
            $hotel = Container::getInstance()->hotelRepository()->findBasicById($hotelId);

            return TypeCoerce::toString($hotel['hotel_name'] ?? '');
        };
        $this->bookingRow = $bookingRow ?? static fn (int $bookingId): ?array => Container::getInstance()->bookingRepository()->findById($bookingId);
    }

    /**
     * Verify all Novoton booking products in the cart against live API prices.
     *
     * @param array<string, mixed> $cart CS-Cart cart array
     * @return array{allow: bool, corrections: array<string, array<string, mixed>>, notifications: list<array<string, mixed>>, reconfirm: bool}
     *                                                                                                                                          - allow: always true (correction/blocking policy is applied by the hook)
     *                                                                                                                                          - corrections: cart_id => ['api_price' => float, 'api_price_raw' => float]
     *                                                                                                                                          - notifications: list of discrepancy data for admin emails
     *                                                                                                                                          - reconfirm: a correction exceeded the absorb allowance — the hook must
     *                                                                                                                                          block this order click so the customer re-confirms the new total
     */
    #[\Override]
    public function verify(array $cart): array
    {
        $result = [
            'allow' => true,
            'corrections' => [],
            'notifications' => [],
            'reconfirm' => false,
        ];

        if (!ConfigProvider::isPreorderPriceCheckEnabled()) {
            return $result;
        }

        if (empty($cart['products'])) {
            return $result;
        }

        // An admin editing a placed order (cart.order_id) re-places it: its
        // bookings were priced and submitted with the order, and today's
        // live price must not correct them or block the save.
        if (TypeCoerce::toInt($cart['order_id'] ?? 0) > 0) {
            return $result;
        }

        $cacheTtl = ConfigProvider::getPreorderCacheTtl();
        $debug = ConfigProvider::isDebugLogging();

        // Lazy-load the API only when actually needed (cache miss).
        $pricing = null;

        $cartProducts = is_array($cart['products']) ? $cart['products'] : [];
        foreach ($cartProducts as $cartId => $product) {
            if (!is_array($product)) {
                continue;
            }
            $extra = TypeCoerce::toStringMap($product['extra'] ?? null);
            if (empty($extra['novoton_booking'])) {
                continue;
            }

            $formPrice = PriceInfoFormatter::toFloat($extra['total_price'] ?? $product['price'] ?? 0);

            if ($formPrice <= 0) {
                continue;
            }

            // The admin email and log name the hotel; an empty name made
            // them read "for hotel  [476]".
            $extra['hotel_name'] = $this->resolveHotelName($extra, $product);
            $extra = $this->withBookingRow($extra);

            // Every room the reservation books is priced on its own (audit
            // C2/H4): one line per room, the line's price is their sum.
            $roomLines = self::roomLines($extra);
            $multiRoom = count($roomLines) > 1;
            $apiPriceWithCommission = 0.0;
            $rawApiPrice = 0.0;
            $roomPrices = [];

            // ── Silent Sync: a multi-room line's own entry (the hook writes it
            // after a correction), else each room's entry (add_to_cart) ──
            $lineCached = $multiRoom ? $this->getCachedPrice($extra, $cacheTtl) : null;
            if ($lineCached !== null) {
                $apiPriceWithCommission = PriceInfoFormatter::toFloat($lineCached['api_price'] ?? 0);
                $rawApiPrice = PriceInfoFormatter::toFloat($lineCached['api_price_raw'] ?? 0);
                $roomLines = [];
            }

            foreach ($roomLines as $roomLine) {
                $cached = $this->getCachedPrice($roomLine, $cacheTtl);

                if ($cached !== null) {
                    $roomApiPrice = PriceInfoFormatter::toFloat($cached['api_price'] ?? 0);
                    $roomRawPrice = PriceInfoFormatter::toFloat($cached['api_price_raw'] ?? 0);

                    if ($debug) {
                        fn_log_event('general', 'runtime', [
                            'message' => 'PreOrderPriceVerifier: using session-cached price (Silent Sync)',
                            'hotel_id' => PriceInfoFormatter::toScalar($roomLine['hotel_id'] ?? ''),
                            'room_id' => PriceInfoFormatter::toScalar($roomLine['room_id'] ?? ''),
                            'age_sec' => time() - PriceInfoFormatter::toInt($cached['timestamp'] ?? 0),
                            'api_price' => $roomApiPrice,
                        ]);
                    }
                } else {
                    // Cache miss / stale — call the API
                    if ($pricing === null) {
                        $pricing = ($this->pricing)();
                        if ($pricing === null) {
                            fn_log_event('general', 'runtime', [
                                'message' => 'PreOrderPriceVerifier: API unavailable, skipping price check',
                            ]);
                            return $result;
                        }
                    }

                    $priceParams = [
                        'hotel_id' => PriceInfoFormatter::toScalar($roomLine['hotel_id'] ?? ''),
                        'room_id' => PriceInfoFormatter::toScalar($roomLine['room_id'] ?? ''),
                        'board_id' => PriceInfoFormatter::toScalar($roomLine['board_id'] ?? ''),
                        'star_rating' => '',
                        'check_in' => PriceInfoFormatter::toScalar($roomLine['check_in'] ?? ''),
                        'check_out' => PriceInfoFormatter::toScalar($roomLine['check_out'] ?? ''),
                        'adults' => PriceInfoFormatter::toInt($roomLine['adults'] ?? 2),
                        'children' => self::parseChildrenAges($roomLine),
                    ];

                    if (empty($priceParams['hotel_id']) || empty($priceParams['check_in'])) {
                        continue 2;
                    }

                    $priceData = $pricing->getRoomPrice($priceParams);
                    $rows = $priceData instanceof \SimpleXMLElement ? RoomOfferRows::fromXml($priceData) : [];
                    $offer = self::lineOffer($rows, $roomLine);

                    // A room without a price leaves the whole line at the
                    // shown price, as a single room always did.
                    if ($offer['row'] === null) {
                        if ($offer['offer_missing']) {
                            $result['notifications'][] = $this->offerMissing($roomLine, $formPrice, $rows, PriceInfoFormatter::toScalar($cartId));
                        } elseif ($debug) {
                            fn_log_event('general', 'runtime', [
                                'message' => 'PreOrderPriceVerifier: API returned no price, allowing order',
                                'hotel_id' => $priceParams['hotel_id'],
                                'room_id' => $priceParams['room_id'],
                            ]);
                        }
                        continue 2;
                    }

                    $roomRawPrice = $offer['row']['price'];
                    $roomApiPrice = $pricing->applyCommission($roomRawPrice);
                }

                $apiPriceWithCommission += $roomApiPrice;
                $rawApiPrice += $roomRawPrice;
                $roomPrices[] = ['price' => $roomApiPrice, 'price_raw' => $roomRawPrice];
            }

            $checkResult = $this->comparePrice(
                $formPrice,
                $apiPriceWithCommission,
                $rawApiPrice,
                $extra,
                $cartId,
            );

            if (!empty($checkResult['correction'])) {
                if ($multiRoom && $roomPrices !== []) {
                    // Each room's live price, in rooms_data order, for whoever
                    // writes the correction back into the booking's rooms.
                    $checkResult['correction']['room_prices'] = $roomPrices;
                }
                $result['corrections'][(string) $cartId] = $checkResult['correction'];
            }

            if (!empty($checkResult['reconfirm'])) {
                $result['reconfirm'] = true;
            }

            if (!empty($checkResult['notification'])) {
                $result['notifications'][] = $checkResult['notification'];
            }
        }

        return $result;
    }

    /**
     * The offer a cart line was sold from, out of a room_price answer.
     *
     * Same room (URL-decoded, as add_to_cart matches it), same board, and —
     * when the line carries one — the same package: a package that is no
     * longer offered is NEVER swapped for another package's price, that
     * would bill the customer for a stay they did not choose.
     * Without a package: the cheapest offer of the room + board.
     *
     * @param list<OfferRow> $rows RoomOfferRows::fromXml() of the answer
     * @param array<string, mixed> $extra cart line extra (room_id, board_id, package_name)
     * @return array{row: OfferRow|null, offer_missing: bool}
     *                                                        offer_missing: the answer priced other offers, but not this line's
     */
    public static function lineOffer(array $rows, array $extra): array
    {
        $row = RoomOfferRows::cheapest(
            $rows,
            rawurldecode(PriceInfoFormatter::toScalar($extra['room_id'] ?? '')),
            PriceInfoFormatter::toScalar($extra['board_id'] ?? ''),
            PriceInfoFormatter::toScalar($extra['package_name'] ?? ''),
        );

        return ['row' => $row, 'offer_missing' => $row === null && $rows !== []];
    }

    /**
     * Session key of a cart line's Silent Sync entry. add_to_cart writes it,
     * this verifier reads it and the pre_place_order hook refreshes it after
     * a correction — one recipe, so the three can never drift apart.
     *
     * The package is part of the key: the cached price is the price of that
     * package's offer, not of the room in general.
     *
     * @param array<string, mixed> $extra cart line extra
     */
    public static function priceCacheKey(array $extra): string
    {
        $roomLines = self::roomLines($extra);
        if (count($roomLines) > 1) {
            // A multi-room line: its own entry, made of every room's recipe,
            // so it can never be read as one room's price (or the reverse).
            return md5('rooms|' . implode('#', array_map(self::cacheKeyParts(...), $roomLines)));
        }

        // One room: the room as booked. add_to_cart writes the line's
        // top-level tuple equal to it, so the key is the one it always was.
        return md5(self::cacheKeyParts($roomLines[0]));
    }

    /**
     * The rooms a cart line books, each as a line of its own: the line's
     * extra with that room's room_id, board_id, package_name, adults,
     * children and children_ages — the values the reservation sends
     * (BookingRoomsGuestsResolver, BookingRoomAssembler, ApiBookingRequestBuilder):
     *   - the rooms of rooms_data; without it, the line's own tuple;
     *   - room, board and package: the room's own, else the line's;
     *   - adults: the room's own, else 2; children: the room's count;
     *   - child i of room N: the age of guest room{N}_child_{i} when the
     *     guest has one (guests_data), else the room's childrenAges[i - 1].
     *
     * A room line carries no rooms_data and no guests_data, so it is its own
     * single room line.
     *
     * @param array<string, mixed> $extra cart line extra
     * @return non-empty-list<array<string, mixed>>
     */
    public static function roomLines(array $extra): array
    {
        $rooms = $extra['rooms_data'] ?? null;
        if (is_string($rooms) && $rooms !== '') {
            $rooms = json_decode($rooms, true);
        }
        $rooms = TypeCoerce::toRowList($rooms);
        if ($rooms === []) {
            // No rooms_data: the reservation books the line's tuple.
            $ages = self::parseChildrenAges($extra);
            $rooms = [[
                'adults' => $extra['adults'] ?? 2,
                'children' => array_key_exists('children', $extra) ? $extra['children'] : count($ages),
                'childrenAges' => $ages,
            ]];
        }
        $guests = self::guests($extra['guests_data'] ?? null);

        $line = $extra;
        unset($line['rooms_data'], $line['guests_data']);
        $lines = [];
        foreach ($rooms as $index => $room) {
            $roomNum = $index + 1;
            $roomAges = TypeCoerce::toIntList(TypeCoerce::toList($room['childrenAges'] ?? []));
            $ages = [];
            for ($i = 1, $children = PriceInfoFormatter::toInt($room['children'] ?? 0); $i <= $children; $i++) {
                $guest = $guests["room{$roomNum}_child_{$i}"] ?? null;
                $ages[] = is_array($guest) && isset($guest['age'])
                    ? PriceInfoFormatter::toInt($guest['age'])
                    : ($roomAges[$i - 1] ?? 0);
            }
            $lines[] = [
                'room_id' => PriceInfoFormatter::toScalar($room['room_id'] ?? $extra['room_id'] ?? ''),
                'board_id' => PriceInfoFormatter::toScalar($room['board_id'] ?? $extra['board_id'] ?? ''),
                'package_name' => PriceInfoFormatter::toScalar($room['package_name'] ?? $extra['package_name'] ?? ''),
                'adults' => PriceInfoFormatter::toInt($room['adults'] ?? 2),
                'children' => count($ages),
                'children_ages' => implode(',', $ages),
            ] + $line;
        }

        return $lines;
    }

    /**
     * A line's guests as the reservation reads them (keyed room{N}_{type}_{i}).
     *
     * @return array<string, mixed>
     */
    private static function guests(mixed $raw): array
    {
        if (is_array($raw)) {
            $raw = json_encode($raw);
        }
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        return (new GuestDataNormalizer())->normalize($raw);
    }

    /**
     * The cart line with the booking row's room fields and guests over its
     * own, as the submission reads them. A failed lookup keeps the line.
     *
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function withBookingRow(array $extra): array
    {
        $bookingId = PriceInfoFormatter::toInt($extra['novoton_booking_id'] ?? 0);
        if ($bookingId <= 0) {
            return $extra;
        }
        try {
            $row = ($this->bookingRow)($bookingId) ?? [];
        } catch (\Throwable) {
            return $extra;
        }
        foreach (self::BOOKED_FIELDS as $field) {
            if (!empty($row[$field])) {
                $extra[$field] = $row[$field];
            }
        }

        return $extra;
    }

    /**
     * One room's Silent Sync recipe.
     *
     * @param array<string, mixed> $extra
     */
    private static function cacheKeyParts(array $extra): string
    {
        return implode('|', [
            PriceInfoFormatter::toScalar($extra['hotel_id'] ?? ''),
            PriceInfoFormatter::toScalar($extra['room_id'] ?? ''),
            PriceInfoFormatter::toScalar($extra['board_id'] ?? ''),
            PriceInfoFormatter::toScalar($extra['check_in'] ?? ''),
            PriceInfoFormatter::toScalar($extra['check_out'] ?? ''),
            PriceInfoFormatter::toInt($extra['adults'] ?? 2),
            implode(',', self::parseChildrenAges($extra)),
            RoomOfferRows::packageName(PriceInfoFormatter::toScalar($extra['package_name'] ?? '')),
        ]);
    }

    /**
     * Look up the session price cache written by add_to_cart.
     *
     * @param array<string, mixed> $extra Cart product extra data
     * @param int $ttl Max age in seconds
     * @return array<string, mixed>|null Cached entry or null if miss/stale
     */
    private function getCachedPrice(array $extra, int $ttl): ?array
    {
        $sessionCache = $this->session->get(self::PRICE_CACHE_SESSION_KEY);
        if (!is_array($sessionCache) || $sessionCache === []) {
            return null;
        }

        $entry = $sessionCache[self::priceCacheKey($extra)] ?? null;
        if (!is_array($entry)) {
            return null;
        }
        $entry = TypeCoerce::toStringMap($entry);
        $age = time() - PriceInfoFormatter::toInt($entry['timestamp'] ?? 0);

        if ($age > $ttl) {
            return null; // stale
        }

        return $entry;
    }

    /**
     * The booked package is gone from the live answer: leave the line at the
     * price the customer was shown and tell the admin what IS offered.
     *
     * @param array<string, mixed> $extra
     * @param list<OfferRow> $rows
     * @return array<string, mixed>
     */
    private function offerMissing(array $extra, float $formPrice, array $rows, string $cartId): array
    {
        $offered = [];
        $sameRoom = RoomOfferRows::matching(
            $rows,
            rawurldecode(PriceInfoFormatter::toScalar($extra['room_id'] ?? '')),
            PriceInfoFormatter::toScalar($extra['board_id'] ?? ''),
        );
        foreach ($sameRoom as $row) {
            $offered[] = ($row['package'] !== '' ? $row['package'] : '-') . ': ' . number_format($row['price'], 2);
        }

        $notification = $this->notificationData($formPrice, 0.0, 0.0, $extra, $cartId);
        $notification['type'] = 'offer_missing';
        $notification['offered'] = $offered;

        fn_log_event('general', 'runtime', [
            'message' => 'PreOrderPriceVerifier: OFFER MISSING — the booked package/room is no longer offered; cart left at the shown price',
            'hotel_id' => $notification['hotel_id'],
            'hotel_name' => $notification['hotel_name'],
            'room_id' => $notification['room_id'],
            'board_id' => $notification['board_id'],
            'package_name' => $notification['package_name'],
            'form_price' => $formPrice,
            'offered' => $offered,
        ]);

        return $notification;
    }

    /**
     * The hotel name for the admin email and log: the line's own, else the
     * cart product's name, else the catalogue's, else "#<hotel id>".
     *
     * @param array<string, mixed> $extra
     * @param array<mixed> $product cart product
     */
    private function resolveHotelName(array $extra, array $product): string
    {
        $name = trim(PriceInfoFormatter::toScalar($extra['hotel_name'] ?? ''));
        if ($name === '') {
            $name = trim(PriceInfoFormatter::toScalar($product['product'] ?? ''));
        }
        $hotelId = PriceInfoFormatter::toScalar($extra['hotel_id'] ?? '');
        if ($name === '' && $hotelId !== '') {
            try {
                $name = trim(($this->hotelName)($hotelId));
            } catch (\Throwable) {
                // A failed lookup must never stop the order.
                $name = '';
            }
        }

        return $name !== '' ? $name : '#' . $hotelId;
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function notificationData(float $formPrice, float $apiPrice, float $rawApiPrice, array $extra, string $cartId): array
    {
        return [
            'hotel_id' => PriceInfoFormatter::toScalar($extra['hotel_id'] ?? ''),
            'hotel_name' => PriceInfoFormatter::toScalar($extra['hotel_name'] ?? ''),
            'room_id' => PriceInfoFormatter::toScalar($extra['room_id'] ?? ''),
            'board_id' => PriceInfoFormatter::toScalar($extra['board_id'] ?? ''),
            'package_name' => RoomOfferRows::packageName(PriceInfoFormatter::toScalar($extra['package_name'] ?? '')),
            'check_in' => PriceInfoFormatter::toScalar($extra['check_in'] ?? ''),
            'check_out' => PriceInfoFormatter::toScalar($extra['check_out'] ?? ''),
            'adults' => PriceInfoFormatter::toInt($extra['adults'] ?? 2),
            'children' => PriceInfoFormatter::toInt($extra['children'] ?? 0),
            'children_ages' => PriceInfoFormatter::toScalar($extra['children_ages'] ?? ''),
            'form_price' => $formPrice,
            'api_price' => $apiPrice,
            'api_price_raw' => $rawApiPrice,
            'cart_id' => $cartId,
        ];
    }

    /**
     * Compare form price to API price and determine action.
     *
     * @param array<string, mixed> $extra
     * @param int|string $cartId
     * @return array{allow: bool, correction: array<string, mixed>|null, notification: array<string, mixed>|null, type: string, reconfirm: bool}
     */
    private function comparePrice(
        float $formPrice,
        float $apiPrice,
        float $rawApiPrice,
        array $extra,
        $cartId,
    ): array {
        $cartId = PriceInfoFormatter::toScalar($cartId);
        $notificationData = $this->notificationData($formPrice, $apiPrice, $rawApiPrice, $extra, $cartId);
        $hotelId = PriceInfoFormatter::toScalar($notificationData['hotel_id']);
        $hotelName = PriceInfoFormatter::toScalar($notificationData['hotel_name']);
        $roomId = PriceInfoFormatter::toScalar($notificationData['room_id']);

        // Use PriceChangeDetector for consistent "No Surprises" UX
        $detector = Container::getInstance()->priceChangeDetector();
        $changeInfo = $detector->analyse(
            $formPrice,
            $apiPrice,
            ConfigProvider::getApiCurrency(),
            'checkout',
            [
                'hotel_name' => $hotelName,
                'hotel_id' => $hotelId,
                'room_id' => $roomId,
            ],
        );

        // Shared "No Surprises" policy — identical knobs for all providers,
        // configured in travel_core settings (CheckoutPriceGuard).
        $comparison = CheckoutPriceGuard::policy()->compare($formPrice, $apiPrice);

        // Case 1: Form price is LOWER than API price → CORRECT (never block)
        if ($comparison->outcome === PriceComparisonOutcome::CorrectUp) {
            $difference = round($comparison->difference, 2);
            $percentLower = round($comparison->percentDelta, 1);

            // Small increase within the absorb allowance: honour the price the
            // customer was shown — the merchant absorbs the difference.
            if ($comparison->difference <= CheckoutPriceGuard::absorbIncrease()) {
                fn_log_event('general', 'runtime', [
                    'message' => 'PreOrderPriceVerifier: ABSORBED — increase within allowance, honouring shown price',
                    'hotel_id' => $hotelId,
                    'room_id' => $roomId,
                    'form_price' => $formPrice,
                    'api_price' => $apiPrice,
                    'difference' => $difference,
                ]);

                $notificationData['difference'] = $difference;
                $notificationData['percent'] = $percentLower;
                $notificationData['type'] = 'price_absorbed';

                return [
                    'allow' => true,
                    'type' => 'price_absorbed',
                    'correction' => null,
                    'notification' => $notificationData,
                    'reconfirm' => false,
                ];
            }

            // The admin learns about it from this log entry and the email the
            // hook sends — never from a storefront toast the shopper sees.
            fn_log_event('general', 'runtime', [
                'message' => 'PreOrderPriceVerifier: CORRECTED — form price below API price, upgrading cart',
                'hotel_id' => $hotelId,
                'hotel_name' => $hotelName,
                'room_id' => $roomId,
                'package_name' => $notificationData['package_name'],
                'form_price' => $formPrice,
                'api_price' => $apiPrice,
                'difference' => $difference,
                'percent_lower' => $percentLower,
            ]);

            $notificationData['difference'] = $difference;
            $notificationData['percent'] = $percentLower;
            $notificationData['type'] = 'price_lower';

            // User-facing price change alert (orange badge for increase)
            if ($changeInfo['significant']) {
                $detector->storeAlert($changeInfo, $cartId);
                ($this->notify)(
                    'W',
                    TypeCoerce::toString(__('novoton_holidays.price_change')),
                    TypeCoerce::toString(__('novoton_holidays.price_updated_at_checkout', [
                        '[old_price]' => ($this->formatAmount)($formPrice),
                        '[new_price]' => ($this->formatAmount)($apiPrice),
                    ])),
                );
            }

            return [
                'allow' => true,
                'type' => 'price_lower',
                'correction' => [
                    'api_price' => $apiPrice,
                    'api_price_raw' => $rawApiPrice,
                ],
                'notification' => $notificationData,
                // Beyond the absorb allowance: the hook blocks this click so the
                // customer re-confirms the corrected total (EU CRD: the amount
                // charged must be the amount shown at the order button).
                'reconfirm' => true,
            ];
        }

        // Case 2: Form price is HIGHER than API price by more than threshold%
        if ($apiPrice > 0) {
            $difference = round($comparison->difference, 2);
            $percentHigher = round($comparison->percentDelta, 1);

            if ($comparison->outcome === PriceComparisonOutcome::AboveThreshold) {
                // Admin-only: log + email (the hook), no storefront toast.
                fn_log_event('general', 'runtime', [
                    'message' => 'PreOrderPriceVerifier: ALERT — form price significantly above API price',
                    'hotel_id' => $hotelId,
                    'hotel_name' => $hotelName,
                    'room_id' => $roomId,
                    'package_name' => $notificationData['package_name'],
                    'form_price' => $formPrice,
                    'api_price' => $apiPrice,
                    'difference' => $difference,
                    'percent_higher' => $percentHigher,
                    'threshold' => CheckoutPriceGuard::alertPercent(),
                ]);

                $notificationData['difference'] = $difference;
                $notificationData['percent'] = $percentHigher;
                $notificationData['type'] = 'price_higher';

                return [
                    'allow' => true,
                    'type' => 'price_higher',
                    'correction' => null,
                    'notification' => $notificationData,
                    'reconfirm' => false,
                ];
            }

            // Price decrease detected — show green "Price Dropped!" if significant
            if ($changeInfo['significant'] && $changeInfo['direction'] === 'decrease') {
                $detector->storeAlert($changeInfo, $cartId);
                ($this->notify)(
                    'N',
                    TypeCoerce::toString(__('novoton_holidays.price_dropped')),
                    TypeCoerce::toString(__('novoton_holidays.price_dropped_to', [
                        '[new_price]' => ($this->formatAmount)($apiPrice),
                    ])),
                );
            }
        }

        // Case 3: Prices are within acceptable range
        return [
            'allow' => true,
            'type' => 'ok',
            'correction' => null,
            'notification' => null,
            'reconfirm' => false,
        ];
    }

    /**
     * Parse children ages from cart extra data.
     *
     * @param array<string, mixed> $extra
     * @return list<int>
     */
    private static function parseChildrenAges(array $extra): array
    {
        $raw = $extra['children_ages'] ?? '';

        if (is_array($raw)) {
            return array_values(array_map(static fn ($v): int => TypeCoerce::toInt($v), $raw));
        }
        if (is_string($raw) && $raw !== '') {
            return array_values(array_map('intval', array_filter(
                explode(',', $raw),
                static fn (string $v): bool => $v !== '',
            )));
        }

        return [];
    }
}
