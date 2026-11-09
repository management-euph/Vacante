<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Every travel_core.* label a template of ANY add-on asks for — storefront,
 * admin and mail, Sphinx's, Novoton's and Eurosite's included — must be in a
 * source LanguageSeeder reads: lang_keys.php or addon.xml.
 *
 * CS-Cart reads the .po files only at install, so a label that lives only
 * there (or nowhere) prints as its raw key on a store installed before it:
 * Sphinx's package booking form showed "_travel_core.meal" instead of
 * "Masă", the booking sidebar "_travel_core.total_price". A template's
 * |default never applies: CS-Cart returns the key itself, which is not empty.
 * AdminLangKeysSeededTest covers travel_core's own admin templates; this
 * one covers every template that borrows a travel_core label.
 */
final class TemplateLangKeysSeededTest extends TestCase
{
    /** @return array<string, true> */
    private static function seededKeys(): array
    {
        $addonRoot = dirname(__DIR__, 3);
        $keys = [];

        $langKeys = require $addonRoot . '/lang_keys.php';
        foreach (array_keys(is_array($langKeys) ? $langKeys : []) as $key) {
            $keys[(string) $key] = true;
        }

        $xml = simplexml_load_file($addonRoot . '/addon.xml');
        if ($xml !== false && isset($xml->language_variables)) {
            foreach ($xml->language_variables->item as $item) {
                $keys[(string) $item['id']] = true;
            }
        }

        return $keys;
    }

    /**
     * Each add-on's design/ directory in the repository.
     *
     * @return list<string>
     */
    private static function designRoots(): array
    {
        $repo = dirname(__DIR__, 7);

        return array_values(array_filter(
            array_merge(glob($repo . '/addon-*/design') ?: [], glob($repo . '/Addon-*/design') ?: []),
            'is_dir',
        ));
    }

    public function testEveryTravelCoreLabelATemplateUsesIsSeeded(): void
    {
        $seeded = self::seededKeys();
        self::assertArrayHasKey('travel_core.meal_plan', $seeded, 'the seeded sources were not read');

        $roots = self::designRoots();
        self::assertGreaterThan(1, count($roots), 'the other add-ons\' templates were not found');

        $offenders = [];
        $templates = 0;
        foreach ($roots as $root) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'tpl') {
                    continue;
                }
                ++$templates;
                $source = (string) file_get_contents($file->getPathname());
                // Literal {__("travel_core.<key>")} only: a key built from a
                // variable ({__("travel_core.obc_status_`$code`")}) is not read.
                if (preg_match_all('/__\(\s*["\'](travel_core\.[a-z0-9_]+)["\']/i', $source, $m) === 0) {
                    continue;
                }
                foreach (array_unique($m[1]) as $key) {
                    if (!isset($seeded[$key])) {
                        $offenders[] = basename(dirname($root)) . ': ' . $file->getFilename() . ' → ' . $key;
                    }
                }
            }
        }

        self::assertGreaterThan(100, $templates, 'too few templates scanned');
        self::assertSame(
            [],
            $offenders,
            'templates use travel_core labels that neither lang_keys.php nor addon.xml has: they print as raw keys '
                . 'on installed stores. Add them to lang_keys.php (or point the template at an existing label).',
        );
    }

    public function testSphinxPackageFormNamesTheMealWithTheSharedLabel(): void
    {
        $form = (string) file_get_contents(dirname(__DIR__, 7)
            . '/addon-sphinx-holidays/design/themes/responsive/templates/addons/sphinx_holidays/views/sphinx_booking/package_booking_form.tpl');

        self::assertStringContainsString('{__("travel_core.meal_plan")}:', $form);
        self::assertStringNotContainsString('travel_core.meal")', $form);
    }
}
