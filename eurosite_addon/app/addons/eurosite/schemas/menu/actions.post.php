<?php
/*
 * Eurosite Touring — page-level tab strip shared by the addon's admin pages.
 */

defined('BOOTSTRAP') or die('Access denied');

/** @var array<string, mixed> $schema */

$tabs = [
    'eurosite_dashboard' => [
        'href'     => 'eurosite.manage',
        'text'     => __('eurosite.dashboard', ['[default]' => 'Dashboard']),
        'position' => 100,
    ],
    'eurosite_hotels' => [
        'href'     => 'eurosite.hotels',
        'text'     => __('eurosite.hotels', ['[default]' => 'Hotels']),
        'position' => 150,
    ],
    'eurosite_whitelist' => [
        'href'     => 'eurosite.whitelist',
        'text'     => __('eurosite.destination_whitelist', ['[default]' => 'Destination whitelist']),
        'position' => 200,
    ],
];

foreach (['eurosite.manage', 'eurosite.hotels', 'eurosite.whitelist'] as $page) {
    $schema[$page] = array_merge($schema[$page] ?? [], $tabs);
}

return $schema;
