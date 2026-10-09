<?php

declare(strict_types=1);

/**
 * Single source of truth for the repo's template/asset mirror sets.
 *
 * Consumed by BOTH:
 *  - scripts/mirror-themes.php  (composer mirror — performs the sync)
 *  - ThemeMirrorTest            (travel_core suite — enforces the sync)
 * so the tool and the guard can never disagree about what is mirrored.
 */
return [
    // design/themes roots (repo-relative) whose nova_theme tree mirrors
    // the responsive tree byte-for-byte. sphinx ships responsive only.
    'theme_roots' => [
        'addon-novoton-holidays/design/themes',
        'addon-travel-core/design/themes',
    ],

    // nova_theme files allowed to differ from their responsive counterpart
    // (path relative to the nova_theme dir => reason). To intentionally fork
    // a file for nova_theme, add it here with a short WHY.
    // 2026-07-22 review resolved all four pending entries: two were
    // comment-only forks (better wording merged into responsive), one was
    // comment-identical code, and blocks/booking_summary.tpl was a STALE
    // 62-line fork of the shared travel_core block — re-synced to the thin
    // wrapper so nova stops shipping outdated markup.
    'drift_allowlist' => [],

    // Sets of repo-relative paths that must stay byte-identical across
    // AREAS (storefront / admin / mail — Smarty resolves templates per
    // area, so each area needs its own physical copy). First path in a
    // set is the reference the others are synced FROM.
    // None today: the order pages draw travel_core's booking card, so
    // components/order_booking_details.tpl lives in the mail tree only
    // (the order emails).
    'area_copy_sets' => [],
];
