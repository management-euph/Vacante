<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Helpers;

use Tygh\Addons\NovotonHolidays\Api\Refusal;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * How a room_price check sets ?:novoton_hotels.has_room_price.
 *
 * Only an answer decides: a reply with a <Price> is 'Y', a reply without one
 * is 'N'. No answer — a network, API or XML error, a refusal such as a wrong
 * password (Refusal), an open circuit breaker, a resort whose call failed —
 * decides nothing: the hotel keeps its flag and its last_price_check, so the
 * next run (least recently checked first) asks again. Writing 'N' for those
 * made every hotel of a failed batch, or of a resort that did not answer, read
 * "no prices".
 */
final class PriceCheckFlags
{
    /** TRUE: the reply has a <Price>. FALSE: an answer without one. NULL: no answer to judge. */
    public static function hasPrices(mixed $response): ?bool
    {
        if (!$response instanceof \SimpleXMLElement || Refusal::reason($response) !== null) {
            return null;
        }

        return !empty($response->xpath('//Price'));
    }

    /**
     * A resort-based check (one room_price call per resort): which of a
     * country's hotels get 'Y', which 'N', and which keep their flag.
     *
     * A hotel found in any answer has prices. One that was not found has none
     * only when its own resort answered; otherwise nothing is known about it.
     * A resort matches the hotel's city trimmed and case-insensitively.
     *
     * @param array<array-key, array<string, mixed>> $hotels hotel_id => row with 'city'
     * @param array<array-key, mixed> $foundWithPrices hotel_id => anything, from every answer
     * @param list<string> $answeredResorts the resorts whose call answered
     *
     * @return array{with: list<string>, without: list<string>, unanswered: list<string>}
     */
    public static function splitByResort(array $hotels, array $foundWithPrices, array $answeredResorts): array
    {
        $answered = [];
        foreach ($answeredResorts as $resort) {
            $answered[self::resortKey($resort)] = true;
        }

        $split = ['with' => [], 'without' => [], 'unanswered' => []];
        foreach ($hotels as $hotelId => $hotel) {
            if (array_key_exists($hotelId, $foundWithPrices)) {
                $split['with'][] = (string) $hotelId;
            } elseif (isset($answered[self::resortKey(TypeCoerce::toString($hotel['city'] ?? ''))])) {
                $split['without'][] = (string) $hotelId;
            } else {
                $split['unanswered'][] = (string) $hotelId;
            }
        }

        return $split;
    }

    /**
     * The check-in the admin price checks default to: the next 7 July. From
     * August to October the old default was this year's July, already past,
     * so every hotel read "no prices".
     */
    public static function nextPeakCheckIn(\DateTimeImmutable $today): string
    {
        $year = (int) $today->format('Y');

        return ($today->format('m-d') >= '07-07' ? $year + 1 : $year) . '-07-07';
    }

    private static function resortKey(string $name): string
    {
        return mb_strtoupper(trim($name));
    }
}
