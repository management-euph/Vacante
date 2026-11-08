<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Cron;

use Tygh\Addons\TravelCore\Cron\CronJobPlan;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Sphinx's scheduled jobs, as its dashboard shows them on Travel Core's shared
 * jobs card (one row per job, Run, the ⋯ menu, the commands with the key
 * masked), and what a Run from the dashboard may pass to each job.
 *
 * The dashboard used to list the jobs as a table of URLs, each carrying the
 * shared cron key in plain text, with Run links that put it in the browser's
 * history. The schedules below are the ones that table gave; the order is what
 * each job needs first: hotels need the destination tree, products need the
 * hotels, the location jobs flag a hotel whose City or Region changed and
 * update_products carries it onto the product, and images are queued from the
 * hotels' image lists, then downloaded.
 */
final class CronPlan extends CronJobPlan
{
    public const string ADDON = 'sphinx_holidays';

    protected const string TITLE = 'Sphinx Holidays';

    protected const string CRON_DISPATCH = 'sphinx_cron.run';

    protected const string URL_MODE_PARAM = 'cron_mode';

    protected const string CLI_SCRIPT = 'app/addons/sphinx_holidays/cron.php';

    /** Job and stage labels: sphinx_holidays.jobs_job_<mode>, …_desc, sphinx_holidays.jobs_stage_<stage>. */
    protected const string LABEL_PREFIX = 'sphinx_holidays.jobs_';

    /**
     * The order the jobs run in: each stage needs the one before it.
     *
     * @var array<string, list<string>>
     */
    public const array STAGES = [
        'reference' => ['destinations'],
        'catalogue' => ['hotels', 'circuits', 'package_routes'],
        'products' => ['add_products', 'discover_boards', 'assign_boards'],
        'locations' => ['backfill_hotel_locations', 'geocode_hotels', 'update_products'],
        'images' => ['enrich_hotel_data', 'sync_images', 'process_image_queue'],
        'upkeep' => ['order_status', 'cleanup'],
    ];

    /**
     * Stage names in the crontab block's comments (the page prints them from labels).
     *
     * @var array<string, string>
     */
    protected const array STAGE_TITLES = [
        'reference' => 'Reference data',
        'catalogue' => 'Catalogue',
        'products' => 'Products',
        'locations' => 'Locations and product updates',
        'images' => 'Images',
        'upkeep' => 'Bookings and upkeep',
    ];

    /**
     * Each job's slot, as the dashboard listed it, and when it counts as late:
     * a weekly job after two weeks, a daily one after two days, a job that
     * runs every few minutes after two hours.
     *
     * @var array<string, array{cron: string, late_after_hours: int}>
     */
    protected const array SCHEDULES = [
        'destinations' => ['cron' => '0 2 * * 0', 'late_after_hours' => 2 * 168],
        'hotels' => ['cron' => '0 3 * * *', 'late_after_hours' => 48],
        'circuits' => ['cron' => '15 3 * * *', 'late_after_hours' => 48],
        'package_routes' => ['cron' => '0 4 * * 1', 'late_after_hours' => 2 * 168],
        'add_products' => ['cron' => '30 3 * * *', 'late_after_hours' => 48],
        'discover_boards' => ['cron' => '0 3 * * *', 'late_after_hours' => 48],
        'assign_boards' => ['cron' => '30 3 * * *', 'late_after_hours' => 48],
        'backfill_hotel_locations' => ['cron' => '30 4 * * *', 'late_after_hours' => 48],
        'geocode_hotels' => ['cron' => '*/15 * * * *', 'late_after_hours' => 2],
        'update_products' => ['cron' => '0 6 * * *', 'late_after_hours' => 48],
        'enrich_hotel_data' => ['cron' => '*/5 * * * *', 'late_after_hours' => 2],
        'sync_images' => ['cron' => '0 4 * * *', 'late_after_hours' => 48],
        'process_image_queue' => ['cron' => '*/2 * * * *', 'late_after_hours' => 2],
        'order_status' => ['cron' => '*/15 * * * *', 'late_after_hours' => 2],
        'cleanup' => ['cron' => '0 5 * * *', 'late_after_hours' => 48],
    ];

    /**
     * Parameters every run of a job carries: geocoding takes 100 hotels a run
     * (OpenStreetMap allows one request a second).
     *
     * @var array<string, array<string, string>>
     */
    protected const array JOB_PARAMS = [
        'geocode_hotels' => ['limit' => '100'],
    ];

    /**
     * Resumable, with a full mode: Check status, Force full sync, Reset progress.
     *
     * @var list<string>
     */
    public const array BATCHED = ['destinations'];

    /**
     * A full re-read (full=1), nothing to resume.
     *
     * @var list<string>
     */
    public const array FORCE_FULL = ['hotels', 'circuits'];

    /**
     * Resumable (reset=1), no full mode.
     *
     * @var list<string>
     */
    public const array RESETTABLE = ['add_products', 'discover_boards', 'assign_boards'];

    /**
     * Jobs that answer status=1 without being batched.
     *
     * @var list<string>
     */
    public const array HAS_STATUS = ['add_products', 'discover_boards', 'assign_boards', 'geocode_hotels'];

    /**
     * force=1: every hotel again, also the ones already done.
     *
     * @var list<string>
     */
    public const array REDO_ALL = ['geocode_hotels'];

    /**
     * Sphinx's own ⋯ menu items: retry the hotels Create products skipped,
     * queue every hotel's images again, retry the downloads that failed.
     *
     * @var array<string, list<array{action: string, label: string, confirm?: string}>>
     */
    protected const array EXTRA_ACTIONS = [
        'add_products' => [['action' => 'retry_skipped', 'label' => 'sphinx_holidays.retry_skipped', 'confirm' => 'sphinx_holidays.retry_skipped_confirm']],
        'sync_images' => [['action' => 'requeue', 'label' => 'sphinx_holidays.jobs_requeue_images', 'confirm' => 'sphinx_holidays.jobs_requeue_images_confirm']],
        'process_image_queue' => [['action' => 'retry_failed', 'label' => 'sphinx_holidays.jobs_retry_failed_images']],
    ];

    /**
     * Real jobs with no place in the schedule: `full` repeats the staged
     * jobs; the others are one-off repairs and reports.
     *
     * @var list<string>
     */
    public const array ON_DEMAND = ['reassign_features', 'full', 'add_circuit_products', 'experiences', 'calendar_prices', 'sync_and_upload_images', 'deduplicate', 'audit_facilities'];

    /**
     * One-hotel checks: run from Check one hotel, never listed as jobs.
     *
     * @var list<string>
     */
    public const array DIAGNOSTIC = ['diagnose_search', 'diagnose_images', 'diagnose_seo', 'diagnose_product_features'];

    /**
     * What each ⋯ menu action sends to the command: the flag it reads, with
     * the value it expects (1, or Y for the image jobs).
     *
     * @var array<string, array<string, string>>
     */
    public const array ACTION_PARAMS = [
        'status' => ['status' => '1'],
        'reset' => ['reset' => '1'],
        'force_full' => ['full' => '1'],
        'force' => ['force' => '1'],
        'retry_skipped' => ['retry_skipped' => '1'],
        'requeue' => ['force' => 'Y'],
        'retry_failed' => ['reset_failed' => 'Y'],
    ];

    /**
     * What a Run from the dashboard passes to $mode's command: the job's own
     * parameters, the flags of an action its ⋯ menu offers, and, for Queue
     * images for a scope and Check one hotel, the few fields their forms send,
     * checked. Nothing else from the request reaches the job.
     *
     * @param array<array-key, mixed> $request
     *
     * @return array<string, string>
     */
    public static function runParams(string $mode, string $action, array $request): array
    {
        $params = self::jobParams($mode);
        if ($action !== '' && in_array($action, self::offeredActions($mode), true)) {
            $params = [...$params, ...(self::ACTION_PARAMS[$action] ?? [])];
        }
        if ($mode === 'sync_images') {
            $params = [...$params, ...self::scope($request)];
        }
        if (in_array($mode, self::DIAGNOSTIC, true)) {
            $params = [...$params, ...self::hotel($mode, $request)];
        }

        return $params;
    }

    /**
     * Queue images for a scope: whitelist=strict, one country, one destination
     * or one region; force=Y; a limit.
     *
     * @param array<array-key, mixed> $request
     *
     * @return array<string, string>
     */
    private static function scope(array $request): array
    {
        $params = [];
        $scope = TypeCoerce::toString($request['scope'] ?? '');
        $id = self::positiveInt($request['scope_id'] ?? '');
        if ($scope === 'strict') {
            $params['whitelist'] = 'strict';
        } elseif ($scope === 'country') {
            $country = strtoupper(trim(TypeCoerce::toString($request['country'] ?? '')));
            if (preg_match('/^[A-Z]{2}$/', $country) === 1) {
                $params['country'] = $country;
            }
        } elseif ($scope === 'destination' && $id !== '') {
            $params['destination_id'] = $id;
        } elseif ($scope === 'region' && $id !== '') {
            $params['region_id'] = $id;
        }
        if (TypeCoerce::toString($request['force'] ?? '') === 'Y') {
            $params['force'] = 'Y';
        }
        $limit = self::positiveInt($request['limit'] ?? '');
        if ($limit !== '') {
            $params['limit'] = $limit;
        }

        return $params;
    }

    /**
     * Check one hotel: an ID for every check, a name for Search API only, and
     * the two writes only for the check they belong to (attach for Images,
     * apply for SEO fields).
     *
     * @param array<array-key, mixed> $request
     *
     * @return array<string, string>
     */
    private static function hotel(string $mode, array $request): array
    {
        $params = [];
        $hotel = trim(TypeCoerce::toString($request['hotel'] ?? ''));
        $id = self::positiveInt($hotel);
        if ($id !== '') {
            $params['hotel_id'] = $id;
        } elseif ($mode === 'diagnose_search') {
            // Letters of any script, digits, spaces and . , ' - only.
            $name = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace("/[^\\p{L}\\p{N} .,'-]+/u", ' ', $hotel)));
            $name = mb_substr($name, 0, 100);
            if ($name !== '') {
                $params['hotel_name'] = $name;
            }
        }
        if ($mode === 'diagnose_images' && TypeCoerce::toString($request['attach'] ?? '') === 'Y') {
            $params['attach'] = 'Y';
        }
        if ($mode === 'diagnose_seo' && TypeCoerce::toString($request['apply'] ?? '') === 'Y') {
            $params['apply'] = 'Y';
        }

        return $params;
    }

    /** A whole number above zero, as text; '' for anything else. */
    private static function positiveInt(mixed $value): string
    {
        $text = trim(TypeCoerce::toString($value));

        return preg_match('/^[1-9]\d{0,9}$/', $text) === 1 ? $text : '';
    }
}
