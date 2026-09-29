<?php

declare(strict_types=1);

/*
 * FGO Invoicing — admin privileges per controller mode.
 *
 * A STRING privilege for every mode, never a GET/POST map: the orders
 * context menu decides whether to show an item with
 * fn_check_permissions($controller, $mode, 'admin', 'post', ...) — lowercase
 * 'post' — and a method map has no 'post' key, so the lookup falls through to
 * "no permission checking" and the item shows for everyone.
 *
 * Reading the invoice log, the pre-check page and downloading PDFs need
 * view_orders; everything that calls FGO or mails the customer needs
 * edit_order (core has no 'manage_orders' privilege). The controller also
 * denies restricted admins outright (RESTRICTED_ADMIN): this schema is what
 * applies should that guard ever be relaxed.
 */

defined('BOOTSTRAP') or die('Access denied');

/** @var array<string, mixed> $schema */

$schema['fgo_invoicing'] = [
    'modes'       => [
        // read-only
        'manage'          => ['permissions' => 'view_orders'],
        'view'            => ['permissions' => 'view_orders'],
        'bulk'            => ['permissions' => 'view_orders'],
        'm_download_pdfs' => ['permissions' => 'view_orders'],
        'test_connection' => ['permissions' => 'view_orders'],
        // FGO calls and customer e-mail
        'issue'           => ['permissions' => 'edit_order'],
        'cancel'          => ['permissions' => 'edit_order'],
        'storno'          => ['permissions' => 'edit_order'],
        'delete'          => ['permissions' => 'edit_order'],
        'attach_awb'      => ['permissions' => 'edit_order'],
        'm_issue'         => ['permissions' => 'edit_order'],
        'm_retry'         => ['permissions' => 'edit_order'],
        'm_email'         => ['permissions' => 'edit_order'],
        'm_cancel'        => ['permissions' => 'edit_order'],
        'm_storno'        => ['permissions' => 'edit_order'],
        'm_delete'        => ['permissions' => 'edit_order'],
        'bulk_run'        => ['permissions' => 'edit_order'],
    ],
    'permissions' => 'view_orders',
];

return $schema;
