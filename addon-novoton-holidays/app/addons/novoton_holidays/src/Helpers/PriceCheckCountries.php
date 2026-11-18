<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Helpers;

use Tygh\Addons\NovotonHolidays\Constants;
use Tygh\Addons\TravelCore\Helpers\RequestCoerce;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * The countries an admin price check asks for (novoton_prices.php:
 * check_prices and check_prices_hotel): the form's `countries[]`, else the
 * older single `country`.
 *
 * Only countries the add-on knows (Constants::COUNTRIES) are kept. The
 * pages print each country into their HTML; a link with a crafted
 * `country` ran its script in the admin's session.
 */
final class PriceCheckCountries
{
    /**
     * @param array<mixed> $request
     *
     * @return list<string>
     */
    public static function fromRequest(array $request): array
    {
        $asked = RequestCoerce::list($request, 'countries');
        if ($asked === [] && RequestCoerce::string($request, 'country') !== '') {
            $asked = [RequestCoerce::string($request, 'country')];
        }
        $asked = array_map(static fn (mixed $country): string => strtoupper(trim(TypeCoerce::toString($country))), $asked);

        return array_values(array_unique(array_intersect($asked, Constants::COUNTRIES)));
    }
}
