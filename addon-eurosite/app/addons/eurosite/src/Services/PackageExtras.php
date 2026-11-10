<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Services;

use Tygh\Addons\Eurosite\Api\EurositeApiClient;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * "Servicii adiționale" on a Eurosite package's booking page: the extras
 * Eurosite offers for the chosen variant (a board upgrade, a charter
 * supplement from another city, a private transfer), each with its price for
 * the whole party.
 *
 * Eurosite answers them in two calls: getHotelServiceTypes lists what the
 * variant offers, and getHotelServicePrice prices one of them for the
 * travellers. Only a service that says it has a price is priced, and at most
 * MAX_PRICED of them, so a long list cannot keep the page waiting.
 *
 * What the guest ticks is sent back with the booking as AddBookingRequest's
 * SuppServices (its code, its type, the stay and the travellers it is for).
 */
final class PackageExtras
{
    /** Extras priced in one page load. */
    public const int MAX_PRICED = 8;

    public function __construct(
        private readonly EurositeApiClient $api,
    ) {
    }

    /**
     * The extras of one package variant, priced, cheapest first. [] when
     * Eurosite offers none or does not answer (the page then has no extras
     * section; a booking is never held up by them).
     *
     * @param array<string, mixed> $snapshot PackageSnapshotStore's
     * @param list<array{adults: int, children_ages: list<int>}> $occupancy
     *
     * @return list<array{code: string, type: string, charter_id: string, title: string, note: string, price: float, currency: string}>
     */
    public function forPackage(array $snapshot, array $occupancy, string $currency, string $language): array
    {
        $where = [
            'country_code' => TypeCoerce::toString($snapshot['country_code'] ?? ''),
            'city_code' => TypeCoerce::toString($snapshot['city_code'] ?? ''),
            'tourop_code' => TypeCoerce::toString($snapshot['tourop_code'] ?? ''),
            'product_code' => TypeCoerce::toString($snapshot['product_code'] ?? ''),
            'variant_id' => TypeCoerce::toString($snapshot['variant_id'] ?? ''),
            'check_in' => TypeCoerce::toString($snapshot['check_in'] ?? ''),
            'check_out' => TypeCoerce::toString($snapshot['check_out'] ?? ''),
            'language' => $language,
        ];

        try {
            $offered = $this->api->getHotelServiceTypes($where);
        } catch (\Throwable $e) {
            fn_log_event('general', 'runtime', ['message' => 'Eurosite package extras unavailable: ' . $e->getMessage()]);

            return [];
        }

        $pax = self::pax($occupancy);
        $extras = [];
        $priced = 0;
        foreach ($offered as $service) {
            if (!$service['has_price'] || $service['code'] === '' || $priced >= self::MAX_PRICED) {
                continue;
            }
            $priced++;
            try {
                $prices = $this->api->getHotelServicePrices($where + [
                    'currency' => $currency,
                    'service_type' => $service['type'],
                    'service_code' => $service['code'],
                    'charter_id' => $service['charter_id'],
                    'pax' => $pax,
                ]);
            } catch (\Throwable $e) {
                fn_log_event('general', 'runtime', [
                    'message' => 'Eurosite package extra ' . $service['code'] . ' could not be priced: ' . $e->getMessage(),
                ]);

                continue;
            }
            $price = 0.0;
            $answered = '';
            foreach ($prices as $row) {
                $price += $row['price'];
                $answered = $answered !== '' ? $answered : $row['currency'];
            }
            if ($price <= 0) {
                continue;
            }
            $extras[] = [
                'code' => $service['code'],
                'type' => $service['type'],
                'charter_id' => $service['charter_id'],
                'title' => PackageTripSummary::sentence($service['name']),
                'note' => PackageTripSummary::sentence($service['type_name']),
                'price' => round($price, 2),
                'currency' => $answered !== '' ? $answered : $currency,
            ];
        }
        usort($extras, static fn (array $a, array $b): int => $a['price'] <=> $b['price']);

        return $extras;
    }

    /**
     * The extras the guest ticked, in the order they were offered: only ones
     * the page itself offered, each once, with the price it was shown at.
     *
     * The rows come back from the session, so each field is read as what it
     * should be rather than trusted.
     *
     * @param list<array<string, mixed>> $offered forPackage()'s, kept in the snapshot
     * @param mixed $chosen the posted service codes
     *
     * @return list<array{code: string, type: string, charter_id: string, title: string, note: string, price: float, currency: string}>
     */
    public static function chosen(array $offered, mixed $chosen): array
    {
        $wanted = [];
        foreach (is_array($chosen) ? $chosen : [] as $code) {
            if (is_scalar($code)) {
                $wanted[(string) $code] = true;
            }
        }
        $out = [];
        $taken = [];
        foreach ($offered as $extra) {
            $code = TypeCoerce::toString($extra['code'] ?? '');
            if ($code === '' || !isset($wanted[$code]) || isset($taken[$code])) {
                continue;
            }
            $taken[$code] = true;
            $out[] = [
                'code' => $code,
                'type' => TypeCoerce::toString($extra['type'] ?? ''),
                'charter_id' => TypeCoerce::toString($extra['charter_id'] ?? ''),
                'title' => TypeCoerce::toString($extra['title'] ?? ''),
                'note' => TypeCoerce::toString($extra['note'] ?? ''),
                'price' => TypeCoerce::toFloat($extra['price'] ?? 0),
                'currency' => TypeCoerce::toString($extra['currency'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * What the chosen extras add to the package's price.
     *
     * @param list<array{price: float}> $extras
     */
    public static function total(array $extras): float
    {
        return round(array_sum(array_column($extras, 'price')), 2);
    }

    /**
     * The extras as AddBookingRequest sends them: each with its code, type,
     * the stay it covers and every traveller (PaxIds, numbered from 1 in the
     * order the rooms send their PaxName elements).
     *
     * @param list<array{code: string, type: string}> $extras
     * @param array<string, mixed> $booking the ?:eurosite_bookings row
     * @param int $paxCount how many travellers the booking has
     *
     * @return list<array{code: string, type: string, check_in: string, check_out: string, pax_ids: list<int>}>
     */
    public static function suppServices(array $extras, array $booking, int $paxCount): array
    {
        $paxIds = $paxCount > 0 ? range(1, $paxCount) : [];

        return array_map(static fn (array $extra): array => [
            'code' => $extra['code'],
            'type' => $extra['type'],
            'check_in' => TypeCoerce::toString($booking['check_in'] ?? ''),
            'check_out' => TypeCoerce::toString($booking['check_out'] ?? ''),
            'pax_ids' => $paxIds,
        ], $extras);
    }

    /**
     * Every traveller, as the pricing call wants them: placeholders before
     * the guest has given their names, a child with its age.
     *
     * @param list<array{adults: int, children_ages: list<int>}> $occupancy
     *
     * @return list<array{type: string, name: string, child_age?: string}>
     */
    private static function pax(array $occupancy): array
    {
        $pax = [];
        $adults = 0;
        $children = 0;
        foreach ($occupancy as $room) {
            for ($i = 0; $i < $room['adults']; $i++) {
                $pax[] = ['type' => 'adult', 'name' => 'Adult ' . (++$adults)];
            }
            foreach ($room['children_ages'] as $age) {
                $pax[] = ['type' => 'child', 'name' => 'Copil ' . (++$children), 'child_age' => (string) $age];
            }
        }

        return $pax;
    }
}
