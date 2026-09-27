<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\ViewModels;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Assembles the pieces of the booking sidebar that are identical for every
 * provider, so novoton and sphinx only supply what genuinely differs.
 *
 * Everything here is pure string/array work — no DB, no Registry, no CS-Cart
 * functions — which is what keeps it unit-testable without a store. The
 * "change selection" value is returned as a bare `dispatch?query` string and
 * the template applies `|fn_url`, matching how components/hotel_header.tpl
 * already builds its product link.
 */
final class BookingSidebarFactory
{
    /**
     * The URL behind "Change your selection".
     *
     * Targets the hotel's product page carrying the CURRENT search, because
     * the PDP's booking engine auto-restores a search from those params and
     * re-runs it inline — so the guest lands on the hotel page with their
     * results already rendered and the form pre-filled, instead of an empty
     * form they have to retype. Returns '' when there is no product to go
     * back to, and the template then hides the link rather than emitting a
     * dead one.
     *
     * @param array<string, string|int> $params check_in, check_out, adults,
     *                                          children, rooms, rooms_data, children_ages — empties dropped.
     */
    public static function changeSelectionUrl(int $productId, array $params): string
    {
        if ($productId <= 0) {
            return '';
        }

        $query = ['product_id' => (string) $productId];
        foreach ($params as $key => $value) {
            $value = trim(TypeCoerce::toString($value));
            if ($value !== '' && $value !== '0') {
                $query[$key] = $value;
            }
        }

        return 'products.view?' . http_build_query($query);
    }

    /**
     * Collapse per-room booking data into display lines, one per distinct room
     * type with its quantity ("2x Camera Dubla" rather than the same name
     * printed twice).
     *
     * @param list<array<string, mixed>> $roomsData
     * @return list<array{qty: int, name: string}>
     */
    public static function roomLines(array $roomsData, string $fallbackName = ''): array
    {
        $counts = [];
        foreach ($roomsData as $room) {
            $name = trim(TypeCoerce::toString($room['room_name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $counts[$name] = ($counts[$name] ?? 0) + 1;
        }

        if ($counts === []) {
            $fallbackName = trim($fallbackName);

            return $fallbackName === '' ? [] : [['qty' => 1, 'name' => $fallbackName]];
        }

        $lines = [];
        foreach ($counts as $name => $qty) {
            $lines[] = ['qty' => $qty, 'name' => (string) $name];
        }

        return $lines;
    }

    /**
     * Total adults / children across every booked room.
     *
     * @param list<array<string, mixed>> $roomsData
     * @return array{adults: int, children: int}
     */
    public static function occupancy(array $roomsData): array
    {
        $adults = 0;
        $children = 0;
        foreach ($roomsData as $room) {
            $adults += TypeCoerce::toInt($room['adults'] ?? 0);
            $children += TypeCoerce::toInt($room['children'] ?? 0);
        }

        return ['adults' => $adults, 'children' => $children];
    }

    /**
     * Split a newline-separated formatted-terms blob (what both providers'
     * TermsFormatter produces) into display lines.
     *
     * @return list<string>
     */
    public static function termLines(string $formatted): array
    {
        $lines = [];
        foreach (preg_split('/\r\n|\r|\n/', $formatted) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * The amount to headline in "If you cancel, you'll pay …", or '' when the
     * policy is not a full-price charge.
     *
     * Only a 100% penalty gets the headline treatment: that is the one case a
     * guest must not miss, and showing a partial fee in the same prominent
     * red row would overstate every other policy.
     *
     * @param list<string> $cancelLines
     */
    public static function fullChargeAmount(array $cancelLines, string $formattedTotal): string
    {
        foreach ($cancelLines as $line) {
            if (str_contains($line, '100%')) {
                return $formattedTotal;
            }
        }

        return '';
    }

    /**
     * One payment-terms line as safe HTML with its percentages in bold
     * ("Avans <strong>30%</strong> la confirmare"). The line is escaped
     * FIRST — it is store-written or API text — and only then marked up.
     */
    public static function emphasizePercentages(string $line): string
    {
        $safe = htmlspecialchars($line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return (string) preg_replace('/(\d+(?:[.,]\d+)?\s?%)/u', '<strong>$1</strong>', $safe);
    }

    /**
     * The per-night share of a total already in the display currency;
     * null when the nights are unknown.
     */
    public static function perNight(float $displayTotal, int $nights): ?float
    {
        return $nights > 0 && $displayTotal > 0 ? $displayTotal / $nights : null;
    }
}
