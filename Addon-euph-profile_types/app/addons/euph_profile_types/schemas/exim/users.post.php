<?php

declare(strict_types=1);

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

/*
 * Customers export and import: the type code of each user. On import the
 * code is checked: only the code of a customer type is taken, and the
 * customer's user groups follow (fn_euph_profile_types_exim_put_type()).
 */

/** @var array<string, mixed> $schema */
$schema['export_fields'] = (is_array($schema['export_fields'] ?? null) ? $schema['export_fields'] : []) + [
    'Profile type' => [
        'process_get' => ['fn_euph_profile_types_exim_get_type', '#key'],
        'process_put' => ['fn_euph_profile_types_exim_put_type', '#key', '#this'],
        'linked' => false,
    ],
];

return $schema;
