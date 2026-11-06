<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Services\ListCardFacts;

/** The card's meal plans and three facilities, from the mapped CS-Cart features, one query a page. */
#[CoversClass(ListCardFacts::class)]
final class ListCardFactsTest extends TestCase
{
    /** @var list<array{list<int>, list<int>}> */
    private array $asked = [];

    /**
     * @param array<string, int> $features feature type => feature id
     * @param list<array<string, mixed>> $rows
     */
    private function facts(array $features, array $rows): ListCardFacts
    {
        return new ListCardFacts(
            static fn (string $type): int => $features[$type] ?? 0,
            function (array $productIds, array $featureIds) use ($rows): array {
                $this->asked[] = [$productIds, $featureIds];

                return $rows;
            },
        );
    }

    public function testMealsAndTheFacilitiesAGuestPicksByFirst(): void
    {
        $facts = $this->facts(['board' => 4, 'hotel_facility' => 6], [
            ['product_id' => '11', 'feature_id' => '6', 'variant' => 'Aer condiționat'],
            ['product_id' => '11', 'feature_id' => '4', 'variant' => 'Mic dejun'],
            ['product_id' => '11', 'feature_id' => '6', 'variant' => 'Parcare gratuită'],
            ['product_id' => '11', 'feature_id' => '6', 'variant' => 'Bar'],
            ['product_id' => '11', 'feature_id' => '6', 'variant' => 'Piscină exterioară'],
            ['product_id' => '11', 'feature_id' => '4', 'variant' => 'Demipensiune'],
            ['product_id' => '11', 'feature_id' => '6', 'variant' => 'Wi-Fi gratuit'],
            ['product_id' => '12', 'feature_id' => '6', 'variant' => 'Bar'],
            ['product_id' => '12', 'feature_id' => '6', 'variant' => ' '],
            ['product_id' => '12', 'feature_id' => '6', 'variant' => 'Bar'],
        ])->forProducts([11, 12, 13]);

        self::assertSame(['meals' => 'Mic dejun · Demipensiune', 'highlights' => ['Piscină exterioară', 'Parcare gratuită', 'Wi-Fi gratuit']], $facts[11]);
        self::assertSame(['meals' => '', 'highlights' => ['Bar']], $facts[12]);
        self::assertArrayNotHasKey(13, $facts);
        self::assertSame([[[11, 12, 13], [4, 6]]], $this->asked);
    }

    public function testNoMappedFeatureNoQuery(): void
    {
        self::assertSame([], $this->facts([], [])->forProducts([11]));
        self::assertSame([], $this->facts(['board' => 4], [])->forProducts([]));
        self::assertSame([], $this->asked);
    }

    public function testHighlightsKeepTheirOwnOrderPastTheRanking(): void
    {
        self::assertSame(['Spa & Wellness', 'Acces direct la plajă', 'Bar'], ListCardFacts::highlights(['Bar', 'Grădină', 'Acces direct la plajă', 'Spa & Wellness']));
        self::assertSame(['Bar', 'Grădină'], ListCardFacts::highlights(['Bar', 'Grădină']));
        self::assertSame([], ListCardFacts::highlights([]));
    }
}
