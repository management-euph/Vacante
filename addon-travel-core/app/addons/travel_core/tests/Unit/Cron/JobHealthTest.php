<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Cron;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Cron\JobHealth;

/**
 * The job-health tile and the jobs in Needs attention, on the Novoton and
 * Sphinx dashboards alike.
 */
final class JobHealthTest extends TestCase
{
    /**
     * Stages as CronJobPlan::stages() gives them, from [mode, state, extra] per job.
     *
     * @param list<list<array{0: string, 1: string, 2?: array<string, mixed>}>> $stages
     *
     * @return list<array{jobs: list<array<string, mixed>>}>
     */
    private static function stages(array $stages): array
    {
        $out = [];
        foreach ($stages as $i => $jobs) {
            $rows = [];
            foreach ($jobs as $job) {
                $rows[] = ['mode' => $job[0], 'state' => $job[1], 'recommended' => false, 'at' => 0, 'error' => '', ...($job[2] ?? [])];
            }
            $out[] = ['stage' => 'stage' . $i, 'number' => $i + 1, 'title_key' => '', 'jobs' => $rows];
        }

        return $out;
    }

    public function testTheTileCountsEveryStateAndSaysHowBad(): void
    {
        $health = JobHealth::counts(self::stages([
            [['a', 'ok'], ['b', 'ok'], ['c', 'late']],
            [['d', 'never'], ['e', 'running']],
        ]));

        self::assertSame(['ok' => 2, 'late' => 1, 'failed' => 0, 'running' => 1, 'stalled' => 0, 'never' => 1], $health['counts']);
        self::assertSame(5, $health['jobs']);
        self::assertSame('warn', $health['tone']);
    }

    public function testAFailedOrStalledJobMakesTheTileBadAndNothingWrongMakesItOk(): void
    {
        self::assertSame('bad', JobHealth::counts(self::stages([[['a', 'ok'], ['b', 'failed']]]))['tone']);
        self::assertSame('bad', JobHealth::counts(self::stages([[['a', 'stalled'], ['b', 'late']]]))['tone']);
        self::assertSame('ok', JobHealth::counts(self::stages([[['a', 'ok'], ['b', 'running']]]))['tone']);
        self::assertSame('ok', JobHealth::counts([])['tone'], 'a plan with no jobs has nothing wrong');
    }

    /** Failed, stalled, never run, late: most serious first, then in the order the jobs run. */
    public function testProblemsComeMostSeriousFirstThenInRunningOrder(): void
    {
        $problems = JobHealth::problems(self::stages([
            [['first_late', 'late', ['at' => 100]], ['ok_job', 'ok']],
            [['failing', 'failed', ['at' => 200, 'error' => 'API down']], ['second_late', 'late', ['at' => 300]]],
            [['stuck', 'stalled', ['at' => 400]], ['new_recommended', 'never', ['recommended' => true]]],
        ]));

        self::assertSame([
            ['kind' => 'failed', 'mode' => 'failing', 'at' => 200, 'error' => 'API down'],
            ['kind' => 'stalled', 'mode' => 'stuck', 'at' => 400, 'error' => ''],
            ['kind' => 'never', 'mode' => 'new_recommended', 'at' => 0, 'error' => ''],
            ['kind' => 'late', 'mode' => 'first_late', 'at' => 100, 'error' => ''],
            ['kind' => 'late', 'mode' => 'second_late', 'at' => 300, 'error' => ''],
        ], $problems);
    }

    /** On a new store every job starts with no runs: only the recommended ones are worth a line. */
    public function testAJobThatNeverRanIsAProblemOnlyWhenRecommended(): void
    {
        self::assertSame([], JobHealth::problems(self::stages([[['a', 'never'], ['b', 'running'], ['c', 'ok']]])));
        self::assertSame('never', JobHealth::problems(self::stages([[['a', 'never', ['recommended' => true]]]]))[0]['kind']);
    }

    /**
     * Needs attention prints each problem with its job's name label and its
     * schedule in words, in the shape the dashboards' other items share.
     */
    public function testAttentionCarriesEachJobsNameAndSchedule(): void
    {
        $items = JobHealth::attention(self::stages([
            [['hotels', 'late', ['at' => 1_700_000_000, 'name_key' => 'eurosite.jobs_job_hotels', 'schedule_key' => 'travel_core.jobs_sched_weekly_sun', 'schedule_params' => ['[time]' => '03:00']]]],
            [['cleanup', 'failed', ['at' => 1_700_100_000, 'error' => 'disk full', 'name_key' => 'eurosite.jobs_job_cleanup']], ['tags', 'ok']],
        ]));

        self::assertSame([
            ['kind' => 'failed', 'mode' => 'cleanup', 'at' => 1_700_100_000, 'error' => 'disk full', 'n' => 0, 'name_key' => 'eurosite.jobs_job_cleanup', 'schedule_key' => '', 'schedule_params' => []],
            ['kind' => 'late', 'mode' => 'hotels', 'at' => 1_700_000_000, 'error' => '', 'n' => 0, 'name_key' => 'eurosite.jobs_job_hotels', 'schedule_key' => 'travel_core.jobs_sched_weekly_sun', 'schedule_params' => ['[time]' => '03:00']],
        ], $items);
        self::assertSame([], JobHealth::attention(self::stages([[['a', 'ok'], ['b', 'running']]])));
    }
}
