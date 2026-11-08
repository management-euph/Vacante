<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Addons\TravelCore\Cron\CronJobPlan;

/**
 * Everything the dashboard says about Novoton's scheduled jobs, in one place.
 *
 * The dashboard used to describe the same jobs three times — two
 * "recommended" panels, an XML panel and a 15-row table of URLs — each URL
 * carrying the shared cron key in plain text, with schedules as free text
 * ("Before Add Products") and no word on whether a job had run. This builder
 * gives ONE row per job, in the order the jobs depend on each other, with its
 * schedule, how it last went, and its URL and CLI commands.
 *
 * Novoton's job list only: the rows, the schedule words, the "late" rule and
 * the crontab text are Travel Core's CronJobPlan, shared with the other
 * providers' dashboards, and so are the templates that draw them.
 */
final class CronPlanBuilder extends CronJobPlan
{
    public const string ADDON = 'novoton_holidays';

    protected const string TITLE = 'Novoton Holidays';

    protected const string CRON_DISPATCH = 'novoton_cron.run';

    protected const string URL_MODE_PARAM = 'mode';

    protected const string CLI_SCRIPT = 'app/addons/novoton_holidays/cron.php';

    /** Job and stage labels: novoton_holidays.dash_job_<mode>, …_desc, novoton_holidays.dash_stage_<stage>. */
    protected const string LABEL_PREFIX = 'novoton_holidays.dash_';

    /**
     * The order the jobs run in: each stage needs the one before it.
     *
     * Read from each command's prerequisites — hotel info and prices need the
     * hotel list; add_hotels_as_products needs room_price ("run mode=room_price
     * first — it sets has_room_price=Y"); offers_update needs the baseline that
     * add_hotels_as_products records; reassign_features and backfill_images
     * work on linked products.
     *
     * @var array<string, list<string>>
     */
    public const array STAGES = [
        'reference' => ['resort_list', 'list_facilities'],
        'hotels' => ['hotel_list', 'hotel_info_batched', 'hotel_facilities_batched', 'refresh_images', 'geocode_addresses'],
        'prices' => ['sync_priceinfo_batched', 'compute_prices', 'recompute_calendar_prices', 'room_price'],
        'products' => ['add_hotels_as_products', 'reassign_features', 'backfill_images', 'offers_update'],
        'upkeep' => ['resinfo', 'cleanup'],
    ];

    /**
     * Stage names in the crontab block's comments (the page prints them from labels).
     *
     * @var array<string, string>
     */
    protected const array STAGE_TITLES = [
        'reference' => 'Reference data',
        'hotels' => 'Hotels',
        'prices' => 'Prices',
        'products' => 'Products',
        'upkeep' => 'Bookings and upkeep',
    ];

    /**
     * The two jobs the old page singled out as "recommended": a badge on their row now.
     *
     * @var list<string>
     */
    public const array RECOMMENDED = ['hotel_info_batched', 'sync_priceinfo_batched'];

    /**
     * Batched jobs, which also answer status=1, force_full=1 and reset=1.
     *
     * @var list<string>
     */
    public const array BATCHED = ['hotel_info_batched', 'sync_priceinfo_batched', 'hotel_facilities_batched'];

    /**
     * Jobs that answer status=1 without being batched (they drain a backlog).
     *
     * @var list<string>
     */
    public const array HAS_STATUS = ['geocode_addresses', 'backfill_images', 'backfill_descriptions'];

    /**
     * Backlog jobs that answer force=1: redo every hotel, also the ones already done.
     *
     * @var list<string>
     */
    public const array REDO_ALL = ['geocode_addresses'];

    /**
     * Jobs that are real but not part of the schedule: `full` repeats jobs the
     * stages already run, and the alternative-request jobs are driven by
     * bookings. backfill_descriptions is a one-off recovery for products
     * created before descriptions were read correctly. Listed separately,
     * with Run and the commands, no schedule.
     *
     * @var list<string>
     */
    public const array ON_DEMAND = ['full', 'alternative_rs', 'alternative_rs_bookings', 'notify_alternatives', 'expire_requests', 'backfill_descriptions'];

    /**
     * One-hotel diagnostics (they need hotel_id=…): never on the dashboard.
     *
     * @var list<string>
     */
    public const array DIAGNOSTIC = ['diagnose_features', 'diagnose_image_urls', 'diagnose_hotel_facilities'];

    /**
     * Suggested slot per job, and after how many hours without a finished run
     * it counts as late. Daily times follow the stage order; the jobs that
     * work through a backlog run every few minutes and resume where they
     * stopped.
     *
     * Novoton's answers (28 Oct 2026) set them: bulk work between 01:00 and
     * 06:00 but never 03:00–04:00, their busiest hour (the 03:30 calendar
     * rebuild calls nobody); hotel_list every 3 days, resort_list and
     * list_facilities weekly; offers_update every 12 hours; resinfo every
     * 15–30 minutes. The hotel-details and season-price passes run every
     * 5 minutes but start their full passes at 05:00 (FullPassSchedule).
     * Hotel facilities moved from 02:30 to 01:45 so a long pass ends before 03:00.
     * Hotel photos (refresh_images, weekly per hotel as Novoton suggested)
     * take a seventh of the hotels each night at 02:15 and stop before 03:00.
     *
     * @var array<string, array{cron: string, late_after_hours: int}>
     */
    protected const array SCHEDULES = [
        'resort_list' => ['cron' => '0 1 * * 0', 'late_after_hours' => 2 * 168],
        'list_facilities' => ['cron' => '15 1 * * 0', 'late_after_hours' => 2 * 168],
        'hotel_list' => ['cron' => '30 1 */3 * *', 'late_after_hours' => 168],
        'hotel_info_batched' => ['cron' => '*/5 * * * *', 'late_after_hours' => 2],
        'hotel_facilities_batched' => ['cron' => '45 1 * * 0', 'late_after_hours' => 2 * 168],
        'refresh_images' => ['cron' => '15 2 * * *', 'late_after_hours' => 48],
        'geocode_addresses' => ['cron' => '45 * * * *', 'late_after_hours' => 6],
        'sync_priceinfo_batched' => ['cron' => '*/5 * * * *', 'late_after_hours' => 2],
        'compute_prices' => ['cron' => '*/5 * * * *', 'late_after_hours' => 2],
        'recompute_calendar_prices' => ['cron' => '30 3 * * *', 'late_after_hours' => 48],
        'room_price' => ['cron' => '0 4 * * *', 'late_after_hours' => 48],
        'add_hotels_as_products' => ['cron' => '30 4 * * *', 'late_after_hours' => 48],
        'reassign_features' => ['cron' => '0 5 * * *', 'late_after_hours' => 48],
        'backfill_images' => ['cron' => '50 * * * *', 'late_after_hours' => 6],
        'offers_update' => ['cron' => '0 5,17 * * *', 'late_after_hours' => 14],
        'resinfo' => ['cron' => '*/15 * * * *', 'late_after_hours' => 2],
        'cleanup' => ['cron' => '0 6 * * *', 'late_after_hours' => 48],
    ];

    /**
     * sync_log types a job wrote under before Travel Core's run log existed,
     * so a job that ran last week does not read "no runs yet" on the day this
     * ships. The run log wins whenever it has a record.
     *
     * @var array<string, list<string>>
     */
    protected const array LEGACY_LOG_TYPES = [
        'hotel_info_batched' => ['hotel_info_batched', 'hotelinfo'],
        'sync_priceinfo_batched' => ['sync_priceinfo_batched', 'sync_priceinfo'],
        'list_facilities' => ['list_facilities', 'facilities'],
        'hotel_facilities_batched' => ['hotel_facilities_batched', 'hotel_facilities'],
    ];
}
