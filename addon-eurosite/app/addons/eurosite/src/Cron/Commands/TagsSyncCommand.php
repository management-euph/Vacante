<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Cron\Commands;

use Tygh\Addons\Eurosite\Services\Container;

/**
 * mode `tags` — getTagOffersRequest → ?:eurosite_tags. The live catalog is
 * currently EMPTY for this account: zero rows is a successful sync, not an
 * error.
 */
final class TagsSyncCommand extends AbstractSyncCommand
{
    #[\Override]
    public static function getModes(): array
    {
        return ['tags'];
    }

    #[\Override]
    public static function getDescription(): string
    {
        return 'Sync the offer-tag catalog (getTagOffersRequest; may be empty)';
    }

    #[\Override]
    public function execute(array $params = []): array
    {
        return $this->runLogged('tags', function (): array {
            $tags = Container::getApi()->getTagOffers();
            $synced = Container::tags()->upsertBatch($tags);

            return ['total' => count($tags), 'synced' => $synced, 'failed' => 0];
        });
    }
}
