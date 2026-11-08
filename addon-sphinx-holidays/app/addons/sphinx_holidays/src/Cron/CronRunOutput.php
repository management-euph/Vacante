<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Cron;

use Tygh\Addons\TravelCore\Contracts\CronDispatcherInterface;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * A job's run as plain text, the same from the public cron URL
 * (controllers/frontend/sphinx_cron.php) and from the dashboard's Run
 * (sphinx_holidays.run_job): a first line, the job's own output as it goes
 * (the dispatcher echoes it), then how it ended, its error and its figures.
 */
final class CronRunOutput
{
    /** The first line of every run. */
    public static function startLine(string $mode): string
    {
        return '[' . date('Y-m-d H:i:s') . "] Sphinx Cron Started - Mode: {$mode}\n";
    }

    /**
     * Run $mode and print how it ended.
     *
     * @param array<string, mixed> $params what the command reads
     */
    public static function dispatch(CronDispatcherInterface $dispatcher, string $mode, array $params): void
    {
        $result = $dispatcher->dispatch($mode, $params);

        $success = TypeCoerce::toBool($result['success'] ?? false);
        $busy = TypeCoerce::toBool($result['busy'] ?? false);

        if ($busy) {
            echo "\n[" . date('Y-m-d H:i:s') . '] ' . TypeCoerce::toString($result['message'] ?? "Mode '{$mode}' is already running.") . "\n";
        } else {
            echo "\n[" . date('Y-m-d H:i:s') . '] Cron job ' . ($success ? 'completed successfully' : 'finished with errors') . ".\n";
        }

        if (!empty($result['error'])) {
            echo 'Error: ' . TypeCoerce::toString($result['error']) . "\n";
        }

        if (!empty($result['stats'])) {
            $s = TypeCoerce::toStringMap($result['stats']);
            echo 'Stats: ' . TypeCoerce::toInt($s['synced'] ?? $s['added'] ?? 0) . '/' . TypeCoerce::toInt($s['total'] ?? 0) . ' synced';
            if (TypeCoerce::toInt($s['skipped'] ?? 0) > 0) {
                echo ', ' . TypeCoerce::toInt($s['skipped'] ?? 0) . ' skipped';
            }
            if (TypeCoerce::toInt($s['failed'] ?? 0) > 0) {
                echo ', ' . TypeCoerce::toInt($s['failed'] ?? 0) . ' failed';
            }
            if (TypeCoerce::toFloat($s['duration_ms'] ?? 0) > 0) {
                echo ' (' . round(TypeCoerce::toFloat($s['duration_ms'] ?? 0) / 1000, 1) . 's)';
            }
            echo "\n";
        }
    }
}
