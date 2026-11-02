<?php

declare(strict_types=1);
/**
 * Travel Core — a closed hotel's page redirects (301) to its resort page or
 * to the product the admin chose (docs/novoton-availability-gaps-plan.md
 * §7.4, §9). Which pages redirect is worked out by the daily run and after
 * each admin change (ClosedHotelRedirects); this reads one stored value, so
 * every other product page costs one small query and nothing else.
 *
 * Permanent (fn_redirect's third argument): search engines move the page's
 * standing to the new address. The product stays Hidden, never Disabled or
 * deleted; clearing the "closed" decision brings the page back.
 *
 * @package TravelCore
 */

use Tygh\Addons\TravelCore\Helpers\RequestCoerce;
use Tygh\Addons\TravelCore\Services\ClosedHotelRedirects;

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

if ($mode === 'view') {
    $travelClosedRedirect = (new ClosedHotelRedirects())->urlFor(RequestCoerce::int($_REQUEST, 'product_id'));
    if ($travelClosedRedirect !== null) {
        fn_redirect($travelClosedRedirect, false, true);
    }
}
