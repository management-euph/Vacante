<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Cron\Commands;

use Tygh\Addons\Eurosite\Services\Container;

/**
 * mode `cleanup` — housekeeping: trim the sync log to the newest 200 rows
 * and drop product-info cache entries for hotels that no longer exist.
 */
final class CleanupCommand extends AbstractSyncCommand
{
    #[\Override]
    public static function getModes(): array
    {
        return ['cleanup'];
    }

    #[\Override]
    public static function getDescription(): string
    {
        return 'Trim the sync log and prune orphaned product-info cache rows';
    }

    #[\Override]
    public function execute(array $params = []): array
    {
        return $this->runLogged('cleanup', function (): array {
            Container::syncLog()->trim(200);
            db_query(
                'DELETE c FROM ?:eurosite_product_info_cache c
                 LEFT JOIN ?:eurosite_hotels h
                   ON h.tourop_code = c.tourop_code AND h.product_code = c.product_code
                 WHERE h.product_code IS NULL',
            );

            return ['total' => 0, 'synced' => 0, 'failed' => 0];
        });
    }
}
