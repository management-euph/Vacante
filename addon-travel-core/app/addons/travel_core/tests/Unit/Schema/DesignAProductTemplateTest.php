<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Pins the hotel page Design A product template
 * (blocks/product_templates/travelcore_design_a.tpl,
 * docs/hotel-page-design-a-plan.md):
 *
 *  - CS-Cart offers it in Settings → Appearance → "Product detailed page
 *    view" (and per product) by file placement alone, labelled by the BARE
 *    lang var named after the file — so the location and the label are
 *    load-bearing, as for travelcore_template.tpl.
 *  - The page order is the design: title row, photo mosaic, then the two
 *    hooks that render the booking form mount (product_detail_bottom for the
 *    after_description position, the product_tabs pre-hook otherwise), so
 *    the search bar and its inline results sit under the photos and above
 *    the tabs whichever position the store uses.
 *  - Each CS-Cart hook appears exactly once: a second occurrence would render
 *    every addon's contribution twice.
 *  - Its stylesheet is scoped entirely under .travel-pdpa, so the current
 *    hotel page and every other page stay untouched.
 */
final class DesignAProductTemplateTest extends TestCase
{
    private const REL = 'templates/addons/travel_core/blocks/product_templates/travelcore_design_a.tpl';

    private const CSS_REL = 'css/addons/travel_core/design-a.css';

    private static function repoAddonRoot(): string
    {
        return dirname(__DIR__, 6);
    }

    private static function themeFile(string $theme, string $rel): string
    {
        return self::repoAddonRoot() . '/design/themes/' . $theme . '/' . $rel;
    }

    private static function template(): string
    {
        return (string) file_get_contents(self::themeFile('responsive', self::REL));
    }

    public function testTemplateAndStylesheetExistInBothThemesByteIdentical(): void
    {
        foreach ([self::REL, self::CSS_REL] as $rel) {
            $responsive = self::themeFile('responsive', $rel);
            $nova = self::themeFile('nova_theme', $rel);
            self::assertFileExists($responsive);
            self::assertFileExists($nova, 'copy it to nova_theme, then composer mirror keeps it in sync');
            self::assertSame(
                (string) file_get_contents($responsive),
                (string) file_get_contents($nova),
                $rel . ': nova_theme copy must be byte-identical (composer mirror)',
            );
        }
    }

    public function testEveryHookRendersExactlyOnce(): void
    {
        $src = self::template();

        foreach ([
            'products:view_main_info',
            'products:main_info_title',
            'products:brand',
            'products:image_wrap',
            'products:product_form_close_tag',
            'products:product_detail_bottom',
            'products:product_tabs',
        ] as $hook) {
            self::assertSame(1, substr_count($src, '{hook name="' . $hook . '"}'), $hook);
        }
    }

    public function testSearchBarSitsUnderThePhotosAndAboveTheTabs(): void
    {
        $src = self::template();

        $head = strpos($src, '<div class="travel-pdpa-head">');
        $gallery = strpos($src, 'class="travel-pdpa-gallery ');
        $stock = strpos($src, '<div class="travel-pdpa-stock-gallery"');
        $detailBottom = strpos($src, '{hook name="products:product_detail_bottom"}');
        $tabs = strpos($src, '{hook name="products:product_tabs"}');
        $tabsInclude = strpos($src, 'views/tabs/components/product_tabs.tpl');

        self::assertIsInt($head);
        self::assertIsInt($gallery);
        self::assertIsInt($stock);
        self::assertIsInt($detailBottom);
        self::assertIsInt($tabs);
        self::assertIsInt($tabsInclude);
        self::assertLessThan($gallery, $head, 'title row first');
        self::assertLessThan($stock, $gallery, 'mosaic, then the hidden stock gallery');
        self::assertLessThan($detailBottom, $stock, 'the mount hooks come after the photos');
        self::assertLessThan($tabs, $detailBottom);
        self::assertLessThan($tabsInclude, $tabs, 'the product_tabs PRE hook (mount) renders before the tabs');
    }

    /**
     * Found on a real store: the theme lays out the stock wrapper's children
     * side by side (image left, info right), so the title row sat next to
     * the photos, and its image rules kept the large photo at its own
     * proportions, centred with white bands.
     */
    public function testRowsStackAndPhotosFillTheirTilesWhateverTheTheme(): void
    {
        $src = (string) preg_replace('/\{\*.*?\*\}/s', '', self::template());
        self::assertStringNotContainsString('ty-product-block__wrapper', $src, 'the theme lays that wrapper out in columns');
        self::assertStringContainsString('<div class="travel-pdpa__wrapper">', $src);

        $css = self::css();
        self::assertStringContainsString(".travel-pdpa .travel-pdpa__wrapper {\n    display: block;", $css);
        self::assertStringContainsString('.travel-pdpa .travel-pdpa__wrapper > * {', $css);
        self::assertStringContainsString('.travel-pdpa .travel-pdpa-gallery__tile img {', $css);
        self::assertStringContainsString('object-fit: cover !important;', $css);
        self::assertStringContainsString('height: 100% !important;', $css);
    }

    public function testNoFeatureListUnderTheName(): void
    {
        // "TV, Air Conditioning, … 3 Stars, Resort": facilities live in the
        // tabs and the stars sit next to the name.
        self::assertStringContainsString('{$show_product_features_short_list = false}', self::template());
    }

    public function testNoDateFreeContentAndNoCartQuantity(): void
    {
        $src = (string) preg_replace('/\{\*.*?\*\}/s', '', self::template());

        // No short description, catalog price or quantity: a hotel's price is
        // date-driven and comes from the search.
        self::assertStringNotContainsString('prod_descr_', $src);
        self::assertStringNotContainsString('"price_`$obj_id`"', $src);
        self::assertStringNotContainsString('qty_`$obj_id`', $src);
        self::assertStringNotContainsString('product_bottom_fixed', $src);
    }

    public function testLocationLineUsesTheServerBuiltMapLink(): void
    {
        $src = self::template();

        self::assertStringContainsString('{$travel_hotel_location_line|escape:html}', $src);
        self::assertStringContainsString('href="{$travel_hotel_map_url|escape:html}" target="_blank" rel="noopener"', $src);
        self::assertStringContainsString('travel_core.pdpa_show_map', $src);
    }

    public function testTheCurrentPagesLocationLineIsNotPrintedTwice(): void
    {
        // Design A says so before the title hook runs …
        $src = self::template();
        $capture = strpos($src, '{capture name="travel_pdp_design"}a{/capture}');
        $hook = strpos($src, '{hook name="products:main_info_title"}');
        self::assertIsInt($capture);
        self::assertIsInt($hook);
        self::assertLessThan($hook, $capture);

        // … and the shared hook then leaves its own line out.
        foreach (['responsive', 'nova_theme'] as $theme) {
            $post = (string) file_get_contents(
                self::themeFile($theme, 'templates/addons/travel_core/hooks/products/main_info_title.post.tpl'),
            );
            self::assertStringContainsString('{if $smarty.capture.travel_pdp_design != "a" && (', $post, $theme);
        }

        // A stale compiled hook would still be hidden.
        self::assertStringContainsString('.travel-pdpa .travel-pdp-location {', self::css());
    }

    public function testPageScriptIsLoadedAndShipped(): void
    {
        self::assertStringContainsString('{script src="js/addons/travel_core/pdp-design-a.js"}', self::template());
        self::assertFileExists(self::repoAddonRoot() . '/js/addons/travel_core/pdp-design-a.js');
    }

    public function testStarsAreAssignedBeforeRendering(): void
    {
        $hooks = (string) file_get_contents(dirname(__DIR__, 3) . '/hooks/cart_hooks.php');
        self::assertStringContainsString("assign('travel_pdp_stars'", $hooks);
        self::assertStringContainsString('$travel_pdp_stars > 0', self::template());
    }

    public function testDropdownLabelIsSeededWithPoParity(): void
    {
        $addonRoot = dirname(__DIR__, 3);

        $langKeys = require $addonRoot . '/lang_keys.php';
        self::assertIsArray($langKeys);
        self::assertSame(
            ['en' => 'Travel Core · Design A', 'ro' => 'Travel Core · Design A'],
            $langKeys['travelcore_design_a'] ?? null,
            'the BARE filename key labels the Appearance dropdown entry',
        );
        foreach (['en', 'ro'] as $lang) {
            $po = (string) file_get_contents(self::repoAddonRoot() . '/var/langs/' . $lang . '/addons/travel_core.po');
            self::assertStringContainsString('msgctxt "Languages::travelcore_design_a"', $po, $lang . '.po');
        }

        $xml = (string) file_get_contents($addonRoot . '/addon.xml');
        self::assertSame(2, substr_count($xml, 'id="travelcore_design_a"'), 'en + ro language_variables entries');
    }

    public function testStylesheetIsLoadedAndScopedToTheDesignAPage(): void
    {
        foreach (['responsive', 'nova_theme'] as $theme) {
            $styles = (string) file_get_contents(
                self::themeFile($theme, 'templates/addons/travel_core/hooks/index/styles.post.tpl'),
            );
            self::assertStringContainsString('{style src="addons/travel_core/design-a.css"}', $styles, $theme);
        }

        $css = (string) preg_replace('~/\*.*?\*/~s', '', self::css());
        preg_match_all('/([^{}]+)\{/', $css, $m);
        $unscoped = [];
        foreach ($m[1] as $head) {
            $head = trim($head);
            if ($head === '' || str_starts_with($head, '@')) {
                continue;
            }
            foreach (explode(',', $head) as $selector) {
                $selector = trim($selector);
                if (!str_starts_with($selector, '.travel-pdpa')) {
                    $unscoped[] = $selector;
                }
            }
        }
        self::assertNotSame([], $m[1]);
        self::assertSame([], $unscoped, 'every Design A rule must start with .travel-pdpa');
    }

    private static function css(): string
    {
        return (string) file_get_contents(self::themeFile('responsive', self::CSS_REL));
    }
}
