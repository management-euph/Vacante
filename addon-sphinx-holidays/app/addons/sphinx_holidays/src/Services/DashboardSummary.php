<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Services;

use Tygh\Addons\TravelCore\Cron\JobHealth;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * What the top of Sphinx's dashboard says: the four At a glance tiles and
 * Needs attention.
 *
 * The page used to open on eleven coloured numbers (destinations, continents,
 * countries … linked products, unlinked hotels) whose colours meant nothing
 * and which never said which of them was a problem. Each tile now says
 * whether its figure is one, and Needs attention lists only what someone has
 * to act on, each with the one button that fixes it. The rules for the jobs
 * themselves are Travel Core's JobHealth, shared with Novoton's dashboard.
 *
 * Pure: the controller gathers the figures, this decides what they mean.
 */
final class DashboardSummary
{
    /**
     * The four tiles: hotels in the destinations sold, their products, the
     * hotels without one, and the scheduled jobs.
     *
     * @param array{total: int, linked: int, waiting: int, skipped: int} $hotels every stored hotel; linked to a product; waiting for an immediate offer; skipped
     * @param array{countries: list<array<string, mixed>>, totals: array<string, int>} $scope WhitelistPageLoader::built()
     * @param list<array{jobs: list<array<string, mixed>>}> $stages CronPlan::stages()
     *
     * @return array{
     *     hotels: array{in_scope: int, where: string, cities: int, warn: bool},
     *     products: array{linked: int, live: int},
     *     unlinked: array{count: int, total: int, waiting: int, skipped: int, ready: int, warn: bool},
     *     jobs: array{counts: array<string, int>, tone: string, jobs: int}
     * }
     */
    public static function glance(array $hotels, array $scope, array $stages): array
    {
        $sold = [];
        foreach ($scope['countries'] as $country) {
            if (TypeCoerce::toString($country['mode'] ?? DestinationsPicker::MODE_OFF) === DestinationsPicker::MODE_OFF) {
                continue;
            }
            $label = TypeCoerce::toString($country['label'] ?? '');
            $sold[] = $label !== '' ? $label : TypeCoerce::toString($country['code'] ?? '');
        }
        $inScope = $scope['totals']['hotels'] ?? 0;

        $without = max(0, $hotels['total'] - $hotels['linked']);

        return [
            // No hotel in the destinations sold: nothing can be sold until
            // the destinations are picked and Hotels has run.
            'hotels' => [
                'in_scope' => $inScope,
                'where' => implode(', ', $sold),
                'cities' => $scope['totals']['cities'] ?? 0,
                'warn' => $inScope === 0,
            ],
            'products' => [
                'linked' => $hotels['linked'],
                'live' => $scope['totals']['live'] ?? 0,
            ],
            // Waiting for an immediate offer is the availability gate working;
            // a skipped hotel needs someone (Retry skipped hotels).
            'unlinked' => [
                'count' => $without,
                'total' => $hotels['total'],
                'waiting' => $hotels['waiting'],
                'skipped' => $hotels['skipped'],
                'ready' => max(0, $without - $hotels['waiting'] - $hotels['skipped']),
                'warn' => $hotels['skipped'] > 0,
            ],
            'jobs' => JobHealth::counts($stages),
        ];
    }

    /**
     * What someone has to act on, most serious first:
     *
     * - a cron request refused for a wrong key (every scheduled run fails
     *   until the crontab gets the commands with the current key);
     * - the jobs JobHealth::problems() lists (failed, stalled, never run,
     *   late), each with its name's label and its schedule, for "is late";
     * - hotels skipped with no product.
     *
     * @param list<array{jobs: list<array<string, mixed>>}> $stages CronPlan::stages()
     * @param int $wrongKeyAt when a request with a wrong key was last refused, while that is still the news (CronHealth says refused); 0 for none
     *
     * @return list<array{kind: string, mode: string, at: int, error: string, n: int, name_key: string, schedule_key: string, schedule_params: array<string, string>}>
     */
    public static function attention(array $stages, int $skipped, int $wrongKeyAt): array
    {
        $items = [];
        if ($wrongKeyAt > 0) {
            $items[] = self::item('refused', '', $wrongKeyAt);
        }

        $rows = [];
        foreach ($stages as $stage) {
            foreach ($stage['jobs'] as $job) {
                $params = [];
                foreach (TypeCoerce::toStringMap($job['schedule_params'] ?? []) as $name => $value) {
                    $params[$name] = TypeCoerce::toString($value);
                }
                $rows[TypeCoerce::toString($job['mode'] ?? '')] = [TypeCoerce::toString($job['name_key'] ?? ''), TypeCoerce::toString($job['schedule_key'] ?? ''), $params];
            }
        }
        foreach (JobHealth::problems($stages) as $problem) {
            [$nameKey, $scheduleKey, $params] = $rows[$problem['mode']] ?? ['', '', []];
            $items[] = self::item($problem['kind'], $problem['mode'], $problem['at'], $problem['error'], 0, $nameKey, $scheduleKey, $params);
        }

        if ($skipped > 0) {
            $items[] = self::item('skipped', '', 0, '', $skipped);
        }

        return $items;
    }

    /**
     * Queue images for a scope's country list: the countries sold, by their
     * names in the destination tree (the code until the tree has the country).
     *
     * @param list<string> $selected ConfigProvider::getSelectedCountryCodes()
     * @param list<array<string, mixed>> $countries WhitelistPageLoader::built()['countries']
     *
     * @return list<array{code: string, name: string}>
     */
    public static function scopeCountries(array $selected, array $countries): array
    {
        $names = [];
        foreach ($countries as $country) {
            $names[TypeCoerce::toString($country['code'] ?? '')] = TypeCoerce::toString($country['label'] ?? '');
        }

        $out = [];
        foreach ($selected as $code) {
            $name = $names[$code] ?? '';
            $out[] = ['code' => $code, 'name' => $name !== '' ? $name : $code];
        }

        return $out;
    }

    /**
     * @param array<string, string> $scheduleParams
     *
     * @return array{kind: string, mode: string, at: int, error: string, n: int, name_key: string, schedule_key: string, schedule_params: array<string, string>}
     */
    private static function item(string $kind, string $mode, int $at, string $error = '', int $n = 0, string $nameKey = '', string $scheduleKey = '', array $scheduleParams = []): array
    {
        return [
            'kind' => $kind,
            'mode' => $mode,
            'at' => $at,
            'error' => $error,
            'n' => $n,
            'name_key' => $nameKey,
            'schedule_key' => $scheduleKey,
            'schedule_params' => $scheduleParams,
        ];
    }
}
