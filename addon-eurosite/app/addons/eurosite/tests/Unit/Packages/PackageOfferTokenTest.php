<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Tests\Unit\Packages;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\Eurosite\Services\PackageOfferToken;

/**
 * A package offer's id: what the booking form needs to find the offer again,
 * signed by the store. Altered, cut or another store's: not an offer.
 */
final class PackageOfferTokenTest extends TestCase
{
    private const array OFFER = [
        'product_code' => 'TR0012', 'tourop_code' => 'EU', 'country_code' => 'TR', 'city_code' => 'TRALN',
        'dep_country_code' => 'RO', 'dep_city_code' => 'ROBCH1', 'transport' => 'plane', 'departure_date' => '2027-07-03',
        'nights' => '7', 'package_id' => '4', 'variant_id' => '1|1068746_669_1', 'room_codes' => '274',
        'check_in' => '2027-07-03', 'check_out' => '2027-07-10', 'price' => '1321.6', 'currency' => 'EUR',
    ];

    public function testATokenCarriesItsOfferBack(): void
    {
        $tokens = new PackageOfferToken('secret');
        $token = $tokens->encode(self::OFFER + ['extra' => 'left out']);

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+\.[0-9a-f]{32}$/', $token, 'URL-safe as it is');
        self::assertSame(self::OFFER, $tokens->decode($token));
        self::assertSame($token, $tokens->encode(self::OFFER), 'the same offer, the same token');
    }

    public function testAMissingFieldIsEmpty(): void
    {
        $tokens = new PackageOfferToken('secret');
        $offer = $tokens->decode($tokens->encode(['product_code' => 'TR0012']));

        self::assertNotNull($offer);
        self::assertSame(PackageOfferToken::FIELDS, array_keys($offer));
        self::assertSame(['TR0012', ''], [$offer['product_code'], $offer['variant_id']]);
    }

    public function testOnlyThisStoresUnalteredTokensAreRead(): void
    {
        $tokens = new PackageOfferToken('secret');
        $token = $tokens->encode(self::OFFER);
        [$body, $signature] = explode('.', $token);

        // Another price, signed by nobody.
        $cheaper = rtrim(strtr(base64_encode((string) json_encode(['price' => '1'] + self::OFFER)), '+/', '-_'), '=');
        self::assertNull($tokens->decode($cheaper . '.' . $signature));
        self::assertNull($tokens->decode($body . '.' . str_repeat('0', 32)));
        self::assertNull($tokens->decode($body));
        self::assertNull($tokens->decode(''));
        self::assertNull($tokens->decode('a.b.c'));
        self::assertNull((new PackageOfferToken('another store'))->decode($token));
    }
}
