<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\Eurosite\Services\HotelListView;

/**
 * What the Eurosite → Hotels page shows for each hotel, the filter chips,
 * and the notice after "Create products".
 */
final class HotelListViewTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function row(array $over = []): array
    {
        return $over + [
            'tourop_code' => 'LA', 'product_code' => 'RO0363', 'name' => 'Parc CM', 'city_code' => 'RO0101',
            'city_name' => 'Mamaia', 'country_code' => 'RO', 'country_name' => 'Romania', 'category' => 4,
            'availability' => 'IM', 'availability_check_in' => '2026-10-08', 'availability_window' => 'near',
            'min_price' => 461, 'min_gross' => 512.4, 'price_currency' => 'EUR', 'first_image' => '',
            'pictures_json' => '["http://img/1.jpg"]', 'info_fetched_at' => '2026-09-23', 'product_id' => 0,
            'sync_status' => 'active', 'gate_hidden' => 'N',
        ];
    }

    /** @return array<string, mixed> */
    private static function search(array $over = []): array
    {
        return $over + [
            'page' => 1, 'items_per_page' => 50, 'sort_by' => 'availability', 'sort_order' => 'asc',
            'city' => '', 'availability' => '', 'images' => '', 'product' => '', 'q' => '',
        ];
    }

    public function testAnImmediateHotelWithPicturesIsReady(): void
    {
        $r = HotelListView::rows([self::row()], false)[0];

        self::assertSame('LA:RO0363', $r['key']);
        self::assertTrue($r['eligible']);
        self::assertSame('', $r['skip_reason']);
        self::assertSame('im', $r['availability_key']);
        self::assertSame('8 Oct', $r['check_in']);
        self::assertFalse($r['is_season']);
        self::assertSame('461 €', $r['price']);
        self::assertSame('512 €', $r['gross']);
        self::assertSame('http://img/1.jpg', $r['thumb']);
        self::assertSame('', $r['product_code'], 'not a product yet');
    }

    /** Details never fetched: "Create products" fetches them first, so it is not "no images" yet. */
    public function testDetailsNotFetchedYetAreFetchedFirstNotSkipped(): void
    {
        $r = HotelListView::rows([self::row(['pictures_json' => null, 'info_fetched_at' => null])], false)[0];

        self::assertTrue($r['eligible']);
        self::assertTrue($r['details_pending']);
        self::assertSame('not_fetched', $r['image_state']);

        $fetched = HotelListView::rows([self::row(['pictures_json' => '[]'])], false)[0];
        self::assertFalse($fetched['eligible']);
        self::assertSame('no_images', $fetched['skip_reason']);
    }

    public function testStopSaleIsListedWithItsReason(): void
    {
        $r = HotelListView::rows([self::row(['availability' => 'ST', 'availability_window' => 'season', 'availability_check_in' => '2027-07-15'])], false)[0];

        self::assertFalse($r['eligible']);
        self::assertSame('stop_sale', $r['skip_reason']);
        self::assertSame('st', $r['availability_key']);
        self::assertTrue($r['is_season']);
    }

    public function testAProductShowsItsCodeAndWhetherTheCheckHidIt(): void
    {
        $r = HotelListView::rows([self::row(['product_id' => 55, 'product_status' => 'H', 'gate_hidden' => 'Y', 'availability' => 'NONE'])], false)[0];

        self::assertSame(55, $r['product_id']);
        self::assertSame('EUS-LA-RO0363', $r['product_code']);
        self::assertSame('H', $r['product_status']);
        self::assertTrue($r['gate_hidden']);
        self::assertSame('none', $r['availability_key']);
    }

    public function testMoney(): void
    {
        self::assertSame('1,139 €', HotelListView::money(1139.4, 'EUR'));
        self::assertSame('300 RON', HotelListView::money(300, 'ron'));
        self::assertSame('', HotelListView::money(0, 'EUR'));
    }

    public function testChipsKeepTheOtherFiltersAndTheSortAndGoBackToPageOne(): void
    {
        $summary = HotelListView::summary(535, ['IM' => 50, 'OR' => 0, 'ST' => 20, 'NONE' => 426, '' => 39], ['with' => 74, 'without' => 0, 'not_fetched' => 461], 3, 0, ['hotels' => 0, 'destinations' => 0], [], []);
        $chips = HotelListView::chips(self::search(['city' => 'RO0101', 'images' => 'with', 'sort_by' => 'price', 'page' => 4]), $summary);

        $im = array_values(array_filter($chips['availability'], static fn (array $c): bool => $c['value'] === 'IM'))[0];
        self::assertSame(50, $im['count']);
        self::assertFalse($im['active']);
        self::assertStringContainsString('city=RO0101', $im['url']);
        self::assertStringContainsString('images=with', $im['url']);
        self::assertStringContainsString('availability=IM', $im['url']);
        self::assertStringContainsString('sort_by=price', $im['url']);
        self::assertDoesNotMatchRegularExpression('/[?&]page=/', $im['url']);

        $values = array_column($chips['availability'], 'value');
        self::assertNotContains('OR', $values, 'an empty chip is noise');
        self::assertTrue($chips['images'][1]['active'], 'With images is the active one');
        self::assertNotContains('without', array_column($chips['images'], 'value'));
    }

    public function testAnEmptyChipStaysWhenItIsTheActiveFilter(): void
    {
        $summary = HotelListView::emptySummary();
        $chips = HotelListView::chips(self::search(['availability' => 'OR']), $summary);

        self::assertContains('OR', array_column($chips['availability'], 'value'));
    }

    /** The hidden return field can only lead back to this list. */
    public function testTheReturnUrlKeepsOnlyKnownFilterKeys(): void
    {
        self::assertSame('eurosite.hotels', HotelListView::returnUrl(''));
        self::assertSame(
            'eurosite.hotels?city=RO0101&q=Parc+CM&sort_by=price&page=2',
            HotelListView::returnUrl('city=RO0101&q=Parc CM&sort_by=price&page=2&dispatch=addons.uninstall&redirect_url=http://evil'),
        );
        self::assertSame('eurosite.hotels', HotelListView::returnUrl('q=%3Cscript%3E'));
    }

    public function testTheFilterQueryCarriesFiltersSortAndPage(): void
    {
        $q = HotelListView::filterQuery(self::search(['availability' => 'IM', 'page' => 3]));

        self::assertStringContainsString('availability=IM', $q);
        self::assertStringContainsString('sort_by=availability', $q);
        self::assertStringContainsString('page=3', $q);
        self::assertStringStartsWith('eurosite.hotels?', HotelListView::listUrl(self::search()));
        self::assertStringNotContainsString('sort_by', HotelListView::listUrl(self::search()), 'each column link adds its own sort');
    }

    public function testTheCreateNoticeSaysWhatHappenedAndWhy(): void
    {
        [$type, $msg] = HotelListView::createdNotice(['added' => 3, 'linked' => 1, 'would_create' => 0, 'failed' => 0, 'skipped' => ['stop_sale' => 2, 'no_images' => 1], 'details_fetched' => 2, 'errors' => []]);
        self::assertSame('N', $type);
        self::assertSame('3 products created, 1 linked to existing products. Skipped: 2 × stop_sale, 1 × no_images.', $msg);

        self::assertSame('W', HotelListView::createdNotice(['added' => 0, 'linked' => 0, 'would_create' => 0, 'failed' => 0, 'skipped' => ['on_request' => 4], 'details_fetched' => 0, 'errors' => []])[0]);
        self::assertSame('E', HotelListView::createdNotice(['added' => 0, 'linked' => 0, 'would_create' => 0, 'failed' => 2, 'skipped' => [], 'details_fetched' => 0, 'errors' => []])[0]);
    }

    public function testTheSummaryCountsWhatWasChecked(): void
    {
        $s = HotelListView::summary(
            535,
            ['IM' => 50, 'OR' => 4, 'ST' => 20, 'NONE' => 426, '' => 35],
            ['with' => 74, 'without' => 0, 'not_fetched' => 461],
            0,
            0,
            ['hotels' => 667, 'destinations' => 35],
            [['city_code' => 'RO0101', 'country_code' => 'RO', 'name' => 'Mamaia', 'hotels' => 97], ['city_code' => 'RO9', 'country_code' => 'RO', 'name' => '', 'hotels' => 1]],
            ['availability' => ['started_at' => '2026-09-24 04:30:00']],
        );

        self::assertSame(500, $s['checked']);
        self::assertSame(2, $s['destinations']);
        self::assertSame([['code' => 'RO0101', 'label' => 'Mamaia (97)'], ['code' => 'RO9', 'label' => 'RO9 (1)']], $s['destination_list']);
        self::assertSame('24 Sep 2026, 04:30', $s['last_availability']);
        self::assertSame('', $s['last_hotels_sync']);
        self::assertSame(667, $s['hidden_hotels']);
    }
}
