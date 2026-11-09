<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Cron;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * What a provider's dashboard says about its scheduled jobs at the top of the
 * page: how many jobs are in each state (the job-health tile) and which of
 * them someone has to act on (Needs attention), from the rows
 * CronJobPlan::stages() built.
 *
 * Novoton's dashboard had these rules on its own; Sphinx's shows the same
 * tile and list, so they live here once.
 *
 * Pure: arrays in, arrays out.
 */
final class JobHealth
{
    /** Most serious first: problems() lists jobs in this order, then in the order they run. */
    public const array SEVERITY = ['failed' => 0, 'stalled' => 1, 'never' => 2, 'late' => 3];

    /**
     * How many jobs are in each state, and the tile's overall tone: bad when a
     * job failed or stalled, warn when one is late or has no runs yet.
     *
     * @param list<array{jobs: list<array<string, mixed>>}> $stages CronJobPlan::stages()
     *
     * @return array{counts: array<string, int>, tone: string, jobs: int}
     */
    public static function counts(array $stages): array
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
     * The jobs worth acting on, each one fixed by its own Run, most serious
     * first (SEVERITY), then in the order they run:
     *
     * - a job that failed or stalled;
     * - a RECOMMENDED job that never ran (the others start with "no runs yet"
     *   on a new store, and listing them all would bury the ones that matter);
     * - a job that is late for its own interval.
     *
     * @param list<array{jobs: list<array<string, mixed>>}> $stages CronJobPlan::stages()
     *
     * @return list<array{kind: string, mode: string, at: int, error: string}>
     */
    public static function problems(array $stages): array
    {
        $items = [];
        $order = 0;
        foreach ($stages as $stage) {
            foreach ($stage['jobs'] as $job) {
                $order++;
                $state = TypeCoerce::toString($job['state'] ?? '');
                $kind = match (true) {
                    in_array($state, ['failed', 'stalled', 'late'], true) => $state,
                    $state === 'never' && ($job['recommended'] ?? false) === true => 'never',
                    default => null,
                };
                if ($kind === null) {
                    continue;
                }
                $items[] = [
                    'kind' => $kind,
                    'mode' => TypeCoerce::toString($job['mode'] ?? ''),
                    'at' => TypeCoerce::toInt($job['at'] ?? 0),
                    'error' => TypeCoerce::toString($job['error'] ?? ''),
                    'order' => $order,
                ];
            }
        }

        usort($items, static fn (array $a, array $b): int => [self::SEVERITY[$a['kind']], $a['order']] <=> [self::SEVERITY[$b['kind']], $b['order']]);

        return array_map(
            static fn (array $i): array => ['kind' => $i['kind'], 'mode' => $i['mode'], 'at' => $i['at'], 'error' => $i['error']],
            $items,
        );
    }

    /**
     * The problems() as Needs attention prints them: each with its job's
     * name label and its schedule in words (for "is late"), in the shape the
     * dashboards' other attention items share (n is their count, 0 here).
     *
     * @param list<array{jobs: list<array<string, mixed>>}> $stages CronJobPlan::stages()
     *
     * @return list<array{kind: string, mode: string, at: int, error: string, n: int, name_key: string, schedule_key: string, schedule_params: array<string, string>}>
     */
    public static function attention(array $stages): array
    {
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

        $items = [];
        foreach (self::problems($stages) as $problem) {
            [$nameKey, $scheduleKey, $params] = $rows[$problem['mode']] ?? ['', '', []];
            $items[] = [
                'kind' => $problem['kind'],
                'mode' => $problem['mode'],
                'at' => $problem['at'],
                'error' => $problem['error'],
                'n' => 0,
                'name_key' => $nameKey,
                'schedule_key' => $scheduleKey,
                'schedule_params' => $params,
            ];
        }

        return $items;
    }
}
