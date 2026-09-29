<?php

declare(strict_types=1);

/*
 * FGO Invoicing — Orders > FGO Invoices (the invoice log, fgo_invoicing.manage).
 *
 * Core Orders items sit at 100-500 and the core add-ons at 900 (rma) and
 * 1000 (call_requests): 1100 is free. The title reuses manage_title, a key
 * every installed store already has.
 *
 * Not shown to restricted admins (an admin with a usergroup): the FGO
 * controller denies them, and a menu entry that always ends in "Access
 * denied" is worse than none.
 */

defined('BOOTSTRAP') or die('Access denied');

/** @var array<string, mixed> $schema */

if (!defined('RESTRICTED_ADMIN') || !RESTRICTED_ADMIN) {
    $schema['central']['orders']['items']['fgo_invoices'] = [
        'attrs'    => ['class' => 'is-addon'],
        'href'     => 'fgo_invoicing.manage',
        'position' => 1100,
        'title'    => __('fgo_invoicing.manage_title'),
    ];
}

return $schema;
