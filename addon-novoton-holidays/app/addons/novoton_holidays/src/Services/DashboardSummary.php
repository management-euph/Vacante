<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * What the top of the dashboard says: the job-health tile, the "Needs
 * attention" strip, and the excluded-resorts list with its counts.
 *
 * The old page showed numbers without saying which of them were a problem —
 * "Season prices available: 0" of 1,147 hotels looked like any other number,
 * and "Hotel Info: Never" sat in a list of dates. This decides, from the
 * figures the controller gathered, what needs doing.
 *
 * Pure: arrays in, arrays out, so every rule is unit-tested.
 */
final class DashboardSummary
{
    /** Most serious first: the strip lists items in this order. */
    private const array SEVERITY = ['failed' => 0, 'stalled' => 1, 'never' => 2, 'late' => 3, 'no_season_prices' => 4];

    /**
     * How many jobs are in each state, and the tile's overall tone.
     *
     * @param list<array{jobs: list<array<string, mixed>>}> $stages CronPlanBuilder::stages()
     *
     * @return array{counts: array<string, int>, tone: string, jobs: int}
     */
    public static function jobHealth(array $stages): array
    {
        $counts = ['ok' => 0, 'late' => 0, 'failed' => 0, 'running' => 0, 'stalled' => 0, 'never' => 0];
        $jobs = 0;
        foreach ($stages as $stage) {
            foreach ($stage['jobs'] as $job) {
                $state = TypeCoerce::toString($job['state'] ?? 'never');
                $counts[$state] = ($counts[$state] ?? 0) + 1;
                $jobs++;
            }
        }

        $tone = match (true) {
            $counts['failed'] + $counts['stalled'] > 0 => 'bad',
            $counts['late'] + $counts['never'] > 0 => 'warn',
            default => 'ok',
        };

        return ['counts' => $counts, 'tone' => $tone, 'jobs' => $jobs];
    }

    /**
     * The problems worth acting on, each with the job whose Run fixes it.
     *
     * - any job that failed or stalled;
     * - a RECOMMENDED job that never ran (the other jobs start "no runs yet"
     *   on a new store, and listing all sixteen would bury the two that matter);
     * - any job that is late for its own interval;
     * - hotels without season prices while the price job has run fine.
     *
     * @param list<array{jobs: list<array<string, mixed>>}> $stages
     * @param array{total: int, with_packages: int} $hotels
     *
     * @return list<array{kind: string, mode: string, at: int, error: string, hotels_total: int, hotels_with_season: int}>
     */
    public static function attention(array $stages, array $hotels): array
    {
        $items = [];
        $order = 0;
        $priceJob = null;

        foreach ($stages as $stage) {
            foreach ($stage['jobs'] as $job) {
                $order++;
                $mode = TypeCoerce::toString($job['mode'] ?? '');
                $state = TypeCoerce::toString($job['state'] ?? '');
                if ($mode === 'sync_priceinfo_batched') {
                    $priceJob = $state;
                }

                $kind = match (true) {
                    in_array($state, ['failed', 'stalled', 'late'], true) => $state,
                    $state === 'never' && ($job['recommended'] ?? false) === true => 'never',
                    default => null,
                };
                if ($kind === null) {
                    continue;
                }
                $items[] = self::item($kind, $mode, TypeCoerce::toInt($job['at'] ?? 0), TypeCoerce::toString($job['error'] ?? ''), $hotels, $order);
            }
        }

        // Season prices missing while the job that fetches them runs fine is
        // its own problem; when that job is the reason, its own item says so.
        if ($hotels['total'] > 0 && $hotels['with_packages'] === 0 && $priceJob !== null && in_array($priceJob, ['ok', 'running'], true)) {
            $items[] = self::item('no_season_prices', 'sync_priceinfo_batched', 0, '', $hotels, PHP_INT_MAX);
        }

        usort($items, static fn (array $a, array $b): int => [self::SEVERITY[$a['kind']], $a['order']] <=> [self::SEVERITY[$b['kind']], $b['order']]);

        return array_map(static function (array $i): array {
            unset($i['order']);

            return $i;
        }, $items);
    }

    /**
     * Resorts grouped by country, with hotel and product counts and whether
     * each is excluded — what the list needs to say what excluding one affects.
     *
     * Hidden resorts (internal ones such as the gift voucher "resort") are
     * left out, as the page always did. Matching is case-insensitive: the
     * setting and the hotel rows do not always agree on case.
     *
     * @param list<array{country: string, city: string, hotels: int, products: int}> $counts
     * @param array<string> $hidden
     * @param array<string> $excluded
     *
     * @return array{countries: list<array<string, mixed>>, resorts: int, excluded: int}
     */
    public static function resorts(array $counts, array $hidden, array $excluded): array
    {
        $hiddenUpper = array_map('mb_strtoupper', $hidden);
        $excludedUpper = array_map('mb_strtoupper', $excluded);

        $byCountry = [];
        foreach ($counts as $row) {
            $city = $row['city'];
            if (in_array(mb_strtoupper($city), $hiddenUpper, true)) {
                continue;
            }
            $isExcluded = in_array(mb_strtoupper($city), $excludedUpper, true);
            $byCountry[$row['country']][] = [
                'name' => $city,
                'label' => self::displayName($city),
                'hotels' => $row['hotels'],
                'products' => $row['products'],
                'excluded' => $isExcluded,
            ];
        }

        $countries = [];
        $resorts = 0;
        $excludedCount = 0;
        foreach ($byCountry as $country => $list) {
            $countryExcluded = count(array_filter($list, static fn (array $r): bool => $r['excluded']));
            $countries[] = [
                'country' => (string) $country,
                'label' => self::displayName((string) $country),
                'resorts' => $list,
                'total' => count($list),
                'hotels' => array_sum(array_column($list, 'hotels')),
                'excluded' => $countryExcluded,
            ];
            $resorts += count($list);
            $excludedCount += $countryExcluded;
        }

        // Largest country first: it is where most of the ticking happens.
        usort($countries, static fn (array $a, array $b): int => $b['total'] <=> $a['total'] ?: strcmp($a['country'], $b['country']));

        return ['countries' => $countries, 'resorts' => $resorts, 'excluded' => $excludedCount];
    }

    /**
     * "ST.CONSTANTINE & ELENA" → "St. Constantine & Elena": the API sends
     * names in capitals, which are hard to scan in a list of 76.
     */
    public static function displayName(string $name): string
    {
        $s = mb_strtolower(trim($name));
        $s = (string) preg_replace_callback('/(^|[\s.&\-])(\p{Ll})/u', static fn (array $m): string => $m[1] . mb_strtoupper($m[2]), $s);

        return (string) preg_replace('/\.(?=\S)/u', '. ', $s);
    }

    /**
     * @param array{total: int, with_packages: int} $hotels
     *
     * @return array{kind: string, mode: string, at: int, error: string, hotels_total: int, hotels_with_season: int, order: int}
     */
    private static function item(string $kind, string $mode, int $at, string $error, array $hotels, int $order): array
    {
        return [
            'kind' => $kind,
            'mode' => $mode,
            'at' => $at,
            'error' => $error,
            'hotels_total' => $hotels['total'],
            'hotels_with_season' => $hotels['with_packages'],
            'order' => $order,
        ];
    }
}
