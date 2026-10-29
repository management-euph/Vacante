<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Results;

use Tygh\Addons\TravelCore\Helpers\RequestCoerce;
use Tygh\Addons\TravelCore\Services\DateHelper;
use Tygh\Addons\TravelCore\Services\MoneyFormatter;

/**
 * The Design A hotel page asking a supplier for its search results
 * (docs/hotel-page-design-a-plan.md): its booking engine sends design=a,
 * with the booking config and with the inline search, so the supplier
 * renders the room-first results (components/design_a_results.tpl) for that
 * page only. Standalone search pages keep their own layout.
 */
final class DesignA
{
    public const PARAM = 'design';
    public const VALUE = 'a';

    /** @param array<array-key, mixed> $request */
    public static function requested(array $request): bool
    {
        return RequestCoerce::string($request, self::PARAM) === self::VALUE;
    }

    /** The store's builder: the shopper's currency, the store's date format. */
    public static function builder(): RoomResultsBuilder
    {
        return new RoomResultsBuilder(
            MoneyFormatter::forStore(),
            static fn (string $iso): string => DateHelper::formatStoreDate($iso),
        );
    }
}
