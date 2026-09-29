<?php

declare(strict_types=1);

/*
 * FGO Invoicing — "FGO invoice ▾" in the bulk-action bar of the orders list
 * (the bar that appears once orders are ticked), next to "Print documents".
 *
 * Core items (app/schemas/context_menu/orders.php, CS-Cart 4.20.1): status at
 * 20, print at 30, actions at 40; 35 sits between the last two.
 *
 * Every action item is a GroupActionItem (the default child type): clicking
 * it submits the list form (orders_list_form, checked order_ids[]) to
 * dispatch[fgo_invoicing.m_*], and the controller redirects to the pre-check
 * page, which IS the confirmation, hence no cm-confirm here.
 *
 * Visibility: the core calls fn_check_permissions(<dispatch>, 'post'), which
 * our permissions schema answers with a string privilege per mode, then each
 * item's permission_callback. The FGO controller denies every admin with a
 * usergroup (RESTRICTED_ADMIN), so the callback hides the items from them;
 * it must be a Closure, the item constructors type-hint one.
 */

use Tygh\ContextMenu\Items\DividerItem;
use Tygh\ContextMenu\Items\GroupItem;

defined('BOOTSTRAP') or die('Access denied');

/** @var array<string, mixed> $schema */

$fgo_invoicing_allowed = static function (): bool {
    return !defined('RESTRICTED_ADMIN') || !RESTRICTED_ADMIN;
};

$schema['items']['fgo_invoice'] = [
    'name'                => ['template' => 'fgo_invoicing.menu_fgo_invoice'],
    'type'                => GroupItem::class,
    'permission_callback' => $fgo_invoicing_allowed,
    'items'               => [
        'fgo_issue'           => [
            'name'                => ['template' => 'fgo_invoicing.menu_issue'],
            'dispatch'            => 'fgo_invoicing.m_issue',
            'permission_callback' => $fgo_invoicing_allowed,
            'position'            => 10,
        ],
        'fgo_retry'           => [
            'name'                => ['template' => 'fgo_invoicing.menu_retry'],
            'dispatch'            => 'fgo_invoicing.m_retry',
            'permission_callback' => $fgo_invoicing_allowed,
            'position'            => 20,
        ],
        'fgo_divider_1'       => [
            'type'     => DividerItem::class,
            'position' => 30,
        ],
        'fgo_download_pdfs'   => [
            'name'                => ['template' => 'fgo_invoicing.menu_download_pdfs'],
            'dispatch'            => 'fgo_invoicing.m_download_pdfs',
            'permission_callback' => $fgo_invoicing_allowed,
            'position'            => 40,
        ],
        'fgo_email'           => [
            'name'                => ['template' => 'fgo_invoicing.menu_email'],
            'dispatch'            => 'fgo_invoicing.m_email',
            'permission_callback' => $fgo_invoicing_allowed,
            'position'            => 50,
        ],
        'fgo_divider_2'       => [
            'type'     => DividerItem::class,
            'position' => 60,
        ],
        'fgo_cancel'          => [
            'name'                => ['template' => 'fgo_invoicing.menu_cancel'],
            'dispatch'            => 'fgo_invoicing.m_cancel',
            'permission_callback' => $fgo_invoicing_allowed,
            'position'            => 70,
        ],
        'fgo_storno'          => [
            'name'                => ['template' => 'fgo_invoicing.menu_storno'],
            'dispatch'            => 'fgo_invoicing.m_storno',
            'permission_callback' => $fgo_invoicing_allowed,
            'position'            => 80,
        ],
        'fgo_delete'          => [
            'name'                => ['template' => 'fgo_invoicing.menu_delete'],
            'dispatch'            => 'fgo_invoicing.m_delete',
            // Red, as core's saved-search "Delete" link (common/saved_search_horizontal.tpl).
            'data'                => ['action_class' => 'text-error'],
            'permission_callback' => $fgo_invoicing_allowed,
            'position'            => 90,
        ],
        'fgo_divider_3'       => [
            'type'     => DividerItem::class,
            'position' => 100,
        ],
        // A plain link, not a form submit: a 'class' in action_attributes
        // drops the default cm-process-items / cm-submit classes, so it
        // navigates without asking for ticked orders.
        'fgo_open_log'        => [
            'name'                => ['template' => 'fgo_invoicing.menu_open_log'],
            'dispatch'            => 'fgo_invoicing.manage',
            'data'                => [
                'action_attributes' => [
                    'class' => 'fgo-invoicing-open-log',
                    'href'  => fn_url('fgo_invoicing.manage'),
                ],
            ],
            'permission_callback' => $fgo_invoicing_allowed,
            'position'            => 110,
        ],
    ],
    'position'            => 35,
];

unset($fgo_invoicing_allowed);

return $schema;
