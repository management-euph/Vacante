<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Inline-STYLE ratchet (sibling of InlineScriptRatchetTest): template markup
 * must not grow new style="..." attributes — styling belongs in the addon
 * stylesheets (the cart booking-details card was migrated to
 * .travel-bcard-* in booking-pages.css as the model).
 *
 * Rules:
 *  - `style="display: none"` alone is PERMITTED everywhere: that is
 *    JS-toggled state, not styling (SmartyCompatTest precedent).
 *  - Files in ALLOWLIST may keep at most their recorded count — lower the
 *    number when you de-inline a file (never raise it).
 *  - Every other responsive template must carry ZERO styling attributes.
 * Smarty comments and <script> regions are stripped before counting
 * (JS-built markup strings are runtime behaviour, not template CSS).
 * nova_theme is byte-mirrored from responsive, so scanning responsive
 * covers both.
 */
final class InlineStyleRatchetTest extends TestCase
{
    /** @var array<string, int> repo-relative template path => max styling attrs */
    private const ALLOWLIST = [
        'addon-novoton-holidays/design/themes/responsive/templates/addons/novoton_holidays/blocks/homepage_booking.tpl' => 2,
        'addon-novoton-holidays/design/themes/responsive/templates/addons/novoton_holidays/hooks/checkout/order_info.post.tpl' => 10,
        'addon-novoton-holidays/design/themes/responsive/templates/addons/novoton_holidays/hooks/checkout/summary_extra.post.tpl' => 3,
        'addon-novoton-holidays/design/themes/responsive/templates/addons/novoton_holidays/hooks/common/product_info.post.tpl' => 9,
        'addon-sphinx-holidays/design/themes/responsive/templates/addons/sphinx_holidays/blocks/package_search.tpl' => 19,
        'addon-sphinx-holidays/design/themes/responsive/templates/addons/sphinx_holidays/views/sphinx_booking/circuit_search.tpl' => 6,
        'addon-sphinx-holidays/design/themes/responsive/templates/addons/sphinx_holidays/views/sphinx_booking/package_search.tpl' => 5,
        'addon-sphinx-holidays/design/themes/responsive/templates/addons/sphinx_holidays/views/sphinx_booking/search.tpl' => 1,
        'addon-travel-core/design/themes/responsive/templates/addons/travel_core/blocks/booking_summary.tpl' => 11,
        // Verbatim copy of the stock default_template.tpl wrapper: the ONE
        // style attr is a settings-driven CSS custom property
        // (--ty-product-block-image-width) that cannot move to static CSS.
        'addon-travel-core/design/themes/responsive/templates/addons/travel_core/blocks/product_templates/travelcore_template.tpl' => 1,
        'addon-travel-core/design/themes/responsive/templates/addons/travel_core/components/booking_form_mount.tpl' => 1,
        'addon-travel-core/design/themes/responsive/templates/addons/travel_core/components/order_booking_details.tpl' => 2,
        'addon-travel-core/design/themes/responsive/templates/addons/travel_core/hooks/products/main_info_title.post.tpl' => 2,
        'addon-travel-core/design/themes/responsive/templates/addons/travel_core/hooks/products/product_detail_bottom.post.tpl' => 15,
    ];

    private const DESIGN_ROOTS = [
        'addon-novoton-holidays/design/themes/responsive/templates',
        'addon-sphinx-holidays/design/themes/responsive/templates',
        'addon-travel-core/design/themes/responsive/templates',
        'addon-fgo-invoicing/design/themes/responsive/templates',
    ];

    public function testInlineStylesOnlyEverShrink(): void
    {
        $repoRoot = dirname(__DIR__, 7);
        $offenders = [];
        $seen = [];

        foreach (self::DESIGN_ROOTS as $designRoot) {
            $absRoot = $repoRoot . '/' . $designRoot;
            if (!is_dir($absRoot)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($absRoot, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'tpl') {
                    continue;
                }
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($repoRoot) + 1));
                $count = self::stylingAttrCount((string) file_get_contents($file->getPathname()));
                $seen[$relative] = true;

                $allowed = self::ALLOWLIST[$relative] ?? 0;
                if ($count > $allowed) {
                    $offenders[] = "{$relative}: {$count} styling style=\"\" attrs (allowed {$allowed})";
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            "Inline style attributes may only SHRINK — move styling into the addon stylesheet "
                . "(model: .travel-bcard-* in travel_core booking-pages.css). "
                . "style=\"display: none\" alone stays permitted (JS-toggled state).",
        );

        // Stale allowlist rows (file deleted/renamed) must be pruned so the
        // ratchet cannot silently cover a new file under an old budget.
        $stale = array_diff_key(self::ALLOWLIST, $seen);
        self::assertSame([], array_keys($stale), 'prune deleted files from the ALLOWLIST');
    }

    private static function stylingAttrCount(string $source): int
    {
        $source = (string) preg_replace('/\{\*.*?\*\}/s', '', $source);
        $source = (string) preg_replace('~<script\b.*?</script>~is', '', $source);

        $count = 0;
        if (preg_match_all('/\sstyle="([^"]*)"/i', $source, $m)) {
            foreach ($m[1] as $decl) {
                if (!preg_match('/^\s*display:\s*none;?\s*$/i', $decl)) {
                    ++$count;
                }
            }
        }

        return $count;
    }
}
