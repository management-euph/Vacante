<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Cron\Commands;

use Tygh\Addons\NovotonHolidays\Cron\AbstractCronCommand;
use Tygh\Addons\NovotonHolidays\Repository\CacheRepository;
use Tygh\Addons\NovotonHolidays\Services\Container;

class CleanupCommand extends AbstractCronCommand
{
    /** Days of sync-log history kept (SyncLogRepository::prune()). */
    public const int LOG_KEEP_DAYS = 14;

    /**
     * @return list<string>
     */
    #[\Override]
    public static function getModes(): array
    {
        return ['cleanup'];
    }

    public static function getDescription(): string
    {
        return 'Clean orphan bookings, old logs, and expired cache';
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(): array
    {
        $this->output('=== NOVOTON CLEANUP ===');
        $this->output('');

        // 1. Clean orphan bookings (no order_id, older than 48h)
        $this->output('1. Cleaning orphan bookings...');
        $bookingRepo = Container::getInstance()->bookingRepository();
        $orphan_count = Container::getInstance()->bookingReportingRepository()->countOrphans(48);
        $bookingRepo->deleteOrphans(48);
        $this->output("   Orphan bookings deleted: {$orphan_count}");
        $this->output('');

        // 2. Clean old sync logs: LOG_KEEP_DAYS of history, and always each
        // sync's last finished run, which the batched syncs need (prune()).
        $this->output('2. Cleaning old sync logs...');
        $syncRepo = Container::getInstance()->syncLogRepository();
        $total_logs = $syncRepo->count();
        $logs_deleted = $syncRepo->prune(self::LOG_KEEP_DAYS);
        $this->output("   Total: {$total_logs}, kept the last " . self::LOG_KEEP_DAYS . " days and each sync's last finished run, deleted: {$logs_deleted}");
        $this->output('');

        // 3. Clean expired cache entries — both storage surfaces, every run.
        // CacheService prunes its CONFIGURED backend (the var/cache/novoton
        // file tree by default); the direct table sweep also runs because
        // rows written while 'database' storage was configured must not
        // linger after a switch back to 'file'. deleteExpired() compares
        // expires_at (an INT unix stamp) against time() — the old inline
        // `expires_at < NOW()` compared the stamp numerically against
        // NOW()'s 20260101000000-style DATETIME form, which is always true:
        // it wiped LIVE cache entries on every run and never touched the
        // file cache at all.
        $this->output('3. Cleaning expired cache...');
        $storage_removed = Container::getInstance()->cacheService()->cleanup();
        $db_removed = (new CacheRepository())->deleteExpired();
        $this->output("   Expired cache entries removed: {$storage_removed} (configured storage), {$db_removed} (database table)");
        $this->output('');

        $stats = [
            'orphans_deleted' => $orphan_count,
            'logs_deleted' => $logs_deleted,
            'cache_deleted' => $storage_removed + $db_removed,
        ];
        $this->logComplete('cleanup', $stats);

        return ['success' => true, 'stats' => $stats];
    }
}
