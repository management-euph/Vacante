<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Cron\Commands;

use Tygh\Addons\Eurosite\Services\Container;
use Tygh\Addons\TravelCore\Cron\AbstractCronCommand as BaseCommand;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Eurosite-specific cron command base.
 *
 * Extends travel_core's shared base with ?:eurosite_sync_log bookkeeping:
 * every command body runs inside runLogged(), which writes the started row,
 * completes it with stats or the failure message, and normalizes the
 * dispatcher result shape.
 */
abstract class AbstractSyncCommand extends BaseCommand
{
    /**
     * Execute the command with optional parameters.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    abstract public function execute(array $params = []): array;

    /**
     * Run $work bracketed by sync-log start/complete rows.
     *
     * @param callable(): array<string, mixed> $work Returns stats
     *                                               (total/synced/failed/skipped/error...)
     * @return array<string, mixed>
     */
    protected function runLogged(string $syncType, callable $work): array
    {
        $log = Container::syncLog();
        $logId = $log->start($syncType);
        try {
            $stats = $work();
            $stats['duration_ms'] = (int) round($this->getDuration() * 1000);
            $failed = TypeCoerce::toInt($stats['failed'] ?? 0);
            $synced = TypeCoerce::toInt($stats['synced'] ?? 0);
            $status = ($failed > 0 && $synced === 0) ? 'failed' : 'completed';
            $log->complete($logId, $status, $stats);

            $this->output(sprintf(
                '[%s] %s: %d/%d synced%s (%.1fs)',
                $syncType,
                $status,
                $synced,
                TypeCoerce::toInt($stats['total'] ?? $synced),
                $failed > 0 ? ", {$failed} failed" : '',
                $this->getDuration(),
            ));

            return $this->wrapResult(['success' => $status === 'completed'] + $stats);
        } catch (\Throwable $e) {
            $log->complete($logId, 'failed', [
                'error' => $e->getMessage(),
                'duration_ms' => (int) round($this->getDuration() * 1000),
            ]);
            $this->output("[{$syncType}] FAILED: " . $e->getMessage());

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * The countries every catalog sync targets: the whitelist's countries
     * plus every country carrying own-offer cities. Fails hard when nothing
     * is configured — a silent no-op sync would read as "everything synced".
     *
     * @return list<string>
     */
    protected function targetCountryCodes(): array
    {
        $codes = array_values(array_unique(array_merge(
            Container::whitelist()->getCountryCodes(),
            Container::cities()->getOwnCountryCodes(),
        )));
        if ($codes === []) {
            throw new \RuntimeException(
                'No sync targets configured. Configure destinations in Eurosite > Whitelist '
                . '(or run the own_cities sync first).',
            );
        }

        return $codes;
    }
}
