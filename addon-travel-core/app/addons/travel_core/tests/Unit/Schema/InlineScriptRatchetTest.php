<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Repo-wide ratchet on inline template JavaScript. Behavioral JS belongs in
 * real files under js/addons/<addon>/ (loaded via {script}, imported by
 * vitest — see sphinx search-results.js / novoton booking-form.js): inline
 * <script> bodies are untestable and grow Smarty-interpolation bugs.
 *
 * The allowlist is the CURRENT inventory (mostly one Smarty-fed config
 * prelude or a small admin-page glue block per file), each capped at its
 * present count. A NEW template with inline JS — or a second block in an
 * allowlisted one — fails with instructions. REMOVE entries as files get
 * extracted; never add one without a reason the config-object pattern can't
 * cover.
 */
final class InlineScriptRatchetTest extends TestCase
{
    /** @var array<string, int> repo-relative tpl path => max inline <script> blocks */
    private const ALLOWLIST = [
        'addon-novoton-holidays/design/backend/templates/addons/novoton_holidays/views/novoton_price_compare/manage.tpl' => 1,
        'addon-novoton-holidays/design/themes/nova_theme/templates/addons/novoton_holidays/hooks/index/scripts.post.tpl' => 1,
        'addon-novoton-holidays/design/themes/nova_theme/templates/addons/novoton_holidays/views/novoton_booking/booking_form.tpl' => 1,
        'addon-novoton-holidays/design/themes/responsive/templates/addons/novoton_holidays/hooks/index/scripts.post.tpl' => 1,
        'addon-novoton-holidays/design/themes/responsive/templates/addons/novoton_holidays/views/novoton_booking/booking_form.tpl' => 1,
        'addon-sphinx-holidays/design/backend/templates/addons/sphinx_holidays/views/sphinx_holidays/hotels.tpl' => 1,
        'addon-sphinx-holidays/design/themes/responsive/templates/addons/sphinx_holidays/blocks/best_deals.tpl' => 1,
        'addon-sphinx-holidays/design/themes/responsive/templates/addons/sphinx_holidays/blocks/package_search.tpl' => 1,
        'addon-sphinx-holidays/design/themes/responsive/templates/addons/sphinx_holidays/hooks/index/scripts.post.tpl' => 1,
        'addon-travel-core/design/backend/templates/addons/travel_core/views/travel_booking_styles/manage.tpl' => 1,
        'addon-travel-core/design/backend/templates/addons/travel_core/views/travel_feature_mappings/edit.tpl' => 1,
        'addon-travel-core/design/backend/templates/addons/travel_core/views/travel_feature_mappings/manage.tpl' => 1,
        'addon-travel-core/design/backend/templates/addons/travel_core/views/travel_feature_mappings/scan_progress.tpl' => 1,
        'addon-travel-core/design/themes/nova_theme/templates/addons/travel_core/components/travel_i18n.tpl' => 1,
        'addon-travel-core/design/themes/nova_theme/templates/addons/travel_core/hooks/index/scripts.post.tpl' => 1,
        'addon-travel-core/design/themes/nova_theme/templates/addons/travel_core/hooks/products/product_detail_bottom.post.tpl' => 1,
        'addon-travel-core/design/themes/responsive/templates/addons/travel_core/components/travel_i18n.tpl' => 1,
        'addon-travel-core/design/themes/responsive/templates/addons/travel_core/hooks/index/scripts.post.tpl' => 1,
        'addon-travel-core/design/themes/responsive/templates/addons/travel_core/hooks/products/product_detail_bottom.post.tpl' => 1,
    ];

    private const DESIGN_ROOTS = [
        'addon-travel-core/design',
        'addon-novoton-holidays/design',
        'addon-sphinx-holidays/design',
        'addon-fgo-invoicing/design',
    ];

    public function testNoNewInlineTemplateJavaScript(): void
    {
        $repoRoot = dirname(__DIR__, 7);
        $violations = [];
        $checked = 0;

        foreach (self::DESIGN_ROOTS as $designRoot) {
            $base = $repoRoot . '/' . $designRoot;
            if (!is_dir($base)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'tpl') {
                    continue;
                }
                $checked++;
                $src = (string) file_get_contents($file->getPathname());
                $inline = 0;
                if (preg_match_all('/<script\b([^>]*)>/', $src, $m) > 0) {
                    foreach ($m[1] as $attrs) {
                        if (!str_contains($attrs, 'src=')) {
                            $inline++;
                        }
                    }
                }
                if ($inline === 0) {
                    continue;
                }
                $relative = ltrim(substr($file->getPathname(), strlen($repoRoot)), '/');
                $allowed = self::ALLOWLIST[$relative] ?? 0;
                if ($inline > $allowed) {
                    $violations[] = "{$relative}: {$inline} inline <script> block(s), allowed {$allowed}";
                }
            }
        }

        self::assertGreaterThan(100, $checked, 'template walk looks broken');
        self::assertSame(
            [],
            $violations,
            "New inline template JS — extract it to js/addons/<addon>/ (loaded via {script}, "
            . "testable by vitest; keep only a Smarty-fed config object inline):\n"
            . implode("\n", $violations),
        );
    }

    public function testAllowlistEntriesAreStillReal(): void
    {
        // A stale entry (file deleted or fully extracted) silently loosens
        // the ratchet — prune it.
        $repoRoot = dirname(__DIR__, 7);

        foreach (self::ALLOWLIST as $relative => $allowed) {
            $path = $repoRoot . '/' . $relative;
            self::assertFileExists($path, "allowlisted file is gone — prune the entry: {$relative}");
            $src = (string) file_get_contents($path);
            $inline = 0;
            if (preg_match_all('/<script\b([^>]*)>/', $src, $m) > 0) {
                foreach ($m[1] as $attrs) {
                    if (!str_contains($attrs, 'src=')) {
                        $inline++;
                    }
                }
            }
            self::assertGreaterThan(0, $inline, "allowlisted file has no inline JS anymore — prune the entry: {$relative}");
        }
    }
}
