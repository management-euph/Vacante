<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Services;

use Tygh\Addons\Eurosite\Dto\PackageOffer;
use Tygh\Addons\Eurosite\Helpers\SessionAccessor;

/**
 * The package a guest is booking, as Eurosite confirmed it when the booking
 * form opened (PackageQuote::find): the form posts only its key, and
 * add-to-cart reads every price, id and guest count from here, as the hotel
 * flow reads OfferContextStore.
 *
 * Its own session key, keeping the last few: a hotel search (which replaces
 * OfferContextStore's set) or a second package opened in another tab does not
 * take away the one on the form.
 *
 * A snapshot has the hotel snapshot's keys (so the shared booking summary,
 * RoomOccupancy and the booking row read it as they read a hotel's), with
 * kind "package" and the package's own: PackageId, the operator, the
 * departure, the searched date and nights, and its services.
 */
final class PackageSnapshotStore
{
    private const string SESSION_KEY = 'eurosite_package_offers';

    /** Snapshots kept: the newest. */
    private const int MAX = 20;

    /**
     * @param array<string, string> $token PackageOfferToken::decode()
     * @param list<array{adults: int, children_ages: list<int>}> $occupancy
     *
     * @return string the snapshot's key
     */
    public static function remember(PackageOffer $offer, array $token, array $occupancy, int $cartProductId): string
    {
        $snapshot = self::snapshot($offer, $token, $occupancy, $cartProductId);
        $key = md5((string) json_encode($snapshot));
        $all = SessionAccessor::getArray(self::SESSION_KEY);
        unset($all[$key]);
        $all[$key] = $snapshot;
        SessionAccessor::setArray(self::SESSION_KEY, array_slice($all, -self::MAX, null, true));

        return $key;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function get(string $key): ?array
    {
        $snapshot = SessionAccessor::getArray(self::SESSION_KEY)[$key] ?? null;
        if (!is_array($snapshot) || $snapshot === []) {
            return null;
        }

        /** @var array<string, mixed> $snapshot */
        return $snapshot;
    }

    /**
     * @param array<string, string> $token
     * @param list<array{adults: int, children_ages: list<int>}> $occupancy
     *
     * @return array<string, mixed>
     */
    public static function snapshot(PackageOffer $offer, array $token, array $occupancy, int $cartProductId): array
    {
        $stay = $offer->hotel;
        $totals = RoomOccupancy::totals($occupancy);

        return [
            'kind' => 'package',
            'product_code' => $stay->productCode,
            'cart_product_id' => max(0, $cartProductId),
            'product_name' => $stay->productName,
            'country_code' => $stay->countryCode !== '' ? $stay->countryCode : ($token['country_code'] ?? ''),
            'city_code' => $stay->cityCode !== '' ? $stay->cityCode : ($token['city_code'] ?? ''),
            'city_name' => $stay->cityName,
            'check_in' => $stay->checkIn,
            'check_out' => $stay->checkOut,
            'variant_id' => $stay->variantId,
            'series_id' => '',
            'price' => $stay->price,
            'old_price' => $stay->oldPrice(),
            'offer_description' => $stay->offerDescription,
            'category' => $stay->category,
            'currency' => $stay->currency,
            'offer_type' => $stay->offerType,
            'availability' => $stay->availability,
            'availability_code' => $stay->availabilityCode,
            'rooms' => $stay->rooms,
            'meals' => $stay->meals,
            'adults' => $totals['adults'],
            'children_ages' => $totals['children_ages'],
            'rooms_occupancy' => $occupancy,
            // The package's own.
            'package_id' => $offer->packageId,
            'tourop_code' => $offer->tourOpCode !== '' ? $offer->tourOpCode : ($token['tourop_code'] ?? ''),
            'transport' => $token['transport'] ?? '',
            'dep_country_code' => $token['dep_country_code'] ?? '',
            'dep_city_code' => $token['dep_city_code'] ?? '',
            'departure_date' => $token['departure_date'] ?? '',
            'nights' => (int) ($token['nights'] ?? 0),
            'services' => $offer->services,
            'accept_remarks' => $offer->acceptRemarks,
        ];
    }
}
