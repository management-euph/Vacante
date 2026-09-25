<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Tests\Unit\Seo;

use PHPUnit\Framework\TestCase;

/**
 * Eurosite → SEO Templates is Travel Core's shared page (the same one Sphinx
 * and Novoton use); this pins Eurosite's side of the wiring: the modes, the
 * menu and tab, the page hooks Travel Core looks up by name, and the queries
 * the preview and "Apply templates now" read.
 */
final class SeoTemplatesPageContractTest extends TestCase
{
    private const ADDON = __DIR__ . '/../../..';

    private static function read(string $path): string
    {
        $src = file_get_contents($path);
        self::assertIsString($src, $path);

        return $src;
    }

    public function testThePageIsTheSharedTravelCorePage(): void
    {
        $tpl = self::read(self::ADDON . '/../../../design/backend/templates/addons/eurosite/views/eurosite/seo_templates.tpl');
        self::assertStringContainsString('{include file="addons/travel_core/components/seo_templates_page.tpl"', $tpl);
        self::assertStringContainsString('seo_form_id="eurosite_seo_form"', $tpl);
    }

    public function testTheControllerSavesAppliesAndShowsThroughTheSharedHelpers(): void
    {
        $controller = self::read(self::ADDON . '/controllers/backend/eurosite.php');
        self::assertStringContainsString("if (\$mode === 'save_seo_templates') {\n        fn_travel_core_seo_page_save('eurosite', \$_REQUEST);", $controller);
        self::assertStringContainsString("if (\$mode === 'apply_seo_templates') {", $controller);
        self::assertStringContainsString('$hotelRepo->linkedBatchForSeo($offset, $batch)', $controller);
        self::assertStringContainsString("if (\$mode === 'seo_templates') {", $controller);
        self::assertStringContainsString('Container::hotels()->sampleForSeo()', $controller);
        self::assertStringContainsString("'save'  => 'eurosite.save_seo_templates'", $controller);
        self::assertStringContainsString("'apply' => 'eurosite.apply_seo_templates'", $controller);
    }

    public function testTheMenuTabsAndTravelCoreHooksExist(): void
    {
        self::assertStringContainsString("'href' => 'eurosite.seo_templates'", self::read(self::ADDON . '/schemas/menu/menu.post.php'));
        self::assertStringContainsString("'href'     => 'eurosite.seo_templates'", self::read(self::ADDON . '/schemas/menu/actions.post.php'));

        $func = self::read(self::ADDON . '/func.php');
        foreach (['fn_eurosite_seo_defaults', 'fn_eurosite_seo_placeholders', 'fn_eurosite_seo_page'] as $fn) {
            self::assertStringContainsString('function ' . $fn . '(): array', $func, $fn);
        }
        self::assertStringContainsString("['name' => 'Eurosite', 'dispatch' => 'eurosite.seo_templates']", $func);
    }

    public function testTheSeoQueriesReadEveryLinkedProductAndCarryAUniqueHotelId(): void
    {
        $repo = self::read(self::ADDON . '/src/Repository/HotelRepository.php');
        self::assertStringContainsString("self::SEO_SELECT . ' WHERE h.product_id > 0 ORDER BY h.tourop_code, h.product_code LIMIT ?i, ?i'", $repo);
        self::assertStringContainsString("CONCAT('EUS-', h.tourop_code, '-', h.product_code) AS hotel_id", $repo);
        // The coordinates come from the details payload.
        self::assertStringContainsString('c.pictures_json, c.description, c.payload_json, c.fetched_at AS info_fetched_at', $repo);
    }

    public function testTheLabelsExist(): void
    {
        /** @var array<string, array<string, string>> $keys */
        $keys = require self::ADDON . '/lang_keys.php';
        foreach (['eurosite.seo_templates', 'eurosite.seo_templates_title', 'eurosite.seo_placeholders_title'] as $key) {
            self::assertArrayHasKey($key, $keys, $key);
        }
    }
}
