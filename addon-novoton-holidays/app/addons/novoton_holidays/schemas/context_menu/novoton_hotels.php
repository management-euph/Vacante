<?php

/**
 * Novoton → Hotels: CS-Cart's bulk bar for the hotel list (the Products
 * page's own, rendered by common/context_menu_wrapper.tpl with
 * object="novoton_hotels"): select by sync status, Status (enable / disable
 * the hotels' products), Actions (queue a re-sync). Each item submits the
 * list's form with the checked hotels.
 */

use Tygh\ContextMenu\Items\GroupItem;

defined('BOOTSTRAP') or die('Access denied!');

$schema = [
    // "Select" → the rows of one sync status (each row's checkbox carries cm-item-status-<status>).
    'selectable_statuses' => [
        'active' => __('novoton_holidays.hotels_status_active'),
        'pending' => __('novoton_holidays.hotels_status_pending'),
        'error' => __('novoton_holidays.hotels_status_error'),
        'unmapped' => __('novoton_holidays.hotels_status_unmapped'),
    ],
    'items' => [
        'status' => [
            'name' => ['template' => 'status'],
            'type' => GroupItem::class,
            'items' => [
                'make_active' => [
                    'name' => ['template' => 'change_to_status', 'params' => ['[status]' => __('active')]],
                    'dispatch' => 'novoton_hotels.enable_products',
                    'position' => 10,
                ],
                'make_disabled' => [
                    'name' => ['template' => 'change_to_status', 'params' => ['[status]' => __('disabled')]],
                    'dispatch' => 'novoton_hotels.disable_products',
                    'data' => [
                        // The risky one: asks first.
                        'action_attributes' => [
                            'class' => 'cm-process-items cm-submit cm-confirm',
                            'data-ca-confirm-text' => __('novoton_holidays.hotels_confirm_disable_bulk'),
                        ],
                    ],
                    'position' => 20,
                ],
            ],
            'position' => 20,
        ],
        'actions' => [
            'name' => ['template' => 'actions'],
            'type' => GroupItem::class,
            'items' => [
                'resync' => [
                    'name' => ['template' => 'novoton_holidays.hotels_resync'],
                    'dispatch' => 'novoton_hotels.resync',
                    'position' => 10,
                ],
            ],
            'position' => 30,
        ],
    ],
];

return $schema;
