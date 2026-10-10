<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Repository;

/**
 * ?:eurosite_room_types — the getRoomRequest catalog (22 rows live).
 */
class RoomTypeRepository extends CodeNameCatalogRepository
{
    #[\Override]
    protected function table(): string
    {
        return 'eurosite_room_types';
    }

    #[\Override]
    protected function codeColumn(): string
    {
        return 'code';
    }
}
