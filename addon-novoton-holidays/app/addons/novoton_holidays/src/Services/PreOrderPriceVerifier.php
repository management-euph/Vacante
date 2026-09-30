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

/**
 * @phpstan-import-type OfferRow from RoomOfferRows
 */
final class PreOrderPriceVerifier implements PreOrderPriceVerifierInterface
{
    /** Session bag of the Silent Sync entries (written by add_to_cart). */
    public const string PRICE_CACHE_SESSION_KEY = 'novoton_price_cache';

    private readonly SessionAccessor $session;

    /** @var \Closure(): (PricingApiClientInterface|null) */
    private readonly \Closure $pricing;

    /** @var \Closure(float): string */
    private readonly \Closure $formatAmount;

    /** @var \Closure(string, string, string): void */
    private readonly \Closure $notify;

    /** @var \Closure(string): string */
    private readonly \Closure $hotelName;

    /**
     * @param (\Closure(): (PricingApiClientInterface|null))|null $pricing the live room_price client;
     *                                                                     null = the add-on's API (null when it is not configured)
     * @param (\Closure(float): string)|null $formatAmount an API-currency amount written as the cart shows it
     * @param (\Closure(string, string, string): void)|null $notify storefront toast (type, title, message)
     * @param (\Closure(string): string)|null $hotelName catalogue hotel name for a hotel id ('' = unknown)
     */
    public function __construct(
        ?SessionAccessor $session = null,
        ?\Closure $pricing = null,
        ?\Closure $formatAmount = null,
        ?\Closure $notify = null,
        ?\Closure $hotelName = null,
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

            // ── Silent Sync: try session cache first ──
            $cached = $this->getCachedPrice($extra, $cacheTtl);

            if ($cached !== null) {
                $apiPriceWithCommission = PriceInfoFormatter::toFloat($cached['api_price'] ?? 0);
                $rawApiPrice = PriceInfoFormatter::toFloat($cached['api_price_raw'] ?? 0);

                if ($debug) {
                    fn_log_event('general', 'runtime', [
                        'message' => 'PreOrderPriceVerifier: using session-cached price (Silent Sync)',
                        'hotel_id' => PriceInfoFormatter::toScalar($extra['hotel_id'] ?? ''),
                        'age_sec' => time() - PriceInfoFormatter::toInt($cached['timestamp'] ?? 0),
                        'api_price' => $apiPriceWithCommission,
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
                    'hotel_id' => PriceInfoFormatter::toScalar($extra['hotel_id'] ?? ''),
                    'room_id' => PriceInfoFormatter::toScalar($extra['room_id'] ?? ''),
                    'board_id' => PriceInfoFormatter::toScalar($extra['board_id'] ?? ''),
                    'star_rating' => '',
                    'check_in' => PriceInfoFormatter::toScalar($extra['check_in'] ?? ''),
                    'check_out' => PriceInfoFormatter::toScalar($extra['check_out'] ?? ''),
                    'adults' => PriceInfoFormatter::toInt($extra['adults'] ?? 2),
                    'children' => self::parseChildrenAges($extra),
                ];

                if (empty($priceParams['hotel_id']) || empty($priceParams['check_in'])) {
                    continue;
                }

                $priceData = $pricing->getRoomPrice($priceParams);
                $rows = $priceData instanceof \SimpleXMLElement ? RoomOfferRows::fromXml($priceData) : [];
                $offer = self::lineOffer($rows, $extra);

                if ($offer['row'] === null) {
                    if ($offer['offer_missing']) {
                        $result['notifications'][] = $this->offerMissing($extra, $formPrice, $rows, PriceInfoFormatter::toScalar($cartId));
                    } elseif ($debug) {
                        fn_log_event('general', 'runtime', [
                            'message' => 'PreOrderPriceVerifier: API returned no price, allowing order',
                            'hotel_id' => $priceParams['hotel_id'],
                            'room_id' => $priceParams['room_id'],
                        ]);
                    }
                    continue;
                }

                $rawApiPrice = $offer['row']['price'];
                $apiPriceWithCommission = $pricing->applyCommission($rawApiPrice);
            }

            $checkResult = $this->comparePrice(
                $formPrice,
                $apiPriceWithCommission,
                $rawApiPrice,
                $extra,
                $cartId,
            );

            if (!empty($checkResult['correction'])) {
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
        return md5(implode('|', [
            PriceInfoFormatter::toScalar($extra['hotel_id'] ?? ''),
            PriceInfoFormatter::toScalar($extra['room_id'] ?? ''),
            PriceInfoFormatter::toScalar($extra['board_id'] ?? ''),
            PriceInfoFormatter::toScalar($extra['check_in'] ?? ''),
            PriceInfoFormatter::toScalar($extra['check_out'] ?? ''),
            PriceInfoFormatter::toInt($extra['adults'] ?? 2),
            implode(',', self::parseChildrenAges($extra)),
            RoomOfferRows::packageName(PriceInfoFormatter::toScalar($extra['package_name'] ?? '')),
        ]));
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
