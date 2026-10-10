<?php
/*
 * Eurosite Touring — Admin permissions schema
 */

/** @var array<string, mixed> $schema */

$schema['eurosite'] = [
    'permissions' => ['GET', 'POST'],
];

$schema['eurosite_booking'] = [
    'permissions' => ['GET', 'POST'],
];

return $schema;
