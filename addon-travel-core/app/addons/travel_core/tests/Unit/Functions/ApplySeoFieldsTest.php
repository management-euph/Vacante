<?php

declare(strict_types=1);

namespace {
    // Load the procedural function-under-test in the GLOBAL namespace, exactly as
    // CS-Cart loads it.
    if (!defined('BOOTSTRAP')) {
        define('BOOTSTRAP', true);
    }
    if (!defined('CART_LANGUAGE')) {
        define('CART_LANGUAGE', 'en');
    }

    // Provider stub mimicking fn_<addon>_seo_defaults() in an addon's func.php.
    // fn_travel_core_apply_seo_fields() discovers it by the conventional name
    // 'fn_' . $addonName . '_seo_defaults' and uses it as the built-in fallback
    // when the stored seo_* settings are blank/absent — the exact situation in
    // the storefront cron context that creates products (AREA 'C', where the
    // admin self-heal seed never ran).
    if (!function_exists('fn_unitseo_seo_defaults')) {
        /** @return array<string, string> */
        function fn_unitseo_seo_defaults(): array
        {
            return [
                'seo_overwrite_mode'         => 'override_all',
                'seo_product_name'           => '{{name}}',
                'seo_page_title'             => '{{name}} - {{city}}, {{country}} {{year}}',
                'seo_meta_description'       => 'Book {{name}} in {{city}}, {{country}}.',
                // Per-language built-in default (see _travel_core_seo_template_for)
                'seo_meta_description__ro'   => 'Rezervă {{name}} în {{city}}, {{country}}.',
                'seo_meta_keywords'          => '{{name}}, {{city}}, {{country}}',
                'seo_name_slug'              => '{{name}}-{{city}}-{{country}}',
                'seo_full_description'       => '',
                'seo_field_product_name'     => 'Y',
                'seo_field_page_title'       => 'Y',
                'seo_field_meta_description' => 'Y',
                'seo_field_meta_keywords'    => 'Y',
                'seo_field_name_slug'        => 'Y',
                'seo_field_full_description' => 'Y',
            ];
        }
    }

    require_once dirname(__DIR__, 3) . '/functions/seo.php';
}

namespace Tygh\Addons\TravelCore\Tests\Unit\Functions {

    use PHPUnit\Framework\TestCase;
    use Tygh\Registry;

    /**
     * Regression coverage for the SEO-default fallback added to
     * fn_travel_core_apply_seo_fields(). The bug: hotel products created by the
     * storefront cron rendered blank Page title / Meta description / Meta
     * keywords because the seo_* settings were never present in that AREA. The
     * fix: fall back to the addon's built-in fn_<addon>_seo_defaults() whenever
     * a stored template is blank — without ever overriding an admin-saved value.
     */
    final class ApplySeoFieldsTest extends TestCase
    {
        /** @var array<string, string> */
        private array $placeholders = [
            'name'          => 'Edart Hotel',
            'city'          => 'Durres',
            'country'       => 'Albania',
            'property_type' => 'hotel',
            'star_rating'   => '4',
            'year'          => '2026',
        ];

        protected function setUp(): void
        {
            // No stored seo_* settings — reproduces the cron (AREA 'C') Registry
            // state where the admin self-heal seed never ran.
            Registry::set('addons.unitseo', []);
        }

        public function testFallsBackToBuiltInDefaultsWhenSettingsBlank(): void
        {
            $result = fn_travel_core_apply_seo_fields('unitseo', $this->placeholders, 0, null);

            $this->assertSame('Edart Hotel', $result['product'] ?? null);
            $this->assertSame('Edart Hotel - Durres, Albania 2026', $result['page_title'] ?? null);
            $this->assertSame('Book Edart Hotel in Durres, Albania.', $result['meta_description'] ?? null);
            $this->assertSame('Edart Hotel, Durres, Albania', $result['meta_keywords'] ?? null);
            $this->assertNotEmpty($result['seo_name'] ?? '');
        }

        public function testStoredSettingTakesPrecedenceOverDefault(): void
        {
            // An admin-saved per-language template must win over the built-in
            // default (implicit language = CART_LANGUAGE, 'en' in this suite).
            Registry::set('addons.unitseo', [
                'seo_page_title__en' => 'Custom {{name}} Title',
            ]);

            $result = fn_travel_core_apply_seo_fields('unitseo', $this->placeholders, 0, null);

            $this->assertSame('Custom Edart Hotel Title', $result['page_title'] ?? null);
            // Other fields still come from the defaults fallback.
            $this->assertSame('Edart Hotel, Durres, Albania', $result['meta_keywords'] ?? null);
        }

        public function testNoFallbackWhenAddonHasNoDefaultsProvider(): void
        {
            // Unknown addon → no fn_<addon>_seo_defaults() exists, so blank
            // settings yield no rendered template fields (legacy behaviour).
            Registry::set('addons.unknownaddon', []);

            $result = fn_travel_core_apply_seo_fields('unknownaddon', $this->placeholders, 0, null);

            $this->assertArrayNotHasKey('page_title', $result);
            $this->assertArrayNotHasKey('meta_description', $result);
            $this->assertArrayNotHasKey('meta_keywords', $result);
        }

        public function testLanguageDefaultRendersForThatLanguageOnly(): void
        {
            // 'ro' resolves the addon's __ro default; 'en' (and the implicit
            // CART_LANGUAGE call) keep the base default.
            $ro = fn_travel_core_apply_seo_fields('unitseo', $this->placeholders, 0, null, 'ro');
            $en = fn_travel_core_apply_seo_fields('unitseo', $this->placeholders, 0, null, 'en');

            $this->assertSame('Rezervă Edart Hotel în Durres, Albania.', $ro['meta_description'] ?? null);
            $this->assertSame('Book Edart Hotel in Durres, Albania.', $en['meta_description'] ?? null);
        }

        public function testStoredSharedTemplateIsIgnored(): void
        {
            // Fresh-addon policy: language-less STORED templates are not part
            // of the resolution chain — only the per-language keys are admin
            // truth. A stray shared value must change nothing.
            Registry::set('addons.unitseo', [
                'seo_meta_description' => 'Shared {{name}} copy.',
            ]);

            $ro = fn_travel_core_apply_seo_fields('unitseo', $this->placeholders, 0, null, 'ro');
            $en = fn_travel_core_apply_seo_fields('unitseo', $this->placeholders, 0, null, 'en');

            $this->assertSame('Rezervă Edart Hotel în Durres, Albania.', $ro['meta_description'] ?? null);
            $this->assertSame('Book Edart Hotel in Durres, Albania.', $en['meta_description'] ?? null);
        }

        public function testStoredLanguageValueWinsForItsLanguageOnly(): void
        {
            Registry::set('addons.unitseo', [
                'seo_meta_description__ro' => 'RO specific: {{name}}.',
            ]);

            $ro = fn_travel_core_apply_seo_fields('unitseo', $this->placeholders, 0, null, 'ro');
            $en = fn_travel_core_apply_seo_fields('unitseo', $this->placeholders, 0, null, 'en');

            $this->assertSame('RO specific: Edart Hotel.', $ro['meta_description'] ?? null);
            // The value targets 'ro' only — 'en' keeps its built-in default.
            $this->assertSame('Book Edart Hotel in Durres, Albania.', $en['meta_description'] ?? null);
        }

        public function testDisabledFieldToggleSkipsRendering(): void
        {
            // seo_field_page_title = 'N' must skip page_title even though a
            // default template exists.
            Registry::set('addons.unitseo', [
                'seo_field_page_title' => 'N',
            ]);

            $result = fn_travel_core_apply_seo_fields('unitseo', $this->placeholders, 0, null);

            $this->assertArrayNotHasKey('page_title', $result);
            // Other defaulted fields remain present.
            $this->assertSame('Book Edart Hotel in Durres, Albania.', $result['meta_description'] ?? null);
        }

        public function testNoDescriptionAtHandLeavesTheProductsDescriptionAlone(): void
        {
            // Empty full-description template + no description placeholder (a
            // bulk apply that doesn't fetch it): writing '' would wipe the
            // product's description, so the field is left out.
            $result = fn_travel_core_apply_seo_fields('unitseo', $this->placeholders, 0, null);
            $this->assertArrayNotHasKey('full_description', $result);

            $result = fn_travel_core_apply_seo_fields(
                'unitseo',
                $this->placeholders + ['description' => 'Seafront hotel.'],
                0,
                null,
            );
            $this->assertSame('Seafront hotel.', $result['full_description'] ?? null);
        }
    }
}
