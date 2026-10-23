<?php
/*
 * Travel Core - Admin permissions schema
 */

/** @var array<string, mixed> $schema */

$schema['travel_core'] = [
    'permissions' => ['GET', 'POST'],
];

$schema['travel_tools'] = [
    'permissions' => ['GET', 'POST'],
];

$schema['travel_hotel_availability'] = [
    'permissions' => ['GET', 'POST'],
];

$schema['travel_booking_styles'] = [
    'permissions' => ['GET', 'POST'],
];

return $schema;
