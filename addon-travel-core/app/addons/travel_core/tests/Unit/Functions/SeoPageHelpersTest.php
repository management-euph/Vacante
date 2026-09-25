<?php

declare(strict_types=1);

namespace {
    if (!defined('BOOTSTRAP')) {
        define('BOOTSTRAP', true);
    }
    if (!defined('CART_LANGUAGE')) {
        define('CART_LANGUAGE', 'en');
    }

    // A provider add-on as the SEO Templates page sees it: defaults,
    // placeholders (bare keys and one explicit label) and its page entry.
    if (!function_exists('fn_unitpage_seo_defaults')) {
        /** @return array<string, string> */
        function fn_unitpage_seo_defaults(): array
        {
            return [
                'seo_product_name'         => '{{name}}',
                'seo_page_title'           => '{{name}} - {{city}}',
                'seo_meta_description'     => 'Book {{name}}.',
                'seo_meta_description__ro' => 'Rezervă {{name}}.',
                'seo_field_meta_keywords'  => 'N',
            ];
        }

        /** @return array<string, array<int|string, string>> */
        function fn_unitpage_seo_placeholders(): array
        {
            return [
                'hotel'    => ['name', 'code' => 'unitpage.ph_code'],
                'location' => ['city'],
            ];
        }

        /** @return array{name: string, dispatch: string} */
        function fn_unitpage_seo_page(): array
        {
            return ['name' => 'Unit', 'dispatch' => 'unitpage.seo_templates'];
        }

        /** @return array{name: string, dispatch: string} */
        function fn_otherpage_seo_page(): array
        {
            return ['name' => 'Another', 'dispatch' => 'otherpage.seo'];
        }
    }

    require_once dirname(__DIR__, 3) . '/functions/seo.php';
}

namespace Tygh\Addons\TravelCore\Tests\Unit\Functions {

    use PHPUnit\Framework\TestCase;
    use Tygh\Registry;

    /**
     * The shared SEO Templates page helpers (functions/seo.php, "SEO Templates
     * admin page" section): the placeholder list a provider declares, the
     * checks for what the engine would silently drop, the provider tab row
     * and the data the page template renders.
     */
    final class SeoPageHelpersTest extends TestCase
    {
        protected function setUp(): void
        {
            Registry::set('addons', ['unitpage' => [], 'otherpage' => [], 'no_seo_page' => []]);
            Registry::set('addons.unitpage', []);
        }

        public function testEveryListedModifierIsOneTheEngineApplies(): void
        {
            $src = (string) file_get_contents(dirname(__DIR__, 3) . '/functions/seo.php');
            $engine = substr($src, (int) strpos($src, 'function fn_travel_core_apply_modifier('), 1500);
            foreach (fn_travel_core_seo_modifiers() as $modifier) {
                self::assertStringContainsString("'" . $modifier . "' =>", $engine, $modifier);
            }
            self::assertCount(11, fn_travel_core_seo_modifiers());
        }

        public function testBareKeysGetTheSharedLabelAndExplicitLabelsAreKept(): void
        {
            self::assertSame([
                'hotel'    => ['name' => 'travel_core.seo_ph_name', 'code' => 'unitpage.ph_code'],
                'location' => ['city' => 'travel_core.seo_ph_city'],
            ], fn_travel_core_seo_placeholder_groups('unitpage'));
            self::assertSame(['name', 'code', 'city'], fn_travel_core_seo_placeholder_keys('unitpage'));
            self::assertSame([], fn_travel_core_seo_placeholder_groups('no_such_addon'));
        }

        public function testProblemsNameWhatTheEngineWouldDrop(): void
        {
            $keys = ['name', 'city'];
            self::assertSame([], fn_travel_core_seo_template_problems('{{name|upper}} - {{city}}', $keys));
            self::assertSame(
                [['travel_core.seo_problem_unknown_placeholder', '{{citty}}']],
                fn_travel_core_seo_template_problems('{{name}} {{citty}} {{citty}}', $keys),
            );
            self::assertSame(
                [['travel_core.seo_problem_unknown_modifier', '|shout']],
                fn_travel_core_seo_template_problems('{{name|shout}}', $keys),
            );
            self::assertSame(
                [['travel_core.seo_problem_one_modifier', '']],
                fn_travel_core_seo_template_problems('{{name|upper|lower}}', $keys),
            );
            self::assertSame(
                [['travel_core.seo_problem_unbalanced', '']],
                fn_travel_core_seo_template_problems('{{name}} - {{city', $keys),
            );
        }

        public function testTheTabRowListsOnlyAddOnsWithAPageSortedByName(): void
        {
            $tabs = fn_travel_core_seo_page_providers('unitpage');

            self::assertSame(['Another', 'Unit'], array_column($tabs, 'name'));
            self::assertSame([false, true], array_column($tabs, 'current'));
            self::assertSame(['otherpage', 'unitpage'], array_column($tabs, 'addon'));
        }

        public function testPageDataCarriesDefaultsTicksAndTheScriptConfig(): void
        {
            $data = fn_travel_core_seo_page_data('unitpage', ['name' => 'Parc CM', 'city' => 'Mamaia'], [
                'save'  => 'unitpage.save_seo',
                'apply' => 'unitpage.apply_seo',
            ]);

            self::assertSame('override_all', $data['values']['seo_overwrite_mode']);
            // A provider default of N is respected; the rest default to Y.
            self::assertSame('N', $data['values']['seo_field_meta_keywords']);
            self::assertSame('Y', $data['values']['seo_field_page_title']);
            self::assertTrue($data['show_providers']);
            self::assertSame('Parc CM', $data['sample_name']);

            // "Restore default" per language: the __<lang> default wins for its language.
            self::assertSame('Book {{name}}.', $data['defaults']['en']['seo_meta_description']);

            $config = json_decode((string) $data['config_json'], true);
            self::assertIsArray($config);
            self::assertSame(['name', 'code', 'city'], $config['keys']);
            self::assertSame(fn_travel_core_seo_modifiers(), $config['modifiers']);
            self::assertSame(['name' => 'Parc CM', 'city' => 'Mamaia'], $config['sample']);
            self::assertArrayHasKey('unknown_placeholder', $config['labels']);

            self::assertSame(
                [['label' => 'travel_core.seo_group_hotel', 'items' => [
                    ['key' => 'name', 'label' => 'travel_core.seo_ph_name'],
                    ['key' => 'code', 'label' => 'unitpage.ph_code'],
                ]], ['label' => 'travel_core.seo_group_location', 'items' => [
                    ['key' => 'city', 'label' => 'travel_core.seo_ph_city'],
                ]]],
                $data['groups'],
            );
        }

        public function testNoSampleMeansNoPreviewData(): void
        {
            $data = fn_travel_core_seo_page_data('unitpage', null, ['save' => 's', 'apply' => 'a']);
            $config = json_decode((string) $data['config_json'], true);

            self::assertSame('', $data['sample_name']);
            self::assertIsArray($config);
            self::assertNull($config['sample']);
        }
    }
}
