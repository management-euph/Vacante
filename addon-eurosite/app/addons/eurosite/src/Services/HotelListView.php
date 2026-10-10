<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Services;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Everything the Eurosite → Hotels page shows, pre-formatted.
 *
 * Smarty 5 modifiers throw inside the admin mainbox capture, so the page
 * gets finished strings and flags: each row's availability pill, price,
 * image state and what would happen if it were ticked; the filter chips
 * with their counts and links; the notice after "Create products".
 * No database here — the controller hands the numbers in.
 */
final class HotelListView
{
    /** The filters a link keeps (the sort is added per link). */
    private const FILTER_KEYS = ['city', 'availability', 'images', 'product', 'q', 'items_per_page'];

    /**
     * @param list<array<string, mixed>> $rows HotelListingRepository rows
     *
     * @return list<array<string, mixed>>
     */
    public static function rows(array $rows, bool $allowWithoutImages): array
    {
        $out = [];
        foreach ($rows as $row) {
            $image = EurositeProductFactory::imageState($row);
            $reason = EurositeProductFactory::skipReason($row, $allowWithoutImages);
            // Details never fetched: "Create products" fetches them first, so
            // "no images" is not known yet.
            $pending = $reason === 'no_images' && $image['state'] === 'not_fetched';
            $availability = TypeCoerce::toString($row['availability'] ?? '');
            $checkIn = TypeCoerce::toString($row['availability_check_in'] ?? '');
            $productId = TypeCoerce::toInt($row['product_id'] ?? 0);
            $productStatus = $productId > 0 ? TypeCoerce::toString($row['product_status'] ?? '') : '';
            $tourop = TypeCoerce::toString($row['tourop_code'] ?? '');
            $code = TypeCoerce::toString($row['product_code'] ?? '');

            $out[] = [
                'key' => $tourop . ':' . $code,
                'tourop' => $tourop,
                'code' => $code,
                'name' => EurositeProductFactory::displayName(TypeCoerce::toString($row['name'] ?? '')),
                'city_code' => TypeCoerce::toString($row['city_code'] ?? ''),
                'city_name' => TypeCoerce::toString($row['city_name'] ?? ''),
                'country_code' => TypeCoerce::toString($row['country_code'] ?? ''),
                'country_name' => TypeCoerce::toString($row['country_name'] ?? ''),
                'stars' => TypeCoerce::toInt($row['category'] ?? 0),
                'availability' => $availability,
                'availability_key' => self::availabilityKey($availability),
                'check_in' => $checkIn !== '' && strtotime($checkIn) !== false ? date('j M', (int) strtotime($checkIn)) : '',
                'is_season' => TypeCoerce::toString($row['availability_window'] ?? '') === 'season',
                'price' => self::money(TypeCoerce::toFloat($row['min_price'] ?? 0), TypeCoerce::toString($row['price_currency'] ?? '')),
                'gross' => self::money(TypeCoerce::toFloat($row['min_gross'] ?? 0), TypeCoerce::toString($row['price_currency'] ?? '')),
                'image_state' => $image['state'],
                'image_count' => $image['count'],
                'thumb' => EurositeProductFactory::pictures($row)[0] ?? '',
                'product_id' => $productId,
                'product_status' => $productStatus,
                'product_code' => $productId > 0 ? EurositeProductFactory::productCode($tourop, $code) : '',
                'gate_hidden' => TypeCoerce::toString($row['gate_hidden'] ?? 'N') === 'Y',
                'skip_reason' => $pending ? '' : $reason,
                'eligible' => $reason === '' || $pending,
                'details_pending' => $pending,
            ];
        }

        return $out;
    }

    /** '' / IM / OR / ST / NONE → a label-key suffix (keys are lowercase). */
    public static function availabilityKey(string $code): string
    {
        return match ($code) {
            'IM' => 'im',
            'OR' => 'or',
            'ST' => 'st',
            'NONE' => 'none',
            default => 'unchecked',
        };
    }

    public static function money(float $amount, string $currency): string
    {
        if ($amount <= 0) {
            return '';
        }
        $symbol = match (strtoupper($currency)) {
            'EUR', '' => '€',
            'USD' => '$',
            'GBP' => '£',
            default => strtoupper($currency),
        };

        return number_format($amount, 0, '.', ',') . ' ' . $symbol;
    }

    /** @return array<string, mixed> */
    public static function emptySummary(): array
    {
        return self::summary(0, [], ['with' => 0, 'without' => 0, 'not_fetched' => 0], 0, 0, ['hotels' => 0, 'destinations' => 0], [], []);
    }

    /**
     * @param array<string, int> $availability countByAvailability()
     * @param array{with: int, without: int, not_fetched: int} $images
     * @param array{hotels: int, destinations: int} $notWhitelisted
     * @param list<array{city_code: string, country_code: string, name: string, hotels: int}> $destinations
     * @param array<string, array<string, mixed>> $lastRuns sync_type => last sync-log row
     *
     * @return array<string, mixed>
     */
    public static function summary(
        int $listed,
        array $availability,
        array $images,
        int $products,
        int $gateHidden,
        array $notWhitelisted,
        array $destinations,
        array $lastRuns,
    ): array {
        $checked = $listed - ($availability[''] ?? 0);

        return [
            'listed' => $listed,
            'destinations' => count($destinations),
            'checked' => max(0, $checked),
            'immediate' => $availability['IM'] ?? 0,
            'on_request' => $availability['OR'] ?? 0,
            'stop_sale' => $availability['ST'] ?? 0,
            'no_offer' => $availability['NONE'] ?? 0,
            'unchecked' => $availability[''] ?? 0,
            'with_images' => $images['with'],
            'without_images' => $images['without'],
            'not_fetched' => $images['not_fetched'],
            'products' => $products,
            'gate_hidden' => $gateHidden,
            'hidden_hotels' => $notWhitelisted['hotels'],
            'hidden_destinations' => $notWhitelisted['destinations'],
            'destination_list' => array_map(static fn (array $d): array => [
                'code' => $d['city_code'],
                'label' => ($d['name'] !== '' ? $d['name'] : $d['city_code']) . ' (' . $d['hotels'] . ')',
            ], $destinations),
            'last_hotels_sync' => self::when($lastRuns['hotels'] ?? []),
            'last_availability' => self::when($lastRuns['availability'] ?? []),
        ];
    }

    /**
     * The filter chips: one group per filter, each chip with its count,
     * link and whether it is the active one. Clicking a chip keeps the
     * other filters and the sort, and goes back to page 1.
     *
     * @param array<string, mixed> $search
     * @param array<string, mixed> $summary
     *
     * @return array<string, list<array{value: string, label_key: string, count: int|null, url: string, active: bool}>>
     */
    public static function chips(array $search, array $summary): array
    {
        $groups = [
            'availability' => [
                ['', 'eurosite.hotels_all', TypeCoerce::toInt($summary['listed'] ?? 0)],
                ['IM', 'eurosite.avail_im', TypeCoerce::toInt($summary['immediate'] ?? 0)],
                ['OR', 'eurosite.avail_or', TypeCoerce::toInt($summary['on_request'] ?? 0)],
                ['ST', 'eurosite.avail_st', TypeCoerce::toInt($summary['stop_sale'] ?? 0)],
                ['NONE', 'eurosite.avail_none', TypeCoerce::toInt($summary['no_offer'] ?? 0)],
                ['unchecked', 'eurosite.avail_unchecked', TypeCoerce::toInt($summary['unchecked'] ?? 0)],
            ],
            'images' => [
                ['', 'eurosite.hotels_all', null],
                ['with', 'eurosite.images_with', TypeCoerce::toInt($summary['with_images'] ?? 0)],
                ['without', 'eurosite.images_without', TypeCoerce::toInt($summary['without_images'] ?? 0)],
                ['not_fetched', 'eurosite.images_not_fetched', TypeCoerce::toInt($summary['not_fetched'] ?? 0)],
            ],
            'product' => [
                ['', 'eurosite.hotels_all', null],
                ['yes', 'eurosite.product_yes', TypeCoerce::toInt($summary['products'] ?? 0)],
                ['no', 'eurosite.product_no', null],
            ],
        ];

        $out = [];
        foreach ($groups as $key => $chips) {
            foreach ($chips as [$value, $labelKey, $count]) {
                if ($count === 0 && $value !== '' && $value !== TypeCoerce::toString($search[$key] ?? '')) {
                    continue; // an empty chip is noise, unless it is the active filter
                }
                $out[$key][] = [
                    'value' => $value,
                    'label_key' => $labelKey,
                    'count' => $count,
                    'url' => 'eurosite.hotels?' . self::query(array_merge($search, [$key => $value]), true),
                    'active' => TypeCoerce::toString($search[$key] ?? '') === $value,
                ];
            }
        }

        return $out;
    }

    /**
     * The base for the sortable column links: filters kept, sort left to the link.
     *
     * @param array<string, mixed> $search
     */
    public static function listUrl(array $search): string
    {
        return 'eurosite.hotels?' . self::query($search, false);
    }

    /**
     * The current filters and sort, for the POST forms to come back to.
     *
     * @param array<string, mixed> $search
     */
    public static function filterQuery(array $search): string
    {
        return self::query($search, true, true);
    }

    /**
     * Where a POST action returns to. Only known filter keys survive, so
     * the hidden field cannot send the admin anywhere but this list.
     */
    public static function returnUrl(string $query): string
    {
        parse_str($query, $params);
        $clean = [];
        foreach ([...self::FILTER_KEYS, 'sort_by', 'sort_order', 'page'] as $key) {
            $value = trim(TypeCoerce::toString($params[$key] ?? ''));
            if ($value !== '' && preg_match('/^[\p{L}\p{N} _.\'-]{1,64}$/u', $value) === 1) {
                $clean[$key] = $value;
            }
        }

        return 'eurosite.hotels' . ($clean === [] ? '' : '?' . http_build_query($clean));
    }

    /**
     * The notice after "Create products".
     *
     * @param array{added: int, linked: int, would_create: int, failed: int, skipped: array<string, int>, details_fetched: int, errors: list<string>} $r
     *
     * @return array{0: string, 1: string} [N|W|E, message]
     */
    public static function createdNotice(array $r): array
    {
        $t = static fn (string $key, array $params): string => TypeCoerce::toString(__($key, $params));
        $parts = [$t('eurosite.hotels_created_n', ['[n]' => $r['added'], '[default]' => '[n] products created'])];
        if ($r['linked'] > 0) {
            $parts[] = $t('eurosite.hotels_linked_n', ['[n]' => $r['linked'], '[default]' => '[n] linked to existing products']);
        }
        if ($r['failed'] > 0) {
            $parts[] = $t('eurosite.hotels_failed_n', ['[n]' => $r['failed'], '[default]' => '[n] failed (see the log)']);
        }
        $skipped = [];
        foreach ($r['skipped'] as $reason => $n) {
            $skipped[] = $n . ' × ' . $t('eurosite.skip_' . $reason, ['[default]' => $reason]);
        }
        $message = implode(', ', $parts) . '.';
        if ($skipped !== []) {
            $message .= ' ' . $t('eurosite.hotels_skipped', ['[default]' => 'Skipped:']) . ' ' . implode(', ', $skipped) . '.';
        }
        $type = match (true) {
            $r['failed'] > 0 && $r['added'] + $r['linked'] === 0 => 'E',
            $r['added'] + $r['linked'] === 0 => 'W',
            default => 'N',
        };

        return [$type, $message];
    }

    /**
     * @param array<string, mixed> $search
     */
    private static function query(array $search, bool $withSort, bool $withPage = false): string
    {
        $keys = self::FILTER_KEYS;
        if ($withSort) {
            $keys[] = 'sort_by';
            $keys[] = 'sort_order';
        }
        if ($withPage) {
            $keys[] = 'page';
        }
        $params = [];
        foreach ($keys as $key) {
            $value = TypeCoerce::toString($search[$key] ?? '');
            if ($value !== '' && !($key === 'page' && $value === '1')) {
                $params[$key] = $value;
            }
        }

        return http_build_query($params);
    }

    /** @param array<string, mixed> $row */
    private static function when(array $row): string
    {
        $at = TypeCoerce::toString($row['started_at'] ?? '');
        $ts = $at !== '' ? strtotime($at) : false;

        return $ts === false ? '' : date('j M Y, H:i', $ts);
    }
}
