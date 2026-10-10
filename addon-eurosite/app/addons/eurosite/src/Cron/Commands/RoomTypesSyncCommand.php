<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Cron\Commands;

use Tygh\Addons\Eurosite\Services\Container;

/**
 * mode `room_types` — getRoomRequest → ?:eurosite_room_types (22 rows live).
 */
final class RoomTypesSyncCommand extends AbstractSyncCommand
{
    #[\Override]
    public static function getModes(): array
    {
        return ['room_types'];
    }

    #[\Override]
    public static function getDescription(): string
    {
        return 'Sync the room-type catalog (getRoomRequest)';
    }

    #[\Override]
    public function execute(array $params = []): array
    {
        return $this->runLogged('room_types', function (): array {
            $rooms = Container::getApi()->getRoomTypes();
            $synced = Container::roomTypes()->upsertBatch($rooms);

            return ['total' => count($rooms), 'synced' => $synced, 'failed' => 0];
        });
    }
}
