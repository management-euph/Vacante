<?php

declare(strict_types=1);

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

/** @var array<string, mixed> $schema */
$schema['export_fields'] = (is_array($schema['export_fields'] ?? null) ? $schema['export_fields'] : []) + [
    'Profile type' => ['db_field' => 'euph_profile_type'],
];

return $schema;
