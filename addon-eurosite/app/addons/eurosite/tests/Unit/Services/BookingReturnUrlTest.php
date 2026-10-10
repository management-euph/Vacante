<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\Eurosite\Services\BookingReturnUrl;

/**
 * "This hotel cannot be booked" (and every other refusal) sent the guest to
 * eurosite_booking.search with no hotel: an empty page. Now it is the
 * hotel's product page with the stay, where the booking engine re-runs the
 * search inline (it reads check_in / check_out / rooms_data from the URL).
 */
final class BookingReturnUrlTest extends TestCase
{
    /** @return array<string, string> */
    private static function query(string $url): array
    {
        self::assertStringStartsWith('products.view?', $url);
        parse_str((string) substr($url, strlen('products.view?')), $query);

        /** @var array<string, string> $query */
        return $query;
    }

    public function testTheProductPageCarriesTheWholeStay(): void
    {
        $query = self::query(BookingReturnUrl::forProduct(41, [
            ['adults' => 2, 'children_ages' => [5]],
            ['adults' => 1, 'children_ages' => []],
        ], '2026-10-05', '2026-10-11'));

        self::assertSame([
            'product_id'    => '41',
            'check_in'      => '2026-10-05',
            'check_out'     => '2026-10-11',
            'adults'        => '3',
            'children'      => '1',
            'children_ages' => '5',
            'rooms'         => '2',
            'rooms_data'    => '[{"adults":2,"children":1,"childrenAges":[5]},{"adults":1,"children":0,"childrenAges":[]}]',
        ], $query);
    }

    public function testTheSnapshotFillsItAndCorrectedRoomsWin(): void
    {
        $snapshot = [
            'check_in'        => '2026-10-05',
            'check_out'       => '2026-10-11',
            'rooms_occupancy' => [['adults' => 2, 'children_ages' => [5]]],
        ];

        self::assertSame('5', self::query(BookingReturnUrl::forSnapshot($snapshot, 41))['children_ages']);

        // The child is 6 at check-in: the product page searches again for 6.
        $corrected = self::query(BookingReturnUrl::forSnapshot($snapshot, 41, [['adults' => 2, 'children_ages' => [6]]]));
        self::assertSame('6', $corrected['children_ages']);
        self::assertSame('[{"adults":2,"children":1,"childrenAges":[6]}]', $corrected['rooms_data']);
    }

    public function testWithoutAStayItIsJustTheProductPage(): void
    {
        // The engine then restores the guest's last search by itself.
        self::assertSame('products.view?product_id=41', BookingReturnUrl::forProduct(41));
        self::assertSame(['product_id' => '41'], self::query(BookingReturnUrl::forProduct(41, [], '2026-10-05', '')));
    }

    public function testNoProductMeansTheHomePage(): void
    {
        self::assertSame('index.index', BookingReturnUrl::forProduct(0, [['adults' => 2, 'children_ages' => []]], '2026-10-05', '2026-10-11'));
        self::assertSame('index.index', BookingReturnUrl::forSnapshot(['check_in' => '2026-10-05'], 0));
    }
}
