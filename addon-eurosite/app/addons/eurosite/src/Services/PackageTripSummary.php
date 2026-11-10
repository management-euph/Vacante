<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Services;

use Tygh\Addons\Eurosite\Dto\PackageOffer;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * A Eurosite package's part of the booking summary (Travel Core's
 * BookingSidebarViewModel::withTrip): its journeys and what its price
 * includes besides the stay, from the services of its snapshot
 * (PackageSnapshotStore).
 *
 * @phpstan-import-type PackageService from PackageOffer
 */
final class PackageTripSummary
{
    /**
     * The journeys as the summary lists them: a flight's way there and back
     * ("✈ Dus · 21.09.2012" / "07:05 Otopeni → 08:50 Antalya"), or each coach
     * journey.
     *
     * @param array<string, mixed> $snapshot
     * @param array{out: string, back: string, bus: string} $words
     * @param callable(int): string $formatDate a day, as the store writes it
     *
     * @return list<array{title: string, line: string, note: string}>
     */
    public static function legs(array $snapshot, array $words, callable $formatDate): array
    {
        $legs = array_values(array_filter(
            self::services($snapshot),
            static fn (array $service): bool => $service['type'] === PackageOffer::TYPE_TRANSPORT,
        ));
        usort($legs, static fn (array $a, array $b): int => strcmp($a['start'], $b['start']));
        if ($legs === []) {
            return [];
        }

        $rows = [];
        if (TypeCoerce::toString($snapshot['transport'] ?? '') !== 'bus') {
            $flight = PackageQuote::flight($legs, TypeCoerce::toString($snapshot['check_in'] ?? ''), TypeCoerce::toString($snapshot['check_out'] ?? ''));
            foreach (['outbound' => $words['out'], 'inbound' => $words['back']] as $key => $word) {
                $way = $flight[$key] ?? null;
                if ($way === null) {
                    continue;
                }
                $day = strtotime($way['departure_date']);
                $rows[] = [
                    'title' => '✈ ' . $word . ($day !== false ? ' · ' . $formatDate($day) : ''),
                    'line' => trim($way['departure_time'] . ' ' . $way['from']) . ' → ' . trim($way['arrival_time'] . ' ' . $way['to']),
                    'note' => '',
                ];
            }

            return $rows;
        }

        foreach ($legs as $leg) {
            [$from, $to] = PackageQuote::places($leg['name']);
            $day = strtotime(substr($leg['start'], 0, 10));
            $rows[] = [
                'title' => $words['bus'] . ($day !== false ? ' · ' . $formatDate($day) : ''),
                'line' => $from !== '' ? $from . ' → ' . $to : self::sentence($leg['name']),
                'note' => '',
            ];
        }

        return $rows;
    }

    /**
     * What the price includes besides the stay and the journeys: the
     * transfer and Eurosite's other services (type 6), the airport tax (7s),
     * each once, as Eurosite names them. The package's commission (8) is part
     * of the price, not a service.
     *
     * @param array<string, mixed> $snapshot
     *
     * @return list<string>
     */
    public static function includes(array $snapshot): array
    {
        $out = [];
        foreach (self::services($snapshot) as $service) {
            if (!in_array($service['type'], [PackageOffer::TYPE_OTHER, PackageOffer::TYPE_AIRPORT_TAX], true)) {
                continue;
            }
            $name = self::sentence($service['name']);
            if ($name !== '' && !in_array($name, $out, true)) {
                $out[] = $name;
            }
        }

        return $out;
    }

    /** "TAXA DE AEROPORT" → "Taxa de aeroport"; "transfer aeroport" → "Transfer aeroport". */
    public static function sentence(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if ($text === '') {
            return '';
        }
        if ($text === mb_strtoupper($text)) {
            $text = mb_strtolower($text);
        }

        return mb_strtoupper(mb_substr($text, 0, 1)) . mb_substr($text, 1);
    }

    /**
     * The snapshot's services, each with the fields a PackageOffer gives.
     *
     * @param array<string, mixed> $snapshot
     *
     * @return list<PackageService>
     */
    private static function services(array $snapshot): array
    {
        $out = [];
        foreach (TypeCoerce::toRowList($snapshot['services'] ?? null) as $service) {
            $seats = $service['seats'] ?? null;
            $out[] = [
                'type' => TypeCoerce::toString($service['type'] ?? ''),
                'code' => TypeCoerce::toString($service['code'] ?? ''),
                'name' => TypeCoerce::toString($service['name'] ?? ''),
                'transport' => TypeCoerce::toString($service['transport'] ?? ''),
                'availability_code' => TypeCoerce::toString($service['availability_code'] ?? ''),
                'start' => TypeCoerce::toString($service['start'] ?? ''),
                'end' => TypeCoerce::toString($service['end'] ?? ''),
                'seats' => $seats === null ? null : TypeCoerce::toInt($seats),
                'price' => TypeCoerce::toFloat($service['price'] ?? 0),
            ];
        }

        return $out;
    }
}
