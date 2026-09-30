<?php
/**
 * Sphinx Holidays - Block Manager Schema
 *
 * Registers the Sphinx widgets as DEDICATED block types
 * (sphinx_package_search) so they appear as their own entries in the Block
 * Manager "Add block" list. Hotels are booked from their product pages only:
 * the destination-browse "Booking Form" and the "Best Deals" blocks are gone.
 *
 * They must NOT be added to the shared 'template' block type: its 'templates'
 * key is the core directory-scan string 'blocks/static_templates', the pool
 * every template-driven block (including the header Search block) selects
 * from. Injecting entries there let the widgets hijack the core Search block
 * (and the previously registered paths did not even resolve to files).
 * Display names come from the block_<type> language variables in var/langs.
 *
 * @package SphinxHolidays
 */

/** @var array<string, mixed> $schema */

// Storefront entry point for the package vertical: a search form posting to
// sphinx_booking.package_search, its destination/departure/transport selects
// fed from the cron-synced sphinx_package_routes table (via the cache_deals
// JSON endpoint, type=package_routes — no provider API call). Its only
// remaining use.
$schema['sphinx_package_search'] = [
    'templates' => [
        'addons/sphinx_holidays/blocks/package_search.tpl' => [],
    ],
    'wrappers'  => 'blocks/wrappers',
];

return $schema;
