<?php

declare(strict_types=1);

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

/** @var array<string, mixed> $schema */
$schema['euph_profile_types'] = [
    'permissions' => [
        'GET' => 'view_euph_profile_types',
        'POST' => 'manage_euph_profile_types',
    ],
];

// The status popup of the list changes a type through tools.update_status.
$schema = fn_euph_profile_types_status_permission($schema);

return $schema;
