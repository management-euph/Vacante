<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Services;

/**
 * The priced offers of a room_price answer, one row per offer.
 *
 * Novoton answers room_price in two shapes:
 *   - FLAT: every offer is a run of sibling elements straight under
 *     <room_price> (agent, idcont, PackageName, Price, IdRoom, Board, …);
 *     the next offer starts at the next <agent>.
 *   - NESTED: one element per offer, holding a single <Price>.
 *
 * The old readers zipped the parallel //Price, //IdRoom and //Board lists by
 * position and never looked at the package. A row missing one of those
 * elements slid every later value onto the wrong price, and the re-price
 * took the cheapest row of ANY package: the "+BEACH" card the customer
 * clicked at 795 came back as another package's 600, and the checkout check
 * then "corrected" it to the first <Price> in the answer. Reading whole rows
 * keeps each price with its own room, board and package.
 *
 * Pure: no API, no CS-Cart.
 *
 * @phpstan-type OfferRow array{price: float, room: string, board: string, package: string, early_booking: float, idcont: string}
 */
final class RoomOfferRows
{
    /** In a flat answer every offer opens with this element. */
    private const string ROW_START = 'agent';

    /**
     * Every offer with a positive price, in document order.
     *
     * @return list<OfferRow>
     */
    public static function fromXml(\SimpleXMLElement $xml): array
    {
        $rows = [];
        foreach ($xml->xpath('//*[Price]') ?: [] as $holder) {
            // One <Price> child: the element IS the offer (nested layout).
            if (count($holder->Price) === 1) {
                $rows[] = self::row(self::nestedFields($holder));
                continue;
            }
            foreach (self::flatRuns($holder) as $fields) {
                $rows[] = self::row($fields);
            }
        }

        return array_values(array_filter($rows, static fn (array $row): bool => $row['price'] > 0));
    }

    /**
     * The offers of one room + board (+ package). An empty filter matches
     * any value; the comparison ignores case.
     *
     * A row without a PackageName belongs to no known package, so it matches
     * any package asked for (as QuoteOfferReader does) — otherwise an answer
     * that never carries package names could never be priced at all.
     *
     * @param list<OfferRow> $rows
     * @return list<OfferRow>
     */
    public static function matching(array $rows, ?string $room, ?string $board, string $package = ''): array
    {
        $room = trim((string) $room);
        $board = trim((string) $board);
        $packageKey = self::packageKey($package);

        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => ($room === '' || strcasecmp(trim($row['room']), $room) === 0)
                && ($board === '' || strcasecmp($row['board'], $board) === 0)
                && ($packageKey === '' || $row['package'] === '' || self::packageKey($row['package']) === $packageKey),
        ));
    }

    /**
     * The lowest-priced offer of that room + board (+ package), or null.
     *
     * @param list<OfferRow> $rows
     * @return OfferRow|null
     */
    public static function cheapest(array $rows, ?string $room, ?string $board, string $package = ''): ?array
    {
        $best = null;
        foreach (self::matching($rows, $room, $board, $package) as $row) {
            if ($best === null || $row['price'] < $best['price']) {
                $best = $row;
            }
        }

        return $best;
    }

    /**
     * A package name as the API spells it: URL-decoded and trimmed.
     */
    public static function packageName(string $package): string
    {
        return trim(rawurldecode($package));
    }

    /**
     * How two package names are compared. A package name travels through
     * search-card links and form posts where "+" can come back as a space
     * (the same loss fn_novoton_holidays_normalize_room_code repairs for
     * rooms), so "+BEACH" and " BEACH" must still be the same package.
     */
    private static function packageKey(string $package): string
    {
        $key = str_replace('+', ' ', self::packageName($package));
        $key = preg_replace('/\s+/u', ' ', $key) ?? $key;

        return mb_strtolower(trim($key));
    }

    /**
     * A nested offer's fields. Room, board and package the element does not
     * carry itself come from its nearest ancestor that does (the
     * <hotel><rooms><IdRoom/><board>…<Price/></board></rooms> shape keeps the
     * room one level up).
     *
     * @return array<string, string>
     */
    private static function nestedFields(\SimpleXMLElement $holder): array
    {
        $fields = [];
        foreach ($holder->children() as $child) {
            $fields[$child->getName()] ??= (string) $child;
        }

        $inherit = ['IdRoom', 'PackageName'];
        if (!isset($fields['IdBoard']) && !isset($fields['Board'])) {
            $inherit[] = 'IdBoard';
            $inherit[] = 'Board';
        }
        foreach ($inherit as $name) {
            if (isset($fields[$name])) {
                continue;
            }
            $found = $holder->xpath('ancestor::*[' . $name . '][1]/' . $name);
            if (is_array($found) && isset($found[0])) {
                $fields[$name] = (string) $found[0];
            }
        }

        return $fields;
    }

    /**
     * Split a flat answer into its offers: a new offer starts at each
     * <agent>, or when an element name comes back inside the current offer
     * (an answer without <agent>). Each offer keeps only its own values, so
     * one missing optional element cannot shift the offers after it.
     *
     * @return list<array<string, string>>
     */
    private static function flatRuns(\SimpleXMLElement $holder): array
    {
        $runs = [];
        $run = [];
        foreach ($holder->children() as $child) {
            $name = $child->getName();
            if ($run !== [] && ($name === self::ROW_START || array_key_exists($name, $run))) {
                $runs[] = $run;
                $run = [];
            }
            $run[$name] = (string) $child;
        }
        if ($run !== []) {
            $runs[] = $run;
        }

        return $runs;
    }

    /**
     * @param array<string, string> $fields element name => text
     * @return OfferRow
     */
    private static function row(array $fields): array
    {
        // The API uses IdBoard or Board depending on the endpoint.
        $board = trim($fields['IdBoard'] ?? '');
        if ($board === '') {
            $board = trim($fields['Board'] ?? '');
        }

        return [
            'price' => (float) trim($fields['Price'] ?? ''),
            'room' => rawurldecode($fields['IdRoom'] ?? ''),
            'board' => $board,
            'package' => self::packageName($fields['PackageName'] ?? ''),
            'early_booking' => (float) trim($fields['early_booking'] ?? ''),
            'idcont' => trim($fields['idcont'] ?? ''),
        ];
    }
}
