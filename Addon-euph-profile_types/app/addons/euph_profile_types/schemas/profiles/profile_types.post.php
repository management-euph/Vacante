<?php

declare(strict_types=1);

use Tygh\Addons\EuphProfileTypes\Enum\UserKind;
use Tygh\Addons\EuphProfileTypes\Service\FieldsList;

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

/*
 * The Checkout column of the customers' profile fields, while the add-on
 * sets customer types: FieldsList::withCheckoutArea().
 */

/** @var array<array-key, mixed> $schema */
if (fn_euph_profile_types_container()->catalog()->isEnabled(UserKind::Customer)) {
    $schema = FieldsList::withCheckoutArea($schema);
}

return $schema;
