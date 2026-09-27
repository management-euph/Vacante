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
     * The Destinations lines for Needs attention, each linking to the page:
     * resorts new since the last Save (per country, "Only selected" ones
     * wait for review), whitelisted resorts gone from the feed, and live
     * products outside the whitelist.
     *
     * @param array{countries: list<array<string, mixed>>, totals: array<string, int>} $destinations DestinationsPage::build()
     *
     * @return list<array{kind: string, country: string, n: int}>
     */
    public static function destinationAlerts(array $destinations): array
    {
        $alerts = [];
        foreach ($destinations['countries'] as $c) {
            $n = is_int($c['new'] ?? null) ? $c['new'] : 0;
            if ($n > 0) {
                $alerts[] = ['kind' => 'new', 'country' => is_string($c['label'] ?? null) ? $c['label'] : '', 'n' => $n];
            }
        }
        if (($destinations['totals']['gone'] ?? 0) > 0) {
            $alerts[] = ['kind' => 'gone', 'country' => '', 'n' => $destinations['totals']['gone']];
        }
        if (($destinations['totals']['outside'] ?? 0) > 0) {
            $alerts[] = ['kind' => 'outside', 'country' => '', 'n' => $destinations['totals']['outside']];
        }

        return $alerts;
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
