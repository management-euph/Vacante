<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Cron;

use Tygh\Addons\TravelCore\Contracts\CronDispatcherInterface;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * A job's run as plain text, the same from a provider's public cron URL
 * (sphinx_cron.php, eurosite_cron.php) and from its dashboard's Run
 * (sphinx_holidays.run_job, eurosite.run_job): a first line, the job's own
 * output as it goes (the dispatcher echoes it), then how it ended, its error
 * and its figures. Sphinx and Eurosite each printed this with their own copy.
 */
final class CronRunOutput
{
    /** The first line of every run: "[time] Sphinx Cron Started - Mode: hotels". */
    public static function startLine(string $provider, string $mode): string
    {
        return '[' . date('Y-m-d H:i:s') . "] {$provider} Cron Started - Mode: {$mode}\n";
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
