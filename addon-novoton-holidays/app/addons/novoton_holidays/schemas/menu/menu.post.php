<?php
/***************************************************************************
 *                                                                          *
 *   Novoton Holidays — Admin Sidebar Menu                                  *
 *   Adds a Novoton section to the CS-Cart admin navigation panel.          *
 *                                                                          *
 ***************************************************************************/

defined('BOOTSTRAP') or die('Access denied');

/** @var array<string, mixed> $schema */

$schema['central']['novoton_holidays'] = [
    'title' => __('novoton_holidays.addon_name', ['[default]' => 'Novoton Holidays']),
    'position' => 910,
    'items' => [
        'novoton_dashboard' => [
            'attrs' => ['class' => 'is-addon'],
            'href' => 'novoton_holidays.manage',
            'position' => 100,
            'title' => __('novoton_holidays.actions.novoton_dashboard', ['[default]' => 'Dashboard']),
        ],
        'novoton_destinations' => [
            'attrs' => ['class' => 'is-addon'],
            'href' => 'novoton_destinations.manage',
            'position' => 150,
            'title' => __('novoton_holidays.dest_title', ['[default]' => 'Destinations']),
        ],
        'novoton_list_facilities' => [
            'attrs' => ['class' => 'is-addon'],
            'href' => 'novoton_holidays.list_facilities',
            'position' => 200,
            'title' => __('novoton_holidays.actions.import_facilities', ['[default]' => 'Import Facilities']),
        ],
        'novoton_seo_templates' => [
            'attrs' => ['class' => 'is-addon'],
            'href' => 'novoton_seo_templates.manage',
            'position' => 300,
            'title' => __('travel_core.seo_templates', ['[default]' => 'SEO Templates']),
        ],
    ],
];

return $schema;
