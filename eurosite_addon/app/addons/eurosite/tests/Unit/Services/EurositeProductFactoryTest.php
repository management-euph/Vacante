<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\Eurosite\Services\EurositeProductFactory as F;

/**
 * The product rules that need no database: the product code, which hotels
 * may become products, their pictures and price.
 */
final class EurositeProductFactoryTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function hotel(array $over = []): array
    {
        return $over + [
            'tourop_code' => 'LA', 'product_code' => 'RO0363', 'name' => 'Parc CM',
            'sync_status' => 'active', 'availability' => 'IM', 'product_id' => 0,
            'pictures_json' => '["http://img/1.jpg","http://img/2.jpg"]', 'first_image' => '',
            'info_fetched_at' => '2026-09-23 10:00:00',
        ];
    }

    public function testTheProductCodeCarriesPrefixTourOperatorAndHotelCode(): void
    {
        self::assertSame('EUS-LA-RO0363', F::productCode('la', ' ro0363 '));
        self::assertSame(['LA', 'RO0363'], F::parseProductCode('EUS-LA-RO0363'));
        self::assertSame(['LA', 'RO0363'], F::parseProductCode('eus-la-ro0363'));
    }

    /** Sphinx (HR59843), Novoton (NVT123) and a bare hotel code are not ours. */
    public function testOtherProvidersCodesAreNotEurosites(): void
    {
        foreach (['HR59843', 'RO0363', 'NVT123', 'EUS-LA', 'EUS--RO1', 'XEUS-LA-RO1', ''] as $code) {
            self::assertNull(F::parseProductCode($code), $code);
        }
    }

    public function testNamesSavedHtmlEscapedReadAsTheHotelDoes(): void
    {
        self::assertSame("EAGLE'S NEST", F::displayName('EAGLE&#039;S NEST'));
        self::assertSame('Vila & Spa', F::displayName(' Vila &amp; Spa '));
    }

    public function testPicturesComeFromTheDetailsThenTheCoverImage(): void
    {
        self::assertSame(['http://img/1.jpg', 'http://img/2.jpg'], F::pictures(self::hotel(['first_image' => 'http://img/cover.jpg'])));
        self::assertSame(['http://img/cover.jpg'], F::pictures(self::hotel(['pictures_json' => '[]', 'first_image' => 'http://img/cover.jpg'])));
        self::assertSame([], F::pictures(self::hotel(['pictures_json' => '["javascript:x", ""]'])), 'only http(s) URLs');
        self::assertSame(['http://img/1.jpg'], F::pictures(self::hotel(['pictures_json' => '["http://img/1.jpg","http://img/1.jpg"]'])), 'no repeats');
    }

    public function testTheImageStateSeparatesNoPicturesFromNotFetched(): void
    {
        self::assertSame(['state' => 'pictures', 'count' => 2], F::imageState(self::hotel()));
        self::assertSame(['state' => 'cover', 'count' => 1], F::imageState(self::hotel(['pictures_json' => null, 'first_image' => 'http://c.jpg'])));
        self::assertSame(['state' => 'none', 'count' => 0], F::imageState(self::hotel(['pictures_json' => '[]'])));
        self::assertSame(['state' => 'not_fetched', 'count' => 0], F::imageState(self::hotel(['pictures_json' => null, 'info_fetched_at' => null])));
    }

    public function testOnlyListedImmediateHotelsWithImagesBecomeProducts(): void
    {
        self::assertSame('', F::skipReason(self::hotel(), false));
        self::assertSame('already_product', F::skipReason(self::hotel(['product_id' => 5, 'availability' => 'ST']), false), 'the first reason is the one shown');
        self::assertSame('not_whitelisted', F::skipReason(self::hotel(['sync_status' => 'inactive']), false));
        self::assertSame('on_request', F::skipReason(self::hotel(['availability' => 'OR']), false));
        self::assertSame('stop_sale', F::skipReason(self::hotel(['availability' => 'ST']), false));
        self::assertSame('no_offer', F::skipReason(self::hotel(['availability' => 'NONE']), false));
        self::assertSame('not_checked', F::skipReason(self::hotel(['availability' => '']), false));
        self::assertSame('no_images', F::skipReason(self::hotel(['pictures_json' => '[]']), false));
    }

    public function testTheSettingLetsHotelsWithoutImagesThrough(): void
    {
        self::assertSame('', F::skipReason(self::hotel(['pictures_json' => '[]']), true));
        self::assertSame('stop_sale', F::skipReason(self::hotel(['pictures_json' => '[]', 'availability' => 'ST']), true), 'still Immediate only');
    }

    public function testEveryReasonItCanGiveIsAKnownOne(): void
    {
        foreach (['already_product', 'not_whitelisted', 'on_request', 'stop_sale', 'no_offer', 'not_checked', 'no_images'] as $reason) {
            self::assertContains($reason, F::SKIP_REASONS);
        }
    }

    public function testThePriceIsConvertedToTheStoreCurrency(): void
    {
        self::assertSame(2295.78, F::toStorePrice(461.0, 4.98), 'EUR offer on a RON store');
        self::assertSame(461.0, F::toStorePrice(461.0, 1.0));
        self::assertSame(461.13, F::toStorePrice(461.129, 0.0), 'unknown currency: as is');
    }

    public function testSeoPlaceholdersNameTheHotelAndItsPlace(): void
    {
        $p = F::placeholders(self::hotel(['category' => 4, 'city_name' => 'Mamaia', 'country_name' => 'Romania', 'city_code' => 'RO0101']));

        self::assertSame('Parc CM', $p['name']);
        self::assertSame('4', $p['classification']);
        self::assertSame('★★★★', $p['stars_emoji']);
        self::assertSame('Mamaia', $p['city']);
        self::assertSame('Romania', $p['country']);
        self::assertSame('http://img/1.jpg', $p['image_url']);

        $bare = F::placeholders(self::hotel(['city_code' => 'RO0101', 'country_code' => 'RO']));
        self::assertSame('RO0101', $bare['city'], 'no synced name: the code');
        self::assertSame('', $bare['classification']);
    }

    public function testSeoPlaceholdersCoverEveryKeyTheSeoPageLists(): void
    {
        $p = F::placeholders(self::hotel([
            'category' => 3, 'city_name' => 'Mamaia', 'country_name' => 'Romania',
            'city_code' => 'ROMM', 'country_code' => 'RO',
            'rooms_json' => '{"DB":"Double room","TW":"Twin room","AP":"Apartment","SG":"Single room"}',
            'payload_json' => '{"latitude":"44.2560","longitude":"28.6240"}',
            'min_price' => '461.40', 'price_currency' => 'EUR',
        ]));

        self::assertSame(['Double room', 'Twin room', 'Apartment', 'Single room'], $p['rooms']);
        self::assertSame('RO0363', $p['code']);
        self::assertSame('ROMM', $p['city_code']);
        self::assertSame('RO', $p['country_code']);
        self::assertSame('44.2560', $p['latitude']);
        self::assertSame('28.6240', $p['longitude']);
        self::assertSame('461', $p['min_price']);
        self::assertSame('EUR', $p['currency']);

        // No price yet: neither the price nor a lone currency.
        $none = F::placeholders(self::hotel());
        self::assertSame('', $none['min_price']);
        self::assertSame('', $none['currency']);
        self::assertSame('', $none['latitude']);

        // Every placeholder the SEO Templates page offers is one this fills.
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/func.php');
        preg_match('/function fn_eurosite_seo_placeholders\(\): array\s*\{(.*?)\n\}/s', $src, $m);
        preg_match_all("/'([a-z_]+)'(?!\s*=>)/", $m[1] ?? '', $keys);
        self::assertNotEmpty($keys[1]);
        foreach ($keys[1] as $key) {
            self::assertArrayHasKey($key, $p, $key);
        }
    }
}
