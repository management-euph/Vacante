<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Cron\Commands;

use Tygh\Addons\Eurosite\Services\Container;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * mode `full` — the whole static-data pipeline in dependency order:
 * countries → own_cities → cities → hotels → room_types → tags.
 *
 * own_cities runs BEFORE cities so its countries join the city-sync target
 * set even with an empty whitelist (first-run bootstrap). product_info is
 * excluded (potentially hundreds of API calls — schedule it separately).
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

        foreach (self::SEQUENCE as $step => $class) {
            $this->output("=== {$step} ===");
            $command = new $class();
            if ($this->outputCallback !== null) {
                $command->setOutputCallback($this->outputCallback);
            }
            $result = $command->execute($params);
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
