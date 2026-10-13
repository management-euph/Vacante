<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphProfileTypes\Enum;

/**
 * Objects a profile type is linked to (?:euph_profile_type_links.object_type).
 */
enum LinkKind: string
{
    case Usergroup = 'G';
    case Storefront = 'S';
    case VendorPlan = 'P';
}
