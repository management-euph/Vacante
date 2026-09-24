<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Functions;

use PHPUnit\Framework\TestCase;

/**
 * The availability-badge counts use CS-Cart plural forms, so Romanian renders
 * "1 Ofertă" / "2 Oferte" / "20 de Oferte" correctly — the previous
 * {if count==1} conditionals were replaced. The keys are seeded through
 * lang_keys.php (novoton gained a self-seeder) so they reach already-installed
 * stores, not just fresh ones.
 *
 * Romanian takes THREE forms, per the Unicode CLDR rules CS-Cart selects by:
 * one (n=1), few (n=0 or n%100 in 2..19), other (everything else, which needs
 * "de"). English keeps two — the msgid stays in the source language and only
 * the translation lists every variant.
 */
final class PluralCountLabelsTest extends TestCase
{
    /** @return array<string, array<string, string>> */
    private static function langKeys(): array
    {
        $vars = require dirname(__DIR__, 3) . '/lang_keys.php';
        self::assertIsArray($vars);

        return $vars;
    }

    public function testOffersKeyHasRomanianSingularAndPluralForms(): void
    {
        $vars = self::langKeys();

        self::assertArrayHasKey('novoton_holidays.n_offers', $vars);
        // Ordered by the CLDR rules: one | few | other. Search results routinely
        // exceed 19, so the "de" form is the one customers see most.
        self::assertSame('[n] Ofertă|[n] Oferte|[n] de Oferte', $vars['novoton_holidays.n_offers']['ro']);
        self::assertSame('[n] Offer|[n] Offers', $vars['novoton_holidays.n_offers']['en']);

        foreach (['n_rooms', 'n_adults', 'n_children', 'n_offers'] as $k) {
            $key = 'novoton_holidays.' . $k;
            self::assertArrayHasKey($key, $vars, "{$key} must be seeded for the badge counts");
            self::assertCount(
                3,
                explode('|', $vars[$key]['ro']),
                "{$key}: Romanian needs one|few|other, not just singular|plural",
            );
            self::assertStringContainsString('[n]', $vars[$key]['ro']);
        }
    }

    public function testNovotonHasALanguageSelfSeeder(): void
    {
        $func = (string) file_get_contents(dirname(__DIR__, 3) . '/func.php');
        self::assertStringContainsString('function fn_novoton_holidays_seed_language_keys(', $func);
        self::assertStringContainsString('function fn_novoton_holidays_language_seed_hash(', $func);

        // init.php reseeds whenever an installed language is missing the
        // current fingerprint. Deliberately NOT admin-gated any more: the
        // old AREA === 'A' probe left CUSTOMERS reading raw keys after a
        // deploy until somebody opened the admin panel.
        $init = (string) file_get_contents(dirname(__DIR__, 3) . '/init.php');
        self::assertStringContainsString('fn_travel_core_heal_language_keys(', $init);
        self::assertStringContainsString("'novoton_holidays'", $init);
        // Through the CLASS, never the func.php helper: older CS-Cart cores
        // include init.php before func.php, and there the helper did not exist
        // yet — the heal failed silently on every request and no label added
        // after install reached the store.
        self::assertStringContainsString('\\Tygh\\Addons\\NovotonHolidays\\Install\\LanguageSeeder::seedHash()', $init);
        self::assertStringNotContainsString('fn_novoton_holidays_language_seed_hash()', $init);
        self::assertStringNotContainsString("'fn_novoton_holidays_language_variables'", $init);
    }
}
