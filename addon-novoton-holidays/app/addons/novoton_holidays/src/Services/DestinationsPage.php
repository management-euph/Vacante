<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Services;

/**
 * What the Destinations page and the dashboard's Destinations card show:
 * every country with its mode, every resort with what selling it means, the
 * resorts that are new since the last Save or gone from the feed, and the
 * live products outside what we sell.
 *
 * Pure: the controller passes in the rows, so this is tested without a DB.
 *
 * Until a whitelist is saved the page shows the older settings as a
 * whitelist — a selected country is "All resorts", or "Only selected" minus
 * its excluded resorts — so the first Save keeps today's behaviour exactly.
 */
final class DestinationsPage
{
    /** A resort whose last hotel_list stamp is this far behind its country's is gone from the feed. */
    private const int GONE_AFTER_SECONDS = 2 * 86400;

    /**
     * @param list<array{country: string, resort: string, hotels: int, priced: int, products: int, live: int, first_seen: string, last_seen: string}> $catalog
     * @param list<string> $knownCountries Constants::COUNTRIES
     * @param list<string> $settingCountries the "Selected countries" setting
     * @param list<string> $legacyExcluded the dashboard's excluded resorts
     * @param list<string> $hidden HIDDEN_RESORTS, never listed
     * @param list<array{product_id: int, hotel_name: string, country: string, resort: string}> $liveProducts
     *
     * @return array{configured: bool, countries: list<array<string, mixed>>, totals: array<string, int>, outside: list<array{product_id: int, hotel_name: string, country: string, resort: string}>}
     */
    public static function build(
        DestinationScope $scope,
        array $catalog,
        array $knownCountries,
        array $settingCountries,
        array $legacyExcluded,
        array $hidden,
        array $liveProducts,
    ): array {
        $configured = $scope->isConfigured();
        $settingUpper = array_map('mb_strtoupper', $settingCountries);

        // Catalog rows by country, hidden resorts dropped.
        $byCountry = [];
        foreach ($knownCountries as $c) {
            $byCountry[mb_strtoupper($c)] = [];
        }
        foreach ($catalog as $row) {
            if ($row['country'] === '' || ConfigProvider::isResortExcluded($row['resort'], $hidden)) {
                continue;
            }
            $byCountry[mb_strtoupper($row['country'])][mb_strtoupper($row['resort'])] = $row;
        }
        foreach ($scope->countries() as $c) {
            $byCountry[mb_strtoupper($c)] ??= [];
        }

        $countries = [];
        $totals = ['countries' => 0, 'resorts' => 0, 'hotels' => 0, 'priced' => 0, 'live' => 0, 'new' => 0, 'gone' => 0];

        foreach ($byCountry as $country => $rows) {
            $country = (string) $country;
            if ($configured) {
                $mode = $scope->mode($country);
                $selected = array_map('mb_strtoupper', $scope->selectedResorts($country) ?? []);
                $reviewedAt = $scope->reviewedAt($country);
            } else {
                $excludedHere = array_values(array_filter(
                    array_keys($rows),
                    static fn ($k): bool => ConfigProvider::isResortExcluded((string) $k, $legacyExcluded),
                ));
                $mode = !in_array($country, $settingUpper, true)
                    ? DestinationScope::MODE_OFF
                    : ($excludedHere === [] ? DestinationScope::MODE_ALL : DestinationScope::MODE_SPECIFIC);
                $selected = array_values(array_map('strval', array_diff(array_keys($rows), $excludedHere)));
                $reviewedAt = '';
            }

            // Resorts the whitelist names that no hotel carries any more:
            // listed (ticked, "not in feed") so the next Save keeps them.
            foreach ($configured ? ($scope->selectedResorts($country) ?? []) : [] as $name) {
                $rows[mb_strtoupper($name)] ??= [
                    'country' => $country, 'resort' => $name, 'hotels' => 0, 'priced' => 0,
                    'products' => 0, 'live' => 0, 'first_seen' => '', 'last_seen' => '',
                ];
            }

            $latest = 0;
            foreach ($rows as $r) {
                $latest = max($latest, (int) strtotime($r['last_seen'] ?: '1970-01-01'));
            }
            $reviewedTs = $reviewedAt === '' ? 0 : (int) strtotime($reviewedAt);

            $resorts = [];
            // resort_count, not 'resorts': that key holds the resort list below.
            $c = ['resort_count' => 0, 'sold' => 0, 'hotels' => 0, 'hotels_sold' => 0, 'priced_sold' => 0, 'live_sold' => 0, 'new' => 0, 'gone' => 0];
            foreach ($rows as $key => $r) {
                $isSelected = in_array((string) $key, $selected, true);
                $lastTs = (int) strtotime($r['last_seen'] ?: '1970-01-01');
                $gone = $r['hotels'] === 0 || ($latest > 0 && $lastTs < $latest - self::GONE_AFTER_SECONDS);
                $firstTs = (int) strtotime($r['first_seen'] ?: '1970-01-01');
                $isNew = $configured && $mode === DestinationScope::MODE_SPECIFIC && !$isSelected && !$gone
                    && $reviewedTs > 0 && $firstTs > $reviewedTs;
                $sold = !$gone && ($mode === DestinationScope::MODE_ALL || ($mode === DestinationScope::MODE_SPECIFIC && $isSelected));

                $resorts[] = [
                    'name' => $r['resort'],
                    'label' => DashboardSummary::displayName($r['resort']),
                    'hotels' => $r['hotels'],
                    'priced' => $r['priced'],
                    'products' => $r['products'],
                    'live' => $r['live'],
                    'selected' => $isSelected,
                    'sold' => $sold,
                    'new' => $isNew,
                    'gone' => $gone,
                ];
                if (!$gone) {
                    $c['resort_count']++;
                    $c['hotels'] += $r['hotels'];
                }
                if ($sold) {
                    $c['sold']++;
                    $c['hotels_sold'] += $r['hotels'];
                    $c['priced_sold'] += $r['priced'];
                    $c['live_sold'] += $r['live'];
                }
                $c['new'] += $isNew ? 1 : 0;
                $c['gone'] += $gone && $isSelected ? 1 : 0;
            }
            usort($resorts, static fn (array $a, array $b): int => strcmp((string) $a['label'], (string) $b['label']));

            $countries[] = [
                'country' => $country,
                'label' => DashboardSummary::displayName($country),
                'mode' => $mode,
                'resorts' => $resorts,
            ] + $c;

            if ($mode !== DestinationScope::MODE_OFF) {
                $totals['countries']++;
                $totals['resorts'] += $c['sold'];
                $totals['hotels'] += $c['hotels_sold'];
                $totals['priced'] += $c['priced_sold'];
                $totals['live'] += $c['live_sold'];
            }
            $totals['new'] += $c['new'];
            $totals['gone'] += $c['gone'];
        }

        // Sold countries first, then the biggest catalogs, then A–Z.
        usort($countries, static fn (array $a, array $b): int => [(int) ($a['mode'] === DestinationScope::MODE_OFF), -$a['hotels'], $a['label']]
            <=> [(int) ($b['mode'] === DestinationScope::MODE_OFF), -$b['hotels'], $b['label']]);

        $outside = [];
        if ($configured) {
            foreach ($liveProducts as $p) {
                if (!$scope->allows($p['country'], $p['resort'])) {
                    $outside[] = $p;
                }
            }
        }
        $totals['outside'] = count($outside);

        return ['configured' => $configured, 'countries' => $countries, 'totals' => $totals, 'outside' => $outside];
    }

    /**
     * The posted picker as whitelist rows. Countries must be known ones; an
     * 'only selected' country keeps its ticked resorts (names trimmed,
     * repeats and hidden resorts dropped).
     *
     * @param array<mixed> $posted DestinationPicker::readPost(): COUNTRY => [mode, items[]]
     * @param list<string> $known countries the page listed
     * @param list<string> $hidden
     * @return list<array{country: string, resort: string, selection_type: string}>
     */
    public static function rowsFromPost(array $posted, array $known, array $hidden): array
    {
        $knownUpper = [];
        foreach ($known as $k) {
            $knownUpper[mb_strtoupper(trim($k))] = trim($k);
        }

        $rows = [];
        foreach ($posted as $country => $entry) {
            $key = mb_strtoupper(trim((string) $country));
            if (!isset($knownUpper[$key]) || !is_array($entry)) {
                continue;
            }
            $mode = is_string($entry['mode'] ?? null) ? $entry['mode'] : DestinationScope::MODE_OFF;
            if ($mode !== DestinationScope::MODE_ALL && $mode !== DestinationScope::MODE_SPECIFIC) {
                continue;
            }
            $name = $knownUpper[$key];
            $rows[] = ['country' => $name, 'resort' => '', 'selection_type' => $mode];
            if ($mode === DestinationScope::MODE_ALL) {
                continue;
            }
            $seen = [];
            $resorts = is_array($entry['items'] ?? null) ? $entry['items'] : [];
            foreach ($resorts as $resort) {
                if (!is_scalar($resort)) {
                    continue;
                }
                $resort = trim((string) $resort);
                $rk = mb_strtoupper($resort);
                if ($resort === '' || isset($seen[$rk]) || mb_strlen($resort) > 100 || ConfigProvider::isResortExcluded($resort, $hidden)) {
                    continue;
                }
                $seen[$rk] = true;
                $rows[] = ['country' => $name, 'resort' => $resort, 'selection_type' => DestinationScope::MODE_SPECIFIC];
            }
        }

        return $rows;
    }
}
