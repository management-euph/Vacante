<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Services;

use Tygh\Addons\TravelCore\Cron\JobHealth;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * What the top of Eurosite's dashboard says: the four At a glance tiles and
 * Needs attention, as Sphinx's and Novoton's dashboards open.
 *
 * Each tile says whether its figure is a problem, and Needs attention lists
 * only what someone has to act on, each with the one thing that fixes it.
 * The rules for the jobs themselves are Travel Core's JobHealth, shared with
 * the other providers' dashboards.
 *
 * Pure: the controller gathers the figures, this decides what they mean.
 */
final class DashboardSummary
{
    /**
     * The four tiles: the hotels in the destinations sold, which of them have
     * an Immediate offer, the products, and the scheduled jobs.
     *
     * @param array{countries: list<array<string, mixed>>, totals: array<string, int>} $scope the destination whitelist, as DestinationsPicker::build() reads it
     * @param array{listed: int, availability: array<string, int>, products: int, hidden: int, immediate_without: int} $hotels the hotels listed (active), per availability code ('' = not checked yet), linked to a product, hidden for having no Immediate offer, Immediate without a product
     * @param list<array{jobs: list<array<string, mixed>>}> $stages CronPlanBuilder::stages()
     *
     * @return array{
     *     hotels: array{listed: int, where: string, cities: int, synced: int, warn: bool},
     *     offers: array{immediate: int, on_request: int, stop_sale: int, no_offer: int, unchecked: int, checked: int, warn: bool},
     *     products: array{linked: int, live: int, hidden: int, waiting: int},
     *     jobs: array{counts: array<string, int>, tone: string, jobs: int}
     * }
     */
    public static function glance(array $scope, array $hotels, array $stages): array
    {
        $sold = [];
        foreach ($scope['countries'] as $country) {
            if (TypeCoerce::toString($country['mode'] ?? DestinationsPicker::MODE_OFF) === DestinationsPicker::MODE_OFF) {
                continue;
            }
            $label = TypeCoerce::toString($country['label'] ?? '');
            $sold[] = $label !== '' ? $label : TypeCoerce::toString($country['code'] ?? '');
        }

        $listed = $hotels['listed'];
        $availability = $hotels['availability'];
        $immediate = $availability['IM'] ?? 0;
        $unchecked = $availability[''] ?? 0;

        return [
            // No hotel listed: nothing can be sold until destinations are
            // whitelisted and Hotels has run.
            'hotels' => [
                'listed' => $listed,
                'where' => implode(', ', $sold),
                'cities' => $scope['totals']['cities'] ?? 0,
                'synced' => self::lastRun($stages, 'hotels'),
                'warn' => $listed === 0,
            ],
            // Only a hotel with an Immediate offer becomes a product. None at
            // all, once the check has looked, means nothing can be sold.
            'offers' => [
                'immediate' => $immediate,
                'on_request' => $availability['OR'] ?? 0,
                'stop_sale' => $availability['ST'] ?? 0,
                'no_offer' => $availability['NONE'] ?? 0,
                'unchecked' => $unchecked,
                'checked' => self::lastRun($stages, 'availability'),
                'warn' => self::noImmediate($hotels),
            ],
            'products' => [
                'linked' => $hotels['products'],
                'live' => $scope['totals']['live'] ?? 0,
                'hidden' => $hotels['hidden'],
                'waiting' => $hotels['immediate_without'],
            ],
            'jobs' => JobHealth::counts($stages),
        ];
    }

    /**
     * What someone has to act on, most serious first:
     *
     * - a cron request refused for a wrong key (every scheduled run fails
     *   until the crontab gets the commands with the current key);
     * - no destination whitelisted (the hotel sync has nothing to fetch);
     * - the jobs JobHealth::attention() lists (failed, stalled, never run,
     *   late), each with its name's label and its schedule;
     * - hotels listed and checked, none with an Immediate offer (the probe
     *   finds the dates that have one).
     *
     * @param list<array{jobs: list<array<string, mixed>>}> $stages CronPlanBuilder::stages()
     * @param int $wrongKeyAt when a request with a wrong key was last refused, while that is still the news (CronHealth says refused); 0 for none
     * @param int $whitelisted destinations on the whitelist
     * @param array{listed: int, availability: array<string, int>, products: int, hidden: int, immediate_without: int} $hotels as glance() takes them
     *
     * @return list<array{kind: string, mode: string, at: int, error: string, n: int, name_key: string, schedule_key: string, schedule_params: array<string, string>}>
     */
    public static function attention(array $stages, int $wrongKeyAt, int $whitelisted, array $hotels): array
    {
        $items = [];
        if ($wrongKeyAt > 0) {
            $items[] = self::item('refused', $wrongKeyAt);
        }
        if ($whitelisted === 0) {
            $items[] = self::item('nothing_sold', 0);
        }
        $items = [...$items, ...JobHealth::attention($stages)];
        if (self::noImmediate($hotels)) {
            $items[] = self::item('no_immediate', 0);
        }

        return $items;
    }

    /**
     * Hotels are listed and the check has looked at some, but none has an Immediate offer.
     *
     * @param array{listed: int, availability: array<string, int>, products: int, hidden: int, immediate_without: int} $hotels
     */
    private static function noImmediate(array $hotels): bool
    {
        $availability = $hotels['availability'];

        return $hotels['listed'] > ($availability[''] ?? 0) && ($availability['IM'] ?? 0) === 0;
    }

    /**
     * When a job last ran (finished, or started for a run still open), 0 if
     * it never did: the row's own time, from the run log or the sync log.
     *
     * @param list<array{jobs: list<array<string, mixed>>}> $stages
     */
    private static function lastRun(array $stages, string $mode): int
    {
        foreach ($stages as $stage) {
            foreach ($stage['jobs'] as $job) {
                if (TypeCoerce::toString($job['mode'] ?? '') === $mode) {
                    return TypeCoerce::toInt($job['at'] ?? 0);
                }
            }
        }

        return 0;
    }

    /**
     * An item that is not a job's, in the shape JobHealth::attention() gives
     * the jobs' own.
     *
     * @return array{kind: string, mode: string, at: int, error: string, n: int, name_key: string, schedule_key: string, schedule_params: array<string, string>}
     */
    private static function item(string $kind, int $at): array
    {
        return [
            'kind' => $kind,
            'mode' => '',
            'at' => $at,
            'error' => '',
            'n' => 0,
            'name_key' => '',
            'schedule_key' => '',
            'schedule_params' => [],
        ];
    }
}
