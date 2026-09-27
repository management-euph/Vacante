<?php
/***************************************************************************
 *                                                                          *
 *   This is an addon for CS-Cart / Multi-Vendor                            *
 *   Copyright (c) Novoton Holidays                                         *
 *                                                                          *
 ***************************************************************************/

defined('BOOTSTRAP') or die('Access denied');

/** @var array<string, mixed> $schema */

// Shared tab set for Novoton admin pages
$tabs = [
    'novoton_dashboard' => [
        'href'     => 'novoton_holidays.manage',
        'text'     => __('novoton_holidays.actions.novoton_dashboard'),
        'position' => 50,
    ],
    'list_facilities' => [
        'href'     => 'novoton_holidays.list_facilities',
        'text'     => __('novoton_holidays.actions.import_facilities'),
        'position' => 200,
    ],
    'novoton_seo_templates' => [
        'href'     => 'novoton_seo_templates.manage',
        'text'     => __('travel_core.seo_templates'),
        'position' => 250,
    ],
];

$pages = [
    'novoton_holidays.manage',
    'novoton_holidays.list_facilities',
    'novoton_seo_templates.manage',
];

foreach ($pages as $page) {
    foreach ($tabs as $key => $tab) {
        $schema[$page][$key] = $tab;
    }
}

return $schema;
