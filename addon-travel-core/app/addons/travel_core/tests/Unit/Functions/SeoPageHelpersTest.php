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

    // CS-Cart's ?:storage_data, in memory: the SEO store's canonical home.
    if (!function_exists('fn_get_storage_data')) {
        /** @var array<string, string> $GLOBALS['unit_storage_data'] */
        $GLOBALS['unit_storage_data'] = [];

        function fn_get_storage_data(string $key): string
        {
            return $GLOBALS['unit_storage_data'][$key] ?? '';
        }

        function fn_set_storage_data(string $key, string $value): void
        {
            $GLOBALS['unit_storage_data'][$key] = $value;
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
            unset($GLOBALS['unit_storage_data']['travel_core_seo_unitpage']);
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

        public function testSavedTemplatesSurviveACacheClear(): void
        {
            // The bug: templates saved on the page came back as the built-in
            // defaults after a reload — the Settings API dropped the rows.
            fn_travel_core_seo_save_lang_templates('unitpage', [
                'en' => ['seo_product_name' => '{{name}}{{classification}}'],
                'ro' => ['seo_product_name' => '{{name}} RO'],
            ]);

            // A reload on a real store: the Registry no longer has the values.
            Registry::set('addons.unitpage', []);

            $settings = fn_travel_core_seo_settings('unitpage');
            self::assertSame('{{name}}{{classification}}', $settings['seo_product_name__en']);
            self::assertSame('{{name}} RO', $settings['seo_product_name__ro']);

            $form = fn_travel_core_seo_lang_form_data('unitpage', fn_unitpage_seo_defaults());
            self::assertSame('{{name}}{{classification}}', $form['values']['en']['seo_product_name']);

            // …and the engine renders with them, not the default.
            $fields = fn_travel_core_apply_seo_fields('unitpage', ['name' => 'Parc CM', 'classification' => '3'], 0, null, 'en');
            self::assertSame('Parc CM3', $fields['product']);
        }

        public function testTheStoredValueWinsOverAnOldSettingsRow(): void
        {
            fn_travel_core_seo_save_lang_templates('unitpage', ['en' => ['seo_page_title' => 'Stored {{name}}']]);
            Registry::set('addons.unitpage', ['seo_page_title__en' => 'Old settings row']);

            self::assertSame('Stored {{name}}', fn_travel_core_seo_settings('unitpage')['seo_page_title__en']);
        }

        public function testModeAndTicksRoundTripThroughTheStore(): void
        {
            fn_travel_core_seo_page_save('unitpage', [
                'seo' => ['seo_overwrite_mode' => 'fill_if_empty', 'seo_field_product_name' => 'Y'],
                'seo_lang' => [],
            ], false);
            Registry::set('addons.unitpage', []);

            $data = fn_travel_core_seo_page_data('unitpage', null, ['save' => 'unitpage.save', 'apply' => 'unitpage.apply']);
            self::assertSame('fill_if_empty', $data['values']['seo_overwrite_mode']);
            self::assertSame('Y', $data['values']['seo_field_product_name']);
            // Unticked on the page = N, whatever the provider default.
            self::assertSame('N', $data['values']['seo_field_page_title']);
            // The form posts to the save URL itself.
            self::assertSame('unitpage.save', $data['save_url']);
        }
    }
}
