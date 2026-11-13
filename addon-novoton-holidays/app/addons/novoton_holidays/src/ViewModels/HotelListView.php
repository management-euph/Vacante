<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\ViewModels;

use Tygh\Addons\NovotonHolidays\Repository\HotelListingRepository;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Novoton → Hotels (the sync monitor): the listing's rows as the template
 * prints them. Pre-formatted here because Smarty 5 modifiers throw inside
 * the admin capture, and so the rules are testable without a store.
 */
final class HotelListView
{
    /** Filters a list URL carries (page and sort apart). */
    private const array FILTERS = ['q', 'country', 'city', 'stars', 'sync_status', 'not_synced_days', 'items_per_page'];

    /**
     * @param list<array<string, mixed>> $rows HotelListingRepository::getListing()
     * @param string $now Y-m-d H:i:s
     *
     * @return list<array<string, mixed>>
     */
    public static function rows(array $rows, string $now): array
    {
        $out = [];
        foreach ($rows as $row) {
            $synced = TypeCoerce::toString($row['hotelinfo_synced_at'] ?? '');
            $tried = TypeCoerce::toString($row['hotelinfo_tried_at'] ?? '');
            $due = TypeCoerce::toString($row['hotelinfo_due_at'] ?? '');
            $status = TypeCoerce::toString($row['sync_status'] ?? '');
            $productId = TypeCoerce::toInt($row['live_product_id'] ?? 0);
            $stars = TypeCoerce::toInt($row['star_rating'] ?? 0);

            $out[] = [
                'id' => TypeCoerce::toString($row['hotel_id'] ?? ''),
                'id_url' => rawurlencode(TypeCoerce::toString($row['hotel_id'] ?? '')),
                'name' => TypeCoerce::toString($row['hotel_name'] ?? ''),
                'city' => TypeCoerce::toString($row['city'] ?? ''),
                'area' => self::area(TypeCoerce::toString($row['region'] ?? ''), TypeCoerce::toString($row['country'] ?? ''), TypeCoerce::toString($row['city'] ?? '')),
                'stars' => $stars >= 1 && $stars <= 5 ? $stars : 0,
                'stars_text' => $stars >= 1 && $stars <= 5 ? str_repeat('★', $stars) : '',
                'type' => TypeCoerce::toString($row['hotel_type'] ?? ''),
                'status' => in_array($status, HotelListingRepository::STATUSES, true) ? $status : 'pending',
                'synced_at' => self::when($synced),
                'synced_days' => self::daysAgo($synced, $now),
                // Shown only when the last request got no answer.
                'tried_at' => $status === 'error' ? self::when($tried) : '',
                // Queued for a re-sync the cron has not run yet.
                'queued' => $due !== '' && ($synced === '' || $due > $synced),
                'product_id' => $productId,
                'product_status' => TypeCoerce::toString($row['product_status'] ?? ''),
                // A product id is stored but the product is gone.
                'product_deleted' => $productId === 0 && TypeCoerce::toInt($row['product_id'] ?? 0) > 0,
                'main_pair' => [],
            ];
        }

        return $out;
    }

    /** "Region, Country", without repeating the city or an empty part. */
    public static function area(string $region, string $country, string $city): string
    {
        $parts = [];
        foreach ([$region, $country] as $part) {
            $part = trim($part);
            if ($part !== '' && strcasecmp($part, trim($city)) !== 0 && !in_array($part, $parts, true)) {
                $parts[] = $part;
            }
        }

        return implode(', ', $parts);
    }

    /** A stored date as the page shows it; '' when there is none. */
    public static function when(string $datetime): string
    {
        $time = $datetime !== '' ? strtotime($datetime) : false;

        return $time !== false && $time > 0 ? date('d.m.Y H:i', $time) : '';
    }

    /** Whole days since $datetime; null when there is none. */
    public static function daysAgo(string $datetime, string $now): ?int
    {
        $time = $datetime !== '' ? strtotime($datetime) : false;
        $nowTime = strtotime($now);
        if ($time === false || $time <= 0 || $nowTime === false) {
            return null;
        }

        return max(0, intdiv($nowTime - $time, 86400));
    }

    /**
     * The query of a list URL: the filters in force ('' ones left out), so a
     * sort link, a row action and a bulk action come back to the same list.
     *
     * @param array<string, mixed> $search
     * @param bool $withSort also sort_by / sort_order / page (a redirect back to the page)
     */
    public static function query(array $search, bool $withSort = false): string
    {
        $keys = $withSort ? array_merge(self::FILTERS, ['sort_by', 'sort_order', 'page']) : self::FILTERS;
        $params = [];
        foreach ($keys as $key) {
            $value = TypeCoerce::toString($search[$key] ?? '');
            if ($value !== '' && $value !== '0') {
                $params[$key] = $value;
            }
        }

        return http_build_query($params);
    }
}
