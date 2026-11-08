<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Support;

use Tygh\Addons\TravelCore\Cron\CronJobPlan;

/**
 * A small provider's job list, as an add-on writes it to get the shared jobs
 * card: what CronJobPlanTest drives. Its endpoint names the job with
 * cron_mode (as Sphinx's does), not Novoton's mode.
 */
final class SampleJobPlan extends CronJobPlan
{
    public const string ADDON = 'sample_holidays';

    protected const string TITLE = 'Sample Holidays';

    protected const string CRON_DISPATCH = 'sample_cron.run';

    protected const string URL_MODE_PARAM = 'cron_mode';

    protected const string CLI_SCRIPT = 'app/addons/sample_holidays/cron.php';

    protected const string LABEL_PREFIX = 'sample_holidays.jobs_';

    /** @var array<string, list<string>> */
    public const array STAGES = [
        'reference' => ['destinations'],
        'catalogue' => ['hotels', 'circuits', 'geocode'],
        'upkeep' => ['order_status', 'cleanup'],
    ];

    /** @var array<string, string> */
    protected const array STAGE_TITLES = [
        'reference' => 'Reference data',
        'catalogue' => 'Catalogue',
        'upkeep' => 'Bookings and upkeep',
    ];

    /** @var array<string, array{cron: string, late_after_hours: int}> */
    protected const array SCHEDULES = [
        'destinations' => ['cron' => '0 2 * * 0', 'late_after_hours' => 2 * 168],
        'hotels' => ['cron' => '0 3 * * *', 'late_after_hours' => 48],
        'circuits' => ['cron' => '15 3 * * *', 'late_after_hours' => 48],
        'geocode' => ['cron' => '*/15 * * * *', 'late_after_hours' => 6],
        'order_status' => ['cron' => '*/15 * * * *', 'late_after_hours' => 2],
        'cleanup' => ['cron' => '0 5 * * *', 'late_after_hours' => 48],
    ];

    /** @var list<string> */
    public const array RECOMMENDED = ['hotels'];

    /** @var list<string> */
    public const array BATCHED = ['destinations'];

    /** @var list<string> */
    public const array HAS_STATUS = ['geocode'];

    /** @var list<string> */
    public const array REDO_ALL = ['geocode'];

    /** @var list<string> */
    public const array FORCE_FULL = ['hotels'];

    /** @var list<string> */
    public const array RESETTABLE = ['circuits'];

    /** @var array<string, array<string, string>> */
    protected const array JOB_PARAMS = [
        'geocode' => ['limit' => '100'],
    ];

    /** @var array<string, list<array{action: string, label: string, confirm?: string}>> */
    protected const array EXTRA_ACTIONS = [
        'hotels' => [['action' => 'retry_skipped', 'label' => 'sample_holidays.jobs_retry_skipped', 'confirm' => 'sample_holidays.jobs_retry_skipped_confirm']],
        'cleanup' => [['action' => 'retry_failed', 'label' => 'sample_holidays.jobs_retry_failed']],
    ];

    /** @var list<string> */
    public const array ON_DEMAND = ['full', 'reassign_features'];

    /** @var list<string> */
    public const array DIAGNOSTIC = ['diagnose_search'];

    /** @var array<string, list<string>> */
    protected const array LEGACY_LOG_TYPES = [
        'hotels' => ['hotels', 'hotel_sync'],
    ];
}
