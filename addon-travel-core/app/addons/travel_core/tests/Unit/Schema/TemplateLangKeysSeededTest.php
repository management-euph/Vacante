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

    /**
     * Sphinx's package page once asked for travel_core.meal, which doesn't
     * exist ("_travel_core.meal: Mic dejun"). It is now on the shared booking
     * page, whose summary shows the meal plan as the board line, from the
     * offer's meal_type_name; the page prints no meal label of its own.
     */
    public function testSphinxPackageFormNamesTheMealWithTheSharedLabel(): void
    {
        $root = dirname(__DIR__, 7) . '/addon-sphinx-holidays';
        $form = (string) file_get_contents($root . '/design/themes/responsive/templates/addons/sphinx_holidays/views/sphinx_booking/package_booking_form.tpl');
        $controller = (string) file_get_contents($root . '/app/addons/sphinx_holidays/controllers/frontend/sphinx_booking/package_booking_form.php');

        self::assertStringNotContainsString('travel_core.meal")', $form);
        self::assertStringContainsString("'board_name' => TypeCoerce::toString(\$hotel['meal_type_name'] ?? ''),", $controller);
        self::assertStringContainsString('{include file="addons/travel_core/components/booking_sidebar.tpl"', $form);
    }
}
