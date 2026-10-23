<?php
/***************************************************************************
 *                                                                          *
 *   Travel Core - Admin Sidebar Menu                                       *
 *   Adds "Travel" section to the CS-Cart admin navigation panel.           *
 *                                                                          *
 ***************************************************************************/

defined('BOOTSTRAP') or die('Access denied');

/** @var array<string, mixed> $schema */

if (!isset($schema['central']) || !is_array($schema['central'])) {
    $schema['central'] = [];
}

$schema['central']['travel_core'] = [
    'title' => __('travel_core.admin_menu_travel'),
    'position' => 900,
    'items' => [
        'travel_bookings' => [
            'attrs' => ['class' => 'is-addon'],
            'href' => 'travel_bookings.manage',
            'position' => 100,
            'title' => __('travel_core.manage_bookings'),
        ],
        'travel_alternatives' => [
            'attrs' => ['class' => 'is-addon'],
            'href' => 'travel_alternatives.manage',
            'position' => 150,
            'title' => __('travel_core.alternative_requests'),
        ],
        'travel_hotel_availability' => [
            'attrs' => ['class' => 'is-addon'],
            'href' => 'travel_hotel_availability.manage',
            'position' => 175,
            'title' => __('travel_core.hotel_availability'),
        ],
        'travel_feature_mappings' => [
            'attrs' => ['class' => 'is-addon'],
            'href' => 'travel_feature_mappings.manage',
            'position' => 200,
            'title' => __('travel_core.feature_mappings'),
        ],
        'travel_booking_styles' => [
            'attrs' => ['class' => 'is-addon'],
            'href' => 'travel_booking_styles.manage',
            'position' => 250,
            'title' => __('travel_core.appearance_settings'),
        ],
        'travel_tools' => [
            'attrs' => ['class' => 'is-addon'],
            'href' => 'travel_tools.manage',
            'position' => 300,
            'title' => __('travel_core.tools_and_cron'),
        ],
    ],
];

return $schema;
