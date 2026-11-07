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
 * those room codes for the offer, never the form's copies. So are its
 * payment and cancellation terms, which the cart line keeps.
 *
 * The rooms on the circuit's page (CircuitRoomOffers) keep each offer they
 * show, and also what the booking form shows of it (title, flight,
 * services…) in the cache table, by offer: the session stays small, and
 * that copy is only read for an offer this session has kept, so "Rezervă"
 * opens the form on that offer without quoting again (quote()).
 */
final class CircuitQuoteStore
{
    public const string SESSION_KEY = 'sphinx_circuit_quotes';

    /** A quote older than this is not trusted: the guest gets a fresh one. */
    public const int TTL = 3600;

    /** At most this many quotes per session (oldest dropped first). */
    private const int MAX_QUOTES = 20;

    /** What the booking form shows of an offer, in the cache table (bump on a shape change). */
    private const string DETAILS_PREFIX = 'circuit_quote:v1:';

    /** The quote's fields the booking form shows (the rest stays out of the cache). */
    private const array DETAIL_FIELDS = [
        'offer_id', 'circuit_id', 'title', 'departure_date', 'transport_type', 'flight', 'duration',
        'departure', 'image', 'rooms', 'meal_type_category_id', 'meal_type_name', 'additional_services',
        'pricing', 'payment_terms', 'cancellation_fees',
    ];

    /**
     * @param SessionAccessor $session where the trusted part is kept
     * @param CacheService|null $cache where what the form shows is kept (null: the add-on's)
     */
    public function __construct(
        private readonly SessionAccessor $session = new SessionAccessor(),
        private readonly ?CacheService $cache = null,
    ) {
    }

    /**
     * Keep every offer of one circuits/quote answer: the price and rooms in
     * the session (remember()), what the booking form shows in the cache.
     *
     * @param list<array<string, mixed>> $quotes the quotes, as Sphinx gave them
     * @param int $circuitId the circuit quoted
     * @param string $departureDate Y-m-d
     * @param int $now the current time
     */
    public function rememberQuotes(array $quotes, int $circuitId, string $departureDate, int $now): void
    {
        $details = [];
        foreach ($quotes as $quote) {
            $offerId = TypeCoerce::toString($quote['offer_id'] ?? '');
            $pricing = TypeCoerce::toStringMap($quote['pricing'] ?? null);
            $sellingPrice = TypeCoerce::toFloat($pricing['selling_price'] ?? 0);
            if ($offerId === '' || $sellingPrice <= 0) {
                continue;
            }
            $this->remember(
                $offerId,
                $circuitId,
                $sellingPrice,
                TypeCoerce::toString($pricing['currency'] ?? ''),
                $departureDate,
                $now,
                TypeCoerce::toString($quote['meal_type_name'] ?? ''),
                TypeCoerce::toString(TypeCoerce::toStringMap($quote['departure'] ?? null)['name'] ?? ''),
                $pricing,
                TypeCoerce::toRowList($quote['rooms'] ?? []),
                TypeCoerce::toStringMap($quote['payment_terms'] ?? null),
                TypeCoerce::toStringMap($quote['cancellation_fees'] ?? null),
            );
            $encoded = json_encode(array_intersect_key($quote, array_flip(self::DETAIL_FIELDS)), JSON_UNESCAPED_UNICODE);
            if ($encoded !== false) {
                $details[self::DETAILS_PREFIX . $offerId] = $encoded;
            }
        }
        if ($details !== []) {
            ($this->cache ?? Container::getCacheService())->setMany($details, self::TTL);
        }
    }

    /**
     * The offer as Sphinx quoted it (rememberQuotes()), when this session
     * keeps it for this circuit and it is still fresh; null otherwise (the
     * booking form then quotes again). Its departure_date is the date it
     * was quoted for, as this session keeps it.
     *
     * @param string $offerId the offer chosen
     * @param int $circuitId the circuit it must be a quote of
     * @param int $now the current time
     *
     * @return array<string, mixed>|null
     */
    public function quote(string $offerId, int $circuitId, int $now): ?array
    {
        $kept = $this->get($offerId, $circuitId, $now);
        if ($kept === null) {
            return null;
        }
        $details = ($this->cache ?? Container::getCacheService())->get(self::DETAILS_PREFIX . $offerId);
        if ($details === null || TypeCoerce::toString($details['offer_id'] ?? '') !== $offerId) {
            return null;
        }

        return ['departure_date' => $kept['departure_date']] + $details;
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
     * @param array<string, mixed> $paymentTerms the quote's payment_terms, as Sphinx sent them
     * @param array<string, mixed> $cancellationFees the quote's cancellation_fees, as Sphinx sent them
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
        array $paymentTerms = [],
        array $cancellationFees = [],
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
            // The cart line keeps them: its card, the checkout's and the
            // order's show the cancellation and payment timeline.
            'payment_terms' => $paymentTerms,
            'cancellation_fees' => $cancellationFees,
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
     * @return array{circuit_id: int, selling_price: float, currency: string, departure_date: string, meal_type: string, departure_name: string, pricing: array<string, mixed>, rooms: list<array<string, mixed>>, payment_terms: array<string, mixed>, cancellation_fees: array<string, mixed>}|null
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
            'payment_terms' => TypeCoerce::toStringMap($quote['payment_terms'] ?? null),
            'cancellation_fees' => TypeCoerce::toStringMap($quote['cancellation_fees'] ?? null),
        ];
    }
}
