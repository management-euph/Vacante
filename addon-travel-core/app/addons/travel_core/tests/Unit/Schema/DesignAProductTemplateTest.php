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

    /**
     * Found on a real store: CS-Cart never enlarges a photo into a
     * thumbnail. Asked for 1200px of a 1024px photo it centres the photo on a
     * 1200px canvas in the thumbnail background colour, so the large tile
     * showed white bands beside it. Each tile asks at most the photo's own
     * width; a photo too small or too tall for its tile shows whole over a
     * blurred copy of itself (pdp-design-a.js).
     */
    public function testPhotosAreNeverAskedWiderThanTheyAre(): void
    {
        $src = self::template();

        self::assertStringContainsString(
            '{$travel_pdpa_photo_x = $travel_pdpa_photo.detailed.image_x|default:$travel_pdpa_photo.icon.image_x|default:0}',
            $src,
        );
        self::assertStringContainsString('{if $travel_pdpa_photo_x > 0 && $travel_pdpa_photo_x < $travel_pdpa_width}', $src);

        $choose = strpos($src, '{$travel_pdpa_width = 600}');
        $cap = strpos($src, '{$travel_pdpa_width = $travel_pdpa_photo_x}');
        $use = strpos($src, 'image_width=$travel_pdpa_width');
        self::assertIsInt($choose);
        self::assertIsInt($cap);
        self::assertIsInt($use);
        self::assertLessThan($cap, $choose, 'the cap applies to the width chosen per tile');
        self::assertLessThan($use, $cap, '… before common/image.tpl asks for the thumbnail');

        $css = self::css();
        self::assertStringContainsString('max-width: var(--travel-pdpa-whole-w, 100%) !important;', $css);
        self::assertStringContainsString('object-fit: contain !important;', $css);
        self::assertStringContainsString('.travel-pdpa .travel-pdpa-gallery__tile--whole .travel-pdpa-gallery__backdrop {', $css);
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

        // The hotel's code (the product code) between the location and the map link.
        $line = strpos($src, 'travel-pdpa-location__line');
        $code = strpos($src, '{__("travel_core.pdpa_code", ["[code]" => $product.product_code|escape:html])}');
        $map = strpos($src, 'travel-pdpa-location__map');
        self::assertIsInt($line);
        self::assertIsInt($code);
        self::assertIsInt($map);
        self::assertLessThan($code, $line);
        self::assertLessThan($map, $code);
        $langKeys = require dirname(__DIR__, 3) . '/lang_keys.php';
        self::assertIsArray($langKeys);
        self::assertSame(['en' => 'CODE: [code]', 'ro' => 'COD: [code]'], $langKeys['travel_core.pdpa_code'] ?? null);
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

    /**
     * The owner's two-line sticky header (#40): line 1 holds the name, the
     * stars (or a circuit's length), the map pin, "Alege datele" /
     * "Alege cameră" and the favourites heart, no dates · guests summary and
     * no "Rezervă"; line 2 is built by the script from the product's tabs.
     * Without the script nothing of it shows.
     */
    public function testStickyHeaderHoldsTheOwnersTwoLines(): void
    {
        $src = self::template();

        $header = strpos($src, '<div class="travel-pdpa-sticky" data-travel-pdpa-sticky hidden');
        $name = strpos($src, 'data-travel-pdpa-top');
        $stars = strpos($src, 'class="travel-pdpa-sticky__stars"');
        $pin = strpos($src, '<a href="{$travel_hotel_map_url|escape:html}" target="_blank" rel="noopener" class="travel-pdpa-sticky__map"');
        $cta = strpos($src, 'data-travel-pdpa-cta');
        $heart = strpos($src, 'data-travel-pdpa-fav aria-pressed="false"');
        $line2 = strpos($src, '<nav class="travel-pdpa-sticky__tabs" data-travel-pdpa-sticky-tabs hidden');
        foreach ([$header, $name, $stars, $pin, $cta, $heart, $line2] as $at) {
            self::assertIsInt($at);
        }
        self::assertLessThan($name, $header);
        self::assertLessThan($stars, $name, 'the name, then "5 ★"');
        self::assertLessThan($pin, $stars, 'then the title row\'s own map link');
        self::assertLessThan($cta, $pin, 'then the button');
        self::assertLessThan($heart, $cta, 'then the heart');
        self::assertLessThan($line2, $heart, 'line 2 under line 1');
        self::assertStringContainsString('data-travel-pdpa-sticky-tablist></div>', $src, 'line 2 is built from the page\'s tab strip');

        // One button: before a search "Alege datele", or the title row's
        // "Alege data plecării" for a circuit or a package by coach or plane;
        // after it "Alege cameră".
        self::assertStringContainsString('data-label-dates="{if $travel_circuit_facts}{__("travel_core.choose_departure_date")|escape:html}{else}{__("travel_core.pdpa_check_availability")|escape:html}{/if}"', $src);
        self::assertStringContainsString('data-label-departure="{__("travel_core.choose_departure_date")|escape:html}"', $src);
        self::assertStringContainsString('data-label-room="{__("travel_core.pdpa_choose_room")|escape:html}"', $src);
        $langKeys = require dirname(__DIR__, 3) . '/lang_keys.php';
        self::assertIsArray($langKeys);
        self::assertSame(['en' => 'Check availability', 'ro' => 'Alege datele'], $langKeys['travel_core.pdpa_check_availability'] ?? null);
        self::assertSame(['en' => 'Select a room', 'ro' => 'Alege cameră'], $langKeys['travel_core.pdpa_choose_room'] ?? null);

        // The earlier summary strip is gone with its dates · guests and "Rezervă".
        foreach (['data-travel-pdpa-compact', 'travel_core.pdpa_summary_label', 'data-travel-goto-results'] as $gone) {
            self::assertStringNotContainsString($gone, $src);
        }
        self::assertArrayNotHasKey('travel_core.pdpa_summary_label', $langKeys);

        // After the hero and its booking hooks, before the tabs it follows.
        $detailBottom = strpos($src, '{hook name="products:product_detail_bottom"}');
        $tabs = strpos($src, '{hook name="products:product_tabs"}');
        self::assertIsInt($detailBottom);
        self::assertIsInt($tabs);
        self::assertLessThan($header, $detailBottom);
        self::assertLessThan($tabs, $header);
    }

    /** The script finds the product's tab strip and panels inside their own box. */
    public function testProductTabsSitInTheBoxTheStickyHeaderFollows(): void
    {
        $src = self::template();

        $include = strpos($src, 'views/tabs/components/product_tabs.tpl');
        $box = strpos($src, '<div class="travel-pdpa-tabs" data-travel-pdpa-tabs>');
        $content = strpos($src, '{$smarty.capture.tabsbox_content nofilter}');
        $wrapper = strpos($src, 'content=$smarty.capture.tabsbox_content');
        self::assertIsInt($include);
        self::assertIsInt($box);
        self::assertIsInt($content);
        self::assertIsInt($wrapper);
        self::assertLessThan($box, $include);
        self::assertLessThan($wrapper, $box, 'the block wrapper inside the box');
        self::assertLessThan($content, $box, 'the bare tabs inside the box');
        $close = strpos($src, '</div>', $content);
        self::assertIsInt($close);
        self::assertLessThan(strpos($src, '{/hook}', $content), $close);
    }

    /**
     * The script stops its scrolls under the header from the stylesheet's
     * line heights; its fallbacks are the same, and the header stays under
     * the booking popups and the phone's sheets (1000, 1001).
     */
    public function testStickyHeaderStylesMatchTheScript(): void
    {
        $css = self::css();
        $script = (string) file_get_contents(self::repoAddonRoot() . '/js/addons/travel_core/pdp-design-a.js');

        self::assertSame(1, preg_match('/\.travel-pdpa \{[^}]*--travel-pdpa-sticky-line1: (\d+)px;\s*--travel-pdpa-sticky-line2: (\d+)px;/', $css, $lines));
        self::assertStringContainsString('var LINE1_FALLBACK = ' . $lines[1] . ';', $script);
        self::assertStringContainsString('var LINE2_FALLBACK = ' . $lines[2] . ';', $script);
        self::assertSame(1, preg_match('/\n\.travel-pdpa-sticky \{[^}]*z-index: (\d+);/', $css, $z));
        self::assertLessThan(1000, (int) $z[1]);
        self::assertStringContainsString('.travel-pdpa-sticky.is-shown {', $css);
        self::assertStringContainsString('.travel-pdpa--stacked .travel-pdpa-tabs [data-travel-pdpa-panel] {', $css);
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
            // At-rules, and the stops of an @keyframes (from / to / 40%), select no element.
            if ($head === '' || str_starts_with($head, '@') || preg_match('/^(from|to|\d+(\.\d+)?%)(\s*,\s*(from|to|\d+(\.\d+)?%))*$/', $head) === 1) {
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
