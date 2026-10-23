<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Cron\Commands;

use Tygh\Addons\Eurosite\Cron\CronDispatcher;
use Tygh\Addons\Eurosite\Services\Container;
use Tygh\Addons\TravelCore\Cron\CronRunLog;
use Tygh\Addons\TravelCore\Helpers\CronRunLock;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * mode `full` — the whole static-data pipeline in dependency order:
 * countries → own_cities → cities → hotels → room_types → tags.
 *
 * own_cities runs BEFORE cities so its countries join the city-sync target
 * set even with an empty whitelist (first-run bootstrap). product_info is
 * excluded (potentially hundreds of API calls — schedule it separately).
 *
 * Each step runs under its own run lock and is logged as its own run, as
 * Sphinx's full does: a scheduled `hotels` still running is never run a
 * second time at once (it is skipped here), and Travel Core sees which step
 * is running (docs/novoton-availability-gaps-plan.md §4.5, rule 3).
 */
final class FullSyncCommand extends AbstractSyncCommand
{
    private const SEQUENCE = [
        'countries' => CountriesSyncCommand::class,
        'own_cities' => OwnCitiesSyncCommand::class,
        'cities' => CitiesSyncCommand::class,
        'hotels' => HotelsSyncCommand::class,
        'room_types' => RoomTypesSyncCommand::class,
        'tags' => TagsSyncCommand::class,
    ];

    /** @var array<string, class-string<AbstractSyncCommand>> */
    private readonly array $sequence;

    /** @param array<string, class-string<AbstractSyncCommand>>|null $sequence the steps; tests pass their own */
    public function __construct(?array $sequence = null)
    {
        parent::__construct();
        $this->sequence = $sequence ?? self::SEQUENCE;
    }

    #[\Override]
    public static function getModes(): array
    {
        return ['full'];
    }

    #[\Override]
    public static function getDescription(): string
    {
        return 'Run every static-data sync in order (countries, own_cities, cities, hotels, room_types, tags)';
    }

    #[\Override]
    public function execute(array $params = []): array
    {
        $log = Container::syncLog();
        $logId = $log->start('full');
        $totals = ['total' => 0, 'synced' => 0, 'failed' => 0];
        $failures = [];

        // A status, reset or debug request is no run: no locks, no run log.
        $isReadOnly = !empty($params['status']) || !empty($params['reset']) || !empty($params['debug']);

        foreach ($this->sequence as $step => $class) {
            $this->output("=== {$step} ===");
            $command = new $class();
            if ($this->outputCallback !== null) {
                $command->setOutputCallback($this->outputCallback);
            }
            if ($isReadOnly) {
                $result = $command->execute($params);
            } else {
                $lock = new CronRunLock(CronDispatcher::lockPath($step));
                if (!$lock->acquire()) {
                    $this->output("[INFO] {$step} is already running, skipped.");
                    continue;
                }
                try {
                    // Its output still goes where full's goes (which keeps
                    // full's lock fresh); the heartbeat keeps the step's.
                    $command->setHeartbeat(static function () use ($lock): void {
                        $lock->touch();
                    });
                    $result = CronRunLog::record('eurosite', $step, static fn (): array => $command->execute($params));
                } finally {
                    $lock->release();
                }
            }
            $stats = is_array($result['stats'] ?? null) ? $result['stats'] : [];
            foreach ($totals as $key => $v) {
                $totals[$key] = $v + TypeCoerce::toInt($stats[$key] ?? 0);
            }
            if (!TypeCoerce::toBool($result['success'] ?? false)) {
                $failures[] = $step . ': ' . TypeCoerce::toString($result['error'] ?? 'failed');
            }
        }

        $totals['error'] = implode('; ', $failures);
        $totals['duration_ms'] = (int) round($this->getDuration() * 1000);
        $log->complete($logId, $failures === [] ? 'completed' : 'failed', $totals);

        return $failures === []
            ? $this->wrapResult(['success' => true] + $totals)
            : ['success' => false, 'error' => $totals['error'], 'stats' => $totals];
    }
}
