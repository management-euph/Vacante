<?php
/***************************************************************************
 *                                                                          *
 *   Eurosite Touring — Admin Sidebar Menu                                  *
 *   Adds a Eurosite section to the CS-Cart admin navigation panel.         *
 *                                                                          *
 ***************************************************************************/

defined('BOOTSTRAP') or die('Access denied');

/** @var array<string, mixed> $schema */

if (!isset($schema['central']) || !is_array($schema['central'])) {
    $schema['central'] = [];
}

$schema['central']['eurosite'] = [
    'title' => __('eurosite.addon_name', ['[default]' => 'Eurosite Touring']),
    'position' => 930,
    'items' => [
        'eurosite_dashboard' => [
            'attrs' => ['class' => 'is-addon'],
            'href' => 'eurosite.manage',
            'position' => 100,
            'title' => __('eurosite.dashboard', ['[default]' => 'Dashboard']),
        ],
        'eurosite_hotels' => [
            'attrs' => ['class' => 'is-addon'],
            'href' => 'eurosite.hotels',
            'position' => 150,
            'title' => __('eurosite.hotels', ['[default]' => 'Hotels']),
        ],
        'eurosite_whitelist' => [
            'attrs' => ['class' => 'is-addon'],
            'href' => 'eurosite.whitelist',
            'position' => 200,
            'title' => __('eurosite.destination_whitelist', ['[default]' => 'Destination whitelist']),
        ],
        'eurosite_bookings' => [
            'attrs' => ['class' => 'is-addon'],
            'href' => 'travel_bookings.manage?provider=eurosite',
            'position' => 300,
            'title' => __('eurosite.bookings', ['[default]' => 'Bookings']),
        ],
    ],
];

return $schema;
