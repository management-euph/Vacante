<?php
/**
 * Novoton Holidays - Admin Permissions Schema
 *
 * Registers all backend controller modes with granular permission groups:
 *
 *   manage_catalog            - hotel & product management
 *   novoton_manage_bookings   - view/manage bookings and alternatives
 *   novoton_manage_sync       - run syncs, diagnostics, cron tools
 *   novoton_manage_settings   - addon settings, API config
 *
 * @package NovotonHolidays
 */

// novoton_destinations controller (the destination whitelist)
$schema['novoton_destinations'] = [
    'modes' => [
        'manage'          => ['permissions' => 'manage_catalog'],
        'save'            => ['permissions' => 'manage_catalog'],
        'disable_outside' => ['permissions' => 'manage_catalog'],
    ],
];

// novoton_holidays controller (main dashboard, hotel catalog, sync)
$schema['novoton_holidays'] = [
    'modes' => [
        'manage'                     => ['permissions' => 'manage_catalog'],
        'hotels'                     => ['permissions' => 'manage_catalog'],
        'view_hotel'                 => ['permissions' => 'manage_catalog'],
        'list_facilities'            => ['permissions' => 'manage_catalog'],

        'sync_facilities'            => ['permissions' => 'novoton_manage_sync'],
        'check_packages'             => ['permissions' => 'novoton_manage_sync'],
        'update_prices'              => ['permissions' => 'novoton_manage_sync'],
        'check_prices'               => ['permissions' => 'novoton_manage_sync'],
        'check_prices_hotel'         => ['permissions' => 'novoton_manage_sync'],
        'room_price'                 => ['permissions' => 'novoton_manage_sync'],
        'cron_offers_update'         => ['permissions' => 'novoton_manage_sync'],
        'fix_tab'                    => ['permissions' => 'novoton_manage_sync'],

        'download_active_prices_csv' => ['permissions' => 'manage_catalog'],
        'export_hotel_features_csv'  => ['permissions' => 'manage_catalog'],
        'get_hotel_features_csv'     => ['permissions' => 'manage_catalog'],
        'cron_export_hotel_features' => ['permissions' => 'novoton_manage_sync'],

        'test_api'                   => ['permissions' => 'novoton_manage_sync'],
        'test_formats'               => ['permissions' => 'novoton_manage_sync'],
        'test_product'               => ['permissions' => 'novoton_manage_sync'],
        'test_hotel_list'            => ['permissions' => 'novoton_manage_sync'],
        'test_room_price'            => ['permissions' => 'novoton_manage_sync'],
        'test_search'                => ['permissions' => 'novoton_manage_sync'],
        'test_hotel_request'         => ['permissions' => 'novoton_manage_sync'],
        'test_alternative_rs'        => ['permissions' => 'novoton_manage_sync'],
        'test_facilities'            => ['permissions' => 'novoton_manage_sync'],
    ],
];

// novoton_bookings controller
$schema['novoton_bookings'] = [
    'modes' => [
        'manage'               => ['permissions' => 'novoton_manage_bookings'],
        'view'                 => ['permissions' => 'novoton_manage_bookings'],
        'update'               => ['permissions' => 'novoton_manage_bookings'],
        'resinfo'              => ['permissions' => 'novoton_manage_bookings'],
        'check_all_status'     => ['permissions' => 'novoton_manage_bookings'],
        'cleanup_orphans'      => ['permissions' => 'novoton_manage_bookings'],
        'update_novoton_id'    => ['permissions' => 'novoton_manage_bookings'],
        'request_alternatives' => ['permissions' => 'novoton_manage_bookings'],
        'alternatives'         => ['permissions' => 'novoton_manage_bookings'],
        'order_tab'            => ['permissions' => 'novoton_manage_bookings'],
    ],
];

// novoton_alternatives controller
$schema['novoton_alternatives'] = [
    'modes' => [
        'manage'              => ['permissions' => 'novoton_manage_bookings'],
        'view'                => ['permissions' => 'novoton_manage_bookings'],
        'check_alternatives'  => ['permissions' => 'novoton_manage_bookings'],
        'notify_customer'     => ['permissions' => 'novoton_manage_bookings'],
        'delete'              => ['permissions' => 'novoton_manage_bookings'],
        'check_all_pending'   => ['permissions' => 'novoton_manage_bookings'],
    ],
];

// novoton_diagnostic controller
$schema['novoton_diagnostic'] = [
    'modes' => [
        'health'  => ['permissions' => 'novoton_manage_sync'],
        'check'   => ['permissions' => 'novoton_manage_sync'],
        'test'    => ['permissions' => 'novoton_manage_sync'],
    ],
];

// novoton_admin controller (settings & config)
$schema['novoton_admin'] = [
    'modes' => [
        'manage'           => ['permissions' => 'novoton_manage_settings'],
        'update'           => ['permissions' => 'novoton_manage_settings'],
        'update_prices'    => ['permissions' => 'novoton_manage_sync'],
        'sync_logs'        => ['permissions' => 'novoton_manage_sync'],
        'bookings'         => ['permissions' => 'novoton_manage_bookings'],
        'booking_details'  => ['permissions' => 'novoton_manage_bookings'],
        'download_log'     => ['permissions' => 'novoton_manage_settings'],
        'export_bookings'  => ['permissions' => 'novoton_manage_bookings'],
        'test_api'         => ['permissions' => 'novoton_manage_sync'],
        'run_cron'         => ['permissions' => 'novoton_manage_sync'],
    ],
];

// novoton_hotels controller (sub-controller included by novoton_holidays)
// Only register modes that are dispatched directly to novoton_hotels.
// Modes like list_facilities are handled by the novoton_holidays controller
// and registered above.
$schema['novoton_hotels'] = [
    'modes' => [
        'manage'                 => ['permissions' => 'manage_catalog'],
        'view'                   => ['permissions' => 'manage_catalog'],
        'update'                 => ['permissions' => 'manage_catalog'],
        'save_facility_types'    => ['permissions' => 'manage_catalog'],
        'sync'                   => ['permissions' => 'novoton_manage_sync'],
        'sync_facilities'        => ['permissions' => 'novoton_manage_sync'],
        'sync_hotel_facilities'  => ['permissions' => 'novoton_manage_sync'],
        'check_packages'         => ['permissions' => 'novoton_manage_sync'],
    ],
];

// novoton_prices controller
// Price modes previously routed via include() from novoton_holidays
// are now dispatched directly.
$schema['novoton_prices'] = [
    'modes' => [
        'manage'                   => ['permissions' => 'manage_catalog'],
        'view'                     => ['permissions' => 'manage_catalog'],
        'update_prices'            => ['permissions' => 'novoton_manage_sync'],
        'check_prices'             => ['permissions' => 'novoton_manage_sync'],
        'check_prices_hotel'       => ['permissions' => 'novoton_manage_sync'],
        'room_price'               => ['permissions' => 'novoton_manage_sync'],
        'download_active_prices_csv' => ['permissions' => 'manage_catalog'],
        'cron_offers_update'       => ['permissions' => 'novoton_manage_sync'],
    ],
];

// novoton_price_compare controller
$schema['novoton_price_compare'] = [
    'modes' => [
        'manage'        => ['permissions' => 'manage_catalog'],
        'compare'       => ['permissions' => 'manage_catalog'],
        'verify'        => ['permissions' => 'manage_catalog'],
        'get_packages'  => ['permissions' => 'manage_catalog'],
        'get_rooms'     => ['permissions' => 'manage_catalog'],
    ],
];

// novoton_tools controller
// Test and export modes previously routed via include() from novoton_holidays
// are now dispatched directly for proper CS-Cart template resolution.
$schema['novoton_tools'] = [
    'modes' => [
        'manage'                      => ['permissions' => 'novoton_manage_sync'],
        'test_api'                    => ['permissions' => 'novoton_manage_sync'],
        'test_formats'                => ['permissions' => 'novoton_manage_sync'],
        'test_product'                => ['permissions' => 'novoton_manage_sync'],
        'test_hotel_list'             => ['permissions' => 'novoton_manage_sync'],
        'test_room_price'             => ['permissions' => 'novoton_manage_sync'],
        'test_search'                 => ['permissions' => 'novoton_manage_sync'],
        'test_hotel_request'          => ['permissions' => 'novoton_manage_sync'],
        'test_alternative_rs'         => ['permissions' => 'novoton_manage_sync'],
        'test_facilities'             => ['permissions' => 'novoton_manage_sync'],
        'export_hotel_features_csv'   => ['permissions' => 'manage_catalog'],
        'get_hotel_features_csv'      => ['permissions' => 'manage_catalog'],
        'cron_export_hotel_features'  => ['permissions' => 'novoton_manage_sync'],
        'export_hotel_features_xml'   => ['permissions' => 'manage_catalog'],
        'download_hotel_features_xml' => ['permissions' => 'manage_catalog'],
    ],
];

// novoton_seo_templates controller (dedicated SEO templates admin page)
$schema['novoton_seo_templates'] = [
    'modes' => [
        'manage'     => ['permissions' => 'novoton_manage_settings'],
        'save'       => ['permissions' => 'novoton_manage_settings'],
        'bulk_apply' => ['permissions' => 'novoton_manage_settings'],
    ],
];

return $schema;
