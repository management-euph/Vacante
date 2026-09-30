<?php

declare(strict_types=1);

/**
 * Sphinx Holidays - Pre-Order Price Verifier
 *
 * Re-verifies Sphinx offer prices at checkout (pre_place_order hook), per
 * booking type — each against its own endpoint:
 *  - hotel: verifyHotelOffer;
 *  - package: verifyPackageOffer (customizePackage when services were chosen);
 *  - circuit: no verify endpoint exists, so a fresh getCircuitQuote for the
 *    same departure and guests (customizeCircuit when services were chosen).
 * Circuit and package offers used to be sent to the HOTEL endpoint, which
 * does not know them: the line was dropped as "unavailable", or never
 * checked. If the offer is no longer available or the price has changed,
 * applies corrections or blocks the order.
 *
 * "Silent Sync" optimisation (same trust model as novoton): every add-to-cart
 * (hotel, circuit, package) prices its offer from the provider moments before
 * checkout and caches that raw price in the session. While that entry is
 * younger than the configurable TTL (default 180 s), this verifier trusts
 * it and skips its own API round-trip, so the Place Order click doesn't
 * pay a provider HTTP call per cart line.
 *
 * @package SphinxHolidays
 * @since   1.0.0
 */

namespace Tygh\Addons\SphinxHolidays\Services;

use Tygh\Addons\SphinxHolidays\Helpers\OfferAvailability;
use Tygh\Addons\TravelCore\Contracts\PreOrderPriceVerifierInterface;
use Tygh\Addons\TravelCore\Enums\PriceComparisonOutcome;
use Tygh\Addons\TravelCore\Helpers\SessionAccessor;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Services\CheckoutPriceGuard;

class PreOrderPriceVerifier implements PreOrderPriceVerifierInterface
{
    private readonly SessionAccessor $session;

    public function __construct(?SessionAccessor $session = null)
    {
        $this->session = $session ?? new SessionAccessor();
    }

    /**
     * {@inheritdoc}
     * @param array<string, mixed> $cart
     * @return array{allow: bool, corrections: array<int|string, array<string, float>>, notifications: array<int, array<string, mixed>>, unavailable: array<int|string, array<string, mixed>>, reconfirm: bool}
     */
    #[\Override]
    public function verify(array $cart): array
    {
        $result = [
            'allow' => true,
            'corrections' => [],
            'notifications' => [],
            'unavailable' => [],  // Cart IDs of unavailable Sphinx offers (to be removed by caller)
            'reconfirm' => false, // a correction exceeded the absorb allowance — hook must block for re-confirmation
        ];

        if (empty($cart['products'])) {
            return $result;
        }

        $api = null;
        $cacheTtl = ConfigProvider::getPreorderCacheTtl();

        // toArrayMap, NOT toStringMap: cart product ids are NUMERIC (PHP int
        // keys), and toStringMap drops every int-keyed entry — with it this
        // loop silently verified nothing for real carts.
        foreach (TypeCoerce::toArrayMap($cart['products']) as $cartId => $product) {
            $productData = TypeCoerce::toStringMap($product);
            $extra = TypeCoerce::toStringMap($productData['extra'] ?? null);
            if (empty($extra['sphinx_booking'])) {
                continue;
            }

            $offerId = TypeCoerce::toString($extra['offer_id'] ?? '');
            $formPrice = TypeCoerce::toFloat($extra['total_price'] ?? $productData['price'] ?? 0);

            if (empty($offerId) || $formPrice <= 0) {
                continue;
            }

            // ── Silent Sync: reuse the raw price verified at add-to-cart ──
            $apiPriceRaw = $this->getCachedRawPrice($offerId, $cacheTtl);

            if ($apiPriceRaw === null) {
                // Cache miss / stale — verify against the live API.
                if ($api === null) {
                    try {
                        $api = Container::getApi();
                    } catch (\Throwable) {
                        fn_log_event('general', 'runtime', [
                            'message' => 'Sphinx PreOrderPriceVerifier: API unavailable, skipping',
                        ]);
                        return $result;
                    }
                }

                $bookingType = TypeCoerce::toString($extra['booking_type'] ?? 'hotel');
                try {
                    $live = match ($bookingType) {
                        'package' => $this->livePackagePrice($api, $offerId, $extra),
                        'circuit' => $this->liveCircuitPrice($api, $offerId, $extra),
                        default => $this->liveHotelPrice($api, $offerId),
                    };
                } catch (\Throwable $e) {
                    fn_log_event('general', 'runtime', [
                        'message' => 'Sphinx PreOrderPriceVerifier: offer verify failed',
                        'offer_id' => $offerId,
                        'booking_type' => $bookingType,
                        'error' => $e->getMessage(),
                    ]);
                    continue;
                }

                // If offer is no longer available, mark for removal instead of blocking the entire order.
                // This allows mixed-provider carts (Novoton + Sphinx) to proceed with the available items.
                if ($live['unavailable']) {
                    fn_log_event('general', 'runtime', [
                        'message' => 'Sphinx PreOrderPriceVerifier: offer unavailable — marking for removal',
                        'offer_id' => $offerId,
                        'booking_type' => $bookingType,
                        'hotel_name' => $extra['hotel_name'] ?? '',
                    ]);

                    $result['unavailable'][$cartId] = [
                        'offer_id' => $offerId,
                        'hotel_name' => $extra['hotel_name'] ?? '',
                    ];
                    continue;
                }

                $apiPriceRaw = $live['price'];
                if ($apiPriceRaw === null || $apiPriceRaw <= 0) {
                    continue;
                }
            }

            // Re-calculate price with commission
            $apiPrice = Container::getCartService()->applyCommission($apiPriceRaw);

            // Shared "No Surprises" policy — identical knobs for all providers,
            // configured in travel_core settings (CheckoutPriceGuard).
            $comparison = CheckoutPriceGuard::policy()->compare($formPrice, $apiPrice);

            if ($comparison->outcome === PriceComparisonOutcome::Match) {
                continue; // Prices match
            }

            $notificationData = [
                'hotel_id' => $extra['hotel_id'] ?? '',
                'hotel_name' => $extra['hotel_name'] ?? '',
                'offer_id' => $offerId,
                'form_price' => $formPrice,
                'api_price' => $apiPrice,
                'cart_id' => (string)$cartId,
                'type' => $comparison->outcome === PriceComparisonOutcome::CorrectUp ? 'price_lower' : 'price_higher',
            ];

            if ($comparison->outcome === PriceComparisonOutcome::CorrectUp) {
                // Small increase within the absorb allowance: honour the price
                // the customer was shown — the merchant absorbs the difference.
                if ($comparison->difference <= CheckoutPriceGuard::absorbIncrease()) {
                    fn_log_event('general', 'runtime', [
                        'message' => 'Sphinx PreOrderPriceVerifier: ABSORBED — increase within allowance, honouring shown price',
                        'offer_id' => $offerId,
                        'form_price' => $formPrice,
                        'api_price' => $apiPrice,
                    ]);

                    $notificationData['type'] = 'price_absorbed';
                    $result['notifications'][] = $notificationData;
                    continue;
                }

                // Form price is lower than API — correct upward, never block;
                // the hook blocks this click so the customer re-confirms.
                fn_log_event('general', 'runtime', [
                    'message' => 'Sphinx PreOrderPriceVerifier: correcting price upward',
                    'offer_id' => $offerId,
                    'form_price' => $formPrice,
                    'api_price' => $apiPrice,
                ]);

                $result['corrections'][$cartId] = [
                    'api_price' => $apiPrice,
                    'api_price_raw' => $apiPriceRaw,
                ];
                $result['notifications'][] = $notificationData;
                $result['reconfirm'] = true;
            } else {
                // AboveThreshold: form price significantly higher — notify admin but allow
                fn_log_event('general', 'runtime', [
                    'message' => 'Sphinx PreOrderPriceVerifier: form price above API by ' . round($comparison->percentDelta, 1) . '%',
                    'offer_id' => $offerId,
                    'form_price' => $formPrice,
                    'api_price' => $apiPrice,
                ]);

                $result['notifications'][] = $notificationData;
            }
        }

        return $result;
    }

    /**
     * The hotel offer as the provider prices it now (verifyHotelOffer).
     * Tolerant availability semantics (verify responses may carry an explicit
     * `available`, a `confirmation`, or just a priced offer) — OfferAvailability.
     *
     * @return array{unavailable: bool, price: float|null} raw price, before commission
     */
    private function liveHotelPrice(\Tygh\Addons\SphinxHolidays\SphinxApi $api, string $offerId): array
    {
        $verified = TypeCoerce::toStringMap($api->verifyHotelOffer($offerId));
        if (!OfferAvailability::isVerifiedAvailable($verified === [] ? null : $verified, ConfigProvider::shouldRequireImmediateAvailability())) {
            return ['unavailable' => true, 'price' => null];
        }

        // Shared price chain: price / selling_price / pricing.selling_price
        return ['unavailable' => false, 'price' => OfferAvailability::extractPrice($verified)];
    }

    /**
     * The package offer as the provider prices it now (verifyPackageOffer),
     * with the chosen services re-priced by customizePackage. A customize
     * failure is ours to log, never a reason to drop the line (price: null).
     *
     * @param array<string, mixed> $extra the cart line's extra
     * @return array{unavailable: bool, price: float|null} raw price, before commission
     */
    private function livePackagePrice(\Tygh\Addons\SphinxHolidays\SphinxApi $api, string $offerId, array $extra): array
    {
        $verified = TypeCoerce::toStringMap($api->verifyPackageOffer($offerId));
        if (!OfferAvailability::isVerifiedAvailable($verified === [] ? null : $verified, ConfigProvider::shouldRequireImmediateAvailability())) {
            return ['unavailable' => true, 'price' => null];
        }

        $services = TypeCoerce::toStringList($extra['additional_services'] ?? []);
        if ($services === []) {
            return ['unavailable' => false, 'price' => OfferAvailability::extractPrice($verified)];
        }

        return ['unavailable' => false, 'price' => self::customizedPrice(
            $api->customizePackage(['offer_id' => $offerId, 'service_codes' => $services]),
        )];
    }

    /**
     * The circuit as the provider prices it now. Circuits have no verify
     * endpoint (as in BookingRetryService), so this is a fresh quote for the
     * same departure and guests: none means the departure is gone. The quote
     * for this offer_id when the provider returns it, else the first — the
     * one the booking form offered. Chosen services are re-priced by
     * customizeCircuit (price: null when that fails, never a removal).
     *
     * @param array<string, mixed> $extra the cart line's extra
     * @return array{unavailable: bool, price: float|null} raw price, before commission
     */
    private function liveCircuitPrice(\Tygh\Addons\SphinxHolidays\SphinxApi $api, string $offerId, array $extra): array
    {
        $services = TypeCoerce::toStringList($extra['service_codes'] ?? []);
        if ($services !== []) {
            $price = self::customizedPrice($api->customizeCircuit(['offer_id' => $offerId, 'service_codes' => $services]));
            if ($price !== null) {
                return ['unavailable' => false, 'price' => $price];
            }
        }

        $params = [
            'circuit_id' => TypeCoerce::toInt($extra['hotel_id'] ?? 0),
            'departure_date' => TypeCoerce::toString($extra['check_in'] ?? ''),
            'occupancy' => [[
                'adults' => max(1, TypeCoerce::toInt($extra['adults'] ?? 2)),
                'children_ages' => TypeCoerce::toIntList(array_filter(
                    explode(',', TypeCoerce::toString($extra['children_ages'] ?? '')),
                    static fn (string $age): bool => $age !== '',
                )),
            ]],
        ];
        $departureId = TypeCoerce::toInt($extra['departure_id'] ?? 0);
        if ($departureId > 0) {
            $params['departure_id'] = $departureId;
        }
        if ($params['circuit_id'] <= 0 || $params['departure_date'] === '') {
            return ['unavailable' => false, 'price' => null];
        }

        $quotes = TypeCoerce::toRowList($api->getCircuitQuote($params));
        if ($quotes === []) {
            return ['unavailable' => true, 'price' => null];
        }
        $quote = $quotes[0];
        foreach ($quotes as $candidate) {
            if (TypeCoerce::toString($candidate['offer_id'] ?? '') === $offerId) {
                $quote = $candidate;
                break;
            }
        }
        if ($services !== []) {
            // The services could not be re-priced: a base quote would compare wrong.
            return ['unavailable' => false, 'price' => null];
        }

        return ['unavailable' => false, 'price' => OfferAvailability::extractPrice($quote)];
    }

    /** The selling price from a customize answer, null when it has none. */
    private static function customizedPrice(mixed $customized): ?float
    {
        $pricing = TypeCoerce::toStringMap(TypeCoerce::toStringMap($customized)['pricing'] ?? null);
        $price = TypeCoerce::toFloat($pricing['selling_price'] ?? 0);

        return $price > 0 ? $price : null;
    }

    /**
     * Look up the session price cache written by the add_to_cart controller.
     *
     * A fresh entry means this exact offer was verified available at that
     * raw price moments ago (add_to_cart rejects unavailable offers), so
     * within the TTL the checkout click can trust it — the same window of
     * trust novoton's Silent Sync gives its session cache.
     *
     * @return float|null Raw (pre-commission) verified price; null on miss/stale
     */
    private function getCachedRawPrice(string $offerId, int $ttl): ?float
    {
        if ($ttl <= 0) {
            return null;
        }

        $bag = $this->session->get('sphinx_price_cache');
        if (!is_array($bag)) {
            return null;
        }

        $entry = $bag[md5($offerId)] ?? null;
        if (!is_array($entry)) {
            return null;
        }

        $entry = TypeCoerce::toStringMap($entry);
        $raw = TypeCoerce::toFloat($entry['api_price_raw'] ?? 0);
        $age = time() - TypeCoerce::toInt($entry['timestamp'] ?? 0);
        if ($raw <= 0 || $age > $ttl) {
            return null;
        }

        return $raw;
    }
}
