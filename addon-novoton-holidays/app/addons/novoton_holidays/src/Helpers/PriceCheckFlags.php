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
    /** How much of an unparsable reply a "no answer" line quotes. */
    private const int REPLY_EXCERPT = 80;

    /**
     * TRUE: the reply has a <Price>. FALSE: an answer without one. NULL: no answer to judge.
     *
     * $unparsedBody is the reply's text when it did not parse as XML (the
     * client then returns false). Novoton answers a room_price for a hotel
     * with nothing priced on those dates with an empty document: the XML
     * declaration and a line break, no element. libxml rejects that with
     * "Line 2: Start tag expected, '<' not found", so those hotels read "no
     * answer" and kept a flag Novoton had just contradicted. An empty
     * document is an answer without prices (isEmptyAnswer); any other text
     * that does not parse is still no answer.
     */
    public static function hasPrices(mixed $response, string $unparsedBody = ''): ?bool
    {
        if (!$response instanceof \SimpleXMLElement) {
            return self::isEmptyAnswer($unparsedBody) ? false : null;
        }
        if (Refusal::reason($response) !== null) {
            return null;
        }

        return !empty($response->xpath('//Price'));
    }

    /**
     * The hotels a resort-level room_price answer offers, or NULL when it is
     * no answer: a reply that did not parse, a refusal, or an <Error> and no
     * offer. An empty document is Novoton's "no prices": an answer with no
     * hotel. A hotel counts once one of its rows has a price above zero, the
     * offers a customer's search would show.
     *
     * @return list<string>|null
     */
    public static function resortOffers(mixed $response, string $unparsedBody = ''): ?array
    {
        if (!$response instanceof \SimpleXMLElement) {
            return self::isEmptyAnswer($unparsedBody) ? [] : null;
        }
        if (Refusal::reason($response) !== null) {
            return null;
        }

        $hotels = [];
        foreach ($response->xpath('//*[IdHotel]') ?: [] as $row) {
            $hotelId = trim((string) $row->IdHotel);
            if ($hotelId === '' || !ctype_digit($hotelId) || isset($hotels[$hotelId])) {
                continue;
            }
            foreach ($row->xpath('.//Price') ?: [] as $price) {
                if ((float) trim((string) $price) > 0) {
                    $hotels[$hotelId] = true;
                    break;
                }
            }
        }
        if ($hotels === [] && ($response->xpath('//Error | //error') ?: []) !== []) {
            return null;
        }

        return array_map('strval', array_keys($hotels));
    }

    /**
     * Whether a reply is an empty document: an XML declaration and/or
     * whitespace (a UTF-8 BOM allowed) and nothing else. A zero-byte body is
     * not one: that is what a failed transfer looks like.
     */
    public static function isEmptyAnswer(string $body): bool
    {
        if ($body === '') {
            return false;
        }
        $rest = preg_replace('/^\xEF\xBB\xBF/', '', $body) ?? $body;
        $rest = preg_replace('/^\s*<\?xml\b[^>]*\?>/i', '', $rest) ?? $rest;

        return trim($rest) === '';
    }

    /** What an empty document stands for in a resort check: an answer with no hotel and no <Price>. */
    public static function emptyAnswer(): \SimpleXMLElement
    {
        return new \SimpleXMLElement('<room_prices/>');
    }

    /**
     * Why a check got no answer, for its log line: Novoton's refusal, else
     * the client's error, with the start of a reply that did not parse so
     * the log shows what Novoton actually sent.
     */
    public static function noAnswerReason(mixed $response, string $lastError, string $unparsedBody): string
    {
        if ($response instanceof \SimpleXMLElement) {
            return 'Novoton: ' . (Refusal::reason($response) ?? 'an answer that could not be read');
        }

        $reason = $lastError !== '' ? $lastError : 'invalid response';
        if ($unparsedBody === '') {
            return $reason . ' (empty reply)';
        }
        $excerpt = addcslashes(mb_strcut($unparsedBody, 0, self::REPLY_EXCERPT, 'UTF-8'), "\0..\37");

        return $reason . ' — reply: "' . $excerpt . (strlen($unparsedBody) > self::REPLY_EXCERPT ? '…' : '') . '"';
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
