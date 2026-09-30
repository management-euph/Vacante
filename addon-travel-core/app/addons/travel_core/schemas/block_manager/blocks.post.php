<?php
/**
 * Travel Core - Block Manager Schema
 *
 * travel_stay_summary: the stay summary for the top of the checkout on
 * phones (blocks/stay_summary.tpl). CS-Cart's lite checkout is assembled
 * from layout blocks, so the store places this one in Design -> Layouts ->
 * Checkout above the checkout blocks; no core template is overridden.
 *
 * A DEDICATED block type, never an entry in the shared 'template' type's
 * pool (its 'templates' key is the core directory-scan string the header
 * Search block selects from — see the provider add-ons' schemas). Display
 * name: the block_travel_stay_summary language variable.
 *
 * @package TravelCore
 */

/** @var array<string, mixed> $schema */

$schema['travel_stay_summary'] = [
    'templates' => [
        'addons/travel_core/blocks/stay_summary.tpl' => [],
    ],
    'wrappers'  => 'blocks/wrappers',
    // Deliberately no 'cache' key: CS-Cart caches only blocks whose schema
    // declares one, and this block shows the shopper's own cart.
];

return $schema;
