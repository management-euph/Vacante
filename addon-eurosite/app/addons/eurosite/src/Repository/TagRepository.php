<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Repository;

/**
 * ?:eurosite_tags — the getTagOffersRequest catalog (legitimately empty for
 * some accounts; an empty sync is a success, not an error).
 */
class TagRepository extends CodeNameCatalogRepository
{
    #[\Override]
    protected function table(): string
    {
        return 'eurosite_tags';
    }

    #[\Override]
    protected function codeColumn(): string
    {
        return 'tag_code';
    }
}
