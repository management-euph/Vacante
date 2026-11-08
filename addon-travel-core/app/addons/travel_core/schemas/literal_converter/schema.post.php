<?php
/**
 * Travel Core - letters CS-Cart's URL names drop
 *
 * fn_generate_name() (app/functions/fn.common.php) turns a name into a URL
 * name through this schema: app/schemas/literal_converter/schema.php, which
 * merges general.php (punctuation), ru.php and umlauts.php. Then it drops
 * whatever is left outside a-z 0-9 - . — and the stock lists have no
 * Romanian ă ș ț ş ţ nor Turkish ğ ı İ, so "Mărăști" became "mrti" and
 * "Ağaoğlu Işıklı" "aaolu-ikl" (seen in the sandbox with
 * dev/tools/seo-slug-check.php).
 *
 * CS-Cart loads this file after its own, for every name it makes: products,
 * categories and pages added by hand too. No core file is modified. Only
 * missing letters are added: CS-Cart's own entries (ö → oe) are kept.
 *
 * The "general" list (non-Latin URL names enabled in the SEO add-on) is left
 * alone: that setting keeps these letters on purpose.
 */

/** @var array<string, string> $schema */

$schema += [
    // Romanian: ș ț with a comma below (correct) and ş ţ with a cedilla (older texts)
    'ă' => 'a', 'Ă' => 'A',
    'ș' => 's', 'Ș' => 'S', 'ş' => 's', 'Ş' => 'S',
    'ț' => 't', 'Ț' => 'T', 'ţ' => 't', 'Ţ' => 'T',
    // Turkish
    'ğ' => 'g', 'Ğ' => 'G',
    'ı' => 'i', 'İ' => 'I',
];

return $schema;
