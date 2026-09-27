<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Services;

/**
 * The offer behind a room_price quote: the early-booking % and extras
 * promotion ("7 = 6") of the row that set the price, and the standard price
 * of the same room + board without extras.
 *
 * The booking page's re-price picks the CHEAPEST matching row
 * (fn_novoton_min_price_from_xml) — for a "7 = 6" room that is the promo row,
 * and the standard row beside it is the price the search card strikes
 * through (SearchService::deduplicateResults merges the two there).
 *
 * Reads each row as the element that holds its <Price>, NOT through the
 * parallel //early_booking and //extras lists: those are optional, so a row
 * without them would shift every later row's values onto the wrong price.
 * Pure: no API, no CS-Cart.
 */
final class QuoteOfferReader
{
    /**
     * @param float $chargedRaw the matched row's API price (before commission)
     * @param string $packageName the booked package: the search card pairs a
     *                            promo row with the standard row of the SAME
     *                            room + board + package; '' = any package
     * @return array{early_booking: float, extras: string, standard: float}|null
     *         null when no row of that room + board carries that price;
     *         standard = 0.0 when every matching row has extras
     */
    public static function fromXml(\SimpleXMLElement $xml, string $roomId, string $boardId, float $chargedRaw, string $packageName = ''): ?array
    {
        $charged = null;
        $standard = 0.0;
        foreach ($xml->xpath('//Price') ?: [] as $priceEl) {
            $row = ($priceEl->xpath('..') ?: [])[0] ?? null;
            // Flat lists (several <Price> siblings) cannot be told apart.
            if (!$row instanceof \SimpleXMLElement || count($row->Price) !== 1) {
                return null;
            }
            $price = (float) (string) $priceEl;
            $room = rawurldecode(self::child($row, 'IdRoom'));
            $board = self::child($row, 'IdBoard');
            if ($board === '') {
                $board = self::child($row, 'Board');
            }
            if ($price <= 0
                || ($roomId !== '' && strcasecmp($room, $roomId) !== 0)
                || ($boardId !== '' && strcasecmp($board, $boardId) !== 0)
                || !self::samePackage($row, $packageName)) {
                continue;
            }
            $extras = trim(self::child($row, 'extras'));
            if ($extras === '' && ($standard === 0.0 || $price < $standard)) {
                $standard = $price;
            }
            if ($charged === null && abs($price - $chargedRaw) < 0.005) {
                $charged = [
                    'early_booking' => (float) self::child($row, 'early_booking'),
                    'extras' => $extras,
                ];
            }
        }

        return $charged === null ? null : $charged + ['standard' => $standard];
    }

    /** A row without a PackageName, or no package asked for, matches any. */
    private static function samePackage(\SimpleXMLElement $row, string $packageName): bool
    {
        $rowPackage = trim(rawurldecode(self::child($row, 'PackageName')));
        $packageName = trim(rawurldecode($packageName));

        return $rowPackage === '' || $packageName === '' || strcasecmp($rowPackage, $packageName) === 0;
    }

    /** A child's text; '' when the row has no such element (isset, not ??). */
    private static function child(\SimpleXMLElement $row, string $name): string
    {
        return isset($row->{$name}) ? (string) $row->{$name} : '';
    }
}
