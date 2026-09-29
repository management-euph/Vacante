<?php

declare(strict_types=1);

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

require_once __DIR__ . '/functions/install.php';
require_once __DIR__ . '/functions/profile_fields.php';
require_once __DIR__ . '/functions/email.php';
require_once __DIR__ . '/functions/bulk.php';
// Self-sufficient (no add-on classes): CS-Cart calls these while building the
// settings form of an add-on whose init.php — and autoloader — has not run.
require_once __DIR__ . '/functions/settings_variants.php';
require_once __DIR__ . '/functions/settings_heal.php';

/**
 * Force-seed every fgo language key. Entry point for dev/tools/seed-langs.php.
 */
function fn_fgo_invoicing_seed_language_keys(): void
{
    \Tygh\Addons\FgoInvoicing\Install\LanguageSeeder::seed();
}

/**
 * Language variables for the runtime self-heal seeder.
 *
 * CS-Cart imports an addon's language variables only when the addon is
 * INSTALLED. A store installed while var/langs/<lang>/addons/fgo_invoicing.po
 * was missing or unparseable keeps empty labels forever. init.php probes a
 * stamp against these sources and reseeds when they change; the self-healing
 * entry point is fn_travel_core_heal_language_keys().
 *
 * @return array<string, array<string, string>> name => [lang_code => value]
 */
function fn_fgo_invoicing_language_variables(): array
{
    return \Tygh\Addons\FgoInvoicing\Install\LanguageSeeder::variables();
}

/**
 * Stat-based fingerprint of addon.xml + lang_keys.php. Cheap enough to run on
 * every request; changes exactly when a label could have changed.
 */
function fn_fgo_invoicing_language_seed_hash(): string
{
    return \Tygh\Addons\FgoInvoicing\Install\LanguageSeeder::seedHash();
}
