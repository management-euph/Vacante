<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Addons\NovotonHolidays\ViewModels\NovotonBookingSidebarBuilder;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Each night's share of a stay's price, from the hotel's priceinfo
 * (PriceInfoCalculation): what an "Over Nights" penalty of N costs, exact,
 * the first N nights at their own season's price instead of N average
 * nights (board 14, 1 Nov 2026: Admiral, 9-16 Nov, 7 nights at 110 € and
 * a 15 € handling fee are 785 €; "2 nights" is 220 €, not 224,29 €).
 *
 *  - Trusted only when priceinfo rebuilds the quoted price within 1 €, as
 *    the admin's price comparison checks (novoton_price_compare): else
 *    none, and the timeline shows N average nights, marked "≈".
 *  - The per-stay fees (handling, company) are no night's: they are left
 *    out of every share. Discounts and supplements follow each night's
 *    price.
 *  - Shares, not amounts: the same list holds in the API's currency and in
 *    the shopper's, with the commission (a percentage) or without it.
 *  - Asked for only when a penalty counts nights (needed()), each room,
 *    board, package and guests once (for(): memoised).
 *
 * Open with Novoton (1 Nov 2026): whether "N nights" are the stay's first
 * N, and whether the handling fee is part of the penalty. Assumed: the
 * first N, without the fee.
 */
final class NightShares
{
    /** The admin's price comparison's tolerance (novoton_price_compare). */
    public const float TOLERANCE = 1.0;

    /** The store's calculation, one for the request (calculate()). */
    private static ?PriceInfoCalculation $calculation = null;

    /** @var array<string, array<string, mixed>> PriceInfoCalculation answers, by question */
    private array $answers = [];

    /**
     * @param \Closure(array<string, mixed>): array<string, mixed> $calculate PriceInfoCalculation::calculate()
     */
    public function __construct(private readonly \Closure $calculate)
    {
    }

    /**
     * The store's: PriceInfoCalculation over the stored priceinfo (each
     * hotel and package read once).
     */
    public static function forStore(): self
    {
        return new self(self::calculate(...));
    }

    /**
     * PriceInfoCalculation, one for the request: its parser reads each
     * hotel's and package's priceinfo once.
     *
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private static function calculate(array $query): array
    {
        self::$calculation ??= new PriceInfoCalculation();

        return self::$calculation->calculate($query);
    }

    /**
     * A room's night shares: [] when priceinfo cannot price it as quoted.
     * Never throws: the timeline then shows the average night.
     *
     * @param array{hotel_id: string, package_name: string, room_id: string, board_id: string, check_in: string, nights: int, adults: int, children_ages: list<int>} $query
     * @param float $quoted the room's price as quoted, commission applied, in the API currency
     * @return list<float>
     */
    public function for(array $query, float $quoted): array
    {
        $key = (string) json_encode($query);
        if (!array_key_exists($key, $this->answers)) {
            try {
                $this->answers[$key] = ($this->calculate)($query + ['booking_date' => date('Y-m-d')]);
            } catch (\Throwable) {
                $this->answers[$key] = [];
            }
        }

        return self::fromCalculation($this->answers[$key], $query['nights'], $quoted);
    }

    /**
     * A booking's night shares, for its cart line (add_to_cart): the cart
     * card's "2 nopți", exact. Each priced room's nights weighed by its
     * price; none unless the booking's terms count nights, or when a room
     * cannot be priced from priceinfo (a board with a supplement, a quote
     * priceinfo does not rebuild).
     *
     * @param list<array{room_id: string, board_id: string, ext_board_id: string, package_name: string, adults: int, childrenAges: list<int>, price: float}> $rooms RoomsQuote's priced rooms
     * @param string $cancellation the booking's <TermsOfCancellation> (room 1's offer)
     * @return list<float>
     */
    public function forBooking(string $hotelId, array $rooms, string $checkIn, int $nights, string $cancellation): array
    {
        [$windows] = NovotonBookingSidebarBuilder::terms(TermsFormatter::parseCancellationTerms($cancellation, $checkIn), []);
        if ($rooms === [] || !self::needed($windows, $nights)) {
            return [];
        }
        $weighed = [];
        foreach ($rooms as $room) {
            $weighed[] = [
                'price' => $room['price'],
                'shares' => $room['ext_board_id'] !== '' ? [] : $this->for([
                    'hotel_id' => $hotelId,
                    'package_name' => $room['package_name'],
                    'room_id' => $room['room_id'],
                    'board_id' => $room['board_id'],
                    'check_in' => $checkIn,
                    'nights' => $nights,
                    'adults' => $room['adults'],
                    'children_ages' => $room['childrenAges'],
                ], $room['price']),
            ];
        }

        return self::combined($weighed);
    }

    /**
     * @param array<string, mixed> $calc PriceInfoCalculation::calculate()
     * @param float $quoted the stay's price as quoted, commission applied, in the API currency
     * @return list<float>
     */
    public static function fromCalculation(array $calc, int $nights, float $quoted): array
    {
        if (empty($calc['success']) || $nights <= 0 || $quoted <= 0
            || abs(TypeCoerce::toFloat($calc['price'] ?? 0) - $quoted) >= self::TOLERANCE) {
            return [];
        }
        $breakdown = TypeCoerce::toStringMap($calc['breakdown'] ?? null);
        $byNight = TypeCoerce::toList($breakdown['base_per_night'] ?? null);
        if (count($byNight) !== $nights) {
            return [];
        }
        $prices = [];
        foreach ($byNight as $night) {
            $price = is_array($night) && is_numeric($night['price'] ?? null) ? (float) $night['price'] : -1.0;
            if ($price < 0) {
                return [];
            }
            $prices[] = $price;
        }
        $sum = array_sum($prices);
        $net = TypeCoerce::toFloat($calc['price_without_commission'] ?? 0);
        $fees = TypeCoerce::toStringMap($breakdown['fees'] ?? null);
        $perStay = TypeCoerce::toFloat($fees['handling_fee'] ?? 0) + TypeCoerce::toFloat($fees['company_fee'] ?? 0);
        if ($sum <= 0 || $net <= 0 || $perStay < 0 || $perStay >= $net) {
            return [];
        }
        $nightsPart = ($net - $perStay) / $net;

        return array_map(static fn (float $price): float => $price / $sum * $nightsPart, $prices);
    }

    /**
     * A penalty counts nights, fewer than the stay's: only then does each
     * night's price matter (all the nights are the whole price).
     *
     * @param list<array<string, mixed>> $windows NovotonBookingSidebarBuilder::terms()
     */
    public static function needed(array $windows, int $nights): bool
    {
        foreach ($windows as $window) {
            $n = $window['nights'] ?? null;
            if (is_int($n) && $n > 0 && $n < $nights) {
                return true;
            }
        }

        return false;
    }

    /**
     * Several rooms booked together, one cart line: each room's nights
     * weighed by its price. [] when a room has none.
     *
     * @param list<array{price: float, shares: list<float>}> $rooms
     * @return list<float>
     */
    public static function combined(array $rooms): array
    {
        $total = 0.0;
        $count = null;
        foreach ($rooms as $room) {
            $count ??= count($room['shares']);
            if ($room['shares'] === [] || $room['price'] <= 0 || count($room['shares']) !== $count) {
                return [];
            }
            $total += $room['price'];
        }
        if ($total <= 0) {
            return [];
        }
        $out = array_fill(0, (int) $count, 0.0);
        foreach ($rooms as $room) {
            foreach ($room['shares'] as $i => $share) {
                $out[$i] += $share * $room['price'] / $total;
            }
        }

        return array_values($out);
    }
}
