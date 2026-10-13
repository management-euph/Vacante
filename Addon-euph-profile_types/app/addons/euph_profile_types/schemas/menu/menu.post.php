<?php

declare(strict_types=1);

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

/*
 * Customers → Profile types; in Multi-Vendor also Vendors → Vendor types.
 */

/** @var array<string, mixed> $schema */
$schema['central'] = fn_euph_profile_types_menu($schema['central'] ?? null, fn_allowed_for('MULTIVENDOR') === true);

return $schema;
