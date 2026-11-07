<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Services;

use Tygh\Addons\TravelCore\Helpers\SessionAccessor;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * The circuit quote the booking form got from the provider, kept on the
 * server (the session) by offer_id — so circuit add-to-cart never takes the
 * price from the form. Circuits have no verify endpoint (hotels and packages
 * do); this snapshot is what stands in for it.
 *
 * `selling_price` is the provider's own figure, BEFORE commission: the cart
 * applies commission exactly once. The quote's whole `pricing` and its
 * `rooms` (their codes) are kept too: circuits/book sends that price and
 * those room codes for the offer, never the form's copies.
 */
final class CircuitQuoteStore
{
    public const string SESSION_KEY = 'sphinx_circuit_quotes';

    /** A quote older than this is not trusted: the guest gets a fresh one. */
    public const int TTL = 3600;

    /** At most this many quotes per session (oldest dropped first). */
    private const int MAX_QUOTES = 20;

    public function __construct(private readonly SessionAccessor $session = new SessionAccessor())
    {
    }

    /**
     * @param string $offerId the quote's offer
     * @param int $circuitId the circuit quoted
     * @param float $sellingPrice the quote's selling price, before commission
     * @param string $currency its currency
     * @param string $departureDate Y-m-d
     * @param int $now the current time
     * @param string $mealType the quote's meal plan ("Half Board"), for the order
     * @param string $departureName where the trip starts ("Bucharest"), for the order
     * @param array<string, mixed> $pricing the quote's pricing (selling_price, supplier_price, ...)
     * @param list<array<string, mixed>> $rooms the quote's rooms (code, name, adults, children_ages)
     */
    public function remember(
        string $offerId,
        int $circuitId,
        float $sellingPrice,
        string $currency,
        string $departureDate,
        int $now,
        string $mealType = '',
        string $departureName = '',
        array $pricing = [],
        array $rooms = [],
    ): void {
        if ($offerId === '' || $circuitId <= 0 || $sellingPrice <= 0) {
            return;
        }
        $quotes = TypeCoerce::toStringMap($this->session->get(self::SESSION_KEY));
        $quotes[md5($offerId)] = [
            'circuit_id' => $circuitId,
            'selling_price' => $sellingPrice,
            'currency' => $currency,
            'departure_date' => $departureDate,
            'meal_type' => $mealType,
            'departure_name' => $departureName,
            'pricing' => $pricing,
            'rooms' => $rooms,
            'stored_at' => $now,
        ];
        $this->session->set(self::SESSION_KEY, array_slice($quotes, -self::MAX_QUOTES, null, true));
    }

    /**
     * The quote stored for this offer, for this circuit, still fresh; null
     * otherwise (the caller must then refuse, never fall back to the form).
     *
     * @param string $offerId the offer asked for
     * @param int $circuitId the circuit it must be a quote of
     * @param int $now the current time
     *
     * @return array{circuit_id: int, selling_price: float, currency: string, departure_date: string, meal_type: string, departure_name: string, pricing: array<string, mixed>, rooms: list<array<string, mixed>>}|null
     */
    public function get(string $offerId, int $circuitId, int $now): ?array
    {
        if ($offerId === '') {
            return null;
        }
        $quote = TypeCoerce::toStringMap(TypeCoerce::toStringMap($this->session->get(self::SESSION_KEY))[md5($offerId)] ?? null);
        if (
            $quote === []
            || TypeCoerce::toInt($quote['circuit_id'] ?? 0) !== $circuitId
            || $now - TypeCoerce::toInt($quote['stored_at'] ?? 0) > self::TTL
            || TypeCoerce::toFloat($quote['selling_price'] ?? 0) <= 0
        ) {
            return null;
        }

        return [
            'circuit_id' => $circuitId,
            'selling_price' => TypeCoerce::toFloat($quote['selling_price']),
            'currency' => TypeCoerce::toString($quote['currency'] ?? ''),
            'departure_date' => TypeCoerce::toString($quote['departure_date'] ?? ''),
            'meal_type' => TypeCoerce::toString($quote['meal_type'] ?? ''),
            'departure_name' => TypeCoerce::toString($quote['departure_name'] ?? ''),
            'pricing' => TypeCoerce::toStringMap($quote['pricing'] ?? null),
            'rooms' => TypeCoerce::toRowList($quote['rooms'] ?? []),
        ];
    }
}
