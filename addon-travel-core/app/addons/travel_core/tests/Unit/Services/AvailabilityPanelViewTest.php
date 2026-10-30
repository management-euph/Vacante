<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Enums\AvailabilityMode;
use Tygh\Addons\TravelCore\Services\AvailabilityPanel;
use Tygh\Addons\TravelCore\Services\AvailabilityPanelView;
use Tygh\Addons\TravelCore\Services\DateHelper;
use Tygh\Addons\TravelCore\Services\TravelCoreConfig;
use Tygh\Addons\TravelCore\Tests\Support\DbStub;
use Tygh\Registry;

/**
 * The availability panel as the hotel page draws it, above the search on
 * both hotel page templates (docs/novoton-availability-gaps-plan.md §7–8):
 * its months and dates in the visitor's language, and the similar hotels'
 * cards from what the store keeps.
 */
final class AvailabilityPanelViewTest extends TestCase
{
    protected function setUp(): void
    {
        DbStub::reset();
        Registry::set('addons.travel_core.availability_panels', 'Y');
        Registry::set('addons.travel_core.availability_privacy_url', 'https://example.test/privacy');
    }

    protected function tearDown(): void
    {
        DbStub::reset();
        Registry::set('addons.travel_core.availability_panels', null);
        Registry::set('addons.travel_core.availability_privacy_url', null);
    }

    /**
     * @param array<string, mixed> $record the hotel's availability record
     * @param list<array<string, mixed>> $bookable its destination's bookable hotels
     * @param array<int, string> $names product id => the product's name on the store
     */
    private static function view(array $record, array $bookable = [], array $names = [], string $lang = 'ro'): AvailabilityPanelView
    {
        DbStub::$getRow = static fn (): array => $record + [
            'provider' => 'novoton', 'hotel_id' => '476', 'product_id' => '15', 'destination_key' => 'BULGARIA|Sunny Beach',
            'hotel_name' => 'Sol Nessebar', 'season_published' => 'N', 'contract_from' => null, 'offer_months' => null,
        ];
        DbStub::$getArray = static fn (): array => $bookable;
        DbStub::$getField = static fn (): string => 'NVT476';

        return new AvailabilityPanelView(
            new AvailabilityPanel(static fn (): ?int => 4, static fn (): AvailabilityMode => AvailabilityMode::Report),
            $lang,
            static fn (int $productId): array => $productId === 21 ? ['pair_id' => 7, 'detailed' => ['image_path' => '/images/21.jpg']] : [],
            static fn (string $provider, string $hotelId): string => $provider === 'novoton' && $hotelId === '521' ? '85 €' : '',
            static fn (string $day): string => 'store:' . $day,
            static fn (int $productId): string => $names[$productId] ?? '',
        );
    }

    private static function today(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-10-27 10:00:00');
    }

    public function testBetweenSeasonsWritesLastSeasonsMonthsInTheVisitorsLanguage(): void
    {
        $months = '{"2026-06":"x","2026-07":"x","2026-08":"x","2026-09":"x"}';

        $ro = self::view(['state' => 'between_seasons', 'offer_months' => $months])->forProduct(15, self::today());
        self::assertNotNull($ro);
        self::assertSame(
            ['between_seasons', 'travel_core.availability_panel_between_seasons', 'iunie', 'septembrie', 'prices', '', 'https://example.test/privacy'],
            [$ro['state'], $ro['message'], $ro['season_from'], $ro['season_to'], $ro['notify'], $ro['date'], $ro['privacy_url']],
        );

        $en = self::view(['state' => 'between_seasons', 'offer_months' => $months], [], [], 'en')->forProduct(15, self::today());
        self::assertSame(['June', 'September'], [$en['season_from'] ?? '', $en['season_to'] ?? '']);
    }

    public function testASeasonOfOneMonthNamesItOnce(): void
    {
        $panel = self::view(['state' => 'between_seasons', 'offer_months' => '{"2026-08":"x"}'])->forProduct(15, self::today());

        self::assertSame(['august', ''], [$panel['season_from'] ?? null, $panel['season_to'] ?? null], 'the template then says "Last season: August."');
    }

    public function testPricesPublishedFromALaterDateSayFromWhenInTheStoresFormat(): void
    {
        $panel = self::view(['state' => 'between_seasons', 'season_published' => 'Y', 'contract_from' => '2027-05-01'])->forProduct(15, self::today());

        self::assertSame(
            ['travel_core.availability_panel_published_from', 'store:2027-05-01', '', ''],
            [$panel['message'] ?? '', $panel['date'] ?? '', $panel['notify'] ?? null, $panel['privacy_url'] ?? null],
            'nothing to wait for, so no form and no consent link',
        );
    }

    public function testSimilarHotelsCarryTheirStoreNamePhotoStarsAndFromPrice(): void
    {
        $bookable = [
            ['product_id' => '21', 'hotel_id' => '521', 'hotel_name' => 'FOUR STARS SUPPLIER NAME', 'product_code' => 'NVT521'],
            ['product_id' => '24', 'hotel_id' => '524', 'hotel_name' => 'Supplier Spelling', 'product_code' => 'NVT524'],
        ];
        $panel = self::view(['state' => 'not_offered'], $bookable, [21 => 'Hotel Four Stars '])->forProduct(15, self::today());

        self::assertSame([
            ['product_id' => 21, 'name' => 'Hotel Four Stars', 'stars' => 4, 'price' => '85 €', 'image' => ['pair_id' => 7, 'detailed' => ['image_path' => '/images/21.jpg']]],
            ['product_id' => 24, 'name' => 'Supplier Spelling', 'stars' => 4, 'price' => '', 'image' => []],
        ], $panel['similar'] ?? null, "the product's name first; a hotel without a stored price shows none");
    }

    public function testNoPanelForABookableHotelOrWhileSwitchedOff(): void
    {
        self::assertNull(self::view(['state' => 'bookable'])->forProduct(15, self::today()));

        Registry::set('addons.travel_core.availability_panels', 'N');
        self::assertNull(self::view(['state' => 'not_offered'])->forProduct(15, self::today()));
    }

    public function testThePrivacyLinkTakesOnlyAWebAddress(): void
    {
        $cases = [
            'https://example.test/privacy' => 'https://example.test/privacy',
            ' /pages/privacy-policy ' => '/pages/privacy-policy',
            'javascript:alert(1)' => '',
            '//elsewhere.test/privacy' => '',
            'https://example.test/a"onmouseover="x' => '',
            '' => '',
        ];
        foreach ($cases as $setting => $expected) {
            Registry::set('addons.travel_core.availability_privacy_url', $setting);
            self::assertSame($expected, TravelCoreConfig::availabilityPrivacyUrl(), $setting);
        }
    }

    public function testMonthNamesAsRunningTextUsesThem(): void
    {
        self::assertSame(['iunie', 'June', 'December', ''], [
            DateHelper::monthName(6, 'ro'),
            DateHelper::monthName(6, 'en'),
            DateHelper::monthName(12, 'de'),
            DateHelper::monthName(13, 'en'),
        ]);
    }
}
