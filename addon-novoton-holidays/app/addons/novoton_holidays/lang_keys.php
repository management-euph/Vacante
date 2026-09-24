<?php

declare(strict_types=1);

/**
 * Runtime-seeded language keys for novoton_holidays.
 *
 * Read by fn_novoton_holidays_seed_language_keys() (func.php), which UPSERTs
 * every entry into ?:language_values whenever this file or addon.xml changes
 * (hash probe in init.php). That is what lets NEW or CHANGED labels reach
 * stores that are already installed — CS-Cart imports the .po files only at
 * install time, so a label added later otherwise renders raw
 * ("_novoton_holidays.n_offers") on upgraded stores.
 *
 * Shape: 'lang.key' => ['en' => '...', 'ro' => '...'].
 * Plural forms use CS-Cart's "[n] singular|[n] plural" syntax; render with
 * {__("novoton_holidays.n_offers", [$count])} — CS-Cart picks the form from
 * the count and substitutes [n].
 */

return [
    // Availability badge counts (search results) — pluralized so "1 ofertă"
    // vs "2 oferte" is correct in Romanian. Values mirror the .po entries;
    // n_offers is new.
    'novoton_holidays.n_rooms' => [
        'en' => '[n] Room|[n] Rooms',
        'ro' => '[n] Cameră|[n] Camere|[n] de Camere',
    ],
    'novoton_holidays.n_offers' => [
        'en' => '[n] Offer|[n] Offers',
        'ro' => '[n] Ofertă|[n] Oferte|[n] de Oferte',
    ],
    'novoton_holidays.n_adults' => [
        'en' => '[n] Adult|[n] Adults',
        'ro' => '[n] Adult|[n] Adulți|[n] de Adulți',
    ],
    'novoton_holidays.n_children' => [
        'en' => '[n] Child|[n] Children',
        'ro' => '[n] Copil|[n] Copii|[n] de Copii',
    ],
    // Dashboard (novoton_holidays.manage) — scheduled jobs, run from the admin
    'novoton_holidays.dash_job_unknown' => [
        'en' => 'Unknown job: [job]',
        'ro' => 'Job necunoscut: [job]',
    ],
];
