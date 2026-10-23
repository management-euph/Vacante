<?php
/***************************************************************************
 *                                                                          *
 *   Travel Core - Menu Actions Schema                                      *
 *   Registers admin tab navigation for travel_core pages                   *
 *                                                                          *
 ***************************************************************************/

defined('BOOTSTRAP') or die('Access denied');

/** @var array<string, mixed> $schema */

// Shared tab set for all travel_core admin pages
$tabs = [
    'travel_bookings' => [
        'href'     => 'travel_bookings.manage',
        'text'     => __('travel_core.manage_bookings'),
        'position' => 100,
    ],
    'travel_alternatives' => [
        'href'     => 'travel_alternatives.manage',
        'text'     => __('travel_core.alternative_requests'),
        'position' => 150,
    ],
    'travel_hotel_availability' => [
        'href'     => 'travel_hotel_availability.manage',
        'text'     => __('travel_core.hotel_availability'),
        'position' => 175,
    ],
    'travel_feature_mappings' => [
        'href'     => 'travel_feature_mappings.manage',
        'text'     => __('travel_core.feature_mappings'),
        'position' => 200,
    ],
    'travel_booking_styles' => [
        'href'     => 'travel_booking_styles.manage',
        'text'     => __('travel_core.appearance_settings'),
        'position' => 250,
    ],
    'travel_tools' => [
        'href'     => 'travel_tools.manage',
        'text'     => __('travel_core.tools_and_cron'),
        'position' => 300,
    ],
];

// Apply same tabs to all travel_core pages (DRY)
$pages = [
    'travel_bookings.manage',
    'travel_bookings.view',
    'travel_alternatives.manage',
    'travel_hotel_availability.manage',
    'travel_feature_mappings.manage',
    'travel_feature_mappings.edit',
    'travel_booking_styles.manage',
    'travel_tools.manage',
];

foreach ($pages as $page) {
    $existing = isset($schema[$page]) && is_array($schema[$page]) ? $schema[$page] : [];
    $schema[$page] = array_merge($existing, $tabs);
}

return $schema;
