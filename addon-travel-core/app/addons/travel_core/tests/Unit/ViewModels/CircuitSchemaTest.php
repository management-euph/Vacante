<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\ViewModels;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Dto\Circuit\CircuitDay;
use Tygh\Addons\TravelCore\Dto\Circuit\CircuitDetails;
use Tygh\Addons\TravelCore\Dto\Circuit\CircuitPage;
use Tygh\Addons\TravelCore\ViewModels\CircuitSchema;

/**
 * A circuit page's structured data (docs/circuit-page-plan.md step 6): a
 * TouristTrip stating only what the supplier says, safe to print raw in a
 * <script> element.
 */
#[CoversClass(CircuitSchema::class)]
final class CircuitSchemaTest extends TestCase
{
    private static function page(float $fromPrice = 2398.0): CircuitPage
    {
        return new CircuitPage('sphinx', '1', 'Italia clasică', [['id' => '1', 'name' => 'București']], 8, 7, 3, 4, CircuitPage::FLIGHT, ['Roma', ' ', 'Florența', 'Veneția'], $fromPrice);
    }

    public function testATripWithItsRouteDaysAndOffer(): void
    {
        $details = new CircuitDetails([new CircuitDay('Sosire la Roma', 'x'), new CircuitDay('', 'a day with no title'), new CircuitDay('Florența', 'y')]);

        $schema = CircuitSchema::build(self::page(), $details, 'Opt zile prin Italia.', 'https://shop.test/images/1.jpg', 'https://shop.test/italia-clasica', 1199.0, 'EUR');

        self::assertSame([
            '@context' => 'https://schema.org',
            '@type' => 'TouristTrip',
            'name' => 'Italia clasică',
            'description' => 'Opt zile prin Italia.',
            'image' => 'https://shop.test/images/1.jpg',
            'url' => 'https://shop.test/italia-clasica',
            'itinerary' => [
                '@type' => 'ItemList',
                'numberOfItems' => 3,
                'itemListElement' => [
                    ['@type' => 'ListItem', 'position' => 1, 'item' => ['@type' => 'Place', 'name' => 'Roma']],
                    ['@type' => 'ListItem', 'position' => 2, 'item' => ['@type' => 'Place', 'name' => 'Florența']],
                    ['@type' => 'ListItem', 'position' => 3, 'item' => ['@type' => 'Place', 'name' => 'Veneția']],
                ],
            ],
            'subTrip' => [
                ['@type' => 'TouristTrip', 'name' => 'Sosire la Roma'],
                ['@type' => 'TouristTrip', 'name' => 'Florența'],
            ],
            'offers' => [
                '@type' => 'Offer',
                'price' => '1199.00',
                'priceCurrency' => 'EUR',
                'availability' => 'https://schema.org/InStock',
                'url' => 'https://shop.test/italia-clasica',
            ],
        ], $schema);
    }

    public function testWhatTheSupplierDoesNotSayIsLeftOut(): void
    {
        $page = new CircuitPage('sphinx', '1', 'Italia', [], 0, 0);

        self::assertSame(
            ['@context' => 'https://schema.org', '@type' => 'TouristTrip', 'name' => 'Italia'],
            CircuitSchema::build($page, null, '', '', '', 0.0, 'EUR'),
            'no description, image, address, route, days or saved price',
        );
        self::assertArrayNotHasKey('offers', CircuitSchema::build($page, null, '', '', '', 100.0, ''), 'a price with no currency is no offer');
    }

    public function testTheJsonCannotCloseItsScriptElement(): void
    {
        $page = new CircuitPage('sphinx', '1', 'Roma </script><script>alert(1)</script>', [], 0, 0);

        $json = CircuitSchema::json(CircuitSchema::build($page, null, '', '', 'https://shop.test/a/b', 0.0, 'EUR'));

        self::assertStringNotContainsString('<', $json);
        self::assertStringNotContainsString('>', $json);
        self::assertStringContainsString('https://shop.test/a/b', $json, 'slashes stay readable');
        self::assertSame('Roma </script><script>alert(1)</script>', json_decode($json, true)['name']);
    }
}
