<?php

declare(strict_types=1);

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

// func.php only require_once's the function files, so loading it here as
// well is harmless whichever of the two CS-Cart includes first, and it makes
// the functions below available to this file.
require_once __DIR__ . '/func.php';

fn_register_hooks(
    // Profile fields: one common list, checkboxes per type.
    'get_profile_fields',
    'get_profile_fields_post',
    // Customers.
    'update_user_pre',
    'update_profile',
    'get_users',
    // Vendors (Multi-Vendor).
    'get_companies',
    'update_company',
    // Orders, e-mails and documents.
    'create_order',
    'update_order',
    'get_order_info',
    // Disabling the add-on gives the checkout blocks their filling back.
    'update_addon_status_pre',
);

// After the add-on is enabled again, the first admin request switches the
// checkout blocks back to the "By profile type" filling (disabling had
// restored theirs). Guarded: a failure here must never break the panel.
if (defined('AREA') && AREA === 'A') {
    try {
        fn_euph_profile_types_ensure_checkout_blocks();
    } catch (\Throwable $e) {
        error_log('euph_profile_types: checkout blocks were not switched: ' . $e->getMessage());
    }
}
