<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Tests\Unit\Hotels;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\Eurosite\Repository\HotelListingRepository as L;

/**
 * The hotel list's filters and sorting, as the SQL they become.
 */
final class HotelListingRepositoryTest extends TestCase
{
    public function testDefaultsAreBestAvailabilityFirstAndTheStorePageSize(): void
    {
        $s = (new L(30))->normalize([]);

        self::assertSame(1, $s['page']);
        self::assertSame(30, $s['items_per_page']);
        self::assertSame('availability', $s['sort_by']);
        self::assertSame('asc', $s['sort_order']);
        self::assertSame('desc', $s['sort_order_rev']);
    }

    public function testUnknownValuesFallBackInsteadOfReachingTheSql(): void
    {
        $s = (new L())->normalize([
            'sort_by' => 'name; DROP TABLE x', 'sort_order' => 'sideways', 'availability' => 'XX',
            'images' => 'some', 'product' => 'maybe', 'city' => "RO'01 01", 'page' => -3, 'items_per_page' => 'abc',
        ]);

        self::assertSame('availability', $s['sort_by']);
        self::assertSame('asc', $s['sort_order']);
        self::assertSame('', $s['availability']);
        self::assertSame('', $s['images']);
        self::assertSame('', $s['product']);
        self::assertSame('RO0101', $s['city']);
        self::assertSame(1, $s['page']);
        self::assertSame(50, $s['items_per_page']);
    }

    public function testOrderByReversesEveryPart(): void
    {
        self::assertSame(
            "FIELD(h.availability, 'IM', 'OR', 'ST', 'NONE', '') ASC, h.min_price ASC, h.name ASC",
            L::orderBy('availability', 'asc'),
        );
        self::assertSame(L::IMAGE_SCORE . ' DESC, h.name DESC', L::orderBy('images', 'desc'));
        self::assertSame('(h.min_price = 0) ASC, h.min_price ASC, h.name ASC', L::orderBy('price', 'asc'), 'no price sorts last');
    }

    /** Ascending images = the hotels without any first, to work through them. */
    public function testTheImageScoreCountsPicturesAndTheCover(): void
    {
        self::assertStringContainsString('JSON_LENGTH(c.pictures_json)', L::IMAGE_SCORE);
        self::assertStringContainsString("h.first_image <> ''", L::IMAGE_SCORE);
        self::assertSame(['name', 'destination', 'stars', 'availability', 'images', 'price'], array_keys(L::SORTS));
    }

    public function testFiltersBecomeConditions(): void
    {
        self::assertSame('', L::condition((new L())->normalize([])));
        self::assertSame(" AND h.city_code = 'RO0101' AND h.availability = 'IM'", L::condition(['city' => 'RO0101', 'availability' => 'IM']));
        self::assertSame(" AND h.availability = ''", L::condition(['availability' => 'unchecked']));
        self::assertSame(' AND c.fetched_at IS NOT NULL AND ' . L::IMAGE_SCORE . ' = 0', L::condition(['images' => 'without']));
        self::assertSame(" AND c.fetched_at IS NULL AND h.first_image = ''", L::condition(['images' => 'not_fetched']));
        self::assertSame(' AND (h.product_id IS NULL OR h.product_id = 0)', L::condition(['product' => 'no']));
        self::assertSame(" AND (h.name LIKE '%Parc\\'s%' OR h.product_code LIKE '%Parc\\'s%')", L::condition(['q' => "Parc's"]));
    }

    public function testTheListOnlyEverShowsListedHotels(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Repository/HotelListingRepository.php');

        self::assertSame(3, substr_count($src, "WHERE h.sync_status = 'active'"), 'count, rows and image counts');
    }
}
